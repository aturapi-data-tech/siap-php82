<?php

use Livewire\Component;
use Livewire\Attributes\Url;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use App\Support\PengaturanAbsensi;
use App\Support\UnitKerja;
use App\Support\Jadwal;

/**
 * PENGATURAN ABSENSI — satu dasbor untuk semua setup absensi HRD:
 *   Aturan                  → angka aturan (siapmst_pengaturan_absensis, via PengaturanAbsensi)
 *   Unit Kerja              → master-unit-kerja (ditanam, :embedded)
 *   Shift                   → master-shift (ditanam, :embedded)
 *   Lokasi Absen HP         → master-lokasi-absen (ditanam, :embedded)
 *   Unit & Lokasi Karyawan  → atur emp_unit_id & emp_lokasi_id banyak karyawan sekaligus
 */
new class extends Component {
    public const TABS = [
        'aturan'          => 'Aturan Absen',
        'unit'            => 'Unit Kerja',
        'shift'           => 'Shift & Toleransi',
        'lokasi'          => 'Titik Lokasi HP',
        'lokasi-karyawan' => 'Unit & Lokasi Karyawan',
    ];

    #[Url]
    public string $tab = 'aturan';

    public array $form = [];

    // Tab Unit & Lokasi Karyawan
    public string $searchKeyword = '';
    public bool $hanyaBelumDiatur = false;
    public string $unitMassal = '';
    public string $lokasiMassal = '';

    public function mount(): void
    {
        if (!array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'aturan';
        }
        $this->muat();
    }

    public function pilihTab(string $tab): void
    {
        if (array_key_exists($tab, self::TABS)) {
            $this->tab = $tab;
            unset($this->ringkasan);
        }
    }

    // ─── Ringkasan (kartu atas) ───────────────────────────────────────────────
    #[Computed]
    public function ringkasan(): array
    {
        return [
            'shiftAktif'       => DB::table('siapmst_shifts')->where('active_status', '1')->count(),
            'lokasiAktif'      => DB::table('siapmst_lokasi_absens')->where('active_status', '1')->count(),
            'karyawanAktif'    => DB::table('siapmst_employers')->where('active_status', '1')->count(),
            'tanpaLokasi'      => DB::table('siapmst_employers')->where('active_status', '1')->whereNull('emp_lokasi_id')->count(),
            'tanpaUnit'        => DB::table('siapmst_employers')->where('active_status', '1')->whereNull('emp_unit_id')->count(),
            'absenHpAktif'     => PengaturanAbsensi::absenHpAktif(),
        ];
    }

    #[On('master.shift.saved')]
    #[On('master.lokasi-absen.saved')]
    #[On('master.unit-kerja.saved')]
    public function segarkanRingkasan(): void
    {
        unset($this->ringkasan, $this->lokasiList, $this->unitList);
    }

    // ─── Tab Aturan ───────────────────────────────────────────────────────────
    private function muat(): void
    {
        PengaturanAbsensi::lupakan();
        $pengaturan = PengaturanAbsensi::get();
        $this->isiForm($pengaturan);
    }

    private function isiForm(object $pengaturan): void
    {
        $this->form = [
            ...array_map(fn($kolom) => (string) (int) $pengaturan->{$kolom}, array_combine(array_keys(PengaturanAbsensi::ATURAN), array_keys(PengaturanAbsensi::ATURAN))),
            'absen_hp_aktif' => (string) $pengaturan->absen_hp_aktif === '1' ? '1' : '0',
        ];
        $this->resetValidation();
    }

    protected function rules(): array
    {
        $rules = ['form.absen_hp_aktif' => 'required|in:0,1'];
        foreach (PengaturanAbsensi::ATURAN as $kolom => [, $minimal, $maksimal]) {
            $rules["form.{$kolom}"] = "required|integer|min:{$minimal}|max:{$maksimal}";
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'integer'  => ':attribute harus angka bulat.',
            'min'      => ':attribute minimal :min.',
            'max'      => ':attribute maksimal :max.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'form.jeda_min_pulang_menit' => 'Jeda minimal masuk → pulang',
            'form.min_lembur_menit'      => 'Minimal lembur',
            'form.batas_pulang_jam'      => 'Batas absen pulang',
            'form.batas_jam_lintas_hari' => 'Batas jam shift malam',
            'form.maks_akurasi_meter'    => 'Akurasi GPS maksimal',
            'form.absen_hp_aktif'        => 'Absen HP',
        ];
    }

    public function simpan(): void
    {
        $this->validate();

        $payload = array_map('intval', array_intersect_key($this->form, PengaturanAbsensi::ATURAN));
        $payload['absen_hp_aktif'] = $this->form['absen_hp_aktif'];
        $payload['updated_by'] = auth()->id();
        $payload['updated_at'] = now();

        DB::table('siapmst_pengaturan_absensis')->updateOrInsert(['id' => 1], $payload);
        PengaturanAbsensi::lupakan();
        unset($this->ringkasan);

        $this->dispatch('toast', type: 'success', message: 'Pengaturan absensi tersimpan — langsung berlaku di kiosk & absen HP.');
    }

    public function batal(): void
    {
        $this->muat();
    }

    /** Isi form dengan nilai bawaan (belum disimpan sampai klik Simpan). */
    public function isiBawaan(): void
    {
        $this->isiForm(PengaturanAbsensi::bawaan());
        $this->dispatch('toast', type: 'info', message: 'Form diisi nilai bawaan — klik Simpan untuk menerapkan.');
    }

    #[Computed]
    public function terakhirDiubah(): ?object
    {
        return DB::table('siapmst_pengaturan_absensis as pg')
            ->leftJoin('users as us', 'us.id', '=', 'pg.updated_by')
            ->where('pg.id', 1)
            ->first(['pg.updated_at', 'us.name']);
    }

    // ─── Tab Lokasi Karyawan ──────────────────────────────────────────────────
    #[Computed]
    public function lokasiList()
    {
        return DB::table('siapmst_lokasi_absens')->orderBy('lokasi_name')->get(['lokasi_id', 'lokasi_name', 'radius_meter', 'active_status']);
    }

    #[Computed]
    public function unitList()
    {
        return UnitKerja::aktif();
    }

    #[Computed]
    public function karyawanList()
    {
        $query = DB::table('siapmst_employers')
            ->where('active_status', '1')
            ->orderBy('emp_name');

        if (trim($this->searchKeyword) !== '') {
            $keyword = mb_strtoupper(trim($this->searchKeyword));
            $query->where(fn($subQuery) => $subQuery->whereRaw('UPPER(emp_id) LIKE ?', ["%{$keyword}%"])
                ->orWhereRaw('UPPER(emp_name) LIKE ?', ["%{$keyword}%"]));
        }
        if ($this->hanyaBelumDiatur) {
            $query->where(fn($subQuery) => $subQuery->whereNull('emp_unit_id')->orWhereNull('emp_lokasi_id'));
        }

        return $query->get(['emp_id', 'emp_name', 'emp_unit_id', 'emp_lokasi_id']);
    }

    public function aturUnitKaryawan(string $empId, string $unitId): void
    {
        if ($unitId !== '' && !$this->unitList->contains('unit_id', $unitId)) {
            return;
        }

        $this->simpanUnit([$empId], $unitId !== '' ? $unitId : null);
        unset($this->ringkasan, $this->karyawanList);

        $this->dispatch('toast', type: 'success', message: 'Unit kerja ' . $empId . ' disimpan.');
    }

    /**
     * Simpan unit; shift default yang bukan milik unit baru (shift khusus unit lain) dikosongkan
     * agar tombol "Isi Sen–Sab" di Jadwal Dinas tidak memakai shift unit lain.
     *
     * @param  array<int, string>  $empIds
     */
    private function simpanUnit(array $empIds, ?string $unitId): void
    {
        DB::table('siapmst_employers')->whereIn('emp_id', $empIds)->update(['emp_unit_id' => $unitId, 'updated_at' => now()]);

        // Shift default yang tidak berlaku di unit baru dikosongkan.
        DB::table('siapmst_employers')
            ->whereIn('emp_id', $empIds)
            ->whereNotNull('emp_shift_default')
            ->whereNotIn('emp_shift_default', Jadwal::shiftBerkode($unitId)->pluck('shift_id')->all() ?: [''])
            ->update(['emp_shift_default' => null]);
    }

    public function aturLokasiKaryawan(string $empId, string $lokasiId): void
    {
        if ($lokasiId !== '' && !$this->lokasiList->contains('lokasi_id', $lokasiId)) {
            return;
        }

        DB::table('siapmst_employers')->where('emp_id', $empId)->update([
            'emp_lokasi_id' => $lokasiId !== '' ? $lokasiId : null,
            'updated_at'    => now(),
        ]);
        unset($this->ringkasan, $this->karyawanList);

        $this->dispatch('toast', type: 'success', message: 'Lokasi kerja ' . $empId . ' disimpan.');
    }

    /**
     * Terapkan unit dan/atau lokasi ke semua karyawan yang sedang tampil (hasil cari / filter).
     * '' = tidak diubah, '-' = dikosongkan.
     */
    public function terapkanMassal(): void
    {
        if (($this->unitMassal !== '' && $this->unitMassal !== '-' && !$this->unitList->contains('unit_id', $this->unitMassal))
            || ($this->lokasiMassal !== '' && $this->lokasiMassal !== '-' && !$this->lokasiList->contains('lokasi_id', $this->lokasiMassal))) {
            return;
        }
        $empIds = $this->karyawanList->pluck('emp_id')->all();
        if (($this->unitMassal === '' && $this->lokasiMassal === '') || $empIds === []) {
            return;
        }

        if ($this->unitMassal !== '') {
            $this->simpanUnit($empIds, $this->unitMassal === '-' ? null : $this->unitMassal);
        }
        if ($this->lokasiMassal !== '') {
            DB::table('siapmst_employers')->whereIn('emp_id', $empIds)->update([
                'emp_lokasi_id' => $this->lokasiMassal === '-' ? null : $this->lokasiMassal,
                'updated_at'    => now(),
            ]);
        }
        $this->reset(['unitMassal', 'lokasiMassal']);
        unset($this->ringkasan, $this->karyawanList);

        $this->dispatch('toast', type: 'success', message: count($empIds) . ' karyawan diperbarui.');
    }
};
?>

