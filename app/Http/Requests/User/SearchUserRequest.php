<?php

namespace App\Http\Requests\User;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 사용자 검색 요청 검증
 */
class SearchUserRequest extends FormRequest
{
    /**
     * 요청 권한을 판정합니다 — 라우트 미들웨어(`permission:admin,core.users.read`)가 이미 막으므로 여기서는 통과시킵니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 회원 검색 요청의 검증 규칙을 반환합니다.
     *
     * @return array<string, ValidationRule|array<mixed>|string> 필드별 검증 규칙(훅 필터 적용 뒤)
     */
    public function rules(): array
    {
        $rules = [
            'keyword' => 'required_without:uuid|string|max:255',
            'uuid' => ['required_without:keyword', 'uuid', Rule::exists(User::class, 'uuid')],
            // 키워드 검색에서 탈퇴 회원을 뺄지(알림 수신자 고르기 등). uuid 단건 조회에는 적용하지 않는다 — 이미 고른 값의 표시용이다.
            'exclude_withdrawn' => 'sometimes|boolean',
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.user.search_validation_rules', $rules, $this);
    }

    /**
     * 검증 문구의 칸 표시 이름을 반환합니다 — 없으면 오류 문구에 필드 키(`uuid`·`exclude_withdrawn`)가 그대로 나간다.
     *
     * @return array<string, string> 필드별 표시 이름
     */
    public function attributes(): array
    {
        return [
            'uuid' => __('validation.user_search.attributes.uuid'),
            'exclude_withdrawn' => __('validation.attributes.exclude_withdrawn'),
        ];
    }

    /**
     * 검증 오류 메시지를 반환합니다.
     *
     * @return array<string, string> 규칙별 다국어 메시지
     */
    public function messages(): array
    {
        return [
            'keyword.required_without' => __('user.validation.search_keyword_required'),
            'keyword.string' => __('user.validation.name_string'),
            'keyword.max' => __('user.validation.name_max'),
        ];
    }
}
