<?php

namespace Plugins\Sirsoft\Sso\Bootstrap\Listeners;

use Plugins\Sirsoft\Sso\Listeners\CleanSsoAccountsOnUserLeave;
use Plugins\Sirsoft\Sso\Listeners\ProvideNecessaryStorageKeysListener;
use Plugins\Sirsoft\Sso\Listeners\RegisterSsoReauthProviderListener;

/**
 * 훅 리스너 — 로그인, 가입, 연결 흐름과 회원 탈퇴, 삭제 정리 영역.
 *
 * `Bootstrap\Listeners::all()` 이 영역별 목록을 합친다. 이 영역의 목록은 이 파일에만 둔다.
 *
 * @since 1.0.0
 */
final class CoreFlow
{
    /**
     * 이 영역의 훅 리스너 목록을 반환합니다.
     *
     * @return array<int, class-string> 리스너 클래스 목록
     */
    public static function all(): array
    {
        return [
            CleanSsoAccountsOnUserLeave::class,
            RegisterSsoReauthProviderListener::class,
            ProvideNecessaryStorageKeysListener::class,
        ];
    }
}
