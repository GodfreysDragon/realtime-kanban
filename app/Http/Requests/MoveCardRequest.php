<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveCardRequest extends FormRequest
{
    public function rules(): array
    {
        $boardId = $this->route('card')->board_id;

        return [
            'column_id' => ['required', 'integer', Rule::exists('columns', 'id')->where('board_id', $boardId)],
            // The card this one should sit directly below; null = top of column.
            // It may have moved away by the time the request lands, which the
            // positioner tolerates, so only existence on the board is checked.
            'after_id' => ['present', 'nullable', 'integer', Rule::exists('cards', 'id')->where('board_id', $boardId)],
        ];
    }
}
