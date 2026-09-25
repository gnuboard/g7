<?php

namespace Modules\Sirsoft\Ecommerce\Services\Concerns;

use Illuminate\Database\Eloquent\Model;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;

/**
 * 클레임 송장(택배사 + 운송장 번호) 입력을 해석하는 공용 규칙 — 교환·반품 서비스 공용
 *
 * 회수중·발송중은 **택배가 물건을 싣고 가는 중**이라는 사실이다. 송장 없이 그 상태가 되면
 * 고객에게 「아래 운송장으로 확인하세요」 알림이 빈 칸으로 나가고, 교환품의 새 주문상품이
 * 조회할 수 없는 배송 행을 갖는다. 그래서 그 상태로 옮기는 모든 입구(신청서 전이·상품 칸·목록
 * 일괄)가 이 한 규칙을 지난다(#599 후속 운영 결정) — 입구마다 따로 두면 한쪽이 빠진다.
 *
 * 송장 저장(「배송 정보」 카드)과 상태 전이는 별개다 — 저장은 저장만 하고, 전이는 지시에 실린
 * 송장을 기록한 뒤 옮긴다.
 */
trait RecordsClaimTracking
{
    /**
     * 택배사·운송장 입력을 정규화합니다. (둘 다 있거나 둘 다 없어야 한다)
     *
     * @param  int|null  $carrierId  택배사 ID
     * @param  string|null  $trackingNumber  운송장 번호 (앞뒤 공백 제거, 빈 문자열은 없음)
     * @return array{0: int|null, 1: string|null} [택배사 ID, 운송장 번호]
     *
     * @throws OrderClaimException 한쪽만 입력한 경우
     */
    protected function normalizeTracking(?int $carrierId, ?string $trackingNumber): array
    {
        $trackingNumber = $trackingNumber !== null && trim($trackingNumber) !== ''
            ? trim($trackingNumber)
            : null;

        if (($carrierId === null) !== ($trackingNumber === null)) {
            throw OrderClaimException::trackingIncomplete();
        }

        return [$carrierId, $trackingNumber];
    }

    /**
     * 송장이 있어야 들어가는 상태로 옮기기 전에, 신청서에 기록할 송장 컬럼을 정합니다.
     *
     * 지시(`carrier_id`·`tracking_number`)에 송장이 실려 있으면 그것을 기록한다. 실려 있지 않으면
     * 신청서에 이미 저장된 송장을 쓴다 — 그마저 없으면 전이를 거절한다.
     *
     * @param  Model  $claim  신청서 (교환·반품)
     * @param  array{carrier: string, tracking: string}  $columns  기록할 컬럼명
     * @param  array  $context  전이 부가 정보
     * @return array<string, mixed> 신청서에 쓸 송장 컬럼 (지시에 송장이 없으면 빈 배열)
     *
     * @throws OrderClaimException 한쪽만 입력 / 지시에도 저장값에도 송장이 없음
     */
    protected function trackingUpdatesForTransition(Model $claim, array $columns, array $context): array
    {
        $carrierId = $context['carrier_id'] ?? null;

        [$carrierId, $trackingNumber] = $this->normalizeTracking(
            $carrierId === null || $carrierId === '' ? null : (int) $carrierId,
            isset($context['tracking_number']) ? (string) $context['tracking_number'] : null,
        );

        if ($trackingNumber !== null) {
            return [$columns['carrier'] => $carrierId, $columns['tracking'] => $trackingNumber];
        }

        if ($claim->getAttribute($columns['carrier']) === null
            || trim((string) $claim->getAttribute($columns['tracking'])) === '') {
            throw OrderClaimException::trackingRequired();
        }

        return [];
    }
}
