<?php use \Illuminate\Support\Str; ?>

<aside x-cloak id="app-sidebar"
    class="fixed top-20 left-0 z-[60] h-[calc(100vh-5rem)] w-80 max-w-[85vw] overflow-hidden flex flex-col
           bg-canvas dark:bg-gray-900 border-r border-hairline dark:border-gray-700
           transform transition-transform duration-300 ease-out"
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'">

    
    <img src="<?php echo e(asset('images/Logogram black solid.png')); ?>" alt=""
        class="absolute -left-44 -bottom-44 w-[40rem] opacity-[0.04] pointer-events-none select-none dark:invert" />

    
    <div class="relative z-10 flex items-center justify-between px-4 h-14">
        <div class="flex items-center gap-2.5">
            
            <span class="relative flex h-2.5 w-2.5">
                <span class="absolute inline-flex w-full h-full rounded-full opacity-60 bg-brand-green animate-ping"></span>
                <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-brand-green"></span>
            </span>

            
            <div class="leading-tight">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->guard()->check()): ?>
                    <div class="text-base font-semibold text-ink dark:text-gray-100"><?php echo e(auth()->user()->name); ?></div>
                    <div class="text-sm text-muted dark:text-gray-400"><?php echo e(auth()->user()->labelPeran() ?: 'User'); ?></div>
                <?php else: ?>
                    <div class="text-base font-semibold text-ink dark:text-gray-100">Guest</div>
                    <div class="text-sm text-muted dark:text-gray-400">Silakan login</div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </div>
    </div>

    
    <div class="relative z-10 h-[3px] w-24 mx-4 rounded-full bg-brand-lime"></div>

    
    <div class="relative z-10 px-3 pt-3 shrink-0">
        <?php if (isset($component)) { $__componentOriginal18c21970322f9e5c938bc954620c12bb = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal18c21970322f9e5c938bc954620c12bb = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.text-input','data' => ['xModel' => 'menuQuery','type' => 'search','placeholder' => 'Cari menu...','class' => 'w-full text-sm']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('text-input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['x-model' => 'menuQuery','type' => 'search','placeholder' => 'Cari menu...','class' => 'w-full text-sm']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal18c21970322f9e5c938bc954620c12bb)): ?>
<?php $attributes = $__attributesOriginal18c21970322f9e5c938bc954620c12bb; ?>
<?php unset($__attributesOriginal18c21970322f9e5c938bc954620c12bb); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal18c21970322f9e5c938bc954620c12bb)): ?>
<?php $component = $__componentOriginal18c21970322f9e5c938bc954620c12bb; ?>
<?php unset($__componentOriginal18c21970322f9e5c938bc954620c12bb); ?>
<?php endif; ?>
    </div>

    
    <?php
        $defaultOpenKey = null;
        if ($sidebarMenus->isNotEmpty()) {
            foreach ($sidebarMenus as $gName => $gItems) {
                if ($gItems->contains(fn($it) => request()->routeIs($it['route']))) {
                    $defaultOpenKey = Str::slug($gName);
                    break;
                }
            }
            $defaultOpenKey ??= Str::slug($sidebarMenus->keys()->first());
        }
    ?>

    <nav class="relative z-10 flex-1 min-h-0 p-3 space-y-1 overflow-y-auto"
        <?php if($defaultOpenKey): ?> x-init="if (Object.keys(openMenus).length === 0) openMenus['<?php echo e($defaultOpenKey); ?>'] = true" <?php endif; ?>>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = ($sidebarMenus ?? collect()); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $groupName => $items): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
            <?php
                $key = Str::slug($groupName);
                $isActiveGroup = $items->contains(fn($it) => request()->routeIs($it['route']));
                $groupText = Str::lower($groupName . ' ' . $items->map(fn($i) => ($i['title'] ?? '') . ' ' . ($i['desc'] ?? ''))->implode(' '));
            ?>

            <div class="pb-1 border-b border-hairline dark:border-gray-700"
                x-show="menuQuery === '' || <?php echo \Illuminate\Support\Js::from($groupText)->toHtml() ?>.includes(menuQuery.toLowerCase())">
                
                <button type="button"
                    class="flex items-center justify-between w-full gap-2 px-3 py-3 transition-colors duration-150 group rounded-lg hover:bg-brand-green/10 dark:hover:bg-brand-lime/10"
                    x-on:click="toggleMenu('<?php echo e($key); ?>')">
                    <span class="text-base truncate min-w-0 <?php echo e($isActiveGroup ? 'font-bold text-brand-green dark:text-brand-lime' : 'font-semibold text-body group-hover:text-ink dark:text-gray-300 dark:group-hover:text-white'); ?>">
                        <?php echo e($groupName); ?>

                    </span>
                    <svg class="w-4 h-4 transition-colors duration-150 shrink-0 <?php echo e($isActiveGroup ? 'text-brand-green dark:text-brand-lime' : 'text-muted group-hover:text-ink dark:text-gray-500 dark:group-hover:text-gray-200'); ?>"
                        :class="openMenus['<?php echo e($key); ?>'] ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 10 6">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 4 4 4-4" />
                    </svg>
                </button>

                
                <div x-cloak x-show="openMenus['<?php echo e($key); ?>'] || menuQuery.length > 0" x-collapse class="mt-0.5 space-y-0.5 pl-3">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $items; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                        <?php
                            $isActiveItem = request()->routeIs($item['route']);
                            $itemText = Str::lower(($item['title'] ?? '') . ' ' . ($item['desc'] ?? ''));
                        ?>
                        <a href="<?php echo e($item['href']); ?>" wire:navigate
                            x-show="menuQuery === '' || <?php echo \Illuminate\Support\Js::from($itemText)->toHtml() ?>.includes(menuQuery.toLowerCase())"
                            class="block px-3 py-2.5 rounded-lg transition-colors duration-150
                                   <?php echo e($isActiveItem ? 'bg-brand-green shadow-sm' : 'hover:bg-brand-green/10 dark:hover:bg-brand-lime/10'); ?>">
                            
                            <div class="text-base leading-snug <?php echo e($isActiveItem ? 'font-bold text-white' : 'font-medium text-body group-hover:text-brand-green dark:text-gray-300 dark:group-hover:text-brand-lime'); ?>">
                                <?php echo e($item['title']); ?>

                            </div>
                            
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($item['desc'])): ?>
                                <div class="text-sm leading-snug mt-0.5 line-clamp-2 <?php echo e($isActiveItem ? 'text-white/80' : 'text-muted dark:text-gray-400'); ?>">
                                    <?php echo e($item['desc']); ?>

                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </a>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                </div>
            </div>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
            <div class="px-4 py-8 text-sm text-center text-muted dark:text-gray-400">
                Tidak ada menu untuk role Anda.
            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    </nav>
</aside>
<?php /**PATH /home/avro/Desktop/LARAVEL/siap/resources/views/layouts/app-sidebar.blade.php ENDPATH**/ ?>