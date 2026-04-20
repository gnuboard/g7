<?php

namespace Tests\Unit\Extension;

use App\Extension\CoreVersionChecker;
use Tests\TestCase;

/**
 * CoreVersionChecker::getCoreVersion() env 우선 읽기 회귀 테스트.
 *
 * 코어 업그레이드 중 spawn 프로세스(proc_open)가 `APP_VERSION={toVersion}` env 를 전달하지만
 * `bootstrap/cache/config.php` 가 존재하면 `LoadConfiguration` 이 캐시된 리터럴을 사용해
 * env 오버라이드가 반영되지 않는 회귀가 있었다. 본 테스트는 `getCoreVersion()` 이
 * env 를 우선 읽어 config cache 유무와 무관하게 spawn 이 전달한 버전을 신뢰함을 검증한다.
 */
class CoreVersionCheckerEnvPriorityTest extends TestCase
{
    private ?string $originalEnvVersion = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnvVersion = $_ENV['APP_VERSION'] ?? null;
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_VERSION'], $_SERVER['APP_VERSION']);
        if ($this->originalEnvVersion !== null) {
            $_ENV['APP_VERSION'] = $this->originalEnvVersion;
            $_SERVER['APP_VERSION'] = $this->originalEnvVersion;
            putenv('APP_VERSION='.$this->originalEnvVersion);
        } else {
            putenv('APP_VERSION');
        }
        parent::tearDown();
    }

    public function test_env_app_version_overrides_config(): void
    {
        config()->set('app.version', '7.0.0-beta.1');  // 캐시된 stale 값 시뮬레이션
        $_ENV['APP_VERSION'] = '7.0.0-beta.2';         // spawn 이 전달한 toVersion

        $this->assertSame(
            '7.0.0-beta.2',
            CoreVersionChecker::getCoreVersion(),
            'env APP_VERSION 이 config 보다 우선해야 합니다.'
        );
    }

    public function test_falls_back_to_config_when_env_missing(): void
    {
        unset($_ENV['APP_VERSION'], $_SERVER['APP_VERSION']);
        putenv('APP_VERSION');
        config()->set('app.version', '7.0.0-beta.1');

        $this->assertSame(
            '7.0.0-beta.1',
            CoreVersionChecker::getCoreVersion(),
            'env 가 없으면 config 값을 반환해야 합니다.'
        );
    }

    public function test_spawn_scenario_cached_config_with_env_override(): void
    {
        // spawn 환경 시뮬레이션: config 는 fromVersion 으로 캐시, env 는 toVersion
        config()->set('app.version', '7.0.0-beta.1');
        $_ENV['APP_VERSION'] = '7.0.0-beta.2';

        // 확장이 >=7.0.0-beta.2 요구 — env 기반 판정이어야 호환성 통과
        $this->assertTrue(
            CoreVersionChecker::isCompatible('>=7.0.0-beta.2'),
            'spawn 에서 env 로 전달된 toVersion 기준으로 호환성이 평가되어 확장이 자동 비활성화되지 않아야 합니다.'
        );
    }
}
