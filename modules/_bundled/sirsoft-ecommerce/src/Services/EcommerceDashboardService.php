<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Helpers\TimezoneHelper;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceStatRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductInquiryRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductReviewRepositoryInterface;

/**
 * 이커머스 대시보드 서비스
 *
 * 관리자 대시보드의 이커머스 영역을 위한 조회/집계 로직을 담당합니다.
 *
 * - 판매 추세(sales-graph)는 집계 테이블 ecommerce_stats 만 읽어 원본(주문/주문상품) 풀스캔을 피합니다.
 * - 「오늘」 상태 배지(overview)는 주문 수다 — 배지 링크가 여는 주문 목록과 같은 조건으로 주문 표에서 바로 센다
 *   (사이트 하루 범위 + ordered_at 인덱스라 풀스캔이 아니다). 7.1.0 결정(2026-10-08 4차) 전에는 집계 표의 옵션 수량을 읽어
 *   한 주문에 수량이 여럿이면 배지 「결제완료 2건」 → 목록 「총 1개」로 갈렸다.
 * - 집계(aggregateRecentDays)는 스케쥴러가 1시간마다 호출해 최근 N일치를 upsert 합니다.
 * - 판매 수량/순매출은 주문상품(order_options)의 매출 반영 상태(option_status) 옵션만 합산하며,
 *   날짜는 주문(orders.ordered_at) 기준으로 귀속합니다.
 * - 「하루」는 사이트 표준시의 하루다(저장 시각은 UTC 그대로, 질의할 때 사이트 하루를 UTC 범위로 바꾼다).
 */
class EcommerceDashboardService
{
    /**
     * 「오늘」 배지 키 → 그 배지 링크가 여는 주문 목록의 상태 조건 (`admin_dashboard_commerce.g7.html` 의 navigate 경로와 같다).
     *
     * 배지 숫자는 이 조건 + 사이트 오늘(`date_basis=site`, 주문일)로 연 목록의 총 건수다. 목록 필터를 그대로 쓰므로
     * 「준비중」은 목록 상태 필터처럼 상품준비중+배송준비중을 함께 세고(OrderStatusEnum::statisticsFilterGroups),
     * 「반품」은 반품 완료 신청서가 있는 주문을 센다. 링크를 바꾸면 이 표도 같이 바꾼다 — 두 곳이 같은지는
     * `DashboardBadgeOrderCountTest::server_badge_conditions_equal_the_layout_badge_links` 가 레이아웃을 읽어 잠근다.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const BADGE_LIST_FILTERS = [
        'pending_payment' => ['order_status' => [OrderStatusEnum::PENDING_PAYMENT->value]],
        'payment_complete' => ['order_status' => [OrderStatusEnum::PAYMENT_COMPLETE->value]],
        'preparing' => ['order_status' => [OrderStatusEnum::PREPARING->value]],
        'shipping_ready' => ['order_status' => [OrderStatusEnum::SHIPPING_READY->value]],
        'shipping' => ['order_status' => [OrderStatusEnum::SHIPPING->value]],
        'cancellations' => ['order_status' => [OrderStatusEnum::CANCELLED->value]],
        'returns' => ['claim_return_statuses' => [ReturnStatusEnum::COMPLETED->value]],
    ];

    /**
     * 일 집계 행(option_status_counts)에 남기는 옵션 상태 버킷 키 목록 (option_status 7버킷 — 상태별 수량 기록).
     *
     * @var array<int, string>
     */
    private const BADGE_STATUS_KEYS = [
        'pending_payment',
        'payment_complete',
        'preparing',
        'shipping_ready',
        'shipping',
        'cancellations',
        'returns',
    ];

    /**
     * @param  EcommerceStatRepositoryInterface  $statRepository  일별 집계 Repository
     * @param  OrderOptionRepositoryInterface  $orderOptionRepository  주문상품 Repository
     * @param  OrderRepositoryInterface  $orderRepository  주문 Repository (오늘 배지 주문 수)
     * @param  ProductReviewRepositoryInterface  $reviewRepository  리뷰 Repository
     * @param  ProductInquiryRepositoryInterface  $inquiryRepository  문의 Repository
     * @param  CurrencyConversionService  $currencyService  기본 통화 소수 자릿수 정규화용 통화 서비스
     */
    public function __construct(
        private readonly EcommerceStatRepositoryInterface $statRepository,
        private readonly OrderOptionRepositoryInterface $orderOptionRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ProductReviewRepositoryInterface $reviewRepository,
        private readonly ProductInquiryRepositoryInterface $inquiryRepository,
        private readonly CurrencyConversionService $currencyService,
    ) {}

