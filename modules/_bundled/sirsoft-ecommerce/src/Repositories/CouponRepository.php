<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Helpers\PermissionHelper;
use App\Helpers\TimezoneHelper;
use App\Models\User;
use App\Repositories\Concerns\FiltersByDateRange;
use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use App\Repositories\Concerns\ResolvesSortSpec;
use App\Search\KeywordSearch;
use App\Support\Query\BoundedPaginator;
use App\Support\Query\PaginationLimits;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Support\MemberDisplayName;

/**
 * 쿠폰 Repository 구현체
 */
class CouponRepository implements CouponRepositoryInterface
{
    use FiltersByDateRange;
    use PaginatesWithDeferredJoin;
    use ResolvesSortSpec;

    /** 허용 정렬 컬럼 (CouponListRequest 와 동일 집합) */
    private const SORTABLE_COLUMNS = ['created_at', 'name', 'discount_value', 'issued_count', 'valid_to'];

    /**
     * 쿠폰 저장소를 생성합니다.
     *
     * @param  Coupon  $model  쿠폰 모델
     * @param  CouponIssue  $issueModel  쿠폰 발급 모델
     */
    public function __construct(
        protected Coupon $model,
        protected CouponIssue $issueModel
    ) {}

    /**
     * {@inheritDoc}
     */
    public function paginate(array $filters = [], int $perPage = 10, array $with = []): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        // 권한 스코프 필터링
        PermissionHelper::applyPermissionScope($query, 'sirsoft-ecommerce.promotion-coupon.read');

        // 발급내역 카운트 추가
        $query->withCount('issues');

        // 필터 적용
        $this->applyCouponFilters($query, $filters);

        // 키워드 검색
        if (! empty($filters['search_keyword'])) {
            $keyword = $filters['search_keyword'];
            $searchField = $filters['search_field'] ?? 'all';

            // all → FULLTEXT(name+description) + creator 보조필드 union (Scout queryCallback total
            // 재계산 시 orWhereHas('creator') 가 MATCH 절 없이 재적용되어 total=0 이 되는 결함 회피)
            if ($searchField === 'all') {
                // FULLTEXT 매칭과 작성자 보조 매칭을 하나의 WHERE 그룹으로 묶어 페이지 쿼리에
                // 직접 넣는다. 종전에는 매칭 ID 전량을 PHP 로 끌어와 whereIn 으로 되먹여,
                // 매칭이 많을수록 메모리와 IN(...) 목록이 함께 커졌다.
                $query->where(function ($group) use ($keyword) {
                    // 정제는 코어 헬퍼가 단독 수행 — BOOLEAN MODE 연산자 입력이 500 이 되지 않는다.
                    // 쿠폰 테이블도 컬럼별 단일 FULLTEXT 인덱스다 — 복합 MATCH 는 쓸 수 없다.
                    KeywordSearch::applyAny($group, ['name', 'description'], $keyword);

                    // 등록자는 이름과 닉네임을 모두 찾는다(관리자 화면의 회원 검색 공통 규칙). 이메일도 종전대로 찾는다.
                    $group->orWhereHas('creator', fn ($creatorQuery) => MemberDisplayName::constrainUsersForAdmin($creatorQuery, (string) $keyword, withEmail: true));
                });
            } elseif ($searchField === 'name') {
                // 컬럼 한정 검색 — Scout(FULLTEXT)는 컬럼 한정 미지원이므로 단일컬럼 LIKE
                $query->where('name', 'like', "%{$keyword}%");
            } elseif ($searchField === 'description') {
                $query->where('description', 'like', "%{$keyword}%");
            } elseif ($searchField === 'created_by') {
                $query->whereHas('creator', fn ($creatorQuery) => MemberDisplayName::constrainUsersForAdmin($creatorQuery, (string) $keyword, withEmail: true));
            }
        }

