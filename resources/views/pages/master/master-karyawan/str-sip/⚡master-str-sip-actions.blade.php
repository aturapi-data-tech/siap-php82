<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Http\Traits\Concerns\WithRenderVersioningTrait;

/**
 * Modal STR/SIP satu karyawan (`siapmst_emp_licenses`) — kolom STR & kolom SIP bersebelahan,
 * masing-masing dengan daftar + form tambah/edit sendiri (jenis ditentukan kolomnya).
 *
 * Aturan masa berlaku (UU 17/2023):
 * - STR berlaku seumur hidup → lic_expired_date selalu NULL.
 * - SIP berlaku 5 tahun → tanggal berakhir otomatis = tanggal terbit + 5 tahun (boleh dikoreksi).
 * active_status '1' = surat yang berlaku sekarang, '0' = riwayat.
 */
new class extends Component {
    use WithRenderVersioningTrait;

    public const MASA_BERLAKU_SIP_TAHUN = 5;

    public string $empId   = '';
    public string $empName = '';

    public bool   $showForm = false;     // form tampil di kolom sesuai form.lic_type
    public string $formMode = 'create'; // create|edit
    public int    $licId    = 0;
    public array  $renderVersions = [];
    protected array $renderAreas  = ['modal', 'form'];

    public array $form = [];

    public function mount(): void
    {
        $this->registerAreas(['modal', 'form']);
        $this->resetForm();
    }

    // ─── Open (per karyawan) ──────────────────────────────────────────────────
    #[On('master.karyawan.openStrSip')]
    public function openStrSip(string $empId): void
    {
        $emp = DB::table('siapmst_employers')->select('emp_id', 'emp_name')->where('emp_id', $empId)->first();
        if (!$emp) {
            $this->dispatch('toast', type: 'error', message: 'Data karyawan tidak ditemukan.');
            return;
        }

        $this->empId   = (string) $emp->emp_id;
        $this->empName = (string) $emp->emp_name;
        $this->cancelForm();

        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'master-str-sip-actions');
    }

    /** Semua STR/SIP karyawan, dikelompokkan per jenis: ['STR' => [...], 'SIP' => [...]]. */
    #[Computed]
    public function licenses(): array
    {
        $rows = DB::table('siapmst_emp_licenses')
            ->where('emp_id', $this->empId)
            ->orderByDesc('active_status')
            ->orderByDesc('lic_issue_date')
            ->get();

        return [
            'STR' => $rows->where('lic_type', 'STR')->values(),
            'SIP' => $rows->where('lic_type', 'SIP')->values(),
        ];
    }

    // ─── Form tambah / edit ───────────────────────────────────────────────────
    public function startCreate(string $type): void
    {
        if (!in_array($type, ['STR', 'SIP'], true)) {
            return;
        }
        if ($type === 'STR' && $this->sudahPunyaStr()) {
            $this->dispatch('toast', type: 'error', message: 'STR berlaku seumur hidup — cukup satu. Ubah STR yang sudah ada.');
            return;
        }

        $this->resetForm();
        $this->form['lic_type'] = $type;
        $this->formMode = 'create';
        $this->licId    = 0;
        $this->showForm = true;
        $this->incrementVersion('form');
        $this->dispatch('focus-str-sip-no');
    }

    /** STR seumur hidup → satu karyawan cukup satu STR. */
    private function sudahPunyaStr(): bool
    {
        return DB::table('siapmst_emp_licenses')->where('emp_id', $this->empId)->where('lic_type', 'STR')->exists();
    }

    public function cancelForm(): void
    {
        $this->resetForm();
        $this->formMode = 'create';
        $this->licId    = 0;
        $this->showForm = false;
    }

    public function startEdit(int $licId): void
    {
        $row = DB::table('siapmst_emp_licenses')->where('lic_id', $licId)->where('emp_id', $this->empId)->first();
        if (!$row) {
            $this->dispatch('toast', type: 'error', message: 'Data STR/SIP tidak ditemukan.');
            return;
        }

        $this->resetForm();
        $this->formMode = 'edit';
        $this->licId    = $licId;
        $this->form = [
            'lic_type'         => (string) $row->lic_type,
            'lic_no'           => (string) $row->lic_no,
            'lic_profesi'      => (string) ($row->lic_profesi ?? ''),
            'lic_issuer'       => (string) ($row->lic_issuer ?? ''),
            'lic_issue_date'   => $this->formatTanggal($row->lic_issue_date),
            'lic_expired_date' => $this->formatTanggal($row->lic_expired_date),
            'active_status'    => (string) $row->active_status === '1' ? '1' : '0',
        ];
        $this->showForm = true;
        $this->incrementVersion('form');
        $this->dispatch('focus-str-sip-no');
    }

    /** STR → kosongkan tgl berakhir; SIP → isi otomatis tgl terbit + 5 tahun bila masih kosong. */
    public function updatedForm($value, string $key): void
    {
        if ($key === 'lic_type' && $value === 'STR') {
            $this->form['lic_expired_date'] = '';
        }

        if (in_array($key, ['lic_type', 'lic_issue_date'], true)) {
            $this->isiOtomatisBerakhirSip();
        }
    }

    private function isiOtomatisBerakhirSip(): void
    {
        if ($this->form['lic_type'] !== 'SIP' || $this->form['lic_expired_date'] !== '') {
            return;
        }

        try {
            $terbit = Carbon::createFromFormat('!d/m/Y', $this->form['lic_issue_date']);
        } catch (\Throwable) {
            return; // tanggal terbit belum lengkap/valid
        }

        $this->form['lic_expired_date'] = $terbit->addYears(self::MASA_BERLAKU_SIP_TAHUN)->format('d/m/Y');
    }

    // ─── Validasi ─────────────────────────────────────────────────────────────
    protected function rules(): array
    {
        return [
            'form.lic_type'         => 'required|in:STR,SIP',
            'form.lic_no'           => 'required|string|max:100',
            'form.lic_profesi'      => 'nullable|string|max:50',
            'form.lic_issuer'       => 'nullable|string|max:100',
            'form.lic_issue_date'   => 'required|date_format:d/m/Y',
            'form.lic_expired_date' => $this->form['lic_type'] === 'SIP'
                ? 'required|date_format:d/m/Y|after:form.lic_issue_date'
                : 'nullable',
            'form.active_status'    => 'required|in:0,1',
        ];
    }

    protected function messages(): array
    {
        return [
            'required'    => ':attribute wajib diisi.',
            'max'         => ':attribute maksimal :max karakter.',
            'in'          => ':attribute tidak valid.',
            'date_format' => ':attribute harus berformat dd/mm/yyyy.',
            'after'       => ':attribute harus setelah Tanggal Terbit.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'form.lic_type'         => 'Jenis',
            'form.lic_no'           => 'Nomor Surat',
            'form.lic_profesi'      => 'Profesi',
            'form.lic_issuer'       => 'Penerbit',
            'form.lic_issue_date'   => 'Tanggal Terbit',
            'form.lic_expired_date' => 'Tanggal Berakhir',
            'form.active_status'    => 'Status',
        ];
    }

    // ─── Save ─────────────────────────────────────────────────────────────────
    public function save(): void
    {
        $this->validate();

        $form = $this->form;
        $payload = [
            'lic_type'         => $form['lic_type'],
            'lic_no'           => mb_strtoupper(trim($form['lic_no'])),
            'lic_profesi'      => $form['lic_profesi'] !== '' ? trim($form['lic_profesi']) : null,
            'lic_issuer'       => $form['lic_issuer'] !== '' ? trim($form['lic_issuer']) : null,
            'lic_issue_date'   => $this->tanggalOracle($form['lic_issue_date']),
            // STR seumur hidup → selalu NULL walau field sempat terisi.
            'lic_expired_date' => $form['lic_type'] === 'SIP' ? $this->tanggalOracle($form['lic_expired_date']) : null,
            'active_status'    => $form['active_status'],
            'updated_at'       => now(),
        ];

        if ($this->formMode === 'create' && $form['lic_type'] === 'STR' && $this->sudahPunyaStr()) {
            $this->dispatch('toast', type: 'error', message: 'STR berlaku seumur hidup — cukup satu. Ubah STR yang sudah ada.');
            return;
        }

        if ($this->formMode === 'create') {
            // insert() biasa — lic_id diisi trigger/sequence Oracle (insertGetId memicu warning yajra di 10g).
            DB::table('siapmst_emp_licenses')->insert(['emp_id' => $this->empId, 'created_at' => now(), ...$payload]);
        } else {
            DB::table('siapmst_emp_licenses')->where('lic_id', $this->licId)->where('emp_id', $this->empId)->update($payload);
        }

        $this->dispatch('toast', type: 'success', message: $form['lic_type'] . ' berhasil disimpan.');
        $this->dispatch('master.karyawan.strSipSaved');
        unset($this->licenses);
        $this->cancelForm();
    }

    // ─── Delete ───────────────────────────────────────────────────────────────
    public function deleteLicense(int $licId): void
    {
        $deleted = DB::table('siapmst_emp_licenses')->where('lic_id', $licId)->where('emp_id', $this->empId)->delete();
        if ($deleted === 0) {
            $this->dispatch('toast', type: 'error', message: 'Data STR/SIP tidak ditemukan.');
            return;
        }

        $this->dispatch('toast', type: 'success', message: 'Data STR/SIP berhasil dihapus.');
        $this->dispatch('master.karyawan.strSipSaved');
        unset($this->licenses);
        if ($this->licId === $licId) {
            $this->cancelForm();
        }
    }

    // ─── Close ────────────────────────────────────────────────────────────────
    public function closeModal(): void
    {
        $this->cancelForm();
        $this->empId = '';
        $this->empName = '';
        $this->dispatch('close-modal', name: 'master-str-sip-actions');
        $this->resetVersion();
    }

    private function resetForm(): void
    {
        $this->form = [
            'lic_type'         => 'SIP',
            'lic_no'           => '',
            'lic_profesi'      => '',
            'lic_issuer'       => '',
            'lic_issue_date'   => '',
            'lic_expired_date' => '',
            'active_status'    => '1',
        ];
        $this->resetValidation();
    }

    private function formatTanggal(?string $tanggal): string
    {
        return empty($tanggal) ? '' : Carbon::parse($tanggal)->format('d/m/Y');
    }

    /** Tanggal sudah lolos date_format:d/m/Y — aman dirakit ke to_date. */
    private function tanggalOracle(?string $tanggal)
    {
        return empty($tanggal) ? null : DB::raw("to_date('{$tanggal}', 'dd/mm/yyyy')");
    }

    /** Status masa berlaku untuk badge: [label, variant]. */
    public function statusBerlaku(object $row): array
    {
        if ((string) $row->active_status !== '1') {
            return ['Riwayat', 'gray'];
        }
        if ($row->lic_type === 'STR' || empty($row->lic_expired_date)) {
            return ['Seumur hidup', 'success'];
        }

        $sisaHari = (int) now()->startOfDay()->diffInDays(Carbon::parse($row->lic_expired_date)->startOfDay(), false);

        return match (true) {
            $sisaHari < 0   => ['Kedaluwarsa', 'danger'],
            $sisaHari <= 90 => ["Habis {$sisaHari} hari lagi", 'warning'],
            default         => ['Berlaku', 'success'],
        };
    }
};
?>

