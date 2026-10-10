<?php

namespace Tests\Helpers;

use App\Contracts\Notifications\ChannelReadinessCheckerInterface;

/**
 * 알림 채널 readiness 를 테스트에서 고정하는 헬퍼 Trait
 *
 * `GenericNotification::via()` 는 채널 선정 시 **readiness** 를 함께 본다
 * ([ChannelReadinessService](app/Services/ChannelReadinessService.php)). 그런데 그 판정은
 * `mail` 의 경우 SMTP 호스트·발신 주소 같은 **환경 설정**을 읽으므로, 설정이 비어 있는 테스트
 * 환경에서는 `mail_smtp_host_empty` 로 not-ready 가 되어 `via()` 가 빈 배열을 돌려준다.
 *
 * 그 결과 채널 선정 **로직**을 검증하려던 단언이 환경 설정 유무에 따라 갈린다 — 실제로
 * `GenericNotificationViaTest`·`GenericNotificationGuestGateTest` 5건이 이 이유로 실패했다.
 * readiness 자체는 `ChannelReadinessService` 가 별도로 책임지므로, via 로직 테스트는 이 축을
 * 고정해 두고 자기 관심사만 본다.
 */
trait MocksChannelReadiness
{
    /**
     * 모든 채널을 ready 로 고정합니다.
     */
    protected function mockAllChannelsReady(): void
    {
        $this->app->singleton(ChannelReadinessCheckerInterface::class, fn () => new class implements ChannelReadinessCheckerInterface
        {
            public function isReady(string $channelId): bool
            {
                return true;
            }

            /**
             * @return array{ready: bool, reason: string|null}
             */
            public function check(string $channelId): array
            {
                return ['ready' => true, 'reason' => null];
            }

            /**
             * @param  array<int, string>  $channelIds  채널 식별자 목록
             * @return array<string, array{ready: bool, reason: string|null}>
             */
            public function checkAll(array $channelIds): array
            {
                return array_fill_keys($channelIds, ['ready' => true, 'reason' => null]);
            }
        });
    }
}
