<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Computed;
use App\Support\UnitKerja;
use App\Support\Jadwal;
use Illuminate\Validation\Rule;
use App\Http\Traits\Concerns\WithRenderVersioningTrait;

/**
 * Modal CRUD `siapmst_shifts`. lintas_hari diturunkan otomatis:
 * jam pulang <= jam masuk berarti pulang esok hari (mis. 21:00–07:00).
 * Unit kosong = shift umum (dipakai unit yang belum punya shift khusus — UnitKerja::saringShift).
 * Kode jadwal unik dalam cakupan: sesama shift umum, atau sesama shift satu unit. Shift unit boleh
 * memakai kode shift umum (mis. "P" Gizi 06:00 vs "P" umum 07:00) — unit yang punya shift khusus
 * hanya memakai shift khususnya, jadi tidak tertukar.
 * Shift fleksibel (mis. Poli, ikut praktik dokter): jam = jendela absen saja; tanpa terlambat,
 * pulang cepat, & lembur otomatis (App\Support\Absensi::fleksibel).
 */
new class extends Component {
    use WithRenderVersioningTrait;

    public string $formMode        = 'create'; // create|edit
    public string $originalShiftId = '';
    public array  $renderVersions  = [];
    protected array $renderAreas   = ['modal'];

    public array $form = [];

    public function mount(): void
    {
        $this->registerAreas(['modal']);
        $this->resetForm();
    }

    #[Computed]
    public function unitList()
    {
        return UnitKerja::aktif();
    }

    // ─── Open Create ──────────────────────────────────────────────────────────
    #[On('master.shift.openCreate')]
    public function openCreate(): void
    {
        $this->resetForm();
        $this->formMode        = 'create';
        $this->originalShiftId = '';
        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'master-shift-actions');
        $this->dispatch('focus-shift-id');
    }

    // ─── Open Edit ────────────────────────────────────────────────────────────
    #[On('master.shift.openEdit')]
    public function openEdit(string $shiftId): void
    {
        $row = DB::table('siapmst_shifts')->where('shift_id', $shiftId)->first();
        if (!$row) {
            $this->dispatch('toast', type: 'error', message: 'Data shift tidak ditemukan.');
            return;
        }

        $this->resetForm();
        $this->formMode        = 'edit';
        $this->originalShiftId = $shiftId;
        $this->form = [
            'shift_id'        => (string) $row->shift_id,
            'shift_name'      => (string) $row->shift_name,
            'shift_kode'      => (string) ($row->shift_kode ?? ''),
            'jam_masuk'       => (string) $row->jam_masuk,
            'jam_pulang'      => (string) $row->jam_pulang,
            'toleransi_menit' => (string) (int) $row->toleransi_menit,
            'unit_id'         => (string) ($row->unit_id ?? ''),
            'shift_warna'     => (string) ($row->shift_warna ?? ''),
            'shift_fleksibel' => (string) ($row->shift_fleksibel ?? '0') === '1' ? '1' : '0',
            'active_status'   => (string) $row->active_status === '1' ? '1' : '0',
        ];

        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'master-shift-actions');
        $this->dispatch('focus-shift-name');
    }

    // ─── Delete ───────────────────────────────────────────────────────────────
    #[On('master.shift.requestDelete')]
    public function deleteShift(string $shiftId): void
    {
        try {
            $deleted = DB::table('siapmst_shifts')->where('shift_id', $shiftId)->delete();
            if ($deleted === 0) {
                $this->dispatch('toast', type: 'error', message: 'Data shift tidak ditemukan.');
                return;
            }

            $this->dispatch('toast', type: 'success', message: 'Shift berhasil dihapus.');
            $this->dispatch('master.shift.saved');
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'ORA-02292')) {
                $this->dispatch('toast', type: 'error', message: 'Shift tidak bisa dihapus karena sudah dipakai di data absensi. Non-aktifkan saja.');
                return;
            }
            throw $exception;
        }
    }

    // ─── Validasi ─────────────────────────────────────────────────────────────
    protected function rules(): array
    {
        return [
            'form.shift_id' => $this->formMode === 'create'
                ? ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9_\-]+$/', 'unique:siapmst_shifts,shift_id']
                : ['required', 'string'],
            'form.shift_name'      => 'required|string|max:50',
            'form.shift_kode'      => ['required', 'string', 'max:2', 'regex:/^[A-Za-z0-9]+$/',
                \Illuminate\Validation\Rule::notIn(['I', 'C', 'i', 'c']),
                function ($attribute, $value, $fail) {
                    if ($bentrok = $this->shiftBerkodeSama((string) $value)) {
                        $fail("Kode Jadwal sudah dipakai {$bentrok->shift_name}"
                            . ($bentrok->unit_name ? " (unit {$bentrok->unit_name})" : ' (semua unit)') . '.');
                    }
                }],
            'form.unit_id'         => 'nullable|exists:siapmst_unit_kerjas,unit_id',
            'form.shift_warna'     => ['nullable', Rule::in(array_keys(Jadwal::PALET))],
            'form.jam_masuk'       => 'required|date_format:H:i',
            'form.jam_pulang'      => 'required|date_format:H:i|different:form.jam_masuk',
            'form.toleransi_menit' => 'required|integer|min:0|max:240',
            'form.shift_fleksibel' => 'required|in:0,1',
            'form.active_status'   => 'required|in:0,1',
        ];
    }

    protected function messages(): array
    {
        return [
            'required'    => ':attribute wajib diisi.',
            'max'         => ':attribute maksimal :max.',
            'min'         => ':attribute minimal :min.',
            'unique'      => ':attribute sudah digunakan.',
            'integer'     => ':attribute harus angka.',
            'in'          => ':attribute tidak valid.',
            'date_format' => ':attribute harus berformat jam HH:MM (mis. 07:00).',
            'different'   => 'Jam Pulang tidak boleh sama dengan Jam Masuk.',
            'form.shift_id.regex' => ':attribute hanya boleh huruf, angka, strip, atau garis bawah.',
            'form.shift_kode.regex' => ':attribute hanya huruf/angka.',
            'form.shift_kode.not_in' => ':attribute I dan C dipakai untuk Izin & Cuti di jadwal.',
            'form.shift_warna.in' => ':attribute harus salah satu pilihan palet.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'form.shift_id'        => 'Kode Shift',
            'form.shift_name'      => 'Nama Shift',
            'form.shift_kode'      => 'Kode Jadwal',
            'form.jam_masuk'       => 'Jam Masuk',
            'form.jam_pulang'      => 'Jam Pulang',
            'form.toleransi_menit' => 'Toleransi',
            'form.unit_id'         => 'Unit',
            'form.shift_warna'     => 'Warna',
            'form.shift_fleksibel' => 'Shift Fleksibel',
            'form.active_status'   => 'Status',
        ];
    }

    // ─── Save ─────────────────────────────────────────────────────────────────
    public function save(): void
    {
        $this->validate();

        $form = $this->form;
        $payload = [
            'shift_name'      => trim($form['shift_name']),
            'shift_kode'      => mb_strtoupper(trim($form['shift_kode'])),
            'jam_masuk'       => $form['jam_masuk'],
            'jam_pulang'      => $form['jam_pulang'],
            'lintas_hari'     => $form['jam_pulang'] <= $form['jam_masuk'] ? '1' : '0',
            'unit_id'         => $form['unit_id'] !== '' ? $form['unit_id'] : null,
            'shift_warna'     => $form['shift_warna'] !== '' ? mb_strtolower($form['shift_warna']) : null,
            'shift_fleksibel' => $form['shift_fleksibel'],
            // Shift fleksibel tidak menilai terlambat → toleransi tidak dipakai.
            'toleransi_menit' => $form['shift_fleksibel'] === '1' ? 0 : (int) $form['toleransi_menit'],
            'active_status'   => $form['active_status'],
            'updated_at'      => now(),
        ];

        if ($this->formMode === 'create') {
            DB::table('siapmst_shifts')->insert([
                'shift_id'   => mb_strtoupper(trim($form['shift_id'])),
                'created_at' => now(),
                ...$payload,
            ]);
        } else {
            DB::table('siapmst_shifts')->where('shift_id', $this->originalShiftId)->update($payload);
        }

        $this->dispatch('toast', type: 'success', message: 'Data shift berhasil disimpan.');
        $this->closeModal();
        $this->dispatch('master.shift.saved');
    }

    /**
     * Shift lain yang kodenya bentrok dalam cakupan unit form ini (null = aman).
     * Kode cukup unik DALAM SATU CAKUPAN: sesama shift umum, atau sesama shift satu unit.
     * Unit yang punya shift khusus hanya memakai shift khususnya (UnitKerja::saringShift), jadi
     * "P" Gizi (06:00) dan "P" umum / "P" Rawat Inap (07:00) tidak pernah tertukar.
     */
    private function shiftBerkodeSama(string $kode): ?object
    {
        $unitId = $this->form['unit_id'] !== '' ? $this->form['unit_id'] : null;

        return DB::table('siapmst_shifts as sh')
            ->leftJoin('siapmst_unit_kerjas as un', 'un.unit_id', '=', 'sh.unit_id')
            ->whereRaw('UPPER(sh.shift_kode) = ?', [mb_strtoupper(trim($kode))])
            ->where('sh.shift_id', '!=', $this->originalShiftId !== '' ? $this->originalShiftId : mb_strtoupper(trim($this->form['shift_id'])))
            ->when($unitId !== null, fn($query) => $query->where('sh.unit_id', $unitId), fn($query) => $query->whereNull('sh.unit_id'))
            ->first(['sh.shift_name', 'un.unit_name']);
    }

    // ─── Close ────────────────────────────────────────────────────────────────
    public function closeModal(): void
    {
        $this->resetForm();
        $this->dispatch('close-modal', name: 'master-shift-actions');
        $this->resetVersion();
    }

    private function resetForm(): void
    {
        $this->form = [
            'shift_id'        => '',
            'shift_name'      => '',
            'shift_kode'      => '',
            'jam_masuk'       => '',
            'jam_pulang'      => '',
            'toleransi_menit' => '10',
            'unit_id'         => '',
            'shift_warna'     => '',
            'shift_fleksibel' => '0',
            'active_status'   => '1',
        ];
        $this->resetValidation();
    }
};
?>

