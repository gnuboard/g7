<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Traits;

use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use Exception;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\DTO\ClaimShippingFee;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\ClaimPreviewRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\StoreExchangeRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\StoreRefundRequestRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\StoreReturnRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UpdateClaimRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimImageResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimLinkResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimPaymentResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimReasonResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderOptionResource;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
use Modules\Sirsoft\Ecommerce\Support\ClaimProgressSteps;
use Modules\Sirsoft\Ecommerce\Support\ClaimUrl;
use Modules\Sirsoft\Ecommerce\Support\RefundBankMask;

/**
 * 클레임 신청 액션 공용 처리 (회원 · 비회원 컨트롤러 공유)
 *
 * 두 컨트롤러의 차이는 **열람자**(회원 ID / 토큰 검증 비회원 주문)뿐이다 — 각 액션의 본문을 여기에 두고
 * 컨트롤러는 열람자 컨텍스트(`viewer()`)만 정한다. 회원·비회원 경로에서 같은 결함이 한쪽만 고쳐지는
 * 일을 막는다.
 *
 * 예외 → 응답: `OrderClaimException`(도메인) 은 키+치환 파라미터로 422, 즉시취소 분기의
 * `OrderCancellationException` 은 취소 경로와 같은 422, 그 외는 500(원문 미노출).
 */
trait HandlesClaimActions
{
    use MapsClaimErrors;

    /**
     * 열람자 컨텍스트를 반환합니다.
     *
     * @return array{user_id: ?int, guest_order: ?Order} 열람자
     */
    abstract protected function viewer(): array;

    /** 즉시 취소 분기에 필요한 권한 — 종전 [주문 취소] 경로(`user/orders/{id}/cancel`)와 같은 식별자 */
    private const PERMISSION_INSTANT_CANCEL = 'sirsoft-ecommerce.user-orders.cancel';

    /**
     * 클레임 서비스를 반환합니다.
     *
     * @return UserClaimService 클레임 서비스
     */
    abstract protected function claims(): UserClaimService;

