<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use App\Http\Traits\Concerns\WithRenderVersioningTrait;

/**
 * Modal CRUD `siapmst_lokasi_absens`. Koordinat bisa ditempel langsung dari
 * Google Maps (klik kanan titik → angka "-8.06, 111.90") atau diambil dari GPS
 * perangkat admin saat berdiri di lokasi.
 */
new class extends Component {
    use WithRenderVersioningTrait;

    public string $formMode         = 'create'; // create|edit
    public string $originalLokasiId = '';
    public array  $renderVersions   = [];
    protected array $renderAreas    = ['modal'];

    public array $form = [];

    public function mount(): void
    {
        $this->registerAreas(['modal']);
        $this->resetForm();
    }

    // ─── Open Create ──────────────────────────────────────────────────────────
    #[On('master.lokasi-absen.openCreate')]
    public function openCreate(): void
    {
        $this->resetForm();
        $this->formMode         = 'create';
        $this->originalLokasiId = '';
        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'master-lokasi-absen-actions');
        $this->dispatch('focus-lokasi-id');
    }

    // ─── Open Edit ────────────────────────────────────────────────────────────
    #[On('master.lokasi-absen.openEdit')]
    public function openEdit(string $lokasiId): void
    {
        $row = DB::table('siapmst_lokasi_absens')->where('lokasi_id', $lokasiId)->first();
        if (!$row) {
            $this->dispatch('toast', type: 'error', message: 'Data lokasi tidak ditemukan.');
            return;
        }

        $this->resetForm();
        $this->formMode         = 'edit';
        $this->originalLokasiId = $lokasiId;
        $this->form = [
            'lokasi_id'     => (string) $row->lokasi_id,
            'lokasi_name'   => (string) $row->lokasi_name,
            'alamat'        => (string) ($row->alamat ?? ''),
            'koordinat'     => $row->latitude . ', ' . $row->longitude,
            'radius_meter'  => (string) (int) $row->radius_meter,
            'active_status' => (string) $row->active_status === '1' ? '1' : '0',
        ];

        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'master-lokasi-absen-actions');
        $this->dispatch('focus-lokasi-name');
    }

    // ─── Delete ───────────────────────────────────────────────────────────────
    #[On('master.lokasi-absen.requestDelete')]
    public function deleteLokasi(string $lokasiId): void
    {
        try {
            $deleted = DB::table('siapmst_lokasi_absens')->where('lokasi_id', $lokasiId)->delete();
            if ($deleted === 0) {
                $this->dispatch('toast', type: 'error', message: 'Data lokasi tidak ditemukan.');
                return;
            }

            $this->dispatch('toast', type: 'success', message: 'Lokasi berhasil dihapus.');
            $this->dispatch('master.lokasi-absen.saved');
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'ORA-02292')) {
                $this->dispatch('toast', type: 'error', message: 'Lokasi tidak bisa dihapus karena sudah dipakai karyawan / data absensi. Non-aktifkan saja.');
                return;
            }
            throw $exception;
        }
    }

    // ─── Validasi ─────────────────────────────────────────────────────────────
    protected function rules(): array
    {
        return [
            'form.lokasi_id' => $this->formMode === 'create'
                ? ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9_\-]+$/', 'unique:siapmst_lokasi_absens,lokasi_id']
                : ['required', 'string'],
            'form.lokasi_name'   => 'required|string|max:100',
            'form.alamat'        => 'nullable|string|max:200',
            // "lat, lng" desimal — format salin dari Google Maps.
            'form.koordinat'     => ['required', 'regex:/^\s*-?\d{1,2}(\.\d+)?\s*,\s*-?\d{1,3}(\.\d+)?\s*$/'],
            'form.radius_meter'  => 'required|integer|min:50|max:20000',
            'form.active_status' => 'required|in:0,1',
        ];
    }

    protected function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'max'      => ':attribute maksimal :max.',
            'min'      => ':attribute minimal :min.',
            'unique'   => ':attribute sudah digunakan.',
            'integer'  => ':attribute harus angka.',
            'in'       => ':attribute tidak valid.',
            'form.lokasi_id.regex' => ':attribute hanya boleh huruf, angka, strip, atau garis bawah.',
            'form.koordinat.regex' => ':attribute harus berformat "latitude, longitude", mis. -8.0654321, 111.9012345.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'form.lokasi_id'     => 'Kode Lokasi',
            'form.lokasi_name'   => 'Nama Lokasi',
            'form.alamat'        => 'Alamat',
            'form.koordinat'     => 'Titik Koordinat',
            'form.radius_meter'  => 'Radius',
            'form.active_status' => 'Status',
        ];
    }

    // ─── Save ─────────────────────────────────────────────────────────────────
    public function save(): void
    {
        $this->validate();

        $form = $this->form;
        [$latitude, $longitude] = array_map(fn($angka) => (float) trim($angka), explode(',', $form['koordinat']));
        if (abs($latitude) > 90 || abs($longitude) > 180) {
            $this->addError('form.koordinat', 'Titik Koordinat di luar jangkauan (latitude ±90, longitude ±180).');
            return;
        }

        $payload = [
            'lokasi_name'   => trim($form['lokasi_name']),
            'alamat'        => $form['alamat'] !== '' ? trim($form['alamat']) : null,
            'latitude'      => round($latitude, 7),
            'longitude'     => round($longitude, 7),
            'radius_meter'  => (int) $form['radius_meter'],
            'active_status' => $form['active_status'],
            'updated_at'    => now(),
        ];

        if ($this->formMode === 'create') {
            DB::table('siapmst_lokasi_absens')->insert([
                'lokasi_id'  => mb_strtoupper(trim($form['lokasi_id'])),
                'created_at' => now(),
                ...$payload,
            ]);
        } else {
            DB::table('siapmst_lokasi_absens')->where('lokasi_id', $this->originalLokasiId)->update($payload);
        }

        $this->dispatch('toast', type: 'success', message: 'Data lokasi berhasil disimpan.');
        $this->closeModal();
        $this->dispatch('master.lokasi-absen.saved');
    }

    // ─── Close ────────────────────────────────────────────────────────────────
    public function closeModal(): void
    {
        $this->resetForm();
        $this->dispatch('close-modal', name: 'master-lokasi-absen-actions');
        $this->resetVersion();
    }

    private function resetForm(): void
    {
        $this->form = [
            'lokasi_id'     => '',
            'lokasi_name'   => '',
            'alamat'        => '',
            'koordinat'     => '',
            'radius_meter'  => '2000',
            'active_status' => '1',
        ];
        $this->resetValidation();
    }
};
?>

