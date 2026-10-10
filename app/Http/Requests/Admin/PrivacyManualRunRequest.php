<?php

namespace App\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 개인정보 조치 직권 실행 요청 (확인 창 건수 조회 · 큐 접수 공용)
 *
 * 받는 옵션은 「대기기간 생략」 하나다. 생략을 지원하지 않는 대상이면 서비스가 무시한다.
 */
class PrivacyManualRunRequest extends FormRequest
{
    /**
     * 권한 확인
     *
     * @return bool 인가 여부 (실행 권한은 대상이 선언하고 컨트롤러가 요청 시점에 검사한다)
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
            'skip_waiting' => ['sometimes', 'boolean'],
        ];

        return HookManager::applyFilters('core.privacy.manual_run_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 오류 메시지
     */
    public function messages(): array
    {
        return [
            'skip_waiting.boolean' => __('validation.privacy_manual_run.skip_waiting.boolean'),
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
            'skip_waiting' => __('validation.attributes.skip_waiting'),
        ];
    }

    /**
     * 「대기기간 생략」 여부를 반환합니다.
     *
     * @return bool 생략 여부 (기본 false)
     */
    public function skipWaiting(): bool
    {
        return (bool) ($this->validated('skip_waiting') ?? false);
    }
}
