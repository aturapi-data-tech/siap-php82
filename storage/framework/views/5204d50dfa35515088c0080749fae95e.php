


<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'disabled' => false,
    'error' => false,
    'extraBlur' => null,
    // Angka di belakang koma. 0 = integer (perilaku lama, tidak berubah).
    'decimals' => 0,
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
    'extraBlur' => null,
    // Angka di belakang koma. 0 = integer (perilaku lama, tidak berubah).
    'decimals' => 0,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    // Ambil nama model dari wire:model (bisa 'basicSalary' atau 'data.nested.field')
    $wireModel = $attributes->whereStartsWith('wire:model')->first();

    // Ambil nilai awal dari komponen parent via $__livewire jika tersedia
    // Fallback ke value attribute jika ada
    $modelValue = null;
    if ($wireModel && isset($__livewire)) {
        try {
            $modelValue = data_get($__livewire, $wireModel);
        } catch (\Throwable) {
        }
    }
    $modelValue ??= $attributes->get('value');

    $desimal = max(0, (int) $decimals);

    // Mode integer memakai (int), BUKAN (float) yang dibulatkan — supaya nilai
    // yang sudah tersimpan tampil persis seperti sebelum prop ini ada.
    if (!$modelValue) {
        $initialValue = '';
    } elseif ($desimal === 0) {
        $initialValue = number_format((int) $modelValue, 0, '.', ',');
    } else {
        // Nol di belakang koma dibuang: 2,50 ditampilkan "2.5", 10,00 jadi "10".
        // rtrim aman di sini karena hanya dijalankan bila ada titiknya.
        $initialValue = number_format((float) $modelValue, $desimal, '.', ',');
        if (str_contains($initialValue, '.')) {
            $initialValue = rtrim(rtrim($initialValue, '0'), '.');
        }
    }

    // Strip wire:model dan value dari attributes — kita handle sendiri
    $attrs = $attributes->whereDoesntStartWith('wire:model')->whereDoesntStartWith('value');

    // Samakan dengan <x-text-input>. v2: fokus ring brand + angka pakai .input-num (mono renggang, tabular).
    // Border & focus-ring dipisah dari $baseClass supaya saat error tidak bentrok
    // dgn border-gray (border-gray-300 ada SETELAH border-error di CSS build → gray menang).
    $baseClass = 'bg-gray-50 text-gray-900 dark:bg-gray-900 dark:text-gray-100
        rounded-lg shadow-sm disabled:opacity-90 disabled:bg-gray-100 disabled:cursor-not-allowed w-full input-num text-right';
    $normalClass = 'border-gray-300 dark:border-gray-700
        focus:border-brand-green focus:ring-brand-green/40
        dark:focus:border-brand-lime dark:focus:ring-brand-lime/40';
    $errorClass = 'border-error focus:border-error focus:ring-error/40
        dark:border-error dark:focus:border-error dark:focus:ring-error/40';
?>

<input <?php if($disabled): echo 'disabled'; endif; ?> value="<?php echo e($initialValue); ?>" inputmode="<?php echo e($desimal > 0 ? 'decimal' : 'numeric'); ?>"
    <?php if($wireModel): ?> x-init="$wire.$watch('<?php echo e($wireModel); ?>', (val) => {
            if (document.activeElement === $el) return;
            let raw = (<?php echo e($desimal); ?> > 0 ? parseFloat(val) : parseInt(val)) || 0;
            $el.value = raw > 0
                ? new Intl.NumberFormat('en-US', { maximumFractionDigits: <?php echo e($desimal); ?> }).format(raw)
                : '';
        })" <?php endif; ?>
    x-on:focus="$el.value = $el.value.replace(/,/g, '')"
    
    x-on:input="
        const desimal = <?php echo e($desimal); ?>;
        const polaPenting = desimal > 0 ? /[\d.]/ : /\d/;
        const polaPentingSemua = desimal > 0 ? /[\d.]/g : /\d/g;

        const posisiSemula = $el.selectionStart;
        const pentingSebelumKursor = ($el.value.slice(0, posisiSemula).match(polaPentingSemua) || []).length;

        let terformat;
        if (desimal > 0) {
            const bersih = $el.value.replace(/[^\d.]/g, '');
            const potongan = bersih.split('.');
            const bulat = potongan.shift().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            terformat = bersih.includes('.')
                ? bulat + '.' + potongan.join('').slice(0, desimal)
                : bulat;
        } else {
            terformat = $el.value.replace(/\D/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }
        $el.value = terformat;

        let posisiBaru = 0;
        if (pentingSebelumKursor > 0) {
            let jumlahPenting = 0;
            for (posisiBaru = 0; posisiBaru < terformat.length; posisiBaru++) {
                if (polaPenting.test(terformat[posisiBaru])) jumlahPenting++;
                if (jumlahPenting === pentingSebelumKursor) { posisiBaru++; break; }
            }
        }
        $el.setSelectionRange(posisiBaru, posisiBaru);
    "
    x-on:blur="
        const desimal = <?php echo e($desimal); ?>;
        const tanpaPemisah = $el.value.replace(/,/g, '');
        let raw = (desimal > 0 ? parseFloat(tanpaPemisah) : parseInt(tanpaPemisah)) || 0;
        <?php if($wireModel): ?> $wire.set('<?php echo e($wireModel); ?>', raw); <?php endif; ?>
        <?php if($extraBlur): ?> <?php echo $extraBlur; ?>; <?php endif; ?>
        $el.value = raw > 0
            ? new Intl.NumberFormat('en-US', { maximumFractionDigits: desimal }).format(raw)
            : '';
    "
    <?php echo e($attrs->merge([
        'class' => $error ? "$baseClass $errorClass" : "$baseClass $normalClass",
    ])); ?>>
<?php /**PATH /home/avro/Desktop/LARAVEL/siap/resources/views/components/text-input-number.blade.php ENDPATH**/ ?>