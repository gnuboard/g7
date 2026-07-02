<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Extension\HookManager;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\DTO\MileageAdminDeductDto;
use Modules\Sirsoft\Ecommerce\DTO\MileageAdminEarnDto;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\MileageValidationException;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageBalanceRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageTransactionRepositoryInterface;

/**
 * 사용자 마일리지 서비스 (원장 SSoT + 표시 캐시)
 *
 * 잔액 변경 경로는 모두 동일 트랜잭션 내 마지막 단계에서 캐시(MileageBalanceRepository)를 재계산합니다.
 * 금전 검증(차감/검증)은 캐시가 아닌 원장 FOR UPDATE 재검증을 사용합니다.
 */
class UserMileageService
{
    use ResolvesActivityLogType;

    /**
     * @param  MileageTransactionRepositoryInterface  $ledger  원장 Repository
     * @param  MileageBalanceRepositoryInterface  $cache  잔액 캐시 Repository
     * @param  EcommerceSettingsService  $settings  환경설정 서비스
     */
    public function __construct(
        private MileageTransactionRepositoryInterface $ledger,
        private MileageBalanceRepositoryInterface $cache,
        private EcommerceSettingsService $settings,
    ) {}

    /**
     * 관리자 내역 목록을 조회합니다 (필터/페이지네이션).
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 개수
     * @return LengthAwarePaginator 페이지네이터
     */
    public function paginateAdminHistory(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->ledger->paginateWithFilters($filters, $perPage);
    }

    /**
     * 회원 uuid 를 정수 user_id 로 해석합니다 (관리자 수동 액션용).
     *
     * 코어 UserResource 가 정수 id 를 노출하지 않으므로 관리자 화면은 회원을 uuid 로 전달합니다.
     *
     * @param  string  $uuid  회원 uuid
     * @return int|null 정수 user_id (미존재 시 null)
     */
    public function resolveUserIdByUuid(string $uuid): ?int
    {
        return $this->ledger->resolveUserIdByUuid($uuid);
    }

    /**
     * 통화 필터 옵션 목록을 반환합니다 (설정 currency_rules 기준).
     *
     * 관리자 내역 통화 필터의 선택지 = 설정에 등록된 통화. 미설정 시 기본통화 단독.
     *
     * @return array<int, string> 통화 코드 배열
     */
    public function getFilterCurrencies(): array
    {
        $rules = (array) $this->settings->getSetting('mileage.currency_rules', []);

        $currencies = [];
        foreach ($rules as $rule) {
            $code = $rule['currency_code'] ?? null;
            if (is_string($code) && $code !== '') {
                $currencies[$code] = true;
            }
        }

        $codes = array_keys($currencies);

        return $codes !== [] ? $codes : [$this->defaultCurrency()];
    }

    /**
     * 마이페이지 회원 내역을 조회합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 개수
     * @return LengthAwarePaginator 페이지네이터
     */
    public function paginateUserHistory(int $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->ledger->paginateForUser($userId, $filters, $perPage);
    }

    /**
     * 거래 ID 로 연결 거래를 조회합니다 (행 확장용).
     *
     * @param  int  $id  거래 ID
     * @return Collection|null 연결 거래 (거래 없으면 null)
     */
    public function getLinkedTransactions(int $id): ?Collection
    {
        $transaction = $this->ledger->findById($id);
        if ($transaction === null) {
            return null;
        }

        return $this->ledger->getLinkedTransactions($transaction);
    }

    /**
     * 사용자 마일리지 잔액 조회 (화면/주입용 — 캐시 O(1) 조회)
     *
     * @param  int  $userId  사용자 ID
     * @return array 마일리지 정보 (enabled/available/pending/expiring_soon/expiring_date/total_earned/total_used/by_currency)
     */
    public function getBalance(int $userId): array
    {
        HookManager::doAction('sirsoft-ecommerce.user_mileage.before_balance', $userId);

        $cached = $this->cache->getCachedBalance($userId);

        $balance = [
            'enabled' => (bool) $this->settings->getSetting('mileage.enabled', false),
            'available' => $cached['available'] ?? 0,
            'pending' => $cached['pending'] ?? 0,
            'expiring_soon' => $cached['expiring_soon'] ?? 0,
            'expiring_date' => $cached['expiring_date'] ?? null,
            'total_earned' => $cached['total_earned'] ?? 0,
            'total_used' => $cached['total_used'] ?? 0,
            'by_currency' => $cached['by_currency'] ?? [],
        ];

        $balance = HookManager::applyFilters('sirsoft-ecommerce.user_mileage.filter_balance', $balance, $userId);

        HookManager::doAction('sirsoft-ecommerce.user_mileage.after_balance', $balance, $userId);

        return $balance;
    }

