<?php

namespace Plugins\Sirsoft\Totp\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Enums\IdentityVerificationChannel;
use App\Enums\IdentityVerificationPurpose;

/**
 * login purpose 의 허용 채널에 `app` 을 더하는 filter 훅 listener.
 *
 * 코어 `IdentityVerificationManager::$corePurposes` 는 login 의 `allowed_channels` 를
 * `['email']` 로 선언한다 — 코어가 소유한 값이므로 플러그인이 직접 고치지 않고 필터로
 * 더한다. 이 채널이 없으면 관리자 「설정 > 본인인증」의 login 목적 행이 인증 앱 수단을
 * 고를 수 있는 것으로 렌더하지 않는다.
 *
 * @since 1.0.0
 */
class ExtendLoginPurposeChannels implements HookListenerInterface
{
    /**
     * 구독 훅 메타데이터.
     *
     * @return array<string, array<string, mixed>> 훅 정의
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.identity.purposes' => [
                'method' => 'extend',
                'priority' => 20,
                'type' => 'filter',
                'sync' => true,
            ],
        ];
    }

    /**
     * 인터페이스 표준 진입점 — getSubscribedHooks 가 method='extend' 를 명시하므로 미사용.
     *
     * @param  mixed  ...$args  사용 안 함
     * @return void
     */
    public function handle(...$args): void
    {
        // no-op — 실제 진입점은 extend() 메서드 (filter 훅)
    }

    /**
     * login purpose 의 allowed_channels 에 app 채널을 더합니다.
     *
     * 코어가 login 목적을 아직 싣지 않은 상태(순서 의존)면 아무것도 하지 않는다 —
     * 없는 목적을 이 플러그인이 만들어 내면 코어 레지스트리의 소유권이 흐려진다.
     *
     * @param  array<string, array<string, mixed>>  $purposes  현재 purpose 레지스트리
     * @return array<string, array<string, mixed>> app 채널이 더해진 레지스트리
     */
    public function extend(array $purposes): array
    {
        $key = IdentityVerificationPurpose::Login->value;

        if (! isset($purposes[$key]) || ! is_array($purposes[$key])) {
            return $purposes;
        }

        $channels = $purposes[$key]['allowed_channels'] ?? [];
        $channels = is_array($channels) ? $channels : [];

        $app = IdentityVerificationChannel::App->value;

        if (! in_array($app, $channels, true)) {
            $channels[] = $app;
        }

        $purposes[$key]['allowed_channels'] = array_values($channels);

        return $purposes;
    }
}
