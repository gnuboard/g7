<?php

namespace Tests\Unit\Extension;

use App\Extension\CoreVersionChecker;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CoreVersionChecker::getCoreVersion() 의 env 우선 판독 **범위** 회귀 테스트.
 *
 * 두 방향의 회귀를 함께 잠근다.
 *
 * 1. 업데이트 트리 **안**: spawn 프로세스(proc_open)가 `APP_VERSION={toVersion}` env 를 전달하지만
 *    `bootstrap/cache/config.php` 가 있으면 `LoadConfiguration` 이 캐시된 리터럴을 써서 env
 *    오버라이드가 반영되지 않는다. 여기서는 env 가 우선해야 확장이 자동 비활성화되지 않는다.
 * 2. 업데이트 트리 **밖**: `php artisan serve` · 큐 워커처럼 오래 사는 프로세스는 기동 시점의
 *    `APP_VERSION` 을 물고 있다. 업데이트가 끝나도 그 프로세스만 옛 버전으로 판정해, 새 코어를
 *    요구하는 확장을 `incompatible_core` 로 꺼 버린다(관리자 템플릿이 꺼지면 복구 UI 도 사라진다 —
 *    2026-09-07 실측). 여기서는 config 가 유일한 근거여야 한다.
 */
class CoreVersionCheckerEnvPriorityTest extends TestCase
{
    private ?string $originalEnvVersion = null;

    private ?string $originalUpdateFlag = null;

    /** @var array<int, string> */
    private array $originalArgv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnvVersion = $_ENV['APP_VERSION'] ?? null;

        $flag = $_ENV['G7_UPDATE_IN_PROGRESS'] ?? $_SERVER['G7_UPDATE_IN_PROGRESS'] ?? getenv('G7_UPDATE_IN_PROGRESS');
        $this->originalUpdateFlag = ($flag === false || $flag === null) ? null : (string) $flag;

        $this->originalArgv = $_SERVER['argv'] ?? [];

        // 각 테스트가 트리 안/밖을 명시적으로 세우도록 기본은 "트리 밖" 으로 둔다.
        $this->setUpdateFlag(false);
        $_SERVER['argv'] = ['artisan', 'inspire'];
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

        if ($this->originalUpdateFlag === null) {
            $this->setUpdateFlag(false);
        } else {
            $_ENV['G7_UPDATE_IN_PROGRESS'] = $this->originalUpdateFlag;
            $_SERVER['G7_UPDATE_IN_PROGRESS'] = $this->originalUpdateFlag;
            putenv('G7_UPDATE_IN_PROGRESS='.$this->originalUpdateFlag);
        }

        $_SERVER['argv'] = $this->originalArgv;