    /**
     * 마일리지 사용 가능 여부 확인 (잔액 기준)
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $amount  사용할 마일리지 금액
     * @param  string|null  $currency  통화 코드 (null 시 기본통화)
     * @return bool 사용 가능 여부
     */
    public function canUse(int $userId, int $amount, ?string $currency = null): bool
    {
        $currency = $currency ?? $this->defaultCurrency();

        return $this->ledger->getBalanceByCurrency($userId, $currency) >= $amount;
    }

    /**
     * 원장 기준 사용 가능 마일리지 잔액 (정수, floor)
     *
     * getBalance()는 표시 캐시 기준이라 캐시 지연 시 어긋날 수 있으므로,
     * 사용 검증/안내 문구는 원장(getBalanceByCurrency) 기준으로 통일합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  string|null  $currency  통화 코드 (null 시 기본통화)
     * @return int 원장 기준 가용 잔액
     */
    public function availableBalance(int $userId, ?string $currency = null): int
    {
        $currency = $currency ?? $this->defaultCurrency();

        return (int) floor($this->ledger->getBalanceByCurrency($userId, $currency));
    }

    /**
     * 사용 가능한 최대 마일리지 조회 (잔액 + 통화 규칙 캡)
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $orderAmount  주문 금액 (사용 한도 계산용)
     * @param  string|null  $currency  통화 코드 (null 시 기본통화)
     * @return int 사용 가능한 최대 마일리지
     */
    public function getMaxUsable(int $userId, int $orderAmount, ?string $currency = null): int
    {
        // 기본 통화 사용 규칙 미설정 시 마일리지 사용 불가 (M2)
        if (! $this->isMileageUsable()) {
            return 0;
        }

        $currency = $currency ?? $this->defaultCurrency();
        $rule = $this->currencyRule($currency);

        $balance = (int) floor($this->ledger->getBalanceByCurrency($userId, $currency));

        // 최대 사용 한도 (percent / fixed)
        $maxByLimit = $this->resolveMaxUseLimit($rule, $orderAmount);

        // 결제금액 캡 (사용액은 결제금액을 넘을 수 없음)
        $cap = min($balance, $maxByLimit, $orderAmount);

        // 사용 단위 내림 보정
        $unit = (int) ($rule['use_unit'] ?? 1);
        if ($unit > 1) {
            $cap = intdiv($cap, $unit) * $unit;
        }

        return max(0, $cap);
    }

    /**
     * 마일리지 사용 검증 (min/use_unit/max/잔액) → 보정값 반환 또는 예외
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $usePoints  사용 요청 마일리지
     * @param  int  $paymentAmount  결제 금액
     * @param  string  $currency  통화 코드
     * @return int 검증/보정된 사용 마일리지
     *
     * @throws MileageValidationException 검증 실패 시
     */
    public function validateUsage(int $userId, int $usePoints, int $paymentAmount, string $currency): int
    {
        if ($usePoints <= 0) {
            return 0;
        }

        // 기본 통화 사용 규칙 미설정 시 마일리지 사용 불가 (M2)
        if (! $this->isMileageUsable()) {
            throw new MileageValidationException(
                __('sirsoft-ecommerce::exceptions.mileage.base_currency_rule_missing')
            );
        }

        $rule = $this->currencyRule($currency);

        $minUse = (int) ($rule['min_use_amount'] ?? 0);
        if ($usePoints < $minUse) {
            throw new MileageValidationException(
                __('sirsoft-ecommerce::exceptions.mileage.below_min_use_amount', ['amount' => $minUse])
            );
        }

        $unit = (int) ($rule['use_unit'] ?? 1);
        if ($unit > 1 && $usePoints % $unit !== 0) {
            throw new MileageValidationException(
                __('sirsoft-ecommerce::exceptions.mileage.invalid_use_unit', ['unit' => $unit])
            );
        }

        $maxByLimit = $this->resolveMaxUseLimit($rule, $paymentAmount);
        if ($usePoints > $maxByLimit || $usePoints > $paymentAmount) {
            throw new MileageValidationException(
                __('sirsoft-ecommerce::exceptions.mileage.exceeds_max_use')
            );
        }

        if ($this->ledger->getBalanceByCurrency($userId, $currency) < $usePoints) {
            throw new MileageValidationException(
                __('sirsoft-ecommerce::exceptions.mileage.insufficient_balance')
            );
        }

        return $usePoints;
    }

