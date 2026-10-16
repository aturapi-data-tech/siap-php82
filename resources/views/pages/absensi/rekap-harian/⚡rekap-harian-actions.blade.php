<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Support\Absensi;
use App\Http\Traits\Concerns\WithRenderVersioningTrait;

/**
 * Isi / koreksi absensi satu karyawan pada satu tanggal kerja (sumber = MANUAL).
 * - Hadir  : wajib shift + jam masuk → status H/T dihitung otomatis (sama dgn kiosk).
 * - I/S/C/A/L: tanpa jam, cukup keterangan (wajib untuk I/S/C).
 * - Dobel shift (tukar jadwal, mis. Pagi + Sore): tetap satu catatan; HRD memilih shift kedua &
 *   jam pulang setelah shift kedua → lembur = jam kerja shift kedua (Absensi::lemburDobelShift),
 *   langsung berstatus Dihitung karena sudah ditetapkan HRD.
 */
new class extends Component {
    use WithRenderVersioningTrait;

    public string $empId   = '';
    public string $empName = '';
    public string $tanggal = ''; // dd/mm/yyyy (tanggal kerja)
    public ?int   $absId   = null;
    public array  $renderVersions = [];
    protected array $renderAreas  = ['modal'];

    public array $form = [];

    public function mount(): void
    {
        $this->registerAreas(['modal']);
        $this->resetForm();
    }

    #[Computed]
    public function shifts()
    {
        return Absensi::shiftAktif();
    }

    /** Pilihan shift di form = shift unit karyawan ini (+ shift yang sudah tercatat, agar data lama tetap tampil). */
    #[Computed]
    public function shiftPilihan()
    {
        if ($this->empId === '') {
            return $this->shifts;
        }
        $shiftUnitId = Absensi::shiftPilihan($this->empId, $this->shifts)->pluck('shift_id')->all();
        $tercatat = [$this->form['shift_id'] ?? '', $this->form['dobel_shift_id'] ?? ''];

        return $this->shifts->filter(fn($shift) => in_array($shift->shift_id, [...$shiftUnitId, ...$tercatat], true))->values();
    }

    // ─── Open ─────────────────────────────────────────────────────────────────
    #[On('absensi.rekap-harian.openInput')]
    public function openInput(string $empId, string $tanggal): void
    {
        $dataKaryawan = DB::table('siapmst_employers')->where('emp_id', $empId)->first(['emp_id', 'emp_name']);
        if (!$dataKaryawan) {
            $this->dispatch('toast', type: 'error', message: 'Data karyawan tidak ditemukan.');
            return;
        }

        $this->resetForm();
        $this->empId   = (string) $dataKaryawan->emp_id;
        $this->empName = (string) $dataKaryawan->emp_name;
        $this->tanggal = $tanggal;

        $row = Absensi::absenTanggal($this->empId, Carbon::createFromFormat('!d/m/Y', $tanggal));
        $this->absId = $row ? (int) $row->abs_id : null;

        if ($row) {
            $this->form = [
                'jenis'      => in_array($row->abs_status, ['H', 'T'], true) ? 'HADIR' : $row->abs_status,
                'shift_id'   => (string) ($row->shift_id ?? ''),
                'jam_masuk'  => $row->jam_masuk ? Carbon::parse($row->jam_masuk)->format('H:i') : '',
                'jam_pulang' => $row->jam_pulang ? Carbon::parse($row->jam_pulang)->format('H:i') : '',
                'dobel_shift_id' => (string) ($row->dobel_shift_id ?? ''),
                'keterangan' => (string) ($row->keterangan ?? ''),
            ];
        }

        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'rekap-harian-actions');
    }

    // ─── Validasi ─────────────────────────────────────────────────────────────
    protected function rules(): array
    {
        $hadir = $this->form['jenis'] === 'HADIR';

        return [
            'form.jenis'      => 'required|in:HADIR,I,S,C,A,L',
            'form.shift_id'   => $hadir ? 'required|exists:siapmst_shifts,shift_id' : 'nullable',
            'form.jam_masuk'  => $hadir ? 'required|date_format:H:i' : 'nullable',
            'form.jam_pulang' => $hadir ? ($this->form['dobel_shift_id'] !== '' ? 'required|date_format:H:i' : 'nullable|date_format:H:i') : 'nullable',
            'form.dobel_shift_id' => $hadir ? 'nullable|exists:siapmst_shifts,shift_id|different:form.shift_id' : 'nullable',
            'form.keterangan' => in_array($this->form['jenis'], ['I', 'S', 'C'], true) ? 'required|string|max:200' : 'nullable|string|max:200',
        ];
    }

    protected function messages(): array
    {
        return [
            'required'    => ':attribute wajib diisi.',
            'in'          => ':attribute tidak valid.',
            'exists'      => ':attribute tidak ada di master shift.',
            'date_format' => ':attribute harus berformat jam HH:MM (mis. 07:05).',
            'max'         => ':attribute maksimal :max karakter.',
            'form.dobel_shift_id.different' => 'Shift kedua harus berbeda dari shift pertama.',
            'form.jam_pulang.required'      => 'Jam Pulang (setelah shift kedua) wajib diisi untuk dobel shift.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'form.jenis'      => 'Status',
            'form.shift_id'   => 'Shift',
            'form.jam_masuk'  => 'Jam Masuk',
            'form.jam_pulang' => 'Jam Pulang',
            'form.dobel_shift_id' => 'Shift Kedua',
            'form.keterangan' => 'Keterangan',
        ];
    }

    // ─── Save ─────────────────────────────────────────────────────────────────
    public function save(): void
    {
        $this->validate();

        $tanggalKerja = Carbon::createFromFormat('!d/m/Y', $this->tanggal);
        $form = $this->form;

        $payload = [
            'shift_id'        => null,
            'jam_masuk'       => null,
            'jam_pulang'      => null,
            'abs_status'      => $form['jenis'],
            'terlambat_menit' => 0,
            'lembur_menit'    => 0,
            'pulang_cepat_menit' => 0,
            'dobel_shift_id'  => null,
            'keterangan'      => trim($form['keterangan']) !== '' ? trim($form['keterangan']) : null,
            'sumber'          => 'MANUAL',
            'updated_by'      => auth()->id(),
            'updated_at'      => now(),
        ];

        if ($form['jenis'] === 'HADIR') {
            $shift = $this->shifts->firstWhere('shift_id', $form['shift_id']);
            $masuk = $this->waktuPada($tanggalKerja, $form['jam_masuk'], $shift, false);
            [$status, $terlambat] = Absensi::statusMasuk($shift, $tanggalKerja, $masuk);

            $pulang = null;
            if ($form['jam_pulang'] !== '') {
                $pulang = $this->waktuPada($tanggalKerja, $form['jam_pulang'], $shift, true);
                // Jam pulang ≤ jam masuk = lewat tengah malam (lembur shift siang, dst.).
                if ($pulang->lessThanOrEqualTo($masuk)) {
                    $pulang->addDay();
                }
                if ($masuk->diffInHours($pulang) > 24) {
                    $this->addError('form.jam_pulang', 'Jam Pulang tidak masuk akal (lebih dari 24 jam setelah masuk).');
                    return;
                }
            }

            $shiftKedua = $form['dobel_shift_id'] !== '' ? $this->shifts->firstWhere('shift_id', $form['dobel_shift_id']) : null;
            if ($shiftKedua) {
                $pulang = $this->pulangDobelShift($tanggalKerja, $masuk, $form['jam_pulang']);
            }

            $payload = [...$payload,
                'shift_id'        => $shift->shift_id,
                'jam_masuk'       => $masuk,
                'jam_pulang'      => $pulang,
                'abs_status'      => $status,
                'terlambat_menit' => $terlambat,
                'lembur_menit'    => match (true) {
                    !$pulang            => 0,
                    $shiftKedua !== null => Absensi::lemburDobelShift($shift, $shiftKedua, $tanggalKerja, $pulang),
                    default             => Absensi::lemburMenit($shift, $tanggalKerja, $pulang),
                },
                // Dobel shift: pulang setelah shift kedua → tidak dinilai pulang cepat terhadap shift pertama.
                'pulang_cepat_menit' => $pulang && !$shiftKedua ? Absensi::pulangCepatMenit($shift, $tanggalKerja, $pulang) : 0,
                'dobel_shift_id'  => $shiftKedua?->shift_id,
                // Jam masuk sudah dilengkapi → keterangan otomatis "lupa absen masuk" tidak berlaku lagi.
                'keterangan'      => $payload['keterangan'] === Absensi::KETERANGAN_LUPA_MASUK ? null : $payload['keterangan'],
            ];
        }

        // Dobel shift: lembur sudah ditetapkan HRD di sini → langsung Dihitung.
        $payload = [...$payload, ...($payload['dobel_shift_id'] && $payload['lembur_menit'] > 0
            ? ['lembur_status' => 'Y', 'lembur_oleh' => auth()->id(), 'lembur_dinilai_at' => now()]
            : $this->penilaianLembur((int) $payload['lembur_menit']))];

        if ($this->absId) {
            DB::table('siaptxn_absensis')->where('abs_id', $this->absId)->update($payload);
        } else {
            DB::table('siaptxn_absensis')->insert([
                ...$payload,
                'emp_id'     => $this->empId,
                'abs_date'   => Absensi::tanggalSql($tanggalKerja),
                'created_at' => now(),
            ]);
        }

        $this->dispatch('toast', type: 'success', message: 'Absensi ' . $this->empName . ' tersimpan.');
        $this->closeModal();
        $this->dispatch('absensi.rekap-harian.saved');
    }

    /**
     * Penilaian lembur setelah koreksi: keputusan admin sebelumnya dipertahankan hanya bila
     * menit lembur tidak berubah; berubah / baru → kembali 'Menunggu' untuk dinilai ulang.
     */
    private function penilaianLembur(int $lemburMenit): array
    {
        $lama = $this->absId
            ? DB::table('siaptxn_absensis')->where('abs_id', $this->absId)->first(['lembur_menit', 'lembur_status', 'lembur_oleh', 'lembur_dinilai_at'])
            : null;

        if ($lemburMenit > 0 && $lama && (int) $lama->lembur_menit === $lemburMenit && $lama->lembur_status) {
            return [];
        }

        return [
            'lembur_status'     => Absensi::statusLemburAwal($lemburMenit),
            'lembur_oleh'       => null,
            'lembur_dinilai_at' => null,
        ];
    }

    /**
     * Jam HH:MM → waktu lengkap pada tanggal kerja. Untuk shift lintas hari, jam yang
     * lebih kecil dari jam masuk shift jatuh di hari berikutnya (mis. pulang 07:00).
     */
    private function waktuPada(Carbon $tanggalKerja, string $jam, object $shift, bool $isPulang): Carbon
    {
        [$angkaJam, $angkaMenit] = array_map('intval', explode(':', $jam));
        $waktu = $tanggalKerja->copy()->setTime($angkaJam, $angkaMenit);

        if ((string) $shift->lintas_hari === '1' && $jam < $shift->jam_masuk && ($isPulang || $angkaJam < 12)) {
            $waktu->addDay();
        }

        return $waktu;
    }

    /**
     * Jam pulang dobel shift: jam pulang ≤ jam masuk = esok hari (mis. Malam + Pagi pulang 12:00,
     * Sore + Malam pulang 07:00).
     */
    private function pulangDobelShift(Carbon $tanggalKerja, Carbon $masuk, string $jam): Carbon
    {
        [$jamPulang, $menitPulang] = array_map('intval', explode(':', $jam));
        $pulang = $tanggalKerja->copy()->setTime($jamPulang, $menitPulang);

        return $pulang->lessThanOrEqualTo($masuk) ? $pulang->addDay() : $pulang;
    }

    /** Pratinjau lembur dobel shift di form (sebelum disimpan); null bila isian belum lengkap. */
    #[Computed]
    public function pratinjauLemburDobel(): ?int
    {
        $form = $this->form;
        $shift = $this->shifts->firstWhere('shift_id', $form['shift_id']);
        $shiftKedua = $this->shifts->firstWhere('shift_id', $form['dobel_shift_id']);
        if (!$shift || !$shiftKedua || $shift === $shiftKedua || !$this->tanggal
            || !preg_match('/^\d{2}:\d{2}$/', $form['jam_masuk']) || !preg_match('/^\d{2}:\d{2}$/', $form['jam_pulang'])) {
            return null;
        }

        $tanggalKerja = Carbon::createFromFormat('!d/m/Y', $this->tanggal);
        $masuk = $this->waktuPada($tanggalKerja, $form['jam_masuk'], $shift, false);

        return Absensi::lemburDobelShift($shift, $shiftKedua, $tanggalKerja, $this->pulangDobelShift($tanggalKerja, $masuk, $form['jam_pulang']));
    }

    // ─── Delete ───────────────────────────────────────────────────────────────
    public function hapus(): void
    {
        if (!$this->absId) {
            return;
        }

        DB::table('siaptxn_absensis')->where('abs_id', $this->absId)->delete();

        $this->dispatch('toast', type: 'success', message: 'Absensi ' . $this->empName . ' dihapus — kembali "Belum absen".');
        $this->closeModal();
        $this->dispatch('absensi.rekap-harian.saved');
    }

    // ─── Close ────────────────────────────────────────────────────────────────
    public function closeModal(): void
    {
        $this->resetForm();
        $this->dispatch('close-modal', name: 'rekap-harian-actions');
        $this->resetVersion();
    }

    private function resetForm(): void
    {
        $this->absId = null;
        $this->form = [
            'jenis'      => 'HADIR',
            'shift_id'   => '',
            'jam_masuk'  => '',
            'jam_pulang' => '',
            'dobel_shift_id' => '',
            'keterangan' => '',
        ];
        $this->resetValidation();
    }
};
?>

