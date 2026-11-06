<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Carbon\Carbon;
use App\Support\Format;
use App\Support\GajiKaryawan;
use App\Support\PinjamanKaryawan;

/**
 * Tab PEMBAYARAN Pinjaman Karyawan (anak pembungkus pinjaman-karyawan): catat pembayaran angsuran DI LUAR GAJI
 * (tunai / transfer) — satu atau beberapa angsuran sekaligus (dobel, di muka). Angsuran yang sudah dibayar di sini
 * tidak dipotong lagi di slip. Riwayat pembayaran pinjaman terpilih + daftar pembayaran luar gaji terbaru (bisa dihapus
 * selama bukan potongan slip Final).
 * $pinjamanId dari pembungkus (tombol Bayar di tab Buku) → pinjaman langsung terpilih.
 */
new class extends Component {
    public ?int $pinjamanId = null;

    #[Session(key: 'pinjaman-karyawan-bulan')]
    public string $bulan = '';

    /** LOV karyawan → pinjaman miliknya. */
    public string $empNama = '';
    public string $empId = '';

    public array $form = [];
    public int   $formVersi = 0;

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    public function mount(?int $pinjamanId = null): void
    {
        $this->resetForm();
        if ($pinjamanId) {
            $row = PinjamanKaryawan::ambil($pinjamanId, $this->periode());
            if ($row) {
                $this->empId = $row->emp_id;
                $this->empNama = $row->emp_name;
                $this->pilihPinjaman($pinjamanId);
            }
        }
    }

    private function periode(): string
    {
        return preg_match('/^(0[1-9]|1[0-2])\/\d{4}$/', $this->bulan) ? Carbon::createFromFormat('!' . Format::BULAN, $this->bulan)->format('Y-m') : now()->subMonthNoOverflow()->format('Y-m');
    }

    private function resetForm(): void
    {
        $this->pinjamanId = null;
        $this->form = ['dari_ke' => 1, 'sampai_ke' => 1, 'sumber' => 'TUNAI', 'jumlah' => 0, 'tanggal' => now()->format(Format::TANGGAL), 'keterangan' => ''];
        $this->formVersi++;
        unset($this->pinjaman, $this->riwayatOrang, $this->pinjamanList);
    }

    public function updatedEmpId(): void
    {
        $this->resetForm();
        $pertama = $this->pinjamanList->first();
        if ($pertama) {
            $this->pilihPinjaman((int) $pertama->pinjaman_id);
        }
    }

    public function pilihPinjaman(int $id): void
    {
        $row = PinjamanKaryawan::ambil($id, $this->periode());
        if (!$row || $row->emp_id !== $this->empId) {
            return;
        }
        $this->pinjamanId = $id;
        // bawaan: angsuran pertama yang belum terbayar (tunggakan terlama, atau bulan ini / berikutnya)
        $terbayar = PinjamanKaryawan::riwayatBayar($id)->pluck('periode')->flip();
        $keBelum = null;
        for ($n = 1; $n <= (int) $row->lama_bulan; $n++) {
            if (!isset($terbayar[PinjamanKaryawan::periodeKe((string) $row->bulan_awal, $n)])) {
                $keBelum = $n;
                break;
            }
        }
        $this->form['dari_ke'] = $keBelum ?? 1;
        $this->form['sampai_ke'] = $keBelum ?? 1;
        $this->form['jumlah'] = (float) $row->angsuran;
        $this->formVersi++;
        unset($this->pinjaman, $this->riwayatOrang);
    }

    public function simpan(): void
    {
        $pinjaman = $this->pinjaman;
        if (!$pinjaman) {
            $this->dispatch('toast', type: 'error', message: 'Pilih karyawan dan pinjamannya dulu.');
            return;
        }
        $dari = (int) $this->form['dari_ke'];
        $sampai = (int) $this->form['sampai_ke'];
        if ($dari < 1 || $sampai < $dari || $sampai > (int) $pinjaman->lama_bulan) {
            $this->dispatch('toast', type: 'error', message: "Angsuran ke- harus 1 s/d {$pinjaman->lama_bulan}, dan 'sampai' tidak kurang dari 'dari'.");
            return;
        }
        $tanggal = null;
        if (trim((string) $this->form['tanggal']) !== '') {
            try {
                $tanggal = Carbon::createFromFormat('!' . Format::TANGGAL, $this->form['tanggal'])->format('Y-m-d');
            } catch (\Throwable) {
                $this->dispatch('toast', type: 'error', message: 'Tanggal bayar tidak valid (dd/mm/yyyy).');
                return;
            }
        }
        $periodeList = [];
        for ($n = $dari; $n <= $sampai; $n++) {
            $periodeList[] = PinjamanKaryawan::periodeKe((string) $pinjaman->bulan_awal, $n);
        }
        $hasil = PinjamanKaryawan::catatBayar((int) $pinjaman->pinjaman_id, $periodeList, (string) $this->form['sumber'],
            is_numeric($this->form['jumlah']) ? (float) $this->form['jumlah'] : 0, $tanggal, (string) $this->form['keterangan'], auth()->id());
        $pesan = $hasil['dibuat'] > 0 ? "{$hasil['dibuat']} angsuran dicatat dibayar " . strtolower(PinjamanKaryawan::SUMBER_BAYAR[$this->form['sumber']] ?? '') . '.' : 'Tidak ada yang dicatat.';
        if ($hasil['dilewati'] !== []) {
            $pesan .= ' Dilewati: ' . implode(' ', $hasil['dilewati']);
        }
        $this->dispatch('toast', type: $hasil['dibuat'] > 0 ? 'success' : 'warning', message: $pesan . ($hasil['dibuat'] > 0 ? ' Bila slip bulan itu sudah berisi angsurannya, klik Terapkan ke Slip di tab Buku.' : ''));
        $this->pilihPinjaman((int) $pinjaman->pinjaman_id);
        unset($this->luarGaji, $this->riwayatOrang, $this->pinjamanList);
    }

    public function hapusBayar(int $bayarId): void
    {
        $galat = PinjamanKaryawan::hapusBayar($bayarId);
        $this->dispatch('toast', type: $galat ? 'error' : 'success', message: $galat ?? 'Pembayaran dihapus — angsuran itu kembali ditagih lewat gaji bila masih berjalan.');
        unset($this->riwayatOrang, $this->luarGaji, $this->pinjaman, $this->pinjamanList);
    }

    public function keBuku(): void
    {
        $this->dispatch('keuangan.pinjaman.batal');
    }

    // ─── Data ─────────────────────────────────────────────────────────────────
    #[Computed]
    public function karyawanOpsi(): array
    {
        return PinjamanKaryawan::daftar($this->periode(), null, 'semua')
            ->filter(fn($row) => $row->emp_aktif === '1')
            ->unique('emp_id')->values()
            ->map(fn($row) => ['id' => $row->emp_id, 'nama' => $row->emp_name, 'kelas' => $row->emp_id . ($row->unit_name ? ' · ' . $row->unit_name : '')])
            ->all();
    }

    /** Pinjaman karyawan terpilih yang masih bisa dibayar (berjalan / belum mulai) — yang sudah lunas tidak ditawarkan. */
    #[Computed]
    public function pinjamanList()
    {
        if ($this->empId === '') {
            return collect();
        }

        return PinjamanKaryawan::daftar($this->periode(), null, 'berjalan')->where('emp_id', $this->empId)
            ->sortBy(fn($row) => ($row->status === 'berjalan' ? '0' : '1') . $row->bulan_awal)->values();
    }

    #[Computed]
    public function pinjaman(): ?object
    {
        return $this->pinjamanId ? PinjamanKaryawan::ambil($this->pinjamanId, $this->periode()) : null;
    }

    /** Riwayat semua pinjaman karyawan terpilih + sisa (kurang berapa). */
    #[Computed]
    public function riwayatOrang()
    {
        return $this->empId !== '' ? PinjamanKaryawan::riwayatKaryawan($this->empId, $this->periode()) : collect();
    }

    #[Computed]
    public function luarGaji()
    {
        return PinjamanKaryawan::pembayaranLuarGaji(50);
    }
};
?>

