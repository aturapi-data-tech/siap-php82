{{--
    x-signature.signature-pad

    Props:
      - wireMethod  (string) : method Livewire penerima base64 dataURL  [default: setSignature]
      - width       (int)    : lebar canvas                              [default: 460]
      - height      (int)    : tinggi canvas                             [default: 180]

    Pemakaian:
        <x-signature.signature-pad wireMethod="setSignature" />
        <x-signature.signature-pad wireMethod="setSignatureSaksi" />
--}}
@props([
    'wireMethod' => 'setSignature',
    'width' => 460,
    'height' => 180,
])

<div x-data="{
    ctx: null,
    drawing: false,
    lastX: 0,
    lastY: 0,

    init() {
        const canvas = this.$refs.canvas; // Use x-ref for reliable reference
        this.ctx = canvas.getContext('2d');
        this.ctx.strokeStyle = '#1f2937';
        this.ctx.lineWidth = 2;
        this.ctx.lineCap = 'round';

        const getPos = (e) => {
            const rect = canvas.getBoundingClientRect();
            const src = e.touches ? e.touches[0] : e;
            return {
                x: (src.clientX - rect.left) * (canvas.width / rect.width),
                y: (src.clientY - rect.top) * (canvas.height / rect.height),
            };
        };

        canvas.addEventListener('mousedown', (e) => {
            this.drawing = true;
            const p = getPos(e);
            this.lastX = p.x;
            this.lastY = p.y;
        });
        canvas.addEventListener('mousemove', (e) => {
            if (!this.drawing) return;
            const p = getPos(e);
            this.ctx.beginPath();
            this.ctx.moveTo(this.lastX, this.lastY);
            this.ctx.lineTo(p.x, p.y);
            this.ctx.stroke();
            this.lastX = p.x;
            this.lastY = p.y;
        });
        canvas.addEventListener('mouseup', () => { this.drawing = false; });
        canvas.addEventListener('mouseleave', () => { this.drawing = false; });
        canvas.addEventListener('touchstart', (e) => {
            e.preventDefault();
            this.drawing = true;
            const p = getPos(e);
            this.lastX = p.x;
            this.lastY = p.y;
        }, { passive: false });
        canvas.addEventListener('touchmove', (e) => {
            if (!this.drawing) return;
            e.preventDefault();
            const p = getPos(e);
            this.ctx.beginPath();
            this.ctx.moveTo(this.lastX, this.lastY);
            this.ctx.lineTo(p.x, p.y);
            this.ctx.stroke();
            this.lastX = p.x;
            this.lastY = p.y;
        }, { passive: false });
        canvas.addEventListener('touchend', () => { this.drawing = false; });
    },

    clear() {
        const canvas = this.$refs.canvas;
        this.ctx.clearRect(0, 0, canvas.width, canvas.height);
    },

    save() {
        const canvas = this.$refs.canvas;
        this.$wire.{{ $wireMethod }}(canvas.toDataURL('image/png'));
    }
}">
    <div class="border-2 border-dashed border-gray-300 rounded-xl overflow-hidden dark:border-gray-600 bg-white">
        <canvas x-ref="canvas" width="{{ $width }}" height="{{ $height }}"
            class="w-full touch-none cursor-crosshair block"></canvas>
    </div>

    <p class="mt-1 text-xs text-center text-gray-400">Tanda tangani di dalam kotak di atas</p>

    <div class="flex gap-2 mt-3">
        <x-secondary-button type="button" x-on:click="clear()" class="text-xs gap-1">
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
            Bersihkan
        </x-secondary-button>

        <x-primary-button type="button" x-on:click="save()" class="text-xs gap-1">
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            Gunakan TTD ini
        </x-primary-button>
    </div>
</div>
