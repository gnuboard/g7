<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnOptionRepositoryInterface;

/**
 * 클레임 신청 상품 행 수량 분할기 (교환·반품·환불 공용)
 *
 * 운영자가 신청 상품 3개 중 2개만 승인하면 그 행은 **두 줄로 갈라져야 한다** — 승인분과
 * 남은 신청분이 서로 다른 상태를 갖기 때문이다. 갈랐을 때 지켜야 할 것은 하나, **금액 합
 * 보존**이다. 조각마다 따로 반올림하면 합이 원본과 몇 원씩 어긋난 채 남고, 그 어긋남은
 * 오류도 로그도 없이 환불액·차액으로만 드러난다.
 *
 * ## 왜 전용 협력자인가
 *
 * 세 유형의 서비스에 같은 안분을 복제하면 비율 계산이 세 벌이 되고, 한쪽만 고쳐졌을 때
 * 그 유형의 금액만 조용히 어긋난다. `ClaimQuantityGuard` 가 "세 유형이 공유하는 판정을
 * 유형별 서비스에 복제하지 않는다" 는 같은 선례다.
 *
 * ## 자체 트랜잭션을 열지 않는다
 *
 * 호출 위치는 **상품별 savepoint 의 맨 앞**(`transitionItem()` 안)이다. 분할이 savepoint
 * 밖에서 일어나면 "분할은 됐는데 전이는 실패한" 행이 남아, 운영자가 아무것도 안 했는데
 * 표가 두 줄로 갈린 채 끝난다. 순서도 계약이다 — `split()` → `option_status` 갱신 →
 * 부수효과. 분할이 먼저여야 교환 승인의 재고 차감이 **줄어든 수량만** 차감한다.
 *
 * ## 원본 = 전이분, 신규 = 잔여 (주문상세 분할과 반대)
 *
 * 부분 성공 계약이 `succeeded: [id]` 로 **운영자가 체크한 행 ID** 를 돌려준다. 선택한 id 가
 * 잔여로 남으면 "승인했는데 succeeded 에 없는 id" 가 되고, 활동로그 전/후와 반려 부수효과도
 * 선택된 행에서 떨어져 나간다. 그래서 원본 행이 전이분을 갖고 잔여가 새 행으로 나간다.
 *
 * 병합은 하지 않는다(`OrderOptionService:105-109` 가 클레임 코스 전이에서 병합을 끈 이유의
 * 대칭이다) — 클레임 아이템을 합치면 활동로그 `option_id` 가 고아가 되고, 환불 행은 어느
 * 라운드 몫인지 구분되지 않는다. 계보 컬럼(`parent_option_id` 등)도 두지 않는다: 계보는
 * `order_option_id` + 신청서 FK 로 이미 닫혀 있고, 분할 사실은 활동로그가 나른다.
 *
 * ## 행에 붙은 사실은 잔여 행이 그대로 잇는다
 *
 * 송장(회수·재발송 택배사·운송장·등록 시각·도착 시각)·회수지·재고 복구 표시는 **행의 사실**이다.
 * `replicate()` 가 그대로 복사하므로 잔여 행은 원래 값을 가진다 — 「셔츠 3개 중 1개에만 송장」 이면
 * 호출부가 쪼갠 뒤 전이분 행에만 새 송장을 쓴다(R4).
 *
 * ## 교환품을 이미 보낸 교환 행도 쪼갠다 (R10 — PO 2026-09-25)
 *
 * 선교환에서는 새 물건을 먼저 보낸 뒤 헌 물건을 나눠 받는다. 교환품의 후속 주문옵션
 * (`new_order_option_id`)이 있는 행을 쪼갤 때는 **후속 주문옵션도 같은 수량으로 쪼개고**
 * (`OrderOptionService::splitOffQuantity()` — 주문 쪽 금액 안분 재사용), 잔여 교환 행이 새로 생긴
 * 후속 행을 가리키게 한다. 두 조각이 한 후속 옵션을 가리키면 재발송 수량이 오류 없이 어긋난다.
 */
class ClaimItemSplitter
{
    /**
     * @param  OrderExchangeOptionRepositoryInterface  $exchangeOptionRepository  교환 신청 상품 Repository
     * @param  OrderReturnOptionRepositoryInterface  $returnOptionRepository  반품 신청 상품 Repository
     * @param  OrderRefundOptionRepositoryInterface  $refundOptionRepository  환불 신청 상품 Repository
     * @param  OrderOptionRepositoryInterface  $orderOptionRepository  주문 옵션 Repository (교환 후속 주문옵션 조회)
     * @param  OrderOptionService  $orderOptionService  주문 옵션 서비스 (후속 주문옵션 수량 분할)
     */
    public function __construct(
        protected OrderExchangeOptionRepositoryInterface $exchangeOptionRepository,
        protected OrderReturnOptionRepositoryInterface $returnOptionRepository,
        protected OrderRefundOptionRepositoryInterface $refundOptionRepository,
        protected OrderOptionRepositoryInterface $orderOptionRepository,
        protected OrderOptionService $orderOptionService,
    ) {}

