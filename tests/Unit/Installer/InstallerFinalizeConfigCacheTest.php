<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerSubprocess;

/**
 * 설치 마무리가 설정 캐시를 무효화하고(제품 결정 13차 D7/E2), .env 확정 뒤 다시 굽는지 실측한다.
 *
 * 마지막 태스크 `config_cache` 는 `.env` 머지 **이전**에 돈다. 그 시점의 config 는
 * `InstallerRuntimeServiceProvider` 가 `storage/installer/runtime.php` 에서 주입한 값이고,
 * Laravel 의 `config:cache` 는 전체 부팅을 거친 config 를 그대로 `var_export` 하므로
 * **DB 비밀번호가 평문으로 캐시 파일에 복제**된다. 덤으로 그 캐시에는
 * `app.installer_completed=false` 가 박혀 모듈·플러그인 라우트가 등록되지 않는다.
 *
 * 그런데 그 캐시를 갱신하는 지점이 어디에도 없었다 — finalize 는 runtime.php 만 지웠다.
 * 결과는 오류도 경고도 없이 「설치는 성공했는데 확장 API 가 전부 404 이고 비밀번호가
 * 캐시 파일에 남아 있는」 상태다.
 *
 * 회귀: 설치 마지막의 config_cache 작업은 complete_flag 뒤·.env 확정(finalize) 전에 돈다. 그때 .env 는 아직
 * 설치 전 템플릿이라, 확정 후 .env 에 INSTALLER_COMPLETED=true 가 써져도 캐시에는 installer_completed=false 가
 * 남았다(실측: 터미널·웹 설치 직후 bootstrap/cache/config.php). 캐시가 켜진 앱은 .env 를 다시 읽지 않으므로
 * 설치 완료 판정을 config 로 하는 경로가 틀린 값을 본다.
 *
 * 제품 결정은 「굽지 않는다」가 아니라 「끝날 때 지우고 병합된 값으로 다시 굽는다」다 — 태스크 목록은 그대로
 * 두고 finalize 가 병합 전에 그 파일을 지우고, 병합·runtime.php 삭제 뒤 다시 만든다(못 만들면 캐시 없이 둔다).
 * 그리고 그 삭제는 **설치를 중단시키지 않는다**.
 *
 * 함수 단위로는 확인되지 않는 계약이다 — 삭제가 `.env` 쓰기와의 순서를 지켜야 하고, 삭제 실패가 흐름을
 * 끊지 않아야 한다. 그래서 이 검사는 두 경로로 finalize 흐름을 임시 설치 루트에서 실제로 태운다:
 * 웹 엔드포인트 전체(`tests/Unit/Installer/stubs/finalize_env_probe.php`)와, 웹·터미널 공유 함수 `installer_finalize_env()`
 * (설정 캐시 다시 만들기를 기록 함수로 바꿔 끼운다).
 *
 * 커버 축: config_cache_state present·absent·undeletable / finalize_outcome cache_cleared·cache_kept_with_log
 *
 * 효과 요약(마커 아님 — 평문): 환경 파일 병합 뒤 설정 캐시 삭제, 캐시 삭제 실패에도 마무리 완주, 확정 뒤 설정 캐시 다시 만들기. 표식은 각 테스트 메서드에 있다.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerFinalizeConfigCacheTest extends TestCase
{
    use RunsInstallerSubprocess;

    /** 웹 엔드포인트 경로(finalize_env_probe)용 임시 설치 루트 */
    private string $root = '';

    /**
     * 두 경로의 임시 설치 루트를 만든다.
     *
     * 웹 엔드포인트 경로: 환경 견본, 완료 상태 파일, 평문 비밀번호가 든 런타임 파일을 깔아 둔다.
     * 공유 함수 경로: 확정 가능한 설치 직후 상태(런타임·state·템플릿 .env)를 하네스 임시 루트에 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/g7-installer-configcache-'.bin2hex(random_bytes(4));

        foreach (['/storage/logs', '/storage/installer', '/storage/app', '/bootstrap/cache'] as $dir) {
            mkdir($this->root.$dir, 0777, true);
        }

        file_put_contents($this->root.'/.env.example', implode("\n", [
            'APP_NAME=Laravel',
            'APP_URL=http://localhost',
            'DB_WRITE_HOST=127.0.0.1',
            'DB_WRITE_PORT=3306',
            'DB_WRITE_DATABASE=g7',
            'DB_WRITE_USERNAME=root',
            'DB_WRITE_PASSWORD=',
            'DB_PREFIX=g7_',
        ])."\n");

        file_put_contents($this->root.'/storage/installer-state.json', json_encode([
            'installation_status' => 'completed',
            'completed_tasks' => ['complete_flag', 'config_cache'],
            'current_step' => 6,
            'g7_locale' => 'ko',
            'config' => [
                'app_name' => 'T',
                'app_url' => 'http://t.test',
                'db_write_host' => '127.0.0.1',
                'db_write_database' => 'g7',
                'db_write_username' => 'root',
            ],
        ]));

        // finalize 는 runtime.php 가 없으면 「이미 끝났다」로 보고 즉시 돌아간다.
        file_put_contents(
            $this->root.'/storage/installer/runtime.php',
            "<?php\n\nreturn ".var_export(['db' => ['write_password' => 'secret-pw']], true).";\n"
        );

        $base = $this->makeInstallerTempBase();
        copy(dirname(__DIR__, 3).'/.env.example', $base.'/.env.example');
        copy(dirname(__DIR__, 3).'/.env.example', $base.'/.env');
        $this->writeInstallerState(['installation_status' => 'completed', 'config' => ['app_url' => 'https://site.test', 'db_write_database' => 'g7']]);
        $this->writeInstallerRuntimeFile(['db' => ['write' => ['host' => 'h', 'port' => '3306', 'database' => 'g7', 'username' => 'u', 'password' => 'p'], 'prefix' => 'g7_'], 'app' => ['key' => 'base64:'.base64_encode(str_repeat('k', 32))]]);
    }

    /**
     * 테스트마다 만든 두 임시 설치 루트를 통째로 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        $this->removeInstallerTempBase();

        parent::tearDown();
    }

    /**
     * 주어진 폴더 아래를 재귀로 지우고 폴더 자신도 지운다.
     *
     * @param  string  $path  지울 폴더 경로 (빈 값이거나 폴더가 아니면 아무것도 하지 않는다)
     * @return void 반환값 없음
     */
    private function removeTree(string $path): void
    {
        if ($path === '' || ! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.'/'.$entry;
            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }

        @rmdir($path);
    }

    /**
     * 임시 설치 루트에서 finalize 를 태운다.
     *
     * 하위 프로세스를 띄울 수 없는 환경이면 건너뛴다.
     *
     * @return string 표준출력(표준오류 포함)
     */
    private function runFinalize(): string
    {
        if (! function_exists('exec')) {
            $this->markTestSkipped('exec 가 없어 하위 프로세스로 finalize 흐름을 태울 수 없다');
        }

        $command = escapeshellarg(PHP_BINARY)
            .' '.escapeshellarg(__DIR__.'/stubs/finalize_env_probe.php')
            .' '.escapeshellarg($this->root)
            .' 2>&1';

        $output = [];
        exec($command, $output);

        return implode("\n", $output);
    }

    /**
     * 임시 설치 루트 안의 설정 캐시 파일 경로를 돌려준다.
     *
     * @return string 설정 캐시 파일 경로
     */
    private function cachePath(): string
    {
        return $this->root.'/bootstrap/cache/config.php';
    }

    /**
     * 임시 설치 루트의 설치 진행 기록 파일 내용을 읽는다.
     *
     * @return string 기록 파일 내용 (파일이 없으면 빈 문자열)
     */
    private function installationLog(): string
    {
        $path = $this->root.'/storage/logs/installation.log';

        // meta:allow raw-source-read — 설치 진행 기록(로그) 파일이다, 소스가 아니다
        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * 환경 파일 병합 전에 구운 설정 캐시(미완료 표식과 평문 비밀번호 포함)를 마무리 단계가 지우는지 실제 흐름으로 확인한다.
     *
     * @scenario task=config_cache, endpoint_guard=finalize_allowed
     *
     * @effects installer_finalize_clears_config_cache_after_env_merge
     *
     * @return void 반환값 없음
     */
    public function test_finalize_deletes_the_config_cache_baked_before_the_env_merge(): void
    {
        // `config:cache` 가 남긴 형태 — runtime.php 의 평문 비밀번호와 미완료 표식이 함께 박혀 있다.
        file_put_contents($this->cachePath(), "<?php\n\nreturn ".var_export([
            'app' => ['installer_completed' => false],
            'database' => ['connections' => ['mysql' => ['write' => ['password' => 'secret-pw']]]],
        ], true).";\n");

        $output = $this->runFinalize();

        $this->assertStringNotContainsString('Fatal error', $output, 'finalize 가 치명 종료했다: '.$output);
        $this->assertFileExists($this->root.'/.env', 'finalize 가 .env 를 쓰지 못했다: '.$output);

        $this->assertFileDoesNotExist(
            $this->cachePath(),
            "설정 캐시가 그대로 남았다.\n"
            .'설치 이전 config(installer_completed=false + 평문 DB 비밀번호)가 박힌 캐시가 잔존하면 '
            ."확장 라우트가 등록되지 않고 자격증명이 파일로 복제된 채 운영에 들어간다.\n"
            .'출력: '.$output,
        );
    }

    /**
     * 삭제가 실패해도 설치는 중단되지 않는다 — 기록만 남는다.
     *
     * 같은 이름의 **디렉토리**를 두어 `unlink()` 를 실패시킨다. 파일 권한은 OS·실행 사용자에
     * 따라 root 가 그대로 지워 버려 재현되지 않는다.
     *
     * @scenario task=config_cache, endpoint_guard=finalize_allowed
     *
     * @effects installer_finalize_survives_config_cache_delete_failure
     *
     * @return void 반환값 없음
     */
    public function test_finalize_completes_even_when_the_config_cache_cannot_be_deleted(): void
    {
        mkdir($this->cachePath(), 0777, true);

        $output = $this->runFinalize();

        $this->assertStringNotContainsString('Fatal error', $output, 'finalize 가 치명 종료했다: '.$output);

        // 삭제 실패가 흐름을 끊지 않았다는 증거 — 뒤따르는 정리가 그대로 수행됐다.
        $this->assertFileExists($this->root.'/.env', '.env 머지가 삭제 실패에 막혔다: '.$output);
        $this->assertFileDoesNotExist(
            $this->root.'/storage/installer/runtime.php',
            'runtime.php 정리가 설정 캐시 삭제 실패에 막혔다 — 삭제 실패는 설치를 중단시키지 않는다',
        );

        // 기대 문구는 **번역된 본문**이다. 종전 기대값(`bootstrap/cache/config.php 삭제
        // FAILED`)은 이 기록이 다국어 키로 옮겨지기 전의 것이라 어느 쪽으로도 맞지 않았고,
        // 그 stale 한 기대가 「이 파일만 번역을 싣지 않는다」는 실제 결함을 가리고 있었다.
        // 원시 키가 나가면 이 단언이 red 다 (13차 G3 라운드).
        $log = $this->installationLog();

        $this->assertStringContainsString(
            '설정 캐시 삭제 실패',
            $log,
            '삭제 실패가 진행 기록에 남지 않았다 — 운영자가 수동 정리 대상을 알 방법이 사라진다',
        );

        $this->assertStringNotContainsString(
            'log_finalize_env_',
            $log,
            '진행 기록이 원시 다국어 키로 나갔다 — 형제 엔드포인트처럼 번역을 실어야 한다',
        );
    }

    /**
     * 설정 캐시가 없을 때 마무리 단계가 환경 파일을 쓰고 삭제 실패를 잘못 기록하지 않는지 확인한다.
     *
     * @scenario task=config_cache, endpoint_guard=finalize_allowed
     *
     * @effects installer_finalize_clears_config_cache_after_env_merge
     *
     * @return void 반환값 없음
     */
    public function test_finalize_is_a_noop_when_no_config_cache_exists(): void
    {
        $this->assertFileDoesNotExist($this->cachePath());

        $output = $this->runFinalize();

        $this->assertStringNotContainsString('Fatal error', $output, 'finalize 가 치명 종료했다: '.$output);
        $this->assertFileExists($this->root.'/.env', 'finalize 가 .env 를 쓰지 못했다: '.$output);
        $this->assertStringNotContainsString(
            'bootstrap/cache/config.php 삭제 FAILED',
            $this->installationLog(),
            '캐시가 없는데 삭제 실패를 기록했다',
        );
    }

    /**
     * finalize 를 실행하고 캐시 재생성 호출 기록을 돌려준다 (실제 artisan 대신 기록 함수로 대체).
     *
     * @param  bool  $withCache  확정 전 설정 캐시 파일(병합 전 값)이 있는지
     * @param  bool  $rebuildSucceeds  다시 만들기가 성공하는지
     * @return array<string, mixed> 호출 여부·호출 시점 .env 의 완료 표식·호출 시점 옛 캐시 유무·끝난 뒤 캐시 내용
     */
    private function finalize(bool $withCache, bool $rebuildSucceeds = true): array
    {
        if ($withCache) {
            @mkdir($this->makeBasePathOf('bootstrap/cache'), 0777, true);
            file_put_contents($this->makeBasePathOf('bootstrap/cache/config.php'), "<?php return ['app' => ['installer_completed' => false]];\n");
        }

        // 성공 흉내는 병합된 값으로 캐시를 새로 쓰고, 실패 흉내는 쓰지 않는다(본체 installerRebuildConfigCache 는 실패 때 캐시를 지운다)
        $outcome = $rebuildSucceeds
            ? <<<'PHP'
                @mkdir(BASE_PATH.'/bootstrap/cache', 0777, true);
                file_put_contents(BASE_PATH.'/bootstrap/cache/config.php', "<?php return ['built_by' => 'rebuild'];\n");
                return ['rebuilt' => true, 'exit_code' => 0, 'output' => ''];
                PHP
            : <<<'PHP'
                return ['rebuilt' => false, 'exit_code' => 1, 'output' => 'boom'];
                PHP;

        $result = $this->runInstallerScript(<<<'PHP'
            function installer_finalize_rebuild_config_cache(string $phpBinary): array {
                $GLOBALS['rebuilt'] = str_contains((string) file_get_contents(BASE_PATH.'/.env'), 'INSTALLER_COMPLETED=true');
                $GLOBALS['stale_cache_at_call'] = is_file(BASE_PATH.'/bootstrap/cache/config.php');
            PHP."\n".$outcome."\n".<<<'PHP'
            }
            require_once $projectRoot.'/public/install/includes/env-finalize.php';
            $GLOBALS['translations'] = loadTranslations('ko');
            $r = installer_finalize_env();
            $cache = BASE_PATH.'/bootstrap/cache/config.php';
            echo json_encode([
                'success' => $r['success'],
                'called' => array_key_exists('rebuilt', $GLOBALS),
                'env_completed_at_call' => $GLOBALS['rebuilt'] ?? null,
                'stale_cache_at_call' => $GLOBALS['stale_cache_at_call'] ?? null,
                'cache_after' => is_file($cache) ? (require $cache) : null,
            ]);
            PHP, ['session' => []]);

        $this->assertIsArray($result['body'], $result['raw']);

        return $result['body'];
    }

    /**
     * 임시 루트 안의 경로.
     *
     * @param  string  $relative  상대 경로
     * @return string 절대 경로
     */
    private function makeBasePathOf(string $relative): string
    {
        return $this->installerTempBase.'/'.$relative;
    }

    /**
     * 병합 전 값의 설정 캐시가 있으면 병합 전에 지우고, .env 를 확정한 **뒤에** 다시 만든다.
     *
     * @effects finalize_rebuilds_config_cache_after_env_merge
     *
     * @return void 반환값 없음
     */
    public function test_rebuilds_config_cache_after_env_is_finalized(): void
    {
        $this->assertSame([
            'success' => true,
            'called' => true,
            'env_completed_at_call' => true,
            'stale_cache_at_call' => false,
            'cache_after' => ['built_by' => 'rebuild'],
        ], $this->finalize(true));
    }

    /**
     * 설정 캐시가 없어도(config_cache 작업 실패·미실행) 웹 설치와 같이 병합된 .env 로 새로 만든다.
     *
     * @effects finalize_rebuilds_config_cache_even_without_prior_cache
     *
     * @return void 반환값 없음
     */
    public function test_rebuilds_config_cache_even_when_no_prior_cache(): void
    {
        $this->assertSame([
            'success' => true,
            'called' => true,
            'env_completed_at_call' => true,
            'stale_cache_at_call' => false,
            'cache_after' => ['built_by' => 'rebuild'],
        ], $this->finalize(false));
    }

    /**
     * 다시 만들기가 실패하면 병합 전 값(설치 미완료 표식)이 든 캐시를 남기지 않는다 — 다음 요청은 병합된 .env 로 부팅한다.
     *
     * @effects finalize_leaves_no_stale_config_cache_on_rebuild_failure
     *
     * @return void 반환값 없음
     */
    public function test_leaves_no_stale_cache_when_rebuild_fails(): void
    {
        $this->assertSame([
            'success' => true,
            'called' => true,
            'env_completed_at_call' => true,
            'stale_cache_at_call' => false,
            'cache_after' => null,
        ], $this->finalize(true, false));
    }
}