<div>
    <x-modal name="master-shift-actions" size="full" height="full" focusable>
        <x-dirty-modal-content
            name="master-shift-actions"
            event="master.shift.saved"
            label="Shift"
            :wireKey="$this->renderKey('modal', [$formMode, $originalShiftId])">

            {{-- HEADER — standar modal modul dokumen (x-modul-dokumen.header, diport dari sirus) --}}
            <x-modul-dokumen.header :judul="($formMode === 'edit' ? 'Ubah Data Shift' : 'Tambah Data Shift')"
                ikon="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" tutupAlpine="tryClose()">
                Jam pulang lebih kecil dari jam masuk = shift lintas hari (pulang esok pagi).
                <x-slot:badge><x-badge class="shrink-0 whitespace-nowrap" :variant="$formMode === 'edit' ? 'warning' : 'success'"> {{ $formMode === 'edit' ? 'Mode: Edit' : 'Mode: Tambah' }} </x-badge></x-slot:badge>
            </x-modul-dokumen.header>

            {{-- BODY --}}
            <div class="flex-1 px-4 py-4 bg-surface-soft dark:bg-gray-950/20" x-enter-chain
                 x-data
                 x-on:focus-shift-id.window="$nextTick(() => setTimeout(() => $refs.inputShiftId?.focus(), 150))"
                 x-on:focus-shift-name.window="$nextTick(() => setTimeout(() => $refs.inputShiftName?.focus(), 150))">

                <div class="max-w-3xl">
                    <x-border-form title="Data Shift">
                        <div class="space-y-4">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <x-input-label value="ID Shift *" />
                                    <x-text-input wire:model.live="form.shift_id" x-ref="inputShiftId"
                                        maxlength="10" placeholder="Mis. PAGI"
                                        :disabled="$formMode === 'edit'"
                                        :error="$errors->has('form.shift_id')" class="w-full mt-1 uppercase"
                                        x-on:keydown.enter.prevent="$refs.inputShiftName?.focus()" />
                                    <x-input-error :messages="$errors->get('form.shift_id')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label value="Kode Jadwal *" />
                                    <x-text-input wire:model.live="form.shift_kode" maxlength="2" placeholder="P / S"
                                        :error="$errors->has('form.shift_kode')" class="w-full mt-1 font-mono text-center uppercase" />
                                    <x-input-error :messages="$errors->get('form.shift_kode')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label value="Nama Shift *" />
                                    <x-text-input wire:model.live="form.shift_name" x-ref="inputShiftName"
                                        maxlength="50"
                                        :error="$errors->has('form.shift_name')" class="w-full mt-1"
                                        x-on:keydown.enter.prevent="$refs.inputJamMasuk?.focus()" />
                                    <x-input-error :messages="$errors->get('form.shift_name')" class="mt-1" />
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <x-input-label value="Jam Masuk *" />
                                    <x-text-input wire:model.live="form.jam_masuk" x-ref="inputJamMasuk"
                                        x-mask="99:99" placeholder="HH:MM"
                                        :error="$errors->has('form.jam_masuk')" class="w-full mt-1 font-mono"
                                        x-on:keydown.enter.prevent="$refs.inputJamPulang?.focus()" />
                                    <x-input-error :messages="$errors->get('form.jam_masuk')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label value="Jam Pulang *" />
                                    <x-text-input wire:model.live="form.jam_pulang" x-ref="inputJamPulang"
                                        x-mask="99:99" placeholder="HH:MM"
                                        :error="$errors->has('form.jam_pulang')" class="w-full mt-1 font-mono"
                                        x-on:keydown.enter.prevent="$refs.inputToleransi?.focus()" />
                                    @if (preg_match('/^\d{2}:\d{2}$/', $form['jam_masuk']) && preg_match('/^\d{2}:\d{2}$/', $form['jam_pulang']) && $form['jam_pulang'] < $form['jam_masuk'])
                                        <p class="mt-1 text-[11px] text-purple-700">Lintas hari — pulang esok hari.</p>
                                    @endif
                                    <x-input-error :messages="$errors->get('form.jam_pulang')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label value="Toleransi Terlambat (menit) *" />
                                    <x-text-input wire:model.live="form.toleransi_menit" x-ref="inputToleransi" :disabled="$form['shift_fleksibel'] === '1'"
                                        type="number" min="0" max="240"
                                        :error="$errors->has('form.toleransi_menit')" class="w-full mt-1"
                                        x-on:keydown.enter.prevent="$wire.save()" />
                                    <x-input-error :messages="$errors->get('form.toleransi_menit')" class="mt-1" />
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div class="sm:col-span-2">
                                    <x-input-label value="Unit" />
                                    <x-select-input wire:model.live="form.unit_id" :error="$errors->has('form.unit_id')" class="mt-1">
                                        <option value="">— semua unit —</option>
                                        @foreach ($this->unitList as $unit)
                                            <option value="{{ $unit->unit_id }}">{{ $unit->unit_name }}</option>
                                        @endforeach
                                    </x-select-input>
                                    <p class="mt-1 text-[11px] text-muted dark:text-gray-400">
                                        Isi bila jam shift ini khusus satu unit (mis. Gizi Shift 1 06:00–13:00). Unit yang punya shift khusus hanya memakai shift khususnya; kosong = shift umum.
                                    </p>
                                    <x-input-error :messages="$errors->get('form.unit_id')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label value="Warna di jadwal" />
                                    @php $kodePratinjau = mb_strtoupper((string) $form['shift_kode']) ?: '?'; @endphp
                                    <div class="flex flex-wrap items-center gap-1.5 mt-1">
                                        <button type="button" wire:click="$set('form.shift_warna', '')" title="Warna bawaan kode {{ $kodePratinjau }}"
                                            style="{{ \App\Support\Jadwal::gaya($kodePratinjau) }}"
                                            class="h-8 px-2 text-[11px] font-bold rounded-lg {{ $form['shift_warna'] === '' ? 'ring-4 ring-offset-1 ring-brand-green' : 'opacity-70 hover:opacity-100' }}">Bawaan</button>
                                        @foreach (\App\Support\Jadwal::PALET as $warna => $namaWarna)
                                            <button type="button" wire:click="$set('form.shift_warna', '{{ $warna }}')" title="{{ $namaWarna }}"
                                                style="{{ \App\Support\Jadwal::gaya($kodePratinjau, $warna) }}"
                                                class="w-8 h-8 text-xs font-bold rounded-lg {{ $form['shift_warna'] === $warna ? 'ring-4 ring-offset-1 ring-brand-green' : 'opacity-70 hover:opacity-100' }}">{{ $kodePratinjau }}</button>
                                        @endforeach
                                    </div>
                                    <x-input-error :messages="$errors->get('form.shift_warna')" class="mt-1" />
                                </div>
                            </div>

                            <div>
                                <x-toggle wire:model.live="form.shift_fleksibel" trueValue="1" falseValue="0">
                                    Shift fleksibel (jam ikut kebutuhan, mis. Poli mendampingi dokter)
                                </x-toggle>
                                <p class="mt-1 text-[11px] text-muted dark:text-gray-400">
                                    Jam masuk–pulang di atas hanya <b>jendela absen</b> (mis. 07:00–21:00 = jam buka poli). Tidak dihitung terlambat,
                                    pulang cepat, maupun lembur otomatis; jam kerja tercatat apa adanya. Bila ini satu-satunya shift unitnya,
                                    karyawan unit tsb bisa langsung absen walau tidak terjadwal.
                                </p>
                            </div>

                            <x-toggle wire:model.live="form.active_status" trueValue="1" falseValue="0">
                                Status Aktif (tampil di kiosk)
                            </x-toggle>
                        </div>
                    </x-border-form>
                </div>
            </div>

            {{-- FOOTER --}}
            <div class="sticky bottom-0 z-10 px-6 py-4 mt-auto bg-surface-soft border-t border-hairline dark:bg-gray-900 dark:border-gray-700">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-xs text-muted dark:text-gray-400">
                        <kbd class="px-1.5 py-0.5 text-xs font-semibold bg-surface-card border border-hairline rounded dark:bg-gray-800 dark:border-gray-600">Enter</kbd>
                        <span class="mx-0.5">di field terakhir untuk simpan</span>
                    </div>
                    <div class="flex justify-end gap-2">
                        <x-secondary-button type="button" x-on:click="tryClose()">Batal</x-secondary-button>
                        <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled">
                            <span wire:loading.remove>Simpan</span>
                            <span wire:loading>Menyimpan...</span>
                        </x-primary-button>
                    </div>
                </div>
            </div>

        </x-dirty-modal-content>
    </x-modal>
</div>
