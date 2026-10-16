<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\UniqueConstraintViolationException;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use App\Support\Perbaikan;
use App\Http\Traits\Concerns\WithRenderVersioningTrait;

/**
 * Modal perbaikan alat — satu form untuk seluruh alur (format "Laporan Perbaikan Alat Medis" RS):
 *   Laporan (alat, kerusakan, kondisi) → Penanganan (respon, teknisi, analisa, internal/vendor,
 *   service report, suku cadang, biaya) → Penyelesaian (selesai, diserahkan, penerima).
 * Isian wajib mengikuti status yang dipilih. Kondisi alat di inventaris ikut diperbarui:
 *   lapor → RR/RB · diserahkan kembali → B · tidak dapat diperbaiki → RB.
 * Bisa dibuka dari temuan P/R checklist pemeliharaan (aset.perbaikan-alat.openFromPemeliharaan).
 */
new class extends Component {
    use WithRenderVersioningTrait;

    private const FORMAT_WAKTU = 'd/m/Y H:i';

    public string $formMode = 'create'; // create|edit
    public ?int   $pbId     = null;
    public string $pbNo     = '';
    public ?int   $pmId     = null;
    public array  $renderVersions = [];
    protected array $renderAreas  = ['modal'];

    public array $form = [];

    private string $printView = 'pages.aset.perbaikan-alat.perbaikan-alat-print';

    public function mount(): void
    {
        $this->registerAreas(['modal']);
        $this->resetForm();
    }

    #[Computed]
    public function ruangs()
    {
        return DB::table('siapmst_ruangs')->where('active_status', '1')->orderBy('ruang_id')->get(['ruang_id', 'ruang_name']);
    }

    #[Computed]
    public function alatRuang()
    {
        if ($this->form['ruang_id'] === '') {
            return collect();
        }

        return DB::table('siapmst_assets')->where('ruang_id', $this->form['ruang_id'])->where('active_status', '1')
            ->orderBy('asset_name')->get(['asset_id', 'asset_name', 'merk', 'tipe_model', 'no_seri', 'kondisi', 'wajib_kalibrasi']);
    }

    #[Computed]
    public function alat(): ?object
    {
        return $this->form['asset_id'] === '' ? null
            : DB::table('siapmst_assets')->where('asset_id', $this->form['asset_id'])->first();
    }

    // ─── Open ─────────────────────────────────────────────────────────────────
    #[On('aset.perbaikan-alat.openCreate')]
    public function openCreate(?string $assetId = null, ?string $kerusakan = null, ?int $pmId = null): void
    {
        $this->resetForm();
        $this->formMode = 'create';
        $this->pbId = null;
        $this->pbNo = '';
        $this->pmId = $pmId;

        if ($assetId) {
            $alat = DB::table('siapmst_assets')->where('asset_id', $assetId)->first(['asset_id', 'ruang_id']);
            if ($alat) {
                if ($aktif = Perbaikan::aktifUntuk($alat->asset_id)) {
                    $this->dispatch('toast', type: 'info', message: "Alat ini sedang dalam perbaikan ({$aktif->pb_no}) — membuka data tersebut.");
                    $this->openEdit((int) $aktif->pb_id);
                    return;
                }
                $this->form['ruang_id'] = (string) $alat->ruang_id;
                $this->form['asset_id'] = (string) $alat->asset_id;
            }
        }
        if ($kerusakan) {
            $this->form['kerusakan'] = $kerusakan;
        }

        $this->bukaModal();
    }

    /** Dari temuan P/R checklist pemeliharaan. */
    #[On('aset.perbaikan-alat.openFromPemeliharaan')]
    public function openFromPemeliharaan(int $pmId): void
    {
        $pm = DB::table('siaptxn_pemeliharaans')->where('pm_id', $pmId)->first();
        if (!$pm) {
            return;
        }

        $kerusakan = 'Temuan checklist pemeliharaan ' . \App\Support\Format::tanggal($pm->pm_tanggal) . ': '
            . \App\Support\Pemeliharaan::HASIL[$pm->pm_hasil] . ($pm->keterangan ? ' — ' . $pm->keterangan : '');
        $this->openCreate($pm->asset_id, $kerusakan, $pmId);
        $this->form['kondisi_alat'] = $pm->pm_hasil === 'R' ? 'RB' : 'RR';
    }

    #[On('aset.perbaikan-alat.openEdit')]
    public function openEdit(int $pbId): void
    {
        $pb = DB::table('siaptxn_perbaikans')->where('pb_id', $pbId)->first();
        if (!$pb) {
            $this->dispatch('toast', type: 'error', message: 'Data perbaikan tidak ditemukan.');
            return;
        }

        $this->resetForm();
        $this->formMode = 'edit';
        $this->pbId = (int) $pb->pb_id;
        $this->pbNo = (string) $pb->pb_no;
        $this->pmId = $pb->pm_id ? (int) $pb->pm_id : null;
        $this->form = [
            'ruang_id'          => (string) $pb->ruang_id,
            'asset_id'          => (string) $pb->asset_id,
            'tgl_permintaan'    => $this->fmt($pb->tgl_permintaan),
            'pelapor'           => (string) ($pb->pelapor ?? ''),
            'kerusakan'         => (string) $pb->kerusakan,
            'kondisi_alat'      => 'RR',
            'tgl_respon'        => $this->fmt($pb->tgl_respon),
            'teknisi'           => (string) ($pb->teknisi ?? ''),
            'analisa'           => (string) ($pb->analisa ?? ''),
            'pb_pelaksana'      => (string) ($pb->pb_pelaksana ?? 'I'),
            'vendor'            => (string) ($pb->vendor ?? ''),
            'no_service_report' => (string) ($pb->no_service_report ?? ''),
            'suku_cadang'       => (string) ($pb->suku_cadang ?? ''),
            'biaya'             => (int) round((float) $pb->biaya),
            'tgl_selesai'       => $this->fmt($pb->tgl_selesai),
            'tgl_diserahkan'    => $this->fmt($pb->tgl_diserahkan),
            'penerima'          => (string) ($pb->penerima ?? ''),
            'keterangan'        => (string) ($pb->keterangan ?? ''),
            'pb_status'         => (string) $pb->pb_status,
        ];

        $this->bukaModal();
    }

    private function bukaModal(): void
    {
        unset($this->alatRuang, $this->alat);
        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'perbaikan-alat-actions');
    }

    public function updatedFormRuangId(): void
    {
        $this->form['asset_id'] = '';
        unset($this->alatRuang, $this->alat);
    }

    public function updatedFormAssetId(): void
    {
        unset($this->alat);
    }

    /** Isi field waktu dengan sekarang (tombol "Sekarang"). */
    public function isiSekarang(string $field): void
    {
        if (in_array($field, ['tgl_permintaan', 'tgl_respon', 'tgl_selesai', 'tgl_diserahkan'], true)) {
            $this->form[$field] = now()->format(self::FORMAT_WAKTU);
        }
    }

    public function setStatus(string $status): void
    {
        if (isset(Perbaikan::STATUS[$status])) {
            $this->form['pb_status'] = $status;
            if ($status === 'V') {
                $this->form['pb_pelaksana'] = 'V';
            }
            $this->resetValidation();
        }
    }

    // ─── Validasi per status ──────────────────────────────────────────────────
    protected function rules(): array
    {
        $st = $this->form['pb_status'];
        $sudah = fn(array $daftar) => in_array($st, $daftar, true);
        $waktu = 'date_format:' . self::FORMAT_WAKTU;

        return [
            'form.ruang_id'          => 'required|exists:siapmst_ruangs,ruang_id',
            'form.asset_id'          => 'required|exists:siapmst_assets,asset_id',
            'form.tgl_permintaan'    => "required|{$waktu}",
            'form.pelapor'           => 'required|string|max:100',
            'form.kerusakan'         => 'required|string|max:500',
            'form.kondisi_alat'      => 'required|in:RR,RB',
            'form.pb_status'         => 'required|in:' . implode(',', array_keys(Perbaikan::STATUS)),
            'form.tgl_respon'        => ($sudah(['P', 'V', 'S', 'K', 'X']) ? 'required' : 'nullable') . "|{$waktu}",
            'form.teknisi'           => ($sudah(['P', 'V', 'S', 'K', 'X']) ? 'required' : 'nullable') . '|string|max:100',
            'form.analisa'           => ($sudah(['S', 'K', 'X']) ? 'required' : 'nullable') . '|string|max:500',
            'form.pb_pelaksana'      => 'nullable|in:I,V',
            'form.vendor'            => ($sudah(['V']) || ($this->form['pb_pelaksana'] === 'V' && $sudah(['S', 'K'])) ? 'required' : 'nullable') . '|string|max:100',
            'form.no_service_report' => 'nullable|string|max:60',
            'form.suku_cadang'       => 'nullable|string|max:500',
            'form.biaya'             => 'nullable|numeric|min:0|max:999999999999',
            'form.tgl_selesai'       => ($sudah(['S', 'K']) ? 'required' : 'nullable') . "|{$waktu}",
            'form.tgl_diserahkan'    => ($sudah(['K']) ? 'required' : 'nullable') . "|{$waktu}",
            'form.penerima'          => ($sudah(['K']) ? 'required' : 'nullable') . '|string|max:100',
            'form.keterangan'        => ($sudah(['X']) ? 'required' : 'nullable') . '|string|max:500',
        ];
    }

    protected function messages(): array
    {
        return [
            'required'    => ':attribute wajib diisi.',
            'max'         => ':attribute terlalu panjang.',
            'exists'      => ':attribute tidak ada di master.',
            'in'          => ':attribute tidak valid.',
            'numeric'     => ':attribute harus angka.',
            'min'         => ':attribute tidak boleh negatif.',
            'date_format' => ':attribute harus berformat dd/mm/yyyy hh:mi.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'form.ruang_id' => 'Ruang', 'form.asset_id' => 'Alat', 'form.tgl_permintaan' => 'Tgl Permintaan',
            'form.pelapor' => 'Pelapor', 'form.kerusakan' => 'Kerusakan', 'form.kondisi_alat' => 'Kondisi Alat',
            'form.tgl_respon' => 'Waktu Respon', 'form.teknisi' => 'Teknisi', 'form.analisa' => 'Analisa Kerusakan',
            'form.vendor' => 'Vendor', 'form.no_service_report' => 'No. Service Report', 'form.suku_cadang' => 'Suku Cadang',
            'form.biaya' => 'Biaya', 'form.tgl_selesai' => 'Tgl Selesai', 'form.tgl_diserahkan' => 'Tgl Diserahkan',
            'form.penerima' => 'Penerima', 'form.keterangan' => 'Keterangan',
        ];
    }

    // ─── Save ─────────────────────────────────────────────────────────────────
    public function save(): void
    {
        $this->validate();

        $f = $this->form;
        $t = fn(string $k) => $f[$k] !== '' ? Carbon::createFromFormat(self::FORMAT_WAKTU, $f[$k]) : null;
        $minta = $t('tgl_permintaan');

        // Urutan waktu harus masuk akal.
        foreach (['tgl_respon' => 'Waktu Respon', 'tgl_selesai' => 'Tgl Selesai', 'tgl_diserahkan' => 'Tgl Diserahkan'] as $k => $label) {
            if (($w = $t($k)) && $w->lt($minta)) {
                $this->addError("form.{$k}", "{$label} tidak boleh sebelum Tgl Permintaan.");
                return;
            }
        }
        if (($s = $t('tgl_selesai')) && ($d = $t('tgl_diserahkan')) && $d->lt($s)) {
            $this->addError('form.tgl_diserahkan', 'Tgl Diserahkan tidak boleh sebelum Tgl Selesai.');
            return;
        }
        if ($minta->gt(now()->addMinutes(5))) {
            $this->addError('form.tgl_permintaan', 'Tgl Permintaan tidak boleh di masa depan.');
            return;
        }

        $data = [
            'asset_id'          => $f['asset_id'],
            'ruang_id'          => $f['ruang_id'],
            'pb_status'         => $f['pb_status'],
            'tgl_permintaan'    => $minta,
            'pelapor'           => trim($f['pelapor']),
            'kerusakan'         => trim($f['kerusakan']),
            'tgl_respon'        => $t('tgl_respon'),
            'teknisi'           => $f['teknisi'] !== '' ? trim($f['teknisi']) : null,
            'analisa'           => $f['analisa'] !== '' ? trim($f['analisa']) : null,
            'pb_pelaksana'      => $f['tgl_respon'] !== '' ? $f['pb_pelaksana'] : null,
            'vendor'            => $f['vendor'] !== '' ? trim($f['vendor']) : null,
            'no_service_report' => $f['no_service_report'] !== '' ? mb_strtoupper(trim($f['no_service_report'])) : null,
            'suku_cadang'       => $f['suku_cadang'] !== '' ? trim($f['suku_cadang']) : null,
            'biaya'             => (float) ($f['biaya'] ?: 0),
            'tgl_selesai'       => $t('tgl_selesai'),
            'tgl_diserahkan'    => $t('tgl_diserahkan'),
            'penerima'          => $f['penerima'] !== '' ? trim($f['penerima']) : null,
            'keterangan'        => $f['keterangan'] !== '' ? trim($f['keterangan']) : null,
            'updated_by'        => auth()->id(),
            'updated_at'        => now(),
        ];

        DB::transaction(function () use ($data, $f, $minta) {
            if ($this->formMode === 'create') {
                // Nomor urut per bulan; bentrok bersamaan → coba nomor berikutnya.
                for ($coba = 0; $coba < 3; $coba++) {
                    try {
                        $this->pbNo = Perbaikan::nomorBaru($minta);
                        DB::table('siaptxn_perbaikans')->insert([...$data, 'pb_no' => $this->pbNo, 'pm_id' => $this->pmId, 'created_at' => now()]);
                        break;
                    } catch (UniqueConstraintViolationException $e) {
                        if ($coba === 2) {
                            throw $e;
                        }
                    }
                }
                $this->pbId = (int) DB::table('siaptxn_perbaikans')->where('pb_no', $this->pbNo)->value('pb_id');
                $kondisi = $f['kondisi_alat'];
            } else {
                DB::table('siaptxn_perbaikans')->where('pb_id', $this->pbId)->update($data);
                $kondisi = null;
            }

            // Kondisi alat di inventaris mengikuti perbaikan.
            $kondisi = match ($f['pb_status']) {
                'K' => 'B',
                'X' => 'RB',
                default => $kondisi,
            };
            if ($kondisi) {
                DB::table('siapmst_assets')->where('asset_id', $f['asset_id'])->update(['kondisi' => $kondisi, 'updated_at' => now()]);
            }
        });

        $this->dispatch('toast', type: 'success', message: "Perbaikan {$this->pbNo} tersimpan — " . Perbaikan::STATUS[$f['pb_status']][0] . '.');
        $this->dispatch('aset.perbaikan-alat.saved');
        $this->dispatch('master.alat.saved');
        $this->closeModal();
    }

    /** Hapus hanya untuk laporan yang belum ditangani (salah input). */
    public function hapus(): void
    {
        $pb = DB::table('siaptxn_perbaikans')->where('pb_id', $this->pbId)->first();
        if (!$pb || $pb->pb_status !== 'B') {
            $this->dispatch('toast', type: 'error', message: 'Hanya laporan yang belum ditangani yang bisa dihapus.');
            return;
        }

        DB::table('siaptxn_perbaikans')->where('pb_id', $this->pbId)->delete();
        $this->dispatch('toast', type: 'success', message: "Laporan {$pb->pb_no} dihapus.");
        $this->dispatch('aset.perbaikan-alat.saved');
        $this->closeModal();
    }

    public function cetak()
    {
        $pb = DB::table('siaptxn_perbaikans as pb')
            ->join('siapmst_assets as aa', 'aa.asset_id', '=', 'pb.asset_id')
            ->leftJoin('siapmst_ruangs as ru', 'ru.ruang_id', '=', 'pb.ruang_id')
            ->where('pb.pb_id', $this->pbId)
            ->first(['pb.*', 'aa.asset_name', 'aa.merk', 'aa.tipe_model', 'aa.no_seri', 'ru.ruang_name']);
        if (!$pb) {
            return null;
        }

        $pdf = Pdf::loadView($this->printView, ['pb' => $pb, 'dicetak' => now(), 'oleh' => auth()->user()?->name])->setPaper('A4');

        return response()->streamDownload(fn() => print $pdf->output(), 'laporan-perbaikan-' . str_replace('/', '-', $pb->pb_no) . '.pdf');
    }

    public function closeModal(): void
    {
        $this->resetForm();
        $this->dispatch('close-modal', name: 'perbaikan-alat-actions');
        $this->resetVersion();
    }

    private function resetForm(): void
    {
        $this->form = [
            'ruang_id' => '', 'asset_id' => '',
            'tgl_permintaan' => now()->format(self::FORMAT_WAKTU),
            'pelapor' => (string) (auth()->user()?->name ?? ''),
            'kerusakan' => '', 'kondisi_alat' => 'RR',
            'tgl_respon' => '', 'teknisi' => '', 'analisa' => '', 'pb_pelaksana' => 'I',
            'vendor' => '', 'no_service_report' => '', 'suku_cadang' => '', 'biaya' => 0,
            'tgl_selesai' => '', 'tgl_diserahkan' => '', 'penerima' => '', 'keterangan' => '',
            'pb_status' => 'B',
        ];
        $this->resetValidation();
    }

    private function fmt(?string $nilai): string
    {
        return $nilai ? Carbon::parse($nilai)->format(self::FORMAT_WAKTU) : '';
    }
};
?>