    /**
     * 주문옵션 적립 (존재검사 → subtotal_earned_points_amount 기준 적립)
     *
     * @param  Order  $order  주문
     * @param  OrderOption  $option  주문옵션
     * @param  MileageTransactionTypeEnum  $type  적립 유형
     * @return MileageTransaction|null 생성된 적립 거래 (이미 적립됐거나 0원이면 null)
     */
    public function earnForOrderOption(Order $order, OrderOption $option, MileageTransactionTypeEnum $type): ?MileageTransaction
    {
        if ($order->user_id === null) {
            return null;
        }

        if ($this->ledger->existsEarnForOption($option->id)) {
            return null;
        }

        $amount = (float) $option->subtotal_earned_points_amount;
        if ($amount <= 0) {
            return null;
        }

        $currency = $this->baseCurrencyForOrder($order);
        $expiresAt = $this->resolveEarnExpiry();

        $tx = $this->ledger->createTransaction([
            'user_id' => $order->user_id,
            'currency' => $currency,
            'type' => $type->value,
            'amount' => $amount,
            'remaining_amount' => $amount,
            'balance_after' => $this->ledger->getBalanceByCurrency($order->user_id, $currency) + $amount,
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'expires_at' => $expiresAt,
            'description' => __('sirsoft-ecommerce::activity_log.description.mileage_earn', ['amount' => $amount]),
        ]);

        $this->cache->recalculateForUser($order->user_id, $currency);
        $this->cache->recalculatePending($order->user_id, $currency);

        $this->logActivity('mileage.earn', [
            'loggable' => $tx,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.mileage_earn',
            'description_params' => ['amount' => (int) $amount],
            'properties' => ['order_id' => $order->id, 'order_option_id' => $option->id, 'currency' => $currency],
        ]);

        return $tx;
    }

    /**
     * FIFO 차감 (FOR UPDATE → 재검증 → lot 소비)
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $amount  차감액
     * @param  string  $currency  통화 코드
     * @param  Order  $order  주문
     * @return MileageTransaction 생성된 사용 거래 (음수)
     *
     * @throws MileageValidationException 잔액 부족 시
     */
    public function deductFifo(int $userId, int $amount, string $currency, Order $order): MileageTransaction
    {
        return $this->consumeFifo(
            $userId,
            $amount,
            $currency,
            MileageTransactionTypeEnum::ORDER_USE,
            ['order_id' => $order->id],
            null,
            null,
        );
    }

    /**
     * 사용 복원 (mileage.restore payload 기반, order_cancel_id 멱등, 신규 lot)
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $orderId  주문 ID
     * @param  int  $orderCancelId  주문취소 ID (멱등 기준)
     * @param  float  $amount  복원액
     * @param  string  $currency  통화 코드
     * @return MileageTransaction|null 복원 거래 (이미 복원됐거나 0원이면 null)
     */
    public function restoreForCancel(int $userId, int $orderId, int $orderCancelId, float $amount, string $currency): ?MileageTransaction
    {
        if ($this->ledger->existsRestoreForCancel($orderCancelId)) {
            return null;
        }

        if ($amount <= 0) {
            return null;
        }

        // 복원 lot 은 원본 expires_at 승계가 아닌 신규 lot (복원 시점 + expiry_days)
        $expiresAt = $this->resolveEarnExpiry();

        $tx = $this->ledger->createTransaction([
            'user_id' => $userId,
            'currency' => $currency,
            'type' => MileageTransactionTypeEnum::ORDER_CANCEL_RESTORE->value,
            'amount' => $amount,
            'remaining_amount' => $amount,
            'balance_after' => $this->ledger->getBalanceByCurrency($userId, $currency) + $amount,
            'order_id' => $orderId,
            'order_cancel_id' => $orderCancelId,
            'expires_at' => $expiresAt,
            'description' => __('sirsoft-ecommerce::activity_log.description.mileage_restore', ['amount' => $amount]),
        ]);

        // 원 사용 거래에 동일 order_cancel_id 역주입 — 복원 ↔ 원 사용 거래 연결 (행 확장 정합)
        $this->ledger->linkUsageToCancel($orderId, $orderCancelId);

        $this->cache->recalculateForUser($userId, $currency);

        $this->logActivity('mileage.restore', [
            'loggable' => $tx,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.mileage_restore',
            'description_params' => ['amount' => (int) $amount],
            'properties' => ['order_id' => $orderId, 'order_cancel_id' => $orderCancelId, 'currency' => $currency],
        ]);

        return $tx;
    }

