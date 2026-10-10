<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'type' => 'submit',
    'disabled' => false,
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
    'type' => 'submit',
    'disabled' => false,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<button type="<?php echo e($type); ?>" <?php echo e($disabled ? 'disabled' : ''); ?>

    <?php echo e($attributes->class([
        // layout — Flowbite: px-5 py-2.5 rounded-lg text-sm font-medium
        'inline-flex items-center justify-center gap-2',
        'px-5 py-2.5 rounded-lg',
        'text-sm font-medium',
        'transition-colors duration-150',
    
        // light mode
        'text-white bg-brand-green',
        'hover:bg-brand-green/90',
        'focus:outline-none focus:ring-4 focus:ring-brand-lime/40',
    
        // dark mode
        'dark:bg-brand-lime dark:text-gray-900',
        'dark:hover:bg-brand-lime/90',
        'dark:focus:ring-brand-green/40',
    
        // disabled
        'disabled:opacity-50',
        'disabled:cursor-not-allowed',
        'disabled:pointer-events-none',
    ])); ?>>
    <?php echo e($slot); ?>

</button>
<?php /**PATH /home/avro/Desktop/LARAVEL/siap/resources/views/components/primary-button.blade.php ENDPATH**/ ?>