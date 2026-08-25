<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Concerns;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * 관리자 클레임 목록 필터 공용 트레이트
 *
 * 교환·반품·환불·취소 네 저장소가 같은 필터 계약(`Admin\ClaimListRequest::filters()`)을
 * 각자의 컬럼명으로 해석한다 — 유형마다 상태·번호·신청자 컬럼명이 다르고(취소는
 * `cancelled_by`, 사유는 `cancel_reason_type`) 귀책·청구 축이 아예 없는 유형(취소)도
 * 있어, 컬럼 대응표(`adminClaimColumns()`)만 저장소가 선언하고 적용 로직은 한 벌로 둔다.
 * 네 벌을 각각 쓰면 필터 하나를 고칠 때 세 벌이 조용히 낡는다.
 *
 * 기간 필터가 `whereDate` 가 아니라 범위 조건인 이유는 `paginateForUser()` 와 같다 —
 * 컬럼에 DATE() 를 씌우면 `created_at` 인덱스를 못 쓴다.
 */
trait FiltersAdminClaimList
{
    /**
     * 유형별 컬럼 대응표를 반환합니다.
     *
     * 지원하지 않는 축은 키를 null 로 둔다 — 그 축의 필터 입력은 FormRequest 가
     * `prohibited` 로 이미 거절하므로 여기서는 적용을 건너뛰기만 하면 된다.
     *
     * @return array{status: string, number: string, requester: string, reason: string, fault: ?string, charge: ?string, items_relation: string} 컬럼 대응표
     */
    abstract protected function adminClaimColumns(): array;

    /**
     * 관리자 목록 필터를 쿼리에 적용합니다.
     *
     * @param  Builder  $query  대상 쿼리
     * @param  array  $filters  필터 (statuses / from / to / fault_types / charge_methods / reason_code / requester_type / search_field / search_keyword)
     */
    protected function applyAdminClaimFilters(Builder $query, array $filters): void
    {
        $columns = $this->adminClaimColumns();

        if (! empty($filters['statuses'])) {
            $query->whereIn($columns['status'], (array) $filters['statuses']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        if (! empty($filters['fault_types']) && $columns['fault'] !== null) {
            $query->whereIn($columns['fault'], (array) $filters['fault_types']);
        }

        if (! empty($filters['charge_methods']) && $columns['charge'] !== null) {
            $query->whereIn($columns['charge'], (array) $filters['charge_methods']);
        }

        if (! empty($filters['reason_code'])) {
            $query->where($columns['reason'], $filters['reason_code']);
        }

        // 회원구분 — 클레임 테이블에는 user_id 가 없고 신청자 컬럼(비회원 NULL)이 회원 여부의
        // 단일 근거다 (`isGuestRequest()` 와 같은 판정).
        if (($filters['requester_type'] ?? null) === 'member') {
            $query->whereNotNull($columns['requester']);
        } elseif (($filters['requester_type'] ?? null) === 'guest') {
            $query->whereNull($columns['requester']);
        }

        $this->applyAdminClaimSearch($query, $filters, $columns);
    }

    /**
     * 검색 드롭다운 4종(클레임번호/주문번호/신청자/상품명 — D27)을 쿼리에 적용합니다.
     *
     * 신청자 검색은 주문자명(`orders.orderer_name`)을 본다 — 클레임 테이블의 신청자 축은
     * 회원 ID 뿐이라 비회원 신청 건은 이름 검색 대상이 없어지기 때문이다. 상품명은
     * 신청 상품 행 → 주문옵션 스냅샷(`product_name`)을 whereExists 로 본다(조인 부풀림 없음).
     *
     * @param  Builder  $query  대상 쿼리
     * @param  array  $filters  필터
     * @param  array  $columns  컬럼 대응표
     */
    protected function applyAdminClaimSearch(Builder $query, array $filters, array $columns): void
    {
        $keyword = trim((string) ($filters['search_keyword'] ?? ''));

        if ($keyword === '') {
            return;
        }

        $like = "%{$keyword}%";

        match ($filters['search_field'] ?? 'claim_number') {
            'order_number' => $query->whereHas('order', fn ($q) => $q->where('order_number', 'like', $like)),
            'requester' => $query->whereHas('order', fn ($q) => $q->where('orderer_name', 'like', $like)),
            'product_name' => $query->whereHas($columns['items_relation'].'.orderOption', fn ($q) => $q->where('product_name', 'like', $like)),
            default => $query->where($columns['number'], 'like', $like),
        };
    }
}
