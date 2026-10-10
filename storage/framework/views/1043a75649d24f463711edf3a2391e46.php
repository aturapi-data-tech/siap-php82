<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'variant' => 'gray', // brand | alternative | gray | danger | success | warning | info | purple
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
    'variant' => 'gray', // brand | alternative | gray | danger | success | warning | info | purple
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $variants = [
        // "brand" pakai hijau brand asli (bukan emerald)
        'brand' => 'bg-brand-green/10 text-brand-green dark:bg-brand-lime/15 dark:text-brand-lime',
        'alternative' => 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-200',
        'gray' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200',
        // v2: status pakai -tint (bg) + -deep (teks) untuk kontras & selaras brand
        'danger' => 'bg-error-tint text-error-deep dark:bg-red-900/30 dark:text-red-200',
        'success' => 'bg-success-tint text-success-deep dark:bg-green-900/30 dark:text-green-200',
        'warning' => 'bg-warning-tint text-warning-deep dark:bg-amber-900/30 dark:text-amber-200',
        'info' => 'bg-info-tint text-info-deep dark:bg-blue-900/30 dark:text-blue-200',
        'purple' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-200',
    ];

    $classes = $variants[$variant] ?? $variants['gray'];
?>

<span
    <?php echo e($attributes->merge([
        'class' => $classes . ' inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
    ])); ?>>
    <?php echo e($slot); ?>

</span>
<?php /**PATH /home/avro/Desktop/LARAVEL/siap/resources/views/components/badge.blade.php ENDPATH**/ ?>