    /**
     * 결제 실패/결제창 닫기로 인한 주문 취소 시 사용 마일리지를 복원합니다.
     *
     * 정식 주문취소(OrderCancel 레코드 + 환불 정산)와 달리, 결제 완료 전 주문이
     * failPayment 로 취소되는 경로는 OrderCancel 이 없다. 따라서 order_cancel_id 없이
     * 주문 단위로 복원하며, 멱등성은 호출자(OrderProcessingService::failPayment)가
     * is_mileage_deducted 플래그 + 결제 전 상태 가드로 보장한다 (복원 후 플래그 해제).
     * 복원 lot 은 신규 발행(복원 시점 + expiry_days) — 정식 취소 복원과 동일 정책.
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $orderId  주문 ID
     * @param  float  $amount  복원액
     * @param  string  $currency  통화 코드
     * @return MileageTransaction|null 복원 거래 (0원이면 null)
     */
    public function restoreForFailedPayment(int $userId, int $orderId, float $amount, string $currency): ?MileageTransaction
    {
        if ($amount <= 0) {
            return null;
        }

        $expiresAt = $this->resolveEarnExpiry();

        $tx = $this->ledger->createTransaction([
            'user_id' => $userId,
            'currency' => $currency,
            'type' => MileageTransactionTypeEnum::ORDER_CANCEL_RESTORE->value,
            'amount' => $amount,
            'remaining_amount' => $amount,
            'balance_after' => $this->ledger->getBalanceByCurrency($userId, $currency) + $amount,
            'order_id' => $orderId,
            'expires_at' => $expiresAt,
            'description' => __('sirsoft-ecommerce::activity_log.description.mileage_restore', ['amount' => $amount]),
        ]);

        $this->cache->recalculateForUser($userId, $currency);

        $this->logActivity('mileage.restore', [
            'loggable' => $tx,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.mileage_restore',
            'description_params' => ['amount' => (int) $amount],
            'properties' => ['order_id' => $orderId, 'currency' => $currency, 'reason' => 'payment_failed'],
        ]);

        return $tx;
    }

    /**
     * 적립 회수 (취소 전이 시 기적립건 회수 — §2 부족 회수 정책)
     *
     * @param  Order  $order  주문
     * @param  OrderOption  $option  주문옵션
     * @return MileageTransaction|null 회수 거래 (회수 대상 없으면 null)
     */
    public function cancelEarnForOption(Order $order, OrderOption $option): ?MileageTransaction
    {
        if ($order->user_id === null) {
            return null;
        }

        // 해당 옵션 적립건 조회
        $earnLot = $this->ledger->findEarnLotForOption($option->id);

        if ($earnLot === null) {
            return null;
        }

        $currency = $this->baseCurrencyForOrder($order);
        $toRecover = (float) $earnLot->amount;

        return DB::transaction(function () use ($order, $option, $earnLot, $currency, $toRecover) {
            $shortfall = $this->recoverPoints($order->user_id, $currency, $toRecover, $earnLot);

            $tx = $this->ledger->createTransaction([
                'user_id' => $order->user_id,
                'currency' => $currency,
                'type' => MileageTransactionTypeEnum::EARN_CANCEL->value,
                'amount' => -($toRecover - $shortfall),
                'remaining_amount' => 0,
                'balance_after' => $this->ledger->getBalanceByCurrency($order->user_id, $currency),
                'order_id' => $order->id,
                'order_option_id' => $option->id,
                'source_transaction_id' => $earnLot->id,
                'metadata' => $shortfall > 0 ? ['shortfall' => $shortfall] : null,
                'description' => __('sirsoft-ecommerce::activity_log.description.mileage_earn_cancel', ['amount' => $toRecover]),
            ]);

            $this->cache->recalculateForUser($order->user_id, $currency);
            $this->cache->recalculatePending($order->user_id, $currency);

            $this->logActivity('mileage.earn_cancel', [
                'loggable' => $tx,
                'description_key' => 'sirsoft-ecommerce::activity_log.description.mileage_earn_cancel',
                'description_params' => ['amount' => (int) $toRecover],
                'properties' => [
                    'order_id' => $order->id,
                    'order_option_id' => $option->id,
                    'currency' => $currency,
                    'shortfall' => $shortfall,
                ],
            ]);

            return $tx;
        });
    }

    /**
     * 관리자 수동 지급
     *
     * @param  int  $userId  대상 사용자 ID
     * @param  MileageAdminEarnDto  $dto  지급 DTO
     * @return MileageTransaction 생성된 지급 거래
     */
    public function adminEarn(int $userId, MileageAdminEarnDto $dto): MileageTransaction
    {
        return DB::transaction(function () use ($userId, $dto) {
            $expiresAt = $this->resolveAdminEarnExpiry($dto);

            $tx = $this->ledger->createTransaction([
                'user_id' => $userId,
                'currency' => $dto->currency,
                'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
                'amount' => $dto->amount,
                'remaining_amount' => $dto->amount,
                'balance_after' => $this->ledger->getBalanceByCurrency($userId, $dto->currency) + $dto->amount,
                'granted_by' => $dto->grantedBy,
                'memo' => $dto->memo,
                'expires_at' => $expiresAt,
                'description' => $dto->description
                    ?? __('sirsoft-ecommerce::activity_log.description.mileage_admin_earn', ['amount' => $dto->amount]),
            ]);

            $this->cache->recalculateForUser($userId, $dto->currency);

            $this->logActivity('mileage.admin_earn', [
                'loggable' => $tx,
                'description_key' => 'sirsoft-ecommerce::activity_log.description.mileage_admin_earn',
                'description_params' => ['amount' => $dto->amount],
                'properties' => ['granted_by' => $dto->grantedBy, 'currency' => $dto->currency],
            ]);

            return $tx;
        });
    }

