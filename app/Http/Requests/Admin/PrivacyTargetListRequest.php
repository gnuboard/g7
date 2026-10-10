<?php

namespace App\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 개인정보 조치 대상 목록 조회 요청
 *
 * 관리자 화면의 「대상 목록」 모달이 무한스크롤로 한 페이지씩 부른다. 대상은 수만 건일 수
 * 있으므로 한 번에 받는 행 수에 상한을 둔다.
 */
class PrivacyTargetListRequest extends FormRequest
{
    /** 기본 페이지 크기 */
    public const DEFAULT_PER_PAGE = 20;

    /** 한 번에 조회 가능한 최대 행 수 */
    public const MAX_PER_PAGE = 100;

    /**
     * 권한 확인
     *
     * @return bool 인가 여부 (목록 권한은 대상 공급자가 선언하고 컨트롤러가 검사한다)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];

        return HookManager::applyFilters('core.privacy.target_list_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 오류 메시지
     */
    public function messages(): array
    {
        return [
            'page.integer' => __('validation.privacy_targets.page.integer'),
            'page.min' => __('validation.privacy_targets.page.min'),
            'per_page.integer' => __('validation.privacy_targets.per_page.integer'),
            'per_page.min' => __('validation.privacy_targets.per_page.min'),
            'per_page.max' => __('validation.privacy_targets.per_page.max', ['max' => self::MAX_PER_PAGE]),
        ];
    }

    /**
     * 검증 필드 라벨
     *
     * @return array<string, string> 필드 => 라벨
     */
    public function attributes(): array
    {
        return [
            'page' => __('validation.attributes.page'),
            'per_page' => __('validation.attributes.per_page'),
        ];
    }

    /**
     * 요청한 페이지 번호를 반환합니다.
     *
     * @return int 페이지 번호 (기본 1)
     */
    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }

    /**
     * 요청한 페이지 크기를 반환합니다.
     *
     * @return int 페이지 크기 (기본 {@see DEFAULT_PER_PAGE})
     */
    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }
}
