{{-- resources/views/components/text-input-number.blade.php --}}
{{--
    Input numerik dengan auto-format 999,999,999.
    Pakai wire:model seperti biasa — komponen otomatis handle konversi.

    Pemakaian:
      <x-text-input-number
          wire:model="basicSalary"
          :disabled="$isFormLocked"
          :error="$errors->has('basicSalary')"
          x-ref="inputBasicSalary"
          x-on:keydown.enter.prevent="$refs.inputRsAdmin?.focus()" />

      Berdesimal (persen, PPN) — sebutkan berapa angka di belakang koma:
      <x-text-input-number wire:model="pph21Persen" :decimals="2" />

    Catatan:
      - decimals = 0 (bawaan) -> nilai ke PHP selalu integer bersih
      - decimals > 0          -> nilai ke PHP float, titik sebagai pemisah desimal
      - wire:model.live TIDAK dipakai — sync dilakukan via $wire.set() saat blur
      - Initial value diambil otomatis dari $modelValue

    KENAPA ADA PROP decimals:
      Komponen ini semula integer-only — input di-strip /\D/g, jadi titik desimal
      ikut terbuang dan "2.5" tersimpan sebagai 25, sepuluh kali lipat. Akibatnya
      setiap field berdesimal di aplikasi (PPN penerimaan medis & non-medis, PPN
      master identitas, persen potongan RS & PPh 21 di modul gaji) terpaksa
      memakai <x-text-input inputmode="decimal"> polos dan kehilangan pemisah
      ribuan, rata kanan, serta font tabular. Tujuh tempat mengulang akal-akalan
      yang sama — itu tanda keterbatasan komponennya yang harus diperbaiki,
      bukan dicatat sebagai catatan kaki di tiap pemakainya.
--}}

@props([
    'disabled' => false,
    'error' => false,
    'extraBlur' => null,
    // Angka di belakang koma. 0 = integer (perilaku lama, tidak berubah).
    'decimals' => 0,
])

@php
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
    // Flowbite v4 (sama dengan x-text-input): latar/teks/border hanya di kelompok normal/galat.
    // w-full hanya bila pemakai tidak memberi lebar sendiri (class="w-28" dst.) — kalau ikut, w-full menang
    // urutan CSS dan lebar pemakai diabaikan (input bulan jadi selebar kolom).
    $lebarBawaan = preg_match('/(^|\s)(?:[a-z0-9]+:)*!?w-/', (string) $attributes->get('class')) ? '' : ' w-full';
    // Status terlihat beda (UX): AKTIF = latar putih + garis sage (default-strong, ≥3:1), hijau brand saat disorot/fokus;
    // NONAKTIF = latar abu lebih tegas + garis pudar, teks TETAP terbaca (text-body, 7,7:1), kursor dilarang; READ-ONLY (form terkunci) = latar abu + garis
    // putus-putus, teks tetap jelas. (Sebelumnya input aktif berlatar abu sehingga terlihat seperti nonaktif.)
    $baseClass = 'rounded-base shadow-xs disabled:bg-neutral-tertiary disabled:border-default disabled:text-body disabled:placeholder:text-gray-500 disabled:opacity-100 disabled:[-webkit-text-fill-color:currentColor] disabled:shadow-none disabled:cursor-not-allowed dark:disabled:bg-gray-700 dark:disabled:text-gray-300 enabled:read-only:bg-neutral-secondary-soft enabled:read-only:border-dashed enabled:read-only:shadow-none dark:enabled:read-only:bg-gray-800 input-num text-right' . $lebarBawaan;
    $normalClass = 'bg-neutral-primary text-heading border-default-strong placeholder:text-gray-500 dark:bg-gray-900 dark:placeholder:text-gray-400
        enabled:hover:border-brand/60 focus:ring-brand focus:border-brand transition-colors duration-150';
    $errorClass = 'bg-danger-soft text-fg-danger-strong border-danger-subtle placeholder:text-fg-danger-strong
        focus:ring-danger focus:border-danger';
@endphp

<input @disabled($disabled) value="{{ $initialValue }}" inputmode="{{ $desimal > 0 ? 'decimal' : 'numeric' }}"
    @if ($wireModel) x-init="$wire.$watch('{{ $wireModel }}', (val) => {
            if (document.activeElement === $el) return;
            let raw = ({{ $desimal }} > 0 ? parseFloat(val) : parseInt(val)) || 0;
            $el.value = raw > 0
                ? new Intl.NumberFormat('en-US', { maximumFractionDigits: {{ $desimal }} }).format(raw)
                : '';
        })" @endif
    x-on:focus="$el.value = $el.value.replace(/,/g, '')"
    {{-- Format ulang TANPA melempar kursor ke ujung.
         Menulis $el.value selalu memindahkan caret ke akhir, sehingga menyunting
         angka yang sudah ada jadi kacau: pada "12,000", menghapus angka 2 di
         tengah membuat kursor lompat ke belakang dan ketikan berikutnya
         menghasilkan "10,002".
         Caranya: hitung ADA BERAPA KARAKTER PENTING sebelum kursor, format ulang,
         lalu kembalikan kursor ke posisi setelah karakter penting ke-N yang sama.
         Pemisah ribuan yang bergeser tidak lagi menyeret kursor.

         "Karakter penting" = digit saja pada mode integer, digit DAN titik desimal
         pada mode desimal. Titik ikut dihitung karena posisinya tetap saat
         diformat ulang; kalau hanya digit yang dihitung, kursor yang berada tepat
         sesudah titik akan melompat ke sebelum titik.

         JANGAN diringkas. Panjangnya inilah yang menjaga posisi kursor —
         lihat memory feedback_caret_input_number_jangan_disederhanakan. --}}
    x-on:input="
        const desimal = {{ $desimal }};
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
        const desimal = {{ $desimal }};
        const tanpaPemisah = $el.value.replace(/,/g, '');
        let raw = (desimal > 0 ? parseFloat(tanpaPemisah) : parseInt(tanpaPemisah)) || 0;
        @if ($wireModel) $wire.set('{{ $wireModel }}', raw); @endif
        @if ($extraBlur) {!! $extraBlur !!}; @endif
        $el.value = raw > 0
            ? new Intl.NumberFormat('en-US', { maximumFractionDigits: desimal }).format(raw)
            : '';
    "
    {{ $attrs->merge([
        'class' => $error ? "$baseClass $errorClass" : "$baseClass $normalClass",
    ]) }}>