    /**
     * 관리자 수동 차감 (FIFO 소비 + 잔액 한도)
     *
     * @param  int  $userId  대상 사용자 ID
     * @param  MileageAdminDeductDto  $dto  차감 DTO
     * @return MileageTransaction 생성된 차감 거래 (음수)
     *
     * @throws MileageValidationException 잔액 초과 차감 시
     */
    public function adminDeduct(int $userId, MileageAdminDeductDto $dto): MileageTransaction
    {
        return $this->consumeFifo(
            $userId,
            $dto->amount,
            $dto->currency,
            MileageTransactionTypeEnum::ADMIN_DEDUCT,
            ['granted_by' => $dto->grantedBy, 'memo' => $dto->memo],
            $dto->grantedBy,
            $dto->memo,
            'deduct_exceeds_balance',
        );
    }

    /**
     * 일괄 유효기간 연장 (지급계 lot 의 expires_at 갱신)
     *
     * @param  int  $userId  대상 사용자 ID
     * @param  array<int, int>  $lotIds  연장 대상 lot ID 목록
     * @param  int  $days  연장 일수
     * @return int 연장된 lot 수
     */
    public function extendLotExpiry(int $userId, array $lotIds, int $days): int
    {
        return DB::transaction(function () use ($userId, $lotIds, $days) {
            $lots = $this->ledger->getRemainingLotsByIds($userId, $lotIds);

            $currencies = [];
            foreach ($lots as $lot) {
                $base = $lot->expires_at !== null ? $lot->expires_at->copy() : now();
                $this->ledger->updateExpiry($lot, $base->addDays($days));
                $currencies[$lot->currency] = true;
            }

            foreach (array_keys($currencies) as $currency) {
                $this->cache->recalculateForUser($userId, $currency);
            }

            if ($lots->isNotEmpty()) {
                $this->logActivity('mileage.extend_expiry', [
                    'loggable' => $lots->first(),
                    'description_key' => 'sirsoft-ecommerce::activity_log.description.mileage_extend_expiry',
                    'description_params' => ['days' => $days],
                    'properties' => ['user_id' => $userId, 'lot_count' => $lots->count(), 'days' => $days],
                ]);
            }

            return $lots->count();
        });
    }

    /**
     * 관리자 적립건 편집 — 사유(memo) 변경 + 만료일(expires_at) 직접 지정.
     *
     * 마일리지 원장은 불변이므로 거래를 삭제하지 않고, 적립계 거래의 부가 필드만 보정한다.
     * 적립계가 아닌 거래는 편집 불가(not_earning). 이미 소멸(expired_at 존재)되었거나 전액
     * 사용(remaining 0)된 적립건은 만료일 변경 불가(expiry_not_editable) — memo 만 변경 가능.
     *
     * @param  int  $id  거래 ID
     * @param  string|null  $memo  변경할 관리자 메모 (null 이면 미변경 — Controller 가 키 존재로 판정)
     * @param  Carbon|null  $expiresAt  변경할 만료일 (null 이면 미변경)
     * @param  bool  $touchMemo  memo 키 전달 여부 (null 로 지우기와 미전달 구분)
     * @param  bool  $touchExpiry  expires_at 키 전달 여부
     * @return MileageTransaction 갱신된 거래
     *
     * @throws MileageValidationException 적립계 아님 / 만료일 변경 불가
     */
    public function updateAdminTransaction(
        int $id,
        ?string $memo,
        ?Carbon $expiresAt,
        bool $touchMemo,
        bool $touchExpiry
    ): MileageTransaction {
        return DB::transaction(function () use ($id, $memo, $expiresAt, $touchMemo, $touchExpiry) {
            $transaction = $this->ledger->findById($id);
            if ($transaction === null) {
                throw new MileageValidationException(__('sirsoft-ecommerce::messages.mileage.not_found'));
            }

            $type = $transaction->type instanceof MileageTransactionTypeEnum
                ? $transaction->type
                : MileageTransactionTypeEnum::tryFrom((string) $transaction->type);

            if (! ($type?->isEarning() ?? false)) {
                throw new MileageValidationException(__('sirsoft-ecommerce::messages.mileage.not_earning'));
            }

            $fields = [];
            if ($touchMemo) {
                $fields['memo'] = $memo;
            }

            if ($touchExpiry) {
                $isExpiredOrUsedUp = $transaction->expired_at !== null
                    || (float) $transaction->remaining_amount <= 0;
                if ($isExpiredOrUsedUp) {
                    throw new MileageValidationException(__('sirsoft-ecommerce::messages.mileage.expiry_not_editable'));
                }

                // 만료일은 적립일시(created_at)보다 이전일 수 없다 (무기한 = null 은 허용)
                if ($expiresAt !== null
                    && $transaction->created_at instanceof Carbon
                    && $expiresAt->lessThan($transaction->created_at)
                ) {
                    throw new MileageValidationException(__('sirsoft-ecommerce::messages.mileage.expiry_before_earned'));
                }

                $fields['expires_at'] = $expiresAt;
            }

            $transaction = $this->ledger->updateAdminFields($transaction, $fields);

            // 만료일 변경 시 소멸 임박 윈도우 캐시 영향 — 사용자 통화 재계산
            if ($touchExpiry) {
                $this->cache->recalculateForUser($transaction->user_id, $transaction->currency);
            }

            $this->logActivity('mileage.adjust', [
                'loggable' => $transaction,
                'description_key' => 'sirsoft-ecommerce::activity_log.description.mileage_adjust',
                'description_params' => ['amount' => (int) abs((float) $transaction->amount)],
                'properties' => [
                    'user_id' => $transaction->user_id,
                    'currency' => $transaction->currency,
                    'memo_changed' => $touchMemo,
                    'expiry_changed' => $touchExpiry,
                ],
            ]);

            return $transaction;
        });
    }

