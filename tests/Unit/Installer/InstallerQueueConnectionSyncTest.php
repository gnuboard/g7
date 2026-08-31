<?php

namespace Tests\Unit\Installer;

use Dotenv\Dotenv;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * inprocess 설치가 큐 연결을 `sync` 로 기록하는지 (A-6 · D6).
 *
 * 기본 템플릿의 `database` 는 큐에 넣기만 하고 **소비 주체가 따로 있다**는 전제인데,
 * CLI 를 쓸 수 없는 호스팅에서는 `queue:work` 워커를 띄울 수단이 없다. 그대로 두면
 * 훅 리스너·알림이 잡 테이블에 쌓이기만 하고 아무도 꺼내지 않으며, **그 사실은 오류로
 * 드러나지 않는다 — 알림이 그냥 오지 않을 뿐이다.**
 *
 * 반대 방향도 함께 잠근다: subprocess 설치는 템플릿 기본값을 그대로 둔다. 거기서도
 * 덮으면 운영자가 고르지 않은 정책 변경이 된다.
 *
 * 이 축은 코드만 있고 재는 시험이 없어, 18줄을 통째로 지워도 아무것도 red 가 되지
 * 않는 상태였다 (검증 R2 · 축 28).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerQueueConnectionSyncTest extends TestCase
{
    private string $tempBase = '';

    private string $skipReason = '';

    protected function setUp(): void
    {
        parent::setUp();

        $projectRoot = dirname(__DIR__, 3);
        $this->tempBase = sys_get_temp_dir().'/g7-env-queue-'.bin2hex(random_bytes(4));
        @mkdir($this->tempBase.'/storage/installer', 0755, true);

        // BASE_PATH 는 `.env.example` 을 읽기 위해 실제 저장소 루트여야 한다(읽기 전용).
        // 반면 상태/런타임 파일 경로는 반드시 temp 로 떼어 놓는다 — 그러지 못하면
        // 운영 storage/installer-state.json·runtime.php 를 건드리게 되므로 skip 한다.
        if (! defined('BASE_PATH')) {
            define('BASE_PATH', $projectRoot);
        }
        if (! defined('STATE_PATH')) {
            define('STATE_PATH', $this->tempBase.'/installer-state.json');
        }
        if (! defined('INSTALLER_RUNTIME_PATH')) {
            define('INSTALLER_RUNTIME_PATH', $this->tempBase.'/storage/installer/runtime.php');
        }

        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        foreach (['STATE_PATH' => STATE_PATH, 'INSTALLER_RUNTIME_PATH' => INSTALLER_RUNTIME_PATH] as $name => $path) {
            $resolved = realpath(dirname((string) $path)) ?: dirname((string) $path);
            if (strpos($resolved, $tempPrefix) !== 0) {
                $this->skipReason = $name.' ('.$path.') 가 시스템 temp 하위가 아님 — 다른 Installer '
                    .'테스트의 상수 정의가 선행됨. 운영 파일 파괴 방지를 위해 skip. 격리 실행: '
                    .'php vendor/bin/phpunit --filter=InstallerQueueConnectionSyncTest';
                $this->markTestSkipped($this->skipReason);
            }
        }

        require_once __DIR__.'/stubs/lang_stub.php';
        require_once $projectRoot.'/public/install/includes/config.php';
        require_once $projectRoot.'/public/install/includes/installer-state.php';
        require_once $projectRoot.'/public/install/includes/functions.php';
        require_once $projectRoot.'/public/install/includes/installer-runtime.php';
    }

    protected function tearDown(): void
    {
        if ($this->skipReason === '' && is_file(STATE_PATH)) {
            @unlink(STATE_PATH);
        }

        parent::tearDown();
    }

    /**
     * state.json 의 config 섹션을 temp 경로에 기록한다.
     *
     * @param  array<string, mixed>  $config  config 섹션
     */
    private function writeState(array $config): void
    {
        @file_put_contents(STATE_PATH, json_encode(['config' => $config]));
    }

    /**
     * 완성된 `.env` 본문을 Laravel 이 쓰는 파서로 되읽는다.
     *
     * @param  string  $envContent  `.env` 본문
     * @return array<string, string|null> 키 → 값
     */
    private function parseEnv(string $envContent): array
    {
        return Dotenv::parse($envContent);
    }

    /**
     * 정상 입력(무해한 값) 조합.
     *
     * @return array<string, mixed>
     */
    private function benignConfig(): array
    {
        return [
            'db_write_host' => '127.0.0.1',
            'db_write_port' => '3306',
            'db_write_database' => 'g7db',
            'db_write_username' => 'g7user',
            'db_write_password' => 'g7pass',
            'db_prefix' => 'g7_',
            'use_read_db' => true,
            'db_read_host' => '127.0.0.2',
            'db_read_port' => '3307',
            'db_read_database' => 'g7read',
            'db_read_username' => 'g7reader',
            'db_read_password' => 'g7readpass',
            'app_name' => 'G7',
            'app_env' => 'production',
            'app_url' => 'https://example.com',
            'core_update_pending_path' => '/var/g7/pending',
            'core_update_github_url' => 'https://github.com/gnuboard/g7',
            'core_update_github_token' => 'ghp_token',
            'php_binary' => '/usr/bin/php',
            'composer_binary' => '/usr/local/bin/composer',
        ];
    }

    /**
     * 같은 키 조합에 라인 주입 페이로드를 채운 것.
     *
     * 각 값은 `.env` 문법에서 위험한 문자를 모두 담는다: 개행(라인 분리), `"`(따옴표 조기 종료),
     * `$1`·`\`(preg_replace 치환문자열 역참조/escape), `${...}`(phpdotenv 변수 보간).
     *
     * @return array<string, mixed>
     */
    private function injectedConfig(): array
    {
        $config = [];
        $index = 0;

        foreach ($this->benignConfig() as $key => $value) {
            if ($key === 'use_read_db') {
                $config[$key] = true;

                continue;
            }

            $index++;
            $config[$key] = $value."\"\nG7_INJECTED_{$index}=pwned\na\$1b\\c\${APP_KEY}";
        }

        return $config;
    }

    // ========================================================================
    // A-6 — 실행 모드에 따른 큐 연결 기록
    // ========================================================================

    /**
     * inprocess 설치는 `QUEUE_CONNECTION=sync` 를 기록한다.
     *
     * @effects inprocess_install_records_sync_queue_connection
     */
    public function test_inprocess_install_records_sync_queue_connection(): void
    {
        $env = $this->parseEnv((string) $this->generateFor('inprocess'));

        $this->assertSame(
            'sync',
            $env['QUEUE_CONNECTION'] ?? null,
            'CLI 를 쓸 수 없는 설치인데 큐 연결이 sync 가 아니다 — 알림·훅이 잡 테이블에 '
            .'쌓이기만 하고 꺼내는 주체가 없다. 오류 없이 알림이 오지 않을 뿐이라 '
            .'운영자가 알아낼 단서가 없다.',
        );
    }

    /**
     * subprocess 설치는 템플릿 기본값을 덮지 않는다.
     *
     * @effects subprocess_install_leaves_queue_connection_untouched
     */
    public function test_subprocess_install_leaves_the_template_default(): void
    {
        $template = $this->parseEnv((string) file_get_contents(BASE_PATH.'/.env.example'));
        $env = $this->parseEnv((string) $this->generateFor('subprocess'));

        $this->assertSame(
            $template['QUEUE_CONNECTION'] ?? null,
            $env['QUEUE_CONNECTION'] ?? null,
            'subprocess 설치가 큐 연결을 덮었다 — 워커를 띄울 수단이 있는 환경인데 '
            .'운영자가 고르지 않은 정책 변경이 적용된다.',
        );
    }

    /**
     * 두 모드의 결과가 실제로 갈린다 (한쪽만 재면 분기가 죽어도 초록이다).
     *
     * @effects queue_connection_differs_between_execution_modes
     */
    public function test_the_two_modes_do_not_produce_the_same_value(): void
    {
        $inprocess = $this->parseEnv((string) $this->generateFor('inprocess'))['QUEUE_CONNECTION'] ?? null;
        $subprocess = $this->parseEnv((string) $this->generateFor('subprocess'))['QUEUE_CONNECTION'] ?? null;

        $this->assertNotSame(
            $subprocess,
            $inprocess,
            '실행 모드가 달라도 큐 연결이 같다 — 분기가 사문화됐다.',
        );
    }

    /**
     * `.env` 본문을 만드는 함수는 **실행 방식을 다시 감지하지 않는다**.
     *
     * 감지는 PHP 바이너리를 실제로 돌려 보는 동작이다. `.env` 본문을 만드는 함수가
     * 그것을 하면, 운영자가 넣은 경로가 그 호스팅에서 실행되지 않을 때 설치가 마지막
     * 단계에서 멎고 사유는 「.env 를 쓰지 못했다」로만 보인다. 정상 설치는 시작 시점
     * (`install-process.php`)이 판정을 박아 두므로, 이 폴백은 예전 버전에서 중단된
     * 설치를 이어받을 때만 도달한다.
     *
     * 판정은 **소스 계약**으로 한다. 행위로 재려 했으나 되돌려도 초록이었다 —
     * 이 개발 기계에서는 `ExecutionEnvironment::detect()` 가 어떤 경로를 주든
     * subprocess 를 돌려줘 결과가 갈리지 않았고, 갈리지 않으면 아무것도 재지 못한다.
     * 실제 사고는 형제 테스트(`InstallerEnvValueInjectionTest`)가 red 가 되면서 드러났다.
     *
     * @effects env_generation_never_redetects_the_execution_mode
     */
    public function test_env_generation_does_not_redetect_the_execution_mode(): void
    {
        $body = $this->generateEnvContentBody();

        $this->assertStringContainsString(
            'execution_mode_resolved',
            $body,
            'generateEnvContent() 가 설치 시작 시점에 박아 둔 실행 방식을 읽지 않는다.',
        );

        foreach (['installerEffectiveExecutionMode(', 'installerResolveExecutionMode(', 'installerDetectExecutionMode('] as $probe) {
            $this->assertStringNotContainsString(
                $probe,
                $body,
                ".env 본문을 만드는 함수가 실행 방식을 다시 감지한다 ({$probe}) — 그 감지는 "
                .'PHP 바이너리를 실제로 돌려 보는 동작이라, 경로가 안 맞는 호스팅에서 설치가 '
                .'마지막 단계에서 멎는다.',
            );
        }
    }

    /**
     * 실행 방식이 확정되지 않았으면 큐 연결을 덮지 않는다.
     *
     * @effects unresolved_execution_mode_leaves_queue_connection_untouched
     */
    public function test_unresolved_execution_mode_leaves_queue_connection_untouched(): void
    {
        $config = $this->benignConfig();
        $config['execution_mode'] = 'auto';

        // `execution_mode_resolved` 를 일부러 넣지 않는다 (구 state 파일 재현).
        @file_put_contents(STATE_PATH, json_encode(['config' => $config]));

        $template = $this->parseEnv((string) file_get_contents(BASE_PATH.'/.env.example'));
        $env = $this->parseEnv((string) generateEnvContent());

        $this->assertSame(
            $template['QUEUE_CONNECTION'] ?? null,
            $env['QUEUE_CONNECTION'] ?? null,
            '판정이 없는데 큐 연결을 덮었다 — 확정되지 않은 값으로 정책을 정하고 있다.',
        );
    }

    /**
     * `generateEnvContent()` 의 소스 본문을 잘라 옵니다.
     *
     * @return string 함수 본문
     */
    private function generateEnvContentBody(): string
    {
        $source = (string) file_get_contents(BASE_PATH.'/public/install/includes/functions.php');
        $start = strpos($source, 'function generateEnvContent(');

        $this->assertNotFalse($start, 'generateEnvContent() 를 소스에서 찾지 못했습니다.');

        // 다음 최상위 함수 선언 직전까지 = 이 함수의 본문.
        $next = strpos($source, '
function ', (int) $start + 10);

        return substr($source, (int) $start, $next === false ? null : $next - (int) $start);
    }

    /**
     * 유효 실행 모드를 고정한 채 `.env` 본문을 만든다.
     *
     * `execution_mode_resolved` 는 설치가 확정한 유효 모드이며
     * `installerEffectiveExecutionMode()` 가 가장 먼저 읽는 값이다.
     *
     * @param  string  $mode  subprocess|inprocess
     * @return string|null 완성된 `.env` 본문
     */
    private function generateFor(string $mode): ?string
    {
        @file_put_contents(STATE_PATH, json_encode([
            'config' => $this->benignConfig(),
            'execution_mode_resolved' => $mode,
        ]));

        return generateEnvContent();
    }
}
