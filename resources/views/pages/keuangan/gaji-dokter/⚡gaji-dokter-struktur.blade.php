<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use App\Support\GajiDokterSirus;
use App\Support\GajiDokterStruktur;
use App\Support\PengaturanGajiDokter;

/**
 * Tab STRUKTUR GAJI Gaji Dokter (anak pembungkus gaji-dokter) — tahap 1 pemindahan penggajian dokter ke SIAP.
 * Satu baris per dokter SIRUS: skema gaji pokok, basis & persen potongan RS (+ aturan berjenjang), PPh 21, NPWP, tarif kapita,
 * tunjangan & potongan rutin — persis panel Struktur Gaji di Master Dokter SIRUS (GajiDokterStruktur). Panel MODE di atas
 * mengatur dua saklar PengaturanGajiDokter: sumber struktur (SIRUS | SIAP) dan mesin slip (SIRUS | SIAP).
 * Tarik dari SIRUS = salin struktur SIRUS (saat SIAP sudah sumber, baris SIAP tidak ditimpa). Simpan saat sumber = SIAP →
 * dikirim ke SIRUS (PUT) — gagal kirim dicatat per baris dan bisa dikirim ulang.
 */
new class extends Component {
    #[Session(key: 'gaji-dokter-struktur-cari')]
    public string $cari = '';

    #[Session(key: 'gaji-dokter-struktur-saring')]
    public string $saring = 'aktif'; // aktif | belum | gagal | semua

    public string $sumberStruktur = 'SIRUS';
    public string $mesinSlip = 'SIRUS';

    /** Modal form struktur satu dokter. */
    public ?string $editDrId = null;
    public string $editDrName = '';
    public array $form = [];
    /** Editor aturan berjenjang (basis B): daftar [kode => [tipe, nilai]] + baris entri baru. */
    public array $aturan = [];
    public string $aturanKode = '';
    public string $aturanTipe = 'P';
    public string $aturanNilai = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    public function mount(): void
    {
        if (!in_array($this->saring, ['aktif', 'belum', 'gagal', 'semua'], true)) {
            $this->saring = 'aktif';
        }
        $pengaturan = PengaturanGajiDokter::ambil();
        $this->sumberStruktur = $pengaturan->sumber_struktur;
        $this->mesinSlip = $pengaturan->mesin_slip;
    }

    public function updatedCari(): void { unset($this->rows); }
    public function updatedSaring(): void { unset($this->rows); }

    public function resetFilters(): void
    {
        $this->reset(['cari']);
        $this->saring = 'aktif';
        unset($this->rows);
    }

    // ─── Mode ────────────────────────────────────────────────────────────────

    public function simpanMode(): void
    {
        $pesanGalat = PengaturanGajiDokter::simpan($this->sumberStruktur, $this->mesinSlip, auth()->id());
        if ($pesanGalat !== null) {
            $this->dispatch('toast', type: 'error', message: $pesanGalat);
            $this->mount();
            return;
        }
        $this->dispatch('toast', type: 'success', message: 'Mode disimpan: struktur di ' . $this->sumberStruktur . ', slip dihitung ' . $this->mesinSlip . '.');
        $this->dispatch('keuangan.gaji-dokter.modeBerubah');
        unset($this->rows, $this->ringkasan);
    }

    // ─── Sinkron SIRUS ───────────────────────────────────────────────────────

    public function tarikDariSirus(): void
    {
        try {
            $hasilTarikStruktur = GajiDokterStruktur::tarikDariSirus(auth()->id());
            $pesanToast = "Struktur {$hasilTarikStruktur['ditarik']} dokter disalin dari SIRUS" . ($hasilTarikStruktur['dilewati'] > 0 ? ", {$hasilTarikStruktur['dilewati']} dilewati (sudah disetel di SIAP / tak dikenal)" : '') . '.';
            $modeSirus = $hasilTarikStruktur['mode'];
            if (($modeSirus['struktur_gaji_dari_siap'] ?? false) !== PengaturanGajiDokter::strukturDiSiap()) {
                $pesanToast .= ' Perhatian: saklar SIRUS (SIAP_STRUKTUR_GAJI_DARI_SIAP=' . (($modeSirus['struktur_gaji_dari_siap'] ?? false) ? 'true' : 'false') . ') belum sama dengan mode di sini.';
            }
            $this->dispatch('toast', type: 'success', message: $pesanToast);
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());
        }
        unset($this->rows, $this->ringkasan);
    }

    public function kirimUlangGagal(): void
    {
        $hasilKirim = GajiDokterStruktur::kirimSemuaKeSirus(true);
        $pesanToast = "{$hasilKirim['terkirim']} struktur terkirim ke SIRUS.";
        if ($hasilKirim['gagal'] !== []) {
            $pesanToast .= ' Gagal ' . count($hasilKirim['gagal']) . ': ' . implode('; ', array_slice($hasilKirim['gagal'], 0, 3)) . (count($hasilKirim['gagal']) > 3 ? '; …' : '');
        }
        $this->dispatch('toast', type: $hasilKirim['gagal'] === [] ? 'success' : 'warning', message: $pesanToast);
        unset($this->rows, $this->ringkasan);
    }

    // ─── Form struktur ───────────────────────────────────────────────────────

    public function bukaStruktur(string $drId): void
    {
        $dokter = $this->rows->firstWhere('dr_id', $drId) ?? GajiDokterStruktur::daftar('', 'semua')->firstWhere('dr_id', $drId);
        if (!$dokter) {
            $this->dispatch('toast', type: 'error', message: 'Dokter tidak ditemukan.');
            return;
        }
        $this->editDrId = $drId;
        $this->editDrName = (string) $dokter->dr_name;
        $struktur = GajiDokterStruktur::ambil($drId);
        $this->form = array_map(fn($nilai) => $nilai === null ? '' : (is_float($nilai) ? rtrim(rtrim(number_format($nilai, 2, '.', ''), '0'), '.') : (string) $nilai), $struktur);
        $this->aturan = GajiDokterStruktur::parseAturan($struktur['potongan_rs_aturan']);
        $this->aturanKode = '';
        $this->aturanTipe = 'P';
        $this->aturanNilai = '';
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'gaji-dokter-struktur');
    }

    public function closeModal(): void
    {
        $this->editDrId = null;
        $this->dispatch('close-modal', name: 'gaji-dokter-struktur');
    }

    public function updatedFormNpwpStatus(string $nilai): void
    {
        if ($nilai === 'N') {
            $this->form['npwp'] = '';
        }
    }

    public function tambahAturan(): void
    {
        $kode = trim($this->aturanKode);
        if (!in_array($kode, GajiDokterStruktur::KOMPONEN_JASA, true)) {
            $this->dispatch('toast', type: 'error', message: 'Pilih komponen jasa dulu.');
            return;
        }
        if (!is_numeric($this->aturanNilai) || (float) $this->aturanNilai < 0 || ($this->aturanTipe === 'P' && (float) $this->aturanNilai > 100)) {
            $this->dispatch('toast', type: 'error', message: $this->aturanTipe === 'P' ? 'Persen harus 0–100.' : 'Nominal harus angka positif.');
            return;
        }
        $this->aturan[$kode] = ['tipe' => $this->aturanTipe === 'N' ? 'N' : 'P', 'nilai' => (float) $this->aturanNilai];
        $this->aturanKode = '';
        $this->aturanNilai = '';
    }

    public function hapusAturan(string $kode): void
    {
        unset($this->aturan[$kode]);
    }

    public function simpanStruktur(): void
    {
        if ($this->editDrId === null) {
            return;
        }
        $data = $this->form;
        $data['potongan_rs_aturan'] = $data['potongan_rs_basis'] === 'B' ? GajiDokterStruktur::susunAturan($this->aturan) : null;
        foreach (GajiDokterStruktur::KOLOM_NOMINAL as $kolom) {
            $data[$kolom] = ($data[$kolom] ?? '') === '' ? 0 : $data[$kolom];
        }
        $hasilSimpanStruktur = GajiDokterStruktur::simpan($this->editDrId, $data, auth()->id());
        if (!$hasilSimpanStruktur['ok']) {
            $this->dispatch('toast', type: 'error', message: $hasilSimpanStruktur['pesan']);
            return;
        }
        $pesanToast = "Struktur gaji {$this->editDrName} tersimpan.";
        if ($hasilSimpanStruktur['sinkron'] !== null) {
            $pesanToast .= ' Gagal dikirim ke SIRUS: ' . $hasilSimpanStruktur['sinkron'] . ' — coba "Kirim ulang yang gagal".';
        } elseif (PengaturanGajiDokter::strukturDiSiap() && GajiDokterSirus::aktif()) {
            $pesanToast .= ' Sudah dikirim ke SIRUS.';
        }
        $this->dispatch('toast', type: $hasilSimpanStruktur['sinkron'] === null ? 'success' : 'warning', message: $pesanToast);
        $this->closeModal();
        unset($this->rows, $this->ringkasan);
    }

    // ─── Data ────────────────────────────────────────────────────────────────

    #[Computed]
    public function rows()
    {
        return GajiDokterStruktur::daftar($this->cari, $this->saring);
    }

    #[Computed]
    public function ringkasan(): array
    {
        $semuaDokter = GajiDokterStruktur::daftar('', 'aktif');

        return ['aktif' => $semuaDokter->count(), 'ada' => $semuaDokter->where('ada_struktur', 1)->count(),
            'siap' => $semuaDokter->where('sumber', 'SIAP')->count(), 'gagal' => $semuaDokter->whereNotNull('sinkron_pesan')->count()];
    }

    #[Computed]
    public function pilihanKomponenJasa(): array
    {
        return array_combine(GajiDokterStruktur::KOMPONEN_JASA, array_map(fn($kode) => GajiDokterStruktur::labelKomponen($kode), GajiDokterStruktur::KOMPONEN_JASA));
    }
};
?>

