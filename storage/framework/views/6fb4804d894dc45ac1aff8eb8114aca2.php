<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    // Label kolom di atas group (mis. "Aksi") — null/'' = tanpa label (default),
    // untuk toolbar yang elemen kanannya tidak berlabel.
    'label' => '',
    // Nama method Livewire untuk reset filter di komponen pemakai.
    'resetAction' => 'resetFilters',
    // true = sembunyikan teks (hanya ikon), padding lebih ringkas. Standar: icon-only.
    'iconOnly' => true,
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
    // Label kolom di atas group (mis. "Aksi") — null/'' = tanpa label (default),
    // untuk toolbar yang elemen kanannya tidak berlabel.
    'label' => '',
    // Nama method Livewire untuk reset filter di komponen pemakai.
    'resetAction' => 'resetFilters',
    // true = sembunyikan teks (hanya ikon), padding lebih ringkas. Standar: icon-only.
    'iconOnly' => true,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>


<div <?php echo e($attributes->class(['w-auto'])); ?>>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($label)): ?>
        <?php if (isset($component)) { $__componentOriginale3da9d84bb64e4bc2eeebaafabfb2581 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginale3da9d84bb64e4bc2eeebaafabfb2581 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.input-label','data' => ['value' => $label]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('input-label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($label)]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginale3da9d84bb64e4bc2eeebaafabfb2581)): ?>
<?php $attributes = $__attributesOriginale3da9d84bb64e4bc2eeebaafabfb2581; ?>
<?php unset($__attributesOriginale3da9d84bb64e4bc2eeebaafabfb2581); ?>
<?php endif; ?>
<?php if (isset($__componentOriginale3da9d84bb64e4bc2eeebaafabfb2581)): ?>
<?php $component = $__componentOriginale3da9d84bb64e4bc2eeebaafabfb2581; ?>
<?php unset($__componentOriginale3da9d84bb64e4bc2eeebaafabfb2581); ?>
<?php endif; ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <div
        class="<?php echo \Illuminate\Support\Arr::toCssClasses([
            'inline-flex items-stretch overflow-hidden bg-canvas border border-hairline divide-x divide-hairline rounded-lg shadow-sm dark:bg-gray-900 dark:border-gray-600 dark:divide-gray-600',
            'mt-1' => !empty($label),
        ]); ?>">
        <button type="button" wire:click="$refresh" title="Muat ulang data tanpa mengubah filter"
            class="<?php echo \Illuminate\Support\Arr::toCssClasses([
                'inline-flex items-center gap-1.5 py-2.5 text-sm font-medium text-blue-600 transition-colors duration-150 hover:bg-blue-50 focus:outline-none focus:bg-blue-50 dark:text-blue-400 dark:hover:bg-blue-900/20',
                'px-2.5' => $iconOnly,
                'px-4' => !$iconOnly,
            ]); ?>">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                wire:loading.class="animate-spin" wire:target="$refresh">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($iconOnly)): ?> Refresh <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </button>

        <button type="button" wire:click="<?php echo e($resetAction); ?>" title="Kembalikan semua filter ke kondisi awal"
            class="<?php echo \Illuminate\Support\Arr::toCssClasses([
                'inline-flex items-center gap-1.5 py-2.5 text-sm font-medium text-gray-600 transition-colors duration-150 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800',
                'px-2.5' => $iconOnly,
                'px-4' => !$iconOnly,
            ]); ?>">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" />
            </svg>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($iconOnly)): ?> Reset <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </button>
    </div>
</div>
<?php /**PATH /home/avro/Desktop/LARAVEL/siap/resources/views/components/toolbar-refresh-reset.blade.php ENDPATH**/ ?>