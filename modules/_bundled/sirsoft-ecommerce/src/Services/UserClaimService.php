<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\DTO\CancellationResult;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimReasonRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCancelRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnRepositoryInterface;

/**
 * 유저(회원·비회원) 클레임 신청 오케스트레이션 서비스 (S5)
 *
 * 회원 컨트롤러와 비회원 컨트롤러가 **같은 메서드**를 부른다 — 둘의 차이는 주문을 해석하는 방법
 * (회원 소유권 / HMAC 토큰)뿐이고 그 차이는 FormRequest 가 흡수한다. 이 서비스는 유형별 도메인 서비스
 * (교환·반품·취소/환불 분기)와 결제·첨부 서비스를 한 흐름으로 엮는다:
 *
 *  - 신청: 도메인 서비스 접수 → 미귀속 첨부 귀속 → (신청 시점 결제면) 결제 대기 건 생성·결제 진입
 *  - 수정(D13/D14): 도메인 서비스 수정 → 첨부 추가·삭제 → 청구액 차액 정산(추가 청구 / 환급)
 *  - 철회: 도메인 서비스 철회 → 기결제 건 환급
 *
 * 클레임 번호 해석(§5-1-5)은 유형별 Repository 의 `findByNumber` 를 차례로 묻는다 — 접두사(EX/RT/RF/CN)는
 * 운영자가 채번 설정에서 바꿀 수 있어 리터럴로 판정하지 않는다.
 */
class UserClaimService
{
    /**
     * @param  OrderExchangeService  $exchangeService  교환 서비스
     * @param  OrderReturnService  $returnService  반품 서비스
     * @param  OrderRefundRequestService  $refundRequestService  취소·환불 분기 서비스
     * @param  ClaimPaymentService  $paymentService  추가결제 서비스
     * @param  ClaimAttachmentService  $attachmentService  첨부 서비스
     * @param  ClaimQuantityGuard  $claimQuantityGuard  수량 잠금 가드
     * @param  EcommerceSettingsService  $settingsService  설정 서비스
     * @param  OrderExchangeRepositoryInterface  $exchangeRepository  교환 Repository
     * @param  OrderReturnRepositoryInterface  $returnRepository  반품 Repository
     * @param  OrderRefundRepositoryInterface  $refundRepository  환불신청서 Repository
     * @param  OrderCancelRepositoryInterface  $cancelRepository  취소 Repository
     * @param  ClaimReasonRepositoryInterface  $claimReasonRepository  사유 카탈로그 Repository
     */
    public function __construct(
        protected OrderExchangeService $exchangeService,
        protected OrderReturnService $returnService,
        protected OrderRefundRequestService $refundRequestService,
        protected ClaimPaymentService $paymentService,
        protected ClaimAttachmentService $attachmentService,
        protected ClaimQuantityGuard $claimQuantityGuard,
        protected EcommerceSettingsService $settingsService,
        protected OrderExchangeRepositoryInterface $exchangeRepository,
        protected OrderReturnRepositoryInterface $returnRepository,
        protected OrderRefundRepositoryInterface $refundRepository,
        protected OrderCancelRepositoryInterface $cancelRepository,
        protected ClaimReasonRepositoryInterface $claimReasonRepository,
    ) {}

    // ───────────────────────────────────────────────
    // 신청 가능 옵션 / 설정
    // ───────────────────────────────────────────────

