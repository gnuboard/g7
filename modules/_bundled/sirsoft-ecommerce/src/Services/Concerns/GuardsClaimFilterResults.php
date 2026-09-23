<?php

namespace Modules\Sirsoft\Ecommerce\Services\Concerns;

/**
 * 클레임 before·filter 훅의 필터 결과를 받아들이는 공용 규칙
 *
 * 클레임 서비스는 쓰기 **전에** `claim.{type}.before_{동작}`(action) → `claim.{type}.filter_{동작}_data`
 * (filter) 를 동기로 발화한다(#599 validate 01-#6). before 는 예외를 던져 동작을 막는 자리이고,
 * filter 는 확장이 입력을 바꾸는 자리다. 둘 다 트랜잭션·잠금 **전**(또는 호출자 트랜잭션 안에서
 * 쓰기 전)에 돌아야 막거나 바꾼 결과가 쓰기에 반영된다 — 커밋 뒤 발화(`FiresClaimHooksAfterCommit`)
 * 는 after 훅의 규칙이다.
 *
 * **filter 가 바꿀 수 있는 값은 화이트리스트다.** 신청 입력에는 확장이 바꾸면 안 되는 값이 섞여
 * 있다 — 신청자(`requested_by`·소유권), 처리자(`processed_by`), 접수 창구 축(`refund_source_type`·
 * `branch`·`gate_statuses`·`user_selectable_reason_only`·`pg_already_settled`), 돈의 경로
 * (`refund_priority`·`refund_bank`·`charge_method`). 필터가 이 값을 바꾸면 비회원 신청이 회원 소유로
 * 넘어가거나, 고객 창구 신청이 운영자 축으로 자격을 넓히거나, 환불금이 다른 계좌로 향한다. 그래서
 * 필터 결과에서 **허용 키만** 가져오고 나머지는 원래 값으로 되돌린다(필터가 새로 넣은 키도 버린다).
 *
 * 허용 키도 무검증으로 쓰지 않는다 — 필터 **뒤에** 고객 입력과 같은 검증을 그대로 지난다(사유는
 * 사유 해석, 대상 상품·수량은 잠금 안 수량 가드, 재발송지는 국가 불변 규칙). 금액은 필터 대상이
 * 아니다 — 금액은 서버가 대상·사유로 계산하는 값이라 입력으로 받지 않는다.
 */
trait GuardsClaimFilterResults
{
    /**
     * 신청 입력 중 확장이 바꿔도 되는 키 (`claim.{type}.filter_request_data`)
     *
     * - items: 대상 상품·수량 — 잠금 안 수량 가드(`ClaimQuantityGuard`)가 주문 소속·잔여 수량을 다시 잰다
     * - reason_code / reason_detail: 사유 — 사유 해석(`resolveReason`)이 활성·노출 여부를 다시 잰다
     * - redelivery_address: 교환 재발송지 — 국가 불변 규칙이 다시 잰다
     *
     * @var array<int, string>
     */
    protected const CLAIM_FILTERABLE_REQUEST_KEYS = ['items', 'reason_code', 'reason_detail', 'redelivery_address'];

    /**
     * 신청 수정 입력 중 확장이 바꿔도 되는 키 (`claim.{type}.filter_update_request_data`)
     *
     * 대상 상품은 수정 대상이 아니므로 빠진다. 청구 방식·환불 계좌는 돈의 경로라 뺀다.
     *
     * @var array<int, string>
     */
    protected const CLAIM_FILTERABLE_UPDATE_KEYS = ['reason_code', 'reason_detail', 'redelivery_address'];

    /**
     * 상태 전이 컨텍스트 중 확장이 바꿔도 되는 키 (`claim.{type}.filter_status_change_data`)
     *
     * 목표 상태는 필터 대상이 아니다 — 목표 상태는 호출층이 미수납 게이트(승인 → 결제 요청 우회)·
     * 종결 정산(반려·철회 → 돈 닫기)을 이미 판정한 값이라, 전이 직전에 바뀌면 그 판정을 건너뛴다.
     * 처리자(`processed_by`)·알림 억제 플래그도 되돌린다.
     *
     * @var array<int, string>
     */
    protected const CLAIM_FILTERABLE_STATUS_CONTEXT_KEYS = ['admin_memo', 'reject_reason'];

    /**
     * 필터 결과에서 허용 키만 받아들이고 나머지는 원래 값으로 되돌립니다.
     *
     * 필터가 배열이 아닌 값을 돌려주면(리스너 실수) 원래 입력을 그대로 쓴다. 허용 키를 필터가
     * 지웠으면 그 키는 입력에서도 빠진다(「보내지 않음」으로 해석된다).
     *
     * @param  mixed  $filtered  필터가 돌려준 값
     * @param  array<string, mixed>  $original  필터 전 입력
     * @param  array<int, string>  $filterableKeys  확장이 바꿔도 되는 키 목록
     * @return array<string, mixed> 허용 키만 반영한 입력
     */
    protected function acceptClaimFilterResult(mixed $filtered, array $original, array $filterableKeys): array
    {
        if (! is_array($filtered)) {
            return $original;
        }

        $accepted = array_diff_key($original, array_flip($filterableKeys));

        foreach ($filterableKeys as $key) {
            if (array_key_exists($key, $filtered)) {
                $accepted[$key] = $filtered[$key];
            }
        }

        return $accepted;
    }
}
