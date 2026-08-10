<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 상품 처리로그(활동 로그) 목록 조회 요청 (관리자)
 *
 * 권한은 라우트 미들웨어(permission:admin,sirsoft-ecommerce.products.read)에서 처리하며,
 * 여기서는 페이지 크기·정렬 옵션만 검증합니다.
 */
class ProductLogsRequest extends FormRequest
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
        $rules = [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort_order' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.product.logs_validation_rules', $rules, $this);
    }

    /**
     * 활동 로그 조회 필터(페이지 크기·정렬)를 반환합니다.
     *
     * 고정 키만 열거하면 확장이 훅으로 규칙을 얹어도 그 값이 Service 까지 도달하지 못하므로,
     * 검증을 통과한 전체 데이터 위에 기본값이 적용된 고정 키를 덮어쓴다.
     *
     * @return array<string, mixed> 조회 필터 (per_page, sort_order + 확장 추가 필드)
     */
    public function getFilters(): array
    {
        return array_merge($this->validated(), [
            'per_page' => (int) ($this->query('per_page', 10)),
            'sort_order' => $this->query('sort_order', 'desc'),
        ]);
    }
}
