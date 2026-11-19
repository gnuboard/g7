<?php

namespace App\Http\Requests\User;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 내 정보 화면 항목 조회 요청.
 *
 * `context` 로 어느 화면의 항목인지 고른다 — `profile` 은 내 정보 수정·보기 화면, `required` 는 추가 정보 입력 화면이다.
 *
 * @since 7.2.0
 */
class ProfileFieldsRequest extends FormRequest
{
    /** 내 정보 수정·보기 화면 */
    public const CONTEXT_PROFILE = 'profile';

    /** 추가 정보 입력 화면 — 비어 있는 필수 항목만 */
    public const CONTEXT_REQUIRED = 'required';

    /**
     * 요청 권한 — 본인 정보라 인증만 되면 허용한다(인증은 라우트 미들웨어).
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'context' => ['nullable', 'string', Rule::in([self::CONTEXT_PROFILE, self::CONTEXT_REQUIRED])],
            // 표시 이름 종류 미리보기에 쓸 입력 중인 값 (저장하지 않는다)
            'preview_name' => ['nullable', 'string', 'max:255'],
            'preview_nickname' => ['nullable', 'string', 'max:50'],
            'preview_email' => ['nullable', 'string', 'max:255'],
        ];

        return HookManager::applyFilters('core.user.profile_fields_validation_rules', $rules, $this);
    }

    /**
     * 검증 속성명.
     *
     * @return array<string, string> 속성 => 이름
     */
    public function attributes(): array
    {
        return [
            'context' => __('fields.attributes.context'),
            'preview_name' => __('display_name.attributes.preview_name'),
            'preview_nickname' => __('display_name.attributes.preview_nickname'),
            'preview_email' => __('display_name.attributes.preview_email'),
        ];
    }
}