    /**
     * 주문의 클레임 가능 상품과 유형·사유·설정을 돌려줍니다. (신청 화면의 `claimable` 컨텍스트)
     *
     * @param  Order  $order  주문
     * @return array 신청 가능 옵션 + 유형별 사유 + 설정 + 미귀속 첨부
     */
    public function claimableOptions(Order $order): array
    {
        $order->loadMissing('options');

        $exchangeStatuses = $this->statusValues('claim.exchange_requestable_statuses', [OrderStatusEnum::DELIVERED->value, OrderStatusEnum::CONFIRMED->value]);
        $returnStatuses = $this->statusValues('claim.return_requestable_statuses', [OrderStatusEnum::DELIVERED->value, OrderStatusEnum::CONFIRMED->value]);

        $options = [];
        $branches = [];

        foreach ($order->options as $option) {
            $statusValue = $option->option_status instanceof OrderStatusEnum ? $option->option_status->value : (string) $option->option_status;

            if ($statusValue === OrderStatusEnum::CANCELLED->value) {
                continue;
            }

            $available = $this->claimQuantityGuard->availableQuantity($option);
            $branch = $this->refundRequestService->branchForStatus($option->option_status);
            $types = [];

            if ($available > 0) {
                if (in_array($statusValue, $exchangeStatuses, true)) {
                    $types[] = ClaimTypeEnum::EXCHANGE->value;
                }

                if (in_array($statusValue, $returnStatuses, true)) {
                    $types[] = ClaimTypeEnum::RETURN->value;
                }

                if ($branch !== null) {
                    $types[] = ClaimTypeEnum::REFUND->value;
                    $branches[$branch] = true;
                }
            }

            $options[] = [
                'option' => $option,
                'available_quantity' => $available,
                'locked_quantity' => max(0, (int) $option->quantity - $available),
                'claimable_types' => $types,
                'refund_branch' => $branch,
            ];
        }

        return [
            'options' => $options,
            'resolved_type' => count($branches) === 1 ? array_key_first($branches) : (count($branches) > 1 ? 'mixed' : null),
            'reasons' => [
                ClaimTypeEnum::EXCHANGE->value => $this->claimReasonRepository->getUserSelectableReasons(ClaimTypeEnum::EXCHANGE->value),
                ClaimTypeEnum::RETURN->value => $this->claimReasonRepository->getUserSelectableReasons(ClaimTypeEnum::RETURN->value),
                ClaimTypeEnum::REFUND->value => $this->claimReasonRepository->getUserSelectableReasons(ClaimTypeEnum::REFUND->value),
            ],
            'settings' => $this->publicSettings(),
            'pending_images' => $this->attachmentService->listPendingFor($order),
        ];
    }

    /**
     * 신청 화면이 읽는 공개 설정을 돌려줍니다. (`claim_settings` 컨텍스트)
     *
     * @return array 공개 설정
     */
    public function publicSettings(): array
    {
        return [
            'allowed_charge_methods' => $this->statusValues('claim.allowed_charge_methods', ClaimChargeMethodEnum::values()),
            'charge_payment_methods' => $this->statusValues('claim.charge_payment_methods', [PaymentMethodEnum::CARD->value, PaymentMethodEnum::POINT->value]),
            'max_images' => $this->attachmentService->maxImages(),
            'image_max_size_kb' => $this->attachmentService->maxSizeKb(),
            'request_period_days' => (int) $this->settingsService->getSetting('claim.request_period_days', 7),
            'exchange_requestable_statuses' => $this->statusValues('claim.exchange_requestable_statuses', [OrderStatusEnum::DELIVERED->value, OrderStatusEnum::CONFIRMED->value]),
            'return_requestable_statuses' => $this->statusValues('claim.return_requestable_statuses', [OrderStatusEnum::DELIVERED->value, OrderStatusEnum::CONFIRMED->value]),
            'instant_cancel_statuses' => $this->refundRequestService->instantCancelStatusValues(),
            'refund_request_statuses' => $this->refundRequestService->refundRequestStatusValues(),
        ];
    }

    // ───────────────────────────────────────────────
    // 미리보기
    // ───────────────────────────────────────────────

    /**
     * 유형별 신청 미리보기를 계산합니다. (금액 계산의 SSoT — 화면은 표시만 한다)
     *
     * @param  Order  $order  주문
     * @param  ClaimTypeEnum  $type  클레임 유형 (exchange / return / refund)
     * @param  array  $input  미리보기 입력 (items / reason_code / charge_method / refund_priority / redelivery_address)
     * @param  int|null  $excludeClaimId  수정 화면이면 자기 신청서 ID (수량 잠금 집계 제외)
     * @return array 미리보기 결과 (유형별 형태 + 공통 `type`)
     *
     * @throws OrderClaimException 신청 자격·사유 위반
     */
    public function preview(Order $order, ClaimTypeEnum $type, array $input, ?int $excludeClaimId = null): array
    {
        $items = $input['items'] ?? [];
        $refundPriority = ($input['refund_priority'] ?? null) instanceof RefundPriorityEnum
            ? $input['refund_priority']
            : (isset($input['refund_priority']) ? RefundPriorityEnum::from((string) $input['refund_priority']) : RefundPriorityEnum::PG_FIRST);
        $chargeMethod = isset($input['charge_method']) && $input['charge_method'] !== '' && $input['charge_method'] !== null
            ? ClaimChargeMethodEnum::from((string) $input['charge_method'])
            : null;

        return match ($type) {
            ClaimTypeEnum::EXCHANGE => ['type' => $type->value] + $this->exchangeService->previewExchange(
                $order,
                $items,
                (string) ($input['reason_code'] ?? ''),
                $chargeMethod,
                $refundPriority,
                $input['redelivery_address'] ?? null,
                $excludeClaimId,
            ),
            ClaimTypeEnum::RETURN => ['type' => $type->value] + $this->returnService->previewReturn(
                $order,
                $items,
                (string) ($input['reason_code'] ?? ''),
                $chargeMethod,
                $refundPriority,
                $excludeClaimId,
            ),
            default => ['type' => $type->value] + $this->refundRequestService->previewRefund($order, $items, $refundPriority, $excludeClaimId),
        };
    }

