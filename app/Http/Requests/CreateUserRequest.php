<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        $isRegisterRoute = $this->routeIs('auth.register');

        return [
            'name' => Rule::requiredIf($isRegisterRoute),
            'email' => ['required', 'email', Rule::when($isRegisterRoute, 'unique:users')],
            'password' => ['required', Rule::when($isRegisterRoute, 'confirmed')],
            'is_admin' => 'sometimes',
        ];
    }
}
