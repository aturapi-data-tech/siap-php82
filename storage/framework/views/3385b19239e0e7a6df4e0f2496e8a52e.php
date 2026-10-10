<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'variant' => 'danger', // danger|danger-soft|warning-soft|primary|secondary|outline
    'action', // contoh: "delete(10)" atau "delete('10')"
    'title' => 'Konfirmasi',
    'message' => 'Apakah Anda yakin?',
    'confirmText' => 'Ya',
    'cancelText' => 'Batal',
    'disabled' => false,
    'wireTarget' => null, // default: nama method dari $action — override bila perlu target lain
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
    'variant' => 'danger', // danger|danger-soft|warning-soft|primary|secondary|outline
    'action', // contoh: "delete(10)" atau "delete('10')"
    'title' => 'Konfirmasi',
    'message' => 'Apakah Anda yakin?',
    'confirmText' => 'Ya',
    'cancelText' => 'Batal',
    'disabled' => false,
    'wireTarget' => null, // default: nama method dari $action — override bila perlu target lain
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    // id unik supaya aman dipakai berulang di table
    $confirmId = 'confirm_' . md5($action . '|' . ($attributes->get('wire:key') ?? '') . '|' . uniqid('', true));

    // Target wire:loading — cukup nama method (tanpa argumen) supaya aman dari
    // tanda kutip di $action. Konsekuensi: semua trigger ber-method sama ikut
    // disabled selama proses; justru mencegah aksi ganda dari baris lain.
    $loadingTarget = $wireTarget ?? \Illuminate\Support\Str::before($action, '(');

    // class trigger button — disesuaikan dengan komponen button standar
    $base = 'inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium whitespace-nowrap transition-colors duration-150';

    $triggerButtonClass = match ($variant) {
        'primary'
            => $base . ' text-white bg-brand-green hover:bg-brand-green/90 focus:outline-none focus:ring-4 focus:ring-brand-lime/40 dark:bg-brand-lime dark:text-gray-900 dark:hover:bg-brand-lime/90',
        'secondary'
            => $base . ' text-gray-700 bg-gray-100 border border-gray-300 hover:bg-gray-200 hover:text-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-gray-700',
        'outline'
            => $base . ' text-brand-green bg-brand-green/10 border border-brand-green/30 hover:bg-brand-green hover:text-white hover:border-brand-green focus:outline-none focus:ring-4 focus:ring-brand-green/20 dark:text-brand-lime dark:bg-brand-lime/10 dark:border-brand-lime/30 dark:hover:bg-brand-lime dark:hover:text-gray-900',
        // Merah bertint (bukan solid) — tampilan tombol hapus ikon di dalam tabel/form,
        // seperti di e-resep. Dibuat jadi VARIAN supaya pemakai tak perlu override
        // `!important` (dilarang Aturan Umum standar-ui-komponen.md).
        // Kuning lembut — aksi KOREKSI (Buka Kunci, batal proses): berbeda dari merah hapus supaya
        // dua tombol berisiko di satu sel tidak tampak sama.
        'warning-soft'
            => $base . ' text-amber-700 bg-amber-50 border border-amber-200 hover:bg-amber-100 hover:text-amber-700 hover:border-amber-300 focus:outline-none focus:ring-4 focus:ring-amber-200 dark:text-amber-400 dark:bg-amber-900/20 dark:border-amber-800/30 dark:hover:bg-amber-900/30 dark:hover:text-amber-300 dark:focus:ring-amber-900',
        'danger-soft'
            => $base . ' text-red-600 bg-red-50 border border-red-200 hover:bg-red-100 hover:text-red-700 hover:border-red-300 focus:outline-none focus:ring-4 focus:ring-red-200 dark:text-red-400 dark:bg-red-900/20 dark:border-red-800/30 dark:hover:bg-red-900/30 dark:hover:text-red-300 dark:focus:ring-red-900',
        default
            => $base . ' text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-4 focus:ring-red-300 dark:bg-red-600 dark:hover:bg-red-700 dark:focus:ring-red-900',
    };
?>