<div>
    <x-modal name="master-lokasi-absen-actions" size="full" height="full" focusable>
        <x-dirty-modal-content
            name="master-lokasi-absen-actions"
            event="master.lokasi-absen.saved"
            label="Lokasi"
            :wireKey="$this->renderKey('modal', [$formMode, $originalLokasiId])">

            {{-- HEADER --}}
            <div class="relative px-6 py-5 bg-surface-soft">
                <div class="relative flex items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-3">
                            <div class="flex items-center justify-center w-10 h-10 rounded-xl bg-brand-green/10 dark:bg-brand-lime/15">
                                <img src="{{ asset('images/Logogram black solid.png') }}" alt="Logo" class="block w-6 h-6 dark:hidden" />
                                <img src="{{ asset('images/Logogram white solid.png') }}" alt="Logo" class="hidden w-6 h-6 dark:block" />
                            </div>
                            <div>
                                <h2 class="ds-display-sm dark:text-gray-100">
                                    {{ $formMode === 'edit' ? 'Ubah Lokasi Absen' : 'Tambah Lokasi Absen' }}
                                </h2>
                                <p class="mt-0.5 text-sm text-muted dark:text-gray-400">
                                    Karyawan bisa absen HP bila jarak GPS-nya ke titik ini tidak melebihi radius.
                                </p>
                            </div>
                        </div>
                        <div class="mt-3">
                            <x-badge :variant="$formMode === 'edit' ? 'warning' : 'success'">
                                {{ $formMode === 'edit' ? 'Mode: Edit' : 'Mode: Tambah' }}
                            </x-badge>
                        </div>
                    </div>
                    <x-icon-button color="gray" type="button" x-on:click="tryClose()">
                        <span class="sr-only">Close</span>
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        </svg>
                    </x-icon-button>
                </div>
            </div>

            {{-- BODY --}}
            <div class="flex-1 px-4 py-4 bg-surface-soft dark:bg-gray-950/20" x-enter-chain
                 x-data
                 x-on:focus-lokasi-id.window="$nextTick(() => setTimeout(() => $refs.inputLokasiId?.focus(), 150))"
                 x-on:focus-lokasi-name.window="$nextTick(() => setTimeout(() => $refs.inputLokasiName?.focus(), 150))">

                <div class="max-w-3xl">
                    <x-border-form title="Data Lokasi">
                        <div class="space-y-4">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <x-input-label value="Kode Lokasi *" />
                                    <x-text-input wire:model.live="form.lokasi_id" x-ref="inputLokasiId"
                                        maxlength="10" placeholder="Mis. RSU"
                                        :disabled="$formMode === 'edit'"
                                        :error="$errors->has('form.lokasi_id')" class="w-full mt-1 uppercase"
                                        x-on:keydown.enter.prevent="$refs.inputLokasiName?.focus()" />
                                    <x-input-error :messages="$errors->get('form.lokasi_id')" class="mt-1" />
                                </div>
                                <div class="sm:col-span-2">
                                    <x-input-label value="Nama Lokasi *" />
                                    <x-text-input wire:model.live="form.lokasi_name" x-ref="inputLokasiName"
                                        maxlength="100" placeholder="Mis. RS Utama / Klinik Pratama Kauman"
                                        :error="$errors->has('form.lokasi_name')" class="w-full mt-1"
                                        x-on:keydown.enter.prevent="$refs.inputAlamat?.focus()" />
                                    <x-input-error :messages="$errors->get('form.lokasi_name')" class="mt-1" />
                                </div>
                            </div>

                            <div>
                                <x-input-label value="Alamat" />
                                <x-text-input wire:model.live="form.alamat" x-ref="inputAlamat" maxlength="200"
                                    :error="$errors->has('form.alamat')" class="w-full mt-1"
                                    x-on:keydown.enter.prevent="$refs.inputKoordinat?.focus()" />
                                <x-input-error :messages="$errors->get('form.alamat')" class="mt-1" />
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3"
                                x-data="{
                                    mencari: false, galat: '',
                                    pakaiPosisiSaya() {
                                        if (!window.isSecureContext) { this.galat = 'GPS hanya bisa dipakai lewat HTTPS. Tempel koordinat dari Google Maps.'; return; }
                                        this.mencari = true; this.galat = '';
                                        navigator.geolocation.getCurrentPosition(
                                            (hasil) => { this.mencari = false; $wire.set('form.koordinat', hasil.coords.latitude.toFixed(7) + ', ' + hasil.coords.longitude.toFixed(7)); },
                                            () => { this.mencari = false; this.galat = 'Lokasi tidak didapat. Izinkan akses lokasi lalu coba lagi.'; },
                                            { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
                                    },
                                }">
                                <div class="sm:col-span-2">
                                    <x-input-label value="Titik Koordinat (latitude, longitude) *" />
                                    <x-text-input wire:model.live.debounce.500ms="form.koordinat" x-ref="inputKoordinat"
                                        placeholder="-8.0654321, 111.9012345"
                                        :error="$errors->has('form.koordinat')" class="w-full mt-1 font-mono"
                                        x-on:keydown.enter.prevent="$refs.inputRadius?.focus()" />
                                    <p class="mt-1 text-[11px] text-muted dark:text-gray-400">
                                        Google Maps → klik kanan titik gedung → klik angka koordinat (tersalin) → tempel di sini.
                                        <button type="button" x-on:click="pakaiPosisiSaya()" class="font-semibold underline text-brand-green">
                                            <span x-text="mencari ? 'Mencari...' : 'Pakai lokasi saya sekarang'"></span>
                                        </button>
                                        @if (preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $form['koordinat'], $cocok))
                                            · <a href="https://www.google.com/maps?q={{ $cocok[1] }},{{ $cocok[2] }}" target="_blank" rel="noopener" class="underline">cek di peta</a>
                                        @endif
                                    </p>
                                    <p x-show="galat" x-text="galat" class="mt-1 text-[11px] text-red-600"></p>
                                    <x-input-error :messages="$errors->get('form.koordinat')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label value="Radius (meter) *" />
                                    <x-text-input wire:model.live="form.radius_meter" x-ref="inputRadius"
                                        type="number" min="50" max="20000" step="50"
                                        :error="$errors->has('form.radius_meter')" class="w-full mt-1"
                                        x-on:keydown.enter.prevent="$wire.save()" />
                                    <p class="mt-1 text-[11px] text-muted dark:text-gray-400">2000 = 2 km.</p>
                                    <x-input-error :messages="$errors->get('form.radius_meter')" class="mt-1" />
                                </div>
                            </div>

                            <x-toggle wire:model.live="form.active_status" trueValue="1" falseValue="0">
                                Status Aktif (bisa dipakai absen HP)
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
