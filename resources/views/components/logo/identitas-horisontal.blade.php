{{-- resources/views/components/logo/identitas-horisontal.blade.php --}}
{{-- Pemakaian: <x-logo.identitas-horisontal /> atau <x-logo.identitas-horisontal :showGaris="false" /> --}}
@props([
    'namaRs' => 'Rumah Sakit Islam Madinah',
    'alamat' => 'Jl. Jati Wayang, Lk. 2, Ngunut, Kec. Ngunut,<br>Kabupaten Tulungagung, Jawa Timur 66292',
    'telp' => '(0355) 396055',
    'fax' => '(0355) 396824',
    'website' => 'www.rsimadinah.com',
    'showGaris' => true,
])

<div class="w-full">
    <table class="w-full border-collapse">
        <tr>
            <td class="align-middle pr-3 w-20">
                <img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('images/Logo Persegi.png'))) }}"
                    alt="Logo {{ $namaRs }}" class="h-20 w-auto block">
            </td>
            <td class="align-middle text-left text-[9.5px] leading-snug text-gray-600">
                <div class="font-bold text-[13px] text-gray-900 mb-0.5">{{ $namaRs }}</div>
                <div>{!! $alamat !!}</div>
                <div>Telp. {{ $telp }}&ensp;Fax. {{ $fax }}</div>
                <div>{{ $website }}</div>
            </td>
        </tr>
    </table>

    @if ($showGaris)
        <div class="mt-3 border-t-[2.5px] border-green-700"></div>
        <div class="mt-0.5 border-t border-green-700"></div>
    @endif
</div>
