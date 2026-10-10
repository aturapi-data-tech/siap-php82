@props([
    'wireModel',                       // path TEKS — selalu diikat wire:model, jadi ketikan tersimpan apa adanya
    'options' => [],                   // string[]  ATAU  array{id,nama,kelas,jenis}[]
    'wireModelId' => null,             // opsional: path id, ikut terisi saat teks cocok dengan daftar
    'wireModelJenis' => null,          // opsional: path jenis — WAJIB bila daftarnya campur dua master
    'disabled' => false,
    'placeholder' => 'Ketik untuk mencari…',
    'inputId' => null,                 // id input — dipakai induk untuk focus via document.getElementById
    'enterAction' => null,             // ekspresi Alpine saat Enter & tak ada baris tersorot
    'maxlength' => 255,
    'error' => false,
    'judulDaftar' => 'daftar',         // title tombol chevron: "Lihat <judulDaftar>"
])

{{--
    COMBOBOX BAKU — satu perilaku untuk semua combobox ketik-saring di aplikasi ini.
    Pemakai TIDAK memanggilnya langsung; ia dipakai lewat pembungkus yang membawa
    sumber datanya sendiri:

      <x-catatan-signa-combobox>     catatan signa e-resep      teks
      <x-ppa-combobox>               nama PPA dari tabel users  teks
      <x-rekonsiliasi-obat-combobox> obat bawaan pasien         teks
      <x-ruangan-combobox>           ruangan/poli dari master   teks + id

    ATURAN POKOK: yang diketik petugas ADALAH nilainya. Daftar itu bantuan ketik, bukan
    pagar. Isian di luar daftar tetap sah dan tersimpan apa adanya — obat bawaan pasien
    dari luar RS, catatan signa tak baku, ruangan yang belum terdaftar di master.
    Komponen ini TIDAK PERNAH mengubah atau mengembalikan ketikan petugas.

    `wireModelId` menambah SATU hal saja di atas itu: selama teks di kotak cocok persis
    dengan salah satu baris daftar, id-nya ikut disimpan; begitu teksnya menyimpang, id
    dikosongkan. Jadi id itu bonus (tautan ke master kalau kebetulan cocok), bukan syarat —
    dan tak pernah ada id basi yang menunjuk baris lain dari yang tertulis. Induk yang
    memakai id ini WAJIB memperlakukannya opsional: yang wajib diisi mestinya TEKS-nya.

    Enter TIDAK memilih baris kecuali ada yang tersorot (lewat panah/hover) — ia
    menjalankan `enterAction`, biasanya "tambah baris"/"simpan", karena yang diketik sudah
    jadi nilainya. Menyalakan sorot-otomatis akan MEREBUT Enter dari `enterAction` di
    6 form (rekonsiliasi obat, pelaporan ESO) — jangan.

    Daftar dibekukan ke x-data saat render; pembungkusnya yang bertanggung jawab men-cache
    query master supaya tidak di-query ulang tiap render Livewire.
--}}
@php
    // Satu bentuk data: string dinormalkan jadi opsi ber-id = namanya sendiri, sehingga
    // sisa komponen tak perlu tahu lagi daftarnya datang dari mana.
    $opsi = [];
    foreach ($options as $o) {
        if (is_array($o)) {
            $opsi[] = [
                'id' => (string) ($o['id'] ?? ''),
                'nama' => (string) ($o['nama'] ?? ''),
                'kelas' => (string) ($o['kelas'] ?? ''),
                'jenis' => (string) ($o['jenis'] ?? ''),
            ];
            continue;
        }
        $nama = trim((string) $o);
        $opsi[] = ['id' => $nama, 'nama' => $nama, 'kelas' => '', 'jenis' => ''];
    }

    // Border DIPISAH dari baseClass: kalau border-gray ikut baseClass lalu border-red
    // ditempel lewat merge, yang menang ditentukan urutan CSS — penanda error bisa
    // tidak kelihatan sama sekali.
    $borderClass = $error
        ? 'border-red-500 dark:border-red-500 focus:border-red-500 focus:ring-red-500'
        : 'border-gray-300 dark:border-gray-700 focus:border-brand-lime focus:ring-brand-lime';
@endphp

