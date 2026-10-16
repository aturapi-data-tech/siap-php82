<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use App\Support\GajiKaryawan;
use App\Http\Traits\Concerns\WithRenderVersioningTrait;

/** Modal parameter gaji satu karyawan (upsert siapmst_gaji_karyawans) — dibuka dari Master Karyawan (tombol Gaji, role gaji.kelola). */
new class extends Component {
    use WithRenderVersioningTrait;

    public string $empId   = '';
    public string $empName = '';
    public bool   $sudahAda = false;
    public array  $renderVersions = [];
    protected array $renderAreas  = ['modal'];

    public array $form = [];

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    public function mount(): void
    {
        $this->registerAreas(['modal']);
        $this->resetForm();
    }

    #[On('keuangan.parameter-gaji.openEdit')]
    public function openEdit(string $empId): void
    {
        $karyawan = DB::table('siapmst_employers')->where('emp_id', $empId)->first(['emp_id', 'emp_name']);
        if (!$karyawan) {
            $this->dispatch('toast', type: 'error', message: 'Data karyawan tidak ditemukan.');
            return;
        }

        $this->resetForm();
        $this->empId   = (string) $karyawan->emp_id;
        $this->empName = (string) $karyawan->emp_name;

        $parameter = DB::table('siapmst_gaji_karyawans')->where('emp_id', $empId)->first();
        $this->sudahAda = (bool) $parameter;
        if ($parameter) {
            foreach (GajiKaryawan::KOLOM_NOMINAL as $kolom) {
                $this->form[$kolom] = (int) round((float) $parameter->$kolom);
            }
            $this->form['pph21_persen'] = (float) $parameter->pph21_persen;
            $this->form['nama_bank']    = (string) ($parameter->nama_bank ?? '');
            $this->form['no_rekening']  = (string) ($parameter->no_rekening ?? '');
        }

        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'parameter-gaji-actions');
    }

    protected function rules(): array
    {
        $rules = [];
        foreach (GajiKaryawan::KOLOM_NOMINAL as $kolom) {
            $rules["form.{$kolom}"] = 'required|numeric|min:0|max:999999999';
        }

        return [...$rules,
            'form.pph21_persen' => 'required|numeric|min:0|max:50',
            'form.nama_bank'    => 'nullable|string|max:30',
            'form.no_rekening'  => 'nullable|string|max:30',
        ];
    }

    protected function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'numeric'  => ':attribute harus angka.',
            'min'      => ':attribute tidak boleh minus.',
            'max'      => ':attribute terlalu besar.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'form.gaji_pokok' => 'Gaji Pokok', 'form.tarif_makan' => 'Tarif Makan', 'form.tarif_transport' => 'Tarif Transport',
            'form.tunjangan_struktural' => 'Tunjangan Struktural', 'form.tunjangan_profesi' => 'Tunjangan Profesi',
            'form.potongan_asuransi' => 'Asuransi', 'form.potongan_organisasi' => 'Iuran Organisasi', 'form.potongan_arisan' => 'Arisan',
            'form.potongan_koperasi' => 'Koperasi', 'form.potongan_infaq' => 'Infaq', 'form.potongan_bpjs' => 'BPJS',
            'form.pph21_persen' => 'PPh 21', 'form.nama_bank' => 'Bank', 'form.no_rekening' => 'No. Rekening',
        ];
    }

    public function save(): void
    {
        $this->validate();

        $payload = ['pph21_persen' => (float) $this->form['pph21_persen'], 'updated_by' => auth()->id(), 'updated_at' => now(),
            'nama_bank'   => trim($this->form['nama_bank']) !== '' ? trim($this->form['nama_bank']) : null,
            'no_rekening' => trim($this->form['no_rekening']) !== '' ? trim($this->form['no_rekening']) : null];
        foreach (GajiKaryawan::KOLOM_NOMINAL as $kolom) {
            $payload[$kolom] = (float) $this->form[$kolom];
        }

        if (DB::table('siapmst_gaji_karyawans')->where('emp_id', $this->empId)->exists()) {
            DB::table('siapmst_gaji_karyawans')->where('emp_id', $this->empId)->update($payload);
        } else {
            DB::table('siapmst_gaji_karyawans')->insert([...$payload, 'emp_id' => $this->empId, 'created_at' => now()]);
        }

        $this->dispatch('toast', type: 'success', message: 'Parameter gaji ' . $this->empName . ' disimpan. Berlaku untuk slip yang di-generate berikutnya.');
        $this->closeModal();
        $this->dispatch('keuangan.parameter-gaji.saved');
    }

    public function closeModal(): void
    {
        $this->resetForm();
        $this->dispatch('close-modal', name: 'parameter-gaji-actions');
        $this->resetVersion();
    }

    private function resetForm(): void
    {
        $this->form = array_fill_keys(GajiKaryawan::KOLOM_NOMINAL, 0) + ['pph21_persen' => 0, 'nama_bank' => '', 'no_rekening' => ''];
        $this->sudahAda = false;
        $this->resetValidation();
    }
};
?>

