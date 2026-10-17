@props(['messages'])

@if ($messages)
    {{-- Flowbite v4: helper galat = fg-danger-strong (ganti otomatis di mode gelap). --}}
    <ul {{ $attributes->merge(['class' => 'text-sm text-fg-danger-strong space-y-1']) }}>
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
