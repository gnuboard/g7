<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Ecommerce\Models\Product;

/**
 * 구매 자격 판정 서비스
 *
 * 상품의 구매 대상 제한(purchase_restriction / allowed_roles)을 기준으로
 * 현재 사용자가 해당 상품을 구매할 수 있는지 판정합니다.
 *
 * 회원/비회원 공통으로 적용되며, 비회원은 코어 `guest` 역할로 판정합니다.
 * 핵심 판정 로직(filterRestrictedProducts)은 역할 ID 배열만 받는 순수 함수로,
 * 인증 컨텍스트와 분리되어 단위 테스트가 용이합니다.
 */
class PurchaseEligibilityService
{
    /**
     * 구매 제한 없음을 나타내는 값
     */
    public const RESTRICTION_NONE = 'none';

    /**
     * 구매 대상 제한이 설정됨을 나타내는 값
     */
    public const RESTRICTION_RESTRICTED = 'restricted';

    /**
     * 비회원 판정에 사용하는 코어 역할 식별자
     */
    public const GUEST_ROLE_IDENTIFIER = 'guest';

    /**
     * @param  RoleRepositoryInterface  $roleRepository  코어 역할 Repository (guest 역할 ID 조회용)
     */
    public function __construct(
        protected RoleRepositoryInterface $roleRepository
    ) {}

    /**
     * 상품 목록 중 현재 사용자가 구매할 수 없는(역할 제한에 걸리는) 상품을 가려냅니다.
     *
     * 판정 규칙:
     * - purchase_restriction 이 restricted 가 아니면 통과 (제한 없음)
     * - restricted 이면 사용자 역할 ID 중 하나가 상품의 allowed_roles 에 있어야 통과
     *
     * 인증 컨텍스트에 의존하지 않는 순수 판정 함수입니다.
     *
     * @param  Collection<int, Product>  $products  검사할 상품 컬렉션
     * @param  array<int, int>  $userRoleIds  현재 사용자의 역할 ID 배열 (비회원은 guest 역할 ID)
     * @return array<int, Product> 구매 불가 상품 목록 (구매 가능하면 빈 배열)
     */
    public function filterRestrictedProducts(Collection $products, array $userRoleIds): array
    {
        $restricted = [];

        foreach ($products as $product) {
            if (! $this->isPurchasableBy($product, $userRoleIds)) {
                $restricted[] = $product;
            }
        }

        return $restricted;
    }

    /**
     * 단일 상품을 주어진 역할 ID 배열로 구매할 수 있는지 판정합니다.
     *
     * @param  Product  $product  대상 상품
     * @param  array<int, int>  $userRoleIds  사용자 역할 ID 배열
     * @return bool 구매 가능 여부
     */
    public function isPurchasableBy(Product $product, array $userRoleIds): bool
    {
        // 제한 없음 → 누구나 구매 가능
        if ($product->purchase_restriction !== self::RESTRICTION_RESTRICTED) {
            return true;
        }

        $allowedRoles = $product->allowed_roles ?? [];

        // restricted 이지만 허용 역할이 비어 있으면 아무도 구매 불가
        if (empty($allowedRoles)) {
            return false;
        }

        // 정수 비교를 위해 역할 ID 정규화
        $allowedRoles = array_map('intval', $allowedRoles);
        $userRoleIds = array_map('intval', $userRoleIds);

        return count(array_intersect($userRoleIds, $allowedRoles)) > 0;
    }

    /**
     * 현재 사용자(또는 비회원)의 역할 ID 배열을 해석합니다.
     *
     * 회원은 보유한 모든 역할 ID, 비회원(null)은 코어 guest 역할 ID 로 판정합니다.
     * guest 역할이 존재하지 않으면 빈 배열을 반환하며, 이 경우 제한 상품은 모두 차단됩니다.
     *
     * @param  User|null  $user  현재 인증 사용자 (비회원이면 null)
     * @return array<int, int> 역할 ID 배열
     */
    public function resolveRoleIds(?User $user): array
    {
        if ($user === null) {
            return $this->guestRoleIds();
        }

        // pluck 시 roles 테이블을 명시 — user_roles join 으로 id 컬럼이 모호해지는 것을 회피
        return $user->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * 코어 guest 역할 ID 배열을 반환합니다.
     *
     * 역할 ID 는 환경마다 다르므로 식별자로 조회합니다 (하드코딩 금지).
     *
     * @return array<int, int> guest 역할 ID 배열 (없으면 빈 배열)
     */
    protected function guestRoleIds(): array
    {
        $guestRole = $this->roleRepository->findByIdentifier(self::GUEST_ROLE_IDENTIFIER);

        return $guestRole !== null ? [(int) $guestRole->id] : [];
    }
}
