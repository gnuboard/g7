<?php

namespace App\Support;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * config 캐시(bootstrap/cache/config.php) 재빌드 헬퍼.
 *
 * config 의 소스(config/*.php, .env, storage/app/settings/*.json, 활성 확장 목록)를
 * 변경하는 모든 라이프사이클 지점이 이 헬퍼를 호출해 "변경 반영 + 캐시 재최적화"를
 * 일관되게 수행한다. 산발적으로 config:clear/config:cache 를 각 지점에 뿌리면
 * 누락이 생겨(설정 저장은 clear 만, 확장 설치는 clear 조차 안 함) config:cache 가
 * 한 번 비워진 뒤 재생성되지 않아 성능 이점이 영구히 사라진다 — 이를 단일 SSoT 로 막는다.
 *
 * 정책: 환경 무관 항상 재생성. config:cache 는 그 자체로 부팅 비용을
 * 절감하고, G7 설정은 config 캐시에 박제되지 않고 부팅 때 SettingsServiceProvider /
 * CoreServiceProvider 의 런타임 Config::set() 으로 재주입되므로 항상 켜두는 것이 이득이다.
 *
 * 주의: "매 요청 재주입되므로 stale 없음" 은 FPM 전제였다. 큐 워커·schedule:work·Reverb
 * 처럼 프로세스가 상주하는 환경에서는 부팅이 한 번뿐이라 저장 후에도 옛 값이 남는다.
 * 그래서 저장 경로가 `ExtensionSettingsMirror` 로 in-memory 미러를 직접 다시 채운다
 * (docs/backend/admin-settings-access.md "config 미러 갱신 시점" 참조). local 개발 시 config/*.php 수정이 즉시 반영되지 않는 점은
 * 개발자가 `php artisan config:clear` 로 대응하는 개발자 책임 영역이다.
 */
class ConfigCacheHelper
{
    /**
     * config 캐시를 비우고 즉시 재생성합니다.
     *
     * 설치 미완료(installer 실행 전) 환경에서는 config:cache 가 불완전한 부팅을
     * 캐시에 박제해 부팅 실패를 유발할 수 있으므로, clear 만 수행하고 재생성은 건너뛴다.
     * 테스트 환경(APP_ENV=testing)은 tests/bootstrap.php 가 config 캐시를 자동 삭제하며
     * 캐시 생성이 테스트 격리를 깨므로 no-op 로 스킵한다.
     *
     * 재생성 실패(권한/디스크 등)는 치명적이지 않다 — config:clear 로 stale 캐시는
     * 이미 제거되어 다음 요청이 fresh config 로 안전하게 부팅되므로, 경고만 남기고 넘어간다.
     */
    public static function rebuild(): void
    {
        // 항상 stale 캐시부터 제거 (값 반영 보장). testing 환경에서도 clear 는 수행한다 —
        // 캐시 파일이 없으면 사실상 no-op 이고 격리를 깨지 않으며, 설정 저장 후 값 반영을
        // 검증하는 기존 테스트(SettingsServiceConfigClearTest) 계약을 유지한다.
        Artisan::call('config:clear');

        // config:cache 생성은 격리를 깨므로(캐시된 config 가 다음 테스트로 누출) testing 에서
        // 스킵한다. 설치 미완료 상태에서도 불완전 config 박제를 피하려 재생성하지 않는다.
        if (app()->environment('testing') || ! self::isInstalled()) {
            return;
        }

        try {
            self::withPreservedContainer(static fn () => Artisan::call('config:cache'));
        } catch (\Throwable $e) {
            Log::warning('config 캐시 재생성 실패 (config:clear 로 stale 은 제거됨 — 다음 요청은 비캐시 부팅)', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 콜백 실행 뒤 전역 컨테이너 인스턴스와 파사드 애플리케이션을 원래 앱으로 되돌립니다.
     *
     * `config:cache` 는 신선한 설정을 얻기 위해 **새 Application 을 부팅**하는데, 그 부팅이 전역 상태를
     * 두 군데 바꾼다.
     *
     * 1. `Application` 생성자의 `Container::setInstance()` — 그 순간부터 `app()` 헬퍼가 실행 중인 앱이
     *    아니라 일회용 앱을 가리킨다. 같은 프로세스에서 그 뒤에 등록되는 `app()->terminating()` 콜백은
     *    종료되지 않는 앱에 걸려 **영원히 실행되지 않는다** — 설정 저장 뒤의 확장 캐시 버전 bump 가
     *    예약한 정적 재게시가 그렇게 조용히 사라졌다(#651 F5 실측: 버전은 올랐는데 게시는 다음 렌더의
     *    자가 치유까지 미뤄짐).
     * 2. `registerBaseBindings()` 의 `Facade::clearResolvedInstances()` + `Facade::setFacadeApplication()`
     *    — 그 순간부터 **모든 파사드**(`Artisan`·`Log`·`DB`·`Cache` …)가 일회용 앱에서 새 인스턴스를
     *    해석한다. 컨테이너만 되돌리면 이 축이 남는다.
     *
     * 2번이 코어 업데이트에서 드러난 형태: Step 11 의 `ConfigCacheHelper::rebuild()` 뒤에 오는
     * `RouteCacheHelper::rebuild()` 의 `Artisan::call('route:clear')` 가 일회용 앱에서 **새 콘솔 Kernel** 을
     * 해석하고, 그 Kernel 은 콘솔 Application 을 처음부터 구성하며 등록된 커맨드를 전부 resolve 한다.
     * 부팅 시점 vendor 가 개발용이었다면 그 목록에 `command.tinker` 같은 require-dev 커맨드가 들어
     * 있는데, Step 8 이 vendor 를 `--no-dev` 로 이미 교체했으므로 그 클래스가 없어
     * `Target class [command.tinker] does not exist.` 로 업데이트 전체가 실패·롤백한다
     * (2026-09-07 7.0.9 → 7.0.11 실측). 프로세스에 이미 등록된 provider 목록은 vendor 교체로 갱신되지
     * 않으므로, 방어는 "교체 뒤에 콘솔 Application 을 다시 구성하지 않는 것" 이다.
     *
     * @param  callable  $callback  전역 인스턴스를 바꿔 놓을 수 있는 작업
     */
    public static function withPreservedContainer(callable $callback): void
    {
        $app = Container::getInstance();
        $facadeApp = Facade::getFacadeApplication();

        try {
            $callback();
        } finally {
            Container::setInstance($app);

            // 일회용 앱이 남긴 파사드 해석 결과를 버리고 원래 앱으로 되돌린다.
            // 순서 주의: clear 를 먼저 해야 일회용 앱에서 해석된 인스턴스가 캐시에 남지 않는다.
            Facade::clearResolvedInstances();
            if ($facadeApp !== null) {
                Facade::setFacadeApplication($facadeApp);
            }
        }
    }

    /**
     * config 캐시만 제거합니다 (재생성 없음).
     *
     * 재생성이 부적절한 특수 경로(예: 설치 직전 초기화)를 위한 보조 진입점.
     */
    public static function clear(): void
    {
        Artisan::call('config:clear');
    }

    /**
     * G7 설치 완료 여부를 확인합니다.
     *
     * config('app.installer_completed') 는 config:clear 직후 재로드되어 신뢰 가능하며,
     * 보조로 storage/app/g7_installed 플래그 파일도 확인한다.
     *
     * @return bool 설치 완료 시 true
     */
    private static function isInstalled(): bool
    {
        if (config('app.installer_completed')) {
            return true;
        }

        return File::exists(storage_path('app/g7_installed'));
    }
}
