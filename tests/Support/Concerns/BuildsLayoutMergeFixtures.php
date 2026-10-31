<?php

namespace Tests\Support\Concerns;

use App\Enums\ExtensionStatus;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\LayoutExtension;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use App\Models\TemplateLayout;
use Illuminate\Support\Facades\File;

/**
 * 레이아웃 3자 병합 회귀 테스트용 픽스처 — 디스크에 가짜 모듈·템플릿을 만들고 판(v1→v2)을 바꿉니다.
 *
 * 실제 확장 업데이트(파일 교체·다운로드)는 네트워크·디스크에 의존하므로, 업데이트 경로가 부르는 레이아웃
 * 동기화 단계를 **실제 파일로** 돌린다. 레이아웃은 `.json` 으로 써서 템플릿 컴포넌트 레지스트리 없이도
 * 설치·갱신이 된다(조각은 `{"partial": "partials/…"}` + 조각 파일의 `meta.is_partial`).
 *
 * 스키마를 새로 만들지 않으므로(DatabaseTransactions) 식별자를 이 픽스처 전용 이름으로 고정하고,
 * 디스크 산출물은 tearDown 에서 지운다.
 */
trait BuildsLayoutMergeFixtures
{
    /** 가짜 모듈 식별자 (네임스페이스 Modules\Testmerge\Kmod) */
    protected string $fixtureModule = 'testmerge-kmod';

    /** 가짜 플러그인 식별자 (네임스페이스 Plugins\Testmerge\Kplug) */
    protected string $fixturePlugin = 'testmerge-kplug';

    /** 가짜 관리자 템플릿 식별자 */
    protected string $fixtureTemplate = 'testmerge-ktpl';

    /** 테스트가 만든 디스크 경로 (tearDown 정리 대상) */
    protected array $fixturePaths = [];

    protected ?Template $fixtureTemplateModel = null;

    /**
     * 픽스처 디스크 산출물을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function cleanupLayoutMergeFixtures(): void
    {
        foreach ($this->fixturePaths as $path) {
            if (File::exists($path)) {
                File::deleteDirectory($path);
            }
        }

        $this->fixturePaths = [];
    }

    /**
     * 가짜 관리자 템플릿(DB 행 + layouts 디렉토리)을 만듭니다 — 모듈 레이아웃이 붙을 곳.
     *
     * @param  array<string, array<string, mixed>>  $layouts  템플릿 자기 레이아웃 (상대 경로 => JSON)
     * @param  string  $status  템플릿 상태
     * @return Template 생성된 템플릿
     */
    protected function makeFixtureTemplate(array $layouts = [], string $status = 'active'): Template
    {
        $path = base_path("templates/{$this->fixtureTemplate}");
        $this->fixturePaths[] = $path;
        File::ensureDirectoryExists($path.'/layouts');
        $this->writeFiles($path.'/layouts', $layouts);

        $this->fixtureTemplateModel = Template::updateOrCreate(
            ['identifier' => $this->fixtureTemplate],
            [
                'vendor' => 'testmerge',
                'name' => ['ko' => '병합 테스트 관리자', 'en' => 'Merge Test Admin'],
                'version' => '1.0.0',
                'type' => 'admin',
                'status' => $status,
                'description' => ['ko' => '테스트용', 'en' => 'For testing'],
            ]
        );

        return $this->fixtureTemplateModel;
    }