    /**
     * 만료 lot 소멸 처리 (배치용 — 만료 lot마다 per-item expired 거래 + expired_at)
     *
     * @param  Carbon  $now  기준 시각
     * @param  int|null  $limit  최대 처리 건수
     * @return int 소멸 처리된 lot 수
     */
    public function expireLots(Carbon $now, ?int $limit = null): int
    {
        $lots = $this->ledger->getExpiringLots($now, $limit);
        $affected = [];

        foreach ($lots as $lot) {
            DB::transaction(function () use ($lot, $now, &$affected) {
                $remaining = (float) $lot->remaining_amount;

                $tx = $this->ledger->createTransaction([
                    'user_id' => $lot->user_id,
                    'currency' => $lot->currency,
                    'type' => MileageTransactionTypeEnum::EXPIRED->value,
                    'amount' => -$remaining,
                    'remaining_amount' => 0,
                    'balance_after' => $this->ledger->getBalanceByCurrency($lot->user_id, $lot->currency) - $remaining,
                    'source_transaction_id' => $lot->id,
                    'expired_at' => $now,
                    'description' => __('sirsoft-ecommerce::activity_log.description.mileage_expire', ['amount' => $remaining]),
                ]);

                $this->ledger->markExpired($lot, $now);
                $this->cache->recalculateForUser($lot->user_id, $lot->currency);

                $this->logActivity('mileage.expire', [
                    'loggable' => $tx,
                    'description_key' => 'sirsoft-ecommerce::activity_log.description.mileage_expire',
                    'description_params' => ['amount' => (int) $remaining],
                    'properties' => ['user_id' => $lot->user_id, 'currency' => $lot->currency],
                ]);

                $affected[] = $lot->id;
            });
        }

        return count($affected);
    }

    /**
     * 회원 탈퇴/삭제 시 활성 lot 소멸 기록 (감사 흐름 보존 — 정책 확정 #8)
     *
     * 활성 lot 을 expired 거래로 소멸 기록(활동로그에 금액·통화·회원ID 잔존)합니다.
     * 거래/캐시 행은 이후 cascade 또는 명시 삭제로 함께 사라집니다.
     *
     * @param  int  $userId  탈퇴 사용자 ID
     */
    public function recordWithdrawalExpiry(int $userId): void
    {
        $lots = $this->ledger->getActiveLotsForUser($userId);

        $now = now();
        foreach ($lots as $lot) {
            $remaining = (float) $lot->remaining_amount;
            if ($remaining <= 0) {
                continue;
            }

            $this->logActivity('mileage.expire', [
                'description_key' => 'sirsoft-ecommerce::activity_log.description.mileage_expire',
                'description_params' => ['amount' => (int) $remaining],
                'properties' => [
                    'user_id' => $userId,
                    'currency' => $lot->currency,
                    'reason' => 'withdrawal',
                ],
            ]);

            $this->ledger->markExpired($lot, $now);
        }
    }

