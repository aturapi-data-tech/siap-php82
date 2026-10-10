
<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'action',
    'title' => 'Hapus Data',
    'message' => 'Apakah Anda yakin ingin menghapus data ini?',
    'confirmText' => 'Ya, hapus',
    'cancelText' => 'Batal',
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
    'action',
    'title' => 'Hapus Data',
    'message' => 'Apakah Anda yakin ingin menghapus data ini?',
    'confirmText' => 'Ya, hapus',
    'cancelText' => 'Batal',
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php if (isset($component)) { $__componentOriginalcfec3fb7ff3aa43ac4f81d0c127ae593 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalcfec3fb7ff3aa43ac4f81d0c127ae593 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.confirm-button','data' => ['variant' => 'danger','action' => $action,'title' => $title,'message' => $message,'confirmText' => $confirmText,'cancelText' => $cancelText,'attributes' => $attributes->merge(['class' => 'px-2.5 py-1.5 text-sm'])]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('confirm-button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['variant' => 'danger','action' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($action),'title' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($title),'message' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($message),'confirmText' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($confirmText),'cancelText' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($cancelText),'attributes' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes->merge(['class' => 'px-2.5 py-1.5 text-sm']))]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
    </svg>
    <?php echo e($slot->isEmpty() ? 'Hapus' : $slot); ?>

 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalcfec3fb7ff3aa43ac4f81d0c127ae593)): ?>
<?php $attributes = $__attributesOriginalcfec3fb7ff3aa43ac4f81d0c127ae593; ?>
<?php unset($__attributesOriginalcfec3fb7ff3aa43ac4f81d0c127ae593); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalcfec3fb7ff3aa43ac4f81d0c127ae593)): ?>
<?php $component = $__componentOriginalcfec3fb7ff3aa43ac4f81d0c127ae593; ?>
<?php unset($__componentOriginalcfec3fb7ff3aa43ac4f81d0c127ae593); ?>
<?php endif; ?>
<?php /**PATH /home/avro/Desktop/LARAVEL/siap/resources/views/components/action-delete.blade.php ENDPATH**/ ?>