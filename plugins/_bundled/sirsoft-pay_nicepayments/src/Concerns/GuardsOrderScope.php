<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Concerns;

use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;

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
        // audit:allow controller-direct-data-access reason: PG 플러그인은 이커머스 Repository 에 의존하지 않는다(모듈 버전 제약 연쇄) — 주문 모델 단건 조회만
        $order = Order::query()->where('order_number', $orderNumber)->first();

        if ($order === null || PermissionHelper::checkScopeAccess($order, $permission)) {
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
        if (PermissionHelper::getEffectiveScope($permission) === null) {
            return null;
        }

        // audit:allow controller-direct-data-access reason: PG 플러그인은 이커머스 Repository 에 의존하지 않는다(모듈 버전 제약 연쇄) — 거래번호의 주문 단건 조회만
        $order = Order::query()
            ->whereIn('id', OrderPayment::query()->where('transaction_id', $tid)->select('order_id'))
            ->first();

        if ($order !== null && PermissionHelper::checkScopeAccess($order, $permission)) {
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
        if ($orderNumbers === [] || PermissionHelper::getEffectiveScope($permission) === null) {
            return $orderNumbers;
        }

        // audit:allow controller-direct-data-access reason: PG 플러그인은 이커머스 Repository 에 의존하지 않는다(모듈 버전 제약 연쇄) — 범위 필터는 코어 PermissionHelper 가 건다
        $query = Order::query()->whereIn('order_number', $orderNumbers);
        PermissionHelper::applyPermissionScope($query, $permission);

        return $query->pluck('order_number')->map(fn ($number) => (string) $number)->all();
    }
}
