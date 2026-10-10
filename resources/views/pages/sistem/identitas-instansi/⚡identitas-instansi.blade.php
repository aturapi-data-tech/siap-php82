<?php

use Livewire\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Support\Identitas;

/**
 * IDENTITAS INSTANSI (company profile) — satu baris siapmst_identitas (id = 1).
 * Varian "single-file" standar master: tanpa list/modal, hanya form + Simpan.
 * Dipakai kop semua cetakan, topbar, halaman depan (profil) & login — lewat App\Support\Identitas.
 * Logo diunggah ke disk public folder identitas/ (DomPDF membaca dari path disk).
 */
new class extends Component {
    use WithFileUploads;

    public array $form = [];

    /** Berkas logo baru (sebelum disimpan). */
    public $logoBaru = null;

    /** Logo tersimpan saat ini (path relatif). */
    public ?string $logoLama = null;

    public bool $hapusLogo = false;

    private string $printView = 'pages.sistem.identitas-instansi.identitas-instansi-print';

    private const KOLOM = ['nama', 'nama_singkat', 'tagline', 'deskripsi', 'pimpinan', 'jabatan_pimpinan',
        'alamat', 'kota', 'kode_pos', 'telp', 'fax', 'email', 'website', 'jam_operasional'];

    public function mount(): void
    {
        $this->muat();
    }

    private function muat(): void
    {
        Identitas::lupakan();
        $row = Identitas::get();
        foreach (self::KOLOM as $k) {
            $this->form[$k] = (string) ($row->{$k} ?? '');
        }
        $this->logoLama = $row->logo ?? null;
        $this->logoBaru = null;
        $this->hapusLogo = false;
        $this->resetValidation();
    }

    /** URL logo untuk pratinjau: berkas baru → temporary URL; dihapus → null; selain itu logo tersimpan. */
    public function pratinjauLogo(): ?string
    {
        if ($this->logoBaru) {
            try {
                return $this->logoBaru->temporaryUrl();
            } catch (\Throwable) {
                return null;
            }
        }

        return $this->hapusLogo ? null : Identitas::logoUrl($this->logoLama);
    }

    public function updatedLogoBaru(): void
    {
        $this->validateOnly('logoBaru', ['logoBaru' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048'],
            ['logoBaru.image' => 'Logo harus berupa gambar.', 'logoBaru.mimes' => 'Logo harus PNG, JPG, atau WEBP.', 'logoBaru.max' => 'Ukuran logo maksimal 2 MB.']);
        $this->hapusLogo = false;
    }

    public function tandaiHapusLogo(): void
    {
        $this->logoBaru = null;
        $this->hapusLogo = true;
    }

    public function simpan(): void
    {
        $this->validate(
            [
                'form.nama'             => 'required|string|max:150',
                'form.nama_singkat'     => 'nullable|string|max:50',
                'form.tagline'          => 'nullable|string|max:200',
                'form.deskripsi'        => 'nullable|string|max:2000',
                'form.pimpinan'         => 'nullable|string|max:100',
                'form.jabatan_pimpinan' => 'nullable|string|max:100',
                'form.alamat'           => 'nullable|string|max:300',
                'form.kota'             => 'nullable|string|max:100',
                'form.kode_pos'         => 'nullable|string|max:10',
                'form.telp'             => 'nullable|string|max:50',
                'form.fax'              => 'nullable|string|max:50',
                'form.email'            => 'nullable|email|max:100',
                'form.website'          => 'nullable|string|max:100',
                'form.jam_operasional'  => 'nullable|string|max:150',
                'logoBaru'              => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
            ],
            [
                'required' => ':attribute wajib diisi.',
                'max'      => ':attribute maksimal :max karakter.',
                'email'    => 'Format email tidak valid.',
                'logoBaru.image' => 'Logo harus berupa gambar.',
                'logoBaru.mimes' => 'Logo harus PNG, JPG, atau WEBP.',
                'logoBaru.max'   => 'Ukuran logo maksimal 2 MB.',
            ],
            [
                'form.nama' => 'Nama Instansi', 'form.nama_singkat' => 'Nama Singkat', 'form.tagline' => 'Tagline',
                'form.deskripsi' => 'Profil Singkat', 'form.pimpinan' => 'Nama Pimpinan', 'form.jabatan_pimpinan' => 'Jabatan Pimpinan',
                'form.alamat' => 'Alamat', 'form.kota' => 'Kota', 'form.kode_pos' => 'Kode Pos', 'form.telp' => 'Telepon',
                'form.fax' => 'Fax', 'form.email' => 'Email', 'form.website' => 'Website', 'form.jam_operasional' => 'Jam Operasional',
            ],
        );

        $data = ['updated_by' => auth()->id(), 'updated_at' => now()];
        foreach (self::KOLOM as $k) {
            $nilai = trim($this->form[$k]);
            $data[$k] = $nilai !== '' ? $nilai : null;
        }

        $logoLamaUnggahan = $this->logoLama && str_starts_with($this->logoLama, Identitas::FOLDER_LOGO . '/') ? $this->logoLama : null;
        if ($this->logoBaru) {
            $nama = 'logo-' . now()->format('dmYHis') . '.' . $this->logoBaru->getClientOriginalExtension();
            $data['logo'] = $this->logoBaru->storeAs(Identitas::FOLDER_LOGO, $nama, 'public');
        } elseif ($this->hapusLogo) {
            $data['logo'] = null;
        }

        DB::table('siapmst_identitas')->updateOrInsert(['id' => 1], $data + ['created_at' => now()]);

        // Logo unggahan lama dibuang bila diganti / dihapus (logo bawaan di public/images tidak disentuh).
        if (array_key_exists('logo', $data) && $logoLamaUnggahan && $logoLamaUnggahan !== $data['logo']) {
            Storage::disk('public')->delete($logoLamaUnggahan);
        }

        $this->muat();
        $this->dispatch('toast', type: 'success', message: 'Identitas instansi tersimpan — kop cetakan, topbar & halaman depan ikut berubah.');
    }

    public function batal(): void
    {
        $this->muat();
    }

    /** Contoh cetak: kop + ringkasan identitas (pakai data TERSIMPAN). */
    public function contohCetak()
    {
        Identitas::lupakan();
        $pdf = Pdf::loadView($this->printView, ['idt' => Identitas::get(), 'dicetak' => now(), 'oleh' => auth()->user()?->name])->setPaper('A4');

        return response()->streamDownload(fn() => print $pdf->output(), 'contoh-kop-identitas.pdf');
    }
};
?>

<div>
    <x-page-title title="Identitas Instansi" subtitle="Logo, nama & profil instansi — dipakai kop semua cetakan, topbar, halaman depan & login" />

    <div class="w-full min-h-[calc(100vh-5rem)] bg-surface-soft dark:bg-gray-900">
        <div class="grid grid-cols-1 gap-6 px-6 py-6 mx-auto max-w-7xl xl:grid-cols-5">

            {{-- FORM --}}
            <div class="space-y-4 xl:col-span-3">
                <x-border-form title="Identitas">
                    <div class="space-y-4">
                        <div>
                            <x-input-label value="Nama Instansi *" />
                            <x-text-input wire:model.live.debounce.400ms="form.nama" maxlength="150" :error="$errors->has('form.nama')" class="w-full mt-1" />
                            <x-input-error :messages="$errors->get('form.nama')" class="mt-1" />
                        </div>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <x-input-label value="Nama Singkat (topbar)" />
                                <x-text-input wire:model.live.debounce.400ms="form.nama_singkat" maxlength="50" placeholder="Mis. Klinik Madinah" class="w-full mt-1" />
                                <x-input-error :messages="$errors->get('form.nama_singkat')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label value="Tagline / Motto" />
                                <x-text-input wire:model.live.debounce.400ms="form.tagline" maxlength="200" placeholder="Mis. Melayani dengan Ikhlas" class="w-full mt-1" />
                                <x-input-error :messages="$errors->get('form.tagline')" class="mt-1" />
                            </div>
                        </div>
                        <div>
                            <x-input-label value="Profil Singkat (Tentang kami di halaman depan)" />
                            <x-textarea wire:model.live.debounce.600ms="form.deskripsi" rows="4" maxlength="2000" :error="$errors->has('form.deskripsi')" class="w-full mt-1" />
                            <div class="flex justify-between mt-1 text-[11px] text-muted"><span>Ganti baris dipertahankan.</span><span>{{ mb_strlen($form['deskripsi'] ?? '') }}/2000</span></div>
                            <x-input-error :messages="$errors->get('form.deskripsi')" class="mt-1" />
                        </div>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <x-input-label value="Nama Pimpinan" />
                                <x-text-input wire:model="form.pimpinan" maxlength="100" class="w-full mt-1" />
                            </div>
                            <div>
                                <x-input-label value="Jabatan Pimpinan" />
                                <x-text-input wire:model="form.jabatan_pimpinan" maxlength="100" placeholder="Mis. Kepala Klinik / Direktur" class="w-full mt-1" />
                            </div>
                        </div>
                    </div>
                </x-border-form>

                <x-border-form title="Alamat & Kontak">
                    <div class="space-y-4">
                        <div>
                            <x-input-label value="Alamat" />
                            <x-text-input wire:model.live.debounce.400ms="form.alamat" maxlength="300" class="w-full mt-1" />
                            <x-input-error :messages="$errors->get('form.alamat')" class="mt-1" />
                        </div>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div class="sm:col-span-2">
                                <x-input-label value="Kota (dipakai tempat tanda tangan)" />
                                <x-text-input wire:model.live.debounce.400ms="form.kota" maxlength="100" class="w-full mt-1" />
                            </div>
                            <div>
                                <x-input-label value="Kode Pos" />
                                <x-text-input wire:model.live.debounce.400ms="form.kode_pos" maxlength="10" class="w-full mt-1" />
                            </div>
                        </div>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <x-input-label value="Telepon" />
                                <x-text-input wire:model.live.debounce.400ms="form.telp" maxlength="50" class="w-full mt-1" />
                            </div>
                            <div>
                                <x-input-label value="Fax" />
                                <x-text-input wire:model.live.debounce.400ms="form.fax" maxlength="50" class="w-full mt-1" />
                            </div>
                            <div>
                                <x-input-label value="Email" />
                                <x-text-input wire:model.live.debounce.400ms="form.email" type="email" maxlength="100" :error="$errors->has('form.email')" class="w-full mt-1" />
                                <x-input-error :messages="$errors->get('form.email')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label value="Website" />
                                <x-text-input wire:model.live.debounce.400ms="form.website" maxlength="100" placeholder="www.contoh.com" class="w-full mt-1" />
                            </div>
                        </div>
                        <div>
                            <x-input-label value="Jam Operasional (profil / halaman depan)" />
                            <x-text-input wire:model="form.jam_operasional" maxlength="150" placeholder="Mis. Pagi 07.00-12.00 · Sore 17.00-21.00" class="w-full mt-1" />
                            <x-input-error :messages="$errors->get('form.jam_operasional')" class="mt-1" />
                        </div>
                    </div>
                </x-border-form>
            </div>

            {{-- LOGO & PRATINJAU --}}
            <div class="space-y-4 xl:col-span-2">
                <x-border-form title="Logo">
                    @php $logoPratinjau = $this->pratinjauLogo(); @endphp
                    <div class="flex items-center gap-4">
                        <div class="flex items-center justify-center w-32 h-32 overflow-hidden bg-white border rounded-2xl border-hairline shrink-0">
                            @if ($logoPratinjau)
                                <img src="{{ $logoPratinjau }}" alt="Logo" class="object-contain max-w-full max-h-full">
                            @else
                                <span class="text-xs text-center text-muted">Belum ada logo</span>
                            @endif
                        </div>
                        <div class="space-y-2 text-sm">
                            <label class="inline-flex items-center px-4 py-2 font-medium text-white rounded-lg cursor-pointer bg-brand-green hover:bg-brand-green/90">
                                Pilih logo…
                                <input type="file" wire:model="logoBaru" accept="image/png,image/jpeg,image/webp" class="hidden">
                            </label>
                            <div wire:loading wire:target="logoBaru" class="text-xs text-muted">Mengunggah…</div>
                            @if ($logoPratinjau)
                                <button type="button" wire:click="tandaiHapusLogo" class="block text-xs text-red-600 hover:underline">Hapus logo</button>
                            @endif
                            <p class="text-[11px] text-muted">PNG/JPG/WEBP, maks 2 MB. Disarankan PNG latar transparan, resolusi tinggi (≥ 400 px) supaya tajam di cetakan.</p>
                            @if ($logoBaru || $hapusLogo)
                                <p class="text-[11px] font-semibold text-amber-700">Belum tersimpan — klik Simpan.</p>
                            @endif
                        </div>
                    </div>
                    <x-input-error :messages="$errors->get('logoBaru')" class="mt-2" />
                </x-border-form>

                <x-border-form title="Pratinjau kop cetakan">
                    @php
                        $alamatPratinjau = collect([$form['alamat'], trim($form['kota'] . ' ' . $form['kode_pos'])])
                            ->filter(fn($v) => filled($v))->unique()->implode(', ');
                    @endphp
                    <div class="p-4 bg-white border rounded-lg border-hairline">
                        <div class="text-right">
                            @if ($logoPratinjau)
                                <img src="{{ $logoPratinjau }}" alt="Logo" class="inline-block object-contain h-16 mb-1">
                            @endif
                            <div class="text-sm font-bold text-gray-900">{{ $form['nama'] ?: 'Nama Instansi' }}</div>
                            @if (filled($alamatPratinjau))<div class="text-[11px] text-gray-700">{{ $alamatPratinjau }}</div>@endif
                            @if (filled($form['telp']) || filled($form['fax']))
                                <div class="text-[11px] text-gray-700">{{ filled($form['telp']) ? 'Telp. ' . $form['telp'] : '' }} {{ filled($form['fax']) ? 'Fax. ' . $form['fax'] : '' }}</div>
                            @endif
                            @if (filled($form['email']) || filled($form['website']))
                                <div class="text-[11px] text-gray-700">{{ collect([$form['email'], $form['website']])->filter()->implode(' · ') }}</div>
                            @endif
                        </div>
                        <div class="mt-2 border-t-[2.5px] border-[#157547]"></div>
                        <div class="mt-0.5 border-t border-[#157547]"></div>
                    </div>
                    <p class="mt-2 text-[11px] text-muted">Pratinjau perkiraan — hasil sebenarnya: <b>Contoh cetak PDF</b> (memakai data yang sudah disimpan).</p>
                </x-border-form>

                <div class="flex flex-wrap justify-end gap-2">
                    <x-secondary-button type="button" wire:click="contohCetak" wire:loading.attr="disabled" wire:target="contohCetak">Contoh cetak PDF</x-secondary-button>
                    <x-secondary-button type="button" wire:click="batal">Batal</x-secondary-button>
                    <x-primary-button type="button" wire:click="simpan" wire:loading.attr="disabled" wire:target="simpan,logoBaru">
                        <span wire:loading.remove wire:target="simpan">Simpan</span>
                        <span wire:loading wire:target="simpan">Menyimpan...</span>
                    </x-primary-button>
                </div>
            </div>

        </div>
    </div>
</div>
