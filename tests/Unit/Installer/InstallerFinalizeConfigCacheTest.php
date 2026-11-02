<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerSubprocess;

/**
 * .env 확정 뒤 설정 캐시를 다시 굽는지 검증한다.
 *
 * 회귀: 설치 마지막의 config_cache 작업은 complete_flag 뒤·.env 확정(finalize) 전에 돈다. 그때 .env 는 아직
 * 설치 전 템플릿이라, 확정 후 .env 에 INSTALLER_COMPLETED=true 가 써져도 캐시에는 installer_completed=false 가
 * 남았다(실측: 터미널·웹 설치 직후 bootstrap/cache/config.php). 캐시가 켜진 앱은 .env 를 다시 읽지 않으므로
 * 설치 완료 판정을 config 로 하는 경로가 틀린 값을 본다.
 */
class InstallerFinalizeConfigCacheTest extends TestCase
{
    use RunsInstallerSubprocess;

    /**
     * 확정 가능한 설치 직후 상태(런타임·state·템플릿 .env)를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $base = $this->makeInstallerTempBase();
        copy(dirname(__DIR__, 3).'/.env.example', $base.'/.env.example');
        copy(dirname(__DIR__, 3).'/.env.example', $base.'/.env');
        $this->writeInstallerState(['installation_status' => 'completed', 'config' => ['app_url' => 'https://site.test', 'db_write_database' => 'g7']]);
        $this->writeInstallerRuntimeFile(['db' => ['write' => ['host' => 'h', 'port' => '3306', 'database' => 'g7', 'username' => 'u', 'password' => 'p'], 'prefix' => 'g7_'], 'app' => ['key' => 'base64:'.base64_encode(str_repeat('k', 32))]]);
    }

    /**
     * 임시 루트 제거.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->removeInstallerTempBase();

        parent::tearDown();
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
