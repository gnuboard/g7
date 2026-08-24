<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Services;

/**
 * 발송 사이클 내 refkey ↔ 코어 알림 로그 연결을 잇는 request-scoped 컨텍스트 (A-2).
 *
 * 코어 알림 발송 한 사이클의 실행 순서는 다음과 같다(코드 확인):
 *   before_channel_send
 *     → 채널 드라이버 send() : bizppurio_dispatch pending 생성(refkey 부여) → 여기에 refkey 기록
 *   after_channel_send
 *     → 코어 NotificationLogListener : NotificationLog 생성(id 부여)
 *       → core.notification_log.after_log_sent / after_log_failed 훅
 *         → LinkNotificationLogListener : 여기서 최근 refkey 를 꺼내 그 dispatch 에 log id 기록
 *
 * 드라이버(send)와 링크 리스너(after_log_sent)는 같은 채널 발송 사이클 안에서 순차 실행되므로,
 * "가장 최근에 부여한 refkey" 를 그 로그와 1:1 로 연결할 수 있다. 복합키(type+user+channel+
 * 시각) 근사 매칭은 동일 시각 중복 발송 시 오매칭 위험이 있어 쓰지 않는다.
 *
 * 컨테이너에 싱글턴으로 바인딩되어 한 HTTP 요청/큐 잡 안에서만 상태를 공유한다.
 * remember() 로 refkey 를 넣고 consume() 로 꺼낸다(꺼내면 비운다 — 다음 사이클로 새지 않게).
 */
class DispatchLinkContext
{
    /** 이번 발송 사이클에서 드라이버가 방금 부여한 refkey (없으면 null). */
    private ?string $pendingRefkey = null;

    /**
     * 드라이버가 dispatch pending 을 만든 직후 그 refkey 를 기록합니다.
     *
     * @param  string  $refkey  방금 부여한 refkey
     */
    public function remember(string $refkey): void
    {
        $this->pendingRefkey = $refkey;
    }

    /**
     * 링크 리스너가 최근 refkey 를 꺼냅니다 (꺼내면 비웁니다).
     *
     * 비-비즈뿌리오 채널(mail/database 등)의 로그가 발화한 경우엔 이 사이클에서 remember 된
     * refkey 가 없으므로 null 을 반환한다(그 로그는 연결 대상 아님).
     *
     * @return string|null 최근 refkey 또는 null
     */
    public function consume(): ?string
    {
        $refkey = $this->pendingRefkey;
        $this->pendingRefkey = null;

        return $refkey;
    }
}
