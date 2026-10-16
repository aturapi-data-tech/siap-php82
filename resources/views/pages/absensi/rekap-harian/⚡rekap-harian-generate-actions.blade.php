<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\UniqueConstraintViolationException;
use Carbon\Carbon;
use App\Support\Absensi;
use App\Support\Format;
use App\Support\Jadwal;
use App\Http\Traits\Concerns\WithRenderVersioningTrait;

/**
 * GENERATE ABSENSI — semua karyawan aktif yang BELUM ADA catatan pada satu tanggal kerja
 * dicatat sekaligus sebagai Libur (L) atau Alpa (A), supaya HRD tidak mengisi satu per satu.
 *
 * - Hanya tanggal yang sudah lewat (hari ini masih ada shift siang/malam yang belum datang).
 * - Default per karyawan dari JADWAL DINAS bila bulan itu sudah berjadwal:
 *   terjadwal kerja → Alpa · tidak terjadwal → Libur · terjadwal I/C → Izin/Cuti.
 *   Tanpa jadwal: Libur bila N minggu terakhir tidak pernah masuk di hari yang sama
 *   (Absensi::liburRutin), selain itu Alpa. Admin bisa mengubah per orang / sekaligus.
 * - Baris tercipta sebagai sumber MANUAL; koreksi/hapus per karyawan di Rekap Harian.
 */