    /**
     * 신청 상품 행을 지정 수량만큼 갈라, 원본을 전이분으로 남기고 잔여를 새 행으로 냅니다.
     *
     * 전량을 지정하면 나눌 것이 없으므로 원본을 그대로 돌려준다(하위호환 경로 — 수량을
     * 싣지 않는 기존 호출부가 이 분기로 들어온다).
     *
     * @param  OrderExchangeOption|OrderReturnOption|OrderRefundOption  $item  신청 상품 행
     * @param  int  $quantity  이번에 전이할 수량
     * @return array{target: OrderExchangeOption|OrderReturnOption|OrderRefundOption, remainder: OrderExchangeOption|OrderReturnOption|OrderRefundOption|null} 전이분과 잔여분
     *
     * @throws OrderClaimException 수량 범위 밖 / 나눌 수 없는 행
     */
    public function split(
        OrderExchangeOption|OrderReturnOption|OrderRefundOption $item,
        int $quantity,
    ): array {
        $column = $this->quantityColumn($item);
        $current = (int) $item->{$column};

        if ($quantity < 1 || $quantity > $current) {
            throw OrderClaimException::quantityInvalid();
        }

        if ($quantity === $current) {
            return ['target' => $item, 'remainder' => null];
        }

        $this->assertSplittable($item);

        $remainingQuantity = $current - $quantity;
        $ratio = $quantity / $current;

        // replicate() 전에 원본 금액을 캡처한다 — 원본을 먼저 줄이면 잔여분이 이미 줄어든
        // 값에서 다시 빠져 합이 원본과 어긋난다(주문옵션 분할이 같은 이유로 같은 순서다).
        $captured = $this->captureAmounts($item);

        $remainder = $this->createRemainder($item, $remainingQuantity, $captured, $ratio);
        $target = $this->applyTargetAmounts($item, $quantity, $captured, $ratio);

        if ($item instanceof OrderExchangeOption && $item->new_order_option_id !== null) {
            $remainder = $this->splitSuccessorOption($item, $remainder, $remainingQuantity);
        }

        return ['target' => $target, 'remainder' => $remainder];
    }

    /**
     * 그 행이 수량으로 나뉠 수 있는지 판정합니다. (화면·서버 공용 술어)
     *
     * `ClaimItemResource` 가 이 판정을 `is_splittable` 로 실어 화면이 그 행의 수량 입력을
     * 잠근다 — 서버만 막으면 운영자는 입력해 놓고 [확인] 에서야 422 를 본다. 리소스가 직렬화
     * 시점에 컨테이너를 건드리지 않도록 **static** 이다(행마다 해석하면 N+1 이 된다).
     *
     * @param  OrderExchangeOption|OrderReturnOption|OrderRefundOption  $item  신청 상품 행
     * @return bool 나눌 수 있으면 true
     */
    public static function isSplittable(OrderExchangeOption|OrderReturnOption|OrderRefundOption $item): bool
    {
        $status = $item->option_status;

        // 교환품을 이미 보낸 행(`new_order_option_id` 보유)도 쪼갤 수 있다 — `split()` 이 후속
        // 주문옵션을 같은 수량으로 함께 쪼갠다(R10). 끝난 행만 나눌 수 없다.
        return ! ($status instanceof \BackedEnum && method_exists($status, 'isFinal') && $status->isFinal());
    }

