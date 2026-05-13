<?php

namespace Modules\Sirsoft\Board\Http\Requests;

use App\Rules\LocaleRequiredTranslatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Board\Models\BoardType;

class StoreBoardTypeRequest extends FormRequest
{
    /**
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slug' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z][a-z0-9-]*$/',
                Rule::unique(BoardType::class, 'slug'),
            ],
            'name' => ['required', new LocaleRequiredTranslatable(maxLength: 100)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.required' => __('sirsoft-board::validation.board_type.slug_required'),
            'slug.regex' => __('sirsoft-board::validation.board_type.slug_format'),
            'slug.unique' => __('sirsoft-board::validation.board_type.slug_unique'),
            'name.required' => __('sirsoft-board::validation.board_type.name_required'),
        ];
    }
}
