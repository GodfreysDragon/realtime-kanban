<?php

namespace App\Http\Requests;

use App\Models\Card;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('view', $this->route('card')->board);
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'label' => ['sometimes', 'nullable', Rule::in(Card::LABELS)],
            // The version the client last saw; a mismatch means someone else
            // saved in between and the request is rejected with 409.
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}
