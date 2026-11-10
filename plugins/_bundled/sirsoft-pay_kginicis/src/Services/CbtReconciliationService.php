<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Services;

use App\Helpers\TimezoneHelper;
use Carbon\Carbon;
use Plugins\Sirsoft\PayKginicis\Repositories\CbtReconciliationRepositoryInterface;

class CbtReconciliationService
{
    public const META_KEY = 'kginicis_cbt_reconciliation';

    public const STATUS_AUTO_REFUNDED = 'auto_refunded';

    public const STATUS_MANUAL_REFUND_REQUIRED = 'manual_refund_required';

    public const STATUS_REFUND_RETRYING = 'refund_retrying';

    /**
     * @param  CbtReconciliationRepositoryInterface  $repository  CBT 조정 레코드 Repository
     */
    public function __construct(
        private readonly CbtReconciliationRepositoryInterface $repository,
    ) {}

    /**
     * 주문번호에 연결된 CBT 조정 레코드를 조회합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return array<string, mixed>|null 정규화된 조정 레코드
     */
    public function get(string $orderNumber): ?array
    {
        $record = $this->repository->findRecord($orderNumber, self::META_KEY);

        return is_array($record) ? $this->normalize($record) : null;
    }

    /**
     * CBT 조정 레코드를 주문 메타에 병합 저장합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  array<string, mixed>  $attributes  저장할 조정 속성
     * @return array<string, mixed>|null 정규화된 조정 레코드
     */
    public function record(string $orderNumber, array $attributes): ?array
    {
        $existing = $this->repository->findRecord($orderNumber, self::META_KEY) ?? [];
        if (! is_array($existing)) {
            $existing = [];
        }

        // audit:allow datetime-display-user-timezone reason: ISO8601(오프셋 포함) API 기계값 — 표시는 *_formatted
        $now = now()->toIso8601String();
        $record = array_merge($existing, $attributes, [
            'created_at' => $existing['created_at'] ?? $now,
            'updated_at' => $now,
        ]);

        $saved = $this->repository->saveRecord($orderNumber, self::META_KEY, $record);

        return is_array($saved) ? $this->normalize($saved) : null;
    }

    /**
     * CBT 수동 환불 재시도 권한을 원자적으로 선점합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return array<string, mixed>|null 선점된 조정 레코드
     */
    public function claimRefundRetry(string $orderNumber): ?array
    {
        $claimed = $this->repository->mutateRecordWithLock($orderNumber, self::META_KEY, function (array $existing): ?array {
            $record = $this->normalize($existing);
            if (! ($record['can_retry'] ?? false)) {
                return null;
            }

            // audit:allow datetime-display-user-timezone reason: ISO8601(오프셋 포함) API 기계값 — 표시는 *_formatted
            $now = now()->toIso8601String();
            $claimed = array_merge($existing, [
                'status' => self::STATUS_REFUND_RETRYING,
                'manual_action_required' => false,
                'last_retry_at' => $now,
                'last_retry_error' => null,
                'retry_count' => ((int) ($existing['retry_count'] ?? 0)) + 1,
                'created_at' => $existing['created_at'] ?? $now,
                'updated_at' => $now,
            ]);

            return $claimed;
        });

        return is_array($claimed) ? $this->normalize($claimed) : null;
    }

    /**
     * 조정 레코드를 화면 응답용으로 정규화합니다. (저장에는 쓰지 않는다)
     *
     * @param  array<string, mixed>  $record  저장된 조정 레코드
     * @return array<string, mixed> 정규화된 조정 레코드
     */
    private function normalize(array $record): array
    {
        $record['status'] = (string) ($record['status'] ?? '');
        $record['tid'] = (string) ($record['tid'] ?? '');
        $record['amount'] = (int) ($record['amount'] ?? 0);
        $record['retry_count'] = (int) ($record['retry_count'] ?? 0);
        $record['manual_action_required'] = $record['status'] === self::STATUS_MANUAL_REFUND_REQUIRED;
        $record['can_retry'] = $record['manual_action_required'] && $record['tid'] !== '';

        $refundResult = is_array($record['refund_result'] ?? null) ? $record['refund_result'] : [];
        $record['refund_result_code'] = (string) ($refundResult['resultCode'] ?? $refundResult['code'] ?? '');
        $record['refund_result_msg'] = (string) ($refundResult['resultMsg'] ?? $refundResult['message'] ?? '');

        // 화면 응답용 — 저장 ISO 는 그대로 두고 보는 사람 시간대 문자열을 곁들인다
        foreach (['created_at', 'updated_at', 'last_retry_at'] as $key) {
            $record[$key.'_formatted'] = $this->formatIsoForViewer($record[$key] ?? null);
        }

        return $record;
    }

    /**
     * 저장된 ISO8601 시각을 보는 사람 시간대 표시 문자열로 바꿉니다.
     *
     * @param  mixed  $iso  저장된 ISO8601 시각
     * @return string|null 표시용 일시 (값이 없거나 해석할 수 없으면 null)
     */
    private function formatIsoForViewer(mixed $iso): ?string
    {
        if (! is_string($iso) || $iso === '') {
            return null;
        }

        try {
            return TimezoneHelper::toUserDateTimeString(Carbon::parse($iso));
        } catch (\Throwable) {
            return null;
        }
    }
}
