<?php

namespace App\Services\Auth;

use App\Extension\Auth\LoginDiscoveryResult;
use App\Extension\HookManager;
use App\Support\SameOriginPath;
use Illuminate\Support\Facades\Log;

/**
 * 로그인 진입 판정 — 이메일 먼저 입력 화면의 수단 판정과 로그인 화면 자동 이동.
 *
 * 둘 다 확장이 필터로 답하고 코어는 모양과 이동 경로만 지킨다(같은 출처 경로만, 회원 존재 여부와 무관한 응답 모양).
 *
 * @since 7.2.0
 */
class LoginEntryService
{
    /**
     * 식별자의 로그인 수단을 판정합니다 — 필터 `core.auth.login_discovery`.
     *
     * @param  string  $identifier  로그인 칸 입력
     * @param  string  $guard  로그인 경로 (user | admin)
     * @return array{method: string, label: array<string, string>|null, redirect_url: string|null} 응답 데이터
     */
    public function discover(string $identifier, string $guard): array
    {
        $result = HookManager::applyFilters('core.auth.login_discovery', null, $identifier, $guard);

        if ($result === null) {
            return LoginDiscoveryResult::password()->toArray();
        }

        if (! $result instanceof LoginDiscoveryResult) {
            Log::warning('core.auth.login_discovery 필터가 LoginDiscoveryResult 가 아닌 값을 돌려줬습니다 — 비밀번호 로그인으로 처리합니다', [
                'type' => get_debug_type($result),
            ]);

            return LoginDiscoveryResult::password()->toArray();
        }

        return $result->toArray();
    }

    /**
     * 로그인 화면 자동 이동 경로를 돌려줍니다 — 필터 `core.auth.login_auto_redirect`.
     *
     * @param  string  $guard  로그인 경로 (user | admin)
     * @return string|null 같은 출처 경로 (없으면 null)
     */
    public function autoRedirect(string $guard): ?string
    {
        return SameOriginPath::normalize(HookManager::applyFilters('core.auth.login_auto_redirect', null, $guard));
    }
}
