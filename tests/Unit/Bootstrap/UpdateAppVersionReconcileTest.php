<?php

namespace Tests\Unit\Bootstrap;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 업데이트 트리 안 프로세스의 APP_VERSION 을 디스크 `.env` 기준으로 올리는 부트스트랩 함수 단위 테스트 (#145, #137).
 *
 * 7.0.0~7.0.10 부모 `core:update` 는 `.env` 의 APP_VERSION 만 바꾸고 자기 프로세스 env 는 옛 값으로 둔다. 그 env 를
 * 물려받은 새 코드 자식(번들 일괄 업데이트, 언어팩 단계)과 부모 안의 `config:cache` 일회용 앱이 옛 버전을
 * `bootstrap/cache/config.php` 에 굽고, 업데이트가 끝난 뒤 첫 프로세스가 `>=7.1.0` 확장을 자동 비활성화했다.
 * 옛 부모 코드는 고칠 수 없으므로 새 `bootstrap/app.php` 가 이 함수로 env 를 디스크 값에 맞춘다.
 *
 * 규칙: 올리기만 한다 — 업그레이드 스텝 자식은 의도적으로 `.env`(fromVersion)보다 높은 toVersion 을 받는다.
 */
class UpdateAppVersionReconcileTest extends TestCase
{
    private string $dir;

    /** @var array{env: mixed, server: mixed, getenv: string|false} */
    private array $original;

    /**
     * 임시 `.env` 디렉터리를 만들고 프로세스 env 세 채널의 원래 값을 기억합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once base_path('bootstrap/g7-update-env.php');

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7_reconcile_'.uniqid();
        mkdir($this->dir);

        $this->original = [
            'env' => $_ENV['APP_VERSION'] ?? null,
            'server' => $_SERVER['APP_VERSION'] ?? null,
            'getenv' => getenv('APP_VERSION'),
        ];
    }

    /**
     * 임시 디렉터리를 지우고 프로세스 env 세 채널을 원래 값으로 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        @unlink($this->dir.DIRECTORY_SEPARATOR.'.env');
        @rmdir($this->dir);

        unset($_ENV['APP_VERSION'], $_SERVER['APP_VERSION']);
        if ($this->original['env'] !== null) {
            $_ENV['APP_VERSION'] = $this->original['env'];
        }
        if ($this->original['server'] !== null) {
            $_SERVER['APP_VERSION'] = $this->original['server'];
        }
        $this->original['getenv'] === false ? putenv('APP_VERSION') : putenv('APP_VERSION='.$this->original['getenv']);

        parent::tearDown();
    }

    /**
     * 프로세스 env 세 채널을 같은 값으로 세웁니다.
     *
     * @param  string|null  $version  세울 버전 (null 이면 세 채널 모두 비움)
     * @return void 반환값 없음
     */
    private function setProcessVersion(?string $version): void
    {
        unset($_ENV['APP_VERSION'], $_SERVER['APP_VERSION']);
        putenv('APP_VERSION');

        if ($version !== null) {
            $_ENV['APP_VERSION'] = $version;
            $_SERVER['APP_VERSION'] = $version;
            putenv('APP_VERSION='.$version);
        }
    }

    /**
     * 임시 디렉터리에 `.env` 를 씁니다.
     *
     * @param  string  $content  `.env` 본문
     * @return void 반환값 없음
     */
    private function writeEnv(string $content): void
    {
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'.env', $content);
    }

    /**
     * 디스크가 더 높으면 세 채널을 모두 디스크 값으로 올린다 (옛 부모 env 상속 자식).
     *
     * @scenario case=disk_newer
     *
     * @effects reconcile_raises_all_three_channels_to_disk_version
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_raises_all_channels_when_disk_is_newer(): void
    {
        $this->writeEnv("APP_NAME=G7\nAPP_VERSION=7.1.1\nAPP_ENV=production\n");
        $this->setProcessVersion('7.0.0');

        $this->assertSame('7.1.1', g7_reconcile_update_app_version($this->dir));
        $this->assertSame('7.1.1', $_ENV['APP_VERSION']);
        $this->assertSame('7.1.1', $_SERVER['APP_VERSION']);
        $this->assertSame('7.1.1', getenv('APP_VERSION'));
    }

    /**
     * 디스크가 더 낮으면 건드리지 않는다 (업그레이드 스텝 자식은 toVersion 을 받는다).
     *
     * @scenario case=process_higher
     *
     * @effects reconcile_never_lowers_upgrade_step_child_version
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_keeps_higher_process_version(): void
    {
        $this->writeEnv("APP_VERSION=7.1.0\n");
        $this->setProcessVersion('7.1.1');

        $this->assertNull(g7_reconcile_update_app_version($this->dir));
        $this->assertSame('7.1.1', $_SERVER['APP_VERSION']);
        $this->assertSame('7.1.1', getenv('APP_VERSION'));
    }

    /**
     * 같으면 아무것도 바꾸지 않는다.
     *
     * @scenario case=equal
     *
     * @effects reconcile_noop_when_equal
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_noop_when_equal(): void
    {
        $this->writeEnv("APP_VERSION=7.1.1\n");
        $this->setProcessVersion('7.1.1');

        $this->assertNull(g7_reconcile_update_app_version($this->dir));
        $this->assertSame('7.1.1', getenv('APP_VERSION'));
    }

    /**
     * 프로세스 env 에 APP_VERSION 이 없으면 세우지 않는다 (Laravel 이 `.env` 로 채운다).
     *
     * @scenario case=process_env_absent
     *
     * @effects reconcile_noop_when_process_env_absent
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_noop_when_process_env_absent(): void
    {
        $this->writeEnv("APP_VERSION=7.1.1\n");
        $this->setProcessVersion(null);

        $this->assertNull(g7_reconcile_update_app_version($this->dir));
        $this->assertFalse(getenv('APP_VERSION'));
        $this->assertArrayNotHasKey('APP_VERSION', $_SERVER);
    }

    /**
     * `.env` 가 없거나 APP_VERSION 줄이 없으면 아무것도 하지 않는다.
     *
     * @scenario case=disk_value_absent
     *
     * @effects reconcile_noop_without_disk_value
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_noop_without_disk_value(): void
    {
        $this->setProcessVersion('7.0.0');
        $this->assertNull(g7_reconcile_update_app_version($this->dir));

        $this->writeEnv("APP_NAME=G7\n# APP_VERSION=9.9.9\n");
        $this->assertNull(g7_reconcile_update_app_version($this->dir));
        $this->assertSame('7.0.0', getenv('APP_VERSION'));
    }

    /**
     * 따옴표, 줄 끝 주석, export 접두, 마지막 줄 우선 규칙을 Dotenv 와 같게 읽는다.
     *
     * @scenario case=dotenv_syntax
     *
     * @effects reconcile_parses_env_value_like_dotenv
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_parses_quotes_comments_and_last_line(): void
    {
        $cases = [
            "APP_VERSION=\"7.1.1\"\n" => '7.1.1',
            "APP_VERSION='7.1.1'\n" => '7.1.1',
            "APP_VERSION=7.1.1 # 출시판\n" => '7.1.1',
            "export APP_VERSION=7.1.1\n" => '7.1.1',
            "APP_VERSION = 7.1.1\r\n" => '7.1.1',
            "APP_VERSION=7.0.5\nAPP_VERSION=7.1.1\n" => '7.1.1',
        ];

        foreach ($cases as $content => $expected) {
            $this->writeEnv($content);
            $this->setProcessVersion('7.0.0');

            $this->assertSame($expected, g7_reconcile_update_app_version($this->dir), '본문: '.json_encode($content));
            $this->assertSame($expected, getenv('APP_VERSION'));
        }
    }

    /**
     * 사전 출시 표기도 버전 순서로 비교한다 (beta 는 정식보다 낮다).
     *
     * @scenario case=prerelease
     *
     * @effects reconcile_compares_prerelease_versions
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_compares_prerelease_versions(): void
    {
        $this->writeEnv("APP_VERSION=7.0.0\n");
        $this->setProcessVersion('7.0.0-beta.7');
        $this->assertSame('7.0.0', g7_reconcile_update_app_version($this->dir));

        $this->writeEnv("APP_VERSION=7.0.0-beta.7\n");
        $this->setProcessVersion('7.0.0');
        $this->assertNull(g7_reconcile_update_app_version($this->dir));
        $this->assertSame('7.0.0', getenv('APP_VERSION'));
    }
}
