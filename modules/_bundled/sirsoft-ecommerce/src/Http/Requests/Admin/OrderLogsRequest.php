<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 주문 활동 로그 조회 요청 (관리자)
 *
 * 권한은 라우트 미들웨어(permission:admin,sirsoft-ecommerce.orders.read)에서 처리하며,
 * 여기서는 페이지 크기·정렬 옵션만 검증합니다.
 */
class OrderLogsRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인 (권한은 미들웨어에서 처리)
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
     * @return array<string, array<int, mixed>> 검증 규칙 배열
     */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort_order' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    /**
     * 활동 로그 조회 필터(페이지 크기·정렬)를 반환합니다.
     *
     * @return array{per_page: int, sort_order: string} 조회 필터
     */
    public function getFilters(): array
    {
        return [
            'per_page' => (int) ($this->query('per_page', 10)),
            'sort_order' => $this->query('sort_order', 'desc'),
        ];
    }
}
