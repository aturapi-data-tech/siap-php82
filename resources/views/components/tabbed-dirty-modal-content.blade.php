@props([
    'name',                                                    // modal name untuk open-modal listener
    'savedEvent' => 'refresh-after-saved',                     // event yang reset dirty per section (sub-component dispatch ini setelah save sukses)
    'wireKey',                                                 // wire:key untuk container
    'tabs' => [],                                              // array tab config: [['key' => '...', 'label' => '...', 'saveEvent' => '...', 'reloadEvent' => '...'], ...]
    'reloadArg' => null,                                       // argumen (mis. riHdrNo) yg dikirim ke reloadEvent saat "buang perubahan"
    'initialTab' => null,                                      // tab default; null = ambil dari tabs[0]
    'guardSwitch' => true,                                     // false = pindah tab langsung tanpa dialog konfirmasi (titik dirty tetap jalan)
    'wrapperClass' => 'flex flex-col min-h-[calc(100vh-8rem)]',
])

@php
    $initial = $initialTab ?? ($tabs[0]['key'] ?? '');
@endphp

{{--
    Tabbed Dirty Modal Content — dirty tracking PER-TAB.
    Sub-component bertanggung jawab dispatch event:
        $dispatch('section-dirty', { tab: '<tab-key>' })   → tandai tab dirty
        $dispatch('section-clean', { tab: '<tab-key>' })   → tandai tab clean (biasanya setelah save sukses)

    Wrapper expose Alpine scope:
        activeTab               — tab key aktif (set via @click di tab nav)
        tabDirty[key]           — bool per tab
        saveMap[key]            — { label, saveEvent } per tab
        tryClose()              — dipanggil tombol Tutup; jika ada dirty → warning, else langsung closeModal
        saveActive()            — dispatch saveEvent untuk activeTab (dipanggil tombol Simpan footer)
        isAnyDirty()            — boolean
        dirtyTabLabels()        — list label tab yang dirty (untuk warning UI)
--}}

