<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 관련 상품 목록 조회 요청 (공개)
 *
 * 상품 상세 「이 상품은 어떠세요?」 목록의 반환 개수 제한 파라미터를 검증합니다.
 */
class PublicProductRelatedRequest extends FormRequest
{
    /**
     * 권한 확인 — 공개 상품 읽기 권한은 라우트의 permission 미들웨어가 판정한다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array<string, array<int, string>> 검사 규칙
     */
    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * 검증 에러 메시지 정의 — 인기 상품 조회와 같은 문구를 쓴다.
     *
     * @return array<string, string> 규칙별 메시지
     */
    public function messages(): array
    {
        return [
            'limit.integer' => __('sirsoft-ecommerce::validation.public_product.limit.integer'),
            'limit.min' => __('sirsoft-ecommerce::validation.public_product.limit.min'),
            'limit.max' => __('sirsoft-ecommerce::validation.public_product.limit.max'),
        ];
    }
}
