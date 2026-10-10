@props([
    'name',                                                    // modal name untuk open-modal listener
    'event',                                                   // *.saved event yang reset dirty
    'label',                                                   // teks form di warning
    'wireKey',                                                 // wire:key untuk container
    'saveEvents' => [],                                        // array nama event child yang akan di-broadcast saat 'Tutup dan Simpan' (pattern eresep-RJ: hindari $wire.save() collide dgn $wire.closeModal())
    'wrapperClass' => 'flex flex-col min-h-[calc(100vh-8rem)]', // override class wrapper bila perlu (mis. min-h-0)
])

{{-- CONTAINER UTAMA — dirty tracking pure-Alpine (no server dispatch) --}}
<div class="{{ $wrapperClass }}"
    wire:key="{{ $wireKey }}"
    x-data="{
        dirty: false,
        showUnsavedWarning: false,
        savingAndClosing: false,
        openedAt: 0,
        setDirty() {
            if (Date.now() - this.openedAt > 300) this.dirty = true;
        },
        tryClose() {
            if (this.dirty) {
                this.showUnsavedWarning = true;
            } else {
                $wire.closeModal();
            }
        },
        async saveAndClose() {
            // Pattern dari pelayanan-rj eresep — broadcast save events ke child via
            // Livewire.dispatch() (BUKAN $wire.save() yang masuk batch component), tunggu
            // semua child confirm via '*.saved' event, baru fire $wire.closeModal() di
            // batch terpisah. Hindari error 'A request already contains one of the
            // messages in this array'.
            if (this.savingAndClosing) return;
            this.savingAndClosing = true;

            const events = @js($saveEvents);
            const savedEvent = '{{ $event }}';

            try {
                if (events.length > 0) {
                    let saved = 0;
                    const onSaved = () => saved++;
                    window.addEventListener(savedEvent, onSaved);
                    try {
                        events.forEach(e => Livewire.dispatch(e));
                        // Tunggu sampai semua child confirm OR timeout 3 detik
                        // (fallback bila ada child gagal validasi → event tidak dipancarkan)
                        const deadline = Date.now() + 3000;
                        while (saved < events.length && Date.now() < deadline) {
                            await new Promise(r => setTimeout(r, 50));
                        }
                    } finally {
                        window.removeEventListener(savedEvent, onSaved);
                    }
                } else {
                    // Fallback: tanpa saveEvents, pakai $wire.save() biasa dengan delay
                    $wire.save();
                    await new Promise(r => setTimeout(r, 500));
                }

                this.showUnsavedWarning = false;
                this.dirty = false;
                await $wire.closeModal();
            } finally {
                this.savingAndClosing = false;
            }
        },
        discardAndClose() {
            // Buang perubahan: bersihkan flag dirty & tutup TANPA menyimpan.
            // State form di server di-reset oleh handler open/edit konsumen saat modal dibuka lagi.
            this.showUnsavedWarning = false;
            this.dirty = false;
            $wire.closeModal();
        },
    }"
    x-on:input="setDirty()"
    x-on:change="setDirty()"
    x-init="
        openedAt = Date.now();
        window.addEventListener('{{ $event }}', () => { dirty = false; openedAt = Date.now(); });
        window.addEventListener('open-modal', (e) => {
            if (e.detail && e.detail.name === '{{ $name }}') {
                dirty = false;
                showUnsavedWarning = false;
                openedAt = Date.now();
            }
        });
    ">
    {{ $slot }}

    {{-- KONFIRMASI TUTUP TANPA SIMPAN --}}
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
                        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Data belum disimpan</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Ada perubahan di form {{ $label }} yang belum disimpan. Simpan dulu, atau keluar tanpa menyimpan?
                        </p>
                    </div>
                    <x-icon-button color="gray" type="button" x-on:click="showUnsavedWarning = false"
                        class="shrink-0" aria-label="Tutup">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd"
                                d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                                clip-rule="evenodd" />
                        </svg>
                    </x-icon-button>
                </div>
                <div class="flex items-center justify-between gap-2 px-5 py-4 bg-gray-50/70 dark:bg-gray-900/20">
                    {{-- Aksi destruktif (buang perubahan) dipisah di kiri, gaya ghost-merah --}}
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
                            x-bind:disabled="savingAndClosing" title="Simpan lalu tutup">
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
</div>