<div>
    <x-modal name="rekap-harian-actions" size="full" height="full" focusable>
        <x-dirty-modal-content
            name="rekap-harian-actions"
            event="absensi.rekap-harian.saved"
            label="Absensi"
            :wireKey="$this->renderKey('modal', [$empId, $tanggal])">

            {{-- HEADER — standar modal modul dokumen (x-modul-dokumen.header, diport dari sirus) --}}
            <x-modul-dokumen.header :judul="($absId ? 'Koreksi' : 'Isi') . ' Absensi — ' . ($empName)"
                ikon="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" tutupAlpine="tryClose()">
                <span class="font-mono">{{ $empId }}</span> · Tanggal kerja <b>{{ $tanggal }}</b>
                <x-slot:badge><x-badge class="shrink-0 whitespace-nowrap" :variant="$absId ? 'warning' : 'success'">{{ $absId ? 'Mode: Koreksi' : 'Mode: Isi Manual' }}</x-badge></x-slot:badge>
            </x-modul-dokumen.header>

            {{-- BODY --}}
            <div class="flex-1 px-4 py-4 bg-surface-soft dark:bg-gray-950/20" x-enter-chain>
                <div class="max-w-3xl">
                    <x-border-form title="Status Kehadiran">
                        <div class="space-y-4">
                            {{-- Jenis --}}
                            <div class="flex flex-wrap gap-2">
                                @foreach (['HADIR' => 'Hadir', 'I' => 'Izin', 'S' => 'Sakit', 'C' => 'Cuti', 'A' => 'Alpa', 'L' => 'Libur'] as $kode => $nama)
                                    <label class="inline-flex items-center gap-2 px-4 py-2 border rounded-xl cursor-pointer
                                        {{ $form['jenis'] === $kode ? 'border-brand-green bg-brand-green/10 font-semibold' : 'border-hairline' }}">
                                        <input type="radio" wire:model.live="form.jenis" value="{{ $kode }}" class="text-brand-green focus:ring-brand-green" />
                                        {{ $nama }}
                                    </label>
                                @endforeach
                            </div>
                            <x-input-error :messages="$errors->get('form.jenis')" class="mt-1" />

                            @if ($form['jenis'] === 'HADIR')
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                    <div>
                                        <x-input-label value="Shift *" />
                                        <x-select-input wire:model.live="form.shift_id" :error="$errors->has('form.shift_id')" class="mt-1">
                                            <option value="">— pilih —</option>
                                            @foreach ($this->shiftPilihan as $shift)
                                                <option value="{{ $shift->shift_id }}">{{ $shift->shift_name }} ({{ $shift->jam_masuk }}–{{ $shift->jam_pulang }})</option>
                                            @endforeach
                                        </x-select-input>
                                        <x-input-error :messages="$errors->get('form.shift_id')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-input-label value="Jam Masuk *" />
                                        <x-text-input wire:model="form.jam_masuk" x-mask="99:99" placeholder="HH:MM"
                                            :error="$errors->has('form.jam_masuk')" class="w-full mt-1 font-mono" />
                                        <x-input-error :messages="$errors->get('form.jam_masuk')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-input-label value="Jam Pulang" />
                                        <x-text-input wire:model.live.debounce.400ms="form.jam_pulang" x-mask="99:99" placeholder="HH:MM"
                                            :error="$errors->has('form.jam_pulang')" class="w-full mt-1 font-mono"
                                            x-on:keydown.enter.prevent="$wire.save()" />
                                        <x-input-error :messages="$errors->get('form.jam_pulang')" class="mt-1" />
                                    </div>
                                </div>
                                <p class="text-xs text-muted dark:text-gray-400">
                                    Status Hadir/Terlambat & lembur dihitung otomatis dari jadwal shift
                                    (lembur dihitung bila pulang ≥ {{ \App\Support\PengaturanAbsensi::minLemburMenit() }} menit lewat jadwal).
                                    Jam pulang yang lebih kecil dari jam masuk = lewat tengah malam (hari berikutnya).
                                </p>

                                {{-- Dobel shift --}}
                                <div class="p-3 border rounded-xl {{ $form['dobel_shift_id'] !== '' ? 'border-indigo-300 bg-indigo-50 dark:bg-indigo-950/30' : 'border-hairline' }}">
                                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                        <div>
                                            <x-input-label value="Dobel shift — shift kedua" />
                                            <x-select-input wire:model.live="form.dobel_shift_id" :error="$errors->has('form.dobel_shift_id')" class="mt-1">
                                                <option value="">— tidak dobel shift —</option>
                                                @foreach ($this->shiftPilihan as $shift)
                                                    @continue($shift->shift_id === $form['shift_id'])
                                                    <option value="{{ $shift->shift_id }}">{{ $shift->shift_name }} ({{ $shift->jam_masuk }}–{{ $shift->jam_pulang }})</option>
                                                @endforeach
                                            </x-select-input>
                                            <x-input-error :messages="$errors->get('form.dobel_shift_id')" class="mt-1" />
                                        </div>
                                        <div class="text-xs sm:col-span-2 text-muted dark:text-gray-400 sm:pt-6">
                                            @if ($form['dobel_shift_id'] === '')
                                                Isi bila karyawan menggantikan jadwal rekan (mis. Pagi + Sore). Jam Pulang = jam pulang setelah shift kedua.
                                            @elseif ($this->pratinjauLemburDobel !== null)
                                                Lembur = jam kerja di shift kedua:
                                                <b class="text-indigo-700">{{ \App\Support\Absensi::labelMenit($this->pratinjauLemburDobel) ?: '0 mnt' }}</b>
                                                — jeda antar-shift tidak dihitung. Tersimpan langsung sebagai lembur <b>Dihitung</b>.
                                            @else
                                                Isi Jam Pulang (setelah shift kedua) untuk melihat lembur.
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <div>
                                <x-input-label value="{{ in_array($form['jenis'], ['I', 'S', 'C'], true) ? 'Keterangan *' : 'Keterangan' }}" />
                                <x-text-input wire:model="form.keterangan" maxlength="200"
                                    placeholder="{{ $form['jenis'] === 'S' ? 'Mis. surat dokter' : ($form['jenis'] === 'C' ? 'Mis. cuti tahunan' : 'Opsional') }}"
                                    :error="$errors->has('form.keterangan')" class="w-full mt-1"
                                    x-on:keydown.enter.prevent="$wire.save()" />
                                <x-input-error :messages="$errors->get('form.keterangan')" class="mt-1" />
                            </div>
                        </div>
                    </x-border-form>
                </div>
            </div>

            {{-- FOOTER --}}
            <div class="sticky bottom-0 z-10 px-6 py-4 mt-auto border-t bg-surface-soft border-hairline dark:bg-gray-900 dark:border-gray-700">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        @if ($absId)
                            <x-confirm-button variant="danger" action="hapus()" title="Hapus Absensi"
                                message="Hapus absensi {{ $empName }} tanggal {{ $tanggal }}? Status kembali menjadi Belum absen."
                                confirmText="Ya, hapus" cancelText="Batal">
                                Hapus Absensi
                            </x-confirm-button>
                        @endif
                    </div>
                    <div class="flex justify-end gap-2">
                        <x-secondary-button type="button" x-on:click="tryClose()">Batal</x-secondary-button>
                        <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading.remove wire:target="save">Simpan</span>
                            <span wire:loading wire:target="save">Menyimpan...</span>
                        </x-primary-button>
                    </div>
                </div>
            </div>

        </x-dirty-modal-content>
    </x-modal>
</div>
