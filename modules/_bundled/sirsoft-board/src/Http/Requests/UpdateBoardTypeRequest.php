<?php

namespace Modules\Sirsoft\Board\Http\Requests;

use App\Rules\LocaleRequiredTranslatable;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Board\Models\BoardType;

class UpdateBoardTypeRequest extends FormRequest
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
            'name' => ['sometimes', 'required', new LocaleRequiredTranslatable(maxLength: 100)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [];
    }
}
