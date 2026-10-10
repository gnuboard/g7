<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCancelRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Support\ClaimScopePermissions;
use Modules\Sirsoft\Ecommerce\Traits\ReappliesPermissionScope;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 관리자 클레임의 권한 스코프 가드 (주문 스코프 상속)
 *
 * 신청서의 스코프 축은 **그 주문의 것**이다 — `module.php` 의 `claims` 권한이 `orders` 와 같은
 * `resource_route_key = order` · `owner_key = user_id` 를 선언한다. 판정 대상 모델이 신청서가 아니라
 * 주문인 이유는, 신청서를 통해 그 주문의 내용·환불 계좌를 보고 환불을 실행하는 것이 곧 그 주문을
 * 다루는 일이기 때문이다. 주문을 볼 수 없는 운영자가 신청서로는 같은 일을 할 수 있으면 주문 스코프가
 * 우회된다.
 *
 * 판정은 그 라우트의 클레임 권한 범위와 **운영자의 주문 조회 범위의 교집합**이다(EC-667) — 클레임
 * 권한만 보면 주문 조회를 「본인만」 으로 묶은 운영자가 클레임 기본값 「전체」 로 남의 주문 신청서를
 * 보고 처리한다. 판정 대상 권한 목록은 `ClaimScopePermissions` 단일 출처다(주문 조회 권한을 보유하지
 * 않은 클레임 전담 운영자는 클레임 권한 범위만으로 판정한다 — 근거는 그 클래스 주석).
 *
 * 세 경로가 같은 판정을 쓴다:
 *  - `orders/{order}/…` 라우트 — `PermissionMiddleware` 가 바인딩된 주문으로 그 라우트의 클레임 권한을
 *    판정하고, 주문 조회 범위는 이 가드가 `assertOrderAccessible()` 로 보탠다(미들웨어는 라우트 권한
 *    하나만 본다).
 *  - `{claimType}/{id}`·번호 기반 라우트 — 파라미터가 모델로 바인딩되지 않아 미들웨어가 건너뛰므로
 *    이 가드가 `assertAccessible()` 로 재적용한다(`ReappliesPermissionScope` 의 근거와 같다).
 *  - 목록·건수 — 저장소가 같은 조건을 쿼리에 건다(`FiltersAdminClaimList::applyAdminClaimScope()`).
 *    일괄 경로(`assertIdsAccessible()`)는 그 저장소 조건으로 센다 — 목록에 보이지 않는 신청서를
 *    일괄 전이로 건드리지 못하게 한다.
 */
class ClaimScopeGuard
{
    use ReappliesPermissionScope;

    /**
     * @param  OrderExchangeRepositoryInterface  $exchangeRepository  교환 신청서 Repository
     * @param  OrderReturnRepositoryInterface  $returnRepository  반품 신청서 Repository
     * @param  OrderRefundRepositoryInterface  $refundRepository  환불신청서 Repository
     * @param  OrderCancelRepositoryInterface  $cancelRepository  취소신청서 Repository
     */
    public function __construct(
        protected OrderExchangeRepositoryInterface $exchangeRepository,
        protected OrderReturnRepositoryInterface $returnRepository,
        protected OrderRefundRepositoryInterface $refundRepository,
        protected OrderCancelRepositoryInterface $cancelRepository,
    ) {}

    /**
     * 신청서가 액터의 스코프 안에 있는지 검사합니다. (판정 대상 = 신청서의 주문)
     *
     * 주문이 없는 신청서는 스코프로 판정할 근거가 없어 막는다 — 클레임 권한·주문 조회 모두 전체
     * 범위인 운영자만 통과한다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @param  string  $permission  스코프 대상 권한 식별자 (라우트 게이트와 같은 것)
     * @return void 반환값 없음
     *
     * @throws AccessDeniedHttpException 스코프 밖인 경우
     */
    public function assertAccessible(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim, string $permission): void
    {
        $claim->loadMissing('order');

        if ($claim->order === null) {
            // 전체 스코프면 통과, 아니면 판정 근거가 없으므로 거절한다.
            if (ClaimScopePermissions::scoped($permission) !== []) {
                throw new AccessDeniedHttpException(__('auth.scope_denied'));
            }

            return;
        }

        $this->assertOrderAccessible($claim->order, $permission);
    }

    /**
     * 주문이 액터의 클레임 권한 범위와 주문 조회 범위 안에 모두 있는지 검사합니다.
     *
     * 신청서 경로(`assertAccessible()`)와 주문에 묶인 클레임 라우트(`orders/{order}/…`)가 함께 쓴다.
     * 주문에 묶인 라우트는 미들웨어가 그 라우트의 클레임 권한만 판정하므로, 여기서 주문 조회 범위를
     * 보태야 「주문 조회 본인만 + 클레임 전체」 운영자가 남의 주문 클레임 내역·접수 문맥을 열지 못한다.
     *
     * @param  Order  $order  판정 대상 주문
     * @param  string  $permission  그 라우트의 클레임 권한 식별자
     * @return void 반환값 없음
     *
     * @throws AccessDeniedHttpException 어느 한 범위라도 밖인 경우
     */
    public function assertOrderAccessible(Order $order, string $permission): void
    {
        foreach (ClaimScopePermissions::for($permission) as $identifier) {
            $this->assertWithinScope($order, $identifier);
        }
    }

    /**
     * 지정한 신청서 ID 전부가 액터의 스코프 안에 있는지 검사합니다. (일괄 경로)
     *
     * 걸러내지 않고 **전량 거부**한다 — 일괄 작업은 호출자가 지정한 집합에 대한 한 번의
     * 의사표시라, 일부만 조용히 빠지면 호출자는 전부 반영됐다고 믿는다(`ReappliesPermissionScope`
     * 와 같은 규율). 존재하지 않는 ID 는 여기서 판정하지 않는다 — 부재는 서비스가 건별로 보고한다.
     *
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @param  array<int, int>  $ids  신청서 ID 목록
     * @param  string  $permission  스코프 대상 권한 식별자
     * @return void 반환값 없음
     *
     * @throws AccessDeniedHttpException 스코프 밖 신청서가 하나라도 있는 경우
     */
    public function assertIdsAccessible(ClaimTypeEnum $type, array $ids, string $permission): void
    {
        if ($ids === [] || ClaimScopePermissions::scoped($permission) === []) {
            return;
        }

        $repository = match ($type) {
            ClaimTypeEnum::EXCHANGE => $this->exchangeRepository,
            ClaimTypeEnum::RETURN => $this->returnRepository,
            ClaimTypeEnum::REFUND => $this->refundRepository,
            ClaimTypeEnum::CANCEL => $this->cancelRepository,
        };

        $existing = $repository->countWithinAdminScope($ids, null);
        $within = $repository->countWithinAdminScope($ids, $permission);

        if ($within < $existing) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }
    }
}
