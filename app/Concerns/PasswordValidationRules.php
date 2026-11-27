<?php

namespace App\Concerns;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /** bcrypt, the default hasher, ignores every byte past the 72nd, so a longer password would be silently shortened. */
    private const PASSWORD_MAX_BYTES = 72;

    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Closure|Password|ValidationRule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', $this->passwordMaxBytesRule(), Password::default(), 'confirmed'];
    }

    /**
     * Refuse a password longer than bcrypt reads. Counts bytes, not characters ("€" is three bytes), and applies in every environment.
     */
    private function passwordMaxBytesRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && strlen($value) > self::PASSWORD_MAX_BYTES) {
                $fail(__('The password must be at most :max bytes; some characters count as more than one.', ['max' => self::PASSWORD_MAX_BYTES]));
            }
        };
    }

    /**
     * Get the validation rules used to validate the current password.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function currentPasswordRules(): array
    {
        return ['required', 'string', 'current_password'];
    }
}
