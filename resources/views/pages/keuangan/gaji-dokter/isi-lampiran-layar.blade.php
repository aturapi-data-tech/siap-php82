{{-- Badan modal Lampiran Rincian Pasien (gaji dokter SIRUS) untuk TAMPILAN LAYAR — dipisah dari komponennya supaya berkas
     komponen tetap urusan data & aksi, yang di sini murni penyajian (pola isi-lampiran-layar di SIRUS).
     Butuh: $dataLampiran (koleksi entri per snapshot: header, baris, perKomponen, angka, versiOpsi), $komponenTerbuka,
     $batasLipat, $modeSemua. --}}
@php
    $rupiah = fn($nilai) => number_format((float) $nilai, 0, ',', '.');
    $namaPeriode = fn($periode) => $periode && preg_match('/^(\d{4})-(\d{2})$/', $periode, $bagianPeriode)
        ? ((['01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April', '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
            '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'][$bagianPeriode[2]] ?? $bagianPeriode[2]) . ' ' . $bagianPeriode[1]) : (string) $periode;
@endphp

@forelse ($dataLampiran as $snapshot)
    @php $header = $snapshot['header']; $angkaRekonsiliasi = $snapshot['angka']; @endphp

    <div class="mb-4 overflow-hidden border bg-canvas border-hairline rounded-xl dark:bg-gray-900 dark:border-gray-700" wire:key="lamp-dok-{{ $header->gd_id }}">

        {{-- IDENTITAS DOKTER --}}
        <div class="px-4 py-3 border-b border-hairline bg-surface-card dark:bg-gray-800 dark:border-gray-700">
            <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
                <h3 class="flex items-center gap-2 text-base font-bold text-ink dark:text-gray-100">
                    {{ $header->dr_name }}
                    <x-badge variant="{{ $header->terbaru === '1' ? 'brand' : 'gray' }}">Versi {{ $header->versi }}{{ $header->terbaru === '1' ? ' · terbaru' : '' }}</x-badge>
                </h3>
                <div class="flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-muted dark:text-gray-400">
                    <span>Periode Jasa: <span class="font-medium text-body dark:text-gray-200">{{ $namaPeriode($header->periode) }}</span></span>
                    <span>Dibayarkan: <span class="font-medium text-body dark:text-gray-200">{{ $namaPeriode($header->periode_gaji) }}</span></span>
                    <span>Jumlah Baris: <span class="font-medium text-body dark:text-gray-200">{{ $snapshot['lampiran']->count() }} transaksi</span></span>
                    <span>Diambil: <span class="font-medium text-body dark:text-gray-200">{{ \Carbon\Carbon::parse($header->diambil_at)->format('d/m/Y H:i') }}</span></span>
                    {{-- Pemilih versi hanya mode satu dokter (khas SIAP: data snapshot berversi). --}}
                    @if (!$modeSemua && $snapshot['versiOpsi']->count() > 1)
                        <label class="flex items-center gap-2">
                            <span class="sr-only">Versi snapshot</span>
                            <select wire:change="gantiVersiSnapshot($event.target.value)" aria-label="Versi snapshot"
                                class="py-1 pl-2 text-xs border rounded-lg pr-7 bg-gray-50 border-gray-300 text-body focus:border-brand-green focus:ring-brand-green dark:bg-gray-800 dark:border-gray-600 dark:text-gray-200">
                                @foreach ($snapshot['versiOpsi'] as $versi)
                                    <option value="{{ $versi->gd_id }}" @selected((int) $versi->gd_id === (int) $header->gd_id)>Versi {{ $versi->versi }}{{ $versi->terbaru === '1' ? ' (terbaru)' : '' }} · {{ \Carbon\Carbon::parse($versi->diambil_at)->format('d/m/Y H:i') }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                </div>
            </div>
        </div>

        {{-- Catatan tanggal WAJIB: tanpa ini baris rawat inap bertanggal bulan lain terbaca salah periode. --}}
        <div class="px-4 py-2 text-xs border-b border-hairline bg-info-tint text-info-deep dark:bg-blue-900/20 dark:text-blue-200 dark:border-gray-700">
            Tanggal di bawah adalah <strong>tanggal layanan</strong> (visite, konsultasi, tindakan, operasi, atau kunjungan). Jasa rawat inap masuk periode gaji saat pasien
            <strong>pulang</strong>, sehingga baris rawat inap dapat bertanggal bulan sebelum periode jasa di atas.
        </div>

        {{-- TABEL PER KOMPONEN --}}
        @foreach ($snapshot['perKomponen'] as $kode => $rowsKomponen)
            @php
                $labelKomponen = $rowsKomponen->first()->label ?: $kode;
                $dasarKapita = $rowsKomponen->first()->masuk_slip !== '1';
                $subtotal = (float) $rowsKomponen->sum('nominal');
                $jumlahPasien = (int) $rowsKomponen->sum('pasien');
                $kunciKomponen = $header->gd_id . '|' . $kode;
                $komponenBesar = $rowsKomponen->count() > $batasLipat;
                $dilipat = $komponenBesar && !in_array($kunciKomponen, $komponenTerbuka, true);
            @endphp

            <div class="border-b border-hairline dark:border-gray-700" wire:key="komp-{{ $header->gd_id }}-{{ $kode }}">
                <div class="flex flex-wrap items-baseline justify-between px-4 py-2 gap-x-4 bg-surface-soft dark:bg-gray-800/60">
                    <div class="text-sm font-semibold text-ink dark:text-gray-100">
                        {{ $labelKomponen }}
                        @if ($dasarKapita)<span class="ml-1 px-1.5 py-0.5 text-xs font-medium rounded bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">dasar hitung per kapita</span>@endif
                    </div>
                    <div class="flex items-center gap-3 text-xs text-muted dark:text-gray-400">
                        <span>{{ $jumlahPasien }} pasien &middot; <span class="font-semibold tabular-nums text-body dark:text-gray-200">{{ $rupiah($subtotal) }}</span></span>
                        @if ($komponenBesar)
                            <x-secondary-button type="button" wire:click="toggleLipatKomponen('{{ $kunciKomponen }}')" wire:loading.attr="disabled" wire:target="toggleLipatKomponen('{{ $kunciKomponen }}')" class="!py-1 !px-2.5 text-xs whitespace-nowrap">
                                <span wire:loading.remove wire:target="toggleLipatKomponen('{{ $kunciKomponen }}')">{{ $dilipat ? 'Tampilkan ' . $rowsKomponen->count() . ' baris' : 'Sembunyikan baris' }}</span>
                                <span wire:loading wire:target="toggleLipatKomponen('{{ $kunciKomponen }}')" class="inline-flex items-center gap-1.5"><x-loading class="w-4 h-4" /> Memuat...</span>
                            </x-secondary-button>
                        @endif
                    </div>
                </div>

                @if ($dilipat)
                    <div class="px-4 py-2 text-xs italic text-muted dark:text-gray-400">
                        {{ $rowsKomponen->count() }} baris dilipat supaya lampiran cepat dibuka &mdash; subtotal di atas sudah menghitung semuanya. Unduh Excel tetap memuat seluruh baris.
                    </div>
                @else
                    @if ($dasarKapita)
                        <div class="px-4 py-1.5 text-xs border-b border-hairline bg-amber-50 text-amber-900 dark:bg-amber-900/20 dark:text-amber-200 dark:border-gray-700">
                            Dokter ini dibayar per kepala. Nominal di bawah adalah tarif komponen dan <strong>tidak masuk slip</strong>; yang dipakai hanya jumlah pasiennya.
                        </div>
                    @endif

                    {{-- table-fixed: lebar kolom dipatuhi, isi berlebih dipotong (tooltip); min-w supaya di layar sempit menggulir, bukan menggencet kolom nama. --}}
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[64rem] text-sm text-left table-fixed text-body dark:text-gray-300">
                            <thead class="text-xs uppercase text-muted bg-canvas dark:bg-gray-900 dark:text-gray-400">
                                <tr>
                                    <th class="w-12 px-2 py-2 text-right">No</th>
                                    <th class="w-24 px-2 py-2">Tanggal</th>
                                    <th class="w-24 px-2 py-2">No. RM</th>
                                    <th class="px-3 py-2">Nama Pasien</th>
                                    <th class="w-40 px-2 py-2">Klaim</th>
                                    <th class="px-2 py-2 w-52">No. SEP</th>
                                    <th class="w-24 px-2 py-2">No. Transaksi</th>
                                    <th class="w-32 px-3 py-2 text-right">Nominal (Rp)</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rowsKomponen as $row)
                                    <tr wire:key="lamp-{{ $row->lamp_id }}" class="border-t border-hairline hover:bg-surface-soft dark:border-gray-700 dark:hover:bg-gray-800/50">
                                        <td class="px-2 py-1.5 text-right tabular-nums text-muted dark:text-gray-400">{{ $loop->iteration }}</td>
                                        <td class="px-2 py-1.5 whitespace-nowrap tabular-nums">{{ $row->tgl }}</td>
                                        <td class="px-2 py-1.5 whitespace-nowrap tabular-nums">{{ $row->reg_no ?: '—' }}</td>
                                        {{-- Keterangan (nama jasa/tindakan) ditumpuk di bawah nama supaya kolom nama tidak menyempit. --}}
                                        <td class="px-3 py-1.5 text-ink dark:text-gray-100" title="{{ $row->nama }}{{ $row->keterangan ? ' — ' . $row->keterangan : '' }}">
                                            <span class="block truncate">{{ $row->nama ?: '—' }}</span>
                                            @if ($row->keterangan)<span class="block text-xs truncate text-muted dark:text-gray-400">{{ $row->keterangan }}</span>@endif
                                        </td>
                                        <td class="px-2 py-1.5 truncate" title="{{ $row->klaim }}">
                                            {{ $row->klaim ?: '—' }}
                                            @if ($row->klaim_status === 'BPJS')
                                                <span class="ml-1 px-1 py-0.5 text-xs font-medium rounded bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">BPJS</span>
                                            @elseif ($row->klaim_status && $row->klaim_status !== $row->klaim)
                                                <span class="ml-1 text-xs text-muted dark:text-gray-400">{{ $row->klaim_status }}</span>
                                            @endif
                                        </td>
                                        {{-- SEP kosong itu WAJAR: pasien umum tidak punya. --}}
                                        <td class="px-2 py-1.5 truncate tabular-nums text-muted dark:text-gray-400" title="{{ $row->no_sep }}">{{ $row->no_sep ?: '—' }}</td>
                                        <td class="px-2 py-1.5 whitespace-nowrap tabular-nums text-muted dark:text-gray-400">{{ $row->txn_no }}</td>
                                        <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap">{{ $rupiah($row->nominal) }}</td>
                                    </tr>
                                @endforeach
                                <tr class="font-semibold border-t-2 border-gray-400 bg-surface-soft dark:border-gray-600 dark:bg-gray-800/60">
                                    <td colspan="7" class="px-3 py-2 text-right">Subtotal {{ $labelKomponen }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums whitespace-nowrap">{{ $rupiah($subtotal) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endforeach

        {{-- REKONSILIASI — lampiran = transaksi; slip = angka snapshot. Selisih tampil apa adanya. --}}
        <div class="px-4 py-3 bg-surface-card dark:bg-gray-800">
            <div class="mb-1.5 text-xs font-semibold tracking-wider uppercase text-muted dark:text-gray-400">Rekonsiliasi dengan Slip</div>
            <table class="w-full text-sm text-body dark:text-gray-300">
                <tr>
                    <td class="py-1">Total nominal transaksi pada lampiran</td>
                    <td class="w-40 py-1 text-right tabular-nums">{{ $rupiah($angkaRekonsiliasi['totalTransaksi']) }}</td>
                </tr>
                @if ($angkaRekonsiliasi['kapitaSlip'] != 0.0 || $angkaRekonsiliasi['totalDasarKapita'] != 0.0)
                    <tr>
                        <td class="py-1">Jasa per kapita pada slip <span class="text-xs text-muted dark:text-gray-400">(dasar hitung {{ $rupiah($angkaRekonsiliasi['totalDasarKapita']) }} tidak dijumlahkan)</span></td>
                        <td class="py-1 text-right tabular-nums">{{ $rupiah($angkaRekonsiliasi['kapitaSlip']) }}</td>
                    </tr>
                @endif
                <tr class="font-bold border-t border-gray-400 text-ink dark:border-gray-600 dark:text-gray-100">
                    <td class="py-1.5">Jasa pada Slip Gaji <span class="text-xs font-normal text-muted dark:text-gray-400">(versi {{ $header->versi }})</span></td>
                    <td class="py-1.5 text-right tabular-nums">{{ $rupiah($angkaRekonsiliasi['jasaSlip']) }}</td>
                </tr>
                @if (round($angkaRekonsiliasi['selisih'], 0) != 0.0)
                    <tr class="text-amber-800 dark:text-amber-300">
                        <td class="py-1">Selisih <span class="text-xs">&mdash; dari baris jasa yang ditambahkan manual pada slip, atau dari transaksi yang berubah setelah slip dibuat.</span></td>
                        <td class="py-1 font-bold text-right tabular-nums">{{ $rupiah($angkaRekonsiliasi['selisih']) }}</td>
                    </tr>
                @endif
                <tr class="text-muted dark:text-gray-400">
                    <td class="py-1">Diterima dokter (setelah potongan &amp; tambahan)</td>
                    <td class="py-1 text-right tabular-nums">{{ $rupiah($header->gaji_diterima) }}</td>
                </tr>
            </table>
        </div>
    </div>
@empty
    <div class="p-6 text-sm text-center border border-dashed rounded-xl text-muted border-gray-300 dark:text-gray-400 dark:border-gray-700">Tidak ada baris lampiran pada snapshot ini.</div>
@endforelse
