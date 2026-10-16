<?php

namespace Modules\Sirsoft\Ecommerce\Console\Commands;

use App\Models\ActivityLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyCountrySetting;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Services\OrderPrivacyRetentionService;

/**
 * Playwright E2E · 관리자 클레임 상세 실측용 신청서 시드 커맨드 (#757).
 *
 * 클레임 상세 spec(`admin-claim-detail.spec.ts`)의 세 시험은 **특정 모양의 신청서**가 있어야 끝까지 잰다 —
 * 라이브 DB 에 그런 건이 우연히 있을 때만 돌면 「건너뜀」이 통과로 세어진다. 이 커맨드가 그 셋을 세운다:
 *
 *   - exchange — 옵션 변경 교환(수량 2, 단가 29,000 → 34,000, 차액 +10,000). 교환 전후 소계가 단가와 갈리는
 *                것은 수량 2 이상에서만 드러나므로 수량을 2 로 둔다. 상태 `requested`.
 *   - return   — 상품 1건짜리 반품, 헤더·상품 행 모두 `approved` — 상품 칸 선택지에 「회수중」이 선다.
 *                송장은 비워 둔다(미리 채움이 빈 값으로 서는지까지 잰다).
 *   - refund   — 환불 계좌가 비어 있는 `approved` 환불신청서(입금된 가상계좌 주문, 현금 29,000).
 *                확인 창 계좌 칸의 수단별 판정(`refund_account_by_method.bank.required_if_cash`)이 참이 된다.
 *
 * **주문은 실제 계산 경로로 만든다.** 상세 응답의 정산 미리보기는 주문을 다시 계산하므로, 금액을 손으로 박은
 * 주문은 재계산과 어긋나 500 이 되거나 엉뚱한 값을 낸다. 그래서 모듈 PHPUnit 의 클레임 조립 헬퍼
 * (`tests/Concerns/BuildsClaimAdjustmentOrders::createClaimOrder`)와 같은 순서 — 전용 배송정책·상품·옵션 →
 * `OrderCalculationService::calculate()` → 그 결과로 주문·주문옵션·배송·결제 행 — 를 따른다.
 *
 * **신청서는 팩토리·모델 생성으로 목표 상태에 바로 세운다.** 접수·전이 서비스를 지나면 고객·운영자 알림과
 * 활동 로그 훅이 라이브 사이트에 실제로 발송·기록되고, 정리(`--teardown`)가 그것까지 되돌릴 수 없다. 행의 모양은
 * 접수 서비스가 쓰는 것과 같게 맞춘다(교환 `items_snapshot`·`new_option_snapshot`, 차액 = (새 단가 − 단가) × 수량).
 * 모듈 Feature 시험(`ClaimRefundAccountByMethodTest` 등)이 같은 상태를 같은 방식으로 세운다.
 *
 * **운영 데이터와 섞이지 않게** 모든 행에 표식을 단다 — 주문번호 `E2E-CLM-{tag}-*`, 신청서 번호
 * `EX|RT|RF-E2ECLM-{tag}`, 상품 코드 `E2ECLM{tag}`(숨김 상품), 배송정책 이름 `E2E 클레임 상세 {tag}`. 비회원
 * 주문이라 회원이 생기지 않는다. `{tag}` 는 호출자가 준다 — Playwright `fullyParallel` 워커마다 다른 tag 를 써야
 * 한 워커의 재시드·정리가 다른 워커의 신청서를 지우지 않는다(memory `e2e-seed-fixed-subjects-collide-under-fully-parallel`).
 *
 * 재실행하면 같은 tag 의 데이터를 먼저 지우고 다시 만든다(멱등). `--teardown --tag=X` 는 그 tag 만,
 * `--teardown` 단독은 이 커맨드가 남긴 전부를 지운다(남은 찌꺼기 정리용). 주문 삭제는 보유기간 영구 삭제와 같은
 * 명시 삭제 경로(`OrderPrivacyRetentionService::purge` — 신청서·결제·첨부 포함)를 쓴다.
 *
 * 팩토리를 쓰므로 개발 의존성(faker)이 설치된 환경에서만 동작한다 — 이 커맨드 자체가 개발 전용이다.
 *
 * 보안 가드 (코어 PlaywrightIssueToken 과 동일 3중 패턴):
 *   ① CLI 한정 — `php_sapi_name() === 'cli'`
 *   ② G7_PLAYWRIGHT_BYPASS=1 환경변수 옵트인
 *   ③ APP_DEBUG=true inline override
 *
 * 호출 예시:
 *   G7_PLAYWRIGHT_BYPASS=1 php artisan playwright:seed-claim-detail --tag=w0abc --json
 *   G7_PLAYWRIGHT_BYPASS=1 php artisan playwright:seed-claim-detail --tag=w0abc --teardown --json
 *   G7_PLAYWRIGHT_BYPASS=1 php artisan playwright:seed-claim-detail --teardown --json
 */