new class extends Component {
    use WithRenderVersioningTrait;

    public string $tanggal  = '';  // dd/mm/yyyy
    public array  $kandidat = [];  // [['emp_id', 'emp_name', 'libur_rutin' => bool, 'jadwal' => kode|''], ...]
    public array  $pilihan  = [];  // emp_id => 'L' | 'A' | 'I' | 'C'
    public bool   $pakaiJadwal = false;
    public string $keteranganAlpa  = 'Tidak absen (generate)';
    public string $keteranganLibur = 'Libur / off';
    public array  $renderVersions = [];
    protected array $renderAreas  = ['modal'];

    public function mount(): void
    {
        $this->registerAreas(['modal']);
    }

    #[On('absensi.rekap-harian.openGenerate')]
    public function openGenerate(string $tanggal): void
    {
        $tanggalKerja = $this->tanggalCarbon($tanggal);
        if (!$tanggalKerja) {
            return;
        }
        if (!$tanggalKerja->lt(now()->startOfDay())) {
            $this->dispatch('toast', type: 'error', message: 'Generate hanya untuk tanggal yang sudah lewat (sebelum hari ini).');
            return;
        }

        $this->tanggal = $tanggalKerja->format(Format::TANGGAL);
        $this->keteranganAlpa = 'Tidak absen (generate)';
        $this->keteranganLibur = 'Libur / off';
        $this->resetValidation();

        $belum = $this->belumAbsen($tanggalKerja);
        $empIds = array_column($belum, 'emp_id');
        $this->pakaiJadwal = Jadwal::adaJadwalBulan($tanggalKerja);
        $jadwal = $this->pakaiJadwal ? Jadwal::bulan($empIds, $tanggalKerja->copy()->startOfMonth()) : [];
        $liburRutin = $this->pakaiJadwal ? [] : Absensi::liburRutin($empIds, $tanggalKerja);

        $this->kandidat = array_map(function ($karyawan) use ($jadwal, $liburRutin, $tanggalKerja) {
            $jadwalHari = $jadwal[$karyawan['emp_id']][$tanggalKerja->day] ?? null;

            return [...$karyawan, 'libur_rutin' => in_array($karyawan['emp_id'], $liburRutin, true), 'jadwal' => $jadwalHari ? Jadwal::kode($jadwalHari) : '',
                'jadwal_warna' => $jadwalHari->shift_warna ?? null, 'jenis' => $jadwalHari->jdw_jenis ?? ''];
        }, $belum);

        $this->pilihan = [];
        foreach ($this->kandidat as $karyawan) {
            $this->pilihan[$karyawan['emp_id']] = $this->pakaiJadwal
                ? match ($karyawan['jenis']) { 'K' => 'A', 'I' => 'I', 'C' => 'C', default => 'L' }
                : ($karyawan['libur_rutin'] ? 'L' : 'A');
        }

        if ($this->kandidat === []) {
            $this->dispatch('toast', type: 'info', message: 'Semua karyawan aktif sudah punya catatan absensi pada ' . $this->tanggal . '.');
            return;
        }

        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'rekap-harian-generate-actions');
    }

    /** Set semua kandidat ke satu status (L / A). */
    public function setSemua(string $status): void
    {
        if (!in_array($status, ['L', 'A', 'I', 'C'], true)) {
            return;
        }
        foreach (array_keys($this->pilihan) as $empId) {
            $this->pilihan[$empId] = $status;
        }
    }

    public function jumlah(string $status): int
    {
        return count(array_filter($this->pilihan, fn($pilihanStatus) => $pilihanStatus === $status));
    }

    public function simpan(): void
    {
        $this->validate(
            ['pilihan' => 'required|array|min:1', 'pilihan.*' => 'in:L,A,I,C',
             'keteranganAlpa' => 'nullable|string|max:200', 'keteranganLibur' => 'nullable|string|max:200'],
            ['pilihan.required' => 'Tidak ada karyawan untuk disimpan.', 'pilihan.*.in' => 'Pilihan harus Libur, Alpa, Izin, atau Cuti.',
             'max' => 'Keterangan maksimal 200 karakter.'],
        );

        $tanggalKerja = $this->tanggalCarbon($this->tanggal);
        if (!$tanggalKerja || !$tanggalKerja->lt(now()->startOfDay())) {
            return;
        }

        // Hanya yang masih belum absen saat ini (bisa saja baru diisi orang lain).
        $masihBelum = array_column($this->belumAbsen($tanggalKerja), 'emp_id');

        $jumlahPerStatus = ['L' => 0, 'A' => 0, 'I' => 0, 'C' => 0];
        foreach ($this->pilihan as $empId => $status) {
            if (!in_array($empId, $masihBelum, true) || !isset($jumlahPerStatus[$status])) {
                continue;
            }
            $keterangan = trim(match ($status) {
                'L' => $this->keteranganLibur,
                'A' => $this->keteranganAlpa,
                default => 'Sesuai jadwal dinas (' . Absensi::STATUS[$status] . ')',
            });

            try {
                DB::table('siaptxn_absensis')->insert([
                    'emp_id'          => $empId,
                    'abs_date'        => Absensi::tanggalSql($tanggalKerja),
                    'abs_status'      => $status,
                    'terlambat_menit' => 0,
                    'lembur_menit'    => 0,
                    'keterangan'      => $keterangan !== '' ? $keterangan : null,
                    'sumber'          => 'MANUAL',
                    'updated_by'      => auth()->id(),
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
                $jumlahPerStatus[$status]++;
            } catch (UniqueConstraintViolationException) {
                // sudah diisi bersamaan — lewati
            }
        }

        $this->dispatch('toast', type: 'success',
            message: "Tersimpan {$this->tanggal}: {$jumlahPerStatus['L']} Libur, {$jumlahPerStatus['A']} Alpa" . ($jumlahPerStatus['I'] + $jumlahPerStatus['C'] ? ", {$jumlahPerStatus['I']} Izin, {$jumlahPerStatus['C']} Cuti" : '') . '.');
        $this->closeModal();
        $this->dispatch('absensi.rekap-harian.saved');
    }

    public function closeModal(): void
    {
        $this->reset(['kandidat', 'pilihan']);
        $this->resetValidation();
        $this->dispatch('close-modal', name: 'rekap-harian-generate-actions');
        $this->resetVersion();
    }

    /** Karyawan aktif tanpa baris absensi pada tanggal kerja. */
    private function belumAbsen(Carbon $tanggalKerja): array
    {
        $tanggalSql = Absensi::tanggalSql($tanggalKerja);

        return DB::table('siapmst_employers as em')
            ->where('em.active_status', '1')
            ->whereNotExists(fn($subQuery) => $subQuery->select(DB::raw(1))
                ->from('siaptxn_absensis as ab')
                ->whereColumn('ab.emp_id', 'em.emp_id')
                ->where('ab.abs_date', '=', $tanggalSql))
            ->orderBy('em.emp_name')
            ->get(['em.emp_id', 'em.emp_name'])
            ->map(fn($row) => ['emp_id' => (string) $row->emp_id, 'emp_name' => (string) $row->emp_name])
            ->all();
    }

    private function tanggalCarbon(string $tanggal): ?Carbon
    {
        try {
            return Carbon::createFromFormat('!' . Format::TANGGAL, $tanggal);
        } catch (\Throwable) {
            return null;
        }
    }
};
?>

<div>
    <x-modal name="rekap-harian-generate-actions" size="full" height="full" focusable>
        <div class="flex flex-col min-h-[calc(100vh-8rem)]" wire:key="{{ $this->renderKey('modal', [$tanggal]) }}">

            {{-- HEADER --}}
            <div class="relative px-6 py-5 bg-surface-soft">
                <div class="relative flex items-start justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <div class="flex items-center justify-center w-10 h-10 rounded-xl bg-brand-green/10 dark:bg-brand-lime/15">
                            <img src="{{ asset('images/Logogram black solid.png') }}" alt="Logo" class="block w-6 h-6 dark:hidden" />
                            <img src="{{ asset('images/Logogram white solid.png') }}" alt="Logo" class="hidden w-6 h-6 dark:block" />
                        </div>
                        <div>
                            <h2 class="ds-display-sm dark:text-gray-100">
                                Generate Absensi — {{ $tanggal }}
                                <span class="text-base font-normal text-muted">({{ \App\Support\Format::hari($tanggal ? \Carbon\Carbon::createFromFormat('!d/m/Y', $tanggal) : null) }})</span>
                            </h2>
                            <p class="mt-0.5 text-sm text-muted dark:text-gray-400">
                                Karyawan aktif yang belum punya catatan. Pilih <b>Libur</b> atau <b>Alpa</b> per orang, lalu simpan sekaligus.
                            </p>
                        </div>
                    </div>
                    <x-icon-button color="gray" type="button" wire:click="closeModal">
                        <span class="sr-only">Close</span>
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        </svg>
                    </x-icon-button>
                </div>
            </div>

            {{-- BODY --}}
            <div class="flex-1 px-4 py-4 bg-surface-soft dark:bg-gray-950/20">
                <div class="grid max-w-5xl grid-cols-1 gap-4 lg:grid-cols-3">
                    <div class="lg:col-span-2">
                        <x-border-form title="Belum ada catatan ({{ count($kandidat) }})">
                            <div class="flex flex-wrap items-center justify-between gap-2 pb-2 mb-2 border-b border-hairline dark:border-gray-700">
                                <p class="text-sm">
                                    <x-badge variant="gray">Libur: {{ $this->jumlah('L') }}</x-badge>
                                    <x-badge variant="danger">Alpa: {{ $this->jumlah('A') }}</x-badge>
                                    @if ($this->jumlah('I') + $this->jumlah('C') > 0)
                                        <x-badge variant="info">Izin/Cuti: {{ $this->jumlah('I') + $this->jumlah('C') }}</x-badge>
                                    @endif
                                </p>
                                <div class="flex gap-2">
                                    <x-secondary-button type="button" wire:click="setSemua('L')" class="!px-2.5 !py-1 text-xs">Semua Libur</x-secondary-button>
                                    <x-secondary-button type="button" wire:click="setSemua('A')" class="!px-2.5 !py-1 text-xs">Semua Alpa</x-secondary-button>
                                </div>
                            </div>

                            <div class="divide-y divide-hairline dark:divide-gray-800 max-h-[55vh] overflow-y-auto">
                                @foreach ($kandidat as $karyawan)
                                    @php $pilihanKaryawan = $pilihan[$karyawan['emp_id']] ?? 'A'; @endphp
                                    <div wire:key="gen-{{ $karyawan['emp_id'] }}" class="flex items-center justify-between gap-3 px-2 py-2">
                                        <div class="min-w-0">
                                            <div class="text-sm font-semibold truncate text-ink dark:text-gray-100">{{ $karyawan['emp_name'] }}</div>
                                            <div class="font-mono text-xs text-muted">
                                                {{ $karyawan['emp_id'] }}
                                                @if ($pakaiJadwal)
                                                    <span class="ml-1 font-sans text-[11px] text-muted-soft">· jadwal:</span>
                                                    @if ($karyawan['jadwal'] !== '')
                                                        <span class="px-1.5 font-sans text-[11px] font-bold rounded" style="{{ \App\Support\Jadwal::gaya($karyawan['jadwal'], $karyawan['jadwal_warna']) }}">{{ $karyawan['jadwal'] }}</span>
                                                    @else
                                                        <span class="font-sans text-[11px] text-muted-soft">libur</span>
                                                    @endif
                                                @elseif ($karyawan['libur_rutin'])
                                                    <span class="ml-1 font-sans text-[11px] text-muted-soft">· biasanya libur di hari ini</span>
                                                @endif
                                            </div>
                                        </div>
                                        <div class="inline-flex overflow-hidden border rounded-lg shrink-0 border-hairline dark:border-gray-700">
                                            <button type="button" wire:click="$set('pilihan.{{ $karyawan['emp_id'] }}', 'L')"
                                                class="px-3 py-1.5 text-xs font-semibold transition {{ $pilihanKaryawan === 'L' ? 'bg-gray-700 text-white' : 'bg-canvas text-muted hover:bg-surface-soft' }}">
                                                Libur
                                            </button>
                                            <button type="button" wire:click="$set('pilihan.{{ $karyawan['emp_id'] }}', 'A')"
                                                class="px-3 py-1.5 text-xs font-semibold transition border-l border-hairline {{ $pilihanKaryawan === 'A' ? 'bg-red-600 text-white' : 'bg-canvas text-muted hover:bg-surface-soft' }}">
                                                Alpa
                                            </button>
                                            @foreach (['I' => 'Izin', 'C' => 'Cuti'] as $kodeStatus => $namaStatus)
                                                <button type="button" wire:click="$set('pilihan.{{ $karyawan['emp_id'] }}', '{{ $kodeStatus }}')"
                                                    class="px-2.5 py-1.5 text-xs font-semibold transition border-l border-hairline {{ $pilihanKaryawan === $kodeStatus ? 'bg-sky-600 text-white' : 'bg-canvas text-muted hover:bg-surface-soft' }}">
                                                    {{ $namaStatus }}
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <x-input-error :messages="$errors->get('pilihan')" class="mt-2" />
                        </x-border-form>
                    </div>

                    <div class="space-y-4">
                        <x-border-form title="Keterangan">
                            <div class="space-y-3">
                                <div>
                                    <x-input-label value="Untuk yang Libur" />
                                    <x-text-input wire:model="keteranganLibur" maxlength="200" class="w-full mt-1" />
                                    <x-input-error :messages="$errors->get('keteranganLibur')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label value="Untuk yang Alpa" />
                                    <x-text-input wire:model="keteranganAlpa" maxlength="200" class="w-full mt-1" />
                                    <x-input-error :messages="$errors->get('keteranganAlpa')" class="mt-1" />
                                </div>
                            </div>
                        </x-border-form>
                        <p class="px-1 text-xs text-muted dark:text-gray-400">
                            @if ($pakaiJadwal)
                                Pilihan awal dari <b>Jadwal Dinas</b>: terjadwal kerja → Alpa, tidak terjadwal → Libur, terjadwal I/C → Izin/Cuti.
                            @else
                                Bulan ini belum ada jadwal dinas — pilihan awal <b>Libur</b> = {{ \App\Support\Absensi::MINGGU_POLA_LIBUR }} minggu terakhir tidak pernah masuk di hari yang sama.
                            @endif
                            Tercatat sebagai input <b>manual</b> — bila keliru, koreksi/hapus per karyawan di Rekap Harian.
                        </p>
                    </div>
                </div>
            </div>

            {{-- FOOTER --}}
            <div class="sticky bottom-0 z-10 px-6 py-4 mt-auto border-t bg-surface-soft border-hairline dark:bg-gray-900 dark:border-gray-700">
                <div class="flex items-center justify-end gap-2">
                    <x-secondary-button type="button" wire:click="closeModal">Batal</x-secondary-button>
                    <x-confirm-button variant="primary" action="simpan()" title="Simpan Generate Absensi"
                        message="Simpan {{ $tanggal }}: {{ $this->jumlah('L') }} Libur, {{ $this->jumlah('A') }} Alpa, {{ $this->jumlah('I') + $this->jumlah('C') }} Izin/Cuti?"
                        confirmText="Ya, simpan" cancelText="Batal">
                        Simpan {{ count($kandidat) }} karyawan
                    </x-confirm-button>
                </div>
            </div>

        </div>
    </x-modal>
</div>
