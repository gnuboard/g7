<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Concerns;

use App\Repositories\Concerns\FiltersByDateRange;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * 구매자 클레임 목록 페이지네이션 공용 트레이트 (회원 신청내역 · 비회원 주문상세 섹션)
 *
 * 같은 카드를 두 화면이 그린다 — 회원은 주문을 가로지르는 신청내역(`paginateForUser`),
 * 비회원은 토큰이 묶인 그 주문의 신청서(`paginateForOrder`)다. 관계·기간 필터·정렬·컬럼이
 * 두 벌이면 한쪽만 고쳤을 때 같은 카드가 회원/비회원에서 다른 필드를 그리고, 빠진 필드는
 * 오류 없이 전 행 null 로 나온다. 그래서 열람자 조건(소유 회원 / 주문)만 각 메서드가 정하고
 * 나머지는 여기 한 벌이다.
 *
 * 기간 필터가 `whereDate` 가 아니라 범위 조건인 이유는 형제 트레이트와 같다 — 컬럼에
 * DATE() 를 씌우면 `created_at` 인덱스를 못 쓴다. 종료일은 그날 23:59:59.999999 까지
 * 포함해야 `whereDate` 와 같은 경계를 갖는다. 하루는 보는 사람의 시간대 하루다 — 목록이
 * 신청일을 그 시간대로 그린다.
 */
trait PaginatesBuyerClaimList
{
    use FiltersByDateRange;

    /**
     * 구매자 목록의 기준 쿼리를 반환합니다. (관계 · 유형 고유 스코프)
     *
     * 목록이 직렬화하는 것만 싣는다(목록 응답의 하위 컬렉션 규율) — 첨부는 상세의 사유
     * 카드에서만 보이고, 주문 배송지는 이 목록의 어느 칸도 읽지 않는다.
     *
     * @return Builder 기준 쿼리
     */
    abstract protected function buyerClaimListQuery(): Builder;

    /**
     * 구매자 목록의 상태 컬럼명을 반환합니다.
     *
     * @return string 상태 컬럼명
     */
    abstract protected function buyerClaimStatusColumn(): string;

    /**
     * 구매자 목록이 실제로 읽는 컬럼 목록을 반환합니다.
     *
     * @return array<int, string> SELECT 컬럼
     */
    abstract protected function buyerClaimListColumns(): array;

    /**
     * 이 주문에서 접수된 이 유형의 건수를 셉니다. (주문상세 섹션의 서브탭 뱃지·기본 유형 판정)
     *
     * 기준 쿼리(`buyerClaimListQuery()`)를 그대로 세므로 유형 고유 스코프가 목록과 같은
     * 근거로 걸린다 — 따로 세면 뱃지와 목록이 갈린다.
     *
     * **상태·기간 필터도 목록과 같이 적용한다**(F15). 예전에는 받지 않아, 같은 화면의
     * 뱃지와 목록이 서로 다른 수를 말했다 — 구매자가 기간을 좁히면 목록은 줄고 뱃지는
     * 그대로여서, 어느 쪽이 맞는지 알 수 없었다. 결함은 "필터가 없다" 가 아니라 **두 쿼리가
     * 다르다** 였으므로, 고치는 방향도 두 쿼리를 맞추는 것이다.
     *
     * @param  int  $orderId  주문 ID
     * @param  array<string, mixed>  $filters  필터 (status / from / to) — 목록과 같은 축
     * @return int 건수
     */
    public function countForOrder(int $orderId, array $filters = []): int
    {
        $query = $this->buyerClaimListQuery()->where('order_id', $orderId);

        $this->applyBuyerClaimFilters($query, $filters);

        return $query->count();
    }

    /**
     * 이 회원이 접수한 이 유형의 건수를 셉니다. (신청내역 서브탭 뱃지 — F12)
     *
     * 열람자 조건은 목록(`paginateForUser`)과 같은 관계 조건이고, 필터도 같은 적용기를 쓴다 —
     * 두 쿼리가 갈리면 뱃지와 목록이 서로 다른 수를 말한다(F15 와 같은 규율).
     *
     * @param  int  $userId  회원 ID
     * @param  array<string, mixed>  $filters  필터 (status / from / to) — 목록과 같은 축
     * @return int 건수
     */
    public function countForUser(int $userId, array $filters = []): int
    {
        $query = $this->buyerClaimListQuery()
            ->whereHas('order', fn ($q) => $q->where('user_id', $userId));

        $this->applyBuyerClaimFilters($query, $filters);

        return $query->count();
    }

    /**
     * 기준 쿼리에 공통 필터를 적용하고 페이지네이션합니다.
     *
     * @param  Builder  $query  열람자 조건이 이미 걸린 쿼리
     * @param  array  $filters  필터 (status / from / to)
     * @param  int  $perPage  페이지당 건수
     * @return LengthAwarePaginator 페이지네이션 결과
     */
    protected function paginateBuyerClaimList(Builder $query, array $filters, int $perPage): LengthAwarePaginator
    {
        $this->applyBuyerClaimFilters($query, $filters);

        return $query->orderByDesc('id')->paginate($perPage, $this->buyerClaimListColumns());
    }

    /**
     * 구매자 목록·건수 공용 필터를 적용합니다. (두 쿼리의 단일 출처 — F15)
     *
     * @param  Builder  $query  대상 쿼리
     * @param  array<string, mixed>  $filters  필터 (status / from / to)
     * @return void
     */
    protected function applyBuyerClaimFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['status'])) {
            $query->where($this->buyerClaimStatusColumn(), $filters['status']);
        }

        // 신청일은 보는 사람의 시간대로 그리므로 그 하루로 자른다.
        $this->applyViewerDateRangeFilter($query, 'created_at', $filters['from'] ?? null, $filters['to'] ?? null);
    }
}