        return $this->finalizeListQuery($query, $filters, $with, $perPage);
    }

    /**
     * 목록 쿼리의 정렬·eager loading 을 적용하고 페이지네이션 결과를 반환합니다.
     *
     * 검색 분기별로 중복되던 정렬/eager/paginate 를 단일 진입점으로 모았습니다.
     *
     * @param  Builder  $query  적용 대상 쿼리(권한/필터/검색 조건이 이미 적용된 상태)
     * @param  array  $filters  정렬 필터(sort_by/sort_order)
     * @param  array  $with  추가 eager loading 관계
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 페이지네이션된 쿠폰 목록
     */
    private function finalizeListQuery(Builder $query, array $filters, array $with, int $perPage): LengthAwarePaginator
    {
        // 정렬 (허용 컬럼 화이트리스트로 해석)
        $sort = $this->resolveSortSpec($filters, self::SORTABLE_COLUMNS, 'created_at')[0];
        $query->orderBy($sort['column'], $sort['direction'])->orderBy('id', $sort['direction']);

        // Eager loading
        if (! empty($with)) {
            $query->with($with);
        }

        // 기본 관계 로드
        $query->with(['creator:'.User::displayColumns()]);

        // audit:allow repository-paginate-column-pruning reason: 쿠폰 "정의" 목록 —
        // 행 수가 운영자가 만든 쿠폰 수에 묶인다(발급 이력은 별도 테이블). description 외에 넓은 컬럼이 없다
        //
        // 키워드 검색은 FULLTEXT + creator 보조 매칭이라 매칭 수가 데이터 증가에 비례한다.
        // 총 건수는 상한까지만 세고, "다음" 이동은 per_page + 1 실측으로 끝까지 열어 둔다.
        return BoundedPaginator::paginate(
            $query,
            perPage: $perPage,
            resultCap: PaginationLimits::resultCap('ecommerce.coupons'),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id, array $with = []): ?Coupon
    {
        $query = $this->model->newQuery();

        if (! empty($with)) {
            $query->with($with);
        }

        // 발급내역 카운트 추가
        $query->withCount('issues');

        return $query->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): Coupon
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(int $id, array $data): Coupon
    {
        $coupon = $this->findById($id);
        $coupon->update($data);

        return $coupon->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(int $id): bool
    {
        $coupon = $this->findById($id);

        return $coupon->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateIssueStatus(array $ids, string $issueStatus): int
    {
        return $this->model->whereIn('id', $ids)->update(['issue_status' => $issueStatus]);
    }

    /**
     * {@inheritDoc}
     */
    public function getIssues(int $couponId, array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $query = $this->issueModel->newQuery();

        $query->forCoupon($couponId);

        // 회원 필터 (UUID → 정수 FK 변환)
        if (! empty($filters['user_id'])) {
            $query->whereHas('user', fn ($q) => $q->where('uuid', $filters['user_id']));
        }

        // 상태 필터
        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        // 지연 조인: 쿠폰 1건의 발급 이력은 대량 발급 시 수십만 건이 될 수 있어
        // 뒤쪽 페이지에서 OFFSET 비용이 그대로 누적된다. inner 는 id 만 훑는다.
        return $this->paginateWithDeferredJoin(
            query: $query,
            columns: ['*'],
            sort: [
                ['column' => 'issued_at', 'direction' => 'desc'],
                ['column' => 'id', 'direction' => 'desc'],
            ],
            perPage: $perPage,
            // 관계 로드 (사용처 주문번호 표시를 위해 order 도 로드)
            relations: ['user:'.User::displayColumns(), 'order:id,order_number'],
            resultCap: PaginationLimits::resultCap('ecommerce.coupon_issues'),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function incrementIssuedCount(int $couponId, int $count = 1): void
    {
        $this->model->where('id', $couponId)->increment('issued_count', $count);
    }

    /**
     * {@inheritDoc}
     */
    public function decrementIssuedCount(int $couponId, int $count = 1): void
    {
        // 음수 방지: 현재 발급 수량을 초과해 감소하지 않도록 GREATEST(0, ...) 처리
        $this->model->where('id', $couponId)
            ->where('issued_count', '>=', $count)
            ->decrement('issued_count', $count);
    }

    /**
     * {@inheritDoc}
     */
    public function syncProducts(Coupon $coupon, array $products): void
    {
        // 기존 연결 제거 후 새로 연결
        $syncData = [];
        foreach ($products as $product) {
            $syncData[$product['id']] = ['type' => $product['type']];
        }
        $coupon->products()->sync($syncData);
    }

    /**
     * {@inheritDoc}
     */
    public function syncCategories(Coupon $coupon, array $categories): void
    {
        // 기존 연결 제거 후 새로 연결
        $syncData = [];
        foreach ($categories as $category) {
            $syncData[$category['id']] = ['type' => $category['type']];
        }
        $coupon->categories()->sync($syncData);
    }

    /**
     * {@inheritDoc}
     */
    public function getDownloadableCoupons(?int $perPage = null): LengthAwarePaginator|Collection
    {
        $query = $this->model->newQuery()
            ->where('issue_method', CouponIssueMethod::DOWNLOAD)
            ->where('issue_condition', CouponIssueCondition::MANUAL)
            ->where('issue_status', CouponIssueStatus::ISSUING)
            ->where(fn ($q) => $q->whereNull('issue_from')->orWhere('issue_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('issue_to')->orWhere('issue_to', '>=', now()))
            ->where(fn ($q) => $q->whereNull('total_quantity')->orWhereColumn('issued_count', '<', 'total_quantity'))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', now()))
            ->with(['includedProducts', 'excludedProducts', 'includedCategories', 'excludedCategories'])
            // 정렬 마지막의 기본키는 전순서 보장용이다 (일괄 등록된 쿠폰의 created_at 동률 대비)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        if ($perPage !== null) {
            // audit:allow repository-paginate-column-pruning reason: 다운로드 가능 쿠폰 "정의" 목록 —
            // 발급중 상태의 쿠폰만 남는 좁은 집합이라 OFFSET 이 깊어질 수 없다
            return $query->paginate($perPage);
        }

        return $query->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findByIdForUpdate(int $id): ?Coupon
    {
        return $this->model->newQuery()->lockForUpdate()->find($id);
    }

    /**
     * 쿠폰 필터 조건을 쿼리에 적용합니다.
     *
     * @param  Builder  $query  Eloquent 쿼리 빌더
     * @param  array  $filters  필터 배열
     * @return void 반환값 없음
     */
    private function applyCouponFilters($query, array $filters): void
    {
        // 적용대상 필터
        if (! empty($filters['target_type']) && $filters['target_type'] !== 'all') {
            $query->byTargetType($filters['target_type']);
        }

        // 할인타입 필터
        if (! empty($filters['discount_type']) && $filters['discount_type'] !== 'all') {
            $query->where('discount_type', $filters['discount_type']);
        }

        // 발급상태 필터
        if (! empty($filters['issue_status']) && $filters['issue_status'] !== 'all') {
            $query->where('issue_status', $filters['issue_status']);
        }

        // 발급방법 필터
        if (! empty($filters['issue_method']) && $filters['issue_method'] !== 'all') {
            $query->byIssueMethod($filters['issue_method']);
        }

        // 발급조건 필터
        if (! empty($filters['issue_condition']) && $filters['issue_condition'] !== 'all') {
            $query->byIssueCondition($filters['issue_condition']);
        }

        // 혜택금액 범위 필터
        // 0 은 유효한 경계값이다. empty() 로 거르면 0 이 "미입력"이 되어 필터가 무시된다.
        if (isset($filters['min_benefit_amount']) && $filters['min_benefit_amount'] !== '') {
            $query->where('discount_value', '>=', $filters['min_benefit_amount']);
        }
        if (isset($filters['max_benefit_amount']) && $filters['max_benefit_amount'] !== '') {
            $query->where('discount_value', '<=', $filters['max_benefit_amount']);
        }

        // 최소주문금액 필터 (0 = 최소주문금액 제한 없는 쿠폰까지 포함하는 유효 경계)
        if (isset($filters['min_order_amount']) && $filters['min_order_amount'] !== '') {
            $query->where('min_order_amount', '>=', $filters['min_order_amount']);
        }

        // 등록일 필터 — 목록이 등록일을 보는 사람의 시간대로 그리므로 그 하루로 자른다.
        $this->applyViewerDateRangeFilter($query, 'created_at', $filters['created_start_date'] ?? null, $filters['created_end_date'] ?? null);

        // 유효기간·발급기간 필터 — 두 기간은 입력(`fromSiteDateStartOfDay`·`fromSiteDateTime`)과
        // 표시(`formatDateStringForSite`) 모두 사이트 표준시라, 보는 사람 시간대와 무관하게
        // 사이트 하루로 잘라야 목록에 보이는 날짜와 맞는다.
        $siteTimezone = TimezoneHelper::getSiteTimezone();
        $this->applyDateRangeFilterInTimezone($query, 'valid_from', $filters['valid_start_date'] ?? null, null, $siteTimezone);
        $this->applyDateRangeFilterInTimezone($query, 'valid_to', null, $filters['valid_end_date'] ?? null, $siteTimezone);
        $this->applyDateRangeFilterInTimezone($query, 'issue_from', $filters['issue_start_date'] ?? null, null, $siteTimezone);
        $this->applyDateRangeFilterInTimezone($query, 'issue_to', null, $filters['issue_end_date'] ?? null, $siteTimezone);

        // 등록자 필터 (UUID → 정수 FK 변환)
        if (! empty($filters['created_by'])) {
            $query->whereHas('creator', fn ($q) => $q->where('uuid', $filters['created_by']));
        }
    }

    /**
     * ID 목록으로 조회하고 ID 키 맵으로 반환합니다 (bulk activity log lookup).
     *
     * @param  array<int, int>  $ids  ID 목록
     * @return Collection ID 를 키로 하는 쿠폰 컬렉션
     */
    public function findByIdsKeyed(array $ids): Collection
    {
        if (empty($ids)) {
            return new Collection;
        }

        return Coupon::whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * {@inheritDoc}
     */
    public function releaseIssuedCounts(array $countsByCouponId): void
    {
        foreach ($countsByCouponId as $couponId => $count) {
            $count = (int) $count;
            if ($count <= 0) {
                continue;
            }

            // 한 줄 UPDATE 라 동시 발급의 증가와 섞여도 값이 엇갈리지 않는다. 기존 수량이 더 작으면(이전 오차) 0 에서 멈춘다
            $this->model->newQuery()
                ->withTrashed()
                ->whereKey((int) $couponId)
                ->update([
                    'issued_count' => DB::raw('CASE WHEN issued_count > '.$count.' THEN issued_count - '.$count.' ELSE 0 END'),
                ]);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function detachProductFromAll(int $productId): int
    {
        // audit:allow repository-raw-hardcoded-table reason: 모델이 없는 피벗(쿠폰 적용·제외 상품) — Coupon::products() 관계 선언과 같은 이름
        return (int) DB::table('ecommerce_promotion_coupon_products')->where('product_id', $productId)->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function detachCategoryFromAll(int $categoryId): int
    {
        // audit:allow repository-raw-hardcoded-table reason: 모델이 없는 피벗(쿠폰 적용·제외 카테고리) — Coupon::categories() 관계 선언과 같은 이름
        return (int) DB::table('ecommerce_promotion_coupon_categories')->where('category_id', $categoryId)->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function getAutoIssueCouponIds(CouponIssueCondition $condition): array
    {
        $now = now();

        // audit:allow query-unbounded-get reason: 지금 발급 중인 한 조건의 자동발급 쿠폰 ID 만 고른다 — 운영자가 만든 쿠폰 정의 수에 묶여 회원·주문 증가에 비례하지 않는다
        return $this->model->newQuery()
            ->where('issue_method', CouponIssueMethod::AUTO)
            ->where('issue_condition', $condition)
            ->where('issue_status', CouponIssueStatus::ISSUING)
            ->where(fn ($q) => $q->whereNull('issue_from')->orWhere('issue_from', '<=', $now))
            ->where(fn ($q) => $q->whereNull('issue_to')->orWhere('issue_to', '>=', $now))
            ->where(fn ($q) => $q->whereNull('total_quantity')->orWhereColumn('issued_count', '<', 'total_quantity'))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $now))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * {@inheritDoc}
     */
    public function getNotificationCoupons(array $alsoIncludeIds = []): Collection
    {
        return $this->model->newQuery()
            ->where(function ($q) use ($alsoIncludeIds) {
                $q->where('notify_enabled', true);
                if ($alsoIncludeIds !== []) {
                    $q->orWhereIn('id', array_map('intval', $alsoIncludeIds));
                }
            })
            ->where('issue_method', '!=', CouponIssueMethod::DOWNLOAD)
            ->orderBy('id')
            ->get();
    }
}
