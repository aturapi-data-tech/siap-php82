<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use App\Support\GajiKaryawan;
use App\Support\PengaturanGaji;

/**
 * Modal "Ketentuan BPJS" (dibuka dari toolbar Gaji Karyawan, role gaji.kelola): UMK, % JKN karyawan, % JP karyawan.
 * Dipakai GajiKaryawan::hitungBpjs() untuk semua slip. Setelah disimpan, slip Draft bulan kerja yang sedang dibuka
 * dihitung ulang supaya baris BPJS-nya mengikuti ketentuan baru; slip Final tidak disentuh.
 */
new class extends Component {
    public string $periode = '';
    public array  $form = ['umk' => 0, 'persen_jkn' => 1, 'persen_jp' => 1, 'keterangan' => '', 'bpjs_tk_npp' => ''];
    public int    $versi = 0;

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    #[On('keuangan.gaji-karyawan.openKetentuanBpjs')]
    public function buka(string $periode): void
    {
        $this->periode = GajiKaryawan::periodeValid($periode) ? $periode : '';
        $aturan = PengaturanGaji::ambil();
        $this->form = ['umk' => (int) round($aturan->umk), 'persen_jkn' => (float) $aturan->persen_jkn, 'persen_jp' => (float) $aturan->persen_jp, 'keterangan' => $aturan->keterangan, 'bpjs_tk_npp' => $aturan->bpjs_tk_npp];
        $this->versi++;
        $this->dispatch('open-modal', name: 'gaji-karyawan-ketentuan-bpjs');
    }

    public function save(): void
    {
        $galat = PengaturanGaji::simpan($this->form, auth()->id());
        if ($galat !== null) {
            $this->dispatch('toast', type: 'error', message: $galat);
            return;
        }
        $dihitung = 0;
        if ($this->periode !== '') {
            foreach (DB::table('siaptxn_gaji_hdrs')->where('periode', $this->periode)->where('gaji_status', GajiKaryawan::STATUS_DRAFT)->pluck('gaji_id') as $gajiId) {
                GajiKaryawan::hitungUlang((int) $gajiId);
                $dihitung++;
            }
        }
        $this->dispatch('toast', type: 'success', message: 'Ketentuan BPJS disimpan.' . ($dihitung > 0 ? " {$dihitung} slip Draft " . GajiKaryawan::namaPeriode($this->periode) . ' dihitung ulang.' : ''));
        $this->dispatch('close-modal', name: 'gaji-karyawan-ketentuan-bpjs');
        $this->dispatch('keuangan.gaji-karyawan.saved');
    }
};
?>

<div>
    <x-modal name="gaji-karyawan-ketentuan-bpjs" size="lg" focusable>
        <x-dirty-modal-content name="gaji-karyawan-ketentuan-bpjs" event="keuangan.gaji-karyawan.saved" label="Ketentuan BPJS" :wireKey="'ketentuan-bpjs-' . $versi">
            <x-modul-dokumen.header judul="Ketentuan BPJS"
                ikon="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" tutupAlpine="tryClose()">
                Dipakai hitungan otomatis BPJS di semua slip: JKN = % × dasar × jiwa; JP = % × upah bulan itu (min. UMK). Kepesertaan per karyawan diatur di Master Karyawan → Gaji.
            </x-modul-dokumen.header>

            <div class="flex-1 px-4 py-4 bg-surface-soft dark:bg-gray-950/20">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <x-input-label for="kb-umk" value="UMK (Rp)" />
                        <x-text-input-number id="kb-umk" wire:model="form.umk" class="w-full mt-1" />
                        <p class="mt-1 text-xs text-muted">Dasar JKN bila master tidak mengisi dasar sendiri; batas bawah upah JP.</p>
                    </div>
                    <div>
                        <x-input-label for="kb-jkn" value="% JKN karyawan / jiwa" />
                        <x-text-input-number id="kb-jkn" wire:model="form.persen_jkn" :decimals="2" class="w-full mt-1" />
                    </div>
                    <div>
                        <x-input-label for="kb-jp" value="% JP karyawan" />
                        <x-text-input-number id="kb-jp" wire:model="form.persen_jp" :decimals="2" class="w-full mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="kb-ket" value="Keterangan" />
                        <x-text-input id="kb-ket" wire:model="form.keterangan" maxlength="200" placeholder="mis. UMK Tulungagung 2026" class="w-full mt-1" />
                    </div>
                    <div>
                        <x-input-label for="kb-npp" value="NPP BPJS Ketenagakerjaan" />
                        <x-text-input id="kb-npp" wire:model="form.bpjs_tk_npp" maxlength="20" placeholder="mis. NN080468" class="w-full mt-1" />
                        <p class="mt-1 text-xs text-muted">Nomor perusahaan di SIPP — kolom NPP laporan upah bulanan.</p>
                    </div>
                </div>
                <p class="mt-3 text-xs text-muted">Setelah disimpan, slip <b>Draft</b> bulan kerja {{ $periode !== '' ? GajiKaryawan::namaPeriode($periode) : 'yang dibuka' }} dihitung ulang; slip Final tidak berubah.</p>
            </div>

            <div class="sticky bottom-0 z-10 px-6 py-4 mt-auto bg-surface-soft border-t border-hairline dark:bg-gray-900 dark:border-gray-700">
                <div class="flex justify-end gap-2">
                    <x-secondary-button type="button" x-on:click="tryClose()">Batal</x-secondary-button>
                    <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Simpan</span>
                        <span wire:loading wire:target="save">Menyimpan...</span>
                    </x-primary-button>
                </div>
            </div>
        </x-dirty-modal-content>
    </x-modal>
</div>
