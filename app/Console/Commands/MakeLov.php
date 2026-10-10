<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MakeLov extends Command
{
    protected $signature = 'make:lov
        {path : Contoh: product/lov-product}
        {--table= : Nama tabel database}
        {--id= : Nama kolom ID (default: id)}
        {--name= : Nama kolom untuk display (default: name)}
        {--force : Overwrite jika file sudah ada}';

    protected $description = 'Create a Livewire 4 SFC LOV component under resources/views/livewire/lov/*';

    public function handle(): int
    {
        $path = trim($this->argument('path'), '/');
        $segments = array_values(array_filter(explode('/', $path)));

        if (count($segments) === 0) {
            $this->error("Path tidak valid.");
            return self::FAILURE;
        }

        $fileName = array_pop($segments);
        $lovFolder = base_path('resources/views/livewire/lov/' . implode('/', $segments));
        $fullPath = $lovFolder . '/' . $fileName . '.blade.php';

        if (!File::exists($lovFolder)) {
            File::makeDirectory($lovFolder, 0755, true);
        }

        if (File::exists($fullPath) && !$this->option('force')) {
            $this->error("LOV already exists: {$fullPath}");
            $this->line("Gunakan --force untuk overwrite.");
            return self::FAILURE;
        }

        // Ambil parameter
        $tableName = $this->option('table') ?: '';
        $idColumn = $this->option('id') ?: 'id';
        $nameColumn = $this->option('name') ?: 'name';

        // Generate stub berdasarkan input
        $stub = $this->generateStub($fileName, $tableName, $idColumn, $nameColumn);

        File::put($fullPath, $stub);

        $this->info("✅ LOV created: {$fullPath}");
        $this->line("📦 Use it as: <livewire:lov." . str_replace('/', '.', $path) . " />");

        $this->line("🔧 Parameters:");
        $this->line("  - target='formName' (wajib, untuk identifikasi)");
        $this->line("  - :initialId='value' (opsional, untuk mode edit)");
        $this->line("  - :readonly='true' (opsional, untuk nonaktifkan tombol Ubah)");

        if ($tableName) {
            $this->line("⚙️  Table: {$tableName}, ID: {$idColumn}, Name: {$nameColumn}");
        } else {
            $this->line("ℹ️  Jangan lupa konfigurasi query database di component!");
        }

        return self::SUCCESS;
    }

    protected function generateStub(string $fileName, string $tableName, string $idColumn, string $nameColumn): string
    {
        // Normalize nama untuk placeholder
        $displayName = str_replace(['lov-', 'lov_', '-', '_'], ' ', $fileName);
        $displayName = ucwords($displayName);
        $lowerName = strtolower($displayName);

        // Jika ada table name, generate query otomatis
        $hasTable = !empty($tableName);

        // Query untuk mode edit
        $editQuery = '';
        if ($hasTable) {
            $editQuery = <<<PHP
        \$row = DB::table('{$tableName}')
            ->select(['{$idColumn}', '{$nameColumn}'])
            ->where('{$idColumn}', \$this->initialId)
            ->first();

        if (\$row) {
            \$this->selected = [
                '{$idColumn}' => (string) \$row->{$idColumn},
                '{$nameColumn}' => (string) (\$row->{$nameColumn} ?? ''),
            ];
        }
PHP;
        } else {
            $editQuery = "// TODO: Load data dari database berdasarkan initialId\n        // \$this->selected = ['{$idColumn}' => 'value', '{$nameColumn}' => 'value'];";
        }

        // Query untuk search
        $searchQuery = '';
        if ($hasTable) {
            $searchQuery = <<<PHP
        // ===== 1) exact match by {$idColumn} =====
        if (ctype_digit(\$keyword)) {
            \$exactRow = DB::table('{$tableName}')
                ->select(['{$idColumn}', '{$nameColumn}'])
                ->where('{$idColumn}', \$keyword)
                ->first();

            if (\$exactRow) {
                \$this->dispatchSelected([
                    '{$idColumn}' => (string) \$exactRow->{$idColumn},
                    '{$nameColumn}' => (string) (\$exactRow->{$nameColumn} ?? ''),
                ]);
                return;
            }
        }

        // ===== 2) search by {$nameColumn} partial =====
        \$upperKeyword = mb_strtoupper(\$keyword);

        \$rows = DB::table('{$tableName}')
            ->select(['{$idColumn}', '{$nameColumn}'])
            ->where(function (\$q) use (\$keyword, \$upperKeyword) {
                if (ctype_digit(\$keyword)) {
                    \$q->orWhere('{$idColumn}', 'like', "%{\$keyword}%");
                }
                \$q->orWhereRaw('UPPER({$nameColumn}) LIKE ?', ["%{\$upperKeyword}%"]);
            })
            ->orderBy('{$nameColumn}')
            ->limit(50)
            ->get();
PHP;
        } else {
            $searchQuery = "// TODO: Ganti dengan query database sesuai kebutuhan\n        // \$rows = DB::table('nama_tabel')->where(...)->get();";
        }

        // Mapping untuk options
        $optionsMapping = '';
        if ($hasTable) {
            $optionsMapping = <<<PHP
        \$this->options = \$rows
            ->map(function (\$row) {
                \$id = (string) \$row->{$idColumn};
                \$name = (string) (\$row->{$nameColumn} ?? '');

                return [
                    // payload (sesuaikan dengan kebutuhan)
                    '{$idColumn}' => \$id,
                    '{$nameColumn}' => \$name,

                    // UI
                    'label' => \$name ?: '-',
                    'hint' => \$id ? "ID {\$id}" : '',
                ];
            })
            ->toArray();
PHP;
        } else {
            $optionsMapping = "// TODO: Map hasil query ke format options\n        // \$this->options = [];";
        }

        // Dispatch payload
        $dispatchPayload = '';
        if ($hasTable) {
            $dispatchPayload = <<<PHP
        \$payload = [
            '{$idColumn}' => \$this->options[\$index]['{$idColumn}'] ?? '',
            '{$nameColumn}' => \$this->options[\$index]['{$nameColumn}'] ?? '',
        ];
PHP;
        } else {
            $dispatchPayload = "// TODO: Sesuaikan payload dengan kebutuhan\n        \$payload = \$this->options[\$index];";
        }

        return <<<BLADE
<?php

use Livewire\Component;
use Illuminate\Support\Facades\DB;

new class extends Component {
    /** target untuk membedakan LOV ini dipakai di form mana */
    public string \$target = 'default';

    /** UI */
    public string \$label = 'Cari {$displayName}';
    public string \$placeholder = 'Ketik {$lowerName}...';

    /** state */
    public string \$search = '';
    public array \$options = [];
    public bool \$isOpen = false;
    public int \$selectedIndex = 0;

    /** selected state (buat mode selected + edit) */
    public ?array \$selected = null;

    /**
     * Mode edit: parent bisa kirim initialId yang sudah tersimpan.
     */
    public ?string \$initialId = null;

    /**
     * Mode readonly: jika true, tombol "Ubah" akan hilang saat selected.
     * Berguna untuk form yang sudah selesai/tidak boleh diedit.
     */
    public bool \$readonly = false;

    public function mount(): void
    {
        if (!\$this->initialId) {
            return;
        }

{$editQuery}
    }

    public function updatedSearch(): void
    {
        // kalau sudah selected, jangan cari lagi
        if (\$this->selected !== null) {
            return;
        }

        \$keyword = trim(\$this->search);

        // minimal 2 char
        if (mb_strlen(\$keyword) < 2) {
            \$this->closeAndResetList();
            return;
        }

{$searchQuery}

{$optionsMapping}

        \$this->isOpen = count(\$this->options) > 0;
        \$this->selectedIndex = 0;

        if (\$this->isOpen) {
            \$this->emitScroll();
        }
    }

    public function clearSelected(): void
    {
        // Jika readonly, tidak bisa clear selected
        if (\$this->readonly) {
            return;
        }

        \$this->selected = null;
        \$this->resetLov();
    }

    public function close(): void
    {
        \$this->isOpen = false;
    }

    public function resetLov(): void
    {
        \$this->reset(['search', 'options', 'isOpen', 'selectedIndex']);
    }

    public function selectNext(): void
    {
        if (!\$this->isOpen || count(\$this->options) === 0) {
            return;
        }

        \$this->selectedIndex = (\$this->selectedIndex + 1) % count(\$this->options);
        \$this->emitScroll();
    }

    public function selectPrevious(): void
    {
        if (!\$this->isOpen || count(\$this->options) === 0) {
            return;
        }

        \$this->selectedIndex--;
        if (\$this->selectedIndex < 0) {
            \$this->selectedIndex = count(\$this->options) - 1;
        }

        \$this->emitScroll();
    }

    public function choose(int \$index): void
    {
        if (!isset(\$this->options[\$index])) {
            return;
        }

{$dispatchPayload}

        \$this->dispatchSelected(\$payload);
    }

    public function chooseHighlighted(): void
    {
        \$this->choose(\$this->selectedIndex);
    }

    /* helpers */

    protected function closeAndResetList(): void
    {
        \$this->options = [];
        \$this->isOpen = false;
        \$this->selectedIndex = 0;
    }

    protected function dispatchSelected(array \$payload): void
    {
        // set selected -> UI berubah jadi nama + tombol ubah
        \$this->selected = \$payload;

        // bersihkan mode search
        \$this->search = '';
        \$this->options = [];
        \$this->isOpen = false;
        \$this->selectedIndex = 0;

        // emit ke parent
        \$this->dispatch('lov.selected', target: \$this->target, payload: \$payload);
    }

    protected function emitScroll(): void
    {
        \$this->dispatch('lov-scroll', id: \$this->getId(), index: \$this->selectedIndex);
    }
};
?>

<x-lov.dropdown :id="\$this->getId()" :isOpen="\$isOpen" :selectedIndex="\$selectedIndex" close="close">
    <x-input-label :value="\$label" />

    <div class="relative mt-1">
        @if (\$selected === null)
            {{-- Mode cari --}}
            @if (!\$readonly)
                <x-text-input type="text" class="block w-full" :placeholder="\$placeholder" wire:model.live.debounce.250ms="search"
                    wire:keydown.escape.prevent="resetLov" wire:keydown.arrow-down.prevent="selectNext"
                    wire:keydown.arrow-up.prevent="selectPrevious" wire:keydown.enter.prevent="chooseHighlighted" />
            @else
                <x-text-input
                    type="text"
                    class="block w-full bg-gray-100 cursor-not-allowed dark:bg-gray-800"
                    :placeholder="\$placeholder"
                    disabled
                />
            @endif
        @else
            {{-- Mode selected --}}
            <div class="flex items-center gap-2">
                <x-text-input
                    type="text"
                    class="flex-1 block w-full"
                    :value="\$selected['{$nameColumn}'] ?? ''"
                    disabled
                />

                @if (!\$readonly)
                    <x-secondary-button
                        type="button"
                        wire:click="clearSelected"
                        class="px-4 whitespace-nowrap"
                    >
                        Ubah
                    </x-secondary-button>
                @endif
            </div>
        @endif

        {{-- dropdown hanya saat mode cari dan tidak readonly --}}
        @if (\$isOpen && \$selected === null && !\$readonly)
            <div
                class="absolute z-50 w-full mt-2 overflow-hidden bg-white border border-gray-200 shadow-lg rounded-xl dark:bg-gray-900 dark:border-gray-700">
                <ul class="overflow-y-auto divide-y divide-gray-100 max-h-72 dark:divide-gray-800">
                    @foreach (\$options as \$index => \$option)
                        <li wire:key="lov-{{ \$option['{$idColumn}'] ?? \$index }}-{{ \$index }}"
                            x-ref="lovItem{{ \$index }}">
                            <x-lov.item wire:click="choose({{ \$index }})" :active="\$index === \$selectedIndex">
                                <div class="font-semibold text-gray-900 dark:text-gray-100">
                                    {{ \$option['label'] ?? '-' }}
                                </div>

                                @if (!empty(\$option['hint']))
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ \$option['hint'] }}
                                    </div>
                                @endif
                            </x-lov.item>
                        </li>
                    @endforeach
                </ul>

                @if (mb_strlen(trim(\$search)) >= 2 && count(\$options) === 0)
                    <div class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                        Data tidak ditemukan.
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-lov.dropdown>
BLADE;
    }
}