<div>
    <x-modal name="perbaikan-alat-actions" size="full" height="full" focusable>
        <x-dirty-modal-content
            name="perbaikan-alat-actions"
            event="aset.perbaikan-alat.saved"
            label="Perbaikan"
            :wireKey="$this->renderKey('modal', [$formMode, $pbId ?? 'baru'])">

            @php
                $alat = $this->alat;
                $st = $form['pb_status'];
                $tahap = fn(array $daftar) => in_array($st, $daftar, true);
            @endphp

            {{-- HEADER — standar modal modul dokumen (x-modul-dokumen.header, diport dari sirus) --}}
            <x-modul-dokumen.header :judul="($formMode === 'edit' ? 'Perbaikan ' . $pbNo : 'Lapor Kerusakan Alat')"
                ikon="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" tutupAlpine="tryClose()">
                Alur: lapor → teknisi respon (target ±{{ \App\Support\Perbaikan::TARGET_RESPON_MENIT }} menit) & analisa → vendor bila perlu → selesai → diserahkan kembali.
            </x-modul-dokumen.header>
            <div class="px-6 py-3 border-b shrink-0 bg-surface-soft border-hairline dark:border-gray-700">
                {{-- Tahapan status --}}
                        <div class="flex flex-wrap gap-1.5">
                            @foreach (\App\Support\Perbaikan::STATUS as $kode => [$nama, $varian])
                                <button type="button" wire:click="setStatus('{{ $kode }}')"
                                    class="px-3 py-1.5 text-xs font-semibold rounded-lg border transition
                                        {{ $st === $kode ? 'bg-brand-green text-white border-brand-green' : 'bg-canvas text-muted border-hairline hover:border-gray-400' }}">
                                    {{ $nama }}
                                </button>
                            @endforeach
                        </div>
            </div>

            {{-- BODY --}}
            <div class="flex-1 px-4 py-4 bg-surface-soft dark:bg-gray-950/20">
                <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">

                    {{-- 1. LAPORAN --}}
                    <x-border-form title="1. Laporan Kerusakan">
                        <div class="space-y-3">
                            <div>
                                <x-input-label value="Ruang *" />
                                <x-select-input wire:model.live="form.ruang_id" :disabled="$formMode === 'edit'" :error="$errors->has('form.ruang_id')" class="mt-1">
                                    <option value="">— pilih —</option>
                                    @foreach ($this->ruangs as $ru)
                                        <option value="{{ $ru->ruang_id }}">{{ $ru->ruang_id }} — {{ $ru->ruang_name }}</option>
                                    @endforeach
                                </x-select-input>
                                <x-input-error :messages="$errors->get('form.ruang_id')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label value="Alat *" />
                                <x-select-input wire:model.live="form.asset_id" :disabled="$formMode === 'edit' || $form['ruang_id'] === ''" :error="$errors->has('form.asset_id')" class="mt-1">
                                    <option value="">— pilih alat —</option>
                                    @foreach ($this->alatRuang as $a)
                                        <option value="{{ $a->asset_id }}">{{ $a->asset_name }} · {{ $a->asset_id }}</option>
                                    @endforeach
                                </x-select-input>
                                <x-input-error :messages="$errors->get('form.asset_id')" class="mt-1" />
                                @if ($alat)
                                    <p class="mt-1 text-xs text-muted">{{ $alat->merk }} {{ $alat->tipe_model }} · No. Seri <span class="font-mono">{{ $alat->no_seri ?: '-' }}</span> · kondisi sekarang {{ \App\Support\Kalibrasi::KONDISI[$alat->kondisi] ?? $alat->kondisi }}</p>
                                @endif
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <x-input-label value="Tgl Permintaan *" />
                                    <div class="flex gap-1 mt-1">
                                        <x-text-input wire:model="form.tgl_permintaan" x-mask="99/99/9999 99:99" placeholder="dd/mm/yyyy hh:mi"
                                            :error="$errors->has('form.tgl_permintaan')" class="w-full font-mono text-sm" />
                                    </div>
                                    <x-input-error :messages="$errors->get('form.tgl_permintaan')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label value="Pelapor (user) *" />
                                    <x-text-input wire:model="form.pelapor" maxlength="100" :error="$errors->has('form.pelapor')" class="w-full mt-1" />
                                    <x-input-error :messages="$errors->get('form.pelapor')" class="mt-1" />
                                </div>
                            </div>
                            <div>
                                <x-input-label value="Kerusakan (kapan, gejala, penyebab) *" />
                                <x-textarea wire:model="form.kerusakan" rows="3" maxlength="500" :error="$errors->has('form.kerusakan')" class="w-full mt-1" />
                                <x-input-error :messages="$errors->get('form.kerusakan')" class="mt-1" />
                            </div>
                            @if ($formMode === 'create')
                                <div>
                                    <x-input-label value="Kondisi alat saat ini *" />
                                    <div class="flex gap-2 mt-1">
                                        @foreach (['RR' => 'Rusak Ringan (masih bisa dipakai terbatas)', 'RB' => 'Rusak Berat (tidak dipakai)'] as $k => $n)
                                            <label class="flex items-center gap-2 px-3 py-2 text-sm border rounded-lg cursor-pointer {{ $form['kondisi_alat'] === $k ? 'border-brand-green bg-brand-green/10' : 'border-hairline' }}">
                                                <input type="radio" wire:model.live="form.kondisi_alat" value="{{ $k }}" class="text-brand-green" /> {{ $n }}
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            @if ($pmId)
                                <p class="text-xs text-muted">🔗 Berasal dari temuan checklist pemeliharaan.</p>
                            @endif
                        </div>
                    </x-border-form>

                    {{-- 2. PENANGANAN --}}
                    <x-border-form title="2. Penanganan">
                        <div class="space-y-3 {{ $tahap(['B']) ? 'opacity-60' : '' }}">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <x-input-label value="Waktu Respon{{ $tahap(['P', 'V', 'S', 'K', 'X']) ? ' *' : '' }}" />
                                    <div class="flex gap-1 mt-1">
                                        <x-text-input wire:model="form.tgl_respon" x-mask="99/99/9999 99:99" placeholder="dd/mm/yyyy hh:mi"
                                            :error="$errors->has('form.tgl_respon')" class="w-full font-mono text-sm" />
                                        <x-secondary-button type="button" wire:click="isiSekarang('tgl_respon')" class="!px-2 text-xs" title="Isi waktu sekarang">Kini</x-secondary-button>
                                    </div>
                                    <x-input-error :messages="$errors->get('form.tgl_respon')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label value="Teknisi{{ $tahap(['P', 'V', 'S', 'K', 'X']) ? ' *' : '' }}" />
                                    <x-text-input wire:model="form.teknisi" maxlength="100" :error="$errors->has('form.teknisi')" class="w-full mt-1" />
                                    <x-input-error :messages="$errors->get('form.teknisi')" class="mt-1" />
                                </div>
                            </div>
                            <div>
                                <x-input-label value="Analisa Kerusakan{{ $tahap(['S', 'K', 'X']) ? ' *' : '' }}" />
                                <x-textarea wire:model="form.analisa" rows="2" maxlength="500" :error="$errors->has('form.analisa')" class="w-full mt-1" />
                                <x-input-error :messages="$errors->get('form.analisa')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label value="Dikerjakan oleh" />
                                <div class="flex gap-2 mt-1">
                                    @foreach (\App\Support\Perbaikan::PELAKSANA as $k => $n)
                                        <label class="flex items-center gap-2 px-3 py-1.5 text-sm border rounded-lg cursor-pointer {{ $form['pb_pelaksana'] === $k ? 'border-brand-green bg-brand-green/10' : 'border-hairline' }}">
                                            <input type="radio" wire:model.live="form.pb_pelaksana" value="{{ $k }}" class="text-brand-green" /> {{ $n }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                            @if ($form['pb_pelaksana'] === 'V')
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <x-input-label value="Vendor{{ $tahap(['V', 'S', 'K']) ? ' *' : '' }}" />
                                        <x-text-input wire:model="form.vendor" maxlength="100" :error="$errors->has('form.vendor')" class="w-full mt-1" />
                                        <x-input-error :messages="$errors->get('form.vendor')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-input-label value="No. Service Report" />
                                        <x-text-input wire:model="form.no_service_report" maxlength="60" class="w-full mt-1 font-mono uppercase" />
                                    </div>
                                </div>
                            @endif
                            <div>
                                <x-input-label value="Penggantian Suku Cadang" />
                                <x-textarea wire:model="form.suku_cadang" rows="2" maxlength="500" class="w-full mt-1" />
                            </div>
                            <div>
                                <x-input-label value="Biaya (Rp)" />
                                <x-text-input-number wire:model="form.biaya" :error="$errors->has('form.biaya')" class="w-full mt-1" />
                                <x-input-error :messages="$errors->get('form.biaya')" class="mt-1" />
                                <p class="mt-1 text-[11px] text-muted">Dicatat untuk evaluasi biaya pemeliharaan (Pedoman). Suku cadang perlu persetujuan manajemen/direktur.</p>
                            </div>
                        </div>
                    </x-border-form>

                    {{-- 3. PENYELESAIAN --}}
                    <x-border-form title="3. Penyelesaian">
                        <div class="space-y-3 {{ $tahap(['B', 'P', 'V']) ? 'opacity-60' : '' }}">
                            <div>
                                <x-input-label value="Tgl Selesai Dikerjakan{{ $tahap(['S', 'K']) ? ' *' : '' }}" />
                                <div class="flex gap-1 mt-1">
                                    <x-text-input wire:model="form.tgl_selesai" x-mask="99/99/9999 99:99" placeholder="dd/mm/yyyy hh:mi"
                                        :error="$errors->has('form.tgl_selesai')" class="w-full font-mono text-sm" />
                                    <x-secondary-button type="button" wire:click="isiSekarang('tgl_selesai')" class="!px-2 text-xs">Kini</x-secondary-button>
                                </div>
                                <x-input-error :messages="$errors->get('form.tgl_selesai')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label value="Tgl Diserahkan Kembali{{ $tahap(['K']) ? ' *' : '' }}" />
                                <div class="flex gap-1 mt-1">
                                    <x-text-input wire:model="form.tgl_diserahkan" x-mask="99/99/9999 99:99" placeholder="dd/mm/yyyy hh:mi"
                                        :error="$errors->has('form.tgl_diserahkan')" class="w-full font-mono text-sm" />
                                    <x-secondary-button type="button" wire:click="isiSekarang('tgl_diserahkan')" class="!px-2 text-xs">Kini</x-secondary-button>
                                </div>
                                <x-input-error :messages="$errors->get('form.tgl_diserahkan')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label value="Penerima (ruang){{ $tahap(['K']) ? ' *' : '' }}" />
                                <x-text-input wire:model="form.penerima" maxlength="100" :error="$errors->has('form.penerima')" class="w-full mt-1" />
                                <x-input-error :messages="$errors->get('form.penerima')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label value="Keterangan{{ $tahap(['X']) ? ' * (alasan & rekomendasi, mis. usul penggantian)' : '' }}" />
                                <x-textarea wire:model="form.keterangan" rows="2" maxlength="500" :error="$errors->has('form.keterangan')" class="w-full mt-1" />
                                <x-input-error :messages="$errors->get('form.keterangan')" class="mt-1" />
                            </div>
                            @if ($alat && (string) $alat->wajib_kalibrasi === '1' && $tahap(['S', 'K']))
                                <div class="p-3 text-xs border rounded-lg border-amber-300 bg-amber-50 text-amber-900">
                                    Alat ini wajib kalibrasi — setelah perbaikan lakukan <b>uji fungsi / kalibrasi ulang</b> dan catat di Inventaris Alat › Kalibrasi.
                                </div>
                            @endif
                            <p class="text-[11px] text-muted">
                                Kondisi alat di inventaris otomatis: <b>Diserahkan kembali</b> → Baik · <b>Tidak dapat diperbaiki</b> → Rusak Berat.
                            </p>
                        </div>
                    </x-border-form>
                </div>
            </div>

            {{-- FOOTER --}}
            <div class="sticky bottom-0 z-10 px-6 py-4 mt-auto bg-surface-soft border-t border-hairline dark:bg-gray-900 dark:border-gray-700">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex gap-2">
                        @if ($formMode === 'edit')
                            <x-secondary-button type="button" wire:click="cetak" wire:loading.attr="disabled" wire:target="cetak">Cetak Laporan</x-secondary-button>
                            @if ($st === 'B')
                                <x-confirm-button variant="danger-soft" action="hapus()" title="Hapus Laporan"
                                    message="Hapus laporan {{ $pbNo }}? Hanya untuk laporan yang salah input."
                                    confirmText="Ya, hapus" cancelText="Batal">Hapus</x-confirm-button>
                            @endif
                        @endif
                    </div>
                    <div class="flex justify-end gap-2">
                        <x-secondary-button type="button" x-on:click="tryClose()">Batal</x-secondary-button>
                        <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading.remove wire:target="save">Simpan — {{ \App\Support\Perbaikan::STATUS[$st][0] }}</span>
                            <span wire:loading wire:target="save">Menyimpan...</span>
                        </x-primary-button>
                    </div>
                </div>
            </div>

        </x-dirty-modal-content>
    </x-modal>
</div>
