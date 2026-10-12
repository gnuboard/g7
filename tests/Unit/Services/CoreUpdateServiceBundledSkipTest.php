<?php

namespace Tests\Unit\Services;

use App\Extension\Helpers\CoreBackupHelper;
use App\Services\CoreUpdateService;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CoreUpdateService::applyUpdate() 의 미배치 번들 건너뛰기 단위 테스트 (공개 #146 S1·S2·S3·S5·S7·S8·S10)
 *
 * 3-way base 가 업데이트 직전 디스크라 운영자가 지운 번들과 새 판 번들이 모두 added 로 판정되고,
 * prune·백업 부재 모드는 targets 를 통째로 덮어써 미설치 번들이 전부 배치되던 결함을 잠급니다.
 * 트러블슈팅 `[case:backend-56]` 의 회귀 그물이다.
 */
class CoreUpdateServiceBundledSkipTest extends TestCase
{
    /** 번들 4도메인 target */
    private const TARGETS = ['modules/_bundled', 'plugins/_bundled', 'templates/_bundled', 'lang-packs/_bundled'];

    private CoreUpdateService $service;

    private string $originalBasePath;

    /**
     * 테스트에서 만든 임시 디렉토리 목록 (tearDown 에서 정리)
     *
     * @var array<int, string>
     */
    private array $tempDirs = [];

    /**
     * 서비스와 원래 base_path 를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CoreUpdateService;
        $this->originalBasePath = base_path();
    }

    /**
     * base_path 를 되돌리고 임시 디렉토리를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        app()->setBasePath($this->originalBasePath);

        foreach ($this->tempDirs as $dir) {
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
            }
        }

        parent::tearDown();
    }

    /**
     * 세 적용 모드 모두에서 skipPaths 의 번들(모듈·플러그인·템플릿·언어팩)을 배치하지 않고,
     * 디스크에 있던 번들과 core 언어팩은 갱신하며, 사용자 자체 `_bundled/{id}` 는 보존합니다.
     *
     * @effects unplaced_bundles_are_not_placed_in_every_mode, on_disk_bundles_are_still_updated, user_custom_bundled_dir_preserved
     *
     * @param  string  $mode  적용 모드 (incremental|prune|no-backup)
     * @return void 반환값 없음
     */
    #[DataProvider('applyModeProvider')]
    public function test_apply_update_skips_unplaced_bundles_in_every_mode(string $mode): void
    {
        [$source, $fakeBase, $backup] = $this->prepareEnv();

        // 새 배포본: 디스크에 있는 번들(board) + 미배치 대상 4도메인
        $this->writeFile($source, 'modules/_bundled/sirsoft-board/module.json', '{"version":"1.3.0"}');
        $this->writeFile($source, 'modules/_bundled/sirsoft-board/stale-free.php', '<?php // v2');
        $this->writeFile($source, 'modules/_bundled/sirsoft-ecommerce/module.json', '{"version":"1.3.0"}');
        $this->writeFile($source, 'plugins/_bundled/sirsoft-gdpr/plugin.json', '{"version":"1.1.0"}');
        $this->writeFile($source, 'templates/_bundled/sirsoft-basic/template.json', '{"version":"1.2.0"}');
        $this->writeFile($source, 'lang-packs/_bundled/g7-plugin-sirsoft-gdpr-ja/language-pack.json', '{}');
        $this->writeFile($source, 'lang-packs/_bundled/g7-core-ja/language-pack.json', '{"version":"1.1.2"}');

        // 현재 디스크: board(구판 + 내부 stale) + core 언어팩 + 사용자 자체 번들
        $this->writeFile($fakeBase, 'modules/_bundled/sirsoft-board/module.json', '{"version":"1.2.0"}');
        $this->writeFile($fakeBase, 'modules/_bundled/sirsoft-board/old-stale.php', '<?php // v1 only');
        $this->writeFile($fakeBase, 'lang-packs/_bundled/g7-core-ja/language-pack.json', '{"version":"1.1.1"}');
        $this->writeFile($fakeBase, 'plugins/_bundled/acme-custom/plugin.json', '{"mine":true}');
        foreach (['modules', 'plugins', 'lang-packs'] as $domain) {
            File::copyDirectory($fakeBase.DIRECTORY_SEPARATOR.$domain, $backup.DIRECTORY_SEPARATOR.$domain);
        }

        $skip = [
            'modules/_bundled/sirsoft-ecommerce',
            'plugins/_bundled/sirsoft-gdpr',
            'templates/_bundled/sirsoft-basic',
            'lang-packs/_bundled/g7-plugin-sirsoft-gdpr-ja',
        ];

        $applyList = null;
        if ($mode === 'incremental') {
            $applyList = CoreBackupHelper::computeApplyList($backup, $source, self::TARGETS, [], [], $skip)['apply'];
        }

        $this->service->applyUpdate($source, null, prune: $mode === 'prune', applyList: $applyList, skipPaths: $skip);

        foreach ($skip as $rel) {
            $this->assertDirectoryDoesNotExist($this->abs($fakeBase, $rel), "[{$mode}] 미배치 번들 {$rel} 은 배치되면 안 된다");
        }

        // 디스크에 있던 번들(설치 여부 무관)과 core 언어팩은 갱신
        $this->assertStringContainsString('1.3.0', File::get($this->abs($fakeBase, 'modules/_bundled/sirsoft-board/module.json')));
        $this->assertFileExists($this->abs($fakeBase, 'modules/_bundled/sirsoft-board/stale-free.php'));
        $this->assertStringContainsString('1.1.2', File::get($this->abs($fakeBase, 'lang-packs/_bundled/g7-core-ja/language-pack.json')));

        // 사용자 자체 _bundled/{id} 보존 (#388)
        $this->assertFileExists($this->abs($fakeBase, 'plugins/_bundled/acme-custom/plugin.json'));

        // prune 모드만 디스크 번들 내부 stale 을 정리하고, 나머지 모드는 보존한다
        $stale = $this->abs($fakeBase, 'modules/_bundled/sirsoft-board/old-stale.php');
        if ($mode === 'prune') {
            $this->assertFileDoesNotExist($stale, 'prune 모드는 디스크 번들 내부 stale 을 정리해야 한다');
        } else {
            $this->assertFileExists($stale);
        }
    }

    /**
     * 적용 모드 3종을 돌려줍니다.
     *
     * @return array<string, array{0: string}> 데이터셋 이름 => [모드]
     */
    public static function applyModeProvider(): array
    {
        return [
            'incremental' => ['incremental'],
            'prune' => ['prune'],
            'no-backup' => ['no-backup'],
        ];
    }

    /**
     * 자동 발견 폴백(`applyDiscoveredTopLevelPaths`)은 protected_paths 의 도메인 루트를 건드리지
     * 않으므로 skipPaths 로 건너뛴 번들이 폴백으로 다시 배치되지 않습니다 (S7 비회귀).
     *
     * @effects auto_discovery_fallback_does_not_place_skipped
     *
     * @return void 반환값 없음
     */
    public function test_auto_discovery_fallback_does_not_place_skipped_bundles(): void
    {
        [$source, $fakeBase] = $this->prepareEnv(['plugins/_bundled']);
        config(['app.update.protected_paths' => ['.env', 'storage', 'vendor', 'modules', 'plugins', 'templates', 'lang-packs']]);

        $this->writeFile($source, 'plugins/_bundled/sirsoft-gdpr/plugin.json', '{}');

        $this->service->applyUpdate($source, null, prune: false, applyList: null, skipPaths: ['plugins/_bundled/sirsoft-gdpr']);

        $this->assertDirectoryDoesNotExist($this->abs($fakeBase, 'plugins/_bundled/sirsoft-gdpr'));
    }

    /**
     * skipPaths 를 넘기지 않으면 기존 동작(신규 번들 배치)이 그대로입니다 (하위호환).
     *
     * @effects apply_update_default_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_apply_update_without_skip_paths_keeps_legacy_behavior(): void
    {
        [$source, $fakeBase] = $this->prepareEnv();
        $this->writeFile($source, 'plugins/_bundled/sirsoft-gdpr/plugin.json', '{}');

        $this->service->applyUpdate($source, null, prune: false, applyList: null);

        $this->assertFileExists($this->abs($fakeBase, 'plugins/_bundled/sirsoft-gdpr/plugin.json'));
    }

    /**
     * 격리 base_path·소스·백업 디렉토리와 config 를 준비합니다.
     *
     * @param  array<int, string>|null  $targets  config('app.update.targets') 값 (null 이면 번들 4도메인)
     * @return array{0: string, 1: string, 2: string} [소스, 가짜 base, 백업]
     */
    private function prepareEnv(?array $targets = null): array
    {
        $id = uniqid();
        $source = storage_path('test_bundled_skip_src_'.$id);
        $fakeBase = storage_path('test_bundled_skip_base_'.$id);
        $backup = storage_path('test_bundled_skip_backup_'.$id);
        foreach ([$source, $fakeBase, $backup] as $dir) {
            File::ensureDirectoryExists($dir);
            $this->tempDirs[] = $dir;
        }

        app()->setBasePath($fakeBase);
        config([
            'app.update.targets' => $targets ?? self::TARGETS,
            'app.update.excludes' => [],
            'app.update.protected_paths' => ['.env', 'storage', 'vendor', 'node_modules', '.git'],
        ]);

        return [$source, $fakeBase, $backup];
    }

    /**
     * 루트 아래 상대경로에 파일을 씁니다.
     *
     * @param  string  $root  루트 디렉토리
     * @param  string  $relative  슬래시 구분 상대경로
     * @param  string  $content  파일 내용
     * @return void 반환값 없음
     */
    private function writeFile(string $root, string $relative, string $content): void
    {
        $file = $this->abs($root, $relative);
        File::ensureDirectoryExists(dirname($file));
        File::put($file, $content);
    }

    /**
     * 루트와 슬래시 구분 상대경로를 플랫폼 절대경로로 합칩니다.
     *
     * @param  string  $root  루트 디렉토리
     * @param  string  $relative  슬래시 구분 상대경로
     * @return string 절대경로
     */
    private function abs(string $root, string $relative): string
    {
        return $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
