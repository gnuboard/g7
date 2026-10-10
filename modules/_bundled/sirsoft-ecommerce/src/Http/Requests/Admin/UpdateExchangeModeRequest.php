<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeModeEnum;

/**
 * 관리자 교환 진행 방식 변경 요청 — 일반 교환 ↔ 선교환 (D1)
 *
 * 바꿀 수 있는지(설정·단계·송장)는 서비스가 판정한다(`OrderExchangeService::setExchangeMode()`).
 */
class UpdateExchangeModeRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인 (권한 체크는 라우트의 permission 미들웨어에서 수행)
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed> 검증 규칙 배열
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', Rule::in(ExchangeModeEnum::values())],
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 메시지 배열
     */
    public function messages(): array
    {
        return [
            'mode.required' => __('sirsoft-ecommerce::validation.claims.exchange_mode.required'),
            'mode.in' => __('sirsoft-ecommerce::validation.claims.exchange_mode.in'),
        ];
    }

    /**
     * 검증 오류 문구에 쓰는 필드 표시명
     *
     * @return array<string, string> 필드 키 → 표시명
     */
    public function attributes(): array
    {
        return [
            'mode' => __('sirsoft-ecommerce::validation.request_attributes.exchange_mode'),
        ];
    }

    /**
     * 바꿀 진행 방식을 반환합니다.
     *
     * @return ExchangeModeEnum 진행 방식
     */
    public function mode(): ExchangeModeEnum
    {
        return ExchangeModeEnum::from((string) $this->validated('mode'));
    }
}