    /**
     * 회원 탈퇴/삭제 시 마일리지 데이터를 명시적으로 정리합니다 (정책 확정 #8).
     *
     * 활성 lot 을 expired 거래로 소멸 기록(활동로그 보존)한 뒤, 원장·잔액 캐시 행을
     * 동일 트랜잭션에서 삭제합니다. CASCADE 는 안전망으로만 두고 명시 삭제합니다
     * (탈퇴는 soft 상태 변경이라 cascadeOnDelete 가 발화하지 않으므로 명시 삭제 필수).
     *
     * @param  int  $userId  탈퇴/삭제 회원 ID
     */
    public function purgeForUser(int $userId): void
    {
        DB::transaction(function () use ($userId) {
            $this->recordWithdrawalExpiry($userId);
            $this->ledger->deleteForUser($userId);
            $this->cache->deleteForUser($userId);
        });
    }

    /**
     * FIFO 차감 공통 처리 (사용/관리자 차감)
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $amount  차감액
     * @param  string  $currency  통화 코드
     * @param  MileageTransactionTypeEnum  $type  거래 유형
     * @param  array  $extra  거래 추가 필드 (order_id/granted_by/memo)
     * @param  int|null  $grantedBy  부여 관리자 ID (활동로그용)
     * @param  string|null  $memo  메모
     * @param  string  $insufficientKey  잔액 부족 예외 메시지 키
     * @return MileageTransaction 생성된 차감 거래
     *
     * @throws MileageValidationException 잔액 부족 시
     */
    private function consumeFifo(
        int $userId,
        int $amount,
        string $currency,
        MileageTransactionTypeEnum $type,
        array $extra,
        ?int $grantedBy,
        ?string $memo,
        string $insufficientKey = 'insufficient_balance',
    ): MileageTransaction {
        return DB::transaction(function () use ($userId, $amount, $currency, $type, $extra, $grantedBy, $insufficientKey) {
            $lots = $this->ledger->getActiveLotsForUpdate($userId, $currency);

            $available = $lots->sum(fn ($lot) => (float) $lot->remaining_amount);
            if ($available < $amount) {
                throw new MileageValidationException(
                    __("sirsoft-ecommerce::exceptions.mileage.{$insufficientKey}")
                );
            }

            $remaining = (float) $amount;
            $firstSource = null;
            foreach ($lots as $lot) {
                if ($remaining <= 0) {
                    break;
                }
                $take = min((float) $lot->remaining_amount, $remaining);
                $this->ledger->decrementRemaining($lot, $take);
                $remaining -= $take;
                $firstSource ??= $lot->id;
            }

            $tx = $this->ledger->createTransaction(array_merge([
                'user_id' => $userId,
                'currency' => $currency,
                'type' => $type->value,
                'amount' => -$amount,
                'remaining_amount' => 0,
                'balance_after' => $this->ledger->getBalanceByCurrency($userId, $currency),
                'source_transaction_id' => $firstSource,
            ], $extra));

            $this->cache->recalculateForUser($userId, $currency);

            $action = $type === MileageTransactionTypeEnum::ADMIN_DEDUCT ? 'mileage.admin_deduct' : 'mileage.use';
            $descKey = $type === MileageTransactionTypeEnum::ADMIN_DEDUCT
                ? 'sirsoft-ecommerce::activity_log.description.mileage_admin_deduct'
                : 'sirsoft-ecommerce::activity_log.description.mileage_use';

            $this->logActivity($action, [
                'loggable' => $tx,
                'description_key' => $descKey,
                'description_params' => ['amount' => $amount],
                'properties' => array_merge(['currency' => $currency], $grantedBy !== null ? ['granted_by' => $grantedBy] : []),
            ]);

            return $tx;
        });
    }

    /**
     * 적립 회수 시 lot 잔여를 우선 차감 → 타 lot FIFO → 부족분 반환 (§2 정책)
     *
     * @param  int  $userId  사용자 ID
     * @param  string  $currency  통화 코드
     * @param  float  $amount  회수액
     * @param  MileageTransaction  $earnLot  대상 적립건
     * @return float 회수하지 못한 부족액
     */
    private function recoverPoints(int $userId, string $currency, float $amount, MileageTransaction $earnLot): float
    {
        $remaining = $amount;

        // 1) 해당 earn lot 잔여 우선 차감
        $fromSelf = min((float) $earnLot->remaining_amount, $remaining);
        if ($fromSelf > 0) {
            $this->ledger->decrementRemaining($earnLot, $fromSelf);
            $remaining -= $fromSelf;
        }

        // 2) 타 lot FIFO 차감
        if ($remaining > 0) {
            $lots = $this->ledger->getActiveLotsForUpdate($userId, $currency);
            foreach ($lots as $lot) {
                if ($remaining <= 0) {
                    break;
                }
                if ($lot->id === $earnLot->id) {
                    continue;
                }
                $take = min((float) $lot->remaining_amount, $remaining);
                $this->ledger->decrementRemaining($lot, $take);
                $remaining -= $take;
            }
        }

        // 3) 부족분 반환 (음수 잔액 구조적 불가)
        return max(0, $remaining);
    }

