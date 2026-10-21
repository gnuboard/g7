<?php

namespace Tests\Feature\Console;

use App\Support\UpdateChildEnv;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 코어 업데이트 트리 안에서 새 코드가 띄우는 자식 프로세스(번들 일괄 업데이트, 언어팩 단계, 훅 캐시 새 프로세스)가
 * APP_VERSION 과 업데이트 플래그를 명시적으로 넘기는지 검증합니다 (#145 F10).
 *
 * 지금은 새 부모가 자기 env 를 올바르게 맞추므로 안전하지만, 상속 env 에만 기대면 부모 쪽 결함 하나로 다시
 * 옛 버전이 config 캐시에 구워진다. 업그레이드 스텝 자식(`spawnUpgradeStepsProcess`)과 같은 모양으로 고정한다.
 * 업데이트 트리 밖(확장 롤백의 훅 캐시 복구 등)에서는 고정하지 않는다 — 그때 고정할 값은 config 뿐이라 이미
 * 오염된 config 를 자식에게 퍼뜨릴 뿐이다.
 */
class BundledSpawnPinsAppVersionTest extends TestCase
{
    /** @var array{version: string|false, flag: string|false, argv: array<int, string>} */
    private array $original;

    /**
     * 프로세스 env 와 argv 의 원래 값을 기억합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->original = [
            'version' => getenv('APP_VERSION'),
            'flag' => getenv('G7_UPDATE_IN_PROGRESS'),
            'argv' => $_SERVER['argv'] ?? [],
        ];
        $_SERVER['argv'] = ['artisan', 'inspire'];
    }

    /**
     * 프로세스 env 와 argv 를 원래 값으로 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach (['APP_VERSION' => $this->original['version'], 'G7_UPDATE_IN_PROGRESS' => $this->original['flag']] as $key => $value) {
            unset($_ENV[$key], $_SERVER[$key]);
            if ($value === false) {
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv($key.'='.$value);
            }
        }
        $_SERVER['argv'] = $this->original['argv'];

        parent::tearDown();
    }

    /**
     * 프로세스 env 한 키를 세 채널에 세우거나 지웁니다.
     *
     * @param  string  $key  환경변수 이름
     * @param  string|null  $value  값 (null 이면 제거)
     * @return void 반환값 없음
     */
    private function setProcessEnv(string $key, ?string $value): void
    {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
        if ($value !== null) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }
    }

    /**
     * 업데이트 트리 안(env 플래그)에서는 현재 코어 버전과 플래그를 고정한다.
     *
     * @scenario case=flag_channel
     *
     * @effects update_child_env_pins_app_version_and_flag
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_pins_version_and_flag_inside_update_tree(): void
    {
        $this->setProcessEnv('G7_UPDATE_IN_PROGRESS', '1');
        $this->setProcessEnv('APP_VERSION', '7.1.1');

        $env = UpdateChildEnv::build();

        $this->assertSame('7.1.1', $env['APP_VERSION']);
        $this->assertSame('1', $env['G7_UPDATE_IN_PROGRESS']);
    }

    /**
     * argv 로만 업데이트 트리가 판정돼도(플래그 미전파) 자식에는 플래그를 세운다.
     *
     * @scenario case=argv_channel
     *
     * @effects update_child_env_pins_app_version_and_flag
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_pins_flag_when_tree_detected_by_argv(): void
    {
        $this->setProcessEnv('G7_UPDATE_IN_PROGRESS', null);
        $this->setProcessEnv('APP_VERSION', '7.1.1');
        $_SERVER['argv'] = ['artisan', 'core:update'];

        $env = UpdateChildEnv::build();

        $this->assertSame('7.1.1', $env['APP_VERSION']);
        $this->assertSame('1', $env['G7_UPDATE_IN_PROGRESS']);
    }

    /**
     * 업데이트 트리 밖에서는 상속 env 를 그대로 넘기고 아무것도 고정하지 않는다.
     *
     * @scenario case=outside_tree
     *
     * @effects update_child_env_untouched_outside_tree
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_leaves_env_untouched_outside_update_tree(): void
    {
        $this->setProcessEnv('G7_UPDATE_IN_PROGRESS', null);
        $this->setProcessEnv('APP_VERSION', null);
        config()->set('app.version', '7.0.0');

        $env = UpdateChildEnv::build();

        $this->assertArrayNotHasKey('APP_VERSION', $env);
        $this->assertArrayNotHasKey('G7_UPDATE_IN_PROGRESS', $env);
    }

    /**
     * 세 실행 지점이 모두 이 조립 함수로 자식 env 를 만든다.
     *
     * @scenario case=call_sites
     *
     * @effects all_update_child_spawns_use_pinned_env
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_all_spawn_points_use_the_builder(): void
    {
        $sites = [
            'app/Console/Commands/Core/Concerns/BundledExtensionUpdatePrompt.php',
            'app/Console/Commands/Core/ExecuteBundledUpdatesCommand.php',
            'app/Support/HookCacheFreshProcess.php',
        ];

        foreach ($sites as $site) {
            $source = (string) file_get_contents(base_path($site));
            $this->assertStringContainsString('UpdateChildEnv::build()', $source, "{$site} 는 자식 env 를 UpdateChildEnv::build() 로 만들어야 한다");
            $this->assertStringNotContainsString('array_merge(getenv(), $_ENV)', $source, "{$site} 에 상속 env 를 그대로 넘기는 조립이 남아 있다");
        }
    }
}
