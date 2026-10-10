<?php

namespace App\Http\Traits\Concerns;

/**
 * Trait WithRenderVersioning
 *
 * Trait untuk mengelola versioning render pada Livewire component
 * Memungkinkan multiple area/komponen di-refresh secara independen
 *
 * @property array $renderVersions Array yang menyimpan versi setiap area
 */
trait WithRenderVersioningTrait
{
    /**
     * Initialize trait
     * Bisa dipanggil di mount/constructor component
     */
    public function initializeWithRenderVersioning(): void
    {
        // Initialize renderVersions jika belum ada
        if (!isset($this->renderVersions) || !is_array($this->renderVersions)) {
            $this->renderVersions = [];
        }

        // Auto-register area dari property $renderAreas jika ada
        if (property_exists($this, 'renderAreas') && is_array($this->renderAreas)) {
            foreach ($this->renderAreas as $areaName) {
                $this->registerArea($areaName);
            }
        }
    }

    /**
     * Register area render baru
     *
     * @param string $areaName Nama area (form, table, modal, dll)
     * @param int $initialVersion Versi awal (default 0)
     * @return self
     */
    public function registerArea(string $areaName, int $initialVersion = 0): self
    {
        if (!isset($this->renderVersions[$areaName])) {
            $this->renderVersions[$areaName] = $initialVersion;
        }

        return $this;
    }

    /**
     * Register multiple area sekaligus
     *
     * @param array $areaList Daftar nama area
     * @return self
     */
    public function registerAreas(array $areaList): self
    {
        foreach ($areaList as $areaName) {
            $this->registerArea($areaName);
        }

        return $this;
    }

    /**
     * Trigger re-render untuk area tertentu
     * Alias dari incrementVersion untuk kemudahan
     *
     * @param string $areaName
     * @return int
     */
    public function triggerRender(string $areaName): int
    {
        return $this->incrementVersion($areaName);
    }

    /**
     * Trigger re-render untuk multiple area
     *
     * @param array $areaNames
     * @return array
     */
    public function triggerRenders(array $areaNames): array
    {
        return $this->incrementVersions($areaNames);
    }

    /**
     * Trigger re-render untuk semua area
     *
     * @return array
     */
    public function triggerAllRenders(): array
    {
        return $this->incrementAllVersions();
    }

    /**
     * Increment versi area
     *
     * @param string $areaName Nama area yang akan di-increment
     * @return int Versi baru setelah increment
     * @throws \InvalidArgumentException
     */
    public function incrementVersion(string $areaName): int
    {
        $this->validateAreaExists($areaName);

        return ++$this->renderVersions[$areaName];
    }

    /**
     * Increment multiple area sekaligus
     *
     * @param array $areaNames Daftar nama area yang akan di-increment
     * @return array Associative array [areaName => newVersion]
     */
    public function incrementVersions(array $areaNames): array
    {
        $updated = [];
        foreach ($areaNames as $areaName) {
            $updated[$areaName] = $this->incrementVersion($areaName);
        }

        return $updated;
    }

    /**
     * Increment semua area yang terdaftar
     *
     * @return array Associative array [areaName => newVersion]
     */
    public function incrementAllVersions(): array
    {
        $updated = [];
        foreach (array_keys($this->renderVersions) as $areaName) {
            $updated[$areaName] = $this->incrementVersion($areaName);
        }

        return $updated;
    }

    /**
     * Get current version of an area
     *
     * @param string $areaName
     * @return int
     */
    public function getVersion(string $areaName): int
    {
        $this->validateAreaExists($areaName);

        return $this->renderVersions[$areaName];
    }

    /**
     * Set version area secara manual
     *
     * @param string $areaName
     * @param int $version
     * @return self
     */
    public function setVersion(string $areaName, int $version): self
    {
        $this->validateAreaExists($areaName);
        $this->renderVersions[$areaName] = max(0, $version);

        return $this;
    }

    /**
     * Reset area version ke 0
     *
     * @param string|null $areaName Jika null, reset semua area
     * @return self
     */
    public function resetVersion(?string $areaName = null): self
    {
        if ($areaName) {
            $this->validateAreaExists($areaName);
            $this->renderVersions[$areaName] = 0;
        } else {
            foreach (array_keys($this->renderVersions) as $name) {
                $this->renderVersions[$name] = 0;
            }
        }

        return $this;
    }

    /**
     * Check if area exists
     *
     * @param string $areaName
     * @return bool
     */
    public function hasArea(string $areaName): bool
    {
        return isset($this->renderVersions[$areaName]);
    }

    /**
     * Get all registered areas with their versions
     *
     * @return array
     */
    public function getAllVersions(): array
    {
        return $this->renderVersions;
    }

    /**
     * Generate wire:key untuk area tertentu
     *
     * @param string $areaName
     * @param array|string $additionalContext Konteks tambahan untuk key (ID, mode, dll)
     * @return string
     */
    /**
     * Generate wire:key untuk area tertentu
     *
     * @param string $areaName
     * @param array|string|null $additionalContext Konteks tambahan untuk key (ID, mode, dll)
     * @return string
     */
    public function renderKey(string $areaName, array|string|null $additionalContext = []): string
    {
        $this->validateAreaExists($areaName);

        // Jika null, ubah menjadi string kosong
        if ($additionalContext === null) {
            $additionalContext = '';
        }

        $context = is_array($additionalContext)
            ? implode('-', array_filter($additionalContext)) // array_filter untuk hapus null/empty
            : $additionalContext;

        $base = "render-{$areaName}-v{$this->renderVersions[$areaName]}";

        return $context ? "{$base}-{$context}" : $base;
    }
    /**
     * Generate array of wire:keys untuk multiple areas
     *
     * @param array $areaNames
     * @param array|string $additionalContext
     * @return array
     */
    public function renderKeys(array $areaNames, array|string $additionalContext = []): array
    {
        $keys = [];
        foreach ($areaNames as $areaName) {
            $keys[$areaName] = $this->renderKey($areaName, $additionalContext);
        }

        return $keys;
    }

    /**
     * Unregister area (remove from tracking)
     *
     * @param string $areaName
     * @return self
     */
    public function unregisterArea(string $areaName): self
    {
        unset($this->renderVersions[$areaName]);

        return $this;
    }

    /**
     * Validate that area exists
     *
     * @param string $areaName
     * @throws \InvalidArgumentException
     */
    protected function validateAreaExists(string $areaName): void
    {
        if (!isset($this->renderVersions[$areaName])) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Area "%s" is not registered. Available areas: %s',
                    $areaName,
                    implode(', ', array_keys($this->renderVersions))
                )
            );
        }
    }
}
