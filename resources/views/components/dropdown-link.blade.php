{{-- Flowbite v4 dropdown item --}}
<a {{ $attributes->merge(['class' => 'flex items-center w-full p-2 text-start text-sm font-medium rounded text-body hover:bg-neutral-tertiary-medium hover:text-heading focus:outline-none focus:bg-neutral-tertiary-medium dark:text-gray-300 dark:hover:text-white transition-colors duration-150']) }}>{{ $slot }}</a>
