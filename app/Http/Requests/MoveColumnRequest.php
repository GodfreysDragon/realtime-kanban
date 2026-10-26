<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MoveColumnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('view', $this->route('column')->board);
    }

    public function rules(): array
    {
        return [
            // The column this one should sit directly after; null = first.
            'after_id' => ['present', 'nullable', 'integer', Rule::exists('columns', 'id')->where('board_id', $this->route('column')->board_id)],
        ];
    }
}
