<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use App\Support\GajiKaryawan;
use App\Support\JasaMedik;
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

    /** Master poin Jasa Medik per pos (siapmst_jm_poins) — sumber "Generate Poin" di menu Pembagian JM. */
    public array $poinJm = [];

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
            $this->form['hari_kerja_default'] = (int) $parameter->hari_kerja_default;
            $this->form['bpjs_jkn'] = ($parameter->bpjs_jkn ?? '0') === '1';
            $this->form['bpjs_jkn_dasar'] = (int) round((float) ($parameter->bpjs_jkn_dasar ?? 0));
            $this->form['bpjs_keluarga'] = (int) ($parameter->bpjs_keluarga ?? 0);
            $this->form['bpjs_jp'] = ($parameter->bpjs_jp ?? '0') === '1';
            $this->form['nama_bank']    = (string) ($parameter->nama_bank ?? '');
            $this->form['no_rekening']  = (string) ($parameter->no_rekening ?? '');
        }

        $this->poinJm = JasaMedik::masterPoin($this->empId);

        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'parameter-gaji-actions');
    }

    protected function rules(): array
    {
        $rules = [];
        foreach (GajiKaryawan::KOLOM_NOMINAL as $kolom) {
            $rules["form.{$kolom}"] = 'required|numeric|min:0|max:999999999';
        }

        foreach (array_keys(JasaMedik::POS) as $kode) {
            $rules["poinJm.{$kode}"] = 'nullable|numeric|min:0|max:999';
        }

        return [...$rules,
            'form.pph21_persen' => 'required|numeric|min:0|max:50',
            'form.hari_kerja_default' => 'required|integer|min:0|max:31',
            'form.bpjs_jkn' => 'boolean', 'form.bpjs_jp' => 'boolean',
            'form.bpjs_jkn_dasar' => 'required|numeric|min:0|max:999999999',
            'form.bpjs_keluarga' => 'required|integer|min:0|max:9',
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
            'form.potongan_koperasi' => 'Koperasi', 'form.potongan_infaq' => 'Infaq',
            'form.bpjs_jkn_dasar' => 'Dasar JKN', 'form.bpjs_keluarga' => 'Keluarga tambahan',
            ...collect(JasaMedik::POS)->mapWithKeys(fn($pos, $kode) => ["poinJm.{$kode}" => 'Poin JM ' . $pos['nama']])->all(),
            'form.pph21_persen' => 'PPh 21', 'form.hari_kerja_default' => 'Hari Kerja Default', 'form.nama_bank' => 'Bank', 'form.no_rekening' => 'No. Rekening',
        ];
    }

    public function save(): void
    {
        $this->validate();

        $payload = ['pph21_persen' => (float) $this->form['pph21_persen'], 'hari_kerja_default' => (int) $this->form['hari_kerja_default'], 'updated_by' => auth()->id(), 'updated_at' => now(),
            'bpjs_jkn' => $this->form['bpjs_jkn'] ? '1' : '0', 'bpjs_jp' => $this->form['bpjs_jp'] ? '1' : '0',
            'bpjs_jkn_dasar' => (float) $this->form['bpjs_jkn_dasar'] > 0 ? (float) $this->form['bpjs_jkn_dasar'] : null,
            'bpjs_keluarga' => (int) $this->form['bpjs_keluarga'],
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
        JasaMedik::simpanMasterPoin($this->empId, $this->poinJm, auth()->id());

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
        $this->form = array_fill_keys(GajiKaryawan::KOLOM_NOMINAL, 0) + ['pph21_persen' => 0, 'hari_kerja_default' => 25, 'nama_bank' => '', 'no_rekening' => '',
            'bpjs_jkn' => false, 'bpjs_jkn_dasar' => 0, 'bpjs_keluarga' => 0, 'bpjs_jp' => false];
        $this->poinJm = array_fill_keys(array_keys(JasaMedik::POS), 0);
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

            {{-- HEADER — standar modal modul dokumen (x-modul-dokumen.header, diport dari sirus) --}}
            <x-modul-dokumen.header :judul="'Parameter Gaji — ' . ($empName)"
                ikon="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" tutupAlpine="tryClose()">
                {{ $empId }} · Perubahan berlaku untuk slip yang di-generate berikutnya; slip yang sudah ada tidak berubah sampai di-generate ulang (selama masih Draft).
                <x-slot:badge><x-badge class="shrink-0 whitespace-nowrap" :variant="$sudahAda ? 'warning' : 'success'">{{ $sudahAda ? 'Mode: Edit' : 'Mode: Baru' }}</x-badge></x-slot:badge>
            </x-modul-dokumen.header>

            {{-- BODY --}}
            <div class="flex-1 px-4 py-4 bg-surface-soft dark:bg-gray-950/20" x-enter-chain>
                <div class="grid max-w-5xl grid-cols-1 gap-4 lg:grid-cols-2">
                    <x-border-form title="Pendapatan">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            @foreach ([
                                'gaji_pokok' => ['Gaji Pokok', 'per bulan'],
                                'tunjangan_struktural' => ['Tunjangan Struktural', 'per bulan'],
                                'tunjangan_profesi' => ['Tunjangan Profesi', 'per bulan'],
                                'tarif_makan' => ['Tarif Makan', 'per hari kerja'],
                                'tarif_transport' => ['Tarif Transport', 'per hari kerja'],
                            ] as $kolom => [$labelKolom, $satuan])
                                <div wire:key="kolom-{{ $kolom }}">
                                    <x-input-label :value="$labelKolom . ' (' . $satuan . ')'" />
                                    <x-text-input-number wire:model="form.{{ $kolom }}" :error="$errors->has('form.' . $kolom)" class="w-full mt-1" />
                                    <x-input-error :messages="$errors->get('form.' . $kolom)" class="mt-1" />
                                </div>
                            @endforeach
                            <div>
                                <x-input-label value="Hari Kerja Default (per bulan)" />
                                <x-text-input-number wire:model="form.hari_kerja_default" :error="$errors->has('form.hari_kerja_default')" class="w-full mt-1" />
                                <x-input-error :messages="$errors->get('form.hari_kerja_default')" class="mt-1" />
                            </div>
                        </div>
                        <p class="mt-3 text-xs text-muted">Tunjangan makan &amp; transport = tarif × hari kerja. Slip baru memakai hari kerja default ini;
                            di rincian slip bisa diubah bila ada cuti / izin. Jasa medik dihitung di menu Pembagian Jasa Medik (dari poin di bawah); upah lembur, bonus &amp; rapel diisi di rincian slip / form bagian setiap bulan.</p>
                    </x-border-form>

                    <x-border-form title="Potongan Rutin (per bulan)">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            @foreach ([
                                'potongan_asuransi' => 'Asuransi lain (bukan BPJS)',
                                'potongan_organisasi' => 'Iuran Organisasi (PPNI / IBI)',
                                'potongan_arisan' => 'Arisan',
                                'potongan_koperasi' => 'Koperasi / Iuran',
                                'potongan_infaq' => 'Infaq',
                            ] as $kolom => $labelKolom)
                                <div wire:key="kolom-{{ $kolom }}">
                                    <x-input-label :value="$labelKolom" />
                                    <x-text-input-number wire:model="form.{{ $kolom }}" :error="$errors->has('form.' . $kolom)" class="w-full mt-1" />
                                    <x-input-error :messages="$errors->get('form.' . $kolom)" class="mt-1" />
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-3 text-xs text-muted">Kasbon diisi lewat Form Bagian; angsuran pinjaman dari menu Pinjaman Karyawan; BPJS dihitung otomatis (kotak di bawah).</p>
                    </x-border-form>

                    <x-border-form title="BPJS (dihitung otomatis di slip)" class="lg:col-span-2">
                        @php $aturanBpjs = \App\Support\PengaturanGaji::ambil(); @endphp
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                            <div class="sm:col-span-2">
                                <x-toggle wire:model.live="form.bpjs_jkn" :trueValue="true" :falseValue="false" label="Peserta BPJS Kesehatan (JKN)" />
                                <p class="mt-1 text-xs text-muted">Potongan {{ rtrim(rtrim(number_format($aturanBpjs->persen_jkn, 2, ',', '.'), '0'), ',') }}% × dasar × (1 + keluarga tambahan).</p>
                            </div>
                            <div class="sm:col-span-2">
                                <x-toggle wire:model.live="form.bpjs_jp" :trueValue="true" :falseValue="false" label="Peserta BPJS Ketenagakerjaan (JP)" />
                                <p class="mt-1 text-xs text-muted">Potongan JP {{ rtrim(rtrim(number_format($aturanBpjs->persen_jp, 2, ',', '.'), '0'), ',') }}% × upah bulan itu (pokok + tunjangan + JM, min. UMK) — berubah tiap bulan.</p>
                            </div>
                            <div class="sm:col-span-2">
                                <x-input-label value="Dasar hitung JKN (kosong = UMK {{ \App\Support\GajiKaryawan::rupiah($aturanBpjs->umk) }})" />
                                <x-text-input-number wire:model="form.bpjs_jkn_dasar" :disabled="!$form['bpjs_jkn']" :error="$errors->has('form.bpjs_jkn_dasar')" class="w-full mt-1" />
                                <x-input-error :messages="$errors->get('form.bpjs_jkn_dasar')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label value="Keluarga tambahan (jiwa)" />
                                <x-text-input-number wire:model="form.bpjs_keluarga" :disabled="!$form['bpjs_jkn']" :error="$errors->has('form.bpjs_keluarga')" class="w-full mt-1" />
                                <x-input-error :messages="$errors->get('form.bpjs_keluarga')" class="mt-1" />
                            </div>
                        </div>
                        <p class="mt-3 text-xs text-muted">UMK &amp; persen diatur di Gaji Karyawan → <b>Ketentuan BPJS</b>. Baris BPJS di slip dihitung ulang setiap slip berubah (pola PPh 21) dan bisa ditimpa per slip di rincian.</p>
                    </x-border-form>

                    <x-border-form title="Poin Jasa Medik (master)" class="lg:col-span-2">
                        <div class="grid grid-cols-2 gap-4 sm:grid-cols-5">
                            @foreach (\App\Support\JasaMedik::POS as $kode => $pos)
                                <div wire:key="poin-jm-{{ $kode }}">
                                    <x-input-label :value="$pos['nama']" />
                                    <x-text-input-number wire:model="poinJm.{{ $kode }}" :decimals="2" :error="$errors->has('poinJm.' . $kode)" class="w-full mt-1" />
                                    <x-input-error :messages="$errors->get('poinJm.' . $kode)" class="mt-1" />
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-3 text-xs text-muted">Poin bagian jasa medik per pos (boleh desimal, mis. 1,25; kosong = tidak ikut pos itu).
                            Dipakai tombol <b>Generate Poin dari Master</b> di menu Pembagian Jasa Medik setiap bulan; poin bulan berjalan masih bisa disesuaikan di sana.</p>
                    </x-border-form>

                    <x-border-form title="Pajak & Rekening" class="lg:col-span-2">
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