    /**
     * 적립 만료일을 설정값 기준으로 계산합니다.
     *
     * @return Carbon|null 만료일 (유효기간 비활성 시 null)
     */
    private function resolveEarnExpiry(): ?Carbon
    {
        if (! (bool) $this->settings->getSetting('mileage.expiry_enabled', true)) {
            return null;
        }

        $days = (int) $this->settings->getSetting('mileage.expiry_days', 365);

        return now()->addDays($days);
    }

    /**
     * 관리자 지급 만료일을 계산합니다.
     *
     * @param  MileageAdminEarnDto  $dto  지급 DTO
     * @return Carbon|null 만료일
     */
    private function resolveAdminEarnExpiry(MileageAdminEarnDto $dto): ?Carbon
    {
        if ($dto->expiresAt !== null) {
            return Carbon::parse($dto->expiresAt);
        }

        if (! $dto->useDefaultExpiry) {
            return null;
        }

        return $this->resolveEarnExpiry();
    }

    /**
     * 통화별 최대 사용 한도를 계산합니다 (percent / fixed).
     *
     * @param  array  $rule  통화 규칙
     * @param  int  $paymentAmount  결제 금액
     * @return int 최대 사용 한도
     */
    private function resolveMaxUseLimit(array $rule, int $paymentAmount): int
    {
        $type = $rule['max_use_type'] ?? 'percent';

        if ($type === 'percent') {
            $percent = (float) ($rule['max_use_percent'] ?? 100);

            return (int) floor($paymentAmount * $percent / 100);
        }

        return (int) ($rule['max_use_value'] ?? PHP_INT_MAX);
    }

    /**
     * 통화 규칙을 조회합니다.
     *
     * @param  string  $currency  통화 코드
     * @return array 통화 규칙
     */
    private function currencyRule(string $currency): array
    {
        $rules = (array) $this->settings->getSetting('mileage.currency_rules', []);

        foreach ($rules as $rule) {
            if (($rule['currency_code'] ?? null) === $currency) {
                return $rule;
            }
        }

        return $rules[0] ?? [];
    }

    /**
     * 기본 통화를 반환합니다.
     *
     * @return string 기본 통화 코드
     */
    private function defaultCurrency(): string
    {
        $rules = (array) $this->settings->getSetting('mileage.currency_rules', []);

        return $rules[0]['currency_code'] ?? 'KRW';
    }

    /**
     * 마일리지 사용이 가능한지 여부를 반환합니다.
     *
     * 기본 통화(base_currency)에 대한 마일리지 통화별 사용 단위 규칙이 설정되어 있지 않으면
     * 마일리지 사용을 허용하지 않는다(정책). 마일리지는 base_currency 기준으로 사용/정산되므로
     * base 규칙이 없으면 사용 단위·최소 사용액·한도를 알 수 없어 사용 자체가 불가하다.
     *
     * @return bool 기본 통화 마일리지 사용 규칙이 설정되어 있으면 true
     */
    public function isMileageUsable(): bool
    {
        $rules = (array) $this->settings->getSetting('mileage.currency_rules', []);
        if ($rules === []) {
            return false;
        }

        $base = $this->settings->getSetting('language_currency.default_currency', null)
            ?? ($rules[0]['currency_code'] ?? null);

        if (! is_string($base) || $base === '') {
            return false;
        }

        foreach ($rules as $rule) {
            if (($rule['currency_code'] ?? null) === $base) {
                return true;
            }
        }

        return false;
    }

    /**
     * 주문의 마일리지 정산 통화(기본화폐 base_currency)를 반환합니다.
     *
     * 마일리지는 주문서/PG 정산과 동일하게 base_currency 기준으로 적립·사용·복원된다.
     * 유저가 선택한 표시통화(order_currency/X-Currency)와 무관하다 — 표시통화는 화면 환산 전용.
     * 주문 통화 스냅샷의 base_currency 를 우선 사용하고, 없으면 마일리지 설정의 기본 통화로 폴백.
     *
     * @param  Order  $order  주문
     * @return string 기준통화 코드
     */
    public function baseCurrencyForOrder(Order $order): string
    {
        $snapshot = $order->currency_snapshot ?? [];

        return $snapshot['base_currency'] ?? $this->defaultCurrency();
    }
}
