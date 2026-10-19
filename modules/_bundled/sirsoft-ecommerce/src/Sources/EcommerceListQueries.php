<?php

namespace Modules\Sirsoft\Ecommerce\Sources;

use App\Helpers\PermissionHelper;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageTransactionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;

/**
 * 이커머스 관리 목록과 같은 조건의 원천 쿼리 (내보내기 대상 행)
 *
 * 화면 건수와 파일 행 수가 어긋나지 않으려면 목록과 **같은 필터 코드**를 지나야 한다. 그래서 필터는 이쪽에
 * 복제하지 않고 각 목록 저장소의 `applyListFilters()` 를 그대로 부른다 — 목록 메서드도 같은 메서드를 부르므로
 * 축이 늘면 두 경로가 함께 따라온다. 파리티 테스트가 축마다 목록 결과와 같은지 잠근다.
 *
 * 권한 범위는 요청자를 **명시해서** 건다 — 큐 작업에는 로그인 사용자가 없어, 생략하면 범위가 빠진다.
 */
class EcommerceListQueries
{
    /**
     * 주문 목록과 같은 조건의 주문 쿼리를 만듭니다.
     *
     * @param  array  $filters  검증된 주문 목록 필터
     * @param  array<int, int>|null  $ids  「선택한 행」 주문 ID (지정하면 필터 대신 이 ID 만)
     * @param  User  $requester  권한 범위를 판정할 요청자
     * @return Builder 주문 쿼리 (관계·정렬 없음)
     */
    public function orders(array $filters, ?array $ids, User $requester): Builder
    {
        $query = Order::query();
        PermissionHelper::applyPermissionScope($query, 'sirsoft-ecommerce.orders.read', $requester);

        if ($ids !== null) {
            // 선택한 행은 그 선택이 대상이다 — 목록 필터를 덧씌우지 않는다.
            return $query->whereIn('id', $ids);
        }

        app(OrderRepositoryInterface::class)->applyListFilters($query, $filters);

        return $query;
    }

    /**
     * 상품 목록과 같은 조건의 상품 쿼리를 만듭니다.
     *
     * @param  array  $filters  검증된 상품 목록 필터
     * @param  array<int, int>|null  $ids  「선택한 행」 상품 ID
     * @param  User  $requester  권한 범위를 판정할 요청자
     * @return Builder 상품 쿼리 (관계·정렬 없음)
     */
    public function products(array $filters, ?array $ids, User $requester): Builder
    {
        $query = Product::query();
        PermissionHelper::applyPermissionScope($query, 'sirsoft-ecommerce.products.read', $requester);

        if ($ids !== null) {
            return $query->whereIn('id', $ids);
        }

        app(ProductRepositoryInterface::class)->applyListFilters($query, $filters);

        return $query;
    }

    /**
     * 마일리지 내역 목록과 같은 조건의 거래 쿼리를 만듭니다.
     *
     * 범위는 관리 목록과 같은 마일리지 조회 범위(`mileage.read`)를 요청자 기준으로 건다.
     *
     * @param  array  $filters  검증된 마일리지 목록 필터
     * @param  array<int, int>|null  $ids  「선택한 행」 거래 ID
     * @param  User  $requester  권한 범위를 판정할 요청자
     * @return Builder 거래 쿼리 (관계·정렬 없음)
     */
    public function mileageTransactions(array $filters, ?array $ids, User $requester): Builder
    {
        $query = MileageTransaction::query();
        PermissionHelper::applyPermissionScope($query, 'sirsoft-ecommerce.mileage.read', $requester);

        if ($ids !== null) {
            return $query->whereIn('id', $ids);
        }

        app(MileageTransactionRepositoryInterface::class)->applyListFilters($query, $filters);

        return $query;
    }
}
