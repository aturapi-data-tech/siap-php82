@props([
    'kill' => null, // null | ['status'=>'sent|killed|gone|active|error', 'mode'=>'kill|disconnect', 'at'=>ts, 'note'=>...]
])

@php
    if (!$kill) {
        return;
    }

    $status = $kill['status'] ?? 'sent';
    $mode   = $kill['mode']   ?? 'kill';
    $verb   = $mode === 'disconnect' ? 'DISCONNECT' : 'KILL';

    [$icon, $label, $cls, $title] = match ($status) {
        'gone'   => ['✓', "{$verb} ✓ gone",   'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300', 'Sesi sudah hilang dari v$session'],
        'killed' => ['⏳', "{$verb} marked",   'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',     'Sesi ditandai KILLED, menunggu PMON cleanup'],
        'error'  => ['✗', "{$verb} failed",  'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300',         ($kill['note'] ?? 'Gagal')],
        'active' => ['!', "{$verb} ignored", 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300', 'Sesi masih ACTIVE — coba mode lain'],
        default  => ['⌛', "{$verb} sent",   'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',         'Perintah dikirim ke Oracle'],
    };
@endphp

<div class="mt-1 inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-semibold {{ $cls }}"
     title="{{ $title }}">
    <span>{{ $icon }}</span>
    <span class="font-mono uppercase tracking-wide">{{ $label }}</span>
</div>
