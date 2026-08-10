<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 활성 배송사 목록(Select 옵션용) 조회 요청을 검증합니다.
 */
class ActiveShippingCarrierRequest extends FormRequest
{
    /**
     * 요청 권한 — 라우트 permission 미들웨어가 담당하므로 true 고정.
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
        return [
            'type' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * 배송사 유형 필터를 반환합니다.
     *
     * @return string|null 유형 (미지정 시 null)
     */
    public function type(): ?string
    {
        $type = $this->validated('type');

        return $type === null ? null : (string) $type;
    }
}
