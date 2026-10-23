<?php

namespace App\Concerns;

use App\Enums\ReadingLength;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }

    /**
     * Get the validation rules used to validate the reading profile.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function readingProfileRules(): array
    {
        return [
            'bio' => ['nullable', 'string', 'max:1000'],
            'preferredLanguages' => ['array'],
            'preferredLanguages.*' => [Rule::in(config('bookflix.languages'))],
            'readingLength' => [Rule::enum(ReadingLength::class)],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('bookflix.avatar_max_kb')],
        ];
    }
}
