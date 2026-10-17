{{-- Penunjuk langkah Absen HP: 1 ID → 2 Lokasi → 3 Selfie.
     aktif = angka (Blade), atau aktif-expr = ekspresi Alpine yang menghasilkan angka (dalam x-data induk). --}}
@props(['aktif' => 1, 'aktifExpr' => null])

@php $daftar = [1 => 'ID', 2 => 'Lokasi', 3 => 'Selfie']; @endphp

<ol class="flex items-center gap-2 text-xs font-semibold" aria-label="Langkah absen">
    @foreach ($daftar as $n => $label)
        @php $ekspr = $aktifExpr ? "($aktifExpr)" : null; @endphp
        <li class="flex items-center flex-1 gap-2 min-w-0"
            @if ($ekspr) x-bind:aria-current="{{ $ekspr }} === {{ $n }} ? 'step' : null" @elseif ($aktif === $n) aria-current="step" @endif>
            <span @if ($ekspr)
                    x-bind:class="{{ $ekspr }} >= {{ $n }} ? 'bg-brand text-white' : 'bg-neutral-tertiary text-body'"
                  @endif
                class="grid w-6 h-6 rounded-full place-items-center shrink-0 {{ $ekspr ? '' : ($aktif >= $n ? 'bg-brand text-white' : 'bg-neutral-tertiary text-body') }}">{{ $n }}</span>
            <span @if ($ekspr) x-bind:class="{{ $ekspr }} >= {{ $n }} ? 'text-heading' : 'text-muted'" @endif
                class="truncate {{ $ekspr ? '' : ($aktif >= $n ? 'text-heading' : 'text-muted') }}">{{ $label }}</span>
            @unless ($loop->last)
                <span class="flex-1 h-px bg-default-medium" aria-hidden="true"></span>
            @endunless
        </li>
    @endforeach
</ol>