    /**
     * 가짜 모듈(디스크 + DB 행)을 만들고 모듈 관리자에 다시 읽힙니다.
     *
     * @param  array<string, array<string, mixed>>  $layouts  `resources/layouts` 기준 상대 경로 => JSON
     * @param  array<string, array<string, mixed>>  $extensions  `resources/extensions` 기준 파일명 => JSON
     * @return ModuleManager 모듈을 다시 읽은 관리자
     */
    protected function makeFixtureModule(array $layouts, array $extensions = []): ModuleManager
    {
        $path = base_path("modules/{$this->fixtureModule}");
        $this->fixturePaths[] = $path;
        File::ensureDirectoryExists($path);

        File::put($path.'/module.php', <<<'PHP'
<?php

namespace Modules\Testmerge\Kmod;

use App\Extension\AbstractModule;

class Module extends AbstractModule
{
    public function getName(): string|array
    {
        return ['ko' => '병합 테스트 모듈', 'en' => 'Merge Test Module'];
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string|array
    {
        return ['ko' => '테스트용', 'en' => 'For testing'];
    }
}
PHP);
        File::put($path.'/module.json', json_encode([
            'identifier' => $this->fixtureModule,
            'vendor' => 'testmerge',
            'name' => ['ko' => '병합 테스트 모듈', 'en' => 'Merge Test Module'],
            'version' => '1.0.0',
        ], JSON_PRETTY_PRINT));

        $this->writeModuleFiles($layouts, $extensions);

        Module::updateOrCreate(
            ['identifier' => $this->fixtureModule],
            [
                'vendor' => 'testmerge',
                'name' => ['ko' => '병합 테스트 모듈', 'en' => 'Merge Test Module'],
                'version' => '1.0.0',
                'status' => ExtensionStatus::Active->value,
                'description' => ['ko' => '테스트용', 'en' => 'For testing'],
            ]
        );

        return $this->reloadFixtureModuleManager();
    }

    /**
     * 모듈 파일을 새 판으로 바꿉니다 — layouts·extensions 디렉토리를 통째로 다시 씁니다.
     *
     * @param  array<string, array<string, mixed>>  $layouts  `resources/layouts` 기준 상대 경로 => JSON
     * @param  array<string, array<string, mixed>>  $extensions  `resources/extensions` 기준 파일명 => JSON
     * @return void 반환값 없음
     */
    protected function writeModuleFiles(array $layouts, array $extensions = []): void
    {
        $path = base_path("modules/{$this->fixtureModule}/resources");
        File::deleteDirectory($path.'/layouts');
        File::deleteDirectory($path.'/extensions');

        if ($layouts !== []) {
            File::ensureDirectoryExists($path.'/layouts');
            $this->writeFiles($path.'/layouts', $layouts);
        }

        if ($extensions !== []) {
            File::ensureDirectoryExists($path.'/extensions');
            $this->writeFiles($path.'/extensions', $extensions);
        }

        clearstatcache();
    }

    /**
     * 모듈 디렉토리 통째를 백업 경로(업데이트가 만드는 것과 같은 모양)로 복사합니다.
     *
     * @return string 백업 경로 (`storage/app/extension_backups/modules/{id}_{ts}`)
     */
    protected function backupFixtureModule(): string
    {
        $backup = storage_path('app/extension_backups/modules/'.$this->fixtureModule.'_'.date('Ymd_His').'_'.substr(md5((string) microtime(true)), 0, 6));
        $this->fixturePaths[] = $backup;
        File::copyDirectory(base_path("modules/{$this->fixtureModule}"), $backup);

        return $backup;
    }

    /**
     * 모듈 관리자를 새로 만들어 픽스처 모듈을 읽게 합니다.
     *
     * @return ModuleManager 모듈 관리자
     */
    protected function reloadFixtureModuleManager(): ModuleManager
    {
        ModuleManager::invalidateModuleStatusCache();
        app()->forgetInstance(ModuleManager::class);
        $manager = app(ModuleManager::class);
        $manager->loadModules();

        return $manager;
    }

    /**
     * 가짜 플러그인(디스크 + DB 행)을 만들고 플러그인 관리자에 다시 읽힙니다 — 모듈 픽스처와 같은 모양.
     *
     * @param  array<string, array<string, mixed>>  $layouts  `resources/layouts` 기준 상대 경로 => JSON
     * @param  array<string, array<string, mixed>>  $extensions  `resources/extensions` 기준 파일명 => JSON
     * @return PluginManager 플러그인을 다시 읽은 관리자
     */
    protected function makeFixturePlugin(array $layouts, array $extensions = []): PluginManager
    {
        $path = base_path("plugins/{$this->fixturePlugin}");
        $this->fixturePaths[] = $path;
        File::ensureDirectoryExists($path);

        File::put($path.'/plugin.php', <<<'PHP'
<?php

namespace Plugins\Testmerge\Kplug;

use App\Extension\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    public function getName(): string|array
    {
        return ['ko' => '병합 테스트 플러그인', 'en' => 'Merge Test Plugin'];
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string|array
    {
        return ['ko' => '테스트용', 'en' => 'For testing'];
    }
}
PHP);
        File::put($path.'/plugin.json', json_encode([
            'identifier' => $this->fixturePlugin,
            'vendor' => 'testmerge',
            'name' => ['ko' => '병합 테스트 플러그인', 'en' => 'Merge Test Plugin'],
            'version' => '1.0.0',
        ], JSON_PRETTY_PRINT));

        $this->writePluginFiles($layouts, $extensions);

        Plugin::updateOrCreate(
            ['identifier' => $this->fixturePlugin],
            [
                'vendor' => 'testmerge',
                'name' => ['ko' => '병합 테스트 플러그인', 'en' => 'Merge Test Plugin'],
                'version' => '1.0.0',
                'status' => ExtensionStatus::Active->value,
                'description' => ['ko' => '테스트용', 'en' => 'For testing'],
            ]
        );

        return $this->reloadFixturePluginManager();
    }

    /**
     * 플러그인 파일을 새 판으로 바꿉니다 — layouts·extensions 디렉토리를 통째로 다시 씁니다.
     *
     * @param  array<string, array<string, mixed>>  $layouts  `resources/layouts` 기준 상대 경로 => JSON
     * @param  array<string, array<string, mixed>>  $extensions  `resources/extensions` 기준 파일명 => JSON
     * @return void 반환값 없음
     */
    protected function writePluginFiles(array $layouts, array $extensions = []): void
    {
        $this->rewriteResources(base_path("plugins/{$this->fixturePlugin}/resources"), $layouts, $extensions);
    }

    /**
     * 플러그인 관리자를 새로 만들어 픽스처 플러그인을 읽게 합니다.
     *
     * @return PluginManager 플러그인 관리자
     */
    protected function reloadFixturePluginManager(): PluginManager
    {
        PluginManager::invalidatePluginStatusCache();
        app()->forgetInstance(PluginManager::class);
        $manager = app(PluginManager::class);
        $manager->loadPlugins();

        return $manager;
    }

    /**
     * 플러그인 디렉토리 통째를 백업 경로(업데이트가 만드는 것과 같은 모양)로 복사합니다.
     *
     * @return string 백업 경로 (`storage/app/extension_backups/plugins/{id}_{ts}`)
     */
    protected function backupFixturePlugin(): string
    {
        return $this->backupDirectory(base_path("plugins/{$this->fixturePlugin}"), 'plugins', $this->fixturePlugin);
    }

    /**
     * 템플릿 디렉토리 통째를 백업 경로(업데이트가 만드는 것과 같은 모양)로 복사합니다.
     *
     * @return string 백업 경로 (`storage/app/extension_backups/templates/{id}_{ts}`)
     */
    protected function backupFixtureTemplate(): string
    {
        return $this->backupDirectory(base_path("templates/{$this->fixtureTemplate}"), 'templates', $this->fixtureTemplate);
    }

    /**
     * 템플릿 자기 레이아웃을 새 판으로 바꿉니다 — `layouts` 디렉토리를 통째로 다시 씁니다.
     *
     * @param  array<string, array<string, mixed>>  $layouts  `layouts` 기준 상대 경로 => JSON
     * @return void 반환값 없음
     */
    protected function writeTemplateLayouts(array $layouts): void
    {
        $root = base_path("templates/{$this->fixtureTemplate}/layouts");
        File::deleteDirectory($root);
        File::ensureDirectoryExists($root);
        $this->writeFiles($root, $layouts);
        clearstatcache();
    }

    /**
     * 템플릿의 확장 조각 오버라이드(`extensions/{대상 모듈}/`)를 새 판으로 바꿉니다.
     *
     * @param  string  $overrideTarget  오버라이드 대상 모듈 식별자 (디렉토리명)
     * @param  array<string, array<string, mixed>>  $fragments  파일명 => JSON (빈 배열이면 디렉토리 삭제)
     * @return void 반환값 없음
     */
    protected function writeTemplateExtensionOverrides(string $overrideTarget, array $fragments): void
    {
        $root = base_path("templates/{$this->fixtureTemplate}/extensions/{$overrideTarget}");
        File::deleteDirectory($root);

        if ($fragments !== []) {
            File::ensureDirectoryExists($root);
            $this->writeFiles($root, $fragments);
        }

        clearstatcache();
    }

    /**
     * 템플릿 매니페스트(`template.json`)를 써서 템플릿 관리자가 픽스처 템플릿을 읽게 합니다 (API 조회용).
     *
     * @return void 반환값 없음
     */
    protected function writeFixtureTemplateManifest(): void
    {
        File::put(base_path("templates/{$this->fixtureTemplate}/template.json"), json_encode([
            'identifier' => $this->fixtureTemplate,
            'vendor' => 'testmerge',
            'name' => ['ko' => '병합 테스트 관리자', 'en' => 'Merge Test Admin'],
            'version' => '1.0.0',
            'type' => 'admin',
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 픽스처 템플릿에 등록된 플러그인 레이아웃 행을 찾습니다.
     *
     * @param  string  $baseName  플러그인 접두어를 뺀 레이아웃 이름
     * @return TemplateLayout|null 행 (지운 행 포함)
     */
    protected function fixturePluginLayout(string $baseName): ?TemplateLayout
    {
        return TemplateLayout::withTrashed()
            ->where('template_id', $this->fixtureTemplateModel->id)
            ->where('name', $this->fixturePlugin.'.'.$baseName)
            ->first();
    }

    /**
     * 디렉토리 통째를 확장 업데이트 백업 경로로 복사합니다.
     *
     * @param  string  $source  복사할 디렉토리
     * @param  string  $type  확장 유형 디렉토리 (`modules`·`plugins`·`templates`)
     * @param  string  $identifier  확장 식별자
     * @return string 백업 경로
     */
    private function backupDirectory(string $source, string $type, string $identifier): string
    {
        $backup = storage_path("app/extension_backups/{$type}/".$identifier.'_'.date('Ymd_His').'_'.substr(md5((string) microtime(true)), 0, 6));
        $this->fixturePaths[] = $backup;
        File::copyDirectory($source, $backup);

        return $backup;
    }

    /**
     * 확장의 `resources/layouts`·`resources/extensions` 를 통째로 다시 씁니다.
     *
     * @param  string  $resources  `resources` 디렉토리
     * @param  array<string, array<string, mixed>>  $layouts  레이아웃 파일
     * @param  array<string, array<string, mixed>>  $extensions  조각 파일
     * @return void 반환값 없음
     */
    private function rewriteResources(string $resources, array $layouts, array $extensions): void
    {
        File::deleteDirectory($resources.'/layouts');
        File::deleteDirectory($resources.'/extensions');

        if ($layouts !== []) {
            File::ensureDirectoryExists($resources.'/layouts');
            $this->writeFiles($resources.'/layouts', $layouts);
        }

        if ($extensions !== []) {
            File::ensureDirectoryExists($resources.'/extensions');
            $this->writeFiles($resources.'/extensions', $extensions);
        }

        clearstatcache();
    }

    /**
     * 픽스처 템플릿에 등록된 모듈 레이아웃 행을 찾습니다.
     *
     * @param  string  $baseName  모듈 접두어를 뺀 레이아웃 이름
     * @return TemplateLayout|null 행 (지운 행 포함)
     */
    protected function fixtureModuleLayout(string $baseName): ?TemplateLayout
    {
        return TemplateLayout::withTrashed()
            ->where('template_id', $this->fixtureTemplateModel->id)
            ->where('name', $this->fixtureModule.'.'.$baseName)
            ->first();
    }

    /**
     * 운영자가 편집기로 저장한 것처럼 행의 본문만 바꿉니다 — 원본 해시·원본 본문은 그대로 둡니다.
     *
     * @param  TemplateLayout|LayoutExtension  $row  대상 행
     * @param  callable(array): array  $mutate  본문 변형
     * @return void 반환값 없음
     */
    protected function operatorEdits($row, callable $mutate): void
    {
        $content = $row->content;
        $row->content = $mutate($content);
        $row->saveQuietly();
    }

    /**
     * 본문을 JSON 문자열로 펼쳐 문자열 포함 여부를 봅니다.
     *
     * @param  mixed  $content  본문
     * @return string JSON 문자열
     */
    protected function flat(mixed $content): string
    {
        return json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 조각이 둘 들어간 목록 화면 + 조각 파일 두 개를 만듭니다 (판마다 문구만 다르게).
     *
     * @param  string  $aText  조각 A 문구
     * @param  string  $bText  조각 B 문구
     * @param  int  $perPage  data_sources 의 per_page
     * @return array<string, array<string, mixed>> 상대 경로 => JSON
     */
    protected function listScreenFiles(string $aText, string $bText, int $perPage = 10): array
    {
        return [
            'admin/merge_list.json' => [
                'version' => '1.0.0',
                'layout_name' => 'merge_list',
                'data_sources' => [
                    ['id' => 'items', 'type' => 'api', 'endpoint' => '/api/items', 'params' => ['per_page' => $perPage]],
                ],
                'slots' => [
                    'content' => [
                        ['id' => 'title', 'type' => 'basic', 'name' => 'H1', 'text' => '목록'],
                        ['partial' => 'partials/merge_list/_a.json'],
                        ['partial' => 'partials/merge_list/_b.json'],
                    ],
                ],
            ],
            'admin/partials/merge_list/_a.json' => [
                'meta' => ['is_partial' => true],
                'id' => 'part_a', 'type' => 'basic', 'name' => 'Div', 'text' => $aText,
            ],
            'admin/partials/merge_list/_b.json' => [
                'meta' => ['is_partial' => true],
                'id' => 'part_b', 'type' => 'basic', 'name' => 'Div', 'text' => $bText,
            ],
        ];
    }

    /**
     * 파일 묶음을 디렉토리에 씁니다.
     *
     * @param  string  $root  기준 디렉토리
     * @param  array<string, array<string, mixed>>  $files  상대 경로 => JSON
     * @return void 반환값 없음
     */
    private function writeFiles(string $root, array $files): void
    {
        foreach ($files as $relative => $json) {
            $file = $root.'/'.$relative;
            File::ensureDirectoryExists(dirname($file));
            File::put($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }
}
