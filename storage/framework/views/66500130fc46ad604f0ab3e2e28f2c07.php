


<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'trueValue' => 'Y',
    'falseValue' => 'N',
    'label' => null,
    'disabled' => false,
    'current' => null,    // override initial value (Mode 2 / per-row)
    'wireClick' => null,  // panggil method server saat klik (Mode 2)
    'onColor' => 'bg-brand', // warna track saat ON — mis. 'bg-error' utk penanda mendesak (CITO)
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
    'trueValue' => 'Y',
    'falseValue' => 'N',
    'label' => null,
    'disabled' => false,
    'current' => null,    // override initial value (Mode 2 / per-row)
    'wireClick' => null,  // panggil method server saat klik (Mode 2)
    'onColor' => 'bg-brand', // warna track saat ON — mis. 'bg-error' utk penanda mendesak (CITO)
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    // Ambil nama model dari wire:model
    $wireModel = $attributes->whereStartsWith('wire:model')->first();

    // Initial value: prioritas $current → wire:model lookup → falseValue
    $currentValue = null;
    if ($current !== null) {
        $currentValue = $current;
    } elseif ($wireModel && isset($__livewire)) {
        try {
            $currentValue = data_get($__livewire, $wireModel);
        } catch (\Throwable) {
        }
    }
    $currentValue ??= $falseValue;

    $isOn = $currentValue == $trueValue;
    $newValue = $isOn ? $falseValue : $trueValue;

    // Encode value untuk wire:click="$set(...)" expression
    $encode = fn($v) => match (true) {
        is_bool($v)    => $v ? 'true' : 'false',
        is_int($v),
        is_float($v)   => (string) $v,
        is_null($v)    => 'null',
        default        => "'" . addslashes((string) $v) . "'",
    };

    // Strip wire:model — kita handle sendiri via wire:click
    $attrs = $attributes->whereDoesntStartWith('wire:model');
?>

<div
    <?php if(!$disabled): ?>
        <?php if($wireModel): ?>
            wire:click="$set('<?php echo e($wireModel); ?>', <?php echo e($encode($newValue)); ?>)"
        <?php elseif($wireClick): ?>
            wire:click="<?php echo e($wireClick); ?>"
        <?php endif; ?>
    <?php endif; ?>
    class="flex items-center space-x-2 <?php echo e($disabled ? 'cursor-not-allowed opacity-90' : 'cursor-pointer'); ?>"
    <?php echo e($attrs); ?>>
    <div class="h-6 transition rounded-full w-11
        <?php echo e($isOn ? $onColor : 'bg-gray-300'); ?>">
        <div class="w-4 h-4 mt-1 transition transform bg-white rounded-full shadow
            <?php echo e($isOn ? 'translate-x-6 ml-1' : 'translate-x-1'); ?>">
        </div>
    </div>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($label): ?>
        <span class="block text-sm font-medium text-gray-700 dark:text-gray-300 <?php echo e($disabled ? 'opacity-90' : ''); ?>"><?php echo e($label); ?></span>
    <?php else: ?>
        <span class="block text-sm font-medium text-gray-700 dark:text-gray-300 <?php echo e($disabled ? 'opacity-90' : ''); ?>"><?php echo e($slot); ?></span>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH /home/avro/Desktop/LARAVEL/siap/resources/views/components/toggle.blade.php ENDPATH**/ ?>