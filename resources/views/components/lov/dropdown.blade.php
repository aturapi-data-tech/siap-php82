@props(['id', 'isOpen' => false, 'selectedIndex' => 0])

<div class="w-full" x-data="{ id: @js($id) }" @click.outside="$wire.close()"
    x-on:lov-scroll.window="
        if ($event.detail.id !== id) return;
        requestAnimationFrame(() => {
            const i = $event.detail.index;
            const el = $refs['lovItem' + i];
            if (el) el.scrollIntoView({ block: 'nearest' });
        })
    ">
    {{ $slot }}
</div>