    /**
     * 매출 금액을 기본 통화의 소수 자릿수로 정규화합니다.
     *
     * 집계 테이블은 통화 무관 decimal(15,2) 라 JPY/KRW(0자리) 통화에서도 소수가 붙는다.
     * 기본 통화 설정의 decimal_places 로 라운딩해 0자리 통화는 정수로 응답한다.
     *
     * @param  float|int|null  $amount  매출 금액
     * @return float|int 기본 통화 자릿수로 라운딩된 매출 금액
     */
    private function roundSalesAmount(float|int|null $amount): float|int
    {
        $decimalPlaces = $this->currencyService->getDecimalPlaces($this->currencyService->getDefaultCurrency());
        $rounded = round((float) ($amount ?? 0), $decimalPlaces);

        return $decimalPlaces === 0 ? (int) $rounded : $rounded;
    }

    /**
     * 「오늘」 배지 7개의 주문 수를 조회합니다.
     *
     * 각 배지 값 = 그 배지 링크가 여는 주문 목록(상태 조건 + 주문일 + 사이트 표준시의 오늘)의 총 건수. 목록 필터 단일 출처
     * (`OrderRepository::applyListFilters`)로 주문 표에서 바로 세므로 옵션 수량이 아니라 주문 수이고, 매시 집계를
     * 기다리지 않는다. 목록과 같은 주문 조회 권한 범위(현재 관리자의 `orders.read` 본인·역할 범위)도 건다 — 범위가 좁혀진
     * 관리자에게도 배지 = 그 관리자가 연 목록 건수다. 범위가 사이트 하루라 ordered_at 인덱스 범위 조회다.
     *
     * @return array<string, int> 배지 키 => 주문 수
     */
    public function getOverview(): array
    {
        $today = $this->siteToday()->toDateString();
        $dayFilters = [
            'date_type' => 'ordered_at',
            'date_basis' => 'site',
            'start_date' => $today,
            'end_date' => $today,
        ];

        $overview = [];
        foreach (self::BADGE_LIST_FILTERS as $key => $statusFilters) {
            $overview[$key] = $this->orderRepository->countWithListFilters(array_merge($dayFilters, $statusFilters));
        }

        return $overview;
    }

    /**
     * 7일 막대그래프 + 7일 합계 + 직전 7일 대비 변화율을 조회합니다.
     *
     * ecommerce_stats 최근 (graphDays * 2) 행을 읽어 이번 기간(차트/합계)과
     * 직전 동일 기간(변화율 비교)을 한 번에 계산합니다. 직전 기간 합이 0 이면
     * 변화율을 null 로 반환합니다 (화면에서 '—' 폴백).
     *
     * @param  int  $graphDays  차트 표시 일수 (기본 7)
     * @return array{
     *     days: array<int, array{date: string, sales_quantity: int, sales_amount: float}>,
     *     total_quantity: int,
     *     total_sales: float,
     *     quantity_change: float|null,
     *     sales_change: float|null,
     *     updated_at: string|null,
     *     updated_at_display: string
     * } 그래프 데이터
     */
    public function getSalesGraph(int $graphDays = 7): array
    {
        $today = $this->siteToday();
        $currentStart = $today->subDays($graphDays - 1);
        $previousStart = $today->subDays($graphDays * 2 - 1);
        $previousEnd = $currentStart->subDay();

        $currentRows = $this->statRepository->getByDateRange(
            $currentStart->toDateString(),
            $today->toDateString(),
        );
        $previousRows = $this->statRepository->getByDateRange(
            $previousStart->toDateString(),
            $previousEnd->toDateString(),
        );

        $indexed = $currentRows->keyBy(fn ($row) => $row->date->toDateString());

        $days = [];
        for ($i = 0; $i < $graphDays; $i++) {
            $date = $currentStart->addDays($i)->toDateString();
            $row = $indexed->get($date);
            $days[] = [
                'date' => $date,
                'sales_quantity' => (int) ($row?->sales_quantity ?? 0),
                'sales_amount' => $this->roundSalesAmount($row?->sales_amount ?? 0),
            ];
        }

        $totalQuantity = (int) $currentRows->sum('sales_quantity');
        $totalSales = $this->roundSalesAmount($currentRows->sum('sales_amount'));
        $prevQuantity = (int) $previousRows->sum('sales_quantity');
        $prevSales = (float) $previousRows->sum('sales_amount');

        $updatedAt = $this->resolveUpdatedAt($currentRows);

        return [
            'days' => $days,
            'total_quantity' => $totalQuantity,
            'total_sales' => $totalSales,
            'quantity_change' => $this->calculateChangeRate($totalQuantity, $prevQuantity),
            'sales_change' => $this->calculateChangeRate($totalSales, $prevSales),
            'updated_at' => $updatedAt,
            'updated_at_display' => $this->formatUpdatedAt($updatedAt),
        ];
    }

