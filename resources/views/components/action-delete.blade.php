{{--
    Tombol Hapus standar untuk baris tabel (master/list).
    confirm-button (danger, modal konfirmasi) + ikon sampah. Label default "Hapus".

    Pemakaian:
        <x-action-delete :action="'requestDelete(\'' . $row->id . '\')'"
                         title="Hapus Poli" message="Yakin hapus data poli {{ $row->desc }}?" />

    Props diteruskan ke <x-confirm-button>:
      - action (wajib)  : ekspresi method Livewire, mis. "delete('10')"
      - title           : judul modal konfirmasi
      - message         : pesan konfirmasi
      - confirmText / cancelText
--}}
@props([
    'action',
    'title' => 'Hapus Data',
    'message' => 'Apakah Anda yakin ingin menghapus data ini?',
    'confirmText' => 'Ya, hapus',
    'cancelText' => 'Batal',
])

<x-confirm-button variant="danger" :action="$action" :title="$title" :message="$message"
    :confirmText="$confirmText" :cancelText="$cancelText"
    {{ $attributes->merge(['class' => 'px-2.5 py-1.5 text-sm']) }}>
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
    </svg>
    {{ $slot->isEmpty() ? 'Hapus' : $slot }}
</x-confirm-button>