<div x-data="{
    show: false,
    open() {
        this.show = true;
        document.body.classList.add('overflow-hidden');
        this.$nextTick(() => {
            // fokus ke tombol confirm biar enak keyboard
            this.$refs.confirmButton?.focus();
        });
    },
    close() {
        this.show = false;
        document.body.classList.remove('overflow-hidden');
    },
    confirm() {
        this.close();
        $wire.<?php echo e($action); ?>;
    }
}" x-on:keydown.escape.window="if (show) close()" class="inline-block">
    
    <button type="button" <?php if($disabled): echo 'disabled'; endif; ?> x-on:click="open()" wire:loading.attr="disabled"
        wire:target="<?php echo e($loadingTarget); ?>"
        <?php echo e($attributes->merge(['class' => $triggerButtonClass . ' disabled:opacity-60 disabled:cursor-not-allowed'])); ?>>
        <span wire:loading.remove wire:target="<?php echo e($loadingTarget); ?>" class="inline-flex items-center gap-2">
            <?php echo e($slot); ?>

        </span>
        <span wire:loading wire:target="<?php echo e($loadingTarget); ?>" class="inline-flex items-center gap-2">
            <?php if (isset($component)) { $__componentOriginal84bc13f46ede078ae58666238de3da00 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal84bc13f46ede078ae58666238de3da00 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.loading','data' => ['class' => 'w-4 h-4']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('loading'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'w-4 h-4']); ?>
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
<?php endif; ?>
            Memproses...
        </span>
    </button>

    
    <div x-cloak x-show="show" class="fixed inset-0 z-[90]" aria-labelledby="<?php echo e($confirmId); ?>_title" role="dialog"
        aria-modal="true">
        
        <div class="fixed inset-0 bg-gray-900/50 dark:bg-gray-900/70" x-on:click="close()"
            x-transition:enter="ease-out duration-150" x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-120"
            x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"></div>

        
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="w-full max-w-md overflow-hidden bg-white border border-gray-200 shadow-2xl rounded-2xl dark:bg-gray-800 dark:border-gray-700"
                x-on:click.stop x-transition:enter="ease-out duration-150"
                x-transition:enter-start="opacity-0 translate-y-2 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="ease-in duration-120"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 translate-y-2 sm:scale-95">
                
                <div class="flex items-start justify-between px-5 py-4 border-b border-gray-200 dark:border-gray-700">
                    <div>
                        <h3 id="<?php echo e($confirmId); ?>_title"
                            class="text-base font-semibold text-gray-900 dark:text-gray-100">
                            <?php echo e($title); ?>

                        </h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            <?php echo e($message); ?>

                        </p>
                    </div>

                    <button type="button"
                        class="inline-flex items-center justify-center text-gray-500 rounded-lg w-9 h-9 hover:text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700"
                        x-on:click="close()" aria-label="Tutup">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd"
                                d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                                clip-rule="evenodd" />
                        </svg>
                    </button>
                </div>

                
                <div class="flex items-center justify-end gap-2 px-5 py-4 bg-gray-50/70 dark:bg-gray-900/20">
                    <?php if (isset($component)) { $__componentOriginal3b0e04e43cf890250cc4d85cff4d94af = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal3b0e04e43cf890250cc4d85cff4d94af = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.secondary-button','data' => ['type' => 'button','xOn:click' => 'close()']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('secondary-button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','x-on:click' => 'close()']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

                        <?php echo e($cancelText); ?>

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

                    <?php if (isset($component)) { $__componentOriginal656e8c5ea4d9a4fa173298297bfe3f11 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal656e8c5ea4d9a4fa173298297bfe3f11 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.danger-button','data' => ['type' => 'button','xRef' => 'confirmButton','xOn:click' => 'confirm()']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('danger-button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','x-ref' => 'confirmButton','x-on:click' => 'confirm()']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

                        <?php echo e($confirmText); ?>

                     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal656e8c5ea4d9a4fa173298297bfe3f11)): ?>
<?php $attributes = $__attributesOriginal656e8c5ea4d9a4fa173298297bfe3f11; ?>
<?php unset($__attributesOriginal656e8c5ea4d9a4fa173298297bfe3f11); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal656e8c5ea4d9a4fa173298297bfe3f11)): ?>
<?php $component = $__componentOriginal656e8c5ea4d9a4fa173298297bfe3f11; ?>
<?php unset($__componentOriginal656e8c5ea4d9a4fa173298297bfe3f11); ?>
<?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php /**PATH /home/avro/Desktop/LARAVEL/siap/resources/views/components/confirm-button.blade.php ENDPATH**/ ?>