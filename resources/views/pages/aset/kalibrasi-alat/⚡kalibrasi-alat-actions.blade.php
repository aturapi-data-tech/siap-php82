<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Support\Format;
use App\Support\Kalibrasi;
use App\Http\Traits\Concerns\WithRenderVersioningTrait;

/**
 * Riwayat + input kalibrasi SATU alat (`siaptxn_kalibrasis`). Dipakai Inventaris Alat
 * dan Jadwal Kalibrasi (event aset.kalibrasi-alat.open).
 *
 * Isian mengikuti Sertifikat Kalibrasi: tanggal, lembaga pelaksana, nomor sertifikat,
 * hasil (Laik / Tidak Laik Pakai), disarankan kalibrasi ulang (berlaku s/d).
 * Berlaku s/d otomatis = tanggal kalibrasi + interval alat (default 12 bulan), boleh dikoreksi.
 */
new class extends Component {
    use WithRenderVersioningTrait;

    public string $assetId = '';
    public ?array $alat    = null;

    public bool   $showForm = false;
    public string $formMode = 'create'; // create|edit
    public int    $kalId    = 0;
    public array  $renderVersions = [];
    protected array $renderAreas  = ['modal', 'form'];

    public array $form = [];

    public function mount(): void
    {
        $this->registerAreas(['modal', 'form']);
        $this->resetForm();
    }

    #[On('aset.kalibrasi-alat.open')]
    public function open(string $assetId): void
    {
        $row = DB::table('siapmst_assets as aa')
            ->leftJoin('siapmst_ruangs as ru', 'ru.ruang_id', '=', 'aa.ruang_id')
            ->where('aa.asset_id', $assetId)
            ->first(['aa.asset_id', 'aa.asset_name', 'aa.merk', 'aa.tipe_model', 'aa.no_seri', 'aa.ruang_id', 'ru.ruang_name', 'aa.interval_kalibrasi', 'aa.wajib_kalibrasi']);

        if (!$row) {
            $this->dispatch('toast', type: 'error', message: 'Data alat tidak ditemukan.');
            return;
        }

        $this->assetId = (string) $row->asset_id;
        $this->alat = (array) $row;
        $this->cancelForm();
        unset($this->riwayat);

        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'kalibrasi-alat-actions');
    }

    #[Computed]
    public function riwayat()
    {
        if ($this->assetId === '') {
            return collect();
        }

        return DB::table('siaptxn_kalibrasis')
            ->where('asset_id', $this->assetId)
            ->orderByDesc('kal_tanggal')
            ->orderByDesc('kal_id')
            ->get();
    }

    /** Daftar lembaga yang pernah dipakai — saran isian pelaksana. */
    #[Computed]
    public function daftarPelaksana(): array
    {
        return DB::table('siaptxn_kalibrasis')->whereNotNull('kal_pelaksana')->distinct()->orderBy('kal_pelaksana')->pluck('kal_pelaksana')->all();
    }

    // ─── Form ─────────────────────────────────────────────────────────────────
    public function startCreate(): void
    {
        $this->resetForm();
        $this->formMode = 'create';
        $this->kalId = 0;
        $this->showForm = true;
        $this->incrementVersion('form');
    }

    public function startEdit(int $kalId): void
    {
        $row = DB::table('siaptxn_kalibrasis')->where('kal_id', $kalId)->where('asset_id', $this->assetId)->first();
        if (!$row) {
            $this->dispatch('toast', type: 'error', message: 'Data kalibrasi tidak ditemukan.');
            return;
        }

        $this->resetForm();
        $this->formMode = 'edit';
        $this->kalId = $kalId;
        $this->form = [
            'kal_tanggal'       => Format::tanggal($row->kal_tanggal),
            'kal_pelaksana'     => (string) ($row->kal_pelaksana ?? ''),
            'kal_no_sertifikat' => (string) ($row->kal_no_sertifikat ?? ''),
            'kal_hasil'         => (string) $row->kal_hasil,
            'kal_berlaku_sd'    => Format::tanggal($row->kal_berlaku_sd),
            'keterangan'        => (string) ($row->keterangan ?? ''),
        ];
        $this->showForm = true;
        $this->incrementVersion('form');
    }

    public function cancelForm(): void
    {
        $this->resetForm();
        $this->formMode = 'create';
        $this->kalId = 0;
        $this->showForm = false;
    }

    /** Tanggal kalibrasi diisi & berlaku s/d masih kosong → isi otomatis + interval alat. */
    public function updatedForm($value, string $key): void
    {
        if ($key !== 'kal_tanggal' || $this->form['kal_berlaku_sd'] !== '' || $this->form['kal_hasil'] !== 'L') {
            return;
        }

        try {
            $tgl = Carbon::createFromFormat('!' . Format::TANGGAL, $this->form['kal_tanggal']);
        } catch (\Throwable) {
            return;
        }

        $interval = (int) ($this->alat['interval_kalibrasi'] ?? Kalibrasi::INTERVAL_DEFAULT);
        $this->form['kal_berlaku_sd'] = Kalibrasi::berlakuSdDefault($tgl, $interval)->format(Format::TANGGAL);
    }

    // ─── Save ─────────────────────────────────────────────────────────────────
    public function save(): void
    {
        $this->validate(
            [
                'form.kal_tanggal'       => 'required|date_format:d/m/Y',
                'form.kal_pelaksana'     => 'required|string|max:100',
                'form.kal_no_sertifikat' => 'nullable|string|max:60',
                'form.kal_hasil'         => 'required|in:L,T',
                'form.kal_berlaku_sd'    => $this->form['kal_hasil'] === 'L'
                    ? 'required|date_format:d/m/Y|after:form.kal_tanggal'
                    : 'nullable|date_format:d/m/Y',
                'form.keterangan'        => 'nullable|string|max:200',
            ],
            [
                'required'    => ':attribute wajib diisi.',
                'max'         => ':attribute maksimal :max karakter.',
                'in'          => ':attribute tidak valid.',
                'date_format' => ':attribute harus berformat dd/mm/yyyy.',
                'after'       => ':attribute harus setelah Tanggal Kalibrasi.',
            ],
            [
                'form.kal_tanggal'       => 'Tanggal Kalibrasi',
                'form.kal_pelaksana'     => 'Lembaga Pelaksana',
                'form.kal_no_sertifikat' => 'No. Sertifikat',
                'form.kal_hasil'         => 'Hasil',
                'form.kal_berlaku_sd'    => 'Berlaku s/d',
                'form.keterangan'        => 'Keterangan',
            ],
        );

        $tgl = Carbon::createFromFormat('!' . Format::TANGGAL, $this->form['kal_tanggal']);
        if ($tgl->isFuture()) {
            $this->addError('form.kal_tanggal', 'Tanggal Kalibrasi tidak boleh di masa depan — catat setelah kalibrasi dilaksanakan.');
            return;
        }

        $f = $this->form;
        $payload = [
            'kal_tanggal'       => $this->tanggalOracle($f['kal_tanggal']),
            'kal_pelaksana'     => trim($f['kal_pelaksana']),
            'kal_no_sertifikat' => $f['kal_no_sertifikat'] !== '' ? mb_strtoupper(trim($f['kal_no_sertifikat'])) : null,
            'kal_hasil'         => $f['kal_hasil'],
            'kal_berlaku_sd'    => $this->tanggalOracle($f['kal_berlaku_sd']),
            'keterangan'        => $f['keterangan'] !== '' ? trim($f['keterangan']) : null,
            'updated_by'        => auth()->id(),
            'updated_at'        => now(),
        ];

        if ($this->formMode === 'create') {
            // insert() biasa — kal_id dari trigger/sequence (insertGetId memicu warning yajra di 10g).
            DB::table('siaptxn_kalibrasis')->insert(['asset_id' => $this->assetId, 'created_at' => now(), ...$payload]);
        } else {
            DB::table('siaptxn_kalibrasis')->where('kal_id', $this->kalId)->where('asset_id', $this->assetId)->update($payload);
        }

        $this->dispatch('toast', type: 'success', message: 'Data kalibrasi tersimpan.');
        $this->dispatch('aset.kalibrasi-alat.saved');
        unset($this->riwayat, $this->daftarPelaksana);
        $this->cancelForm();
    }

    public function deleteKalibrasi(int $kalId): void
    {
        $deleted = DB::table('siaptxn_kalibrasis')->where('kal_id', $kalId)->where('asset_id', $this->assetId)->delete();
        if ($deleted === 0) {
            $this->dispatch('toast', type: 'error', message: 'Data kalibrasi tidak ditemukan.');
            return;
        }

        $this->dispatch('toast', type: 'success', message: 'Data kalibrasi dihapus.');
        $this->dispatch('aset.kalibrasi-alat.saved');
        unset($this->riwayat);
        if ($this->kalId === $kalId) {
            $this->cancelForm();
        }
    }

    public function closeModal(): void
    {
        $this->cancelForm();
        $this->assetId = '';
        $this->alat = null;
        $this->dispatch('close-modal', name: 'kalibrasi-alat-actions');
        $this->resetVersion();
    }

    private function resetForm(): void
    {
        $this->form = [
            'kal_tanggal'       => '',
            'kal_pelaksana'     => '',
            'kal_no_sertifikat' => '',
            'kal_hasil'         => 'L',
            'kal_berlaku_sd'    => '',
            'keterangan'        => '',
        ];
        $this->resetValidation();
    }

    /** Tanggal sudah lolos date_format:d/m/Y — aman dirakit ke to_date. */
    private function tanggalOracle(?string $tanggal)
    {
        return empty($tanggal) ? null : DB::raw("to_date('{$tanggal}', 'dd/mm/yyyy')");
    }
};
?>