<div>
    @php
        $rp = fn($n) => GajiKaryawan::rupiah($n);
        $lbl = fn($p) => PinjamanKaryawan::labelPeriode((string) $p);
        $pinjaman = $this->pinjaman;
        $jumlahKe = max(0, (int) ($form['sampai_ke'] ?? 0) - (int) ($form['dari_ke'] ?? 0) + 1);
        $total = (is_numeric($form['jumlah'] ?? null) ? (float) $form['jumlah'] : 0) * $jumlahKe;
    @endphp
    <div class="flex flex-col gap-4 mt-4">
        {{-- FORM PEMBAYARAN --}}
        <div class="flex flex-col w-full bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
            <div class="px-4 pt-3">
                <h2 class="font-semibold text-ink dark:text-gray-100">Catat pembayaran di luar gaji</h2>
                <p class="text-xs text-muted">Tunai / transfer untuk satu atau beberapa angsuran sekaligus (dobel, di muka). Angsuran yang tercatat di sini <b>tidak dipotong</b> di slip. Potongan lewat gaji tidak perlu dicatat — otomatis saat Terapkan ke Slip.</p>
            </div>
            <div class="grid grid-cols-1 gap-4 px-4 py-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="sm:col-span-2 xl:col-span-4">
                    <x-input-label for="pb-emp" value="Karyawan" />
                    <div class="mt-1">
                        <x-combobox wireModel="empNama" wireModelId="empId" :options="$this->karyawanOpsi" inputId="pb-emp" placeholder="Ketik nama / ID peminjam, lalu pilih dari daftar…" judulDaftar="daftar peminjam" maxlength="100" />
                    </div>
                    @if ($empId !== '')
                        <div class="mt-2">
                            @if ($this->pinjamanList->isNotEmpty())
                                <x-tabs variant="chip" aria-label="Pilih pinjaman yang dibayar">
                                    @foreach ($this->pinjamanList as $row)
                                        <x-tab :active="$pinjamanId === (int) $row->pinjaman_id" wire:click="pilihPinjaman({{ $row->pinjaman_id }})" wire:key="chip-{{ $row->pinjaman_id }}">
                                            {{ $row->sumber_nama }} · {{ $rp($row->pokok) }} · mulai {{ $lbl($row->bulan_awal) }}{{ $row->status === 'belum' ? ' · belum mulai' : '' }}
                                        </x-tab>
                                    @endforeach
                                </x-tabs>
                            @else
                                <span class="text-xs text-muted">Tidak ada pinjaman yang masih berjalan untuk karyawan ini. Riwayat pinjaman lunas tetap tampil di bawah.</span>
                            @endif
                        </div>
                    @elseif ($empNama !== '')
                        <p class="mt-1 text-xs text-warning-deep dark:text-warning">Nama belum cocok dengan daftar — pilih dari daftar.</p>
                    @endif
                </div>

                @if ($pinjaman)
                    <div class="p-3 text-sm border rounded-xl border-hairline bg-surface-soft sm:col-span-2 xl:col-span-4 dark:bg-gray-800 dark:border-gray-700" wire:key="ringkas-{{ $pinjaman->pinjaman_id }}">
                        <span class="font-semibold">{{ $pinjaman->sumber_nama }}</span> · pokok {{ $rp($pinjaman->pokok) }} · {{ $pinjaman->lama_bulan }} bln · angsuran <b class="font-mono">{{ $rp($pinjaman->angsuran) }}</b>/bln ·
                        terbayar <b>{{ $pinjaman->terbayar }}/{{ $pinjaman->lama_bulan }}</b>
                        @if ($pinjaman->tunggakan !== [])
                            · <span class="font-semibold text-danger-deep dark:text-danger">tunggakan {{ count($pinjaman->tunggakan) }} ({{ implode(', ', array_map($lbl, $pinjaman->tunggakan)) }})</span>
                        @endif
                        · posisi {{ $bulan }}: ke-{{ $pinjaman->ke }}
                    </div>
                    <div wire:key="form-bayar-{{ $formVersi }}" class="contents">
                        <div>
                            <x-input-label for="pb-dari" value="Angsuran ke- (dari)" />
                            <x-text-input-number id="pb-dari" wire:model="form.dari_ke" class="w-full mt-1" />
                            <p class="mt-1 text-xs text-muted">= bulan kerja {{ $lbl(PinjamanKaryawan::periodeKe($pinjaman->bulan_awal, max(1, (int) ($form['dari_ke'] ?: 1)))) }}</p>
                        </div>
                        <div>
                            <x-input-label for="pb-sampai" value="sampai ke-" />
                            <x-text-input-number id="pb-sampai" wire:model="form.sampai_ke" class="w-full mt-1" />
                            <p class="mt-1 text-xs text-muted">Sama dengan "dari" = satu angsuran. Lebih = bayar dobel / di muka.</p>
                        </div>
                        <div>
                            <x-input-label for="pb-sumber" value="Cara bayar" />
                            <x-select-input id="pb-sumber" wire:model="form.sumber" class="w-full mt-1">
                                @foreach (PinjamanKaryawan::SUMBER_BAYAR as $kode => $label)
                                    @if ($kode !== 'GAJI')<option value="{{ $kode }}">{{ $label }}</option>@endif
                                @endforeach
                            </x-select-input>
                        </div>
                        <div>
                            <x-input-label for="pb-tgl" value="Tanggal bayar" />
                            <x-text-input id="pb-tgl" wire:model.blur="form.tanggal" x-mask="99/99/9999" placeholder="dd/mm/yyyy" class="w-full mt-1 font-mono" />
                        </div>
                        <div>
                            <x-input-label for="pb-jumlah" value="Jumlah per angsuran (Rp)" />
                            <x-text-input-number id="pb-jumlah" wire:model="form.jumlah" class="w-full mt-1" />
                        </div>
                        <div class="sm:col-span-2 xl:col-span-3">
                            <x-input-label for="pb-ket" value="Keterangan" />
                            <x-text-input id="pb-ket" wire:model="form.keterangan" maxlength="200" placeholder="mis. bayar 2 bulan sekaligus" class="w-full mt-1" />
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2 sm:col-span-2 xl:col-span-4" role="status" aria-live="polite">
                        <span class="text-sm">{{ $jumlahKe }} angsuran × {{ $rp(is_numeric($form['jumlah'] ?? null) ? (float) $form['jumlah'] : 0) }} = <b class="font-mono">{{ $rp($total) }}</b></span>
                        <div class="flex gap-2">
                            <x-secondary-button type="button" wire:click="keBuku">Ke Buku Pinjaman</x-secondary-button>
                            <x-primary-button type="button" wire:click="simpan" wire:loading.attr="disabled" wire:target="simpan">
                                <span wire:loading.remove wire:target="simpan">Catat Pembayaran</span>
                                <span wire:loading wire:target="simpan">Menyimpan...</span>
                            </x-primary-button>
                        </div>
                    </div>
                @endif
            </div>

        </div>

        {{-- RIWAYAT: per orang bila karyawan dipilih, selain itu pembayaran luar gaji terbaru --}}
        <div class="flex flex-col bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
            @if ($empId !== '')
                @php $riwayatOrang = $this->riwayatOrang; $totalSisa = $riwayatOrang->sum('sisa_nominal'); @endphp
                <div class="px-4 pt-3 pb-4" wire:key="riwayat-orang-{{ $empId }}">
                    <div class="flex flex-wrap items-end justify-between gap-2">
                        <h3 class="text-sm font-semibold text-ink dark:text-gray-100">Riwayat pembayaran {{ $empNama }} <span class="font-normal text-muted">— potong gaji &amp; luar gaji</span></h3>
                        <span class="text-sm">Kurang: <b class="font-mono">{{ $rp($totalSisa) }}</b> ({{ $riwayatOrang->sum('sisa_angsuran') }} angsuran, {{ $riwayatOrang->where('sisa_angsuran', '>', 0)->count() }} pinjaman)</span>
                    </div>
                    <div class="grid grid-cols-1 gap-3 mt-3 lg:grid-cols-2 xl:grid-cols-3">
                    @forelse ($riwayatOrang as $pj)
                        <div class="flex flex-col border rounded-xl border-hairline dark:border-gray-700 {{ $pinjamanId === (int) $pj->pinjaman_id ? 'ring-2 ring-brand-green/40 dark:ring-brand-lime/40' : '' }}" wire:key="riwayat-pj-{{ $pj->pinjaman_id }}">
                            <div class="px-3 py-2 text-sm bg-surface-soft rounded-t-xl dark:bg-gray-800">
                                <div class="flex flex-wrap items-center justify-between gap-1">
                                    <span class="font-semibold">{{ $pj->sumber_nama }}</span>
                                    <x-badge :variant="['belum' => 'gray', 'berjalan' => 'success', 'lunas' => 'info', 'dilunasi' => 'info'][$pj->status] ?? 'gray'">{{ ['belum' => 'Belum mulai', 'berjalan' => 'Berjalan', 'lunas' => 'Lunas', 'dilunasi' => 'Dilunasi ' . $lbl($pj->lunas_periode)][$pj->status] ?? $pj->status }}</x-badge>
                                </div>
                                <div class="text-xs text-muted">pokok {{ $rp($pj->pokok) }} · {{ $pj->lama_bulan }} bln · mulai {{ $lbl($pj->bulan_awal) }} · angsuran <span class="font-mono">{{ $rp($pj->angsuran) }}</span></div>
                                <div class="mt-1 font-mono text-xs">
                                    terbayar <b>{{ $pj->terbayar }}/{{ $pj->lama_bulan }}</b> = {{ $rp($pj->terbayar_nominal) }}
                                    @if ($pj->sisa_angsuran > 0)<span class="block font-semibold text-danger-deep dark:text-danger">kurang {{ $pj->sisa_angsuran }} × {{ $rp($pj->angsuran) }} = {{ $rp($pj->sisa_nominal) }}</span>@else<span class="block text-success-deep dark:text-success">tidak ada kekurangan</span>@endif
                                    @if ($pj->tunggakan !== [])<span class="block text-danger-deep dark:text-danger">tunggakan {{ implode(', ', array_map($lbl, $pj->tunggakan)) }}</span>@endif
                                </div>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="ds-table">
                                    <thead><tr><th>Angsuran</th><th>Pembayaran</th><th class="text-right">Jumlah</th><th></th></tr></thead>
                                    <tbody>
                                        @forelse ($pj->riwayat as $b)
                                            @php $detail = $b->sumber === 'GAJI' ? ($b->dipotong_periode && $b->dipotong_periode !== $b->periode ? 'slip ' . $lbl($b->dipotong_periode) : '') : ($b->tanggal_bayar ? Format::tanggal($b->tanggal_bayar) : ''); $ket = $b->keterangan === 'riwayat sebelum SIAP' ? 'sebelum SIAP' : $b->keterangan; @endphp
                                            <tr wire:key="bayar-{{ $b->bayar_id }}">
                                                <td class="whitespace-nowrap"><b class="font-mono">ke-{{ $b->ke }}</b> <span class="font-mono text-xs text-muted">{{ $lbl($b->periode) }}</span></td>
                                                <td>
                                                    <x-badge :variant="$b->sumber === 'GAJI' ? 'info' : 'success'">{{ PinjamanKaryawan::SUMBER_BAYAR[$b->sumber] ?? $b->sumber }}</x-badge>
                                                    @if ($detail !== '' || $ket)<span class="ml-1 text-[11px] text-muted">{{ trim($detail . ($ket ? ($detail !== '' ? ' · ' : '') . $ket : '')) }}</span>@endif
                                                </td>
                                                <td class="font-mono text-right text-xs whitespace-nowrap">{{ $rp($b->jumlah) }}</td>
                                                <td class="text-right">
                                                    <x-confirm-button variant="danger-soft" action="hapusBayar({{ $b->bayar_id }})" title="Hapus pembayaran" class="!px-1.5 !py-0.5 text-[11px]"
                                                        message="Hapus catatan pembayaran angsuran ke-{{ $b->ke }} ({{ $lbl($b->periode) }})? Angsuran itu kembali ditagih lewat gaji bila masih berjalan." confirmText="Ya, hapus" cancelText="Batal">✕</x-confirm-button>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="4" class="px-4 py-4 text-center text-muted">Belum ada pembayaran tercatat.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @empty
                        <p class="mt-2 text-sm text-muted">Belum ada pinjaman.</p>
                    @endforelse
                    </div>
                </div>
            @else
                <div class="px-4 pt-3">
                    <h3 class="text-sm font-semibold text-ink dark:text-gray-100">Pembayaran luar gaji terbaru</h3>
                    <p class="text-xs text-muted">50 catatan terakhir, semua pinjaman. Pilih karyawan di atas untuk melihat riwayat lengkap per orang (potong gaji &amp; luar gaji) dan sisa kurangnya.</p>
                </div>
            <div class="overflow-x-auto">
                <table class="ds-table">
                    <thead><tr><th>Karyawan</th><th>Angsuran</th><th class="text-right">Jumlah</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($this->luarGaji as $b)
                            <tr wire:key="luar-{{ $b->bayar_id }}">
                                <td><span class="font-semibold">{{ $b->emp_name }}</span><span class="block text-xs text-muted">{{ $b->sumber_nama }} · {{ $b->tanggal_bayar ? Format::tanggal($b->tanggal_bayar) : '–' }}</span></td>
                                <td class="font-mono text-xs">ke-{{ $b->ke }}/{{ $b->lama_bulan }} · {{ $lbl($b->periode) }}<span class="block font-sans text-muted">{{ PinjamanKaryawan::SUMBER_BAYAR[$b->sumber] ?? $b->sumber }}{{ $b->keterangan ? ' · ' . $b->keterangan : '' }}</span></td>
                                <td class="font-mono text-right">{{ $rp($b->jumlah) }}</td>
                                <td class="text-right">
                                    <x-confirm-button variant="danger-soft" action="hapusBayar({{ $b->bayar_id }})" title="Hapus pembayaran" class="!px-2 !py-1 text-xs"
                                        message="Hapus catatan pembayaran {{ $b->emp_name }} angsuran ke-{{ $b->ke }} ({{ $lbl($b->periode) }})?" confirmText="Ya, hapus" cancelText="Batal">Hapus</x-confirm-button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-muted">Belum ada pembayaran luar gaji.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </div>
</div>
