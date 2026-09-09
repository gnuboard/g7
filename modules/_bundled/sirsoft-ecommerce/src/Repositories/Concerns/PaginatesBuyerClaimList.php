<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Concerns;

use Carbon\Carbon;
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
 * 포함해야 `whereDate` 와 같은 경계를 갖는다.
 */
trait PaginatesBuyerClaimList
{
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
     * 기준 쿼리(`buyerClaimListQuery()`)를 그대로 세므로 유형 고유 스코프(환불의 취소 발 제외)가
     * 목록과 같은 근거로 걸린다 — 따로 세면 뱃지와 목록이 갈린다. 상태·기간 필터는 받지 않는다:
     * 뱃지는 "이 유형에 접수된 건이 있는가" 를 말하는 자리다.
     *
     * @param  int  $orderId  주문 ID
     * @return int 건수
     */
    public function countForOrder(int $orderId): int
    {
        return $this->buyerClaimListQuery()->where('order_id', $orderId)->count();
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
        if (! empty($filters['status'])) {
            $query->where($this->buyerClaimStatusColumn(), $filters['status']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return $query->orderByDesc('id')->paginate($perPage, $this->buyerClaimListColumns());
    }
}