    /**
     * 교환 행의 후속 주문옵션을 잔여 수량만큼 떼어 내고 잔여 교환 행에 잇습니다. (R10)
     *
     * 원본 교환 행은 원래 후속 옵션을 계속 가리키고(수량은 전이분으로 줄어든다), 잔여 교환 행은
     * 떼어 낸 새 후속 옵션을 가리킨다.
     *
     * @param  OrderExchangeOption  $item  원본 교환 행 (전이분)
     * @param  OrderExchangeOption  $remainder  잔여 교환 행
     * @param  int  $remainingQuantity  잔여 수량
     * @return OrderExchangeOption 새 후속 옵션을 가리키는 잔여 교환 행
     */
    protected function splitSuccessorOption(
        OrderExchangeOption $item,
        OrderExchangeOption $remainder,
        int $remainingQuantity,
    ): OrderExchangeOption {
        $successor = $this->orderOptionRepository->findOrFail((int) $item->new_order_option_id);

        // 후속 옵션 수량이 교환 행 수량과 어긋나 있으면(데이터 보정 등) 쪼개지 않는다 — 잔여 교환 행은
        // 후속 옵션 없이 남고, 발송 단계에 들어가면 `ensureNewOrderOption()` 이 자기 몫을 새로 만든다.
        if ((int) $successor->quantity <= $remainingQuantity) {
            return $this->exchangeOptionRepository->update($remainder, ['new_order_option_id' => null]);
        }

        $split = $this->orderOptionService->splitOffQuantity($successor, $remainingQuantity);

        return $this->exchangeOptionRepository->update($remainder, ['new_order_option_id' => $split->id]);
    }

    /**
     * 나눌 수 없는 행이면 거절합니다.
     *
     * @param  OrderExchangeOption|OrderReturnOption|OrderRefundOption  $item  신청 상품 행
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 나눌 수 없는 행
     */
    protected function assertSplittable(OrderExchangeOption|OrderReturnOption|OrderRefundOption $item): void
    {
        if (! self::isSplittable($item)) {
            throw OrderClaimException::claimItemNotSplittable();
        }
    }

    /**
     * 유형별 신청 수량 컬럼명을 반환합니다.
     *
     * @param  OrderExchangeOption|OrderReturnOption|OrderRefundOption  $item  신청 상품 행
     * @return string 수량 컬럼명
     */
    protected function quantityColumn(OrderExchangeOption|OrderReturnOption|OrderRefundOption $item): string
    {
        return match (true) {
            $item instanceof OrderExchangeOption => 'exchange_quantity',
            $item instanceof OrderReturnOption => 'return_quantity',
            default => 'quantity',
        };
    }

    /**
     * 비율로 안분할 금액 필드명을 반환합니다.
     *
     * 수량에 비례하지 않는 축(단가·원 수량·원 상태·교환 대상 옵션)은 여기 넣지 않는다 —
     * 안분하면 조각의 단가가 달라져 화면의 `단가 × 수량` 이 소계와 맞지 않는다.
     *
     * @param  OrderExchangeOption|OrderReturnOption|OrderRefundOption  $item  신청 상품 행
     * @return array<int, string> 안분 대상 필드명
     */
    protected function proratedColumns(OrderExchangeOption|OrderReturnOption|OrderRefundOption $item): array
    {
        return match (true) {
            $item instanceof OrderExchangeOption => ['price_difference'],
            $item instanceof OrderReturnOption => ['subtotal_amount'],
            default => ['subtotal_amount', 'discount_amount', 'shipping_amount', 'refund_amount'],
        };
    }

    /**
     * 비율로 안분할 다통화(JSON) 필드명을 반환합니다.
     *
     * @param  OrderExchangeOption|OrderReturnOption|OrderRefundOption  $item  신청 상품 행
     * @return array<int, string> 안분 대상 다통화 필드명
     */
    protected function proratedMultiCurrencyColumns(OrderExchangeOption|OrderReturnOption|OrderRefundOption $item): array
    {
        return $item instanceof OrderExchangeOption ? ['mc_price_difference'] : [];
    }

    /**
     * 분할 전 원본 금액을 캡처합니다.
     *
     * @param  OrderExchangeOption|OrderReturnOption|OrderRefundOption  $item  신청 상품 행
     * @return array<string, mixed> 캡처된 금액
     */
    protected function captureAmounts(OrderExchangeOption|OrderReturnOption|OrderRefundOption $item): array
    {
        $captured = [];

        foreach ($this->proratedColumns($item) as $column) {
            $captured[$column] = (float) ($item->{$column} ?? 0);
        }

        foreach ($this->proratedMultiCurrencyColumns($item) as $column) {
            $value = $item->{$column};
            $captured[$column] = is_array($value) ? $value : null;
        }

        return $captured;
    }

