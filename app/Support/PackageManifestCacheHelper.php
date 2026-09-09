<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;

/**
 * 패키지 매니페스트 캐시(bootstrap/cache/packages.php · services.php) 정리·재생성 헬퍼.
 *
 * Laravel 의 `PackageManifest::getManifest()` 는 `packages.php` 가 존재하면 stale 여부를
 * 검사하지 않고 그대로 읽고, `ProviderRepository::load()` 가 거기 등재된 eager provider 를
 * `new` 한다. 그래서 vendor 를 교체한 뒤 그 파일을 남겨 두면 다음에 부팅하는 프로세스가
 * 새 vendor 에 없는 클래스를 찾다 **부팅 단계에서** 죽는다 — 앱 로그가 아직 열리기 전이라
 * 예외도 남지 않고, 부모 프로세스에는 자식의 비정상 종료로만 보인다.
 *
 * `ConfigCacheHelper`/`RouteCacheHelper` 와 같은 자리의 헬퍼로, vendor 를 바꾼 뒤 새 PHP
 * 프로세스를 띄우는 지점이 이 헬퍼를 경유한다. 삭제 로직을 호출부마다 `@unlink` 로 복제하면
 * 한 곳만 빠져도 그 경로가 조용히 옛 매니페스트로 부팅한다.
 */
class PackageManifestCacheHelper
{
    /**
     * 패키지 매니페스트 캐시 두 파일을 삭제합니다 (재생성 없음).
     *
     * 경로는 `Application` 의 게터로 읽어 `APP_PACKAGES_CACHE`/`APP_SERVICES_CACHE` 환경변수
     * 재지정을 존중한다(테스트 격리가 이 경로 재지정에 의존한다).
     *
     * 삭제 실패(권한 · 소유권 불일치 · Windows 파일 핸들 점유)는 예외로 올리지 않는다 — 부팅 직전에
     * 불리므로 실패가 흐름을 막으면 안 되고, 코어 업데이트의 마지막 단계(`clearAllCaches()`)가 다시
     * 시도한다. 대신 지우지 못한 파일의 경로를 돌려준다. spawn 직전 호출부는 그 목록을 업그레이드
     * 로그에 남긴다 — 그 상태면 자식의 자가 치유도 같은 권한으로 같은 이유로 실패해 증상은 이전
     * 설치본 provider 의 「Class not found」 그대로인데, 이 기록이 권한이 원인이라는 유일한 흔적이다.
     *
     * @return array<int, string> 삭제하지 못하고 남은 파일의 절대 경로. 전부 지웠거나 원래 없었으면 빈 배열
     */
    public static function clear(): array
    {
        $app = app();
        $remaining = [];

        foreach ([$app->getCachedServicesPath(), $app->getCachedPackagesPath()] as $path) {
            if (! is_file($path)) {
                continue;
            }

            if (! @unlink($path)) {
                clearstatcache(true, $path);
                if (is_file($path)) {
                    $remaining[] = $path;
                }
            }
        }

        clearstatcache();

        return $remaining;
    }

    /**
     * 패키지 매니페스트 캐시를 비우고 현재 vendor 기준으로 다시 만듭니다.
     *
     * `package:discover` 는 새 Application 을 부팅하지 않으므로(현재 앱의 `PackageManifest` 를
     * 그대로 쓴다) `ConfigCacheHelper::withPreservedContainer()` 로 감쌀 필요가 없다.
     *
     * @return void
     */
    public static function rebuild(): void
    {
        self::clear();

        Artisan::call('package:discover');
    }
}
