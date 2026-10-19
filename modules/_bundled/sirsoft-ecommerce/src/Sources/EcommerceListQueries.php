<?php

namespace Modules\Sirsoft\Ecommerce\Sources;

use App\Helpers\PermissionHelper;
use App\Models\User;
use App\Repositories\Concerns\FiltersByDateRange;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use LogicException;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageTransactionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\MileageTransactionRepository;
use Modules\Sirsoft\Ecommerce\Repositories\OrderRepository;
use Modules\Sirsoft\Ecommerce\Repositories\ProductRepository;

/**
 * 이커머스 관리 목록과 같은 조건의 원천 쿼리 (내보내기 대상 행)
 *
 * 화면 건수와 파일 행 수가 어긋나지 않으려면 목록과 **같은 필터 코드**를 지나야 한다. 이 플러그인은 이커머스
 * 모듈을 고칠 수 없으므로, 목록 저장소가 감춰 둔 필터 메서드를 그 저장소의 범위로 묶은 클로저로 부른다
 * (`Closure::bind`). 필터를 이쪽에 복제하면 모듈이 필터를 늘리는 순간 파일만 조용히 전건이 되지만, 이렇게
 * 부르면 축이 늘어도 함께 따라오고 메서드 이름이 바뀌면 조용히 틀리는 대신 바로 실패한다.
 *
 * 마일리지 목록만은 필터가 목록 메서드 안에 직접 쓰여 있어 따로 부를 메서드가 없다 — 그 본문을 옮겨 두고,
 * 저장소의 도우미(거래유형 분류)만 같은 방식으로 빌린다. 파리티 테스트가 목록 건수와 비교해 어긋남을 잡는다.
 *
 * 권한 범위는 요청자를 **명시해서** 건다 — 큐 작업에는 로그인 사용자가 없어, 생략하면 범위가 빠진다.
 */
class EcommerceListQueries
{
    use FiltersByDateRange;

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

        $this->callInside($this->repository(OrderRepositoryInterface::class), OrderRepository::class, function (Builder $query, array $filters): void {
            /** @var OrderRepository $this */
            $this->applyListFilters($query, $filters);
        }, $query, $filters);

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

        // 검색어 분기는 목록 메서드(getListWithFilters) 본문과 같다 — FULLTEXT 대상 필드는 저장소의 매칭
        // 도우미를, 코드류 필드는 LIKE 를 쓴다. 이어서 저장소의 필터 메서드를 그대로 부른다.
        $this->callInside($this->repository(ProductRepositoryInterface::class), ProductRepository::class, function (Builder $query, array $filters): void {
            /** @var ProductRepository $this */
            if (! empty($filters['search_keyword'])) {
                $keyword = $filters['search_keyword'];
                $field = $filters['search_field'] ?? 'all';

                if (in_array($field, ['all', 'name', 'description'])) {
                    $this->applyKeywordMatch($query, $keyword, includeAuxFields: $field === 'all');
                } else {
                    $query->where(function ($q) use ($keyword, $field) {
                        if ($field === 'product_code') {
                            $q->where('product_code', 'like', "%{$keyword}%");
                        }
                        if ($field === 'sku') {
                            $q->where('sku', 'like', "%{$keyword}%");
                        }
                        if ($field === 'barcode') {
                            $q->where('barcode', 'like', "%{$keyword}%");
                        }
                    });
                }
            }

            $this->applyAdminFilters($query, $filters);
        }, $query, $filters);

        return $query;
    }

    /**
     * 마일리지 내역 목록과 같은 조건의 거래 쿼리를 만듭니다.
     *
     * 관리 목록은 아직 권한 범위를 걸지 않지만, 내보내기는 요청자의 마일리지 조회 범위를 건다 — 전체 범위
     * 운영자에게는 목록과 같은 결과다.
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

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        // 거래유형: UI 4분류 슬러그(earn/use/expire/adjust)를 8종 enum 으로 — 저장소의 분류표를 빌린다
        if (! empty($filters['type'])) {
            $types = $this->callInside($this->repository(MileageTransactionRepositoryInterface::class), MileageTransactionRepository::class, function (string $category): array {
                /** @var MileageTransactionRepository $this */
                return $this->typesForCategory($category);
            }, (string) $filters['type']);
            $query->whereIn('type', $types);
        }

        if (! empty($filters['currency'])) {
            $query->where('currency', $filters['currency']);
        }

        // 기간 — 목록과 같은 코어 도우미(보는 사람의 하루 경계)
        $this->applyViewerDateRangeFilter($query, 'created_at', $filters['start_date'] ?? null, $filters['end_date'] ?? null);

        if (! empty($filters['search_keyword'])) {
            $keyword = $filters['search_keyword'];
            $field = $filters['search_field'] ?? 'member';

            $query->where(function ($outer) use ($field, $keyword) {
                switch ($field) {
                    case 'member_id':
                        $outer->where('user_id', $keyword);
                        break;
                    case 'email':
                        $outer->whereHas('user', fn ($q) => $q->where('email', 'like', "%{$keyword}%"));
                        break;
                    case 'order':
                        $outer->whereHas('order', fn ($q) => $q->where('order_number', 'like', "%{$keyword}%"));
                        break;
                    case 'member':
                    default:
                        $outer->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$keyword}%"));
                        break;
                }
            });
        }

        return $query;
    }

    /**
     * 이커머스 저장소를 꺼냅니다(조회할 때 꺼낸다 — 모듈이 꺼져도 유형 목록·관리 화면은 만들어져야 한다).
     *
     * @param  class-string  $contract  저장소 계약
     * @return object 저장소
     */
    private function repository(string $contract): object
    {
        return app($contract);
    }

    /**
     * 저장소 인스턴스의 범위에서 클로저를 실행합니다(감춰 둔 필터 메서드 호출용).
     *
     * @param  object  $repository  컨테이너가 준 저장소
     * @param  class-string  $declaringClass  필터 메서드를 선언한 저장소 클래스
     * @param  Closure  $body  실행할 본문 (`$this` = 저장소)
     * @param  mixed  ...$args  본문 인자
     * @return mixed 본문 반환값
     *
     * @throws LogicException 다른 구현이 바인딩돼 같은 필터 경로를 보장할 수 없을 때
     */
    private function callInside(object $repository, string $declaringClass, Closure $body, mixed ...$args): mixed
    {
        if (! $repository instanceof $declaringClass) {
            throw new LogicException(__('sirsoft-ecommerce::exceptions.export.source_unavailable', ['class' => $declaringClass]));
        }

        return Closure::bind($body, $repository, $declaringClass)(...$args);
    }
}