<div class="mt-4">
    @php
        $rupiah = fn($nilai) => number_format((float) $nilai, 0, ',', '.');
        $formatPersen = fn($nilai) => rtrim(rtrim(number_format((float) $nilai, 2, ',', '.'), '0'), ',');
        $ringkasan = $this->ringkasan;
        $strukturDiSiap = $sumberStruktur === 'SIAP';
    @endphp

    {{-- MODE PEMINDAHAN --}}
    <div class="px-4 py-3 mb-3 border bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
        <div class="flex flex-wrap items-start gap-6">
            <div class="min-w-0">
                <div class="text-sm font-semibold text-ink dark:text-gray-100">Mode pemindahan gaji dokter ke SIAP</div>
                <p class="mt-0.5 text-xs text-muted dark:text-gray-400">Dinyalakan bertahap. Saklar sisi SIRUS (<span class="font-mono">SIAP_STRUKTUR_GAJI_DARI_SIAP</span>, <span class="font-mono">SIAP_GAJI_DOKTER_NONAKTIF</span> di .env SIRUS) harus mengikuti pilihan di sini.</p>
            </div>
            <div class="flex flex-wrap items-end gap-3 ml-auto">
                <div class="w-56">
                    <x-input-label for="gds-sumber" value="Struktur gaji disetel di" />
                    <div class="mt-1">
                        <x-select-input id="gds-sumber" wire:model="sumberStruktur">
                            <option value="SIRUS">SIRUS (tahap 0 — SIAP hanya menyalin)</option>
                            <option value="SIAP">SIAP (tahap 1 — dikirim ke SIRUS)</option>
                        </x-select-input>
                    </div>
                </div>
                <div class="w-56">
                    <x-input-label for="gds-mesin" value="Slip dihitung oleh" />
                    <div class="mt-1">
                        <x-select-input id="gds-mesin" wire:model="mesinSlip">
                            <option value="SIRUS">SIRUS (tahap 2 — SIAP membanding)</option>
                            <option value="SIAP">SIAP (tahap 3 — SIRUS arsip)</option>
                        </x-select-input>
                    </div>
                </div>
                <x-confirm-button variant="primary" action="simpanMode()" title="Simpan mode"
                    message="Ubah mode pemindahan? Struktur di {{ $sumberStruktur }}, slip dihitung {{ $mesinSlip }}. Pastikan .env SIRUS sudah disetel sama, dan untuk mesin SIAP: struktur semua dokter sudah ditarik / disetel di SIAP." confirmText="Ya, simpan" cancelText="Batal">
                    Simpan mode
                </x-confirm-button>
            </div>
        </div>
    </div>

    {{-- TOOLBAR --}}
    <div class="flex flex-wrap items-end gap-3 px-4 py-3 mb-3 border bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
        <div class="w-56">
            <x-input-label for="gds-cari" value="Cari dokter" />
            <x-text-input id="gds-cari" type="text" wire:model.live.debounce.300ms="cari" placeholder="nama dokter" class="block w-full mt-1" />
        </div>
        <div class="w-56">
            <x-input-label for="gds-saring" value="Tampilkan" />
            <div class="mt-1">
                <x-select-input id="gds-saring" wire:model.live="saring">
                    <option value="aktif">Dokter aktif</option>
                    <option value="belum">Belum punya struktur</option>
                    <option value="gagal">Gagal kirim ke SIRUS</option>
                    <option value="semua">Semua dokter</option>
                </x-select-input>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2 ml-auto">
            <x-badge :variant="$ringkasan['ada'] === $ringkasan['aktif'] ? 'success' : 'warning'">{{ $ringkasan['ada'] }} / {{ $ringkasan['aktif'] }} dokter aktif punya struktur</x-badge>
            @if ($strukturDiSiap)<x-badge variant="info">{{ $ringkasan['siap'] }} disetel di SIAP</x-badge>@endif
            @if ($ringkasan['gagal'] > 0)<x-badge variant="danger">{{ $ringkasan['gagal'] }} gagal kirim</x-badge>@endif
            @if (GajiDokterSirus::aktif())
                <x-confirm-button variant="secondary" action="tarikDariSirus()" title="Tarik dari SIRUS"
                    message="{{ $strukturDiSiap ? 'Salin struktur dari SIRUS hanya untuk dokter yang belum disetel di SIAP? Setelan SIAP tidak ditimpa.' : 'Salin struktur gaji semua dokter dari SIRUS? Salinan lama di SIAP ditimpa (SIRUS masih sumbernya).' }}" confirmText="Ya, tarik" cancelText="Batal">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                    Tarik dari SIRUS
                </x-confirm-button>
                @if ($strukturDiSiap && $ringkasan['gagal'] > 0)
                    <x-secondary-button type="button" wire:click="kirimUlangGagal" wire:loading.attr="disabled" wire:target="kirimUlangGagal">
                        <span wire:loading.remove wire:target="kirimUlangGagal">Kirim ulang yang gagal</span>
                        <span wire:loading wire:target="kirimUlangGagal"><x-loading /> Mengirim…</span>
                    </x-secondary-button>
                @endif
            @endif
            <x-toolbar-refresh-reset :label="null" />
        </div>
    </div>

    {{-- DAFTAR --}}
    <div class="overflow-x-auto border shadow-sm bg-canvas border-hairline rounded-2xl dark:border-gray-700 dark:bg-gray-900">
        <table class="ds-table">
            <thead>
                <tr>
                    <th>Dokter</th>
                    <th>Karyawan SIAP</th>
                    <th>Skema &amp; gaji pokok</th>
                    <th>Potongan RS</th>
                    <th>PPh 21 · NPWP</th>
                    <th class="text-right">Kapita RI / RJ</th>
                    <th>Sumber</th>
                    <th class="text-right">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->rows as $row)
                    <tr wire:key="gds-{{ $row->dr_id }}" class="{{ $row->aktif === '1' ? '' : 'opacity-60' }}">
                        <td><span class="font-semibold">{{ $row->dr_name }}</span><span class="block font-mono text-xs text-muted">{{ $row->dr_id }}{{ $row->aktif === '1' ? '' : ' · nonaktif' }}</span></td>
                        <td class="text-sm">@if ($row->emp_id){{ $row->emp_name }}<span class="block font-mono text-xs text-muted">{{ $row->emp_id }}</span>@else<x-badge variant="warning">Belum dipetakan</x-badge>@endif</td>
                        @if ($row->ada_struktur)
                            <td class="text-sm"><span class="font-mono">{{ $row->skema_gaji_pokok }}</span> · {{ $rupiah($row->basic_salary) }}</td>
                            <td class="text-sm"><span class="font-mono">{{ $row->potongan_rs_basis }}</span>@if (in_array($row->potongan_rs_basis, ['T', 'J'], true)) · {{ $formatPersen($row->potongan_rs_persen) }}% @elseif ($row->potongan_rs_basis === 'B') · berjenjang @else · tidak @endif</td>
                            <td class="text-sm">{{ $formatPersen($row->pph21_persen) }}% · {{ $row->npwp_status === 'Y' ? 'NPWP' : 'tanpa NPWP' }}</td>
                            <td class="font-mono text-sm text-right tabular-nums">{{ (float) $row->tarif_per_kapita_ri > 0 ? $rupiah($row->tarif_per_kapita_ri) : '–' }} / {{ (float) $row->tarif_per_kapita_rj > 0 ? $rupiah($row->tarif_per_kapita_rj) : '–' }}</td>
                            <td class="text-xs">
                                <x-badge :variant="$row->sumber === 'SIAP' ? 'info' : 'gray'">{{ $row->sumber === 'SIAP' ? 'Disetel di SIAP' : 'Salinan SIRUS' }}</x-badge>
                                @if ($row->sinkron_pesan)<x-badge variant="danger" title="{{ $row->sinkron_pesan }}">gagal kirim</x-badge>
                                @elseif ($row->sumber === 'SIAP' && $row->disinkron_at)<span class="block mt-0.5 text-muted">terkirim {{ \Carbon\Carbon::parse($row->disinkron_at)->format('d/m/Y H:i') }}</span>@endif
                            </td>
                        @else
                            <td colspan="4" class="text-sm text-muted">Belum ada struktur — klik Ubah untuk mengisi, atau Tarik dari SIRUS.</td>
                            <td></td>
                        @endif
                        <td class="text-right">
                            <x-outline-button type="button" class="gap-2 whitespace-nowrap shrink-0" wire:click="bukaStruktur('{{ $row->dr_id }}')" wire:loading.attr="disabled" wire:target="bukaStruktur('{{ $row->dr_id }}')">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                {{ $row->ada_struktur ? 'Ubah' : 'Isi' }}
                            </x-outline-button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-8 text-center text-muted">{{ $ringkasan['aktif'] === 0 ? 'Master dokter belum ditarik — buka tab Pemetaan Dokter → Segarkan dari SIRUS.' : 'Tidak ada dokter yang cocok dengan saringan.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- MODAL STRUKTUR (meniru panel Struktur Gaji di Master Dokter SIRUS) --}}
    <x-modal name="gaji-dokter-struktur" size="4xl" focusable>
        <div class="flex flex-col">
            <x-modul-dokumen.header judul="Struktur Gaji" tutup="closeModal"
                ikon="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                {{ $editDrName }} — dipakai saat slip gaji dokter diproses{{ $strukturDiSiap ? '; tersimpan di SIAP dan dikirim ke SIRUS' : '; SIRUS masih sumbernya, ubah di sini hanya bila mode sudah SIAP' }}.
                <x-slot:badge><x-badge variant="warning">{{ $editDrId ?? '-' }}</x-badge></x-slot:badge>
            </x-modul-dokumen.header>

            @if ($editDrId !== null)
                <div class="px-4 py-4 space-y-4 bg-surface-soft dark:bg-gray-950/20">
                    {{-- PANDUAN --}}
                    <div x-data="{ buka: false }" class="overflow-hidden border border-blue-200 rounded-2xl bg-blue-50 dark:bg-blue-900/20 dark:border-blue-700">
                        <button type="button" x-on:click="buka = !buka" class="flex items-center justify-between w-full px-4 py-2.5 text-sm font-semibold text-blue-900 hover:bg-blue-100 dark:text-blue-200 dark:hover:bg-blue-900/30 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-green">
                            <span>Panduan: cara menyetel struktur gaji</span>
                            <svg class="w-4 h-4 text-blue-600 transition-transform motion-reduce:transition-none" x-bind:class="buka && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                        </button>
                        <div x-show="buka" x-cloak class="px-4 pb-4 space-y-2 text-sm text-blue-900 dark:text-blue-100">
                            <p><b>Gaji pokok</b> nominal tetap per bulan, isi 0 bila dokter murni dibayar dari jasa. <b>Skema</b>: A aditif = jasa + pokok; G garanty fee = yang terbesar antara jasa dan pokok (pokok jadi jaminan minimum); N = tanpa pokok.</p>
                            <p><b>Status NPWP</b> satu-satunya yang menentukan pajak: dimatikan berarti PPh 21 dipotong 20% lebih tinggi. Nomor NPWP hanya arsip, boleh kosong.</p>
                            <p><b>Basis potongan RS</b>: T dari total gaji (pokok ikut dipotong); J dari jasa saja; N tidak dipotong; B berjenjang per komponen — isi aturannya di bawah: persen (mis. 10) atau nominal (Rp500.000) per komponen jasa.</p>
                            <p><b>PPh 21</b> bawaan 2,5% = NPPN 50% × tarif 5%. <b>Tarif per kapita</b> RI/RJ: bila terisi, komponen jalur itu diganti satu baris pasien × tarif. <b>Tunjangan</b> kena pajak; <b>potongan rutin</b> dipotong setelah pajak. Isi 0 bila tidak dipakai.</p>
                            <p>Setelan baru berlaku untuk slip yang diproses sesudahnya; slip lama memakai nilai saat diproses.</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <x-border-form title="Gaji pokok &amp; pajak">
                            <div class="space-y-3">
                                <div><x-input-label for="gds-pokok" value="Gaji Pokok (Rp)" /><div class="mt-1"><x-text-input-number id="gds-pokok" wire:model="form.basic_salary" /></div></div>
                                <div><x-input-label for="gds-skema" value="Skema Gaji Pokok" /><div class="mt-1"><x-select-input id="gds-skema" wire:model="form.skema_gaji_pokok">@foreach (GajiDokterStruktur::SKEMA as $kode => $label)<option value="{{ $kode }}">{{ $kode }} — {{ $label }}</option>@endforeach</x-select-input></div></div>
                                <div class="flex items-center gap-3">
                                    <x-toggle wire:model.live="form.npwp_status" trueValue="Y" falseValue="N" label="Ber-NPWP" />
                                </div>
                                @if (($form['npwp_status'] ?? 'Y') === 'Y')
                                    <div><x-input-label for="gds-npwp" value="Nomor NPWP (opsional)" /><x-text-input id="gds-npwp" type="text" wire:model="form.npwp" maxlength="30" class="block w-full mt-1" /></div>
                                @endif
                                <div><x-input-label for="gds-pph" value="PPh 21 (%)" /><div class="mt-1 w-32"><x-text-input-number id="gds-pph" wire:model="form.pph21_persen" :decimals="2" /></div></div>
                            </div>
                        </x-border-form>

                        <x-border-form title="Potongan rumah sakit">
                            <div class="space-y-3">
                                <div><x-input-label for="gds-basis" value="Basis Potongan RS" /><div class="mt-1"><x-select-input id="gds-basis" wire:model.live="form.potongan_rs_basis">@foreach (GajiDokterStruktur::BASIS as $kode => $label)<option value="{{ $kode }}">{{ $kode }} — {{ $label }}</option>@endforeach</x-select-input></div></div>
                                @if (in_array($form['potongan_rs_basis'] ?? 'T', ['T', 'J'], true))
                                    <div><x-input-label for="gds-persen" value="Potongan RS (%)" /><div class="mt-1 w-32"><x-text-input-number id="gds-persen" wire:model="form.potongan_rs_persen" :decimals="2" /></div></div>
                                @elseif (($form['potongan_rs_basis'] ?? '') === 'B')
                                    <div class="text-xs text-muted dark:text-gray-400">Aturan per komponen jasa — persen dari komponen itu, atau nominal tetap (tidak melebihi komponennya).</div>
                                    <table class="w-full text-sm">
                                        <tbody>
                                            @forelse ($aturan as $kode => $aturanItem)
                                                <tr wire:key="aturan-{{ $kode }}" class="border-t border-hairline dark:border-gray-700">
                                                    <td class="py-1">{{ GajiDokterStruktur::labelKomponen($kode) }} <span class="font-mono text-xs text-muted">{{ $kode }}</span></td>
                                                    <td class="py-1 font-mono text-right tabular-nums">{{ $aturanItem['tipe'] === 'N' ? 'Rp ' . $rupiah($aturanItem['nilai']) : $formatPersen($aturanItem['nilai']) . '%' }}</td>
                                                    <td class="py-1 text-right"><x-hapus-button action="hapusAturan('{{ $kode }}')" title="Hapus aturan" message="Hapus aturan {{ $kode }}?" /></td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="3" class="py-2 text-xs italic text-muted">Belum ada aturan — tambahkan di bawah.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                    <div class="grid items-end grid-cols-12 gap-2 pt-2 border-t border-hairline dark:border-gray-700">
                                        <div class="col-span-5"><x-input-label for="gds-aturan-kode" value="Komponen" /><div class="mt-1"><x-select-input id="gds-aturan-kode" wire:model="aturanKode"><option value="">— pilih —</option>@foreach ($this->pilihanKomponenJasa as $kode => $label)<option value="{{ $kode }}">{{ $label }}</option>@endforeach</x-select-input></div></div>
                                        <div class="col-span-3"><x-input-label for="gds-aturan-tipe" value="Tipe" /><div class="mt-1"><x-select-input id="gds-aturan-tipe" wire:model="aturanTipe"><option value="P">Persen</option><option value="N">Nominal Rp</option></x-select-input></div></div>
                                        <div class="col-span-3"><x-input-label for="gds-aturan-nilai" value="Nilai" /><div class="mt-1"><x-text-input-number id="gds-aturan-nilai" wire:model="aturanNilai" :decimals="2" /></div></div>
                                        <div class="col-span-1"><x-secondary-button type="button" wire:click="tambahAturan" class="!px-3" title="Tambah aturan" aria-label="Tambah aturan">+</x-secondary-button></div>
                                    </div>
                                @endif
                            </div>
                        </x-border-form>

                        <x-border-form title="Tarif per kapita &amp; tunjangan (kena pajak)">
                            <div class="grid grid-cols-2 gap-3">
                                <div><x-input-label for="gds-kap-ri" value="Tarif per pasien RI" /><div class="mt-1"><x-text-input-number id="gds-kap-ri" wire:model="form.tarif_per_kapita_ri" /></div></div>
                                <div><x-input-label for="gds-kap-rj" value="Tarif per kunjungan RJ" /><div class="mt-1"><x-text-input-number id="gds-kap-rj" wire:model="form.tarif_per_kapita_rj" /></div></div>
                                <div><x-input-label for="gds-tj-s" value="Tunjangan Struktural" /><div class="mt-1"><x-text-input-number id="gds-tj-s" wire:model="form.tunjangan_struktural" /></div></div>
                                <div><x-input-label for="gds-tj-f" value="Tunjangan Fungsional" /><div class="mt-1"><x-text-input-number id="gds-tj-f" wire:model="form.tunjangan_fungsional" /></div></div>
                                <div><x-input-label for="gds-tj-h" value="Tunjangan Kehadiran" /><div class="mt-1"><x-text-input-number id="gds-tj-h" wire:model="form.tunjangan_hadir" /></div></div>
                            </div>
                        </x-border-form>

                        <x-border-form title="Potongan rutin (setelah pajak)">
                            <div class="grid grid-cols-2 gap-3">
                                @foreach (GajiDokterStruktur::POTONGAN_RUTIN as $kolom => $kode)
                                    <div><x-input-label for="gds-{{ $kolom }}" :value="GajiDokterStruktur::LABEL_KOLOM[$kolom]" /><div class="mt-1"><x-text-input-number id="gds-{{ $kolom }}" wire:model="form.{{ $kolom }}" /></div></div>
                                @endforeach
                            </div>
                        </x-border-form>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2 px-4 py-3 border-t border-hairline dark:border-gray-700">
                    <x-secondary-button type="button" wire:click="closeModal">Batal</x-secondary-button>
                    <x-primary-button type="button" wire:click="simpanStruktur" wire:loading.attr="disabled" wire:target="simpanStruktur">
                        <span wire:loading.remove wire:target="simpanStruktur">Simpan{{ $strukturDiSiap && GajiDokterSirus::aktif() ? ' & kirim ke SIRUS' : '' }}</span>
                        <span wire:loading wire:target="simpanStruktur"><x-loading /> Menyimpan…</span>
                    </x-primary-button>
                </div>
            @endif
        </div>
    </x-modal>
</div>
