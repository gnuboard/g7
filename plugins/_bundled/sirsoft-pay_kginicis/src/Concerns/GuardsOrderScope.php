<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Concerns;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\OrderAccess;

/**
 * 관리자 PG 연동 경로의 주문 권한 범위(「본인만」·「소유역할」) 재적용
 *
 * 이 플러그인의 관리자 라우트는 이커머스 주문 권한(`sirsoft-ecommerce.orders.*`·`claims.execute-refund`)을
 * 걸지만 주문을 `{orderNumber}`·요청 본문의 거래번호로 받는다. 권한 미들웨어는 라우트 파라미터가 권한의
 * `resource_route_key`(`order`)로 Model 바인딩될 때만 범위를 검사하므로 이 경로에서는 범위가 적용되지
 * 않았다. 컨트롤러가 주문을 찾은 직후 여기서 같은 판정(코어 `PermissionHelper`)을 다시 한다.
 *
 * 거부는 예외가 아니라 403 응답으로 돌려준다 — 컨트롤러의 넓은 `catch (\Exception)` 에 삼켜져 500 으로
 * 위장되지 않게 하기 위해서다. 주문이 없으면 판정하지 않는다(부재 응답은 각 경로의 기존 계약을 따른다).
 *
 * 판정은 이커머스 공개 계약 {@see OrderAccess} 가 한다(#916). 호스트 요건: 생성자에서
 * `PaymentGatewayBridge`(또는 `OrderAccess`)를 `$bridge` 로 주입받는다.
 */
trait GuardsOrderScope
{
    /**
     * 주문번호의 주문이 액터의 권한 범위 밖이면 403 응답을 돌려줍니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $permission  라우트가 거는 이커머스 권한 식별자
     * @return JsonResponse|null 범위 밖이면 403 응답, 아니면 null
     */
    protected function orderScopeDenial(string $orderNumber, string $permission): ?JsonResponse
    {
        // 주문이 없으면(null) 판정하지 않는다 — 부재 응답은 각 경로의 기존 계약을 따른다.
        if ($this->bridge->isOrderWithinScope($orderNumber, $permission) !== false) {
            return null;
        }

        return ResponseHelper::forbidden('auth.scope_denied');
    }

    /**
     * 거래번호(TID)의 주문이 액터의 권한 범위 밖이면 403 응답을 돌려줍니다.
     *
     * 범위가 「전체」 면 판정하지 않는다. 범위가 좁혀진 액터에게는 이 사이트 주문에 연결되지 않은 거래번호도
     * 거부한다 — 판정할 주문이 없는 거래를 PG 에서 조회해 보여 주면 범위가 의미를 잃는다.
     *
     * @param  string  $tid  거래번호
     * @param  string  $permission  라우트가 거는 이커머스 권한 식별자
     * @return JsonResponse|null 범위 밖이면 403 응답, 아니면 null
     */
    protected function transactionScopeDenial(string $tid, string $permission): ?JsonResponse
    {
        // 범위 「전체」 판정과 거래번호 → 주문 조회는 계약 안에서 한다(이 결제사 결제 행만 본다).
        if ($this->bridge->isTransactionWithinScope('kginicis', $tid, $permission)) {
            return null;
        }

        return ResponseHelper::forbidden('auth.scope_denied');
    }

    /**
     * 주문번호 목록에서 액터의 권한 범위 안에 있는 것만 남깁니다.
     *
     * @param  array<int, string>  $orderNumbers  주문번호 목록
     * @param  string  $permission  라우트가 거는 이커머스 권한 식별자
     * @return array<int, string> 범위 안 주문번호
     */
    protected function orderNumbersWithinScope(array $orderNumbers, string $permission): array
    {
        return $this->bridge->orderNumbersWithinScope($orderNumbers, $permission);
    }
}