class PlaywrightSeedClaimDetail extends Command
{
    /** 시드 주문번호 접두 — 재실행·정리의 모집단 */
    private const ORDER_PREFIX = 'E2E-CLM-';

    /** 신청서 번호에 넣는 표식 — spec 이 운영 데이터 발견에서 시드를 거를 때 같은 문자열을 쓴다 */
    private const CLAIM_MARK = 'E2ECLM';

    /** 시드 상품 코드 접두 (공개 상세 라우트 제약 `[0-9A-Za-z]+` 에 맞게 하이픈 없이) */
    private const PRODUCT_CODE_PREFIX = 'E2ECLM';

    /** 시드 주문 단가 */
    private const UNIT_PRICE = 29000;

    /** 교환 대상 옵션의 추가금 */
    private const EXCHANGE_PRICE_ADJUSTMENT = 5000;

    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'playwright:seed-claim-detail
        {--tag= : 워커별 구분 꼬리표 (영숫자 1~16자). 시드에는 필수, 정리에서 생략하면 전량}
        {--teardown : 시드한 데이터를 삭제한다}
        {--json : 결과를 JSON 으로 출력}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = 'Playwright E2E · 관리자 클레임 상세 실측용 교환·반품·환불 신청서 시드 (CLI + G7_PLAYWRIGHT_BYPASS 가드)';

    /**
     * 커맨드를 실행합니다.
     *
     * @param  OrderPrivacyRetentionService  $retention  주문 영구 삭제 서비스 (정리)
     * @param  OrderCalculationService  $calculator  주문 계산 서비스 (주문 금액의 정본)
     * @return int 종료 코드
     */
    public function handle(OrderPrivacyRetentionService $retention, OrderCalculationService $calculator): int
    {
        // ① CLI 한정
        if (php_sapi_name() !== 'cli') {
            $this->error('CLI 전용 커맨드입니다.');

            return self::FAILURE;
        }

        // ② 명시 옵트인
        if (env('G7_PLAYWRIGHT_BYPASS') !== '1') {
            $this->error('G7_PLAYWRIGHT_BYPASS=1 환경변수가 필요합니다.');

            return self::FAILURE;
        }

        // ③ APP_DEBUG 강제
        Config::set('app.debug', true);

        $tag = $this->option('tag');
        $tag = is_string($tag) && $tag !== '' ? $tag : null;

        if ($tag !== null && ! preg_match('/^[A-Za-z0-9]{1,16}$/', $tag)) {
            $this->error('--tag 는 영숫자 1~16자여야 합니다.');

            return self::FAILURE;
        }

        if ($this->option('teardown')) {
            $result = $this->purge($retention, $tag);
        } else {
            if ($tag === null) {
                $this->error('시드에는 --tag 가 필요합니다 (병렬 워커끼리 서로의 신청서를 지우지 않도록).');

                return self::FAILURE;
            }

            $this->purge($retention, $tag);
            $result = DB::transaction(fn () => $this->seed($calculator, $tag));
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($result as $key => $value) {
                $this->line($key.': '.(is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)));
            }
        }

