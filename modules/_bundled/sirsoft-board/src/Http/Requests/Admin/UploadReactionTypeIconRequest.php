<?php

namespace Modules\Sirsoft\Board\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Board\Http\Requests\Concerns\ProvidesFallbackRuleMessages;
use Modules\Sirsoft\Board\Models\ReactionType;

/**
 * 반응 유형 아이콘 업로드 요청 검증
 *
 * 아이콘은 반응 버튼·목록에 20px 안팎으로 렌더되는 작은 이미지다. SVG 는 제외한다 —
 * 첨부 다운로드 엔드포인트가 인증 없이 인라인 스트리밍이라 SVG 를 허용하면
 * 스크립트가 든 파일이 사이트 오리진에서 실행될 수 있다 (결정 R13).
 */
class UploadReactionTypeIconRequest extends FormRequest
{
    use ProvidesFallbackRuleMessages;

    /**
     * 요청 권한 확인 (라우트 미들웨어가 권한을 강제한다)
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:1024'],
            'reaction_type_id' => ['nullable', 'integer', Rule::exists(ReactionType::class, 'id')],
        ];
    }

    /**
     * 검증 오류 메시지
     *
     * @return array<string, string> 오류 메시지
     */
    public function messages(): array
    {
        return [
            'file.required' => __('sirsoft-board::validation.reaction_type.icon.required'),
            'file.image' => __('sirsoft-board::validation.reaction_type.icon.image'),
            'file.mimes' => __('sirsoft-board::validation.reaction_type.icon.mimes'),
            'file.max' => __('sirsoft-board::validation.reaction_type.icon.max'),
        ];
    }

    /**
     * 검증 속성명
     *
     * @return array<string, string> 속성명
     */
    public function attributes(): array
    {
        return [
            'file' => __('sirsoft-board::validation.attributes.reaction_type.icon'),
            'reaction_type_id' => __('sirsoft-board::validation.attributes.reaction_type.id'),
        ];
    }
}
