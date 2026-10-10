<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
'title' => '',
'align' => 'start',
'bgcolor' => 'bg-canvas',
'class' => '',
'titleClass' => '',
'padding' => 'p-4',
'collapsible' => false,
'open' => true,
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
'title' => '',
'align' => 'start',
'bgcolor' => 'bg-canvas',
'class' => '',
'titleClass' => '',
'padding' => 'p-4',
'collapsible' => false,
'open' => true,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
// Set alignment class
$alignClass = match($align) {
'center' => 'text-center',
'end' => 'text-right',
'right' => 'text-right',
default => 'text-left',
};
?>


<div <?php echo e($attributes->merge(['class' => "border border-hairline rounded-2xl shadow-sm dark:border-gray-700 {$bgcolor} dark:bg-gray-900 {$class}"])); ?>

    <?php if($collapsible): ?> x-data="{ open: <?php echo \Illuminate\Support\Js::from($open)->toHtml() ?> }" <?php endif; ?>>
    <div class="<?php echo e($padding); ?>">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($title): ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($collapsible): ?>
                <button type="button" @click="open = !open"
                    class="flex items-center justify-between w-full gap-2 mb-4">
                    <span class="ds-form-title <?php echo e($alignClass); ?> <?php echo e($titleClass); ?>"><?php echo e($title); ?></span>
                    <svg class="w-4 h-4 text-muted transition-transform shrink-0" :class="open ? 'rotate-180' : ''"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>
                <div x-show="open" x-collapse>
                    <?php echo e($slot); ?>

                </div>
            <?php else: ?>
                <div class="ds-form-title mb-4 <?php echo e($alignClass); ?> <?php echo e($titleClass); ?>"><?php echo e($title); ?></div>
                <?php echo e($slot); ?>

            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php else: ?>
            <?php echo e($slot); ?>

        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
</div>
<?php /**PATH /home/avro/Desktop/LARAVEL/siap/resources/views/components/border-form.blade.php ENDPATH**/ ?>