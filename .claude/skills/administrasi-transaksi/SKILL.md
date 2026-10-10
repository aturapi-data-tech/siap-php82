---
name: administrasi-transaksi
description: Pola entri transaksi administrasi / penunjang dengan LOV → field → Enter simpan, tanpa tombol Tambah, Batal ikon, dan hapus baris pakai standar e-resep. WAJIB dibaca sebelum membuat/memodifikasi form entry transaksi (OK, laborat, radiologi, administrasi RI/RJ/UGD).
---

# Model Transaksi Administrasi

Pola cepat untuk menambah item ke transaksi penunjang / administrasi:
**LOV pilih item → isi field penunjang (qty, tarif, dst) → Enter untuk simpan**.
Tidak pakai tombol Tambah; hapus baris pakai ikon sampah standar e-resep.

Contoh implementasi: `resources/views/pages/transaksi/penunjang/kamar-operasi/⚡tindakan-kamar-operasi.blade.php`
dan `bahan-alat-kamar-operasi.blade.php`.

---

## 1. Struktur form entri

```blade
@unless ($isFormLocked)
    <div class="grid grid-cols-1 gap-3 p-3 mb-4 ... lg:grid-cols-12">
        {{-- Kolom 1: LOV item --}}
        <div class="lg:col-span-7" id="ok-lov-tindakan"
            x-on:keydown.enter="if (!$event.target.value?.trim()) $dispatch('kamar-operasi-lanjut-tab', { ke: 'BahanAlat' })">
            <livewire:lov.jasa-dokter.lov-jasa-dokter ... />
        </div>

        {{-- Kolom 2..N: field numerik --}}
        <div class="lg:col-span-3">
            <x-input-label value="Tarif" />
            <x-text-input-number id="ok-tarif-tindakan" wire:model="formHarga"
                wire:key="{{ $this->renderKey('form-tindakan', 'tarif') }}"
                x-on:keydown.enter.prevent="
                    const kosong = $el.value.replace(/\D/g, '') === '';
                    $el.blur();
                    kosong ? $dispatch('kamar-operasi-lanjut-tab', { ke: 'BahanAlat' }) : $wire.tambah()
                " />
        </div>

        {{-- Tombol Batal (ikon), BUKAN tombol Tambah --}}
        <div class="flex items-end lg:col-span-2">
            <x-icon-button color="gray" type="button" wire:click.prevent="resetForm"
                title="Batal — kosongkan form entri">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </x-icon-button>
        </div>

        {{-- Petunjuk Enter --}}
        <p class="text-xs text-muted dark:text-gray-400 lg:col-span-12">
            Tekan <span class="px-1.5 py-0.5 font-semibold rounded border border-hairline bg-canvas text-body dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Enter</span>
            di kolom terakhir untuk menyimpan.
        </p>
    </div>
@endunless
```

Aturan:
- **Tidak ada tombol Tambah / Simpan** di form entri. Simpan hanya lewat Enter di kolom terakhir.
- **Tombol Batal hanya berupa ikon × (`<x-icon-button color="gray">`)** untuk mengosongkan form.
- Field numerik pakai `<x-text-input-number>` dan wajib `$el.blur()` sebelum `$wire.tambah()`
  (lihat skill `livewire-input-patterns` §2 / §3).

---

## 2. Reset field agar UI benar-benar bersih

`<x-text-input-number>` menyimpan state Alpine sendiri. Setelah `reset()` properti Livewire,
tambahkan `wire:key` yang berubah supaya input di-remount dengan nilai awal.

Gunakan `WithRenderVersioningTrait`:

```php
new class extends Component {
    use KamarOperasiTrait, WithRenderVersioningTrait;

    public array $renderVersions = [];
    protected array $renderAreas = ['form-tindakan'];

    public function mount(string $okReg = ''): void
    {
        $this->registerAreas($this->renderAreas);
        // ...
    }

    public function resetForm(): void
    {
        $this->reset(['formAccdocId', 'formAccdocDesc', 'formHarga']);
        $this->incrementVersion('form-tindakan');
        $this->dispatch('kamar-operasi-fokus', ke: 'ok-lov-tindakan');
    }
}
```