<div>
    <x-modal name="kalibrasi-alat-actions" size="full" height="full" focusable>
        <div class="flex flex-col min-h-[calc(100vh-8rem)]" wire:key="{{ $this->renderKey('modal', [$assetId]) }}">

            {{-- HEADER --}}
            <div class="relative px-6 py-5 bg-surface-soft">
                <div class="relative flex items-start justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <div class="flex items-center justify-center w-10 h-10 rounded-xl bg-brand-green/10 dark:bg-brand-lime/15">
                            <img src="{{ asset('images/Logogram black solid.png') }}" alt="Logo" class="block w-6 h-6 dark:hidden" />
                            <img src="{{ asset('images/Logogram white solid.png') }}" alt="Logo" class="hidden w-6 h-6 dark:block" />
                        </div>
                        @if ($alat)
                            <div>
                                <h2 class="ds-display-sm dark:text-gray-100">Kalibrasi — {{ $alat['asset_name'] }}</h2>
                                <p class="mt-0.5 text-sm text-muted dark:text-gray-400">
                                    <span class="font-mono">{{ $alat['asset_id'] }}</span>
                                    · {{ $alat['merk'] ?: '-' }} {{ $alat['tipe_model'] }}
                                    · No. Seri <span class="font-mono">{{ $alat['no_seri'] ?: '-' }}</span>
                                    · {{ $alat['ruang_id'] }} {{ $alat['ruang_name'] }}
                                    · interval {{ (int) $alat['interval_kalibrasi'] }} bulan
                                </p>
                            </div>
                        @endif
                    </div>
                    <x-icon-button color="gray" type="button" wire:click="closeModal">
                        <span class="sr-only">Close</span>
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        </svg>
                    </x-icon-button>
                </div>
            </div>

            {{-- BODY --}}
            <div class="grid flex-1 grid-cols-1 gap-4 px-4 py-4 bg-surface-soft dark:bg-gray-950/20 xl:grid-cols-5">

                {{-- RIWAYAT --}}
                <div class="xl:col-span-3">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="font-semibold text-ink dark:text-gray-100">Riwayat kalibrasi ({{ $this->riwayat->count() }})</h3>
                        @unless ($showForm && $formMode === 'create')
                            <x-primary-button type="button" wire:click="startCreate" class="!px-3 !py-1.5 text-xs">+ Catat Kalibrasi</x-primary-button>
                        @endunless
                    </div>
                    <div class="overflow-hidden border shadow-sm bg-canvas border-hairline rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                        <table class="ds-table">
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Pelaksana / No. Sertifikat</th>
                                    <th>Hasil</th>
                                    <th>Berlaku s/d</th>
                                    <th class="ds-c">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->riwayat as $i => $row)
                                    <tr wire:key="kal-{{ $row->kal_id }}" class="{{ $kalId === (int) $row->kal_id ? 'bg-brand-green/5' : '' }} {{ $i > 0 ? 'opacity-60' : '' }}">
                                        <td class="font-mono whitespace-nowrap">
                                            {{ \App\Support\Format::tanggal($row->kal_tanggal) }}
                                            @if ($i === 0)
                                                <div class="font-sans text-[10px] font-semibold text-brand-green">TERAKHIR</div>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="text-sm">{{ $row->kal_pelaksana }}</div>
                                            <div class="font-mono text-xs text-muted">{{ $row->kal_no_sertifikat ?: '—' }}</div>
                                            @if ($row->keterangan)
                                                <div class="text-[11px] text-muted-soft">{{ $row->keterangan }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            <x-badge :variant="$row->kal_hasil === 'L' ? 'success' : 'danger'">{{ \App\Support\Kalibrasi::HASIL[$row->kal_hasil] ?? $row->kal_hasil }}</x-badge>
                                        </td>
                                        <td class="font-mono whitespace-nowrap">{{ \App\Support\Format::tanggal($row->kal_berlaku_sd) ?: '—' }}</td>
                                        <td class="ds-c">
                                            <div class="flex justify-center gap-2">
                                                <x-action-edit wire:click="startEdit({{ $row->kal_id }})" />
                                                <x-action-delete :action="'deleteKalibrasi(' . $row->kal_id . ')'"
                                                    title="Hapus Kalibrasi"
                                                    message="Hapus catatan kalibrasi {{ \App\Support\Format::tanggal($row->kal_tanggal) }}?" />
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-6 py-10 text-center text-muted dark:text-gray-400">
                                            Belum ada riwayat kalibrasi. Klik <b>+ Catat Kalibrasi</b> setelah kalibrasi dilaksanakan.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- FORM --}}
                <div class="xl:col-span-2">
                    @if ($showForm)
                        <div x-enter-chain wire:key="{{ $this->renderKey('form', [$formMode, $kalId]) }}"
                            x-data x-init="$nextTick(() => setTimeout(() => $refs.inputTanggal?.focus(), 150))">
                            <x-border-form :title="$formMode === 'edit' ? 'Ubah Catatan Kalibrasi' : 'Catat Kalibrasi'">
                                <div class="space-y-4">
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <x-input-label value="Tanggal Kalibrasi *" />
                                            <x-text-input wire:model.live.debounce.500ms="form.kal_tanggal" x-ref="inputTanggal"
                                                x-mask="99/99/9999" placeholder="dd/mm/yyyy"
                                                :error="$errors->has('form.kal_tanggal')" class="w-full mt-1 font-mono"
                                                x-on:keydown.enter.prevent="$refs.inputPelaksana?.focus()" />
                                            <x-input-error :messages="$errors->get('form.kal_tanggal')" class="mt-1" />
                                        </div>
                                        <div>
                                            <x-input-label value="Hasil *" />
                                            <x-select-input wire:model.live="form.kal_hasil" :error="$errors->has('form.kal_hasil')" class="mt-1">
                                                @foreach (\App\Support\Kalibrasi::HASIL as $kode => $nama)
                                                    <option value="{{ $kode }}">{{ $nama }}</option>
                                                @endforeach
                                            </x-select-input>
                                        </div>
                                    </div>

                                    <div>
                                        <x-input-label value="Lembaga Pelaksana *" />
                                        <x-text-input wire:model="form.kal_pelaksana" x-ref="inputPelaksana" list="kalibrasi-pelaksana"
                                            maxlength="100" placeholder="Mis. BPFK Surabaya / PT ... (terakreditasi KAN)"
                                            :error="$errors->has('form.kal_pelaksana')" class="w-full mt-1"
                                            x-on:keydown.enter.prevent="$refs.inputSertifikat?.focus()" />
                                        <datalist id="kalibrasi-pelaksana">
                                            @foreach ($this->daftarPelaksana as $p)
                                                <option value="{{ $p }}"></option>
                                            @endforeach
                                        </datalist>
                                        <x-input-error :messages="$errors->get('form.kal_pelaksana')" class="mt-1" />
                                    </div>

                                    <div>
                                        <x-input-label value="No. Sertifikat" />
                                        <x-text-input wire:model="form.kal_no_sertifikat" x-ref="inputSertifikat" maxlength="60"
                                            :error="$errors->has('form.kal_no_sertifikat')" class="w-full mt-1 font-mono uppercase"
                                            x-on:keydown.enter.prevent="$refs.inputBerlaku?.focus()" />
                                        <x-input-error :messages="$errors->get('form.kal_no_sertifikat')" class="mt-1" />
                                    </div>

                                    <div>
                                        <x-input-label value="{{ $form['kal_hasil'] === 'L' ? 'Berlaku s/d (kalibrasi ulang) *' : 'Berlaku s/d' }}" />
                                        <x-text-input wire:model.live.debounce.500ms="form.kal_berlaku_sd" x-ref="inputBerlaku"
                                            x-mask="99/99/9999" placeholder="dd/mm/yyyy"
                                            :error="$errors->has('form.kal_berlaku_sd')" class="w-full mt-1 font-mono"
                                            x-on:keydown.enter.prevent="$wire.save()" />
                                        <p class="mt-1 text-[11px] text-muted dark:text-gray-400">
                                            Otomatis tanggal kalibrasi + {{ (int) ($alat['interval_kalibrasi'] ?? 12) }} bulan; sesuaikan dengan sertifikat ("disarankan kalibrasi ulang").
                                        </p>
                                        <x-input-error :messages="$errors->get('form.kal_berlaku_sd')" class="mt-1" />
                                    </div>

                                    <div>
                                        <x-input-label value="Keterangan" />
                                        <x-text-input wire:model="form.keterangan" maxlength="200" class="w-full mt-1"
                                            placeholder="{{ $form['kal_hasil'] === 'T' ? 'Mis. dikirim perbaikan, tidak dipakai' : 'Opsional' }}" />
                                        <x-input-error :messages="$errors->get('form.keterangan')" class="mt-1" />
                                    </div>

                                    <div class="flex justify-end gap-2 pt-3 border-t border-hairline dark:border-gray-700">
                                        <x-secondary-button type="button" wire:click="cancelForm">Batal</x-secondary-button>
                                        <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                                            <span wire:loading.remove wire:target="save">{{ $formMode === 'edit' ? 'Perbarui' : 'Simpan' }}</span>
                                            <span wire:loading wire:target="save">Menyimpan...</span>
                                        </x-primary-button>
                                    </div>
                                </div>
                            </x-border-form>
                        </div>
                    @else
                        <div class="p-5 text-sm border border-dashed rounded-2xl border-hairline text-muted dark:border-gray-700 dark:text-gray-400">
                            <p class="font-semibold text-ink dark:text-gray-200">Catatan</p>
                            <ul class="mt-2 space-y-1 list-disc list-inside">
                                <li>Kalibrasi minimal <b>1 kali setahun</b> oleh BPFK / institusi penguji terakreditasi KAN (Permenkes 54/2015 Pasal 8).</li>
                                <li>Isi sesuai <b>Sertifikat Kalibrasi</b>: tanggal, lembaga, nomor sertifikat, hasil, dan saran kalibrasi ulang.</li>
                                <li>Hasil <b>Tidak Laik Pakai</b> → alat jangan dipakai sampai diperbaiki & dikalibrasi ulang.</li>
                            </ul>
                        </div>
                    @endif
                </div>
            </div>

            {{-- FOOTER --}}
            <div class="sticky bottom-0 z-10 px-6 py-4 mt-auto border-t bg-surface-soft border-hairline dark:bg-gray-900 dark:border-gray-700">
                <div class="flex justify-end">
                    <x-secondary-button type="button" wire:click="closeModal">Tutup</x-secondary-button>
                </div>
            </div>

        </div>
    </x-modal>
</div>
