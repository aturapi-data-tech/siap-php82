<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'type' => 'button',
    'color' => 'green', // green|lime|red|yellow|blue|gray
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
    'type' => 'button',
    'color' => 'green', // green|lime|red|yellow|blue|gray
    'disabled' => false,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $colors = [
        'green' =>
            'text-brand-green border-brand-green/30 hover:bg-brand-green/10 focus:ring-brand-green/20 dark:text-brand-lime dark:border-brand-lime/30 dark:hover:bg-brand-lime/10 dark:focus:ring-brand-lime/20',
        'lime' => 'text-brand-lime border-brand-lime/40 hover:bg-brand-lime/10 focus:ring-brand-lime/20',
        'red' =>
            'text-red-600 border-red-300 hover:bg-red-50 focus:ring-red-200 dark:text-red-400 dark:border-red-500/30 dark:hover:bg-red-500/10 dark:focus:ring-red-900',
        'yellow' =>
            'text-yellow-600 border-yellow-300 hover:bg-yellow-50 focus:ring-yellow-200 dark:text-yellow-400 dark:border-yellow-500/30 dark:hover:bg-yellow-500/10 dark:focus:ring-yellow-900',
        'blue' =>
            'text-blue-600 border-blue-300 hover:bg-blue-50 focus:ring-blue-200 dark:text-blue-400 dark:border-blue-500/30 dark:hover:bg-blue-500/10 dark:focus:ring-blue-900',
        'gray' =>
            'text-gray-500 border-gray-300 hover:bg-gray-100 focus:ring-gray-200 dark:text-gray-400 dark:border-gray-600 dark:hover:bg-gray-700 dark:focus:ring-gray-700',
    ];
?>

<button type="<?php echo e($type); ?>" <?php echo e($disabled ? 'disabled' : ''); ?>

    <?php echo e($attributes->class([
        // square — p-2 biar icon pas di tengah
        'inline-flex items-center justify-center',
        'p-2 rounded-lg',
        'text-sm',
        'border bg-transparent',
        'transition-colors duration-150',
        'focus:outline-none focus:ring-4',
    
        // color variant
        $colors[$color] ?? $colors['gray'],
    
        // disabled
        'disabled:opacity-50',
        'disabled:cursor-not-allowed',
        'disabled:pointer-events-none',
    ])); ?>>
    <?php echo e($slot); ?>

</button>
<?php /**PATH /home/avro/Desktop/LARAVEL/siap/resources/views/components/icon-button.blade.php ENDPATH**/ ?>