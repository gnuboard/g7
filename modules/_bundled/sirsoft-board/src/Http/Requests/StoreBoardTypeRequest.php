<?php

namespace Modules\Sirsoft\Board\Http\Requests;

use App\Rules\LocaleRequiredTranslatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Board\Http\Requests\Concerns\ProvidesFallbackRuleMessages;
use Modules\Sirsoft\Board\Models\BoardType;

class StoreBoardTypeRequest extends FormRequest
{
    use ProvidesFallbackRuleMessages;

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

    /**
     * 검증 항목 표시 이름을 반환합니다.
     *
     * 다국어 이름 칸은 코어 다국어 필수 문구(「:attribute의 :language(:locale) 값은 필수입니다」)가 칸 이름으로 쓴다.
     * 없으면 칸 이름 없이 「한국어(ko) 값은 필수입니다」만 나왔다(2026-10-07 실측).
     *
     * @return array<string, string> 필드 → 표시 이름
     */
    public function attributes(): array
    {
        return [
            'name' => __('sirsoft-board::validation.attributes.board_type.name'),
        ];
    }
}
