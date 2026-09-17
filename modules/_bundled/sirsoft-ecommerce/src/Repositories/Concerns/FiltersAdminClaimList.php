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
     * 금액 축은 컬럼 하나로 표현되지 않는다 — 유형마다 무엇이 "그 행의 금액" 인지가 다르고
     * (교환·반품 = 청구액 / 환불·취소 = 환불액 + 환불 마일리지), 취소는 자기 테이블에 금액
     * 컬럼이 아예 없어 환불 관계를 거친다. 그래서 합산 대상 컬럼 목록(`amount_columns`)과
     * 경유 관계(`amount_relation`)를 따로 선언한다. 목록 화면의 '금액' 컬럼이 그리는 값과
     * 같은 것을 걸러야 운영자가 "보이는 금액으로 거른다" 고 믿을 수 있다.
     *
     * 통화도 마찬가지다 — 교환·반품은 자기 `currency_code` 를, 환불·취소는 주문의 `currency`
     * 를 본다(`currency` 가 null 이면 주문 조인).
     *
     * @return array{status: string, number: string, requester: string, reason: string, fault: ?string, charge: ?string, items_relation: string, amount_columns: array<int, string>, amount_relation: ?string, currency: ?string, charge_payment_relation: ?string} 컬럼 대응표
     */
    abstract protected function adminClaimColumns(): array;

    /**
     * 관리자 목록 필터를 쿼리에 적용합니다.
     *
     * @param  Builder  $query  대상 쿼리
     * @param  array  $filters  필터 (statuses / from / to / fault_types / charge_methods / reason_code / requester_type / search_field / search_keyword / min_amount / max_amount / currency_code / charge_payment_methods)
     * @return void 쿼리를 제자리에서 수정한다
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

        $this->applyAdminClaimAmountRange($query, $filters, $columns);
        $this->applyAdminClaimCurrency($query, $filters, $columns);

        if (! empty($filters['charge_payment_methods']) && $columns['charge_payment_relation'] !== null) {
            $query->whereHas(
                $columns['charge_payment_relation'],
                fn ($q) => $q->whereIn('payment_method', (array) $filters['charge_payment_methods'])
            );
        }

        $this->applyAdminClaimSearch($query, $filters, $columns);
    }

    /**
     * 금액 범위 필터를 쿼리에 적용합니다.
     *
     * 합산 컬럼이 둘 이상이면 raw 비교가 불가피하다. 컬럼명은 대응표(코드 상수)에서만 오고
     * 값은 바인딩으로 넘기며, 식별자는 그래머의 `wrap()` 이 감싼다 — 테이블명·별칭을 문자열로
     * 조립하지 않는다.
     *
     * @param  Builder  $query  대상 쿼리
     * @param  array  $filters  필터
     * @param  array  $columns  컬럼 대응표
     * @return void 쿼리를 제자리에서 수정한다
     */
    protected function applyAdminClaimAmountRange(Builder $query, array $filters, array $columns): void
    {
        $min = $filters['min_amount'] ?? null;
        $max = $filters['max_amount'] ?? null;

        if ($min === null && $max === null) {
            return;
        }

        $amountColumns = $columns['amount_columns'] ?? [];

        if ($amountColumns === []) {
            return;
        }

        $apply = function (Builder $scoped) use ($amountColumns, $min, $max): void {
            $grammar = $scoped->getQuery()->getGrammar();
            $expression = count($amountColumns) === 1
                ? $grammar->wrap($amountColumns[0])
                : '('.implode(' + ', array_map(fn ($c) => $grammar->wrap($c), $amountColumns)).')';

            if ($min !== null) {
                $scoped->whereRaw("{$expression} >= ?", [$min]);
            }

            if ($max !== null) {
                $scoped->whereRaw("{$expression} <= ?", [$max]);
            }
        };

        // 취소는 자기 테이블에 금액이 없어 환불 관계를 거친다 — 환불이 아직 없는 취소 건은
        // 금액으로 거를 대상이 아니므로 whereHas 가 자연히 제외한다.
        if (($columns['amount_relation'] ?? null) !== null) {
            $query->whereHas($columns['amount_relation'], $apply);

            return;
        }

        $apply($query);
    }

    /**
     * 통화 필터를 쿼리에 적용합니다.
     *
     * @param  Builder  $query  대상 쿼리
     * @param  array  $filters  필터
     * @param  array  $columns  컬럼 대응표
     * @return void 쿼리를 제자리에서 수정한다
     */
    protected function applyAdminClaimCurrency(Builder $query, array $filters, array $columns): void
    {
        $code = $filters['currency_code'] ?? null;

        if ($code === null || $code === '') {
            return;
        }

        if (($columns['currency'] ?? null) !== null) {
            $query->where($columns['currency'], $code);

            return;
        }

        // 자기 통화 컬럼이 없는 유형(환불·취소)은 주문의 결제 통화가 근거다.
        $query->whereHas('order', fn ($q) => $q->where('currency', $code));
    }

    /**
     * 검색 드롭다운 4종(클레임번호/주문번호/신청자/상품명 — D27)을 쿼리에 적용합니다.
     *
     * 신청자 검색은 주문자명을 본다 — 클레임 테이블의 신청자 축은 회원 ID 뿐이라 비회원
     * 신청 건은 이름 검색 대상이 없어지기 때문이다. 그 이름은 **주문 헤더가 아니라 배송지
     * 행**(`OrderAddress.orderer_name`)에 있다(`Order::getOrdererName()` 과 같은 출처) —
     * `orders` 에 직접 걸면 없는 컬럼을 찾다가 목록 전체가 500 이 된다(S3-D19). 상품명은
     * 신청 상품 행 → 주문옵션 스냅샷(`product_name`)을 whereExists 로 본다(조인 부풀림 없음).
     *
     * @param  Builder  $query  대상 쿼리
     * @param  array  $filters  필터
     * @param  array  $columns  컬럼 대응표
     * @return void 쿼리를 제자리에서 수정한다
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
            // 주문자 이름은 주문 헤더가 아니라 **배송지 행**에 있다(`OrderAddress.orderer_name`).
            // `order` 관계에 직접 걸면 `ecommerce_orders.orderer_name` 을 찾다가 SQL 이 죽어
            // 목록 전체가 500 이 된다 — 빈 결과가 아니라 화면이 멈춘다(S3-D19).
            'requester' => $query->whereHas('order.shippingAddress', fn ($q) => $q->where('orderer_name', 'like', $like)),
            // 상품명은 **다국어 JSON 컬럼**이다. 평문 `like` 로 훑으면 한글이 영원히 안 걸린다 —
            // MySQL 이 비ASCII 를 유니코드 이스케이프로 저장하므로 원문 바이트와 만나지 않는다
            // (실측: `텀블러` 0건 · `Tumbler` 44건). 로케일별 `->{locale}` 로 꺼내 비교한다 —
            // Eloquent 가 `JSON_UNQUOTE(JSON_EXTRACT(...))` 로 컴파일해 이스케이프를 푼다(S3-D20).
            // 선례: `ProductInquiryRepository::getListWithFilters()` 의 상품명 검색과 같은 형태.
            'product_name' => $query->whereHas(
                $columns['items_relation'].'.orderOption',
                fn ($q) => $q->where(function ($inner) use ($like) {
                    foreach (config('app.translatable_locales', ['ko', 'en']) as $locale) {
                        $inner->orWhere("product_name->{$locale}", 'like', $like);
                    }
                })
            ),
            default => $query->where($columns['number'], 'like', $like),
        };
    }
}