<div>
    <x-page-title
        title="Pengaturan Absensi"
        subtitle="Semua setup absensi di satu tempat — aturan, unit kerja, shift, titik lokasi HP, unit & lokasi karyawan" />

    <div class="w-full min-h-[calc(100vh-5rem)] bg-surface-soft dark:bg-gray-900">
        {{-- Lebar penuh (px-6) — sama dengan tab master yang ditanam (Unit Kerja, Shift, Titik Lokasi) --}}
        <div class="px-6 py-4">

            {{-- RINGKASAN — chip ringkas satu baris (klik = buka tab terkait); keterangan di tooltip --}}
            @php
                $ringkasan = $this->ringkasan;
                $titik = fn(bool $baik) => $baik ? 'bg-success' : 'bg-warning';
            @endphp
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" wire:click="pilihTab('aturan')" title="GPS + selfie di /absensi/hp" class="inline-flex items-center gap-2 px-3 py-1.5 text-sm border rounded-full bg-canvas border-hairline hover:border-brand focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green dark:bg-gray-800 dark:border-gray-700 dark:hover:border-brand-lime">
                    <span class="w-2 h-2 rounded-full {{ $titik($ringkasan['absenHpAktif']) }}" aria-hidden="true"></span>
                    <span class="text-muted">Absen HP</span>
                    <b class="{{ $ringkasan['absenHpAktif'] ? 'text-fg-success-strong' : 'text-fg-warning' }}">{{ $ringkasan['absenHpAktif'] ? 'Aktif' : 'Nonaktif' }}</b>
                </button>
                <button type="button" wire:click="pilihTab('shift')" title="Jam kerja & toleransi terlambat" class="inline-flex items-center gap-2 px-3 py-1.5 text-sm border rounded-full bg-canvas border-hairline hover:border-brand focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green dark:bg-gray-800 dark:border-gray-700 dark:hover:border-brand-lime">
                    <span class="text-muted">Shift aktif</span>
                    <b class="text-heading tabular-nums">{{ $ringkasan['shiftAktif'] }}</b>
                </button>
                <button type="button" wire:click="pilihTab('lokasi')" title="Titik RS / klinik + radius absen HP" class="inline-flex items-center gap-2 px-3 py-1.5 text-sm border rounded-full bg-canvas border-hairline hover:border-brand focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green dark:bg-gray-800 dark:border-gray-700 dark:hover:border-brand-lime">
                    <span class="w-2 h-2 rounded-full {{ $titik($ringkasan['lokasiAktif'] > 0) }}" aria-hidden="true"></span>
                    <span class="text-muted">Titik lokasi</span>
                    <b class="tabular-nums {{ $ringkasan['lokasiAktif'] > 0 ? 'text-heading' : 'text-fg-danger-strong' }}">{{ $ringkasan['lokasiAktif'] }}</b>
                    @if ($ringkasan['lokasiAktif'] === 0)
                        <span class="text-xs text-fg-danger-strong">— absen HP belum bisa dipakai</span>
                    @endif
                </button>
                <button type="button" wire:click="pilihTab('lokasi-karyawan')"
                    title="{{ $ringkasan['tanpaLokasi'] }} karyawan tanpa lokasi HP (boleh absen di titik mana pun)" class="inline-flex items-center gap-2 px-3 py-1.5 text-sm border rounded-full bg-canvas border-hairline hover:border-brand focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green dark:bg-gray-800 dark:border-gray-700 dark:hover:border-brand-lime">
                    <span class="w-2 h-2 rounded-full {{ $titik($ringkasan['tanpaUnit'] === 0) }}" aria-hidden="true"></span>
                    <span class="text-muted">Tanpa unit</span>
                    <b class="tabular-nums {{ $ringkasan['tanpaUnit'] > 0 ? 'text-fg-warning' : 'text-heading' }}">{{ $ringkasan['tanpaUnit'] }}</b>
                    <span class="text-xs text-muted tabular-nums">/ {{ $ringkasan['karyawanAktif'] }} karyawan</span>
                </button>
            </div>

            {{-- TAB --}}
            <x-tabs variant="underline" class="mt-4">
                @foreach ($this::TABS as $kunci => $label)
                    <x-tab :active="$tab === $kunci" wire:click="pilihTab('{{ $kunci }}')">{{ $label }}</x-tab>
                @endforeach
            </x-tabs>
        </div>

        {{-- TAB: ATURAN --}}
        @if ($tab === 'aturan')
            <div wire:key="tab-aturan" class="px-6 pb-8">
                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">

                    <x-border-form title="Absen HP (GPS + selfie)">
                        <div class="space-y-4">
                            <x-toggle wire:model.live="form.absen_hp_aktif" trueValue="1" falseValue="0">
                                Absen HP {{ $form['absen_hp_aktif'] === '1' ? 'aktif' : 'nonaktif' }}
                            </x-toggle>
                            <p class="-mt-2 text-[11px] text-muted">Nonaktifkan sementara bila ada masalah — karyawan diarahkan absen di kiosk.</p>

                            <div>
                                <x-input-label value="Akurasi GPS maksimal (meter) *" />
                                <x-text-input wire:model.live.debounce.400ms="form.maks_akurasi_meter" type="number" min="20" max="5000"
                                    :error="$errors->has('form.maks_akurasi_meter')" class="w-full mt-1 sm:w-40" />
                                <p class="mt-1 text-[11px] text-muted">
                                    HP yang GPS-nya kurang akurat dari ini (mis. di dalam gedung) diminta mencoba lagi. Bawaan 500 m.
                                    Radius tiap titik diatur di tab <button type="button" wire:click="pilihTab('lokasi')" class="underline text-brand-green">Titik Lokasi HP</button>.
                                </p>
                                <x-input-error :messages="$errors->get('form.maks_akurasi_meter')" class="mt-1" />
                            </div>

                            <div class="p-3 text-sm border rounded-xl border-hairline bg-surface-soft">
                                Link untuk karyawan:
                                <a href="{{ route('absensi.hp') }}" target="_blank" rel="noopener" class="font-mono underline break-all text-brand-green">{{ route('absensi.hp') }}</a>
                                <p class="mt-1 text-[11px] text-muted">Dibuka dari HP lewat HTTPS. Juga ada di kartu Absensi halaman depan.</p>
                            </div>
                        </div>
                    </x-border-form>

                    <x-border-form title="Masuk & Pulang">
                        <div class="space-y-4">
                            <div>
                                <x-input-label value="Jeda minimal masuk → pulang (menit) *" />
                                <x-text-input wire:model.live.debounce.400ms="form.jeda_min_pulang_menit" type="number" min="1" max="120"
                                    :error="$errors->has('form.jeda_min_pulang_menit')" class="w-full mt-1 sm:w-40" />
                                <p class="mt-1 text-[11px] text-muted">Absen lagi kurang dari ini setelah masuk dianggap dobel, tidak dicatat pulang. Bawaan 10.</p>
                                <x-input-error :messages="$errors->get('form.jeda_min_pulang_menit')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label value="Batas absen pulang setelah jadwal pulang (jam) *" />
                                <x-text-input wire:model.live.debounce.400ms="form.batas_pulang_jam" type="number" min="1" max="12"
                                    :error="$errors->has('form.batas_pulang_jam')" class="w-full mt-1 sm:w-40" />
                                <p class="mt-1 text-[11px] text-muted">
                                    @if (is_numeric($form['batas_pulang_jam']))
                                        Contoh: shift pulang 14:00 → absen pulang masih diterima sampai
                                        <b>{{ sprintf('%02d:00', (14 + (int) $form['batas_pulang_jam']) % 24) }}</b>{{ 14 + (int) $form['batas_pulang_jam'] >= 24 ? ' (besok)' : '' }}.
                                    @endif
                                    Lewat dari itu dianggap lupa absen pulang (diisi HRD lewat Koreksi). Bawaan 6.
                                </p>
                                <x-input-error :messages="$errors->get('form.batas_pulang_jam')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label value="Batas jam shift malam masih milik kemarin *" />
                                <x-text-input wire:model.live.debounce.400ms="form.batas_jam_lintas_hari" type="number" min="1" max="23"
                                    :error="$errors->has('form.batas_jam_lintas_hari')" class="w-full mt-1 sm:w-40" />
                                <p class="mt-1 text-[11px] text-muted">
                                    @if (is_numeric($form['batas_jam_lintas_hari']))
                                        Absen shift lintas hari (mis. 21:00–07:00) sebelum jam <b>{{ sprintf('%02d:00', (int) $form['batas_jam_lintas_hari']) }}</b> tercatat di tanggal kemarin.
                                    @endif
                                    Bawaan 12.
                                </p>
                                <x-input-error :messages="$errors->get('form.batas_jam_lintas_hari')" class="mt-1" />
                            </div>

                            <p class="p-3 text-[11px] border rounded-xl border-hairline bg-surface-soft text-muted">
                                Toleransi terlambat diatur per shift di tab
                                <button type="button" wire:click="pilihTab('shift')" class="underline text-brand-green">Shift &amp; Toleransi</button>.
                            </p>
                        </div>
                    </x-border-form>

                    <x-border-form title="Lembur">
                        <div>
                            <x-input-label value="Minimal lembur (menit) *" />
                            <x-text-input wire:model.live.debounce.400ms="form.min_lembur_menit" type="number" min="0" max="240"
                                :error="$errors->has('form.min_lembur_menit')" class="w-full mt-1 sm:w-40" />
                            <p class="mt-1 text-[11px] text-muted">
                                Pulang lewat jadwal kurang dari ini (mis. beres-beres) tidak dihitung lembur. Lembur yang tercatat
                                berstatus <i>Menunggu</i> dan dinilai di Rekap Bulanan. Bawaan 30.
                            </p>
                            <x-input-error :messages="$errors->get('form.min_lembur_menit')" class="mt-1" />
                        </div>
                    </x-border-form>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 mt-4">
                    <p class="text-xs text-muted">
                        @if ($this->terakhirDiubah?->updated_at)
                            Terakhir diubah {{ \App\Support\Format::waktu($this->terakhirDiubah->updated_at) }}{{ $this->terakhirDiubah->name ? ' oleh ' . $this->terakhirDiubah->name : '' }}
                        @endif
                    </p>
                    <div class="flex flex-wrap justify-end gap-2">
                        <x-secondary-button type="button" wire:click="isiBawaan">Isi nilai bawaan</x-secondary-button>
                        <x-secondary-button type="button" wire:click="batal">Batal</x-secondary-button>
                        <x-primary-button type="button" wire:click="simpan" wire:loading.attr="disabled" wire:target="simpan">
                            <span wire:loading.remove wire:target="simpan">Simpan</span>
                            <span wire:loading wire:target="simpan">Menyimpan...</span>
                        </x-primary-button>
                    </div>
                </div>
            </div>
        @endif

        {{-- TAB: UNIT KERJA --}}
        @if ($tab === 'unit')
            <livewire:pages::master.master-unit-kerja.master-unit-kerja :embedded="true" wire:key="tab-unit" />
        @endif

        {{-- TAB: SHIFT --}}
        @if ($tab === 'shift')
            <livewire:pages::master.master-shift.master-shift :embedded="true" wire:key="tab-shift" />
        @endif

        {{-- TAB: TITIK LOKASI --}}
        @if ($tab === 'lokasi')
            <livewire:pages::master.master-lokasi-absen.master-lokasi-absen :embedded="true" wire:key="tab-lokasi" />
        @endif

        {{-- TAB: UNIT & LOKASI KARYAWAN --}}
        @if ($tab === 'lokasi-karyawan')
            <div wire:key="tab-lokasi-karyawan" class="px-6 pb-8">
                <div class="flex flex-col gap-3 py-3 lg:flex-row lg:items-end lg:justify-between">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <x-text-input type="text" wire:model.live.debounce.300ms="searchKeyword"
                            placeholder="Cari ID / nama karyawan..." class="w-full sm:w-72" />
                        <x-toggle wire:model.live="hanyaBelumDiatur" :trueValue="true" :falseValue="false">Hanya yang unit / lokasinya belum diatur</x-toggle>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs font-semibold text-muted">Atur sekaligus:</span>
                        <div class="w-48">
                        <x-select-input wire:model="unitMassal">
                            <option value="">— unit tetap —</option>
                            @foreach ($this->unitList as $unit)
                                <option value="{{ $unit->unit_id }}">{{ $unit->unit_name }}</option>
                            @endforeach
                            <option value="-">Kosongkan unit</option>
                        </x-select-input>
                        </div>
                        <div class="w-48">
                        <x-select-input wire:model="lokasiMassal">
                            <option value="">— lokasi tetap —</option>
                            @foreach ($this->lokasiList as $lokasi)
                                <option value="{{ $lokasi->lokasi_id }}">{{ $lokasi->lokasi_name }}</option>
                            @endforeach
                            <option value="-">Kosongkan (semua titik)</option>
                        </x-select-input>
                        </div>
                        <x-secondary-button type="button" wire:click="terapkanMassal" wire:loading.attr="disabled"
                            wire:confirm="Terapkan ke semua karyawan yang tampil di tabel?">
                            Terapkan ke {{ $this->karyawanList->count() }} karyawan
                        </x-secondary-button>
                    </div>
                </div>

                @if ($this->unitList->isEmpty())
                    <p class="p-3 mb-3 text-sm border rounded-xl border-amber-300 bg-amber-50 text-amber-900">
                        Belum ada unit kerja. Tambahkan dulu di tab
                        <button type="button" wire:click="pilihTab('unit')" class="font-semibold underline">Unit Kerja</button>.
                    </p>
                @endif
                @if ($this->lokasiList->isEmpty())
                    <p class="p-3 mb-3 text-sm border rounded-xl border-amber-300 bg-amber-50 text-amber-900">
                        Belum ada titik lokasi. Tambahkan dulu di tab
                        <button type="button" wire:click="pilihTab('lokasi')" class="font-semibold underline">Titik Lokasi HP</button>.
                    </p>
                @endif

                <div class="overflow-x-auto border bg-canvas border-hairline rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                    <table class="ds-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nama</th>
                                <th>Unit Kerja</th>
                                <th>Lokasi Absen HP</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->karyawanList as $row)
                                <tr wire:key="lokasi-karyawan-{{ $row->emp_id }}-{{ $row->emp_unit_id }}-{{ $row->emp_lokasi_id }}">
                                    <td class="ds-td-token">{{ $row->emp_id }}</td>
                                    <td class="ds-td-strong">{{ $row->emp_name }}</td>
                                    <td>
                                        <div class="w-56">
                                        <x-select-input
                                            x-on:change="$wire.aturUnitKaryawan('{{ $row->emp_id }}', $event.target.value)">
                                            <option value="" @selected(!$row->emp_unit_id)>— belum diatur —</option>
                                            @foreach ($this->unitList as $unit)
                                                <option value="{{ $unit->unit_id }}" @selected($row->emp_unit_id === $unit->unit_id)>{{ $unit->unit_name }}</option>
                                            @endforeach
                                        </x-select-input>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="w-64">
                                        <x-select-input
                                            x-on:change="$wire.aturLokasiKaryawan('{{ $row->emp_id }}', $event.target.value)">
                                            <option value="" @selected(!$row->emp_lokasi_id)>— semua titik aktif —</option>
                                            @foreach ($this->lokasiList as $lokasi)
                                                <option value="{{ $lokasi->lokasi_id }}" @selected($row->emp_lokasi_id === $lokasi->lokasi_id)>
                                                    {{ $lokasi->lokasi_name }}{{ (string) $lokasi->active_status === '1' ? '' : ' (non-aktif)' }}
                                                </option>
                                            @endforeach
                                        </x-select-input>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-10 text-center text-muted">Tidak ada karyawan untuk filter ini.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-[11px] text-muted">Perubahan langsung tersimpan saat pilihan diganti.</p>
            </div>
        @endif
    </div>
</div>
