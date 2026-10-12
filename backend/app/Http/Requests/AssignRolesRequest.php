<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role_ids' => [
                'present',
                'array',
            ],
            'role_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('roles', 'id'),
            ],
        ];
    }
}