    // ───────────────────────────────────────────────
    // 신청
    // ───────────────────────────────────────────────

    /**
     * 교환을 신청합니다. (접수 → 첨부 귀속 → 신청 시점 결제 진입)
     *
     * @param  Order  $order  주문
     * @param  array  $payload  서비스 페이로드 (`StoreExchangeRequest::toServicePayload()`)
     * @param  array<int, int>  $imageIds  귀속할 미귀속 첨부 ID
     * @param  string|null  $paymentMethod  신청 시점 결제 수단
     * @return array{claim: OrderExchange, payment: ?ClaimPayment, payment_entry: ?array} 신청 결과
     *
     * @throws OrderClaimException 신청 자격·사유·청구 방식·첨부 위반
     */
    public function requestExchange(Order $order, array $payload, array $imageIds = [], ?string $paymentMethod = null): array
    {
        // 첨부 자격은 신청서를 만들기 전에 본다 — 접수는 자체 트랜잭션으로 이미 커밋되므로,
        // 귀속 단계에서 거절하면 신청서만 남고 그 신청서가 수량 잠금을 잡아 재신청까지 막힌다.
        // 결제 수단 자격(비회원의 마일리지)도 같은 이유로 접수 앞에 둔다 — 결제 진입에서 거절하면
        // 신청서와 쓸 수 없는 수단이 박힌 결제행이 둘 다 남는다.
        $this->attachmentService->resolveAttachable($order, $imageIds, $payload['requested_by'] ?? null);
        $this->paymentService->assertRequestPaymentMethodAvailable($paymentMethod, $payload['requested_by'] ?? null);

        $exchange = $this->exchangeService->requestExchange($order, $payload);
        $this->attachmentService->attachPending($order, $imageIds, ClaimTypeEnum::EXCHANGE, $exchange->id, $payload['requested_by'] ?? null);

        return $this->enterPayment($exchange, $paymentMethod, $payload['requested_by'] ?? null);
    }

    /**
     * 반품을 신청합니다. (접수 → 첨부 귀속 → 신청 시점 결제 진입)
     *
     * @param  Order  $order  주문
     * @param  array  $payload  서비스 페이로드
     * @param  array<int, int>  $imageIds  귀속할 미귀속 첨부 ID
     * @param  string|null  $paymentMethod  신청 시점 결제 수단
     * @return array{claim: OrderReturn, payment: ?ClaimPayment, payment_entry: ?array} 신청 결과
     *
     * @throws OrderClaimException 신청 자격·사유·청구 방식·첨부 위반
     */
    public function requestReturn(Order $order, array $payload, array $imageIds = [], ?string $paymentMethod = null): array
    {
        // 교환 경로와 같은 이유로 첨부 자격과 결제 수단 자격을 선행 검사한다 (아래 `requestExchange()` 주석 참조).
        $this->attachmentService->resolveAttachable($order, $imageIds, $payload['requested_by'] ?? null);
        $this->paymentService->assertRequestPaymentMethodAvailable($paymentMethod, $payload['requested_by'] ?? null);

        $return = $this->returnService->requestReturn($order, $payload);
        $this->attachmentService->attachPending($order, $imageIds, ClaimTypeEnum::RETURN, $return->id, $payload['requested_by'] ?? null);

        return $this->enterPayment($return, $paymentMethod, $payload['requested_by'] ?? null);
    }

