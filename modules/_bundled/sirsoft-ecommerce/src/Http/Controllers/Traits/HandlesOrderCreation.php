<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Traits;

use App\Extension\HookManager;
use App\Helpers\ResponseHelper;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\CartUnavailableException;
use Modules\Sirsoft\Ecommerce\Exceptions\InsufficientStockException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderProcessingException;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Modules\Sirsoft\Ecommerce\Exceptions\UnsupportedPaymentCurrencyException;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\CreateOrderRequest;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;

/**
 * 주문 생성 공통 흐름 Trait
 *
 * 회원/비회원 주문 생성을 단일 endpoint(POST user/orders → Public\OrderController::store)
 * 에서 처리하는 공통 흐름을 한곳에 둡니다. 임시주문 조회 → 재고 검증 → 주문 생성 →
 * PG 필요 여부 판단 → 임시주문 삭제 → 응답 조립 → 예외 처리까지 공통이며,
 * 회원/비회원이 갈리는 부분(응답 Resource, 회원 전용 후처리)만 호출 측 콜백으로 위임합니다.
 * (회원 컨텍스트는 Auth::id() 로 판정 — userId 가 null 이면 비회원)
 *
 * 이 Trait을 사용하는 컨트롤러는 다음 프로퍼티를 생성자 주입으로 보유해야 합니다.
 * - $tempOrderService (TempOrderService)
 * - $stockService (StockService)
 * - $orderProcessingService (OrderProcessingService)
 */
