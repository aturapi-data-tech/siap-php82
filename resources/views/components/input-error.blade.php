@props(['messages'])

@if ($messages)
    {{-- text-error = warna error standar DS (#c64545), samakan dgn border-error input.
         dark:text-red-400 dijaga utk keterbacaan di latar gelap. --}}
    <ul {{ $attributes->merge(['class' => 'text-sm text-error dark:text-red-400 space-y-1']) }}>
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