<div class="{{ $wrapperClass }}"
    wire:key="{{ $wireKey }}"
    x-data="{
        activeTab: @js($initial),
        tabDirty: @js(collect($tabs)->mapWithKeys(fn($t) => [$t['key'] => false])->all()),
        showUnsavedWarning: false,
        showSwitchWarning: false,
        pendingTab: null,
        savingAndClosing: false,
        savingAndSwitching: false,
        {{-- JANGAN pakai tanda kutip ganda di komentar ini: isi x-data ada di dalam atribut HTML,
             satu kutip ganda akan menutup atribut & sisa JS bocor jadi teks di halaman. --}}
        saveFailed: false,          // true = percobaan Simpan menyisakan tab yang belum tersimpan
        saveMap: @js(collect($tabs)->mapWithKeys(fn($t) => [$t['key'] => ['label' => $t['label'], 'saveEvent' => $t['saveEvent']]])->all()),
        reloadMap: @js(collect($tabs)->mapWithKeys(fn($t) => [$t['key'] => $t['reloadEvent'] ?? null])->all()),
        reloadArg: @js($reloadArg),

        markDirty(tab) {
            if (this.saveMap[tab]) this.tabDirty[tab] = true;
        },
        markClean(tab) {
            if (this.saveMap[tab]) this.tabDirty[tab] = false;
        },
        isAnyDirty() {
            return Object.values(this.tabDirty).some(v => v);
        },
        dirtyTabKeys() {
            return Object.entries(this.tabDirty).filter(([k, v]) => v).map(([k]) => k);
        },
        dirtyTabLabels() {
            return this.dirtyTabKeys().map(k => this.saveMap[k]?.label ?? k);
        },

        saveActive() {
            const ev = this.saveMap[this.activeTab]?.saveEvent;
            if (ev) Livewire.dispatch(ev);
        },

        /* ── Switch tab dengan guard dirty pada activeTab ──
           guardSwitch=false: pindah langsung. Perubahan tetap tersimpan di state Livewire
           tab asal (tidak di-reload dari DB), jadi titik dirty & isinya tetap ada saat balik. */
        guardSwitch: @js((bool) $guardSwitch),
        requestSwitchTab(targetTab) {
            if (targetTab === this.activeTab) return;
            if (this.guardSwitch && this.tabDirty[this.activeTab]) {
                this.pendingTab = targetTab;
                this.showSwitchWarning = true;
                return;
            }
            this.activeTab = targetTab;
        },
        cancelSwitch() {
            this.showSwitchWarning = false;
            this.pendingTab = null;
        },
        async saveAndSwitch() {
            if (this.savingAndSwitching) return;
            this.savingAndSwitching = true;
            try {
                const fromTab = this.activeTab;
                const ev = this.saveMap[fromTab]?.saveEvent;
                if (ev) {
                    let saved = 0;
                    const onSaved = () => saved++;
                    window.addEventListener('{{ $savedEvent }}', onSaved);
                    try {
                        Livewire.dispatch(ev);
                        const deadline = Date.now() + 3000;
                        while (saved < 1 && Date.now() < deadline) {
                            await new Promise(r => setTimeout(r, 50));
                        }
                    } finally {
                        window.removeEventListener('{{ $savedEvent }}', onSaved);
                    }
                }
                this.tabDirty[fromTab] = false;
                const target = this.pendingTab;
                this.pendingTab = null;
                this.showSwitchWarning = false;
                this.activeTab = target;
            } finally {
                this.savingAndSwitching = false;
            }
        },
        discardAndSwitch() {
            // Buang perubahan tab aktif TANPA menyimpan lalu pindah.
            // Revert data form via reloadEvent (reload dari DB), bukan sekadar reset flag,
            // supaya edit tak nyangkut & muncul lagi saat balik ke tab ini.
            const fromTab = this.activeTab;
            const reloadEv = this.reloadMap[fromTab];
            if (reloadEv && this.reloadArg) {
                Livewire.dispatch(reloadEv, { riHdrNo: this.reloadArg });
            }
            this.tabDirty[fromTab] = false;
            const target = this.pendingTab;
            this.pendingTab = null;
            this.showSwitchWarning = false;
            this.activeTab = target;
        },

        tryClose() {
            if (this.isAnyDirty()) {
                this.saveFailed = false;
                this.showUnsavedWarning = true;
            } else {
                $wire.closeModal();
            }
        },

        /* Simpan semua tab dirty lalu tutup — HANYA kalau semuanya benar-benar tersimpan.
           Tab yang gagal validasi tidak mengirim section-clean, jadi tetap dirty:
           modal dibiarkan terbuka & daftar tab tersisa ditampilkan, bukan ditutup diam-diam. */
        async saveAndClose() {
            if (this.savingAndClosing) return;
            this.savingAndClosing = true;
            this.saveFailed = false;
            try {
                const dirtyKeys = this.dirtyTabKeys();
                const events = dirtyKeys.map(k => this.saveMap[k]?.saveEvent).filter(Boolean);
                if (events.length > 0) {
                    events.forEach(e => Livewire.dispatch(e));
                    // Tunggu sampai tiap tab melapor bersih (section-clean → markClean),
                    // bukan sekadar menghitung event saved global.
                    const deadline = Date.now() + 5000;
                    while (this.dirtyTabKeys().length > 0 && Date.now() < deadline) {
                        await new Promise(r => setTimeout(r, 50));
                    }
                }
                if (this.isAnyDirty()) {
                    this.saveFailed = true;   // biarkan dialog terbuka, tampilkan sisa tab
                    return;
                }
                this.showUnsavedWarning = false;
                await $wire.closeModal();
            } finally {
                this.savingAndClosing = false;
            }
        },
        discardAndClose() {
            // Buang perubahan SEMUA tab & tutup TANPA menyimpan.
            // State form di server di-reset oleh handler open konsumen saat modal dibuka lagi.
            Object.keys(this.tabDirty).forEach(k => this.tabDirty[k] = false);
            this.showUnsavedWarning = false;
            $wire.closeModal();
        },
    }"
    x-init="
        window.addEventListener('open-modal', (e) => {
            if (e.detail && e.detail.name === '{{ $name }}') {
                Object.keys(tabDirty).forEach(k => tabDirty[k] = false);
                showUnsavedWarning = false;
                showSwitchWarning = false;
                pendingTab = null;
            }
        });
    "
    x-on:section-dirty="markDirty($event.detail.tab)"
    x-on:section-clean="markClean($event.detail.tab)">

    {{ $slot }}

    {{-- ══════════ KONFIRMASI TUTUP TANPA SIMPAN (per-tab) ══════════ --}}
    <div x-cloak x-show="showUnsavedWarning" class="fixed inset-0 z-[99]" role="dialog" aria-modal="true">
        <div class="fixed inset-0 bg-gray-900/50 dark:bg-gray-900/70"
            x-on:click="showUnsavedWarning = false"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="w-full max-w-md overflow-hidden bg-white border border-gray-200 shadow-2xl rounded-2xl dark:bg-gray-800 dark:border-gray-700"
                x-on:click.stop>
                <div class="flex items-start gap-3 px-5 py-4 border-b border-gray-200 dark:border-gray-700">
                    <div class="flex items-center justify-center w-10 h-10 rounded-full bg-amber-100 dark:bg-amber-900/30 shrink-0">
                        <svg class="w-5 h-5 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Ada tab belum disimpan</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Tab berikut punya perubahan belum disimpan:
                        </p>
                        {{-- List ke bawah — lebih kebaca saat tab yang dirty banyak --}}
                        <ul class="mt-2 space-y-1 text-sm">
                            <template x-for="label in dirtyTabLabels()" :key="label">
                                <li class="flex items-center gap-2 font-medium text-gray-700 dark:text-gray-300">
                                    <span class="inline-block w-1.5 h-1.5 rounded-full bg-amber-500 shrink-0"></span>
                                    <span x-text="label"></span>
                                </li>
                            </template>
                        </ul>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400" x-show="!saveFailed">
                            Simpan semua, atau keluar tanpa menyimpan?
                        </p>
                        {{-- Muncul kalau tombol Simpan sudah ditekan tapi masih ada tab yang gagal tersimpan
                             (mis. validasi belum lengkap). Modal sengaja tidak ditutup. --}}
                        <p x-show="saveFailed" x-cloak
                            class="p-2 mt-2 text-sm border rounded-lg text-error bg-error/5 border-error/20">
                            Tab di atas <strong>gagal disimpan</strong>. Buka tab tersebut dan cek pesan validasi
                            (field wajib berwarna merah), lalu Simpan lagi — atau pilih Keluar untuk membuang perubahan.
                        </p>
                    </div>
                </div>
                <div class="flex items-center justify-between gap-2 px-5 py-4 bg-gray-50/70 dark:bg-gray-900/20">
                    {{-- Aksi destruktif (buang semua perubahan) dipisah di kiri, gaya ghost-merah --}}
                    <button type="button" x-on:click="discardAndClose()" x-bind:disabled="savingAndClosing"
                        title="Keluar tanpa menyimpan perubahan"
                        class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 rounded-lg text-sm font-medium transition-colors duration-150
                               text-error bg-error/5 border border-error/20 hover:bg-error/10 hover:border-error/30
                               focus:outline-none focus:ring-4 focus:ring-error/20
                               disabled:opacity-50 disabled:cursor-not-allowed disabled:pointer-events-none">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 5v1a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h6a2 2 0 012 2v1" />
                        </svg>
                        Keluar
                    </button>
                    <div class="flex items-center gap-2">
                        <x-secondary-button type="button" x-on:click="showUnsavedWarning = false"
                            x-bind:disabled="savingAndClosing" title="Lanjut edit form">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                            </svg>
                            Lanjut
                        </x-secondary-button>
                        <x-primary-button type="button" x-on:click="saveAndClose()"
                            x-bind:disabled="savingAndClosing" title="Simpan semua lalu tutup">
                            <span x-show="!savingAndClosing" class="flex items-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                                Simpan
                            </span>
                            <span x-show="savingAndClosing" x-cloak class="flex items-center gap-1">
                                <x-loading /> Menyimpan...
                            </span>
                        </x-primary-button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ══════════ KONFIRMASI GANTI TAB TANPA SIMPAN ══════════ --}}
    <div x-cloak x-show="showSwitchWarning" class="fixed inset-0 z-[99]" role="dialog" aria-modal="true">
        <div class="fixed inset-0 bg-gray-900/50 dark:bg-gray-900/70"
            x-on:click="cancelSwitch()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="w-full max-w-md overflow-hidden bg-white border border-gray-200 shadow-2xl rounded-2xl dark:bg-gray-800 dark:border-gray-700"
                x-on:click.stop>
                <div class="flex items-start gap-3 px-5 py-4 border-b border-gray-200 dark:border-gray-700">
                    <div class="flex items-center justify-center w-10 h-10 rounded-full bg-amber-100 dark:bg-amber-900/30 shrink-0">
                        <svg class="w-5 h-5 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Tab ada perubahan belum disimpan</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Tab <span class="font-medium text-gray-700 dark:text-gray-300" x-text="saveMap[activeTab]?.label ?? activeTab"></span>
                            punya perubahan belum disimpan. Simpan dulu sebelum pindah ke
                            <span class="font-medium text-gray-700 dark:text-gray-300" x-text="saveMap[pendingTab]?.label ?? pendingTab"></span>?
                        </p>
                    </div>
                </div>
                <div class="flex items-center justify-between gap-2 px-5 py-4 bg-gray-50/70 dark:bg-gray-900/20">
                    {{-- Aksi destruktif (buang perubahan tab aktif) dipisah di kiri, gaya ghost-merah --}}
                    <button type="button" x-show="reloadMap[activeTab]" x-on:click="discardAndSwitch()"
                        x-bind:disabled="savingAndSwitching" title="Pindah tanpa menyimpan perubahan tab ini"
                        class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 rounded-lg text-sm font-medium transition-colors duration-150
                               text-error bg-error/5 border border-error/20 hover:bg-error/10 hover:border-error/30
                               focus:outline-none focus:ring-4 focus:ring-error/20
                               disabled:opacity-50 disabled:cursor-not-allowed disabled:pointer-events-none">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 5v1a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h6a2 2 0 012 2v1" />
                        </svg>
                        Pindah tanpa simpan
                    </button>
                    <div class="flex items-center gap-2">
                        <x-secondary-button type="button" x-on:click="cancelSwitch()"
                            x-bind:disabled="savingAndSwitching">
                            Batal
                        </x-secondary-button>
                        <x-primary-button type="button" x-on:click="saveAndSwitch()"
                            x-bind:disabled="savingAndSwitching">
                            <span x-show="!savingAndSwitching">Simpan &amp; Pindah</span>
                            <span x-show="savingAndSwitching" x-cloak class="flex items-center gap-1">
                                <x-loading /> Menyimpan...
                            </span>
                        </x-primary-button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
