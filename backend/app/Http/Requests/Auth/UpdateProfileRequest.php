<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['nullable', 'string', 'max:60'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user()->id)],
            // A handle: letters, digits, dot, dash, underscore; starts with a letter or digit.
            'username' => ['nullable', 'string', 'min:3', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', Rule::unique('users', 'username')->ignore($this->user()->id)],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9][0-9 ()-]{5,}$/'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'occupation' => ['nullable', 'string', 'max:80'],
            'bio' => ['nullable', 'string', 'max:500'],
            'timezone' => ['nullable', 'string', Rule::in(timezone_identifiers_list())],
            'country' => ['nullable', 'string', 'max:80'],
            'province' => ['nullable', 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:80'],
            'street_address' => ['nullable', 'string', 'max:150'],
            'postal_code' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'username.regex' => 'A username can use letters, numbers, dots, dashes and underscores, and must start with a letter or number.',
            'phone.regex' => 'Enter the phone number with digits only, optionally starting with + and the country code.',
            'date_of_birth.before' => 'The date of birth must be in the past.',
        ];
    }
}
