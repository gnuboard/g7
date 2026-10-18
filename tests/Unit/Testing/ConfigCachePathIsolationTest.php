<?php

namespace Tests\Unit\Testing;

use Tests\TestCase;

/**
 * 테스트 프로세스의 config 캐시 경로 격리 테스트
 *
 * 테스트 부트스트랩은 시작 때 `bootstrap/cache/config.php` 를 지우지만, 테스트가 도는 사이 다른 프로세스
 * (같은 개발 클론에서 돌린 `plugin:update`·`module:update`·`template:update` — 확장 반영은 config 캐시를 다시 굽는다)
 * 가 그 파일을 다시 만들면 그 뒤에 만들어지는 테스트 앱은 캐시된 개발 config 로 부팅했다. 캐시된 config 는
 * `.env.testing` 을 무시하므로 테스트가 개발 DB 에 붙고(2026-10-08 00:50 실측: 게시판 테스트 setUp 의 정리 쿼리가
 * 개발 DB `g7_2` 에서 실행되다 FK 로 멈춤), 확장 저장소 디스크가 `storage/app/plugins` 를 가리켜 GDPR 설정 테스트가
 * 개발 사이트 설정 파일을 덮었다(2026-10-07 23:38 실측, O-M1F-A).
 *
 * 라우트 캐시와 같이 config 캐시 경로도 테스트 전용 위치로 돌려, 운영 캐시가 언제 다시 구워지든 테스트 앱이
 * 그것을 읽지 않게 한다.
 *
 * @scenario-manifest tests/scenarios/testing-config-cache-isolation.yaml
 */
class ConfigCachePathIsolationTest extends TestCase
{
    /**
     * 테스트 앱의 config 캐시 경로는 운영 캐시(`bootstrap/cache/config.php`)가 아니라 테스트 전용 위치다.
     *
     * @return void 반환값 없음
     *
     * @scenario producer=extension_update_during_run
     *
     * @effects test_app_never_reads_site_config_cache
     */
    public function test_config_cache_path_points_to_testing_storage(): void
    {
        $path = $this->slashed($this->app->getCachedConfigPath());

        $this->assertStringEndsWith('/storage/framework/testing/config.php', $path);
        $this->assertNotSame(
            $this->slashed($this->app->bootstrapPath('cache/config.php')),
            $path,
            '운영 config 캐시를 읽으면 다른 프로세스가 구운 개발 config(개발 DB·개발 저장소)로 테스트가 돈다'
        );
    }

    /**
     * 테스트 앱은 캐시된 config 가 아니라 `.env.testing` 으로 부팅하고, 확장 저장소 디스크는 테스트 경로다.
     *
     * @return void 반환값 없음
     *
     * @scenario producer=extension_update_during_run
     *
     * @effects test_app_never_reads_site_config_cache, extension_storage_stays_under_testing_root
     */
    public function test_test_app_boots_from_env_testing_with_testing_storage_roots(): void
    {
        $this->assertFalse($this->app->configurationIsCached());
        $this->assertSame('testing', $this->app->environment());

        foreach (['modules', 'plugins'] as $disk) {
            $root = $this->slashed((string) config("filesystems.disks.{$disk}.root"));
            $this->assertStringEndsWith("/storage/framework/testing/{$disk}", $root);
        }
    }

    /**
     * 경로 구분자를 `/` 로 맞춥니다 (Windows 경로 비교용).
     *
     * @param  string  $path  경로
     * @return string 구분자를 맞춘 경로
     */
    private function slashed(string $path): string
    {
        return strtr($path, DIRECTORY_SEPARATOR, '/');
    }
}