trait HandlesOrderCreation
{
    /**
     * 주문 생성 공통 흐름을 수행합니다.
     *
     * @param  CreateOrderRequest  $request  검증된 주문 생성 요청
     * @param  int|null  $userId  회원 ID (비회원은 null)
     * @param  callable  $buildResponseData  응답 데이터 빌더. 시그니처: fn (Order $order, bool $requiresPg, string $pgProvider): array
     * @param  callable|null  $afterCreate  주문 생성 직후 후처리 콜백 (회원 전용 배송지 자동저장 등). 시그니처: fn (Order $order, bool $requiresPg): void
     * @param  string  $logEndpoint  API 사용량 로그 엔드포인트 식별자
     * @return JsonResponse 생성된 주문 정보를 포함한 JSON 응답
     */
    protected function processOrderCreation(
        CreateOrderRequest $request,
        ?int $userId,
        callable $buildResponseData,
        ?callable $afterCreate = null,
        string $logEndpoint = 'order.store'
    ): JsonResponse {
        try {
            $this->logApiUsage($logEndpoint);

            $cartKey = $request->header('X-Cart-Key');

            // 임시 주문 조회 (회원: user_id 기준, 비회원: cart_key 기준)
            $tempOrder = $this->tempOrderService->getTempOrder($userId, $cartKey);

            // 임시 주문 없거나 만료된 경우
            if (! $tempOrder) {
                return ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'exceptions.temp_order_not_found',
                    404
                );
            }

            // 재고 검증
            $items = $this->buildStockValidationItems($tempOrder);
            $this->stockService->validateStock($items);

            // 훅: 결제 진입 전 (사용자 본인인증(IDV) 정책 가드 지점 — checkout_verification purpose).
            // EnforceIdentityPolicyListener 가 'sirsoft-ecommerce.checkout.before_payment' 정책이
            // 활성이고 grace 만료 시 IdentityVerificationRequiredException(428) 을 throw 한다.
            // 이 예외는 \Error 를 상속하므로 아래 catch (Exception) 에 걸리지 않고 전파되어 428 이 유지된다.
            // 주문 레코드 생성(createFromTempOrder) 직전이므로 428 로 막혀도 주문 부산물이 생기지 않는다.
            HookManager::doAction('sirsoft-ecommerce.checkout.before_payment', $tempOrder, $userId);

            // 주문 생성 (회원/비회원 공통 — TempOrder.user_id 가 주문 user_id 로 그대로 반영)
            $order = $this->orderProcessingService->createFromTempOrder(
                tempOrder: $tempOrder,
                ordererInfo: $request->getOrdererInfo(),
                shippingInfo: $request->getShippingInfo(),
                paymentMethod: $request->input('payment_method'),
                expectedTotalAmount: (float) $request->input('expected_total_amount'),
                shippingMemo: $request->input('shipping_memo'),
                depositorName: $request->input('depositor_name'),
                dbankInfo: $request->getDbankInfo(),
                guestLookupPassword: $request->getGuestLookupPassword()
            );

            $order->load(['options', 'payment', 'shippingAddress']);

            // PG 결제 필요 여부 판단
            $paymentMethod = PaymentMethodEnum::tryFrom($order->payment->payment_method->value ?? $order->payment->payment_method);
            $pgProvider = $this->orderProcessingService->determinePgProvider($paymentMethod->value);
            $requiresPg = $paymentMethod->needsPgProvider()
                && ! in_array($pgProvider, ['manual', 'internal', 'none'])
                // 결제할 금액이 0원이면(전액 마일리지/예치금 등 비현금 충당) PG 호출 불필요 —
                // 주문 생성 시점에 이미 결제완료 확정됨. 결제수단 선택과 무관.
                && (int) $order->total_due_amount > 0;

            // 임시 주문 삭제: non-PG 만 즉시 삭제, PG 결제는 completePayment() 시점에 삭제
            if (! $requiresPg) {
                $this->tempOrderService->deleteTempOrder($userId, $cartKey);
            }

            // 생성 직후 후처리 (회원 전용 배송지 자동저장 등) — 호출 측 위임
            if ($afterCreate !== null) {
                $afterCreate($order, $requiresPg);
            }

            // 응답 데이터 조립 — 회원/비회원 응답 Resource 차이는 호출 측 위임
            $responseData = $buildResponseData($order, $requiresPg, $pgProvider);

            return ResponseHelper::success('sirsoft-ecommerce::messages.order.created', $responseData, 201);

        } catch (PaymentAmountMismatchException $e) {
            return ResponseHelper::error(
                __('sirsoft-ecommerce::exceptions.payment_amount_mismatch', [
                    'expected' => number_format($e->getExpectedAmount()),
                    'actual' => number_format($e->getActualAmount()),
                ]),
                422
            );

        } catch (UnsupportedPaymentCurrencyException $e) {
            // 결제 통화로 청구 불가(환율 미설정/0 또는 환산액 0) — 명확한 422 차단
            return ResponseHelper::error(
                'sirsoft-ecommerce::exceptions.unsupported_payment_currency',
                422,
                ['code' => 'unsupported_payment_currency', 'currency' => $e->getCurrency()],
                ['currency' => $e->getCurrency()]
            );

        } catch (InsufficientStockException $e) {
            return ResponseHelper::error(
                'sirsoft-ecommerce::exceptions.order_create_failed',
                422,
                [
                    'detail' => $e->getMessage(),
                    'insufficient_items' => $e->getInsufficientItems(),
                ]
            );

        } catch (CartUnavailableException $e) {
            // 구매 대상 제한 등으로 구매 불가 상품이 있는 경우 (회원/비회원 공통)
            $messageKey = $e->hasRestrictionIssue()
                ? 'sirsoft-ecommerce::exceptions.purchase_not_allowed'
                : 'sirsoft-ecommerce::exceptions.cart_unavailable';

            return ResponseHelper::error(__($messageKey), 422, [
                'code' => 'cart_unavailable',
                'unavailable_items' => $e->getUnavailableItems(),
                'has_stock_issue' => $e->hasStockIssue(),
                'has_status_issue' => $e->hasStatusIssue(),
                'has_restriction_issue' => $e->hasRestrictionIssue(),
            ]);

        } catch (OrderProcessingException $e) {
            // 주문 확정 재계산 검증 실패(쿠폰 만료/min_amount/per_user_limit/not_combinable 등)
            // generic 500 이 아닌 422 로 하드 차단 — 서버 우회 방지 (U14/MP06)
            Log::warning('Order create: calculation validation failed', [
                'message' => $e->getMessage(),
            ]);

            return ResponseHelper::error(
                'sirsoft-ecommerce::exceptions.order_calculation_validation_failed',
                422,
                ['code' => 'order_calculation_validation_failed']
            );

        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.order.create_failed',
                500
            );
        }
    }

    /**
     * 주문 생성 응답에 포함할 기본 응답 데이터를 구성합니다.
     *
     * 회원/비회원 컨트롤러가 응답 Resource(OrderResource / GuestOrderResource)를
     * 주입해 공통 메타(redirect_url, requires_pg_payment, PG 데이터)와 합칩니다.
     *
     * @param  Order  $order  생성된 주문
     * @param  bool  $requiresPg  PG 결제 필요 여부
     * @param  string  $pgProvider  PG 공급자 식별자
     * @param  JsonResource  $orderResource  주문 응답 리소스
     * @return array 응답 데이터
     */
    protected function buildOrderResponseData(Order $order, bool $requiresPg, string $pgProvider, $orderResource): array
    {
        $responseData = [
            'order' => $orderResource,
            'redirect_url' => "/shop/orders/{$order->order_number}/complete",
            'requires_pg_payment' => $requiresPg,
        ];

        if ($requiresPg) {
            $responseData['pg_provider'] = "sirsoft-{$pgProvider}";
            $responseData['pg_payment_data'] = $this->buildPgPaymentData($order);

            // provider-agnostic 결제 진입 — provider 레지스트리가 선언한 프론트 결제 진입
            // 핸들러 풀네임을 응답에 그대로 내려 템플릿이 dispatch 한다. provider 가 핸들러를
            // 선언하지 않으면 키를 설정하지 않아(템플릿 PG 분기 미발화) non-PG fallback 으로
            // 안전 강하한다.
            $paymentHandler = $this->resolvePgPaymentHandler($pgProvider);
            if ($paymentHandler !== null) {
                $responseData['pg_payment_handler'] = $paymentHandler;
            }
        }

        return $responseData;
    }

    /**
     * PG provider 의 프론트 결제 진입 핸들러 풀네임을 레지스트리에서 조회합니다.
     *
     * PG 플러그인이 `sirsoft-ecommerce.payment.registered_pg_providers` 필터 훅에 등록한
     * provider 엔트리의 `payment_handler` 키를 반환합니다. 미선언 시 null.
     *
     * @param  string  $pgProvider  PG provider id (예: 'kginicis')
     * @return string|null 프론트 결제 진입 핸들러 풀네임 또는 null
     */
    protected function resolvePgPaymentHandler(string $pgProvider): ?string
    {
        $providers = app(EcommerceSettingsService::class)->getRegisteredPgProviders();

        foreach ($providers as $provider) {
            if (($provider['id'] ?? null) === $pgProvider) {
                $handler = $provider['payment_handler'] ?? null;

                return is_string($handler) && $handler !== '' ? $handler : null;
            }
        }

        return null;
    }

    /**
     * 재고 검증용 아이템 목록 생성
     *
     * @param  TempOrder  $tempOrder  임시 주문
     * @return array 재고 검증용 아이템 배열
     */
    protected function buildStockValidationItems(TempOrder $tempOrder): array
    {
        $items = [];
        $calculationResult = $tempOrder->calculation_result ?? [];

        foreach ($calculationResult['items'] ?? [] as $item) {
            $items[] = [
                'product_option_id' => $item['productOptionId'] ?? $item['product_option_id'],
                'quantity' => $item['quantity'],
            ];
        }

        return $items;
    }

    /**
     * PG 결제용 데이터 생성
     *
     * 주문 생성 API 응답에 포함될 PG 결제 요청 데이터를 빌드합니다.
     *
     * @param  Order  $order  주문
     * @return array PG 결제 요청 데이터
     */
    protected function buildPgPaymentData(Order $order): array
    {
        // 주문명 생성 (로컬라이즈된 첫 번째 상품명 + 외 N건)
        $options = $order->options;
        $locale = app()->getLocale();
        $firstName = $options->first()?->product_name;
        $orderName = is_array($firstName)
            ? ($firstName[$locale] ?? $firstName[config('app.fallback_locale', 'ko')] ?? reset($firstName) ?: '')
            : ($firstName ?? '');
        if ($options->count() > 1) {
            $orderName .= ' 외 '.($options->count() - 1).'건';
        }

        // 주문자 정보 (배송지 주소에서 가져옴)
        $shippingAddress = $order->shippingAddress;

        // PG 청구 금액/통화 = base total_due_amount 를 주문 스냅샷 환율로 결제 통화 환산.
        // amount 는 PG 가 요구하는 최소 화폐단위 정수(KRW 7058 / USD $6→600 / JPY 정수).
        // 미지원 통화(환율 0/미설정)는 resolveSnapshotPaymentCharge 가 InvalidArgumentException 으로 차단.
        $charge = app(CurrencyConversionService::class)
            ->resolveSnapshotPaymentCharge((float) $order->total_due_amount, $order->currency_snapshot ?? []);

        return [
            'order_number' => $order->order_number,
            'order_name' => $orderName,
            'amount' => $charge['minor_unit_amount'],
            'currency' => $charge['currency'],
            'customer_name' => $shippingAddress?->orderer_name,
            'customer_email' => $shippingAddress?->orderer_email,
            'customer_phone' => preg_replace('/[^0-9]/', '', $shippingAddress?->orderer_phone ?? ''),
            'customer_key' => $order->user_id ? "user_{$order->user_id}" : null,
        ];
    }
}