    /**
     * 신청 가능 상품·유형·사유·설정을 돌려줍니다.
     *
     * @param  Order  $order  소유권이 확인된 주문
     * @return JsonResponse 응답
     */
    protected function respondClaimableOptions(Order $order): JsonResponse
    {
        try {
            $payload = $this->claims()->claimableOptions($order);
            $currency = $order->currency_snapshot['base_currency'] ?? $order->currency ?? null;
            $snapshot = $order->currency_snapshot;

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.claims.claimable_retrieved', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                // 이 응답의 onSuccess 가 상품 줄을 세우면서 보조 통화 목록에서 기준 통화를 걸러낸다.
                // 그 기준을 다른 데이터소스(`order`)에서 읽으면 두 응답의 도착 순서에 따라 결과가
                // 갈리므로, 반응 대상인 이 응답이 스스로 들고 온다(S17 트랙 A F-5). 판정은 서비스가
                // 소유한다 — 여기서 다시 계산하면 주문 리소스와 규칙이 갈릴 수 있다.
                'base_currency' => $payload['base_currency'],
                'resolved_type' => $payload['resolved_type'],
                'options' => array_map(fn (array $row) => [
                    // 점유 수량은 서비스가 이미 구했다 — 주입하지 않으면 옵션 리소스가 행마다 다시 구한다(3쿼리).
                    'option' => (new OrderOptionResource($row['option']))
                        ->withOrderCurrency($currency)
                        ->withCurrencySnapshot($snapshot)
                        ->withClaimLockedQuantity((int) $row['locked_quantity'])
                        ->toArray(request()),
                    'available_quantity' => $row['available_quantity'],
                    'locked_quantity' => $row['locked_quantity'],
                    'claimable_types' => $row['claimable_types'],
                    'refund_branch' => $row['refund_branch'],
                ], $payload['options']),
                'reasons' => array_map(
                    fn ($reasons) => ClaimReasonResource::collection($reasons)->toArray(request()),
                    $payload['reasons'],
                ),
                'settings' => $payload['settings'],
                // 신청 버튼을 잠그는 키 — 즉시 취소 분기(`refund_branch = auto`)는 주문 취소 권한, 심사 분기·반품·교환은
                // 신청 권한이다. 서버가 같은 기준으로 403 을 낸다(`respondStoreRefund()`). 비회원은 guest 역할로 판정한다.
                'abilities' => [
                    'can_cancel' => PermissionHelper::check(self::PERMISSION_INSTANT_CANCEL),
                    'can_request' => PermissionHelper::check('sirsoft-ecommerce.user-claims.request'),
                ],
                // 접수 뒤 자동으로 끝나는지(P16·P17) — 분기와 다른 축이라 따로 싣는다.
                'refund_auto_approved' => $payload['refund_auto_approved'],
                // 등록 계좌 프리필은 **가린 모양**으로 싣는다 — 이 응답은 회원 본인뿐 아니라 비회원 조회
                // 토큰을 가진 누구에게나 나가므로, 원문을 실으면 토큰 하나로 계좌번호·예금주 실명이
                // 새어 나간다. 구매자가 손대지 않고 제출하면 가린 값이 그대로 돌아오고, 서버가 그 값이
                // 등록 계좌를 가린 모양과 같을 때만 원문으로 되돌린다(`RefundBankMask::restoreRegistered()`).
                'refund_account' => $this->maskedRefundAccount($payload['refund_account']),
                'pending_images' => ClaimImageResource::collection($payload['pending_images'])->toArray(request()),
            ]);
        } catch (Exception $e) {
            return $this->claimServerError('claimable-options', $e);
        }
    }

    /**
     * 신청 미리보기를 계산합니다.
     *
     * @param  ClaimPreviewRequest  $request  검증된 요청
     * @return JsonResponse 응답
     */
    protected function respondPreview(ClaimPreviewRequest $request): JsonResponse
    {
        try {
            $order = $request->getOrder();
            $excludeId = null;
            $claimNumber = (string) ($request->validated('claim_number') ?? '');

            if ($claimNumber !== '') {
                $resolved = $this->claims()->resolveByNumber($claimNumber);

                if ($resolved !== null && $resolved['type'] === $request->claimType()
                    && $this->claims()->isOwnedBy($resolved['claim'], $this->viewer()['user_id'], $this->viewer()['guest_order'])) {
                    $excludeId = (int) $resolved['claim']->id;
                }
            }

            $preview = $this->claims()->preview($order, $request->claimType(), $request->validated(), $excludeId);

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.claims.preview_success', $this->normalizePreview($preview));
        } catch (OrderClaimException $e) {
            return $this->claimDomainError($e);
        } catch (Exception $e) {
            return $this->claimServerError('preview', $e);
        }
    }

    /**
     * 교환을 신청합니다.
     *
     * @param  StoreExchangeRequest  $request  검증된 요청
     * @return JsonResponse 응답
     */
    protected function respondStoreExchange(StoreExchangeRequest $request): JsonResponse
    {
        try {
            $result = $this->claims()->requestExchange($request->getOrder(), $request->toServicePayload(), $request->imageIds(), $request->paymentMethod());

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.claims.requested', $this->requestedPayload($result), 201);
        } catch (OrderClaimException $e) {
            return $this->claimDomainError($e);
        } catch (Exception $e) {
            return $this->claimServerError('exchange-request', $e);
        }
    }

    /**
     * 반품을 신청합니다.
     *
     * @param  StoreReturnRequest  $request  검증된 요청
     * @return JsonResponse 응답
     */
    protected function respondStoreReturn(StoreReturnRequest $request): JsonResponse
    {
        try {
            $result = $this->claims()->requestReturn($request->getOrder(), $request->toServicePayload(), $request->imageIds(), $request->paymentMethod());

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.claims.requested', $this->requestedPayload($result), 201);
        } catch (OrderClaimException $e) {
            return $this->claimDomainError($e);
        } catch (Exception $e) {
            return $this->claimServerError('return-request', $e);
        }
    }

    /**
     * 취소·환불을 신청합니다. (D2 자동 분기)
     *
     * 접수 결과는 **언제나 환불신청서**다(D2) — `branch` 는 그 신청서가 어디까지 자동으로
     * 갔는지를 말할 뿐이다(`auto` = 승인·완료까지 자동 / `request` = 접수에서 정지).
     * 화면은 이 값을 읽어 안내 문구와 이동 주소를 정한다(F8 — 예전에는 클라이언트가
     * 주문 전체 기준 `resolved_type` 으로 추측해, 혼재 주문에서 접수한 탭과 이동한 탭이
     * 갈렸다).
     *
     * 예외는 **환불할 돈이 없는 주문**(미결제·0원)이다 — 환불신청서 없이 취소신청서만
     * 생기므로 `claim` 에 취소신청서를 싣고 `claim_type` 으로 구분한다.
     *
     * @param  StoreRefundRequestRequest  $request  검증된 요청
     * @return JsonResponse 응답
     */
    protected function respondStoreRefund(StoreRefundRequestRequest $request): JsonResponse
    {
        try {
            $payload = $request->toServicePayload();

            // 즉시 취소 분기는 종전 [주문 취소]와 같은 일이다 — 라우트의 신청 권한에 더해 주문 취소 권한도 본다.
            // 이 검사가 없으면 주문 취소 권한을 거둔 회원·비회원이 신청 화면으로 배송 전 주문을 그대로 취소한다.
            if ($this->claims()->refundBranchFor($request->getOrder(), $payload['items']) === OrderRefundRequestService::BRANCH_AUTO
                && ! PermissionHelper::check(self::PERMISSION_INSTANT_CANCEL)) {
                return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.claims.instant_cancel_forbidden', 403);
            }

            $result = $this->claims()->requestRefund($request->getOrder(), $payload, $request->imageIds());

            if ($result['refund'] === null) {
                $cancel = $result['cancel']->fresh(['cancelOptions.orderOption', 'refund', 'order']);

                return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.claims.cancelled_instantly', [
                    'branch' => $result['branch'],
                    'claim' => $this->claimResource($cancel)->toArray(request()),
                ], 201);
            }

            $refund = $result['refund']->fresh(['refundOptions.orderOption', 'images', 'order']);

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.claims.requested', [
                'branch' => $result['branch'],
                'claim' => $this->claimResource($refund)->toArray(request()),
            ], 201);
        } catch (OrderClaimException $e) {
            return $this->claimDomainError($e);
        } catch (OrderCancellationException $e) {
            // 자동 전이 중 취소·환불 거절 — D15 에 따라 접수까지 전체 롤백된 상태다.
            // 사유 키가 있으면 그 사유를 안내하고(P13), 없으면(PG 거절) 원문을 싣지 않는다 —
            // 이 면은 회원·비회원 공개 면이라 PG 가 돌려준 원문(가맹점 설정·내부 코드)을 내보내지 않는다.
            return $this->publicCancellationError($e, 'refund-request');
        } catch (Exception $e) {
            return $this->claimServerError('refund-request', $e);
        }
    }

    /**
     * 신청서 상세를 돌려줍니다.
     *
     * @param  string  $claimNumber  클레임 번호
     * @return JsonResponse 응답
     */
    protected function respondShow(string $claimNumber): JsonResponse
    {
        try {
            $resolved = $this->claims()->findOwned($claimNumber, $this->viewer()['user_id'], $this->viewer()['guest_order']);

            // 번호 해석이 싣는 관계는 소유권 판정에 필요한 최소한이다(상품 행·첨부·주문).
            // 상세 화면은 그 위에 상품의 원 주문옵션(상품명·옵션명·썸네일)과 회수·재발송
            // 배송사(배송사명·추적 URL)를 그린다 — 다시 싣지 않으면 그 칸들이 오류 없이
            // 전부 비고, 응답은 200 이라 어디에도 어긋난 흔적이 남지 않는다. 수정 응답이
            // 이미 같은 이유로 `reloaded()` 를 거친다.
            $claim = $this->reloaded($resolved['claim']);

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.claims.retrieved', [
                'claim' => $this->detailResource($claim)->toArray(request()),
                // 진행단계 — 관리자 상세와 **같은 배열**을 같은 자리에서 만든다. 화면이 순서를
                // 다시 적으면 그 사본이 어휘의 두 번째 출처가 되어 회원 화면만 낡은 채 남는다.
                'progress_steps' => ClaimProgressSteps::for($claim),
                // 선교환의 「회수」 줄 — 관리자 상세와 같은 배열(일반 교환·반품은 null).
                'collection_lane' => ClaimProgressSteps::collectionLane($claim),
                // 연결 신청서(보완 5 · Q8) — 주소는 서버가 회원/비회원을 갈라 준다. partial 이 회원 여부를 판단하지 않는다.
                'linked_claims' => ClaimLinkResource::envelope(
                    $this->claims()->linkedClaimsFor($claim),
                    fn (string $number) => $this->viewer()['guest_order'] !== null
                        ? ClaimUrl::guestDetail($number)
                        : ClaimUrl::memberDetail($number),
                    $claim->order,
                ),
            ]);
        } catch (OrderClaimException $e) {
            return $this->claimNotFound();
        } catch (Exception $e) {
            return $this->claimServerError('show', $e);
        }
    }

    /**
     * 승인 전 신청 내용을 수정합니다. (D13/D14)
     *
     * @param  UpdateClaimRequest  $request  검증된 요청
     * @param  string  $claimNumber  클레임 번호
     * @return JsonResponse 응답
     */
    protected function respondUpdate(UpdateClaimRequest $request, string $claimNumber): JsonResponse
    {
        try {
            $resolved = $this->claims()->findOwned($claimNumber, $this->viewer()['user_id'], $this->viewer()['guest_order']);
        } catch (OrderClaimException $e) {
            return $this->claimNotFound();
        }

        try {
            if ($resolved['type'] === ClaimTypeEnum::CANCEL) {
                throw OrderClaimException::notEditable();
            }

            $result = $this->claims()->update(
                $resolved['type'],
                $resolved['claim'],
                $request->toServicePayload(),
                $request->addImageIds(),
                $request->removeImageIds(),
                $this->viewer()['user_id'],
            );

            $payload = ['claim' => $this->detailResource($this->reloaded($result['claim']))->toArray(request())];

            if ($result['payment'] !== null) {
                $payload['payment'] = (new ClaimPaymentResource($result['payment']))->toArray(request());
            }

            if ($result['payment_entry'] !== null) {
                $payload += $result['payment_entry'];
            }

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.claims.updated', $payload);
        } catch (OrderClaimException $e) {
            return $this->claimDomainError($e);
        } catch (Exception $e) {
            return $this->claimServerError('update', $e);
        }
    }

    /**
     * 신청을 철회합니다.
     *
     * @param  string  $claimNumber  클레임 번호
     * @return JsonResponse 응답
     */
    protected function respondWithdraw(string $claimNumber): JsonResponse
    {
        try {
            $resolved = $this->claims()->findOwned($claimNumber, $this->viewer()['user_id'], $this->viewer()['guest_order']);
        } catch (OrderClaimException $e) {
            return $this->claimNotFound();
        }

        try {
            $withdrawn = $this->claims()->withdraw($resolved['type'], $resolved['claim']);

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.claims.withdrawn', [
                'claim' => $this->detailResource($this->reloaded($withdrawn))->toArray(request()),
            ]);
        } catch (OrderClaimException $e) {
            return $this->claimDomainError($e);
        } catch (Exception $e) {
            return $this->claimServerError('withdraw', $e);
        }
    }

    /**
     * 결제 대기 건의 결제를 시작합니다.
     *
     * @param  string  $paymentNumber  결제번호
     * @param  string|null  $paymentMethod  결제 수단
     * @return JsonResponse 응답
     */
    protected function respondPay(string $paymentNumber, ?string $paymentMethod): JsonResponse
    {
        try {
            $payment = $this->claims()->findOwnedPayment($paymentNumber, $this->viewer()['user_id'], $this->viewer()['guest_order']);
        } catch (OrderClaimException $e) {
            // 결제 부재는 신청서 부재와 다른 안내다 — 부재·소유권 위반을 구분하지 않는 404 정책은 그대로 두고
            // 문구만 결제 전용으로 지정한다(`findOwnedPayment()` 가 던지는 키와 같다).
            return $this->claimNotFound('exceptions.claim_payment_not_found');
        }

        try {
            $result = $this->claims()->pay($payment, $paymentMethod, $this->viewer()['user_id']);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                $result['payment']->isPaid() ? 'messages.claims.payment_completed' : 'messages.claims.payment_started',
                [
                    'payment' => (new ClaimPaymentResource($result['payment']))->toArray(request()),
                    'requires_pg_payment' => $result['requires_pg_payment'],
                    'pg_provider' => $result['pg_provider'],
                    'pg_payment_handler' => $result['pg_payment_handler'],
                    'pg_payment_data' => $result['pg_payment_data'],
                ]
            );
        } catch (OrderClaimException $e) {
            return $this->claimDomainError($e);
        } catch (Exception $e) {
            return $this->claimServerError('payment-pay', $e);
        }
    }

    /**
     * PG 콜백으로 결제를 확정합니다.
     *
     * @param  string  $paymentNumber  결제번호
     * @param  array  $callback  PG 콜백 파라미터
     * @return JsonResponse 응답
     */
    protected function respondApprove(string $paymentNumber, array $callback): JsonResponse
    {
        try {
            $payment = $this->claims()->findOwnedPayment($paymentNumber, $this->viewer()['user_id'], $this->viewer()['guest_order']);
        } catch (OrderClaimException $e) {
            // 결제 시작 경로와 같은 문구를 쓴다 — 두 경로가 갈리면 같은 부재가 화면마다 다르게 안내된다.
            return $this->claimNotFound('exceptions.claim_payment_not_found');
        }

        try {
            $paid = $this->claims()->approve($payment, $callback);

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.claims.payment_completed', [
                'payment' => (new ClaimPaymentResource($paid))->toArray(request()),
            ]);
        } catch (OrderClaimException $e) {
            return $this->claimDomainError($e);
        } catch (Exception $e) {
            return $this->claimServerError('payment-approve', $e);
        }
    }

    // ───────────────────────────────────────────────
    // 응답 조립
    // ───────────────────────────────────────────────

    /**
     * 신청 결과(신청서 + 결제 진입)를 응답 페이로드로 만듭니다.
     *
     * 결제 진입 4키(`requires_pg_payment` / `pg_provider` / `pg_payment_handler` / `pg_payment_data`)는 주문 생성 응답과 같은
     * 위치(최상위)에 둔다 — 템플릿이 같은 dispatch 로 결제창을 연다.
     *
     * @param  array{claim: OrderExchange|OrderReturn, payment: ?ClaimPayment, payment_entry: ?array}  $result  신청 결과
     * @return array 응답 페이로드
     */
    protected function requestedPayload(array $result): array
    {
        $payload = [
            'claim' => $this->detailResource($this->reloaded($result['claim']))->toArray(request()),
            'payment' => $result['payment'] ? (new ClaimPaymentResource($result['payment']))->toArray(request()) : null,
            'requires_pg_payment' => false,
        ];

        if ($result['payment_entry'] !== null) {
            $payload = array_merge($payload, $result['payment_entry']);
        }

        return $payload;
    }

    /**
     * 환불계좌 요건의 등록 계좌 프리필을 가린 모양으로 바꿉니다.
     *
     * @param  array{required: bool, prefill: ?array, banks: array}  $requirement  서비스가 만든 환불계좌 요건
     * @return array 프리필이 가려진 환불계좌 요건
     */
    protected function maskedRefundAccount(array $requirement): array
    {
        $requirement['prefill'] = RefundBankMask::prefill($requirement['prefill'] ?? null);

        return $requirement;
    }

    /**
     * 미리보기 결과의 DTO 를 배열로 풉니다.
     *
     * @param  array  $preview  서비스 미리보기 결과
     * @return array 응답용 배열
     */
    protected function normalizePreview(array $preview): array
    {
        foreach ($preview as $key => $value) {
            if ($value instanceof ClaimShippingFee) {
                $preview[$key] = $value->toArray();
            } elseif ($value instanceof AdjustmentResult) {
                $preview[$key] = $value->toPreviewArray();
            } elseif ($value instanceof \BackedEnum) {
                $preview[$key] = $value->value;
            }
        }

        return $preview;
    }

    /**
     * 상세 리소스를 만듭니다. (교환·반품은 결제 블록 포함)
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return ClaimResource 리소스
     */
    protected function detailResource(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): ClaimResource
    {
        $resource = $this->claimResource($claim);

        if ($claim instanceof OrderExchange || $claim instanceof OrderReturn) {
            $resource->withPaymentBlock($this->claims()->paymentBlock($claim));
        }

        return $resource;
    }

    /**
     * 신청서 리소스를 만듭니다. (통화는 주문 스냅샷 전파)
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return ClaimResource 리소스
     */
    protected function claimResource(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): ClaimResource
    {
        $claim->loadMissing('order');
        $order = $claim->order;

        return (new ClaimResource($claim))
            ->withOrderCurrency($order?->currency_snapshot['base_currency'] ?? $order?->currency)
            ->withCurrencySnapshot($order?->currency_snapshot);
    }

    /**
     * 상세 응답에 필요한 관계를 다시 싣습니다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return OrderExchange|OrderReturn|OrderRefund|OrderCancel 관계가 로드된 신청서
     */
    protected function reloaded(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): OrderExchange|OrderReturn|OrderRefund|OrderCancel
    {
        $relations = match (true) {
            $claim instanceof OrderExchange => ['exchangeOptions.orderOption', 'exchangeOptions.collectionCarrier', 'exchangeOptions.redeliveryCarrier', 'images', 'order'],
            $claim instanceof OrderReturn => ['returnOptions.orderOption', 'returnOptions.collectionCarrier', 'images', 'order'],
            $claim instanceof OrderRefund => ['refundOptions.orderOption', 'images', 'order'],
            default => ['cancelOptions.orderOption', 'refund', 'order'],
        };

        return $claim->fresh($relations) ?? $claim;
    }
}
