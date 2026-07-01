<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Services;

use Plugins\Sirsoft\PayKginicis\Repositories\CbtReconciliationRepositoryInterface;

class CbtReconciliationService
{
    public const META_KEY = 'kginicis_cbt_reconciliation';

    public const STATUS_AUTO_REFUNDED = 'auto_refunded';

    public const STATUS_MANUAL_REFUND_REQUIRED = 'manual_refund_required';

    public const STATUS_REFUND_RETRYING = 'refund_retrying';

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

        return $record;
    }

}
