<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'name',                                                    // modal name untuk open-modal listener
    'event',                                                   // *.saved event yang reset dirty
    'label',                                                   // teks form di warning
    'wireKey',                                                 // wire:key untuk container
    'saveEvents' => [],                                        // array nama event child yang akan di-broadcast saat 'Tutup dan Simpan' (pattern eresep-RJ: hindari $wire.save() collide dgn $wire.closeModal())
    'wrapperClass' => 'flex flex-col min-h-[calc(100vh-8rem)]', // override class wrapper bila perlu (mis. min-h-0)
]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter(([
    'name',                                                    // modal name untuk open-modal listener
    'event',                                                   // *.saved event yang reset dirty
    'label',                                                   // teks form di warning
    'wireKey',                                                 // wire:key untuk container
    'saveEvents' => [],                                        // array nama event child yang akan di-broadcast saat 'Tutup dan Simpan' (pattern eresep-RJ: hindari $wire.save() collide dgn $wire.closeModal())
    'wrapperClass' => 'flex flex-col min-h-[calc(100vh-8rem)]', // override class wrapper bila perlu (mis. min-h-0)
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>


<div class="<?php echo e($wrapperClass); ?>"
    <?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = ''.e($wireKey).''; ?>wire:key="<?php echo e($wireKey); ?>"
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

            const events = <?php echo \Illuminate\Support\Js::from($saveEvents)->toHtml() ?>;
            const savedEvent = '<?php echo e($event); ?>';

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
        window.addEventListener('<?php echo e($event); ?>', () => { dirty = false; openedAt = Date.now(); });
        window.addEventListener('open-modal', (e) => {
            if (e.detail && e.detail.name === '<?php echo e($name); ?>') {
                dirty = false;
                showUnsavedWarning = false;
                openedAt = Date.now();
            }
        });
    ">
    <?php echo e($slot); ?>


    
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
                            Ada perubahan di form <?php echo e($label); ?> yang belum disimpan. Simpan dulu, atau keluar tanpa menyimpan?
                        </p>
                    </div>
                    <?php if (isset($component)) { $__componentOriginal658398a0e73a18931bb7def04d911f42 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal658398a0e73a18931bb7def04d911f42 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.icon-button','data' => ['color' => 'gray','type' => 'button','xOn:click' => 'showUnsavedWarning = false','class' => 'shrink-0','ariaLabel' => 'Tutup']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('icon-button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['color' => 'gray','type' => 'button','x-on:click' => 'showUnsavedWarning = false','class' => 'shrink-0','aria-label' => 'Tutup']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd"
                                d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                                clip-rule="evenodd" />
                        </svg>
                     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal658398a0e73a18931bb7def04d911f42)): ?>
<?php $attributes = $__attributesOriginal658398a0e73a18931bb7def04d911f42; ?>
<?php unset($__attributesOriginal658398a0e73a18931bb7def04d911f42); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal658398a0e73a18931bb7def04d911f42)): ?>
<?php $component = $__componentOriginal658398a0e73a18931bb7def04d911f42; ?>
<?php unset($__componentOriginal658398a0e73a18931bb7def04d911f42); ?>
<?php endif; ?>
                </div>
                <div class="flex items-center justify-between gap-2 px-5 py-4 bg-gray-50/70 dark:bg-gray-900/20">
                    
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
                        <?php if (isset($component)) { $__componentOriginal3b0e04e43cf890250cc4d85cff4d94af = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal3b0e04e43cf890250cc4d85cff4d94af = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.secondary-button','data' => ['type' => 'button','xOn:click' => 'showUnsavedWarning = false','xBind:disabled' => 'savingAndClosing','title' => 'Lanjut edit form']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('secondary-button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','x-on:click' => 'showUnsavedWarning = false','x-bind:disabled' => 'savingAndClosing','title' => 'Lanjut edit form']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                            </svg>
                            Lanjut
                         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal3b0e04e43cf890250cc4d85cff4d94af)): ?>
<?php $attributes = $__attributesOriginal3b0e04e43cf890250cc4d85cff4d94af; ?>
<?php unset($__attributesOriginal3b0e04e43cf890250cc4d85cff4d94af); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal3b0e04e43cf890250cc4d85cff4d94af)): ?>
<?php $component = $__componentOriginal3b0e04e43cf890250cc4d85cff4d94af; ?>
<?php unset($__componentOriginal3b0e04e43cf890250cc4d85cff4d94af); ?>
<?php endif; ?>
                        <?php if (isset($component)) { $__componentOriginald411d1792bd6cc877d687758b753742c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald411d1792bd6cc877d687758b753742c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.primary-button','data' => ['type' => 'button','xOn:click' => 'saveAndClose()','xBind:disabled' => 'savingAndClosing','title' => 'Simpan lalu tutup']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('primary-button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','x-on:click' => 'saveAndClose()','x-bind:disabled' => 'savingAndClosing','title' => 'Simpan lalu tutup']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

                            <span x-show="!savingAndClosing" class="flex items-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                                Simpan
                            </span>
                            <span x-show="savingAndClosing" x-cloak class="flex items-center gap-1">
                                <?php if (isset($component)) { $__componentOriginal84bc13f46ede078ae58666238de3da00 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal84bc13f46ede078ae58666238de3da00 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.loading','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('loading'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal84bc13f46ede078ae58666238de3da00)): ?>
<?php $attributes = $__attributesOriginal84bc13f46ede078ae58666238de3da00; ?>
<?php unset($__attributesOriginal84bc13f46ede078ae58666238de3da00); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal84bc13f46ede078ae58666238de3da00)): ?>
<?php $component = $__componentOriginal84bc13f46ede078ae58666238de3da00; ?>
<?php unset($__componentOriginal84bc13f46ede078ae58666238de3da00); ?>
<?php endif; ?> Menyimpan...
                            </span>
                         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginald411d1792bd6cc877d687758b753742c)): ?>
<?php $attributes = $__attributesOriginald411d1792bd6cc877d687758b753742c; ?>
<?php unset($__attributesOriginald411d1792bd6cc877d687758b753742c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginald411d1792bd6cc877d687758b753742c)): ?>
<?php $component = $__componentOriginald411d1792bd6cc877d687758b753742c; ?>
<?php unset($__componentOriginald411d1792bd6cc877d687758b753742c); ?>
<?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php /**PATH /home/avro/Desktop/LARAVEL/siap/resources/views/components/dirty-modal-content.blade.php ENDPATH**/ ?>