    /**
     * 취소·환불을 신청합니다. (D2 자동 분기 — 즉시취소 또는 환불신청서)
     *
     * 즉시취소 분기는 취소 파이프라인이 끝나면 첨부할 신청서가 없으므로 미귀속 첨부를 정리한다.
     *
     * @param  Order  $order  주문
     * @param  array  $payload  서비스 페이로드
     * @param  array<int, int>  $imageIds  귀속할 미귀속 첨부 ID
     * @return array{branch: string, cancel: ?CancellationResult, refund: ?OrderRefund} 분기 결과
     *
     * @throws OrderClaimException 신청 자격·사유·첨부 위반
     * @throws OrderCancellationException 즉시취소 파이프라인 거절
     */
    public function requestRefund(Order $order, array $payload, array $imageIds = []): array
    {
        // 교환·반품과 같은 이유로 선행 검사한다. 즉시취소 분기는 신청서가 생기지 않지만, 그 분기도
        // 취소 파이프라인을 되돌릴 수는 없으므로 첨부가 잘못된 요청은 파이프라인 전에 걸러야 한다.
        $this->attachmentService->resolveAttachable($order, $imageIds, $payload['requested_by'] ?? null);

        $result = $this->refundRequestService->request($order, $payload);

        if ($result['refund'] instanceof OrderRefund) {
            $this->attachmentService->attachPending($order, $imageIds, ClaimTypeEnum::REFUND, $result['refund']->id, $payload['requested_by'] ?? null);
        } else {
            foreach ($this->attachmentService->listPendingFor($order) as $image) {
                if (in_array((int) $image->id, array_map('intval', $imageIds), true)) {
                    $this->attachmentService->delete($image);
                }
            }
        }

        return $result;
    }

    // ───────────────────────────────────────────────
    // 조회
    // ───────────────────────────────────────────────

    /**
     * 신청서 묶음에 실린 사유 코드의 라벨 맵을 만듭니다. (목록 표기용 묶음 조회)
     *
     * `ClaimResource` 는 주입이 없으면 행마다 모델 게터로 `ClaimReason` 을 되읽는다. 목록은 그 횟수가
     * 행 수만큼 늘어나므로 유형별로 코드를 모아 `whereIn` 한 번씩만 친다. 비활성 사유도 포함해야
     * 이미 접수된 신청서의 라벨이 유지된다(단건 게터와 같은 모집단).
     *
     * @param  iterable<string, \Illuminate\Support\Collection>  $byType  유형 값 => 신청서 목록
     * @return array<string, string> `"{유형}:{코드}" => 라벨` 맵
     */
    public function reasonLabelsFor(iterable $byType): array
    {
        $codesByType = [];

        foreach ($byType as $claims) {
            foreach ($claims as $claim) {
                $isCancel = $claim instanceof OrderCancel;
                $type = $isCancel ? ClaimTypeEnum::REFUND->value : $claim->claimType()->value;
                $code = (string) ($isCancel ? ($claim->cancel_reason_type ?? '') : ($claim->reason_code ?? ''));

                if ($code !== '') {
                    $codesByType[$type][] = $code;
                }
            }
        }

        $labels = [];

        foreach ($codesByType as $type => $codes) {
            foreach ($this->claimReasonRepository->findByCodes($type, $codes) as $reason) {
                $labels[$type.':'.$reason->code] = $reason->getLocalizedName();
            }
        }

        return $labels;
    }

    /**
     * 클레임 번호로 신청서를 해석합니다. (교환 → 반품 → 환불 → 취소 순)
     *
     * @param  string  $claimNumber  클레임 번호
     * @return array{type: ClaimTypeEnum, claim: OrderExchange|OrderReturn|OrderRefund|OrderCancel}|null 해석 결과
     */
    public function resolveByNumber(string $claimNumber): ?array
    {
        $claimNumber = trim($claimNumber);

        if ($claimNumber === '') {
            return null;
        }

        if ($exchange = $this->exchangeRepository->findByNumber($claimNumber, ['exchangeOptions', 'images', 'order'])) {
            return ['type' => ClaimTypeEnum::EXCHANGE, 'claim' => $exchange];
        }

        if ($return = $this->returnRepository->findByNumber($claimNumber, ['returnOptions', 'images', 'order'])) {
            return ['type' => ClaimTypeEnum::RETURN, 'claim' => $return];
        }

        if ($refund = $this->refundRepository->findByNumber($claimNumber, ['refundOptions', 'images', 'order'])) {
            return ['type' => ClaimTypeEnum::REFUND, 'claim' => $refund];
        }

        if ($cancel = $this->cancelRepository->findByNumber($claimNumber, ['cancelOptions', 'refund', 'order'])) {
            return ['type' => ClaimTypeEnum::CANCEL, 'claim' => $cancel];
        }

        return null;
    }

