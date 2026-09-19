<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;

/**
 * 관리자 상품문의 일괄 삭제 요청
 *
 * 관리자는 이미 전 회원의 행을 조회할 수 있으므로 exists 를 걸어도 ID 탐색 통로가 되지 않는다.
 * 없는 ID 를 조용히 삼키는 대신 422 로 되돌려 목록이 낡았다는 사실을 드러낸다.
 *
 * `ProductInquiry` 는 소프트 삭제 모델이라 `Rule::exists` 만으로는 이미 지워진 행까지 통과한다 —
 * `exists` 는 쿼리 빌더로 직접 테이블을 치므로 모델의 `SoftDeletingScope` 를 타지 않는다. 그대로 두면
 * 목록에서 사라진 문의를 다시 골라 보내도 422 가 아니라 「0건 삭제」가 나가 목록이 낡았다는 사실이
 * 가려진다. 위 문단의 의도를 지키려면 살아 있는 행으로 좁혀야 한다.
 */
class AdminInquiryBulkDeleteRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
     *
     * @return bool 항상 true (권한은 미들웨어에서 검증)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct', Rule::exists(ProductInquiry::class, 'id')->whereNull('deleted_at')],
        ];
    }
}
