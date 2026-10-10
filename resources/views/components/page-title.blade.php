@props([
    'title' => '',
    'subtitle' => '',
])

{{--
  Page Title untuk topbar (sebelah logo RS).
  Penggunaan: <x-page-title title="..." subtitle="..." />

  Props plain text — semua styling (font-weight, ukuran, warna) sudah
  diatur di layouts/app.blade.php (slot title + subtitle).
  Saat wire:navigate ke page lain, store di-reset di layouts/app.blade.php
  (event livewire:navigating) supaya tidak nyangkut ke page tujuan.
--}}
<div x-data x-init="$store.pageTitle = { title: @js($title), subtitle: @js($subtitle) }"></div>