    /**
     * 클레임 번호로 열람자의 신청서를 찾습니다. (소유권 위반은 부재와 같은 예외)
     *
     * @param  string  $claimNumber  클레임 번호
     * @param  int|null  $userId  회원 ID (회원 경로)
     * @param  Order|null  $guestOrder  토큰으로 검증된 비회원 주문 (비회원 경로)
     * @return array{type: ClaimTypeEnum, claim: OrderExchange|OrderReturn|OrderRefund|OrderCancel} 해석 결과
     *
     * @throws OrderClaimException 부재·소유권 위반
     */
    public function findOwned(string $claimNumber, ?int $userId, ?Order $guestOrder = null): array
    {
        $resolved = $this->resolveByNumber($claimNumber);

        if ($resolved === null || ! $this->isOwnedBy($resolved['claim'], $userId, $guestOrder)) {
            throw OrderClaimException::claimNotFound();
        }

        return $resolved;
    }

    /**
     * 신청서가 열람자의 것인지 판정합니다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @param  int|null  $userId  회원 ID
     * @param  Order|null  $guestOrder  비회원 주문
     * @return bool 본인 소유이면 true
     */
    public function isOwnedBy(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim, ?int $userId, ?Order $guestOrder = null): bool
    {
        if ($guestOrder !== null) {
            return (int) $claim->order_id === (int) $guestOrder->id;
        }

        if ($userId === null) {
            return false;
        }

        $claim->loadMissing('order');

        return $claim->order !== null && (int) $claim->order->user_id === (int) $userId;
    }

    /**
     * 회원의 신청 내역을 유형별로 페이지네이션 조회합니다. (서브탭 = 유형)
     *
     * @param  int  $userId  회원 ID
     * @param  ClaimTypeEnum  $type  유형
     * @param  array  $filters  필터 (status / from / to)
     * @param  int  $perPage  페이지당 건수
     * @return LengthAwarePaginator 페이지네이션 결과
     */
    public function listForUser(int $userId, ClaimTypeEnum $type, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return match ($type) {
            ClaimTypeEnum::EXCHANGE => $this->exchangeRepository->paginateForUser($userId, $filters, $perPage),
            ClaimTypeEnum::RETURN => $this->returnRepository->paginateForUser($userId, $filters, $perPage),
            ClaimTypeEnum::REFUND => $this->refundRepository->paginateForUser($userId, $filters, $perPage),
            ClaimTypeEnum::CANCEL => $this->cancelRepository->paginateForUser($userId, $filters, $perPage),
        };
    }

    /**
     * 주문의 클레임 목록을 유형별로 돌려줍니다. (비회원 주문상세 "이 주문의 클레임" 섹션)
     *
     * @param  Order  $order  주문
     * @return array<string, Collection> 유형 값 => 신청서 목록
     */
    public function listForOrder(Order $order): array
    {
        return [
            // `.orderOption` 은 표시 필드의 출처다 — 빼면 `ClaimItemResource` 의 `whenLoaded` 가
            // 정상 동작한 결과로 상품명·옵션명·썸네일이 오류 없이 전부 null 이 된다(회원 목록·상세는 싣는다).
            // 관계 목록은 회원 목록(저장소 `paginateForUser`)과 같아야 한다 — 하나라도 빠지면 그 필드가
            // 오류 없이 전 행 null 이 된다(`order_number`·회수/재발송 배송사명).
            ClaimTypeEnum::EXCHANGE->value => $this->exchangeRepository->listByOrderId($order->id, ['exchangeOptions.orderOption', 'images', 'order.shippingAddress', 'collectionCarrier', 'redeliveryCarrier']),
            ClaimTypeEnum::RETURN->value => $this->returnRepository->listByOrderId($order->id, ['returnOptions.orderOption', 'images', 'order.shippingAddress', 'collectionCarrier']),
            ClaimTypeEnum::REFUND->value => $this->refundRepository->listByOrderId($order->id, ['refundOptions.orderOption', 'images', 'order.shippingAddress'])
                ->filter(fn (OrderRefund $refund) => $refund->refund_source_type !== RefundSourceTypeEnum::CANCEL)
                ->values(),
            ClaimTypeEnum::CANCEL->value => $this->cancelRepository->listByOrderId($order->id, ['cancelOptions.orderOption', 'refund', 'order.shippingAddress']),
        ];
    }

