<?php

namespace App\Contracts\Notifications;

/**
 * 알림 채널 발송 준비 상태 검증 인터페이스
 *
 * 각 채널의 설정 완료 여부를 사전 검증하여,
 * 미설정 채널은 발송 시도 자체를 건너뛰도록 합니다.
 * 플러그인은 core.notification.channel_readiness 필터 훅으로 체커를 확장할 수 있습니다.
 */
interface ChannelReadinessCheckerInterface
{
    /**
     * 채널이 발송 준비 상태인지 확인합니다.
     *
     * @param string $channelId 채널 식별자 (mail, database 등)
     * @return bool
     */
    public function isReady(string $channelId): bool;

    /**
     * 채널별 준비 상태와 사유를 반환합니다.
     *
     * @param string $channelId 채널 식별자
     * @return array{ready: bool, reason: string|null}
     */
    public function check(string $channelId): array;

    /**
     * 여러 채널의 준비 상태를 일괄 반환합니다 (관리자 UI용).
     *
     * @param array<string> $channelIds 채널 ID 배열
     * @return array<string, array{ready: bool, reason: string|null}>
     */
    public function checkAll(array $channelIds): array;
}
