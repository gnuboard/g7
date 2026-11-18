<?php

namespace Tests\Feature\Installer;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 웹 마법사 [강제 진행] 재설치가 앞 설치의 확장 캐시를 비우는지.
 *
 * 기존 설치 DB 의 테이블을 지우고(db_cleanup drop_tables) 다시 설치할 때, 앞 설치가 남긴 확장 캐시(활성 확장 목록을 담은
 * 애플리케이션 파일 캐시, 훅·오토로드·예약 명령 매핑)가 그대로면 빈 DB 위에서도 앞 설치의 플러그인(히어로섹션)이 켜진 것으로
 * 보여 그 리스너가 등록된다. 그 플러그인은 아직 설치·오토로드 전이라 서비스 공급자(저장소 바인딩)가 없어, 모듈 활성화가
 * 리스너를 만들다 「… is not instantiable」 로 실패했다. CLI 재설치(`installer_cli_reinstall_targets()`)는 같은 캐시를 이미 지운다.
 *
 * @scenario-manifest tests/scenarios/installer-stale-extension-cache.yaml
 */
class StaleExtensionCacheCleanupTest extends TestCase
{
    /** @var string 시험용 설치 루트 */
    private string $testBase;

    /**
     * 시험용 설치 루트와 shim 을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->testBase = storage_path('app/test-stale-ext-cache-'.uniqid());
        File::ensureDirectoryExists($this->testBase.'/bootstrap/cache');
        File::ensureDirectoryExists($this->testBase.'/storage/framework/cache/data/ab/cd');

        require_once base_path('public/install/includes/vendor-bundle-installer.php');
    }

    /**
     * 시험용 설치 루트를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->testBase);
        parent::tearDown();
    }

    /**
     * 확장 캐시 4종과 애플리케이션 파일 캐시를 지우고, 그 밖의 캐시·`.gitignore` 는 남긴다.
     *
     * @scenario case=stale_caches_present
     *
     * @effects reinstall_clears_stale_extension_caches
     *
     * @return void 반환값 없음
     */
    public function test_clears_extension_caches_and_app_file_cache(): void
    {
        $cache = $this->testBase.'/bootstrap/cache';
        foreach (['hooks.php', 'autoload-extensions.php', 'extension-schedule-commands.php', 'routes-v7.php', 'packages.php'] as $file) {
            File::put($cache.'/'.$file, '<?php return [];');
        }
        $data = $this->testBase.'/storage/framework/cache/data';
        File::put($data.'/.gitignore', "*\n!.gitignore\n");
        File::put($data.'/ab/cd/stale-active-plugins', 'a:1:{i:0;s:21:"sirsoft-hero_section";}');

        $cleared = clearStaleExtensionCaches($this->testBase);

        foreach (['hooks.php', 'autoload-extensions.php', 'extension-schedule-commands.php', 'routes-v7.php'] as $file) {
            $this->assertFileDoesNotExist($cache.'/'.$file, "{$file} 이 남아 앞 설치의 확장이 켜진 것으로 보인다");
        }
        $this->assertFileExists($cache.'/packages.php', '확장과 무관한 캐시는 남긴다');
        $this->assertFileDoesNotExist($data.'/ab/cd/stale-active-plugins', '앞 설치의 활성 확장 목록 캐시가 남았다');
        $this->assertFileExists($data.'/.gitignore');
        $this->assertContains('hooks.php', $cleared);
    }

    /**
     * 새 설치(캐시 없음)에서는 아무것도 지우지 않는다.
     *
     * @scenario case=fresh_install
     *
     * @effects reinstall_clears_stale_extension_caches
     *
     * @return void 반환값 없음
     */
    public function test_noop_on_fresh_install(): void
    {
        File::deleteDirectory($this->testBase.'/storage');
        $this->assertSame([], clearStaleExtensionCaches($this->testBase));
    }

    /**
     * 테이블 삭제 여부와 무관하게(새 DB + 앞 설치 폴더 포함) 설치 완료 표식이 없으면 db_cleanup 이 분기 앞에서 정리를 부른다.
     *
     * @scenario case=fresh_db_leftover_folders
     *
     * @effects reinstall_clears_stale_extension_caches
     *
     * @return void 반환값 없음
     */
    public function test_db_cleanup_clears_caches_before_drop_branch(): void
    {
        $body = self::dbCleanupBody((string) file_get_contents(base_path('public/install/includes/task-runner.php')));
        $call = strpos($body, 'clearStaleExtensionCaches(BASE_PATH)');
        $branch = strpos($body, "if (\$action !== 'drop_tables')");

        $this->assertNotFalse($call, '설치 시작이 앞 설치의 확장 캐시를 비우지 않는다');
        $this->assertNotFalse($branch);
        $this->assertLessThan($branch, $call, '정리가 테이블 삭제 분기 안에만 있어 새 DB(테이블 없음) 설치에서는 돌지 않는다');
        $this->assertStringContainsString("g7_installed", substr($body, 0, $call), '설치 완료 표식이 있는 사이트에서도 캐시를 지운다');
    }

    /**
     * task-runner 소스에서 cleanupExistingTablesSSE() 본문을 잘라 냅니다.
     *
     * @param  string  $source  task-runner.php 소스
     * @return string 함수 본문
     */
    public static function dbCleanupBody(string $source): string
    {
        $start = strpos($source, 'function cleanupExistingTablesSSE');
        $end = strpos($source, "\n    function ", $start + 10);

        return substr($source, (int) $start, $end === false ? null : $end - (int) $start);
    }
}