    /**
     * 잔여분을 새 행으로 만듭니다. (원본 − 전이분 — 반올림 합계 보존)
     *
     * 완료 시각만 비운다. 원 수량·원 상태·단가·교환 대상 옵션은 **신청 시점의 사실**이라
     * 조각마다 그대로 승계해야 한다 — 잔여분에서 지우면 그 행의 반려 복귀가 돌아갈 상태를
     * 잃고, 화면은 "원래 몇 개였는지" 를 잔여 조각에서만 말하지 못한다.
     *
     * @param  OrderExchangeOption|OrderReturnOption|OrderRefundOption  $item  원본 행
     * @param  int  $remainingQuantity  잔여 수량
     * @param  array<string, mixed>  $captured  분할 전 금액
     * @param  float  $ratio  전이분 비율
     * @return OrderExchangeOption|OrderReturnOption|OrderRefundOption 잔여 행
     */
    protected function createRemainder(
        OrderExchangeOption|OrderReturnOption|OrderRefundOption $item,
        int $remainingQuantity,
        array $captured,
        float $ratio,
    ): OrderExchangeOption|OrderReturnOption|OrderRefundOption {
        // 속성 배열이 아니라 **모델**을 복제한다 — 원시 속성 배열로 `create()` 를 부르면
        // JSON 캐스트 컬럼(`mc_price_difference`·`new_option_snapshot`)이 이미 인코딩된
        // 문자열인 채로 다시 인코딩되어, 화면에서 보조 통화·교환 옵션명이 통째로 빈다.
        $remainder = $item->replicate(['completed_at']);

        $remainder->{$this->quantityColumn($item)} = $remainingQuantity;
        $remainder->completed_at = null;

        foreach ($this->proratedColumns($item) as $column) {
            $remainder->{$column} = round($captured[$column] - $this->splitAmount($captured[$column], $ratio), 2);
        }

        foreach ($this->proratedMultiCurrencyColumns($item) as $column) {
            $remainder->{$column} = $this->subtractMultiCurrency(
                $captured[$column],
                $this->splitMultiCurrency($captured[$column], $ratio),
            );
        }

        return match (true) {
            $remainder instanceof OrderExchangeOption => $this->exchangeOptionRepository->save($remainder),
            $remainder instanceof OrderReturnOption => $this->returnOptionRepository->save($remainder),
            default => $this->refundOptionRepository->save($remainder),
        };
    }

    /**
     * 원본 행에 전이분 수량·금액을 적용합니다.
     *
     * @param  OrderExchangeOption|OrderReturnOption|OrderRefundOption  $item  원본 행
     * @param  int  $quantity  전이 수량
     * @param  array<string, mixed>  $captured  분할 전 금액
     * @param  float  $ratio  전이분 비율
     * @return OrderExchangeOption|OrderReturnOption|OrderRefundOption 갱신된 원본 행
     */
    protected function applyTargetAmounts(
        OrderExchangeOption|OrderReturnOption|OrderRefundOption $item,
        int $quantity,
        array $captured,
        float $ratio,
    ): OrderExchangeOption|OrderReturnOption|OrderRefundOption {
        $updates = [$this->quantityColumn($item) => $quantity];

        foreach ($this->proratedColumns($item) as $column) {
            $updates[$column] = $this->splitAmount($captured[$column], $ratio);
        }

        foreach ($this->proratedMultiCurrencyColumns($item) as $column) {
            $updates[$column] = $this->splitMultiCurrency($captured[$column], $ratio);
        }

        return match (true) {
            $item instanceof OrderExchangeOption => $this->exchangeOptionRepository->update($item, $updates),
            $item instanceof OrderReturnOption => $this->returnOptionRepository->update($item, $updates),
            default => $this->refundOptionRepository->update($item, $updates),
        };
    }

    /**
     * 전이분 금액을 계산합니다.
     *
     * @param  float  $original  원본 금액
     * @param  float  $ratio  전이분 비율
     * @return float 전이분 금액
     */
    protected function splitAmount(float $original, float $ratio): float
    {
        return round($original * $ratio, 2);
    }

    /**
     * 다통화 금액의 전이분을 계산합니다.
     *
     * @param  array<string, mixed>|null  $original  원본 다통화 금액
     * @param  float  $ratio  전이분 비율
     * @return array<string, float>|null 전이분 다통화 금액
     */
    protected function splitMultiCurrency(?array $original, float $ratio): ?array
    {
        if (! $original) {
            return null;
        }

        $result = [];

        foreach ($original as $currency => $amount) {
            $result[$currency] = round((float) $amount * $ratio, 2);
        }

        return $result;
    }

    /**
     * 다통화 금액에서 전이분을 뺀 잔여를 계산합니다.
     *
     * @param  array<string, mixed>|null  $original  원본 다통화 금액
     * @param  array<string, float>|null  $split  전이분 다통화 금액
     * @return array<string, float>|null 잔여 다통화 금액
     */
    protected function subtractMultiCurrency(?array $original, ?array $split): ?array
    {
        if (! $original) {
            return null;
        }

        $result = [];

        foreach ($original as $currency => $amount) {
            $result[$currency] = round((float) $amount - (float) ($split[$currency] ?? 0), 2);
        }

        return $result;
    }
}
