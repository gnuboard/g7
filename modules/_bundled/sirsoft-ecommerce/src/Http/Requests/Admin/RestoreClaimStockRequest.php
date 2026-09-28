<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 뒤늦은 재고 복구 요청 (S13 — 완료됐지만 재고를 복구하지 않은 상품 행)
 *
 * 복구할 수 있는지(완료 · 미복구 · 미회수 아님)는 서비스가 행을 잠근 뒤 판정한다
 * (`AdminClaimService::restoreStock()`). `items` 를 비우면 복구 가능한 행 전부다.
 */
class RestoreClaimStockRequest extends FormRequest
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
            'items' => ['nullable', 'array', 'max:200'],
            'items.*' => ['integer', 'min:1'],
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
            'items.array' => __('sirsoft-ecommerce::validation.claims.items.array'),
            'items.*.integer' => __('sirsoft-ecommerce::validation.claims.items.order_option_id_required'),
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
            'items' => __('sirsoft-ecommerce::validation.request_attributes.claim_item'),
            'items.*' => __('sirsoft-ecommerce::validation.request_attributes.claim_item'),
        ];
    }

    /**
     * 복구할 상품 행 ID 목록을 반환합니다. (빈 배열 = 복구 가능한 행 전부)
     *
     * @return array<int, int> 상품 행 ID
     */
    public function itemIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) ($this->validated('items') ?? []))));
    }
}
