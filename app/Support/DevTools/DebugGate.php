<?php

namespace App\Support\DevTools;

use App\Contracts\Repositories\ConfigRepositoryInterface;

/**
 * DevTools 엔드포인트 접근 게이트 (디버그 모드 판정).
 *
 * 왜 별도 클래스인가:
 *   `routes/devtools.php` 의 게이트는 원래
 *   `config('app.debug') || \App\Models\Setting::getValue('debug.mode', false)` 였는데,
 *   **`App\Models\Setting` 은 저장소에 존재하지 않는다** (설정이 JSON 으로 이관되며 제거된 유물).
 *   `||` 단축평가에 가려져 드러나지 않았을 뿐이다 — `SettingsServiceProvider` 가 `debug.mode` 를
 *   `config('app.debug')` 로 동기화하므로 정상 경로에서는 두 번째 항이 평가되지 않는다.
 *
 *   그러나 settings JSON 의 `debug` 카테고리가 비면 그 동기화가 조기 반환하고, `.env` 의
 *   `APP_DEBUG=false` 와 만나 **403 이어야 할 응답이 `Class "App\Models\Setting" not found`
 *   500 이 된다.** 이 게이트의 두 번째 조건은 한 번도 동작한 적이 없다.
 *
 * 판정 SSoT 는 형제 컴포넌트인 `App\Http\Middleware\SyncBoostWithDebugMode` 가 쓰는
 * `ConfigRepositoryInterface::get('debug.mode', false)` 와 일치시킨다.
 */
class DebugGate
{
    /**
     * 디버그 모드 활성화 여부를 반환합니다.
     *
     * `.env` 의 `APP_DEBUG` 또는 관리자 환경설정의 `debug.mode` 중 하나라도 켜져 있으면 true.
     *
     * @return bool 디버그 모드 활성화 여부
     */
    public static function isEnabled(): bool
    {
        if ((bool) config('app.debug')) {
            return true;
        }

        return (bool) app(ConfigRepositoryInterface::class)->get('debug.mode', false);
    }
}