<div>
    <x-modal name="master-str-sip-actions" size="full" height="full" focusable>
        <div class="flex flex-col min-h-[calc(100vh-8rem)]"
            wire:key="{{ $this->renderKey('modal', [$empId]) }}">

            {{-- HEADER --}}
            <div class="relative px-6 py-5 bg-surface-soft">
                <div class="relative flex items-start justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <div class="flex items-center justify-center w-10 h-10 rounded-xl bg-brand-green/10 dark:bg-brand-lime/15">
                            <img src="{{ asset('images/Logogram black solid.png') }}" alt="Logo" class="block w-6 h-6 dark:hidden" />
                            <img src="{{ asset('images/Logogram white solid.png') }}" alt="Logo" class="hidden w-6 h-6 dark:block" />
                        </div>
                        <div>
                            <h2 class="ds-display-sm dark:text-gray-100">STR & SIP — {{ $empName }}</h2>
                            <p class="mt-0.5 text-sm text-muted dark:text-gray-400">
                                <span class="font-mono">{{ $empId }}</span> ·
                                STR berlaku seumur hidup, SIP berlaku {{ $this::MASA_BERLAKU_SIP_TAHUN }} tahun (UU 17/2023).
                            </p>
                        </div>
                    </div>
                    <x-icon-button color="gray" type="button" wire:click="closeModal">
                        <span class="sr-only">Close</span>
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        </svg>
                    </x-icon-button>
                </div>
            </div>

            {{-- BODY — kolom STR & kolom SIP bersebelahan --}}
            <div class="grid flex-1 grid-cols-1 gap-4 px-4 py-4 bg-surface-soft dark:bg-gray-950/20 lg:grid-cols-2">
                @foreach (['STR' => 'Surat Tanda Registrasi — berlaku seumur hidup', 'SIP' => 'Surat Izin Praktik — berlaku ' . $this::MASA_BERLAKU_SIP_TAHUN . ' tahun'] as $jenis => $keterangan)
                    @php $isiKolom = $this->licenses[$jenis]; @endphp
                    <section wire:key="kolom-{{ $jenis }}"
                        class="flex flex-col border shadow-sm bg-canvas border-hairline rounded-2xl dark:border-gray-700 dark:bg-gray-900">

                        {{-- Judul kolom --}}
                        <div class="flex items-start justify-between gap-3 px-5 py-4 border-b border-hairline dark:border-gray-700">
                            <div>
                                <div class="flex items-center gap-2">
                                    <x-badge :variant="$jenis === 'STR' ? 'info' : 'purple'">{{ $jenis }}</x-badge>
                                    <span class="text-sm font-semibold text-ink dark:text-gray-100">{{ $isiKolom->count() }} surat</span>
                                </div>
                                <p class="mt-1 text-xs text-muted dark:text-gray-400">{{ $keterangan }}</p>
                            </div>
                            {{-- STR seumur hidup → tombol Tambah hilang begitu STR sudah ada (cukup Edit) --}}
                            @if ($jenis === 'STR' && $isiKolom->isNotEmpty())
                                <span class="text-[11px] text-muted-soft text-right">Cukup 1 STR<br>— gunakan Edit</span>
                            @elseif (!($showForm && $form['lic_type'] === $jenis && $formMode === 'create'))
                                <x-primary-button type="button" wire:click="startCreate('{{ $jenis }}')" class="!px-3 !py-1.5 text-xs">
                                    + Tambah {{ $jenis }}
                                </x-primary-button>
                            @endif
                        </div>

                        {{-- Daftar surat --}}
                        <div class="p-4 space-y-3">
                            @forelse ($isiKolom as $row)
                                @php [$label, $variant] = $this->statusBerlaku($row); @endphp
                                <div wire:key="str-sip-{{ $row->lic_id }}"
                                    class="p-3 border rounded-xl transition
                                        {{ $licId === (int) $row->lic_id ? 'border-brand-green/60 bg-brand-green/5 dark:border-brand-lime/60 dark:bg-brand-lime/5' : 'border-hairline dark:border-gray-700' }}
                                        {{ (string) $row->active_status !== '1' ? 'opacity-60' : '' }}">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="ds-td-token break-all">{{ $row->lic_no }}</div>
                                            <div class="mt-0.5 text-xs text-muted dark:text-gray-400">
                                                {{ $row->lic_profesi ?: '-' }}{{ $row->lic_issuer ? ' · ' . $row->lic_issuer : '' }}
                                            </div>
                                        </div>
                                        <x-badge :variant="$variant">{{ $label }}</x-badge>
                                    </div>
                                    <div class="flex items-end justify-between gap-3 mt-2">
                                        <div class="text-xs text-body dark:text-gray-300">
                                            Terbit <b>{{ \App\Support\Format::tanggal($row->lic_issue_date) ?: '-' }}</b>
                                            @if ($jenis === 'SIP')
                                                · Berakhir <b>{{ \App\Support\Format::tanggal($row->lic_expired_date) ?: '-' }}</b>
                                            @endif
                                        </div>
                                        <div class="flex gap-2 shrink-0">
                                            <x-action-edit wire:click="startEdit({{ $row->lic_id }})" />
                                            <x-action-delete :action="'deleteLicense(' . $row->lic_id . ')'"
                                                title="Hapus {{ $jenis }}"
                                                message="Yakin hapus {{ $jenis }} nomor {{ $row->lic_no }}? Untuk surat lama, sebaiknya ubah status jadi Riwayat." />
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <p class="py-6 text-sm text-center text-muted dark:text-gray-400">Belum ada {{ $jenis }}.</p>
                            @endforelse

                            {{-- Form tambah / edit — tampil di kolom jenisnya --}}
                            @if ($showForm && $form['lic_type'] === $jenis)
                                <div x-enter-chain
                                    wire:key="{{ $this->renderKey('form', [$formMode, $licId, $jenis]) }}"
                                    x-data
                                    x-init="$nextTick(() => setTimeout(() => $refs.inputNo?.focus(), 150))"
                                    x-on:focus-str-sip-no.window="$nextTick(() => setTimeout(() => $refs.inputNo?.focus(), 150))">
                                    <x-border-form :title="($formMode === 'edit' ? 'Ubah ' : 'Tambah ') . $jenis">
                                        <div class="space-y-4">
                                            <div>
                                                <x-input-label value="Nomor {{ $jenis }} *" />
                                                <x-text-input wire:model="form.lic_no" x-ref="inputNo"
                                                    maxlength="100"
                                                    :error="$errors->has('form.lic_no')" class="w-full mt-1 uppercase"
                                                    x-on:keydown.enter.prevent="$refs.inputProfesi?.focus()" />
                                                <x-input-error :messages="$errors->get('form.lic_no')" class="mt-1" />
                                            </div>

                                            <div class="grid grid-cols-2 gap-3">
                                                <div>
                                                    <x-input-label value="Profesi" />
                                                    <x-text-input wire:model="form.lic_profesi" x-ref="inputProfesi" list="str-sip-profesi"
                                                        maxlength="50" placeholder="Dokter, Perawat, ..."
                                                        :error="$errors->has('form.lic_profesi')" class="w-full mt-1"
                                                        x-on:keydown.enter.prevent="$refs.inputIssuer?.focus()" />
                                                    <x-input-error :messages="$errors->get('form.lic_profesi')" class="mt-1" />
                                                </div>
                                                <div>
                                                    <x-input-label value="Penerbit" />
                                                    <x-text-input wire:model="form.lic_issuer" x-ref="inputIssuer"
                                                        maxlength="100"
                                                        placeholder="{{ $jenis === 'STR' ? 'Mis. KKI / Kemenkes' : 'Mis. DPMPTSP Kab. Tulungagung' }}"
                                                        :error="$errors->has('form.lic_issuer')" class="w-full mt-1"
                                                        x-on:keydown.enter.prevent="$refs.inputIssueDate?.focus()" />
                                                    <x-input-error :messages="$errors->get('form.lic_issuer')" class="mt-1" />
                                                </div>
                                            </div>

                                            <div class="grid grid-cols-2 gap-3">
                                                <div>
                                                    <x-input-label value="Tanggal Terbit *" />
                                                    <x-text-input wire:model.live.debounce.500ms="form.lic_issue_date" x-ref="inputIssueDate"
                                                        placeholder="dd/mm/yyyy" x-mask="99/99/9999"
                                                        :error="$errors->has('form.lic_issue_date')" class="w-full mt-1"
                                                        x-on:keydown.enter.prevent="{{ $jenis === 'SIP' ? '$refs.inputExpiredDate?.focus()' : '$wire.save()' }}" />
                                                    <x-input-error :messages="$errors->get('form.lic_issue_date')" class="mt-1" />
                                                </div>
                                                <div>
                                                    @if ($jenis === 'SIP')
                                                        <x-input-label value="Tanggal Berakhir *" />
                                                        <x-text-input wire:model.live.debounce.500ms="form.lic_expired_date" x-ref="inputExpiredDate"
                                                            placeholder="dd/mm/yyyy" x-mask="99/99/9999"
                                                            :error="$errors->has('form.lic_expired_date')" class="w-full mt-1"
                                                            x-on:keydown.enter.prevent="$wire.save()" />
                                                        <p class="mt-1 text-[11px] text-muted dark:text-gray-400">
                                                            Otomatis terbit + {{ $this::MASA_BERLAKU_SIP_TAHUN }} tahun; sesuaikan bila tertulis lain.
                                                        </p>
                                                        <x-input-error :messages="$errors->get('form.lic_expired_date')" class="mt-1" />
                                                    @else
                                                        <x-input-label value="Tanggal Berakhir" />
                                                        <x-text-input value="Seumur hidup" disabled class="w-full mt-1" />
                                                    @endif
                                                </div>
                                            </div>

                                            <x-toggle wire:model.live="form.active_status" trueValue="1" falseValue="0">
                                                {{ $form['active_status'] === '1' ? 'Berlaku sekarang' : 'Riwayat (sudah diganti)' }}
                                            </x-toggle>

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
                            @endif
                        </div>
                    </section>
                @endforeach

                <datalist id="str-sip-profesi">
                    @foreach (['Dokter', 'Dokter Spesialis', 'Dokter Gigi', 'Perawat', 'Bidan', 'Apoteker', 'Tenaga Teknis Kefarmasian', 'Ahli Teknologi Laboratorium Medik', 'Radiografer', 'Fisioterapis', 'Nutrisionis'] as $p)
                        <option value="{{ $p }}"></option>
                    @endforeach
                </datalist>
            </div>

            {{-- FOOTER --}}
            <div class="sticky bottom-0 z-10 px-6 py-4 mt-auto border-t bg-surface-soft border-hairline dark:bg-gray-900 dark:border-gray-700">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-xs text-muted dark:text-gray-400">
                        <kbd class="px-1.5 py-0.5 text-xs font-semibold bg-surface-card border border-hairline rounded dark:bg-gray-800 dark:border-gray-600">Enter</kbd>
                        <span class="mx-0.5">di field terakhir untuk menyimpan</span>
                    </div>
                    <x-secondary-button type="button" wire:click="closeModal">Tutup</x-secondary-button>
                </div>
            </div>

        </div>
    </x-modal>
</div>