    // ───────────────────────────────────────────────
    // 수정 / 철회
    // ───────────────────────────────────────────────

    /**
     * 승인 전 신청 내용을 수정합니다. (D13/D14 — 사유·상세 사유·첨부·교환 재발송지, 차액 정산)
     *
     * @param  ClaimTypeEnum  $type  유형
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @param  array  $payload  수정 페이로드
     * @param  array<int, int>  $addImageIds  귀속할 미귀속 첨부 ID
     * @param  array<int, int>  $removeImageIds  삭제할 첨부 ID
     * @param  int|null  $userId  수정 주체 회원 ID
     * @return array{claim: OrderExchange|OrderReturn|OrderRefund, payment: ?ClaimPayment, payment_entry: ?array} 수정 결과
     *
     * @throws OrderClaimException 수정 불가·첨부 위반·환급 실패
     */
    public function update(ClaimTypeEnum $type, OrderExchange|OrderReturn|OrderRefund $claim, array $payload, array $addImageIds = [], array $removeImageIds = [], ?int $userId = null): array
    {
        $claim->loadMissing('order');
        $order = $claim->order;

        // 첨부 자격은 수정 본문을 쓰기 **전에** 본다 — 도메인 서비스는 자체 트랜잭션으로 커밋하고
        // 그 뒤의 삭제는 파일까지 지운다. 귀속 단계에서 거절하면 사유·금액 변경은 반영되고 첨부만
        // 영구히 사라진 채 422 가 나가, 사용자는 "수정 실패" 를 보면서 실제로는 바뀐 신청서를 갖는다.
        // 상한은 `attachPending()` 과 같은 등식이어야 하므로 삭제 후 남을 장수를 함께 넘긴다.
        $remainingAttached = $this->attachmentService->listFor($type, $claim->id)
            ->reject(fn ($image) => in_array((int) $image->id, array_map('intval', $removeImageIds), true))
            ->count();

        $this->attachmentService->resolveAttachable($order, $addImageIds, $userId, $remainingAttached);

        $updated = match ($type) {
            ClaimTypeEnum::EXCHANGE => $this->exchangeService->updateRequest($claim, $payload),
            ClaimTypeEnum::RETURN => $this->returnService->updateRequest($claim, $payload),
            default => $this->refundRequestService->updateRequest($claim, $payload),
        };

        foreach ($this->attachmentService->listFor($type, $updated->id) as $image) {
            if (in_array((int) $image->id, array_map('intval', $removeImageIds), true)) {
                $this->attachmentService->delete($image);
            }
        }

        $this->attachmentService->attachPending($order, $addImageIds, $type, $updated->id, $userId);

        $payment = null;
        $entry = null;

        if ($updated instanceof OrderExchange || $updated instanceof OrderReturn) {
            $payment = $this->paymentService->settleChargeDifference($updated);

            if ($payment !== null && $payment->isPending()) {
                $entry = $this->paymentService->buildPaymentPayload($payment);
            }
        }

        return ['claim' => $updated->fresh(), 'payment' => $payment, 'payment_entry' => $entry];
    }

    /**
     * 신청을 철회합니다. (기결제 건 환급 포함)
     *
     * @param  ClaimTypeEnum  $type  유형
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return OrderExchange|OrderReturn|OrderRefund 철회된 신청서
     *
     * @throws OrderClaimException 철회 불가 / 환급 실패
     */
    public function withdraw(ClaimTypeEnum $type, OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): OrderExchange|OrderReturn|OrderRefund
    {
        $withdrawn = match ($type) {
            ClaimTypeEnum::EXCHANGE => $this->exchangeService->withdraw($claim),
            ClaimTypeEnum::RETURN => $this->returnService->withdraw($claim),
            ClaimTypeEnum::REFUND => $this->refundRequestService->withdraw($claim),
            default => throw OrderClaimException::notWithdrawable(),
        };

        if ($withdrawn instanceof OrderExchange || $withdrawn instanceof OrderReturn) {
            $this->paymentService->refundPaymentsForClaim($withdrawn, __('sirsoft-ecommerce::messages.claims.withdrawn'));
        }

        return $withdrawn;
    }

    // ───────────────────────────────────────────────
    // 결제
    // ───────────────────────────────────────────────