    /**
     * 전체 상품의 최신 노출 리뷰를 조회합니다.
     *
     * @param  int  $limit  조회 건수
     * @return Collection 최신 리뷰 컬렉션
     */
    public function getRecentReviews(int $limit): Collection
    {
        return $this->reviewRepository->getRecentAcrossProducts($limit);
    }

    /**
     * 전체 상품의 미답변 문의 목록과 총 건수를 조회합니다.
     *
     * @param  int  $limit  조회 건수
     * @return array{items: Collection, total: int, board_slug: string|null} 미답변 문의
     */
    public function getPendingInquiries(int $limit): array
    {
        return [
            'items' => $this->inquiryRepository->getPendingRecent($limit),
            'total' => $this->inquiryRepository->countPending(),
            // 상품 문의는 게시판 모듈 Post 로 저장된다. 관리자 상세는 지정된 문의 게시판의
            // 글 상세(/admin/board/{slug}/post/{inquirable_id})에서 본다 (미지정 시 null).
            'board_slug' => g7_module_settings('sirsoft-ecommerce', 'inquiry.board_slug'),
        ];
    }

    /**
     * 최근 N일치(오늘 포함) 판매 집계 행을 ecommerce_stats 에 upsert 합니다.
     *
     * 그 이전 날짜 행은 건드리지 않아 과거 추세가 보존됩니다. date unique 로 멱등합니다.
     * 판매 수량/순매출은 매출 반영 상태(option_status) 옵션만 합산하고, 상태별 수량 기록(option_status_counts)은
     * 당일 전체 옵션 수량을 7버킷으로 매핑합니다 — 「오늘」 배지는 이 기록이 아니라 주문 수를 바로 센다(getOverview).
     * 날짜는 주문(orders.ordered_at) 기준입니다.
     *
     * @param  int  $days  집계 대상 일수 (오늘 포함, 기본 7)
     * @return array<int, array{date: string, sales_quantity: int, sales_amount: float}> 갱신된 집계 목록
     */
    public function aggregateRecentDays(int $days = 7): array
    {
        $today = $this->siteToday();
        $result = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $today->subDays($i)->toDateString();

            $salesQuantity = $this->orderOptionRepository->sumNetQuantityOnDate($date);
            $salesAmount = $this->orderOptionRepository->sumNetSalesOnDate($date);
            $statusCounts = $this->buildBadgeBuckets(
                $this->orderOptionRepository->countByOptionStatusOnDate($date),
            );

            $this->statRepository->upsertForDate($date, $salesQuantity, $salesAmount, $statusCounts);

            $result[] = [
                'date' => $date,
                'sales_quantity' => $salesQuantity,
                'sales_amount' => $salesAmount,
            ];
        }