```blade
<x-text-input-number ... wire:key="{{ $this->renderKey('form-tindakan', 'tarif') }}" />
```

- Area WAJIB didaftarkan di `mount()` (auto-register dari `$renderAreas` tidak selalu jalan
  untuk inline class di Blade).
- Setiap input field berbeda diberi konteks berbeda di `renderKey()` supaya key unik.

---

## 3. Lompat tab saat kolom terakhir kosong

Sama seperti Administrasi RI: kalau user menekan Enter di kolom terakhir dalam keadaan kosong,
pindah ke tab berikutnya dan set fokus ke LOV tab tujuan.

```javascript
x-on:keydown.enter.prevent="
    const kosong = $el.value.replace(/\D/g, '') === '';
    $el.blur();
    kosong ? $dispatch('kamar-operasi-lanjut-tab', { ke: 'BahanAlat' }) : $wire.tambah()
"
```

Event `kamar-operasi-lanjut-tab` ditangani induk (shell) yang mengubah `activeTab` dan
meneruskan fokus. Pada LOV, kosong + Enter juga mengirim event yang sama supaya user bisa
lewati tab tanpa mouse.

---

## 4. Tombol hapus baris — standar e-resep

Untuk hapus item di dalam tabel form (bukan master/transaksi utama), pakai
`<x-outline-button>` merah-tint + ikon sampah + `wire:confirm`, BUKAN `<x-confirm-button>`
dan bukan tombol berlabel teks.

```blade
<x-outline-button type="button"
    wire:click.prevent="hapus({{ $row['okact_id'] }})"
    wire:confirm="Hapus tindakan ini? Jasa dokter operator akan dihitung ulang."
    wire:loading.attr="disabled"
    class="!text-red-600 !bg-red-50 !border-red-200 hover:!bg-red-100 hover:!text-red-700 hover:!border-red-300
           dark:!text-red-400 dark:!bg-red-900/20 dark:!border-red-800/30
           dark:hover:!bg-red-900/30 dark:hover:!text-red-300 !px-2 !py-1"
    title="Hapus tindakan">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
    </svg>
</x-outline-button>
```

Referensi: `docs/standar-komponen-tombol.md` §"Tabel: Tombol Hapus di Baris Data"
dan file e-resep (`eresep-ri-non-racikan.blade.php`).

---

## 5. Struktur aksi PHP

```php
public function tambah(): void
{
    if (!$this->bolehUbah()) {
        return;
    }

    if (empty($this->formAccdocId)) {
        $this->dispatch('toast', type: 'error', message: 'Pilih item terlebih dahulu.');
        return;
    }

    // validasi ...

    $berhasil = $this->jalankanDenganRetryOk(function () use (...) {
        $row = $this->kunciBarisOk($this->okReg);

        // insert detail ...

        // hitung ulang pos turunan supaya konsisten
        [, $totalBaru] = KamarOperasiTarif::hitungUlang($this->okReg, $row);

        $this->catatLogOk($sumber, $refNo, 'Tambah ...');
    }, 'Gagal menambahkan item');

    if (!$berhasil) {
        return;
    }

    $this->resetForm();
    $this->findData();
    $this->dispatch('kamar-operasi.updated');
    $this->dispatch('toast', type: 'success', message: 'Item ditambahkan.');
}
```

Poin penting:
- Lock header transaksi, insert detail, hitung ulang pos turunan, dan audit log
  **dalam satu DB::transaction**.
- Panggil `hitungUlang()` dari satu tempat (mis. `KamarOperasiTarif`) — jangan duplikasi
  rumus di tiap aksi.
- Setelah sukses: `resetForm()` → `findData()` → broadcast update → toast.

---

## 6. Terkait

- `administrasi-inline-edit` — untuk edit langsung di sel tabel (bukan form entry).
- `livewire-input-patterns` — Enter→$wire, blur, caret, x-text-input-number.
- `docs/standar-komponen-tombol.md` — standar tombol hapus & confirm.