<div class="relative w-full"
    x-data="{
        allOptions: @js($opsi),
        filtered: [],
        open: false,
        highlighted: -1,
        teksModel: @js($wireModel),
        idModel: @js($wireModelId),
        jenisModel: @js($wireModelJenis),

        init() { this.filter(this.$refs.cbInput ? this.$refs.cbInput.value : ''); },

        teks(opt) { return opt.kelas ? opt.nama + ' — ' + opt.kelas : opt.nama; },

        filter(q) {
            const lq = (q ?? '').toString().toLowerCase().trim();
            this.filtered = lq.length === 0
                ? this.allOptions.slice(0, 50)
                : this.allOptions.filter(o =>
                    (o.nama ?? '').toLowerCase().includes(lq)
                    || (o.id ?? '').toLowerCase().includes(lq)
                    || (o.kelas ?? '').toLowerCase().includes(lq)
                  ).slice(0, 50);
        },

        openDropdown() {
            this.filter(this.$refs.cbInput ? this.$refs.cbInput.value : '');
            this.open = this.filtered.length > 0;
            this.highlighted = -1;
        },

        onInput() {
            const cur = this.$refs.cbInput ? this.$refs.cbInput.value : '';
            this.filter(cur);
            this.open = this.filtered.length > 0;
            // tidak auto-highlight; user explicit via arrow / hover
            this.highlighted = -1;
            this.tautkanId(cur);
        },

        // Id mengikuti teks, bukan sebaliknya: cocok persis → id ikut, menyimpang → id
        // dikosongkan. Ketikan petugas TIDAK disentuh apa pun hasilnya.
        tautkanId(teksSekarang) {
            if (!this.idModel) return;
            const q = (teksSekarang ?? '').toString().trim().toLowerCase();
            const cocok = q === '' ? null : this.allOptions.find(o => (o.nama ?? '').toLowerCase() === q);
            this.simpanId(cocok);
        },

        simpanId(opt) {
            if (!this.idModel) return;
            this.$wire.set(this.idModel, opt ? opt.id : '', false);
            if (this.jenisModel) { this.$wire.set(this.jenisModel, opt ? opt.jenis : '', false); }
        },

        clearValue() {
            this.$wire.set(this.teksModel, '', false);
            this.simpanId(null);
            if (this.$refs.cbInput) {
                this.$refs.cbInput.value = '';
                this.$refs.cbInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
            this.open = false;
            this.highlighted = -1;
            this.$nextTick(() => this.$refs.cbInput && this.$refs.cbInput.focus());
        },

        pick(opt) {
            // defer (live=false): set value tanpa AJAX seketika, supaya tidak menyela ketikan.
            this.$wire.set(this.teksModel, opt.nama, false);
            this.simpanId(opt);
            if (this.$refs.cbInput) {
                this.$refs.cbInput.value = opt.nama;
                this.$refs.cbInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
            this.open = false;
            this.highlighted = -1;
            this.$nextTick(() => this.$refs.cbInput && this.$refs.cbInput.focus());
        },

        moveDown() {
            if (!this.open) {
                this.openDropdown();
                if (!this.open) return;
                this.highlighted = 0;
                return;
            }
            if (this.filtered.length === 0) return;
            this.highlighted = this.highlighted < 0 ? 0 : (this.highlighted + 1) % this.filtered.length;
        },

        moveUp() {
            if (!this.open) {
                this.openDropdown();
                if (!this.open) return;
                this.highlighted = this.filtered.length - 1;
                return;
            }
            if (this.filtered.length === 0) return;
            this.highlighted = this.highlighted <= 0 ? this.filtered.length - 1 : this.highlighted - 1;
        },
    }"
    x-on:click.outside="open = false">

    <div class="relative">
        <input
            type="text"
            autocomplete="off"
            @disabled($disabled)
            wire:model="{{ $wireModel }}"
            placeholder="{{ $placeholder }}"
            maxlength="{{ $maxlength }}"
            @if($inputId) id="{{ $inputId }}" @endif
            x-ref="cbInput"
            x-on:input="onInput()"
            x-on:keydown.escape="open = false"
            x-on:keydown.arrow-down.prevent="moveDown()"
            x-on:keydown.arrow-up.prevent="moveUp()"
            {{-- Tab TANPA .prevent: baris yang tersorot diambil, fokus tetap lanjut ke isian berikutnya. --}}
            x-on:keydown.tab="if (open && highlighted >= 0 && filtered[highlighted]) { pick(filtered[highlighted]); } open = false;"
            x-on:keydown.enter.prevent="
                if (open && highlighted >= 0 && filtered[highlighted]) {
                    pick(filtered[highlighted]);
                } else {
                    open = false;
                    @if($enterAction) {{ $enterAction }}; @endif
                }
            "
            {{ $attributes->merge([
                'class' => trim($borderClass . ' dark:bg-gray-900 dark:text-gray-100 rounded-md shadow-sm disabled:opacity-90 disabled:bg-gray-100 disabled:cursor-not-allowed w-full ' . ($disabled ? '' : 'pr-16')),
            ]) }} />

        {{-- Tombol Clear (×) — hanya muncul kalau ada nilai --}}
        @unless($disabled)
            <button type="button"
                x-show="$refs.cbInput && $refs.cbInput.value !== ''"
                x-on:mousedown.prevent
                x-on:click.prevent="clearValue()"
                title="Kosongkan"
                class="absolute top-1/2 right-8 -translate-y-1/2 inline-flex items-center justify-center w-6 h-6 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 rounded transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>

            {{-- Chevron untuk buka/tutup daftar manual --}}
            <button type="button"
                {{-- Fokus ditahan di kotak: kalau pindah ke tombol ini, panah atas/bawah
                     tak lagi menyorot daftar yang baru saja dibuka. --}}
                x-on:mousedown.prevent
                x-on:click.prevent="open ? (open = false) : openDropdown(); if ($refs.cbInput) { $refs.cbInput.focus(); }"
                :title="open ? 'Tutup daftar' : 'Lihat {{ $judulDaftar }}'"
                class="absolute top-1/2 right-2 -translate-y-1/2 inline-flex items-center justify-center w-6 h-6 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 rounded transition">
                <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </button>
        @endunless
    </div>

    {{--
        TIGA LAPIS PENGAMAN untuk satu penyakit yang sama: sebuah <li> hasil x-for
        ter-inisialisasi ULANG di luar scope x-for, lalu Alpine melempar
        "opt is not defined" DI TENGAH flushHandlers — dan sisa directive pada batch
        initTree yang sama ikut batal, sehingga elemen lain di halaman (mis. chip
        sub-nav di tab Pelayanan Bedah RI) tampil kosong tanpa styling.

        1. wire:ignore — isi daftar 100% dibangun Alpine dari `allOptions` (sudah dibekukan
           ke x-data saat render), server tak pernah perlu memperbaruinya. Tanpa ini morph
           Livewire ikut mengaduk <template x-for> dan bisa mencabut <li>-nya ke DOM hidup.
        2. <template x-if="open"> — selama daftar tertutup (nyaris selalu) TIDAK ADA <li>
           di DOM sama sekali, jadi tak ada yang bisa tercabut morph. Tanpa ini tiap
           combobox menyimpan 50 <li> tersembunyi seumur halaman, padahal satu halaman EMR
           memuat banyak combobox sekaligus (e-resep, inform consent, pindah ruang, ESO…).
        3. typeof-guard di :class/x-text/handler — kalau toh ada <li> yang ter-init tanpa
           scope, ekspresinya menghasilkan nilai kosong, BUKAN ReferenceError; batch
           initTree jalan terus dan komponen lain di halaman tidak ikut jadi korban.
    --}}
    <div wire:ignore
         x-show="open && filtered.length > 0"
         x-transition.opacity.duration.100ms
         class="absolute z-50 w-full mt-2 overflow-hidden bg-white border border-gray-200 shadow-lg rounded-xl dark:bg-gray-900 dark:border-gray-700">
        <template x-if="open">
            <ul class="overflow-y-auto divide-y divide-gray-100 max-h-72 dark:divide-gray-800">
                <template x-for="(opt, idx) in filtered" :key="idx + '-' + opt.jenis + '-' + opt.id">
                    <li :class="typeof idx === 'undefined'
                            ? ''
                            : (idx === highlighted
                                ? 'bg-brand-lime/15 dark:bg-brand-lime/25 ring-1 ring-brand-lime/30'
                                : 'hover:bg-brand-lime/10 dark:hover:bg-brand-lime/20')"
                        class="w-full px-4 py-3 text-left text-gray-800 dark:text-gray-100 rounded-lg transition-colors duration-150 cursor-pointer"
                        x-on:mousedown.prevent="if (typeof opt !== 'undefined') { pick(opt); }"
                        x-on:mouseenter="if (typeof idx !== 'undefined') { highlighted = idx; }"
                        x-text="typeof opt === 'undefined' ? '' : teks(opt)"></li>
                </template>
            </ul>
        </template>
    </div>
</div>