        return $result;
    }

    /**
     * 사이트 표준시의 오늘(0시)을 돌려줍니다.
     *
     * 집계 표(ecommerce_stats)는 모든 운영자가 함께 보는 하루 1행이라 보는 사람마다 하루 경계를 달리할 수 없다.
     * 방문자 통계·게시판 대시보드처럼 사이트 표준시의 하루로 행을 만들고 읽는다 — UTC 자정으로 자르면 한국 시간
     * 새벽 0~9시 주문이 전날 행에 들어가고 「오늘」 배지가 아침 9시까지 어제 행을 읽는다(7.1.0 QE-O2).
     *
     * @return CarbonImmutable 사이트 표준시 오늘 0시
     */
    public function siteToday(): CarbonImmutable
    {
        return CarbonImmutable::now(TimezoneHelper::getSiteTimezone() ?: 'UTC')->startOfDay();
    }

    /**
     * 원시 option_status 수량 맵을 상태별 수량 기록 7버킷(일 집계 option_status_counts)으로 매핑합니다.
     *
     * 이 기록은 옵션 수량이다 — 「오늘」 배지는 이 기록을 읽지 않고 주문 수를 바로 센다(getOverview, 7.1.0 결정).
     * 취소(cancelled)는 cancellations 버킷으로, 그 외 상태는 동일 키로 매핑합니다.
     * 부분취소는 별도 상태가 아니라 취소된 옵션(option_status=cancelled)으로 집계되므로 cancellations 에 포함됩니다.
     * returns(반품) 버킷은 취소 칸과 같은 규칙으로 반품완료 옵션(option_status=returned)을 셉니다 —
     * 반품 진행 중(return_in_progress)은 아직 돌려받지 않은 상품이라 넣지 않는다(취소 칸도 환불신청 중은 세지 않는다).
     * #599 통합 실측 P15 ① · S0-D1: 클레임 도메인이 생긴 뒤에도 0 으로 고정돼 있어 반품 완료 38건 위에서
     * 배지가 계속 0 이었다.
     *
     * @param  array<string, int>  $rawCounts  option_status 값 => 수량
     * @return array<string, int> 상태별 수량 7버킷 (상태 키 => 수량)
     */
    private function buildBadgeBuckets(array $rawCounts): array
    {
        $buckets = array_fill_keys(self::BADGE_STATUS_KEYS, 0);

        $buckets['pending_payment'] = (int) ($rawCounts[OrderStatusEnum::PENDING_PAYMENT->value] ?? 0);
        $buckets['payment_complete'] = (int) ($rawCounts[OrderStatusEnum::PAYMENT_COMPLETE->value] ?? 0);
        $buckets['preparing'] = (int) ($rawCounts[OrderStatusEnum::PREPARING->value] ?? 0);
        $buckets['shipping_ready'] = (int) ($rawCounts[OrderStatusEnum::SHIPPING_READY->value] ?? 0);
        $buckets['shipping'] = (int) ($rawCounts[OrderStatusEnum::SHIPPING->value] ?? 0);
        // 부분취소는 별도 주문 상태가 아니라 잔여 옵션 기준 진행 상태로 집계되므로(partial_cancelled 제거),
        // 취소 칸은 전체취소(CANCELLED)만 합산한다.
        $buckets['cancellations'] = (int) ($rawCounts[OrderStatusEnum::CANCELLED->value] ?? 0);
        $buckets['returns'] = (int) ($rawCounts[OrderStatusEnum::RETURNED->value] ?? 0);

        return $buckets;
    }

    /**
     * 직전 기간 대비 증감율(%)을 계산합니다.
     *
     * 직전 기간 합이 0 이면 비교 기준이 없으므로 null 을 반환합니다.
     *
     * @param  float  $current  이번 기간 합계
     * @param  float  $previous  직전 기간 합계
     * @return float|null 소수점 첫째 자리 증감율 또는 null
     */
    private function calculateChangeRate(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * 집계 행들의 마지막 갱신 시각(MAX updated_at)을 ISO 문자열로 반환합니다.
     *
     * @param  Collection  $rows  집계 행 컬렉션
     * @return string|null 마지막 갱신 시각 또는 null
     */
    private function resolveUpdatedAt(Collection $rows): ?string
    {
        $max = $rows->max('updated_at');

        return $max?->toIso8601String();
    }

    /**
     * 갱신 시각을 사용자 타임존의 HH:mm 캡션으로 포맷합니다.
     *
     * @param  string|null  $updatedAt  ISO 8601 갱신 시각
     * @return string HH:mm 문자열 또는 빈 문자열
     */
    private function formatUpdatedAt(?string $updatedAt): string
    {
        if (! $updatedAt) {
            return '';
        }

        return TimezoneHelper::toUserCarbon(Carbon::parse($updatedAt))?->format('H:i') ?? '';
    }
}