        parent::tearDown();
    }

    /**
     * 업데이트 트리 안에서는 env APP_VERSION 이 config 보다 우선한다.
     *
     * @effects getCoreVersion_prefers_env_APP_VERSION_only_inside_update_tree
     */
    #[Test]
    public function test_env_app_version_overrides_config(): void
    {
        $this->setUpdateFlag(true);
        config()->set('app.version', '7.0.0-beta.1');  // 캐시된 stale 값 시뮬레이션
        $_ENV['APP_VERSION'] = '7.0.0-beta.2';         // spawn 이 전달한 toVersion

        $this->assertSame(
            '7.0.0-beta.2',
            CoreVersionChecker::getCoreVersion(),
            '업데이트 트리 안에서는 env APP_VERSION 이 config 보다 우선해야 합니다.'
        );
    }

    /**
     * 업데이트 트리 안이어도 env 가 없으면 config 로 내려간다.
     *
     * @effects getCoreVersion_falls_back_to_config_when_env_absent_inside_update_tree
     */
    #[Test]
    public function test_falls_back_to_config_when_env_missing(): void
    {
        $this->setUpdateFlag(true);
        unset($_ENV['APP_VERSION'], $_SERVER['APP_VERSION']);
        putenv('APP_VERSION');
        config()->set('app.version', '7.0.0-beta.1');

        $this->assertSame(
            '7.0.0-beta.1',
            CoreVersionChecker::getCoreVersion(),
            'env 가 없으면 config 값을 반환해야 합니다.'
        );
    }

    /**
     * spawn 자식 시나리오: 캐시된 config 는 fromVersion, env 는 toVersion → 확장이 살아남는다.
     */
    #[Test]
    public function test_spawn_scenario_cached_config_with_env_override(): void
    {
        // spawn 환경 시뮬레이션: config 는 fromVersion 으로 캐시, env 는 toVersion
        $this->setUpdateFlag(true);
        config()->set('app.version', '7.0.0-beta.1');
        $_ENV['APP_VERSION'] = '7.0.0-beta.2';

        // 확장이 >=7.0.0-beta.2 요구 — env 기반 판정이어야 호환성 통과
        $this->assertTrue(
            CoreVersionChecker::isCompatible('>=7.0.0-beta.2'),
            'spawn 에서 env 로 전달된 toVersion 기준으로 호환성이 평가되어 확장이 자동 비활성화되지 않아야 합니다.'
        );
    }

    /**
     * 업데이트 트리 **밖**에서는 프로세스 env 의 옛 APP_VERSION 을 무시하고 config 를 쓴다.
     *
     * 업데이트 전에 띄운 `php artisan serve` 가 `7.0.9` 를 물고 있는 상황을 시뮬레이션한다.
     * env 를 그대로 믿으면 `>=7.0.10` 을 요구하는 관리자 템플릿이 비활성화되어 화면이 통째로
     * 사라진다 — 그 상태에서는 복구 UI 에도 도달할 수 없다.
     *
     * @effects getCoreVersion_ignores_env_APP_VERSION_outside_update_tree
     */
    #[Test]
    public function test_env_app_version_is_ignored_outside_update_tree(): void
    {
        $this->setUpdateFlag(false);
        config()->set('app.version', '7.0.10');   // 업데이트가 끝나 .env·config 는 새 버전
        $_ENV['APP_VERSION'] = '7.0.9';           // 기동 시점 env 를 물고 있는 상주 프로세스
        $_SERVER['APP_VERSION'] = '7.0.9';
        putenv('APP_VERSION=7.0.9');

        $this->assertSame(
            '7.0.10',
            CoreVersionChecker::getCoreVersion(),
            '업데이트 트리 밖에서는 config 버전이 근거여야 합니다.'
        );

        $this->assertTrue(
            CoreVersionChecker::isCompatible('>=7.0.10'),
            '상주 프로세스의 옛 env 때문에 새 코어를 요구하는 확장이 비활성화되면 안 됩니다.'
        );
    }

    /**
     * env 플래그가 전파되지 않아도 argv 가 업데이트 커맨드면 env 우선이 켜진다.
     */
    #[Test]
    public function test_argv_update_command_enables_env_priority(): void
    {
        $this->setUpdateFlag(false);
        $_SERVER['argv'] = ['artisan', 'core:execute-upgrade-steps', '--from=7.0.9', '--to=7.0.10'];

        config()->set('app.version', '7.0.9');
        $_ENV['APP_VERSION'] = '7.0.10';

        $this->assertSame(
            '7.0.10',
            CoreVersionChecker::getCoreVersion(),
            'argv 보조 판정으로도 업데이트 트리로 인식해 env 를 우선해야 합니다.'
        );
    }

    /**
     * 업데이트 트리 플래그를 세 채널($_ENV/$_SERVER/putenv)에 세우거나 지웁니다.
     *
     * @param  bool  $enabled  true 면 플래그 설정, false 면 세 채널 모두 제거
     */
    private function setUpdateFlag(bool $enabled): void
    {
        if (! $enabled) {
            unset($_ENV['G7_UPDATE_IN_PROGRESS'], $_SERVER['G7_UPDATE_IN_PROGRESS']);
            putenv('G7_UPDATE_IN_PROGRESS');

            return;
        }

        $_ENV['G7_UPDATE_IN_PROGRESS'] = '1';
        $_SERVER['G7_UPDATE_IN_PROGRESS'] = '1';
        putenv('G7_UPDATE_IN_PROGRESS=1');
    }
}
