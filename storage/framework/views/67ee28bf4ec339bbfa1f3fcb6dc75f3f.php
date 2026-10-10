<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'disabled' => false,
    'error' => false,
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
    'disabled' => false,
    'error' => false,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    // Border & focus-ring SENGAJA dipisah dari $baseClass: di CSS hasil build,
    // `border-gray-300` berada SETELAH `border-error`, jadi kalau keduanya ikut
    // satu class (saat error), gray menang & border merah hilang. Maka pilih
    // salah satu — normal ATAU error — bukan menumpuk keduanya.
    $baseClass = 'bg-gray-50 text-gray-900 dark:bg-gray-900 dark:text-gray-100
        rounded-lg shadow-sm disabled:opacity-90 disabled:bg-gray-100 disabled:cursor-not-allowed w-full';

    // v2: fokus ring brand (green) di terang, lime di gelap
    $normalClass = 'border-gray-300 dark:border-gray-700
        focus:border-brand-green focus:ring-brand-green/40
        dark:focus:border-brand-lime dark:focus:ring-brand-lime/40';

    $errorClass = 'border-error focus:border-error focus:ring-error/40
        dark:border-error dark:focus:border-error dark:focus:ring-error/40';
?>

<input <?php if($disabled): echo 'disabled'; endif; ?>
    <?php echo e($attributes->merge([
        'class' => $error ? "$baseClass $errorClass" : "$baseClass $normalClass",
    ])); ?>>
<?php /**PATH /home/avro/Desktop/LARAVEL/siap/resources/views/components/text-input.blade.php ENDPATH**/ ?>