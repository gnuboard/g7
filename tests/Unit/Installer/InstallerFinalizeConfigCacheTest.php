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
     * @param  bool  $withCache  확정 전 설정 캐시 파일이 있는지
     * @return array<string, mixed> 호출 여부·호출 시점 .env 의 완료 표식
     */
    private function finalize(bool $withCache): array
    {
        if ($withCache) {
            @mkdir($this->makeBasePathOf('bootstrap/cache'), 0777, true);
            file_put_contents($this->makeBasePathOf('bootstrap/cache/config.php'), "<?php return ['app' => ['installer_completed' => false]];\n");
        }

        $result = $this->runInstallerScript(<<<'PHP'
            function installer_finalize_rebuild_config_cache(): bool {
                $GLOBALS['rebuilt'] = str_contains((string) file_get_contents(BASE_PATH.'/.env'), 'INSTALLER_COMPLETED=true');
                return true;
            }
            require_once $projectRoot.'/public/install/includes/env-finalize.php';
            $GLOBALS['translations'] = loadTranslations('ko');
            $r = installer_finalize_env();
            echo json_encode(['success' => $r['success'], 'called' => array_key_exists('rebuilt', $GLOBALS), 'env_completed_at_call' => $GLOBALS['rebuilt'] ?? null]);
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
     * 설정 캐시가 있으면 .env 를 확정한 **뒤에** 다시 굽는다.
     *
     * @effects finalize_rebuilds_config_cache_after_env_merge
     *
     * @return void 반환값 없음
     */
    public function test_rebuilds_config_cache_after_env_is_finalized(): void
    {
        $this->assertSame(['success' => true, 'called' => true, 'env_completed_at_call' => true], $this->finalize(true));
    }

    /**
     * 설정 캐시가 없으면(config_cache 실패·미실행) 굽지 않는다 — 캐시를 새로 켜지 않는다.
     *
     * @effects finalize_skips_rebuild_without_config_cache
     *
     * @return void 반환값 없음
     */
    public function test_skips_rebuild_when_no_config_cache(): void
    {
        $this->assertSame(['success' => true, 'called' => false, 'env_completed_at_call' => null], $this->finalize(false));
    }
}
