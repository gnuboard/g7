<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
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
 * 절감하고, G7 설정은 config 캐시에 박제되지 않고 매 요청 SettingsServiceProvider /
 * CoreServiceProvider 의 런타임 Config::set() 으로 재주입되므로(설정 stale 없음),
 * 항상 켜두는 것이 이득이다. local 개발 시 config/*.php 수정이 즉시 반영되지 않는 점은
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
     *
     * @return void
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
            Artisan::call('config:cache');
        } catch (\Throwable $e) {
            Log::warning('config 캐시 재생성 실패 (config:clear 로 stale 은 제거됨 — 다음 요청은 비캐시 부팅)', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * config 캐시만 제거합니다 (재생성 없음).
     *
     * 재생성이 부적절한 특수 경로(예: 설치 직전 초기화)를 위한 보조 진입점.
     *
     * @return void
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
