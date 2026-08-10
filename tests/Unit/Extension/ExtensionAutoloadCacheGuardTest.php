<?php

namespace Tests\Unit\Extension;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Extension\ExtensionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 확장 오토로드 캐시(bootstrap/cache/autoload-extensions.php) 파괴 방지 가드 테스트.
 *
 * 이 캐시는 `Modules\*` / `Plugins\*` PSR-4 매핑의 단일 소스라, 빈 배열로 덮어써지면
 * 모든 모듈/플러그인 컨트롤러가 "Target class does not exist" 로 사이트 전역 500 이 된다.
 *
 * 생성기는 "DB 설치 목록 × 디스크 활성 디렉토리" 교집합이므로, DB 쪽만 일시적으로
 * 비어도(테이블 부재 · 설치 중간 상태 · testing DB) 교집합이 공집합이 되어 정상 매핑이
 * 소실된다. 본 테스트는 그 소실을 차단하는 두 가드를 고정한다.
 */
class ExtensionAutoloadCacheGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 실 오토로드 캐시 파일 경로
     */
    private string $realCachePath;

    /**
     * 실 캐시 파일의 테스트 시작 시점 내용 (없으면 null)
     */
    private ?string $realCacheBackup = null;

    /**
     * 임시 오토로드 캐시 파일 경로
     */
    private string $tmpCachePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->realCachePath = base_path('bootstrap/cache/autoload-extensions.php');
        $this->tmpCachePath = storage_path('framework/testing/autoload_guard_'.uniqid().'.php');

        File::ensureDirectoryExists(dirname($this->tmpCachePath));

        // 가드가 없는 상태(= 최초 fail 실행)에서도 개발 환경 캐시가 파괴되지 않도록 원본 보존.
        if (File::exists($this->realCachePath)) {
            $this->realCacheBackup = File::get($this->realCachePath);
        }
    }

    protected function tearDown(): void
    {
        if ($this->realCacheBackup !== null) {
            File::put($this->realCachePath, $this->realCacheBackup);
        }

        if (File::exists($this->tmpCachePath)) {
            File::delete($this->tmpCachePath);
        }

        parent::tearDown();
    }

    /**
     * 디스크에 활성 확장 디렉토리가 있는데 DB 조회 결과가 0건이면 빈 매핑을 쓰지 않아야 한다.
     *
     * DB 쪽이 신뢰 불가한 상태(테이블 부재 · 설치 중간 · 다른 DB)에서 산출된 공집합을
     * 그대로 기록하면 정상 매핑이 소실된다. 이 경우 기존 파일을 보존해야 한다.
     */
    public function test_generate_autoload_file_preserves_existing_map_when_db_reports_none_but_directories_exist(): void
    {
        $this->assertNotEmpty(
            $this->installedLookingExtensionDirectories(),
            '이 테스트는 디스크에 활성 확장 디렉토리가 존재하는 저장소에서만 유효합니다.'
        );

        // RefreshDatabase 로 modules/plugins 테이블은 비어 있다 (= DB 설치 목록 0건).
        $manager = $this->managerWritingTo($this->tmpCachePath);

        // 정상 매핑이 이미 존재하는 상태를 재현
        File::put($this->tmpCachePath, $this->cacheFileWithPsr4([
            'Modules\\Sirsoft\\Board\\' => 'modules/sirsoft-board/src/',
        ]));

        $manager->generateAutoloadFile();

        $written = require $this->tmpCachePath;

        $this->assertNotEmpty(
            $written['psr4'],
            'DB 조회가 0건이어도 디스크에 활성 확장 디렉토리가 있으면 기존 PSR-4 매핑을 빈 배열로 덮어쓰면 안 됩니다. '
            .'(덮어쓰면 모든 모듈/플러그인 라우트가 500 — Target class does not exist)'
        );
    }

    /**
     * 테스트 환경에서는 실 bootstrap/cache 캐시 파일을 절대 건드리지 않아야 한다.
     *
     * testing DB 는 활성 확장 구성이 개발 DB 와 다르므로, 어떤 경로로든 실 캐시가
     * 재생성되면 개발 환경 매핑이 testing 기준으로 교체되어 사이트가 깨진다.
     */
    public function test_generate_autoload_file_does_not_touch_real_cache_path_in_testing_env(): void
    {
        $this->assertNotNull(
            $this->realCacheBackup,
            '이 테스트는 실 오토로드 캐시가 생성된 환경에서만 유효합니다 (extension:update-autoload 선행 필요).'
        );

        app(ExtensionManager::class)->generateAutoloadFile();

        $this->assertSame(
            $this->realCacheBackup,
            File::get($this->realCachePath),
            'testing 환경에서는 실 bootstrap/cache/autoload-extensions.php 를 재생성하면 안 됩니다.'
        );
    }

    /**
     * extension:update-autoload 는 훅 캐시 재생성 전에 런타임 오토로드를 재등록해야 한다.
     *
     * 미재등록 시 현재 프로세스의 ClassLoader 는 (방금 교체된) 이전 매핑을 들고 있어
     * ModuleManager/PluginManager 가 확장 클래스를 로드하지 못하고, 훅 캐시가
     * modules/plugins 0건으로 생성된다 — 모든 확장 훅이 조용히 사라진다.
     */
    public function test_update_autoload_command_reregisters_runtime_autoload_before_hook_cache(): void
    {
        $body = File::get(app_path('Console/Commands/Extension/UpdateAutoloadCommand.php'));

        $reregisterPos = strpos($body, 'reregisterRuntimeAutoload(');
        $hookCachePos = strpos($body, '->generate($moduleManager, $pluginManager)');

        $this->assertNotFalse(
            $reregisterPos,
            'extension:update-autoload 는 런타임 오토로드 재등록을 호출해야 합니다.'
        );
        $this->assertNotFalse($hookCachePos, '훅 캐시 재생성 호출을 찾을 수 없습니다.');

        $this->assertLessThan(
            $hookCachePos,
            $reregisterPos,
            '런타임 오토로드 재등록은 훅 캐시 재생성보다 먼저 수행되어야 합니다 '
            .'(순서가 뒤바뀌면 훅 캐시가 modules/plugins 0건으로 생성됨).'
        );
    }

    /**
     * 지정 경로에 기록하는 ExtensionManager 인스턴스를 생성합니다.
     *
     * @param  string  $path  오토로드 캐시 파일 경로
     * @return ExtensionManager 경로가 치환된 매니저
     */
    private function managerWritingTo(string $path): ExtensionManager
    {
        return new class(app(ModuleRepositoryInterface::class), app(PluginRepositoryInterface::class), $path) extends ExtensionManager
        {
            public function __construct(
                ModuleRepositoryInterface $moduleRepository,
                PluginRepositoryInterface $pluginRepository,
                string $path
            ) {
                parent::__construct($moduleRepository, $pluginRepository);
                $this->autoloadFilePath = $path;
            }
        };
    }

    /**
     * 디스크에 존재하는 "설치된 것으로 보이는" 확장 디렉토리 목록을 반환합니다.
     *
     * @return array<int, string> 디렉토리 절대경로 목록
     */
    private function installedLookingExtensionDirectories(): array
    {
        $found = [];

        foreach (['modules', 'plugins'] as $type) {
            $base = base_path($type);
            if (! File::isDirectory($base)) {
                continue;
            }

            foreach (File::directories($base) as $dir) {
                if (str_starts_with(basename($dir), '_')) {
                    continue;
                }
                if (File::exists($dir.'/composer.json')) {
                    $found[] = $dir;
                }
            }
        }

        return $found;
    }

    /**
     * 주어진 PSR-4 매핑을 담은 캐시 파일 내용을 생성합니다.
     *
     * @param  array<string, string>  $psr4  PSR-4 매핑
     * @return string 캐시 파일 내용
     */
    private function cacheFileWithPsr4(array $psr4): string
    {
        return '<?php return '.var_export([
            'psr4' => $psr4,
            'classmap' => [],
            'src_classmap' => [],
            'files' => [],
            'vendor_autoloads' => [],
        ], true).';';
    }
}