<div>
    <x-modal name="parameter-gaji-actions" size="full" height="full" focusable>
        <x-dirty-modal-content
            name="parameter-gaji-actions"
            event="keuangan.parameter-gaji.saved"
            label="Parameter Gaji"
            :wireKey="$this->renderKey('modal', [$empId])">

            {{-- HEADER --}}
            <div class="relative px-6 py-5 bg-surface-soft">
                <div class="relative flex items-start justify-between gap-4">
                    <div>
                        <h2 class="ds-display-sm dark:text-gray-100">Parameter Gaji — {{ $empName }}</h2>
                        <p class="mt-0.5 text-sm text-muted dark:text-gray-400">
                            {{ $empId }} · Perubahan berlaku untuk slip yang di-generate berikutnya; slip yang sudah ada tidak berubah sampai di-generate ulang (selama masih Draft).
                        </p>
                        <div class="mt-3">
                            <x-badge :variant="$sudahAda ? 'warning' : 'success'">{{ $sudahAda ? 'Mode: Edit' : 'Mode: Baru' }}</x-badge>
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
            <div class="flex-1 px-4 py-4 bg-surface-soft dark:bg-gray-950/20" x-enter-chain>
                <div class="grid max-w-5xl grid-cols-1 gap-4 lg:grid-cols-2">
                    <x-border-form title="Pendapatan">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            @foreach ([
                                'gaji_pokok' => ['Gaji Pokok', 'per bulan'],
                                'tunjangan_struktural' => ['Tunjangan Struktural', 'per bulan'],
                                'tunjangan_profesi' => ['Tunjangan Profesi', 'per bulan'],
                                'tarif_makan' => ['Tarif Makan', 'per hari hadir'],
                                'tarif_transport' => ['Tarif Transport', 'per hari hadir'],
                            ] as $kolom => [$labelKolom, $satuan])
                                <div wire:key="kolom-{{ $kolom }}">
                                    <x-input-label :value="$labelKolom . ' (' . $satuan . ')'" />
                                    <x-text-input-number wire:model="form.{{ $kolom }}" :error="$errors->has('form.' . $kolom)" class="w-full mt-1" />
                                    <x-input-error :messages="$errors->get('form.' . $kolom)" class="mt-1" />
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-3 text-xs text-muted">Jasa medik, upah lembur, bonus &amp; rapel diisi di rincian slip setiap bulan.</p>
                    </x-border-form>

                    <x-border-form title="Potongan Rutin (per bulan)">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            @foreach ([
                                'potongan_asuransi' => 'Asuransi',
                                'potongan_organisasi' => 'Iuran Organisasi (PPNI / IBI)',
                                'potongan_arisan' => 'Arisan',
                                'potongan_koperasi' => 'Koperasi / Iuran',
                                'potongan_infaq' => 'Infaq',
                                'potongan_bpjs' => 'BPJS',
                            ] as $kolom => $labelKolom)
                                <div wire:key="kolom-{{ $kolom }}">
                                    <x-input-label :value="$labelKolom" />
                                    <x-text-input-number wire:model="form.{{ $kolom }}" :error="$errors->has('form.' . $kolom)" class="w-full mt-1" />
                                    <x-input-error :messages="$errors->get('form.' . $kolom)" class="mt-1" />
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-3 text-xs text-muted">Kasbon &amp; angsuran diisi di rincian slip setiap bulan.</p>
                    </x-border-form>

                    <x-border-form title="Pajak & Rekening">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div>
                                <x-input-label value="PPh 21 (%)" />
                                <x-text-input-number wire:model="form.pph21_persen" :decimals="2" :error="$errors->has('form.pph21_persen')" class="w-full mt-1" />
                                <x-input-error :messages="$errors->get('form.pph21_persen')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label value="Bank" />
                                <x-text-input wire:model="form.nama_bank" maxlength="30" placeholder="Mis. BSI" class="w-full mt-1" />
                                <x-input-error :messages="$errors->get('form.nama_bank')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label value="No. Rekening" />
                                <x-text-input wire:model="form.no_rekening" maxlength="30" class="w-full mt-1 font-mono" />
                                <x-input-error :messages="$errors->get('form.no_rekening')" class="mt-1" />
                            </div>
                        </div>
                        <p class="mt-3 text-xs text-muted">PPh 21 = persen × total pendapatan slip; bisa ditimpa per slip di rincian.</p>
                    </x-border-form>
                </div>
            </div>

            {{-- FOOTER --}}
            <div class="sticky bottom-0 z-10 px-6 py-4 mt-auto bg-surface-soft border-t border-hairline dark:bg-gray-900 dark:border-gray-700">
                <div class="flex justify-end gap-2">
                    <x-secondary-button type="button" x-on:click="tryClose()">Batal</x-secondary-button>
                    <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled">
                        <span wire:loading.remove>Simpan</span>
                        <span wire:loading>Menyimpan...</span>
                    </x-primary-button>
                </div>
            </div>
        </x-dirty-modal-content>
    </x-modal>
</div>
