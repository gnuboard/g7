<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ResolvesClaimOrder;

/**
 * 주문 스코프 클레임 조회 요청 (입력 본문 없음 — 회원 `claimable-options`)
 *
 * 검증할 본문은 없고, 라우트의 주문을 **소유권이 확인된 주문**으로 해석하는 일만 한다
 * (`ResolvesClaimOrder::getOrder()`). 컨트롤러가 주문 Repository 를 직접 들고 `user_id` 를 비교하던
 * 사본을 걷어 내고, 주문 소유권 판정을 형제 신청 요청들과 같은 한 곳으로 모은다.
 */
class ClaimOrderRequest extends FormRequest
{
    use ResolvesClaimOrder;

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
     * 요청에 적용할 검증 규칙 (본문 없음)
     *
     * @return array<string, mixed> 검증 규칙 배열
     */
    public function rules(): array
    {
        return [];
    }
}
