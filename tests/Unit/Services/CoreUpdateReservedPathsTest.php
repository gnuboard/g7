<?php

namespace Tests\Unit\Services;

use App\Extension\Helpers\CoreBackupHelper;
use App\Extension\Helpers\UpdateReservedPaths;
use App\Services\CoreUpdateService;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 코어 업데이트 「custom 예약 자리」 보호 단위 테스트 (#792 F0-1)
 *
 * 업데이트 대상 폴더(`routes`·`resources`·`app`…) 안쪽에 개발자가 둔 custom 파일이 증분·prune·백업 없음
 * 세 모드 어디에서도 덮이거나 지워지지 않는지, 롤백 manifest·prune 이 건드리지 않는지 잠근다.
 */
class CoreUpdateReservedPathsTest extends TestCase
{
    /** 예약 자리가 들어 있는 업데이트 대상 */
    private const TARGETS = ['app', 'routes', 'resources', 'public', 'lang', 'config', 'database'];

    /** 기본 예약 경로(config/app.php 기본값과 같다) */
    private const RESERVED = [
        'routes/custom.php',
        'app/Custom',
        'resources/views/custom',
        'public/custom',
        'lang/custom',
        'config/custom.php',
        'database/migrations/custom',
        'custom',
    ];

    /** 개발자 파일 — 예약 자리 7곳 + 하위 폴더 + custom composer */
    private const DEVELOPER_FILES = [
        'routes/custom.php' => '<?php // dev routes',
        'app/Custom/Http/Controllers/BoardController.php' => '<?php // dev controller',
        'app/Custom/CustomServiceProvider.php' => '<?php // dev provider',
        'resources/views/custom/board/list.blade.php' => 'dev list',
        'resources/views/custom/jquery/index.html' => '<html>dev</html>',
        'public/custom/example.js' => 'dev js',
        'public/custom/img/logo.svg' => '<svg/>',
        'lang/custom/ko/board.php' => '<?php return [];',
        'config/custom.php' => '<?php return [];',
        'database/migrations/custom/2026_10_16_000000_create_x.php' => '<?php // dev migration',
        'custom/composer.json' => '{"require":{"twig/twig":"^3.0"}}',
        'custom/vendor/autoload.php' => '<?php // dev vendor',
    ];

    private CoreUpdateService $service;

    private string $originalBasePath;

    /** @var array<int, string> */
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
     * 세 적용 모드 모두에서 예약 자리의 개발자 파일이 그대로 남고, 같은 폴더의 코어 파일은 갱신됩니다.
     *
     * @scenario case=apply_modes
     *
     * @effects reserved_files_survive_every_mode, core_files_in_same_target_updated
     *
     * @param  string  $mode  적용 모드 (incremental|prune|no-backup)
     * @return void 반환값 없음
     */
    #[DataProvider('applyModeProvider')]
    public function test_developer_files_in_reserved_paths_survive_every_mode(string $mode): void
    {
        [$source, $fakeBase, $backup] = $this->prepareEnv();

        // 새 배포본: 같은 대상 폴더의 코어 파일만 (예약 자리 없음)
        $this->writeFile($source, 'routes/web.php', '<?php // core v2');
        $this->writeFile($source, 'app/Http/Kernel.php', '<?php // core v2');
        $this->writeFile($source, 'resources/views/app.blade.php', 'core shell v2');
        $this->writeFile($source, 'lang/ko/auth.php', '<?php // core v2');
        $this->writeFile($source, 'config/app.php', '<?php // core v2');
        $this->writeFile($source, 'database/migrations/2026_01_01_000000_core.php', '<?php // core v2');
        $this->writeFile($source, 'public/index.php', '<?php // core v2');

        // 현재 디스크: 코어 구판 + 개발자 파일
        foreach (['routes/web.php', 'app/Http/Kernel.php', 'resources/views/app.blade.php', 'lang/ko/auth.php', 'config/app.php', 'database/migrations/2026_01_01_000000_core.php', 'public/index.php'] as $core) {
            $this->writeFile($fakeBase, $core, '<?php // core v1');
            $this->writeFile($backup, $core, '<?php // core v1');
        }
        foreach (self::DEVELOPER_FILES as $rel => $content) {
            $this->writeFile($fakeBase, $rel, $content);
            if (! str_starts_with($rel, 'custom/')) {
                $this->writeFile($backup, $rel, $content);
            }
        }

        $applyList = null;
        if ($mode === 'incremental') {
            $applyList = CoreBackupHelper::computeApplyList($backup, $source, self::TARGETS, [], [])['apply'];
        }

        $this->service->applyUpdate($source, null, prune: $mode === 'prune', applyList: $applyList);

        foreach (self::DEVELOPER_FILES as $rel => $content) {
            $this->assertFileExists($this->abs($fakeBase, $rel), "[{$mode}] 개발자 파일 {$rel} 이 지워지면 안 된다");
            $this->assertSame($content, File::get($this->abs($fakeBase, $rel)), "[{$mode}] 개발자 파일 {$rel} 이 덮이면 안 된다");
        }
        $this->assertSame('<?php // core v2', File::get($this->abs($fakeBase, 'routes/web.php')), "[{$mode}] 같은 폴더의 코어 파일은 갱신된다");
        $this->assertSame('core shell v2', File::get($this->abs($fakeBase, 'resources/views/app.blade.php')));
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
     * 배포본이 예약 자리에 같은 이름 파일을 실어도 적용 단계는 개발자 파일을 덮지 않고,
     * 사전 검사(findInSource)가 그 파일을 찾아 업데이트를 막을 근거를 준다.
     *
     * @scenario case=release_ships_reserved
     *
     * @effects release_reserved_file_detected, release_reserved_file_not_applied
     *
     * @return void 반환값 없음
     */
    public function test_release_shipping_reserved_file_is_detected_and_not_applied(): void
    {
        [$source, $fakeBase] = $this->prepareEnv();
        $this->writeFile($source, 'routes/custom.php', '<?php // core accidentally shipped');
        $this->writeFile($source, 'resources/views/custom/x.blade.php', 'core shipped');
        $this->writeFile($source, 'routes/web.php', '<?php // core v2');
        $this->writeFile($fakeBase, 'routes/custom.php', '<?php // dev routes');

        $found = UpdateReservedPaths::findInSource($source);
        $this->assertContains('routes/custom.php', $found);
        $this->assertContains('resources/views/custom/x.blade.php', $found);

        $this->service->applyUpdate($source, null, prune: true, applyList: null);

        $this->assertSame('<?php // dev routes', File::get($this->abs($fakeBase, 'routes/custom.php')));
        $this->assertFileDoesNotExist($this->abs($fakeBase, 'resources/views/custom/x.blade.php'));
    }

    /**
     * 예약 자리 판정은 접두가 같은 다른 이름(`app/CustomX`·`routes/custom.php.bak`)을 잡지 않습니다.
     *
     * @scenario case=segment_exact
     *
     * @effects reserved_prefix_is_segment_exact
     *
     * @return void 반환값 없음
     */
    public function test_contains_is_segment_exact(): void
    {
        config(['app.update.reserved_paths' => self::RESERVED]);

        $this->assertTrue(UpdateReservedPaths::contains('app/Custom/X.php'));
        $this->assertTrue(UpdateReservedPaths::contains('custom/vendor/autoload.php'));
        $this->assertTrue(UpdateReservedPaths::contains('routes\\custom.php'));
        $this->assertFalse(UpdateReservedPaths::contains('app/CustomX/Y.php'));
        $this->assertFalse(UpdateReservedPaths::contains('routes/custom.php.bak'));
        $this->assertFalse(UpdateReservedPaths::contains('customize/a.txt'));
    }

    /**
     * 신규 파일 manifest 와 적용 목록에 예약 자리가 들어가지 않고, manifest 에 들어가 있어도 롤백 prune 이 건너뜁니다.
     *
     * @scenario case=manifest_and_rollback
     *
     * @effects manifest_excludes_reserved, rollback_prune_skips_reserved
     *
     * @return void 반환값 없음
     */
    public function test_manifest_and_rollback_prune_skip_reserved_paths(): void
    {
        [$source, $fakeBase, $backup] = $this->prepareEnv();
        $this->writeFile($source, 'routes/custom.php', '<?php // shipped');
        $this->writeFile($source, 'routes/new-core.php', '<?php // new core file');

        $apply = CoreBackupHelper::computeApplyList($backup, $source, self::TARGETS, [], []);
        $this->assertContains('routes/new-core.php', $apply['apply']);
        $this->assertNotContains('routes/custom.php', $apply['apply']);

        CoreBackupHelper::writeNewFilesManifest($backup, $source, self::TARGETS, [], [], '7.1.1', '7.2.0');
        $manifest = json_decode(File::get($backup.DIRECTORY_SEPARATOR.CoreBackupHelper::NEW_FILES_MANIFEST), true);
        $this->assertContains('routes/new-core.php', $manifest['new_files']);
        $this->assertNotContains('routes/custom.php', $manifest['new_files']);

        // manifest 가 (구버전이 만들어) 예약 자리를 담고 있어도 롤백 prune 은 지우지 않는다
        $manifest['new_files'][] = 'routes/custom.php';
        $manifest['new_dirs'][] = 'resources/views/custom';
        File::put($backup.DIRECTORY_SEPARATOR.CoreBackupHelper::NEW_FILES_MANIFEST, json_encode($manifest));
        $this->writeFile($fakeBase, 'routes/custom.php', '<?php // dev');
        File::ensureDirectoryExists($this->abs($fakeBase, 'resources/views/custom'));
        $this->writeFile($fakeBase, 'routes/new-core.php', '<?php // new core file');

        $result = CoreBackupHelper::pruneNewFiles($backup, $fakeBase, []);

        $this->assertFileExists($this->abs($fakeBase, 'routes/custom.php'));
        $this->assertDirectoryExists($this->abs($fakeBase, 'resources/views/custom'));
        $this->assertFileDoesNotExist($this->abs($fakeBase, 'routes/new-core.php'));
        $this->assertGreaterThanOrEqual(2, $result['protected_count']);
    }

    /**
     * 최상위 `custom/`(개발자 composer)은 protected_paths 라 자동 발견 폴백이 복사하지 않습니다.
     *
     * @scenario case=auto_discovery
     *
     * @effects top_level_custom_not_auto_discovered
     *
     * @return void 반환값 없음
     */
    public function test_top_level_custom_is_not_auto_discovered(): void
    {
        [$source, $fakeBase] = $this->prepareEnv(['routes']);
        config(['app.update.protected_paths' => ['.env', 'storage', 'vendor', 'custom']]);
        $this->writeFile($source, 'custom/composer.json', '{"core":"shipped"}');
        $this->writeFile($fakeBase, 'custom/composer.json', '{"require":{}}');

        $this->service->applyUpdate($source, null, prune: true, applyList: null);

        $this->assertSame('{"require":{}}', File::get($this->abs($fakeBase, 'custom/composer.json')));
    }

    /**
     * 격리 base_path·소스·백업 디렉토리와 config 를 준비합니다.
     *
     * @param  array<int, string>|null  $targets  config('app.update.targets') 값
     * @return array{0: string, 1: string, 2: string} [소스, 가짜 base, 백업]
     */
    private function prepareEnv(?array $targets = null): array
    {
        $id = uniqid();
        $source = storage_path('test_reserved_src_'.$id);
        $fakeBase = storage_path('test_reserved_base_'.$id);
        $backup = storage_path('test_reserved_backup_'.$id);
        foreach ([$source, $fakeBase, $backup] as $dir) {
            File::ensureDirectoryExists($dir);
            $this->tempDirs[] = $dir;
        }

        app()->setBasePath($fakeBase);
        config([
            'app.update.targets' => $targets ?? self::TARGETS,
            'app.update.excludes' => [],
            'app.update.protected_paths' => ['.env', 'storage', 'vendor', 'node_modules', '.git', 'custom'],
            'app.update.reserved_paths' => self::RESERVED,
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