        return self::SUCCESS;
    }

    /**
     * 세 신청서를 만듭니다.
     *
     * @param  OrderCalculationService  $calculator  주문 계산 서비스
     * @param  string  $tag  워커별 꼬리표
     * @return array<string, mixed> 신청서 번호·주문번호
     */
    private function seed(OrderCalculationService $calculator, string $tag): array
    {
        [$product, $baseOption, $targetOption] = $this->catalog($tag);

        $exchange = $this->exchange($calculator, $tag, $product, $baseOption, $targetOption);
        $return = $this->returnClaim($calculator, $tag, $product, $baseOption);
        $refund = $this->refund($calculator, $tag, $product, $baseOption);

        return [
            'tag' => $tag,
            'claim_mark' => self::CLAIM_MARK,
            'exchange_number' => $exchange->exchange_number,
            'exchange_order_number' => $exchange->order->order_number,
            'return_number' => $return->return_number,
            'return_order_number' => $return->order->order_number,
            'refund_number' => $refund->refund_number,
            'refund_order_number' => $refund->order->order_number,
        ];
    }

    /**
     * 전용 배송정책·상품·옵션 둘(기본 · 교환 대상)을 만듭니다.
     *
     * 기존 상품을 쓰지 않는다 — 옵션 변경 교환에는 같은 상품의 다른 옵션이 필요하고, 운영 상품의 재고·옵션을
     * 건드리지 않아야 한다. 상점 화면에 섞이지 않게 숨김 상품으로 둔다(주문 계산은 노출 상태를 보지 않는다).
     *
     * @param  string  $tag  워커별 꼬리표
     * @return array{0: Product, 1: ProductOption, 2: ProductOption} [상품, 기본 옵션, 교환 대상 옵션]
     */
    private function catalog(string $tag): array
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => 'E2E 클레임 상세 '.$tag, 'en' => 'E2E claim detail '.$tag],
            'is_default' => false,
            'is_active' => true,
        ]);

        $policy->countrySettings()->create([
            'country_code' => 'KR',
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => ChargePolicyEnum::FREE,
            'base_fee' => 0,
            'free_threshold' => null,
            'extra_fee_enabled' => false,
            'is_active' => true,
        ]);

        $product = ProductFactory::new()->create([
            'product_code' => self::PRODUCT_CODE_PREFIX.$tag,
            'name' => ['ko' => 'E2E 클레임 상세 상품', 'en' => 'E2E claim detail product'],
            'tax_status' => 'taxable',
            'selling_price' => self::UNIT_PRICE,
            'list_price' => self::UNIT_PRICE,
            'stock_quantity' => 100,
            'display_status' => ProductDisplayStatus::HIDDEN,
            'shipping_policy_id' => $policy->id,
            'has_options' => true,
            'option_groups' => [[
                'name' => ['ko' => '색상', 'en' => 'Color'],
                'values' => [['ko' => '화이트', 'en' => 'White'], ['ko' => '블랙', 'en' => 'Black']],
            ]],
        ]);

        $baseOption = ProductOptionFactory::new()->forProduct($product)->create([
            'option_code' => self::PRODUCT_CODE_PREFIX.'-'.$tag.'-WHITE',
            'option_values' => [['key' => ['ko' => '색상', 'en' => 'Color'], 'value' => ['ko' => '화이트', 'en' => 'White']]],
            'option_name' => ['ko' => '화이트', 'en' => 'White'],
            'price_adjustment' => 0,
            'stock_quantity' => 100,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $targetOption = ProductOptionFactory::new()->forProduct($product)->create([
            'option_code' => self::PRODUCT_CODE_PREFIX.'-'.$tag.'-BLACK',
            'option_values' => [['key' => ['ko' => '색상', 'en' => 'Color'], 'value' => ['ko' => '블랙', 'en' => 'Black']]],
            'option_name' => ['ko' => '블랙', 'en' => 'Black'],
            'price_adjustment' => self::EXCHANGE_PRICE_ADJUSTMENT,
            'stock_quantity' => 100,
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        return [$product->fresh(), $baseOption->fresh(), $targetOption->fresh()];
    }

    /**
     * 옵션 변경 교환(수량 2, 상위 옵션 — 차액 청구)을 만듭니다.
     *
     * @param  OrderCalculationService  $calculator  주문 계산 서비스
     * @param  string  $tag  워커별 꼬리표
     * @param  Product  $product  시드 상품
     * @param  ProductOption  $baseOption  주문한 옵션
     * @param  ProductOption  $targetOption  교환 대상 옵션
     * @return OrderExchange 교환 신청서
     */
    private function exchange(
        OrderCalculationService $calculator,
        string $tag,
        Product $product,
        ProductOption $baseOption,
        ProductOption $targetOption,
    ): OrderExchange {
        $quantity = 2;
        $order = $this->order($calculator, $tag, 'EX', $product, $baseOption, $quantity, OrderStatusEnum::DELIVERED);
        $orderOption = $order->options->first();

        $unitPrice = (float) $orderOption->unit_price;
        $newUnitPrice = (float) $targetOption->getFinalPrice();
        // 접수 서비스와 같은 식 — (새 단가 − 단가) × 수량 (추가옵션 없음)
        $difference = ($newUnitPrice - $unitPrice) * $quantity;

        $orderOption->forceFill(['option_status' => OrderStatusEnum::EXCHANGE_IN_PROGRESS])->save();

        $newOptionSnapshot = $targetOption->toSnapshotArray();
        $newOptionSnapshot['option_value'] = ['ko' => '색상: 블랙', 'en' => 'Color: Black'];

        $exchange = OrderExchange::factory()->create([
            'order_id' => $order->id,
            'exchange_number' => 'EX-'.self::CLAIM_MARK.'-'.$tag,
            'exchange_status' => ExchangeStatusEnum::REQUESTED,
            'reason_code' => 'size_mismatch',
            'fault_type' => ClaimReasonFaultTypeEnum::CUSTOMER,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_APPROVAL,
            'claim_shipping_fee' => 0,
            'additional_product_amount' => max(0, $difference),
            'refund_product_amount' => max(0, -$difference),
            'total_charge_amount' => max(0, $difference),
            'currency_code' => $order->currency,
            'items_snapshot' => [[
                'option_id' => $orderOption->id,
                'product_name' => $orderOption->product_name,
                'option_name' => $orderOption->option_name,
                'option_value' => $orderOption->option_value,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'option_status' => OrderStatusEnum::DELIVERED->value,
                'new_product_option_id' => $targetOption->id,
                'new_option_name' => $targetOption->option_name,
                'new_unit_price' => $newUnitPrice,
                'price_difference' => $difference,
            ]],
        ]);

        OrderExchangeOption::factory()->create([
            'order_exchange_id' => $exchange->id,
            'order_id' => $order->id,
            'order_option_id' => $orderOption->id,
            'option_status' => ExchangeStatusEnum::REQUESTED,
            'exchange_quantity' => $quantity,
            'original_quantity' => $quantity,
            'original_option_status' => OrderStatusEnum::DELIVERED,
            'unit_price' => $unitPrice,
            'new_product_option_id' => $targetOption->id,
            'new_option_snapshot' => $newOptionSnapshot,
            'new_unit_price' => $newUnitPrice,
            'price_difference' => $difference,
        ]);

        return $exchange->fresh(['order']);
    }

    /**
     * 상품 1건짜리 승인 반품(송장 없음)을 만듭니다.
     *
     * @param  OrderCalculationService  $calculator  주문 계산 서비스
     * @param  string  $tag  워커별 꼬리표
     * @param  Product  $product  시드 상품
     * @param  ProductOption  $option  주문한 옵션
     * @return OrderReturn 반품 신청서
     */
    private function returnClaim(
        OrderCalculationService $calculator,
        string $tag,
        Product $product,
        ProductOption $option,
    ): OrderReturn {
        $order = $this->order($calculator, $tag, 'RT', $product, $option, 1, OrderStatusEnum::DELIVERED);
        $orderOption = $order->options->first();
        $unitPrice = (float) $orderOption->unit_price;

        $orderOption->forceFill(['option_status' => OrderStatusEnum::RETURN_IN_PROGRESS])->save();

        $return = OrderReturn::factory()->create([
            'order_id' => $order->id,
            'return_number' => 'RT-'.self::CLAIM_MARK.'-'.$tag,
            'return_status' => ReturnStatusEnum::APPROVED,
            'reason_code' => 'changed_mind',
            'fault_type' => ClaimReasonFaultTypeEnum::CUSTOMER,
            'claim_shipping_fee' => 0,
            'total_charge_amount' => 0,
            'currency_code' => $order->currency,
            'approved_at' => now(),
            'items_snapshot' => [[
                'option_id' => $orderOption->id,
                'product_name' => $orderOption->product_name,
                'option_name' => $orderOption->option_name,
                'option_value' => $orderOption->option_value,
                'quantity' => 1,
                'unit_price' => $unitPrice,
                'option_status' => OrderStatusEnum::DELIVERED->value,
            ]],
        ]);

        OrderReturnOption::factory()->create([
            'order_return_id' => $return->id,
            'order_id' => $order->id,
            'order_option_id' => $orderOption->id,
            'option_status' => ReturnStatusEnum::APPROVED,
            'return_quantity' => 1,
            'original_quantity' => 1,
            'original_option_status' => OrderStatusEnum::DELIVERED,
            'unit_price' => $unitPrice,
            'subtotal_amount' => $unitPrice,
        ]);

        return $return->fresh(['order']);
    }

    /**
     * 환불 계좌가 비어 있는 승인 환불신청서(입금된 가상계좌 주문)를 만듭니다.
     *
     * @param  OrderCalculationService  $calculator  주문 계산 서비스
     * @param  string  $tag  워커별 꼬리표
     * @param  Product  $product  시드 상품
     * @param  ProductOption  $option  주문한 옵션
     * @return OrderRefund 환불신청서
     */
    private function refund(
        OrderCalculationService $calculator,
        string $tag,
        Product $product,
        ProductOption $option,
    ): OrderRefund {
        $order = $this->order($calculator, $tag, 'RF', $product, $option, 1, OrderStatusEnum::PAYMENT_COMPLETE, [
            // 입금된 가상계좌 — PG 가 고객 계좌로 송금하는 결제라 계좌 판정 축이 선다. 주문 시점 환불 계좌는 비운다.
            'payment_method' => PaymentMethodEnum::VBANK->value,
            'vbank_code' => '004',
            'vbank_name' => 'KB국민은행',
            'vbank_number' => '000-000-000000',
            'vbank_holder' => 'E2E 클레임',
            'vbank_issued_at' => now()->subDays(2),
            'card_name' => null,
            'card_number_masked' => null,
            'card_approval_number' => null,
            'refund_bank_code' => null,
            'refund_bank_name' => null,
            'refund_bank_account' => null,
            'refund_bank_holder' => null,
        ]);
        $orderOption = $order->options->first();
        $unitPrice = (float) $orderOption->unit_price;
        $cash = (float) $order->total_paid_amount;

        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::DIRECT,
            'refund_number' => 'RF-'.self::CLAIM_MARK.'-'.$tag,
            'refund_status' => RefundStatusEnum::APPROVED,
            'refund_method' => RefundMethodEnum::BANK,
            'reason_code' => 'changed_mind',
            'fault_type' => ClaimReasonFaultTypeEnum::CUSTOMER,
            'refund_amount' => $cash,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
            'refund_bank_code' => null,
            'refund_bank_account' => null,
            'refund_bank_holder' => null,
            'approved_at' => now(),
        ]);

        OrderRefundOption::create([
            'order_refund_id' => $refund->id,
            'order_id' => $order->id,
            'order_option_id' => $orderOption->id,
            'option_status' => RefundStatusEnum::APPROVED,
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'subtotal_amount' => $unitPrice,
            'discount_amount' => 0,
            'refund_amount' => $cash,
        ]);

        return $refund->fresh(['order']);
    }

    /**
     * 단일 상품 비회원 주문을 계산 결과로 만듭니다. (주소·배송·결제 포함)
     *
     * `BuildsClaimAdjustmentOrders::createClaimOrder()` 와 같은 순서다 — 금액은 전부 계산 서비스가 정한다.
     * 배송 행은 주문옵션마다 배송정책을 달아 만든다(`BuildsClaimFlowOrders::attachPolicyShippings()` 와 같은 형태).
     *
     * @param  OrderCalculationService  $calculator  주문 계산 서비스
     * @param  string  $tag  워커별 꼬리표
     * @param  string  $kind  주문번호 꼬리 (EX|RT|RF)
     * @param  Product  $product  상품
     * @param  ProductOption  $option  상품 옵션
     * @param  int  $quantity  수량
     * @param  OrderStatusEnum  $status  주문·주문옵션 상태
     * @param  array<string, mixed>  $paymentOverrides  결제행 덮어쓰기
     * @return Order 주문 (options 로드됨)
     */
    private function order(
        OrderCalculationService $calculator,
        string $tag,
        string $kind,
        Product $product,
        ProductOption $option,
        int $quantity,
        OrderStatusEnum $status,
        array $paymentOverrides = [],
    ): Order {
        $result = $calculator->calculate(new CalculationInput(items: [
            new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: $quantity),
        ]));

        // 계산이 상품을 싣지 못하면 옵션 없는 주문이 생겨 상세 화면이 엉뚱한 곳에서 깨진다 — 여기서 멈춘다(트랜잭션 롤백).
        if (count($result->items) !== 1) {
            // 개발 전용 E2E 시드의 CLI 진단 문구 — 사용자 노출이 없어 언어 키를 두지 않는다.
            throw new \RuntimeException('Order calculation did not include the seed product option (product_option_id='.$option->id.').');
        }

        $shippingPolicySnapshot = [];
        foreach ($result->items as $item) {
            if ($item->appliedShippingPolicy) {
                $shippingPolicySnapshot[$item->productOptionId] = $item->appliedShippingPolicy->toArray();
            }
        }

        $delivered = $status === OrderStatusEnum::DELIVERED;
        $finalAmount = $result->summary->finalAmount;

        // 비회원 주문 — 팩토리 기본값은 회원을 새로 만들어, 정리 뒤에도 개발 DB 에 회원이 쌓인다.
        $order = Order::factory()->forGuest()->create([
            'order_number' => self::ORDER_PREFIX.$tag.'-'.$kind,
            'order_status' => $status,
            'ip_address' => '203.0.113.57',
            'subtotal_amount' => $result->summary->subtotal,
            'total_discount_amount' => $result->summary->totalDiscount,
            'base_shipping_amount' => $result->summary->baseShippingTotal,
            'extra_shipping_amount' => $result->summary->extraShippingTotal,
            'shipping_discount_amount' => $result->summary->shippingDiscount,
            'total_shipping_amount' => $result->summary->totalShipping,
            'total_amount' => $finalAmount,
            'total_paid_amount' => $finalAmount,
            'total_due_amount' => 0,
            'total_points_used_amount' => $result->summary->pointsUsed,
            'is_mileage_deducted' => $result->summary->pointsUsed > 0,
            'total_tax_amount' => $result->summary->taxableAmount,
            'total_tax_free_amount' => $result->summary->taxFreeAmount,
            'total_cancelled_amount' => 0,
            'total_refunded_amount' => 0,
            'total_refunded_points_amount' => 0,
            'total_earned_points_amount' => 0,
            'item_count' => 1,
            'shipping_policy_applied_snapshot' => $shippingPolicySnapshot,
            'ordered_at' => now()->subDays(3),
            'paid_at' => now()->subDays(3),
        ]);

        foreach ($result->items as $item) {
            $orderOption = OrderOption::factory()->forOrder($order)->create([
                'product_id' => $item->productId,
                'product_option_id' => $item->productOptionId,
                'option_status' => $status,
                'product_name' => $product->name,
                'product_option_name' => $option->option_name,
                'option_name' => $option->option_name,
                'option_value' => ['ko' => '색상: 화이트', 'en' => 'Color: White'],
                'quantity' => $item->quantity,
                'unit_price' => $item->unitPrice,
                'subtotal_price' => $item->subtotal,
                'subtotal_discount_amount' => 0,
                'coupon_discount_amount' => 0,
                'code_discount_amount' => 0,
                'subtotal_paid_amount' => $item->finalAmount,
                'subtotal_tax_amount' => $item->taxableAmount,
                'subtotal_tax_free_amount' => $item->taxFreeAmount,
                'subtotal_points_used_amount' => $item->pointsUsedShare,
                'subtotal_earned_points_amount' => $item->pointsEarning,
                'product_snapshot' => $product->toSnapshotArray(),
                'option_snapshot' => $option->toSnapshotArray(),
                'delivered_at' => $delivered ? now()->subDay() : null,
                'is_stock_deducted' => true,
            ]);

            OrderShipping::factory()->forOrderOption($orderOption)->create([
                'shipping_policy_id' => $product->shipping_policy_id,
                'delivery_policy_snapshot' => ['country_code' => 'KR'],
                'base_shipping_amount' => 0,
                'extra_shipping_amount' => 0,
                'total_shipping_amount' => 0,
                'shipping_discount_amount' => 0,
            ]);
        }

        OrderAddress::factory()->create([
            'order_id' => $order->id,
            'address_type' => 'shipping',
            'orderer_name' => '클레임상세주문자',
            'orderer_phone' => '010-5757-0001',
            'orderer_email' => 'e2e-claim-detail@example.test',
            'recipient_name' => '클레임상세수령인',
            'recipient_phone' => '010-5757-0002',
            'recipient_country_code' => 'KR',
            'zipcode' => '06236',
            'address' => '서울 강남구 테헤란로 1',
            'address_detail' => '101호',
        ]);

        OrderPayment::factory()->forOrder($order)->create(array_merge([
            'payment_status' => PaymentStatusEnum::PAID,
            'paid_amount_local' => $finalAmount,
            'paid_amount_base' => $finalAmount,
            'currency' => $order->currency,
            'buyer_name' => '클레임상세주문자',
            'buyer_email' => 'e2e-claim-detail@example.test',
            'paid_at' => now()->subDays(3),
        ], $paymentOverrides));

        return $order->fresh(['options', 'shippings', 'payment', 'payments']);
    }

    /**
     * 시드 데이터를 지웁니다 (재실행 멱등의 핵심).
     *
     * 주문은 보유기간 삭제와 같은 명시 삭제 경로로 지운다 — 신청서·신청 행·클레임 결제·첨부까지 함께 사라진다.
     * 그 경로가 남기는 활동 로그도 시드의 흔적이라 함께 지운다. 상품·옵션·배송정책은 주문이 사라진 뒤에 지운다
     * (주문옵션·상품이 그것을 참조한다).
     *
     * @param  OrderPrivacyRetentionService  $retention  주문 영구 삭제 서비스
     * @param  string|null  $tag  워커별 꼬리표 (null 이면 이 커맨드가 남긴 전부)
     * @return array<string, int> 삭제 건수
     */
    private function purge(OrderPrivacyRetentionService $retention, ?string $tag): array
    {
        $orderPattern = self::ORDER_PREFIX.($tag !== null ? $tag.'-' : '').'%';
        $orders = Order::withTrashed()->where('order_number', 'like', $orderPattern)->get();
        $orderIds = $orders->pluck('id')->all();

        // 신청서 활동 로그를 지우려면 주문이 사라지기 전에 신청서 ID 를 모아 둔다.
        $claimLogTargets = [
            (new OrderExchange)->getMorphClass() => OrderExchange::query()->whereIn('order_id', $orderIds)->pluck('id')->all(),
            (new OrderReturn)->getMorphClass() => OrderReturn::query()->whereIn('order_id', $orderIds)->pluck('id')->all(),
            (new OrderRefund)->getMorphClass() => OrderRefund::query()->whereIn('order_id', $orderIds)->pluck('id')->all(),
            (new Order)->getMorphClass() => $orderIds,
        ];

        foreach ($orders as $order) {
            $retention->purge($order);
        }

        foreach ($claimLogTargets as $morph => $ids) {
            if ($ids !== []) {
                ActivityLog::query()->where('loggable_type', $morph)->whereIn('loggable_id', $ids)->delete();
            }
        }

        $productQuery = DB::table((new Product)->getTable());
        $tag !== null
            ? $productQuery->where('product_code', self::PRODUCT_CODE_PREFIX.$tag)
            : $productQuery->where('product_code', 'like', self::PRODUCT_CODE_PREFIX.'%');
        $products = $productQuery->get(['id', 'shipping_policy_id']);
        $productIds = $products->pluck('id')->all();
        $policyIds = $products->pluck('shipping_policy_id')->filter()->unique()->values()->all();

        $deletedProducts = 0;
        $deletedPolicies = 0;

        if ($productIds !== []) {
            DB::table((new ProductOption)->getTable())->whereIn('product_id', $productIds)->delete();
            $deletedProducts = DB::table((new Product)->getTable())->whereIn('id', $productIds)->delete();
        }

        if ($policyIds !== []) {
            DB::table((new ShippingPolicyCountrySetting)->getTable())->whereIn('shipping_policy_id', $policyIds)->delete();
            $deletedPolicies = DB::table((new ShippingPolicy)->getTable())->whereIn('id', $policyIds)->delete();
        }

        return [
            'deleted_orders' => count($orderIds),
            'deleted_products' => $deletedProducts,
            'deleted_shipping_policies' => $deletedPolicies,
        ];
    }
}
