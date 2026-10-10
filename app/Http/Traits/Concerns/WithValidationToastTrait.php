<?php

namespace App\Http\Traits\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * Trait WithValidationToastTrait
 *
 * Wrap Livewire `validate()` supaya saat validation gagal, user
 * langsung dapat toast feedback (bukan silent failure). Field-level
 * error tetap di-render via $errors bag (pakai <x-input-error> di view).
 *
 * Pemakaian:
 *   use App\Http\Traits\Concerns\WithValidationToastTrait;
 *   ...
 *   new class extends Component {
 *       use WithValidationToastTrait;
 *
 *       public function save(): void
 *       {
 *           $this->validateWithToast();  // ← pengganti $this->validate()
 *       }
 *   }
 */
trait WithValidationToastTrait
{
    /**
     * Validate dengan auto-dispatch toast saat gagal.
     * Re-throw ValidationException supaya Livewire tetap render $errors di view.
     */
    protected function validateWithToast($rules = null, $messages = [], $attributes = []): array
    {
        // Tanpa rules eksplisit & komponen tak punya rule apa pun (rules() kosong)
        // → lewati validasi, cegah MissingRulesException. (Kasus ini dulu selalu error.)
        if ($rules === null) {
            $resolved = method_exists($this, 'rules') ? $this->rules() : (property_exists($this, 'rules') ? $this->rules : []);
            if (empty($resolved)) {
                return [];
            }
        }

        try {
            return $this->validate($rules, $messages, $attributes);
        } catch (ValidationException $e) {
            $this->dispatchValidationToast($e);
            throw $e;
        }
    }

    /**
     * Bisa juga dipakai standalone setelah catch manual di SFC.
     */
    protected function dispatchValidationToast(ValidationException $e): void
    {
        $errors = $e->validator->errors()->all();
        if (empty($errors)) {
            $this->dispatch('toast', type: 'error', message: 'Ada field yang belum/salah diisi.');
            return;
        }

        $first = $errors[0];
        $extra = \count($errors) - 1;
        $msg = $extra > 0 ? "{$first} (+{$extra} error lain)" : $first;
        $this->dispatch('toast', type: 'error', message: $msg);
    }
}
