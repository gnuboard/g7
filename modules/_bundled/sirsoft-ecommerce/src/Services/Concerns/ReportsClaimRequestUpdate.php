<?php

namespace Modules\Sirsoft\Ecommerce\Services\Concerns;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * 신청 내용 수정(D13·S15)의 전/후 비교 공용 규칙
 *
 * 교환·반품·환불 서비스의 `updateRequest()` 는 사유·상세 사유·환불 계좌(교환은 재발송지)를 고치고, 사유가
 * 바뀌면 누가 배송비를 내는지(귀책 → 회수·왕복비 → 청구액)까지 바뀐다. 이 수정이 흔적 없이 지나가면 운영자가
 * 고객 대신 사유를 바꾼 사실을 나중에 되짚을 근거가 없다 — 그래서 잠금 안에서 수정 전 값을 잡고, 쓰기 뒤
 * 값과 비교해 **실제로 바뀐 항목만** `claim.{type}.request_updated` 훅으로 내보낸다.
 *
 * 세 서비스가 같은 비교 규칙을 써야 활동 로그의 항목 구성이 유형마다 어긋나지 않는다. 그 유형에 없는
 * 컬럼(환불신청서의 배송비 등)은 전/후 모두 null 이라 변경으로 잡히지 않는다.
 *
 * ## 값을 싣지 않는 항목
 *
 * 환불 계좌·재발송지는 개인정보라 **바뀌었다는 사실만** 싣는다(전/후 모두 null + `masked`). 훅을 받는
 * 쪽(활동 로그·확장)이 계좌번호·주소를 저장하지 않도록 발행 지점에서 값을 떼어 낸다.
 */
trait ReportsClaimRequestUpdate
{
    /**
     * 신청 내용 수정의 비교 대상 컬럼과 값 정규화 방식.
     *
     * `money` 는 decimal 캐스트 문자열("3000.00")과 계산 결과(3000.0)가 서로 다른 값으로 잡히지 않도록
     * 실수로 맞춘다.
     *
     * @return array<string, string> 컬럼 => 정규화 방식 (value|money)
     */
    protected function claimRequestUpdateFields(): array
    {
        return [
            'reason_code' => 'value',
            'fault_type' => 'value',
            'reason_detail' => 'value',
            'claim_shipping_fee' => 'money',
            'total_charge_amount' => 'money',
            'charge_method' => 'value',
        ];
    }

    /**
     * 바뀐 사실만 싣는 항목과 그 항목을 이루는 컬럼.
     *
     * @return array<string, array<int, string>> 항목 => 컬럼 목록
     */
    protected function claimRequestUpdateMaskedGroups(): array
    {
        return [
            'refund_bank' => ['refund_bank_code', 'refund_bank_account', 'refund_bank_holder'],
            'redelivery_address' => ['redelivery_address_snapshot'],
        ];
    }

    /**
     * 신청서의 수정 대상 값을 비교용 스냅샷으로 잡습니다.
     *
     * 개인정보 항목의 원값도 비교를 위해 담기지만, 이 스냅샷은 메서드 안에서만 쓰이고 훅에는
     * `claimRequestUpdateChanges()` 가 값을 뗀 결과만 나간다.
     *
     * @param  Model  $claim  신청서
     * @return array{values: array<string, mixed>, masked: array<string, array<int, mixed>>} 스냅샷
     */
    protected function claimRequestUpdateSnapshot(Model $claim): array
    {
        $values = [];

        foreach ($this->claimRequestUpdateFields() as $field => $kind) {
            $value = $claim->getAttribute($field);

            if ($value instanceof BackedEnum) {
                $value = $value->value;
            } elseif ($kind === 'money' && $value !== null) {
                $value = (float) $value;
            }

            $values[$field] = $value;
        }

        $masked = [];

        foreach ($this->claimRequestUpdateMaskedGroups() as $group => $columns) {
            $masked[$group] = array_map(fn (string $column) => $claim->getAttribute($column), $columns);
        }

        return ['values' => $values, 'masked' => $masked];
    }

    /**
     * 두 스냅샷에서 실제로 바뀐 항목만 추립니다.
     *
     * @param  array{values: array<string, mixed>, masked: array<string, array<int, mixed>>}  $before  수정 전 스냅샷
     * @param  array{values: array<string, mixed>, masked: array<string, array<int, mixed>>}  $after  수정 후 스냅샷
     * @return array<string, array{before: mixed, after: mixed, masked?: bool}> 항목 => 전/후 (바뀐 것이 없으면 빈 배열)
     */
    protected function claimRequestUpdateChanges(array $before, array $after): array
    {
        $changes = [];

        foreach ($before['values'] as $field => $old) {
            $new = $after['values'][$field] ?? null;

            if ($old !== $new) {
                $changes[$field] = ['before' => $old, 'after' => $new];
            }
        }

        foreach ($before['masked'] as $group => $old) {
            if ($old != ($after['masked'][$group] ?? null)) {
                $changes[$group] = ['before' => null, 'after' => null, 'masked' => true];
            }
        }

        return $changes;
    }

    /**
     * 신청 내용 수정 훅의 부가 정보를 만듭니다.
     *
     * 작업자는 인증 사용자다 — 고객 수정이면 신청자, 운영자 대리 수정(S15)이면 운영자다. 비회원 수정은 null.
     *
     * @param  bool  $operator  운영자 대리 수정 여부
     * @return array{operator: bool, processed_by: int|null} 부가 정보
     */
    protected function claimRequestUpdateContext(bool $operator): array
    {
        $userId = Auth::id();

        return ['operator' => $operator, 'processed_by' => $userId !== null ? (int) $userId : null];
    }
}