    /**
     * 결제번호로 열람자의 결제 건을 찾습니다.
     *
     * @param  string  $paymentNumber  결제번호 (CP)
     * @param  int|null  $userId  회원 ID
     * @param  Order|null  $guestOrder  비회원 주문
     * @return ClaimPayment 결제 건
     *
     * @throws OrderClaimException 부재·소유권 위반
     */
    public function findOwnedPayment(string $paymentNumber, ?int $userId, ?Order $guestOrder = null): ClaimPayment
    {
        $payment = $this->paymentService->findByNumber($paymentNumber);

        if ($payment === null) {
            throw OrderClaimException::paymentNotFound();
        }

        $payment->loadMissing('order');

        $owned = $guestOrder !== null
            ? (int) $payment->order_id === (int) $guestOrder->id
            : ($userId !== null && $payment->order !== null && (int) $payment->order->user_id === (int) $userId);

        if (! $owned) {
            throw OrderClaimException::paymentNotFound();
        }

        return $payment;
    }

    /**
     * 결제 대기 건의 결제를 시작합니다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @param  string|null  $paymentMethod  결제 수단
     * @param  int|null  $userId  회원 ID (마일리지 결제 주체)
     * @return array 결제 진입 결과 (`ClaimPaymentService::pay`)
     *
     * @throws OrderClaimException 결제 불가
     */
    public function pay(ClaimPayment $payment, ?string $paymentMethod, ?int $userId): array
    {
        return $this->paymentService->pay($payment, $paymentMethod, $userId);
    }

    /**
     * PG 콜백으로 결제를 확정합니다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @param  array  $callback  PG 콜백 파라미터
     * @return ClaimPayment 결제 완료 건
     *
     * @throws OrderClaimException 승인 실패
     */
    public function approve(ClaimPayment $payment, array $callback): ClaimPayment
    {
        return $this->paymentService->approvePgPayment($payment, $callback);
    }

    /**
     * 신청서의 결제 건 목록과 미수납액을 돌려줍니다. (상세 응답의 결제 블록)
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @return array{payments: Collection, outstanding_amount: float, pending_payment: ?ClaimPayment, payment_entry: ?array} 결제 블록
     */
    public function paymentBlock(OrderExchange|OrderReturn $claim): array
    {
        $payments = $this->paymentService->listForClaim($claim);
        $pending = $payments->first(fn (ClaimPayment $payment) => $payment->isPending());

        return [
            'payments' => $payments,
            'outstanding_amount' => $this->paymentService->outstandingAmount($claim),
            'pending_payment' => $pending,
            'payment_entry' => $pending ? $this->paymentService->buildPaymentPayload($pending) : null,
        ];
    }

    // ───────────────────────────────────────────────
    // 내부 헬퍼
    // ───────────────────────────────────────────────

    /**
     * 접수된 교환·반품의 신청 시점 결제를 시작합니다.
     *
     * @param  OrderExchange|OrderReturn  $claim  접수된 신청서
     * @param  string|null  $paymentMethod  결제 수단
     * @param  int|null  $userId  신청자 회원 ID
     * @return array{claim: OrderExchange|OrderReturn, payment: ?ClaimPayment, payment_entry: ?array} 신청 결과
     */
    protected function enterPayment(OrderExchange|OrderReturn $claim, ?string $paymentMethod, ?int $userId): array
    {
        $payment = $this->paymentService->initiateForRequest($claim, $paymentMethod);
        $entry = null;

        if ($payment !== null) {
            $result = $this->paymentService->pay($payment, $paymentMethod, $userId);
            $payment = $result['payment'];
            $entry = $result['requires_pg_payment'] ? [
                'requires_pg_payment' => true,
                'pg_provider' => $result['pg_provider'],
                'pg_payment_handler' => $result['pg_payment_handler'],
                'pg_payment_data' => $result['pg_payment_data'],
            ] : ['requires_pg_payment' => false, 'pg_provider' => null, 'pg_payment_handler' => null, 'pg_payment_data' => null];
        }

        return ['claim' => $claim->fresh(), 'payment' => $payment, 'payment_entry' => $entry];
    }

    /**
     * 설정의 값 목록을 문자열 배열로 읽습니다.
     *
     * @param  string  $key  설정 키
     * @param  array<string>  $default  기본값
     * @return array<string> 값 목록
     */
    protected function statusValues(string $key, array $default): array
    {
        return array_values(array_map('strval', (array) $this->settingsService->getSetting($key, $default)));
    }
}
