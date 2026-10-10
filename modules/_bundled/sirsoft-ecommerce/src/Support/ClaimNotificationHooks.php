<?php

namespace Modules\Sirsoft\Ecommerce\Support;

/**
 * 클레임 알림 전용 훅 이름의 단일 출처
 *
 * 알림 훅은 **도메인 훅과 다른 축**이다 — 도메인 훅(`claim.{type}.status_changed`)은 "무슨 일이
 * 일어났는가" 를 말하고, 알림 훅(`claim.{type}.notify_{key}`)은 "그 사건을 알릴 차례다" 를
 * 말한다. 자동 전이의 중간 단계처럼 사건은 있었으나 알리지 않는 조합이 있어 둘이 갈린다.
 *
 * ## 왜 리스너 클래스에 두지 않는가
 *
 * 발화하는 쪽(서비스)과 구독하는 쪽(리스너·정의 선언)이 같은 문자열을 읽어야 하는데,
 * 서비스가 리스너를 import 하면 "훅 구독 전용" 경계가 깨진다(리스너는 `HookManager` 가
 * 자동 인스턴스화하는 대상이지 협력자가 아니다). 문자열을 양쪽에 리터럴로 적으면 한쪽만
 * 고쳐졌을 때 **알림이 조용히 안 나간다** — 예외도 로그도 없다.
 */
class ClaimNotificationHooks
{
    /** 알림 전용 훅 접두 (도메인 훅과 구분) */
    public const PREFIX = 'sirsoft-ecommerce.claim.';

    /** 철회 알림(관리자 수신) 훅 — 유형 구분 없이 한 종 */
    public const WITHDRAWN = 'sirsoft-ecommerce.claim.notify_withdrawn';

    /**
     * 대리 철회 안내(고객 수신) 훅 — 유형 구분 없이 한 종.
     *
     * 운영자가 고객 요청으로 대신 철회(S14 — `withdrawn_by_admin` 기록)했을 때만 나간다. 고객이 스스로
     * 철회했으면 그 사실을 본인에게 되돌려 보낼 이유가 없지만, 대신 철회는 고객이 한 일이 아니라서
     * 이 알림이 없으면 고객은 자기 신청이 왜 사라졌는지 알 길이 없다.
     */
    public const WITHDRAWN_ON_BEHALF = 'sirsoft-ecommerce.claim.notify_withdrawn_on_behalf';

    /**
     * 부분 처리 안내(고객 수신) 훅 — 유형 구분 없이 한 종.
     *
     * 운영자가 신청 상품 중 일부만 처리하면 헤더 상태가 그대로라 기존 상태 알림이 발화하지
     * 않는다. 그때 고객이 자기 신청이 어떻게 갈렸는지 알 수 있는 유일한 통로다.
     */
    public const PARTIALLY_PROCESSED = 'sirsoft-ecommerce.claim.notify_partially_processed';

    /**
     * 상품 단위 전이가 끝났다는 **도메인 사실** 훅.
     *
     * 알림 훅(`notify_*`)이 아니다 — 「보낼 것인가」를 정하지 않고 「무슨 일이 있었는가」만
     * 알린다. 판정은 알림 축(`ClaimStatusNotificationListener`)이 단독으로 한다. 서비스가
     * 직접 `notify_partially_processed` 를 쏘면 알림 발화 판정이 두 곳에 생기고, 규칙이
     * 바뀔 때 한쪽만 고쳐진다.
     *
     * 인자: `($claim, array $result, string $target)`
     */
    public const ITEMS_TRANSITIONED = 'sirsoft-ecommerce.claim.items_transitioned';

    /**
     * 유형·알림키로 상태 알림 훅 이름을 만듭니다.
     *
     * @param  string  $claimType  클레임 유형 값 (`exchange` / `return` / `refund`)
     * @param  string  $key  알림 키 접미 (`requested` / `approved` 등)
     * @return string 훅 이름
     */
    public static function forStatus(string $claimType, string $key): string
    {
        return self::PREFIX.$claimType.'.notify_'.$key;
    }
}
