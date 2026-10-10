<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Concerns;

use App\Helpers\PermissionHelper;
use App\Repositories\Concerns\FiltersByDateRange;
use Illuminate\Database\Eloquent\Builder;
use Modules\Sirsoft\Ecommerce\Support\ClaimScopePermissions;

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
 * 컬럼에 DATE() 를 씌우면 `created_at` 인덱스를 못 쓴다. 하루는 보는 사람의 시간대 하루다 —
 * 목록이 신청일을 그 시간대로 그린다.
 */
trait FiltersAdminClaimList
{
    use FiltersByDateRange;

    /**
     * 관리자 목록·건수에 적용하는 스코프 권한 식별자
     */
    public const ADMIN_CLAIM_SCOPE_PERMISSION = 'sirsoft-ecommerce.claims.read';

    /**
     * 신청서를 열람자의 권한 스코프 안으로 좁힙니다. (주문 스코프 상속)
     *
     * 신청서의 스코프 축은 **그 주문의 것**이다(`module.php` 의 `claims` 권한이 `orders` 와 같은
     * `owner_key = user_id` 를 선언한다). 그래서 신청서 자신이 아니라 `order` 관계에 스코프 조건을
     * 건다 — 판정식은 코어 단일 출처(`PermissionHelper::applyPermissionScope()`)이고, 주문 목록
     * (`OrderRepository`)과 같은 식이다.
     *
     * 클레임 권한 범위만 걸지 않고 **주문 조회 범위를 함께 건다**(두 범위의 교집합 — EC-667). 주문 조회를
     * 「본인만」 으로 묶은 운영자가 클레임 기본값 「전체」 로 남의 주문 신청서를 보면, "주문 목록에는
     * 없는데 클레임 목록에는 있는" 주문이 생긴다. 판정 대상 목록은 `ClaimScopePermissions` 단일 출처다.
     *
     * 범위가 걸린 권한이 하나도 없으면(전체·미보유·비인증 호출) 아무 조건도 붙지 않는다.
     *
     * @param  Builder  $query  대상 쿼리
     * @param  string  $permission  스코프를 판정할 클레임 권한 식별자
     * @return void 쿼리를 제자리에서 수정한다
     */
    protected function applyAdminClaimScope(Builder $query, string $permission): void
    {
        $scoped = ClaimScopePermissions::scoped($permission);

        if ($scoped === []) {
            return;
        }

        $query->whereHas('order', function (Builder $order) use ($scoped): void {
            foreach ($scoped as $identifier) {
                PermissionHelper::applyPermissionScope($order, $identifier);
            }
        });
    }

    /**
     * 지정한 신청서 중 열람자의 스코프 안에 있는 건수를 셉니다. (일괄 액션 가드)
     *
     * 목록과 **같은 스코프 조건**(`applyAdminClaimScope()`)으로 센다 — 일괄 경로가 목록과 다른
     * 판정을 쓰면 목록에 보이지 않는 신청서를 일괄 전이로 건드릴 수 있다.
     *
     * @param  array<int, int>  $ids  신청서 ID 목록
     * @param  string|null  $permission  스코프를 판정할 권한 식별자 (null 이면 스코프 없이 존재 건수만)
     * @return int 스코프 안의 건수 (존재하지 않는 ID 는 세지 않는다)
     */
    public function countWithinAdminScope(array $ids, ?string $permission): int
    {
        $query = $this->model->newQuery()->whereIn($this->model->getKeyName(), array_values(array_unique(array_map('intval', $ids))));

        if ($permission !== null) {
            $this->applyAdminClaimScope($query, $permission);
        }

        return $query->count();
    }

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
     * @param  array  $filters  필터 (statuses / from / to / fault_types / charge_methods / reason_code / requester_type / search_field / search_keyword / min_amount / max_amount / currency_code / charge_payment_methods / request_source / collection_overdue / pending_refund)
     * @return void 쿼리를 제자리에서 수정한다
     */
    protected function applyAdminClaimFilters(Builder $query, array $filters): void
    {
        $columns = $this->adminClaimColumns();

        // 목록·건수는 열람자의 `claims.read` 범위와 주문 조회 범위가 겹치는 신청서만 센다 — 주문 스코프 상속.
        $this->applyAdminClaimScope($query, self::ADMIN_CLAIM_SCOPE_PERMISSION);

        if (! empty($filters['statuses'])) {
            $query->whereIn($columns['status'], (array) $filters['statuses']);
        }

        // 신청일은 보는 사람의 시간대로 그리므로 그 하루로 자른다.
        $this->applyViewerDateRangeFilter($query, 'created_at', $filters['from'] ?? null, $filters['to'] ?? null);

        if (! empty($filters['fault_types']) && $columns['fault'] !== null) {
            $query->whereIn($columns['fault'], (array) $filters['fault_types']);
        }

        if (! empty($filters['charge_methods']) && $columns['charge'] !== null) {
            $query->whereIn($columns['charge'], (array) $filters['charge_methods']);
        }

        if (! empty($filters['reason_code'])) {
            $query->where($columns['reason'], $filters['reason_code']);
        }

        // 회원구분 — 판정은 **주문의 주인**이 한다 (`isGuestRequest()` 와 같은 기준).
        //
        // 신청자 컬럼(`requested_by`·`cancelled_by`)으로 가르면 안 된다: 비회원 신청과
        // **운영자 대리 접수**가 똑같이 null 이라, 회원 주문의 운영자 접수 건이 「비회원」
        // 목록에 선다 — 운영자는 연락 수단을 잘못 고른다(#599 세션A 실측 2026-09-21:
        // 회원 주문 20260921-1316400577 의 RF20260921-1412250846·RF20260921-1416278729).
        if (($filters['requester_type'] ?? null) === 'member') {
            $query->whereHas('order', fn ($q) => $q->whereNotNull('user_id'));
        } elseif (($filters['requester_type'] ?? null) === 'guest') {
            $query->whereHas('order', fn ($q) => $q->whereNull('user_id'));
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
        $this->applyAdminClaimTypeFilters($query, $filters);
    }

    /**
     * 유형 고유 필터를 적용합니다. (접수 출처 · 회수 지연 · 환불 미접수 — 기본은 없음)
     *
     * 유형마다 근거 컬럼이 달라(교환·반품 = `requested_by_admin`, 환불 = `refund_source_type`) 대응표로
     * 담기지 않는다. 그 축이 없는 유형은 이 메서드를 두지 않아 필터가 조용히 무시된다 — 다른 탭 건수가
     * 0 이 되지 않는다.
     *
     * @param  Builder  $query  대상 쿼리
     * @param  array  $filters  필터 (request_source / collection_overdue / pending_refund)
     * @return void 쿼리를 제자리에서 수정한다
     */
    protected function applyAdminClaimTypeFilters(Builder $query, array $filters): void {}

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
