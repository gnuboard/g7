<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * 라우트 캐시(bootstrap/cache/routes-v*.php) 재빌드 헬퍼.
 *
 * 라우트의 소스(코어 routes/*.php, 활성 모듈·플러그인의 routes/api.php)를 변경하는
 * 모든 라이프사이클 지점이 이 헬퍼를 호출해 "변경 반영 + 캐시 재최적화"를 일관되게
 * 수행한다. 산발적으로 route:clear 를 각 지점에 뿌리면 누락이 생기고(확장 설치·활성화는
 * clear 조차 하지 않았다), 반대로 clear 만 하면 한 번 비워진 캐시가 재생성되지 않아
 * 성능 이점이 영구히 사라진다 — 이를 단일 SSoT 로 막는다.
 *
 * 라우트 캐시는 훅 캐시와 성질이 다르다. 훅 캐시는 항목이 없으면 스캔 폴백으로 동작해
 * 낡아도 안전하지만, **라우트 캐시에는 폴백이 없다** — 캐시에 없는 라우트는 그대로 404 다.
 * 오류도 경고도 남지 않고 그 엔드포인트만 조용히 사라지므로, 확장을 설치한 쪽에서는
 * "내 코드는 분명히 있는데 라우트가 없다" 는 형태로만 관측된다.
 *
 * 정책: 환경 무관 항상 재생성 (`ConfigCacheHelper` 와 동일). route:cache 는 그 자체로
 * 부팅 비용을 절감하고, 라우트는 배포 단위로 고정되므로 항상 켜두는 것이 이득이다.
 * local 개발 시 라우트 파일 수정이 즉시 반영되지 않는 점은 개발자가 `php artisan route:clear`
 * 로 대응하는 개발자 책임 영역이며, 확장 라우트는 `{module|plugin}:update` 가 이 헬퍼를
 * 경유하므로 그 경로에서는 자동 반영된다.
 */
class RouteCacheHelper
{
    /**
     * 라우트 캐시를 비우고 즉시 재생성합니다.
     *
     * `route:cache` 는 내부적으로 기존 캐시를 지운 뒤 새 애플리케이션 인스턴스를 부팅해
     * 라우트를 수집하므로, 방금 설치·활성화한 확장의 라우트도 함께 잡힌다.
     *
     * 설치 미완료(installer 실행 전) 환경에서는 불완전한 부팅을 캐시에 박제할 수 있으므로
     * clear 만 수행한다. 테스트 환경은 캐시 생성이 격리를 깨므로 동일하게 스킵한다.
     *
     * 재생성 실패(직렬화 불가한 클로저 라우트, 권한/디스크 등)는 치명적이지 않다 —
     * clear 로 stale 캐시는 이미 제거되어 다음 요청이 fresh 라우트로 안전하게 부팅된다.
     * 비어 있으면 느릴 뿐 정확하지만, 낡으면 방금 설치한 확장이 통째로 동작하지 않는다.
     *
     * @return void
     */
    public static function rebuild(): void
    {
        // 항상 stale 캐시부터 제거 (변경 반영 보장).
        Artisan::call('route:clear');

        if (app()->environment('testing') || ! self::isInstalled()) {
            return;
        }

        try {
            Artisan::call('route:cache');
        } catch (\Throwable $e) {
            Log::warning('라우트 캐시 재생성 실패 (route:clear 로 stale 은 제거됨 — 다음 요청은 비캐시 부팅)', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 라우트 캐시만 제거합니다 (재생성 없음).
     *
     * 재생성이 부적절한 특수 경로(예: 코어 업데이트 흐름 중간 — vendor 교체 중 상태를
     * 캐시에 구울 수 없다)를 위한 보조 진입점.
     *
     * @return void
     */
    public static function clear(): void
    {
        Artisan::call('route:clear');
    }

    /**
     * G7 설치 완료 여부를 확인합니다.
     *
     * @return bool 설치 완료 시 true
     */
    private static function isInstalled(): bool
    {
        if (config('app.installer_completed')) {
            return true;
        }

        return file_exists(storage_path('app/g7_installed'));
    }
}
