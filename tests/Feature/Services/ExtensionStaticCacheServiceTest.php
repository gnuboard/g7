<?php

namespace Tests\Feature\Services;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use App\Services\ExtensionBundleService;
use App\Services\ExtensionStaticCacheService;
use App\Services\LanguagePackService;
use App\Services\TemplateService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 부트스트랩 리소스 정적 게시(bake) 서비스 테스트 (#122 S1).
 *
 * 게시 트리 형상(병합 결과 = 폴백 API 페이로드), 원자성(tmp → rename → manifest
 * 마지막), 멱등(skip), 비활성 산출물 부재, 경로 검증 거부, GC 보존 정책,
 * kill-switch, terminating 트리거 환경 게이트를 검증한다.
 */
class ExtensionStaticCacheServiceTest extends TestCase
{
    use RefreshDatabase;

    private const VERSION = 987654321;

    /** 테스트 전용 public 루트 (실 게시 트리 격리) */
    private string $isolatedPublicPath;

    protected function setUp(): void
    {
        parent::setUp();

        // 실 게시 트리(public/build/ext) 격리.
        //
        // GC 케이스가 "삭제 3건 / VERSION+300 만 잔존" 을 단언하므로 이 테스트는
        // base 디렉토리가 비어 있는 상태에서 출발해야 한다. 그런데 `baseDir()` 은
        // `public_path()` 기준이라, base 를 비우는 것이 곧 **운영 중 사이트의 게시본을
        // 지우는 것**이 된다 (테스트 1건 실행만으로 전량 소실 — 자가 치유가 복구하기
        // 전까지 구 HTML 이 참조하는 자산이 404 → 폴백). 그래서 base 를 비우는 대신
        // public 루트 자체를 테스트 전용 임시 경로로 돌린다.
        $this->isolatedPublicPath = storage_path('framework/testing/public-'.getmypid());
        File::ensureDirectoryExists($this->isolatedPublicPath);
        $this->app->usePublicPath($this->isolatedPublicPath);

        // 결정적 버전 시드 (getExtensionCacheVersion 폴백 재생성 방지)
        Cache::put('g7:core:ext.cache_version', self::VERSION);

        // 이전 실행이 비정상 종료로 남긴 게시 락 잔존물 정리 (결정적 실행 보장)
        Cache::lock('ext-static.publish.'.self::VERSION, 300)->forceRelease();
        Cache::lock('ext-static.publish.'.(self::VERSION + 1), 300)->forceRelease();

        // 실패 마커 격리 — 남아 있으면 백오프가 걸려 다음 테스트의 게시 예약이 조용히 스킵된다.
        Cache::forget('g7:core:ext.static.publish_failure');

        ExtensionStaticCacheService::resetPublishScheduleForTesting();
        $this->cleanBaseDir();
    }

    protected function tearDown(): void
    {
        $this->cleanBaseDir();
        File::deleteDirectory($this->isolatedPublicPath);
        ExtensionStaticCacheService::resetPublishScheduleForTesting();

        parent::tearDown();
    }

    private function cleanBaseDir(): void
    {
        File::deleteDirectory(public_path('build/ext'));
    }

    /**
     * 게시 실패 시 어느 분기에서 멈췄는지 보여 줍니다.
     *
     * `publishCurrent()` 는 여러 사유로 **조용히 false** 를 돌려준다(kill-switch·락 미획득·
     * 게시 중 예외). 단언 메시지가 "false is true" 뿐이면 그중 무엇인지 알 수 없어, 간헐적
     * 실패를 만났을 때 재현부터 다시 해야 한다.
     *
     * @param  ExtensionStaticCacheService  $svc  대상 서비스
     * @return string 진단 문자열
     */
    private function publishDiagnostics(ExtensionStaticCacheService $svc): string
    {
        $v = ExtensionStaticCacheService::getExtensionCacheVersion();
        $lock = Cache::lock('ext-static.publish.'.$v, 1);
        $free = $lock->get();
        if ($free) {
            $lock->release();
        }
        $store = config('cache.default');

        // 실패 마커까지 보여 준다 — 프리플라이트/쓰기 실패는 마커에 사유를 남기므로,
        // 마커가 있으면 어느 분기였는지 즉시 알 수 있고 없으면 그 두 분기가 아니었다는 뜻이다.
        // (마커 없이 false 면 kill-switch 또는 락 미획득이다.)
        $marker = ExtensionStaticCacheService::failureMarker();

        return sprintf(
            'enabled=%s version=%d expected=%d lockFree=%s store=%s published=%s marker=%s baseDir=%s',
            var_export($svc->isEnabled(), true), $v, self::VERSION, var_export($free, true),
            $store, var_export($svc->isPublished($v), true),
            $marker === null ? 'none' : ($marker['reason'].':'.$marker['message']),
            $svc->baseDir()
        );
    }

    private function service(): ExtensionStaticCacheService
    {
        return app(ExtensionStaticCacheService::class);
    }

    /**
     * 활성 모듈·플러그인 행을 만듭니다.
     *
     * 레포지토리를 모킹하지 않는다 — 게시에는 번들 서비스 등 같은 레포지토리를 쓰는 다른
     * 소비자가 있어, 인터페이스를 통째로 대체하면 그쪽이 끊겨 게시 자체가 실패한다.
     * `RefreshDatabase` 라 행은 테스트마다 비어 있으므로 실제 설치본에 좌우되지도 않는다.
     *
     * @param  array<int, string>  $modules  활성 모듈 식별자
     * @param  array<int, string>  $plugins  활성 플러그인 식별자
     */
    private function createActiveExtensions(array $modules, array $plugins): void
    {
        foreach ($modules as $identifier) {
            Module::create([
                'identifier' => $identifier,
                'vendor' => explode('-', $identifier)[0],
                'name' => ['ko' => '테스트 모듈', 'en' => 'Test Module'],
                'version' => '1.0.0',
                'status' => ExtensionStatus::Active->value,
                'description' => ['ko' => '테스트', 'en' => 'Test'],
            ]);
        }

        foreach ($plugins as $identifier) {
            Plugin::create([
                'identifier' => $identifier,
                'vendor' => explode('-', $identifier)[0],
                'name' => ['ko' => '테스트 플러그인', 'en' => 'Test Plugin'],
                'version' => '1.0.0',
                'status' => ExtensionStatus::Active->value,
                'description' => ['ko' => '테스트', 'en' => 'Test'],
            ]);
        }
    }

    private function createActiveTemplate(string $identifier = 'sirsoft-admin_basic', string $type = 'admin'): Template
    {
        return Template::create([
            'identifier' => $identifier,
            'vendor' => explode('-', $identifier)[0],
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => $type,
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트', 'en' => 'Test'],
        ]);
    }

    /**
     * 활성 템플릿 0개(설치 직전)여도 예외 없이 빈 게시가 성립한다.
     *
     * @scenario publish_state=unpublished, artifact_integrity=intact, filesystem_writable=writable, environment=production, trigger=manual_command, process_user=web
     */
    public function test_publishes_empty_tree_when_no_active_templates(): void
    {
        $service = $this->service();

        $this->assertTrue($service->publishCurrent(), $this->publishDiagnostics($service));
        $this->assertTrue($service->isPublished(self::VERSION));
        $this->assertFileExists($service->versionDir(self::VERSION).'/manifest.json');
        $this->assertFileExists($service->versionDir(self::VERSION).'/.htaccess');
    }

    /**
     * lang 게시물은 폴백 API(serveLanguage)와 동일 페이로드다 (canonical 비교).
     *
     * @effects published_lang_matches_api_payload
     */
    public function test_published_lang_matches_api_payload(): void
    {
        $this->createActiveTemplate();

        $service = $this->service();
        $this->assertTrue($service->publishCurrent(), $this->publishDiagnostics($service));

        $publishedPath = $service->versionDir(self::VERSION).'/templates/sirsoft-admin_basic/lang/ko.json';
        $this->assertFileExists($publishedPath);

        $apiResponse = $this->getJson('/api/templates/sirsoft-admin_basic/lang/ko.json');
        $apiResponse->assertStatus(200);

        $this->assertSame(
            json_decode((string) $apiResponse->getContent(), true),
            json_decode((string) file_get_contents($publishedPath), true),
            '정적 게시본과 폴백 API 의 lang 페이로드가 canonical 동일해야 한다'
        );
    }

    /**
     * routes 게시물은 성공 봉투({"success":true,...,"data":...})를 포함한다 —
     * 프론트 소비 코드(result.success / result.data) 무변경 보장.
     *
     * @effects published_routes_has_success_envelope
     */
    public function test_published_routes_has_success_envelope(): void
    {
        $this->createActiveTemplate();

        $service = $this->service();
        $this->assertTrue($service->publishCurrent(), $this->publishDiagnostics($service));

        $publishedPath = $service->versionDir(self::VERSION).'/templates/sirsoft-admin_basic/routes.json';
        $this->assertFileExists($publishedPath);

        $published = json_decode((string) file_get_contents($publishedPath), true);
        $this->assertTrue($published['success']);
        $this->assertArrayHasKey('data', $published);

        // 폴백 API 의 data 와 동일해야 한다
        $apiResponse = $this->getJson('/api/templates/sirsoft-admin_basic/routes.json');
        $apiResponse->assertStatus(200);
        $this->assertSame($apiResponse->json('data'), $published['data']);
    }

    /**
     * 활성 모듈·플러그인의 운영자 소유 디렉토리(`custom/`)도 게시된다.
     *
     * 모듈·플러그인 빌드 산출물은 **병합 번들**로만 게시되는데 `custom/` 은 그 번들에
     * 들어가지 않는다. 게시하지 않으면 확장 자산 중 이것만 요청마다 PHP 를 거치고,
     * CSS 내부 상대 `url()` 이 해석되지 않는다 — 이 축이 빠지면 같은 기능이 확장 타입에
     * 따라 다르게 동작하고 그 이유가 화면에 드러나지 않는다.
     *
     * @effects custom_asset_published_for_all_extension_types
     */
    public function test_publishes_active_module_and_plugin_custom_assets(): void
    {
        $this->createActiveTemplate();

        $module = 'g7test-publish_module';
        $plugin = 'g7test-publish_plugin';

        $fixtures = [
            base_path("modules/{$module}/custom") => 'modules/'.$module,
            base_path("plugins/{$plugin}/custom") => 'plugins/'.$plugin,
        ];

        foreach (array_keys($fixtures) as $dir) {
            File::ensureDirectoryExists($dir.'/fonts');
            File::put($dir.'/custom.css', "body { background-image: url('./fonts/x.woff2'); }");
            File::put($dir.'/fonts/x.woff2', 'font');
            File::put($dir.'/notes.txt', 'not an asset');
        }

        // 활성 목록은 레포지토리가 판정한다 — 실제 설치본에 의존하면 이 저장소 상태에 따라
        // 단언이 공허하게 통과한다.
        $this->createActiveExtensions([$module], [$plugin]);

        try {
            $service = $this->service();
            $this->assertTrue($service->publishCurrent(), $this->publishDiagnostics($service));

            $versionDir = $service->versionDir(self::VERSION);

            foreach ($fixtures as $root) {
                $this->assertFileExists($versionDir."/{$root}/assets/custom/custom.css");
                $this->assertFileExists($versionDir."/{$root}/assets/custom/fonts/x.woff2");

                // 허용 확장자 화이트리스트는 타입과 무관하게 같아야 한다
                $this->assertFileDoesNotExist($versionDir."/{$root}/assets/custom/notes.txt");
            }
        } finally {
            File::deleteDirectory(base_path("modules/{$module}"));
            File::deleteDirectory(base_path("plugins/{$plugin}"));
        }
    }

    /**
     * 비활성 확장의 custom 은 게시하지 않는다.
     *
     * 자산 서빙이 활성 확장에만 응답하므로, 게시해 봐야 아무도 참조하지 않는 사본이
     * 버전 디렉토리마다 쌓인다.
     *
     * @effects custom_asset_published_for_all_extension_types
     */
    public function test_inactive_module_custom_is_absent_from_published_tree(): void
    {
        $this->createActiveTemplate();

        $module = 'g7test-inactive_module';
        $dir = base_path("modules/{$module}/custom");
        File::ensureDirectoryExists($dir);
        File::put($dir.'/custom.css', '/* operator */');

        // 활성 행을 만들지 않는다 — 비활성(미설치) 상태 그대로.

        try {
            $service = $this->service();
            $this->assertTrue($service->publishCurrent(), $this->publishDiagnostics($service));

            $this->assertFileDoesNotExist(
                $service->versionDir(self::VERSION)."/modules/{$module}/assets/custom/custom.css"
            );
        } finally {
            File::deleteDirectory(base_path("modules/{$module}"));
        }
    }

    /**
     * components.json 사본과 dist 에셋이 게시되고 `*.map` 은 제외된다.
     *
     * @effects published_tree_excludes_sourcemaps
     */
    public function test_publishes_components_and_dist_assets_excluding_sourcemaps(): void
    {
        $this->createActiveTemplate();

        $service = $this->service();
        $this->assertTrue($service->publishCurrent(), $this->publishDiagnostics($service));

        $templateDir = $service->versionDir(self::VERSION).'/templates/sirsoft-admin_basic';

        // components.json 사본 (실번들 파일이 존재)
        $this->assertFileExists($templateDir.'/components.json');
        $this->assertSame(
            json_decode((string) file_get_contents(base_path('templates/sirsoft-admin_basic/components.json')), true),
            json_decode((string) file_get_contents($templateDir.'/components.json'), true)
        );

        // dist 에셋 사본 존재 + 소스맵 부재
        $this->assertDirectoryExists($templateDir.'/assets');
        $maps = glob($templateDir.'/assets/*/*.map') ?: [];
        $this->assertSame([], $maps, '소스맵은 게시 대상이 아니다');
    }

    /**
     * 비활성 템플릿 산출물은 게시 트리에 부재한다 (활성 게이트의 정적 등가물).
     *
     * @effects published_tree_excludes_inactive_extensions
     */
    public function test_inactive_template_is_absent_from_published_tree(): void
    {
        $this->createActiveTemplate();
        Template::create([
            'identifier' => 'sirsoft-basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '비활성', 'en' => 'Inactive'],
            'version' => '1.0.0',
            'type' => 'user',
            'status' => ExtensionStatus::Inactive->value,
            'description' => ['ko' => '비활성', 'en' => 'Inactive'],
        ]);

        $service = $this->service();
        $this->assertTrue($service->publishCurrent(), $this->publishDiagnostics($service));

        $this->assertDirectoryExists($service->versionDir(self::VERSION).'/templates/sirsoft-admin_basic');
        $this->assertDirectoryDoesNotExist($service->versionDir(self::VERSION).'/templates/sirsoft-basic');
    }

    /**
     * 식별자 패턴 불일치(경로 세그먼트 위험) 템플릿은 게시에서 거부된다.
     *
     * @effects identifier_outside_pattern_rejected
     */
    public function test_rejects_identifier_outside_whitelist_pattern(): void
    {
        $this->createActiveTemplate();

        // vendor-name 패턴을 벗어나는 식별자 (DB 직접 생성으로 우회 가정)
        Template::create([
            'identifier' => 'UPPER..traversal',
            'vendor' => 'upper',
            'name' => ['ko' => '위험', 'en' => 'Danger'],
            'version' => '1.0.0',
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '위험', 'en' => 'Danger'],
        ]);

        $service = $this->service();
        $this->assertTrue($service->publishCurrent(), $this->publishDiagnostics($service));

        $templatesDir = $service->versionDir(self::VERSION).'/templates';
        $this->assertDirectoryExists($templatesDir.'/sirsoft-admin_basic');
        $entries = array_map('basename', File::directories($templatesDir));
        $this->assertSame(['sirsoft-admin_basic'], $entries, '패턴 불일치 식별자는 트리에 없어야 한다');
    }

    /**
     * 멱등 — 게시 완료 상태에서 재호출은 skip (기존 게시물 유지), force 는 재게시.
     *
     * @scenario publish_state=published, artifact_integrity=intact, filesystem_writable=writable, environment=production, trigger=manual_command, process_user=web
     *
     * @effects publish_is_idempotent_until_forced
     */
    public function test_publish_is_idempotent_and_force_republishes(): void
    {
        $service = $this->service();
        $this->assertTrue($service->publishCurrent(), $this->publishDiagnostics($service));

        // 게시 후 심은 마커가 skip 재호출에서 살아남으면 재게시가 없었다는 증거
        $marker = $service->versionDir(self::VERSION).'/idempotency-marker';
        File::put($marker, 'x');

        $this->assertTrue($service->publishCurrent(), $this->publishDiagnostics($service));
        $this->assertFileExists($marker);

        // force 재게시는 디렉토리를 새로 만든다 → 마커 소실
        $this->assertTrue($service->publishCurrent(force: true));
        $this->assertFileDoesNotExist($marker);
        $this->assertTrue($service->isPublished(self::VERSION));
    }

    /**
     * 쓰기 단계 실패 시 예외를 삼키고 false + tmp 잔존물 정리 + manifest 부재.
     *
     * @scenario publish_state=partial, artifact_integrity=intact, filesystem_writable=writable, environment=production, trigger=lifecycle, process_user=web
     *
     * @effects write_failure_cleans_tmp_and_falls_back
     */
    public function test_write_failure_cleans_tmp_and_returns_false(): void
    {
        $this->createActiveTemplate();

        // 병합 SSoT 가 예외를 던지는 상황 시뮬레이션
        $mock = $this->partialMock(TemplateService::class, function ($mock) {
            $mock->shouldReceive('getLanguageDataWithModules')
                ->andThrow(new \RuntimeException('disk full'));
        });

        $service = new ExtensionStaticCacheService(
            $mock,
            app(TemplateRepositoryInterface::class),
            app(ModuleRepositoryInterface::class),
            app(PluginRepositoryInterface::class),
            app(ExtensionBundleService::class),
            app(LanguagePackService::class),
        );

        $this->assertFalse($service->publishCurrent());
        $this->assertFalse($service->isPublished(self::VERSION));

        $base = $service->baseDir();
        $tmpDirs = File::isDirectory($base)
            ? array_filter(File::directories($base), fn ($d) => str_ends_with($d, '.tmp'))
            : [];
        $this->assertSame([], array_values($tmpDirs), 'tmp 잔존물은 정리되어야 한다');
    }

    /**
     * 게시 루트를 만들 수 없으면 **병합을 시작하기 전에** 끊는다 (P2 프리플라이트).
     *
     * `public/build` 가 다른 계정 소유 + `g-w` 면 웹 프로세스는 `ext` 를 mkdir 조차 하지
     * 못한다. 그 사실을 전 로케일 lang 병합 + 전 템플릿 dist 복사를 다 헛돈 뒤에 알게 되면
     * 그 비용이 **모든 프로덕션 요청**에서 반복된다.
     *
     * @scenario publish_state=unpublished, artifact_integrity=intact, filesystem_writable=parent_denied, environment=production, trigger=lifecycle, process_user=web
     *
     * @effects parent_denied_short_circuits_before_merge
     */
    public function test_unwritable_parent_short_circuits_before_merge(): void
    {
        $this->createActiveTemplate();

        // 병합 SSoT 가 호출되면 실패 — 프리플라이트가 그 앞에서 끊어야 한다.
        $mock = $this->partialMock(TemplateService::class, function ($mock) {
            $mock->shouldReceive('getLanguageDataWithModules')
                ->never();
        });

        $service = new ExtensionStaticCacheService(
            $mock,
            app(TemplateRepositoryInterface::class),
            app(ModuleRepositoryInterface::class),
            app(PluginRepositoryInterface::class),
            app(ExtensionBundleService::class),
            app(LanguagePackService::class),
        );

        // 게시 루트도, 그 부모도 만들 수 없는 상태를 만든다 — public 루트를 파일로 막아
        // 어떤 하위 디렉토리도 생성될 수 없게 한다 (Windows/Unix 공통으로 성립).
        $blocked = $this->isolatedPublicPath.'/blocked-public';
        File::put($blocked, 'not a directory');
        $this->app->usePublicPath($blocked);

        $this->assertFalse($service->publishCurrent());
        $this->assertFalse($service->isPublished(self::VERSION));

        $marker = ExtensionStaticCacheService::failureMarker();

        $this->assertIsArray($marker, '프리플라이트 실패가 마커에 남지 않았다');
        $this->assertSame('parent_not_writable', $marker['reason']);
    }

    /**
     * 게시 루트 자체가 쓰기 불가면 같은 지점에서 끊긴다 (P1).
     *
     * @scenario publish_state=unpublished, artifact_integrity=intact, filesystem_writable=tree_denied, environment=production, trigger=lifecycle, process_user=web
     *
     * @effects parent_denied_short_circuits_before_merge
     */
    public function test_unwritable_publish_tree_short_circuits(): void
    {
        $this->createActiveTemplate();

        $service = $this->service();

        // 게시 루트 자리에 파일을 놓아 디렉토리로 쓸 수 없게 만든다.
        $base = $service->baseDir();
        File::ensureDirectoryExists(dirname($base));
        File::deleteDirectory($base);
        File::put($base, 'not a directory');

        $this->assertFalse($service->publishCurrent());
        $this->assertFalse($service->isPublished(self::VERSION));

        $marker = ExtensionStaticCacheService::failureMarker();
        $this->assertIsArray($marker);
        $this->assertSame('parent_not_writable', $marker['reason']);

        @unlink($base);
    }

    /**
     * 쓰기 실패는 실패 마커에 사유와 함께 기록된다 (O1 백오프의 근거).
     *
     * @scenario publish_state=partial, artifact_integrity=intact, filesystem_writable=writable, environment=production, trigger=lifecycle, process_user=web
     *
     * @effects publish_failure_recorded_in_marker
     */
    public function test_write_failure_records_failure_marker(): void
    {
        $this->createActiveTemplate();
        Cache::forget('g7:core:ext.static.publish_failure');

        $mock = $this->partialMock(TemplateService::class, function ($mock) {
            $mock->shouldReceive('getLanguageDataWithModules')
                ->andThrow(new \RuntimeException('disk full'));
        });

        $service = new ExtensionStaticCacheService(
            $mock,
            app(TemplateRepositoryInterface::class),
            app(ModuleRepositoryInterface::class),
            app(PluginRepositoryInterface::class),
            app(ExtensionBundleService::class),
            app(LanguagePackService::class),
        );

        $this->assertFalse($service->publishCurrent());

        $marker = ExtensionStaticCacheService::failureMarker();

        $this->assertIsArray($marker, '실패 마커가 기록되지 않았다 — 진단 표면이 실패를 볼 수 없다');
        $this->assertSame('write_failed', $marker['reason']);
        $this->assertSame(self::VERSION, $marker['version']);
        $this->assertSame(1, $marker['count']);
        $this->assertStringContainsString('disk full', $marker['message']);
    }

    /**
     * 게시 성공은 실패 마커를 제거한다 — 백오프가 영구 고착되지 않는다.
     *
     * @effects publish_success_clears_failure_marker
     */
    public function test_successful_publish_clears_failure_marker(): void
    {
        Cache::put('g7:core:ext.static.publish_failure', [
            'version' => self::VERSION,
            'at' => now()->toIso8601String(),
            'reason' => 'write_failed',
            'count' => 3,
            'message' => 'stale',
        ], 300);

        $service = $this->service();
        $this->assertTrue($service->publishCurrent(), $this->publishDiagnostics($service));

        $this->assertNull(
            ExtensionStaticCacheService::failureMarker(),
            '성공했는데 실패 마커가 남아 있다 — 이후 예약이 영구 억제된다'
        );
    }

    /**
     * 신선한 실패 마커가 있으면 terminating 게시를 재예약하지 않는다 (O1 백오프).
     *
     * 쓰기 불가 환경에서 모든 프로덕션 요청이 전 로케일 병합 + 전 템플릿 dist 복사를
     * 헛도는 것을 막는다.
     *
     * @effects publish_failure_backoff_suppresses_reschedule
     */
    public function test_fresh_failure_marker_suppresses_publish_scheduling(): void
    {
        $this->app['env'] = 'production';
        ExtensionStaticCacheService::resetPublishScheduleForTesting();
        ExtensionStaticCacheService::fakeRootProcessForTesting(false);

        Cache::put('g7:core:ext.static.publish_failure', [
            'version' => self::VERSION,
            'at' => now()->toIso8601String(),
            'reason' => 'parent_not_writable',
            'count' => 2,
            'message' => 'denied',
        ], 300);

        ExtensionStaticCacheService::schedulePublishOnTerminate();
        $this->app->terminate();

        $this->assertFalse(
            $this->service()->isPublished(self::VERSION),
            '실패 마커가 신선한데 게시가 재시도됐다 — 백오프가 걸리지 않는다'
        );
    }

    /**
     * 절단 쓰기는 예외로 잡히고 manifest 가 기록되지 않는다 (A1).
     *
     * `File::put()` 은 디스크 풀/quota 에서 **짧은 int** 를 돌려주며 성공한 것처럼 보인다.
     * `=== false` 만 보면 절단 JSON 이 200 으로 서빙되고, 프론트는 `response.ok` 만 보므로
     * 폴백하지 않은 채 `response.json()` 이 던져 부팅 전체가 실패한다.
     *
     * @scenario publish_state=partial, artifact_integrity=truncated, filesystem_writable=writable, environment=production, trigger=lifecycle, process_user=web
     *
     * @effects truncated_artifact_rejected_before_manifest
     */
    public function test_truncated_write_is_rejected_before_manifest(): void
    {
        $this->createActiveTemplate();

        // File::put 이 실제보다 짧은 바이트 수를 반환하는 상황 (디스크 풀/quota).
        // partialMock 이라 ensureDirectoryExists 등 나머지 파일 연산은 그대로 동작한다.
        File::partialMock()->shouldReceive('put')->andReturn(1);

        $service = $this->service();

        $this->assertFalse($service->publishCurrent());
        $this->assertFalse(
            $service->isPublished(self::VERSION),
            '절단 산출물이 게시 완료로 표시됐다 — 손상 JSON 이 200 으로 서빙된다'
        );
    }

    /**
     * GC — 현재 버전 + 직전 1개 보존, 그 외 삭제.
     *
     * 갓 만들어진 `.tmp` 는 살아남는다 — 락은 버전별이라 다른 버전의 게시가 동시에
     * 진행 중일 수 있고, 나이 무관 삭제는 그 순간 살아 있는 남의 tmp 를 파괴한다.
     *
     * @effects cleanup_keeps_current_and_previous_versions
     */
    public function test_cleanup_keeps_current_and_previous_only(): void
    {
        $service = $this->service();
        $this->assertTrue($service->publishCurrent(), $this->publishDiagnostics($service));

        // 과거 버전 3개 + 진행 중으로 보이는 신규 tmp 시뮬레이션
        foreach ([100, 200, 300] as $old) {
            File::ensureDirectoryExists($service->versionDir($old));
            File::put($service->versionDir($old).'/manifest.json', '{}');
        }
        File::ensureDirectoryExists($service->baseDir().'/400.tmp');

        $deleted = $service->cleanup();

        // 보존: 현재(VERSION) + 직전(300) + 신규 400.tmp. 삭제: 100, 200
        $this->assertSame(2, $deleted);
        $this->assertDirectoryExists($service->versionDir(self::VERSION));
        $this->assertDirectoryExists($service->versionDir(300));
        $this->assertDirectoryDoesNotExist($service->versionDir(200));
        $this->assertDirectoryDoesNotExist($service->versionDir(100));
        $this->assertDirectoryExists(
            $service->baseDir().'/400.tmp',
            '진행 중일 수 있는 신규 tmp 는 보존되어야 한다'
        );
    }

    /**
     * GC — 나이 가드: 10분을 넘긴 `.tmp` / `.old` 만 삭제된다 (A4).
     *
     * @effects cleanup_removes_only_stale_work_directories
     */
    public function test_cleanup_removes_only_stale_work_directories(): void
    {
        $service = $this->service();
        $base = $service->baseDir();
        File::ensureDirectoryExists($base);

        $fresh = $base.'/500.tmp';
        $staleTmp = $base.'/600.tmp';
        $staleOld = $base.'/700.old';

        foreach ([$fresh, $staleTmp, $staleOld] as $dir) {
            File::ensureDirectoryExists($dir);
        }

        // 락 TTL(300초)의 2배가 기준 — 그보다 확실히 오래된 시각으로 되돌린다.
        touch($staleTmp, time() - 3600);
        touch($staleOld, time() - 3600);
        clearstatcache();

        $deleted = $service->cleanup();

        $this->assertDirectoryExists($fresh, '신규 tmp 가 삭제되었다 — 진행 중 게시가 파괴된다');
        $this->assertDirectoryDoesNotExist($staleTmp, '늙은 tmp 가 정리되지 않았다');
        $this->assertDirectoryDoesNotExist($staleOld, '늙은 .old 가 정리되지 않았다');
        $this->assertSame(2, $deleted);
    }

    /**
     * GC — 현재 버전 디렉토리가 실존하지 않으면 실존 최신 2개를 보존한다 (D2).
     *
     * `cache:clear` 후 첫 호출자가 CLI 면 포인터만 새 버전으로 점프하고 산출물은 아직
     * 없다. 그 상태에서 없는 버전을 보존 슬롯으로 쓰면 진짜 직전 버전이 삭제된다.
     *
     * @effects gc_preserves_previous_when_current_absent
     */
    public function test_cleanup_preserves_two_newest_when_current_version_absent(): void
    {
        $service = $this->service();
        $base = $service->baseDir();
        File::ensureDirectoryExists($base);

        // 현재 버전(VERSION) 디렉토리는 만들지 않는다 — 포인터만 앞선 상태.
        foreach ([100, 200, 300] as $old) {
            File::ensureDirectoryExists($service->versionDir($old));
            File::put($service->versionDir($old).'/manifest.json', '{}');
        }

        $deleted = $service->cleanup();

        $this->assertDirectoryExists($service->versionDir(300), '실존 최신이 보존되지 않았다');
        $this->assertDirectoryExists(
            $service->versionDir(200),
            '진짜 직전 버전이 삭제됐다 — 없는 현재 버전이 보존 슬롯을 잡고 있다'
        );
        $this->assertDirectoryDoesNotExist($service->versionDir(100));
        $this->assertSame(1, $deleted);
    }

    /**
     * kill-switch(core.static_cache.enabled=false) — 게시 자체가 중단된다.
     *
     * @scenario publish_state=unpublished, artifact_integrity=intact, filesystem_writable=writable, environment=production, trigger=kill_switch, process_user=web
     *
     * @effects kill_switch_disables_publish_and_gate
     */
    public function test_kill_switch_disables_publishing(): void
    {
        config(['core.static_cache.enabled' => false]);

        $service = $this->service();

        $this->assertFalse($service->publishCurrent());
        $this->assertDirectoryDoesNotExist($service->baseDir());
    }

    /**
     * terminating 트리거 — 비프로덕션(testing)에서는 예약되지 않는다.
     *
     * @scenario publish_state=unpublished, artifact_integrity=intact, filesystem_writable=writable, environment=dev, trigger=lifecycle, process_user=web
     *
     * @effects terminating_publish_gated_to_production
     */
    public function test_terminating_publish_not_scheduled_outside_production(): void
    {
        ExtensionStaticCacheService::schedulePublishOnTerminate();

        $this->app->terminate();

        $this->assertDirectoryDoesNotExist($this->service()->baseDir());
    }

    /**
     * terminating 트리거 — 프로덕션에서는 종료 시점의 현재 버전으로 게시된다.
     *
     * @scenario publish_state=unpublished, artifact_integrity=intact, filesystem_writable=writable, environment=production, trigger=lifecycle, process_user=web
     *
     * @effects terminating_publish_uses_final_version_after_burst
     */
    public function test_terminating_publish_runs_in_production(): void
    {
        app()['env'] = 'production';

        ExtensionStaticCacheService::schedulePublishOnTerminate();

        // 예약 후 버전이 한 번 더 bump 되어도 실행 시점의 최종 버전으로 게시 (자연 병합)
        Cache::put('g7:core:ext.cache_version', self::VERSION + 1);

        $this->app->terminate();

        $this->assertTrue($this->service()->isPublished(self::VERSION + 1));
        $this->assertFalse($this->service()->isPublished(self::VERSION));
    }

    /**
     * root 프로세스(sudo CLI)에서는 terminating 게시가 예약되지 않는다.
     *
     * root 로 게시하면 캐시 락 샤드 디렉토리(`storage/framework/cache/data/xx`)와
     * 병합 번들(`storage/app/ext-bundles`)이 root 소유로 남고, 이후 웹 프로세스의
     * 캐시 쓰기가 그 샤드에 해시되는 순간 Permission denied 로 죽는다
     * (실사례: sudo 코어 업데이트 직후 전면 500). 게시는 다음 웹 렌더의
     * 자가 치유(웹 계정)가 수행한다.
     *
     * @scenario publish_state=unpublished, artifact_integrity=intact, filesystem_writable=writable, environment=production, trigger=lifecycle, process_user=root_cli
     *
     * @effects root_cli_defers_publish_to_web_self_heal
     */
    public function test_terminating_publish_not_scheduled_for_root_process(): void
    {
        app()['env'] = 'production';
        ExtensionStaticCacheService::fakeRootProcessForTesting(true);

        try {
            ExtensionStaticCacheService::schedulePublishOnTerminate();
            $this->app->terminate();

            $this->assertDirectoryDoesNotExist($this->service()->baseDir());
        } finally {
            ExtensionStaticCacheService::fakeRootProcessForTesting(null);
        }
    }

    /**
     * terminating 게시 예약 플래그는 콜백 실행 시점에 리셋된다.
     *
     * 요청마다 앱 인스턴스를 새로 만드는 장수 프로세스(Octane 류)에서는 static 플래그만
     * 프로세스에 살아남는다 — 리셋이 없으면 2번째 이후 bump 는 새 앱에 콜백이 등록되지
     * 않은 채 플래그만 true 라 그 워커에서 영구 미게시가 되고, `AssetUrl` 자가 치유도
     * 같은 플래그를 쓰므로 복구 경로가 없다. FPM(요청=프로세스)에서는 무영향.
     *
     * @scenario publish_state=published, artifact_integrity=intact, filesystem_writable=writable, environment=production, trigger=lifecycle, process_user=web
     *
     * @effects terminating_schedule_rearms_after_execution
     */
    public function test_terminating_publish_rearms_after_callback_execution(): void
    {
        app()['env'] = 'production';

        ExtensionStaticCacheService::schedulePublishOnTerminate();
        $this->app->terminate();

        $property = new \ReflectionProperty(ExtensionStaticCacheService::class, 'publishScheduled');

        $this->assertFalse($property->getValue(), '콜백 실행 후 플래그가 리셋되어 재예약 가능해야 한다');
    }

    /**
     * 게시 `.htaccess` 는 불변 캐시와 함께 mod_deflate 압축을 선언한다.
     *
     * 정적 서빙은 Laravel 압축 미들웨어(GzipEncodeResponse)를 우회하므로, Apache 에서는
     * 게시 디렉토리가 스스로 압축을 선언해야 종전 API 대비 전송량 회귀가 없다
     * (실측: lang/ko.json 524,915B 비압축 전송). nginx 는 규정 문서의 gzip 스니펫이 담당한다.
     *
     * @scenario publish_state=published, artifact_integrity=intact, filesystem_writable=writable, environment=production, trigger=manual_command, process_user=web
     *
     * @effects published_htaccess_declares_compression
     */
    public function test_published_htaccess_declares_compression(): void
    {
        $service = $this->service();

        $this->assertTrue($service->publishCurrent(), $this->publishDiagnostics($service));

        $htaccess = File::get($service->versionDir(self::VERSION).'/.htaccess');

        $this->assertStringContainsString('mod_deflate.c', $htaccess);
        $this->assertStringContainsString('application/json', $htaccess);
    }

    /**
     * manifest 기록이 실패하면 **이미 앉은 최종 디렉토리**를 정리한다 (A2).
     *
     * rename 이 이미 성공한 뒤라 `$tmp` 는 존재하지 않는다 — tmp 만 지우는 정리는 no-op 이고,
     * manifest 없는 완성 디렉토리가 영구 잔존한다. 그 상태에서 `isPublished()` 는 영원히
     * false 라 요청마다 트리를 지웠다 만들기를 반복하며, 실패는 로그에만 남는다.
     *
     * @scenario publish_state=partial, artifact_integrity=truncated, filesystem_writable=writable, environment=production, trigger=lifecycle, process_user=web
     *
     * @effects manifest_failure_cleans_final_directory
     */
    public function test_manifest_failure_cleans_final_directory(): void
    {
        $this->createActiveTemplate();

        $service = $this->service();
        $final = $service->versionDir(self::VERSION);
        $base = $service->baseDir();

        // manifest 쓰기 **시점만** 실패시킨다 — 첫 파일 쓰기에서 던지면 rename 까지 가지
        // 못해 이 경로(A2)가 아니라 tmp 정리 경로(P1)를 검사하게 된다.
        $real = new Filesystem;
        $fs = File::partialMock();
        $fs->shouldReceive('put')
            ->withArgs(fn ($path) => str_ends_with(str_replace('\\', '/', (string) $path), '/manifest.json'))
            ->andReturn(false);
        $fs->shouldReceive('put')
            ->andReturnUsing(fn ($path, $contents, $lock = false) => $real->put($path, $contents, $lock));

        $this->assertFalse($service->publishCurrent());
        $this->assertFalse($service->isPublished(self::VERSION));

        $this->assertDirectoryDoesNotExist(
            $final,
            'manifest 없는 완성 디렉토리가 잔존했다 — isPublished 가 영원히 false 라 매 요청이 트리를 다시 만든다'
        );

        $leftovers = File::isDirectory($base) ? array_map('basename', File::directories($base)) : [];
        $this->assertSame([], $leftovers, '게시 작업 디렉토리가 정리되지 않았다');

        // 실패 지점이 manifest 였음을 고정한다 — 그보다 앞에서 끊겼다면 이 테스트는
        // rename 이후 경로(A2)가 아니라 tmp 정리 경로를 검사한 셈이라 공허하다.
        $marker = ExtensionStaticCacheService::failureMarker();
        $this->assertIsArray($marker);
        $this->assertSame('write_failed', $marker['reason']);
        $this->assertStringContainsString('manifest.json', $marker['message']);
    }

    /**
     * 재게시 중 `rename($tmp, $final)` 이 실패하면 비켜 둔 기존 버전이 복원된다 (A3).
     *
     * 실측 근거: Windows 재게시에서 이 경로가 실제로 발동했고(마커 상세
     * `Failed to rename publish directory: …{v}.tmp -> …{v}`), 기존 게시본은 온전히
     * 보존됐다. 복원이 없으면 이미 배달된 HTML 이 참조하는 CSS/JS/폰트가 전부 404 가
     * 되고 폰트에는 복구기가 없다.
     *
     * @scenario publish_state=published, artifact_integrity=intact, filesystem_writable=writable, environment=production, trigger=manual_command, process_user=web
     *
     * @effects rename_failure_restores_previous_published_version
     */
    public function test_rename_failure_restores_previous_published_version(): void
    {
        $service = $this->service();
        $this->assertTrue($service->publishCurrent(), $this->publishDiagnostics($service));

        $final = $service->versionDir(self::VERSION);
        $old = $final.'.old';
        $base = $service->baseDir();

        // 기존 게시본의 증거 — 복원되면 이 파일이 그대로 살아 있다.
        File::put($final.'/previous-version-marker', 'v1');

        // 새 트리를 앉힐 자리를 **비어 있지 않은 디렉토리**로 점유해 rename 을 실패시킨다.
        // 점유 시점은 기존 버전을 `.old` 로 비켜낸 직후여야 하므로(그 전이면 기존 버전에
        // 파일 하나가 늘 뿐이다), 스왑 판정 호출(`File::isDirectory($old)`)에 훅을 건다.
        $fired = false;
        $fs = File::partialMock();
        $fs->shouldReceive('isDirectory')
            ->withArgs(fn ($path) => (string) $path === $old)
            ->andReturnUsing(function ($path) use (&$fired, $final) {
                if (! $fired && ! is_dir($final)) {
                    $fired = true;
                    mkdir($final, 0775, true);
                    file_put_contents($final.DIRECTORY_SEPARATOR.'blocker', 'occupied');
                }

                return is_dir($path);
            });
        $fs->shouldReceive('isDirectory')->andReturnUsing(fn ($path) => is_dir($path));

        $this->assertFalse($service->publishCurrent(force: true), '스왑 실패가 성공으로 보고됐다');
        $this->assertTrue($fired, 'rename 실패를 유도하지 못했다 — 이 테스트는 공허 통과다');

        $this->assertFileExists(
            $final.'/previous-version-marker',
            '스왑 실패 후 기존 게시본이 복원되지 않았다 — 배달된 HTML 의 자산이 전부 404 가 된다'
        );
        $this->assertFileExists($final.'/manifest.json');
        $this->assertTrue($service->isPublished(self::VERSION));
        $this->assertDirectoryDoesNotExist($old, '비켜 둔 디렉토리가 제자리로 돌아가지 않았다');

        $leftovers = array_filter(
            array_map('basename', File::directories($base)),
            fn ($name) => str_ends_with($name, '.tmp')
        );
        $this->assertSame([], array_values($leftovers), 'tmp 잔존물이 정리되지 않았다');

        $marker = ExtensionStaticCacheService::failureMarker();
        $this->assertIsArray($marker);
        $this->assertSame('write_failed', $marker['reason']);
        $this->assertStringContainsString('Failed to rename publish directory', $marker['message']);
    }

    /**
     * 디렉토리 rename 은 포기 전에 재시도한다 — 일시 거부를 게시 실패로 만들지 않는다.
     *
     * 실측 근거: 실패 순간 목적지는 **부재**였고 `.old` 스왑도 관여하지 않았는데
     * `rename` 이 false 를 돌려줬으며, 상태를 바꾸지 않고 다시 호출하면 전부 성공했다
     * (즉시 1건 / 200ms 후 3건). 재시도가 없으면 그 한 번이 그대로 게시 실패가 되어
     * 실패 마커와 대시보드 알림까지 올라간다.
     *
     * 재시도 자체는 native `rename` 이라 주입할 수 없으므로, **끝내 실패하는** 경우의
     * 소요 시간으로 재시도 발생을 관측한다 — 단발 호출이면 대기 없이 즉시 끝난다.
     * 이 단언이 없으면 상수를 1 로 되돌려도 다른 케이스는 전부 통과한다.
     *
     * @scenario publish_state=published, artifact_integrity=intact, filesystem_writable=writable, environment=production, trigger=manual_command, process_user=web
     *
     * @effects rename_failure_restores_previous_published_version
     */
    public function test_directory_rename_is_retried_before_giving_up(): void
    {
        $service = $this->service();
        $this->assertTrue($service->publishCurrent(), $this->publishDiagnostics($service));

        $final = $service->versionDir(self::VERSION);
        $old = $final.'.old';

        // 위 A3 케이스와 같은 방식으로 자리를 **지속적으로** 점유해 rename 을 끝까지
        // 실패시킨다 — 그래야 재시도가 전부 소진되고 그 대기가 소요 시간에 드러난다.
        $fired = false;
        $fs = File::partialMock();
        $fs->shouldReceive('isDirectory')
            ->withArgs(fn ($path) => (string) $path === $old)
            ->andReturnUsing(function ($path) use (&$fired, $final) {
                if (! $fired && ! is_dir($final)) {
                    $fired = true;
                    mkdir($final, 0775, true);
                    file_put_contents($final.DIRECTORY_SEPARATOR.'blocker', 'occupied');
                }

                return is_dir($path);
            });
        $fs->shouldReceive('isDirectory')->andReturnUsing(fn ($path) => is_dir($path));

        $startedAt = microtime(true);
        $this->assertFalse($service->publishCurrent(force: true));
        $elapsed = microtime(true) - $startedAt;

        $this->assertTrue($fired, 'rename 실패를 유도하지 못했다 — 이 테스트는 공허 통과다');

        // 시도 3회 = 재시도 대기 2회. 롤백 rename 도 같은 헬퍼를 타므로 실제 대기는
        // 이보다 길지만, 하한만 단언해 머신 속도에 좌우되지 않게 한다.
        $this->assertGreaterThanOrEqual(
            0.4,
            $elapsed,
            'rename 이 재시도 없이 즉시 포기했다 — 일시 거부가 그대로 게시 실패가 된다'
        );
    }

    /**
     * GC — 삭제에 실패한 디렉토리는 경고를 남기고 **삭제 카운트에서 제외**된다 (A5).
     *
     * 반환값을 검사하지 않으면 소유권 불일치로 삭제가 실패해도 "N개 삭제" 로 보고되어,
     * 구버전·고아 tmp 가 무한 누적되는 동안 운영자에게는 정상으로 보인다.
     *
     * @effects cleanup_excludes_failed_deletions_from_count
     */
    public function test_cleanup_excludes_failed_deletion_from_count(): void
    {
        $service = $this->service();
        $this->assertTrue($service->publishCurrent(), $this->publishDiagnostics($service));

        foreach ([100, 200, 300] as $old) {
            File::ensureDirectoryExists($service->versionDir($old));
            File::put($service->versionDir($old).'/manifest.json', '{}');
        }

        // 삭제 대상은 100·200 두 개. 그중 100 의 삭제만 실패시킨다.
        $undeletable = $service->versionDir(100);
        $real = new Filesystem;

        Log::spy();

        $fs = File::partialMock();
        $fs->shouldReceive('deleteDirectory')
            ->withArgs(fn ($dir) => (string) $dir === $undeletable)
            ->andReturn(false);
        $fs->shouldReceive('deleteDirectory')
            ->andReturnUsing(fn ($dir, $preserve = false) => $real->deleteDirectory($dir, $preserve));

        $deleted = $service->cleanup();

        $this->assertSame(1, $deleted, '삭제에 실패한 디렉토리가 삭제 건수에 집계됐다 — 잔존물 누적이 정상으로 보인다');
        $this->assertDirectoryDoesNotExist($service->versionDir(200));
        $this->assertDirectoryExists($service->versionDir(self::VERSION));
        $this->assertDirectoryExists($service->versionDir(300));

        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $context = []) => str_contains((string) $message, '정적 게시 디렉토리 삭제 실패')
        );
    }

    /**
     * 락 미획득(경합) — `Log::debug` 만 남고 실패 마커는 기록하지 않는다 (O2 a).
     *
     * 다른 프로세스가 게시 중인 것은 정상 상황이다. 마커를 남기면 정상적인 동시 요청
     * 경합이 실패로 집계돼 진단 표면에 거짓 장애가 뜬다.
     *
     * @scenario publish_state=unpublished, artifact_integrity=intact, filesystem_writable=writable, environment=production, trigger=lifecycle, process_user=web
     *
     * @effects lock_contention_skips_without_failure_marker
     */
    public function test_lock_contention_skips_without_failure_marker(): void
    {
        // 다른 프로세스가 이미 게시 중인 상태 — 같은 버전의 락을 선점한다.
        $holder = Cache::lock('ext-static.publish.'.self::VERSION, 300);
        $this->assertTrue($holder->get(), '테스트가 락을 선점하지 못했다 — 경합 상황을 만들 수 없다');

        Log::spy();

        try {
            $service = $this->service();

            $this->assertFalse($service->publishCurrent());
            $this->assertFalse($service->isPublished(self::VERSION));
            $this->assertNull(
                ExtensionStaticCacheService::failureMarker(),
                '정상 경합이 실패 마커로 집계됐다 — 진단 표면에 거짓 장애가 뜬다'
            );

            Log::shouldHaveReceived('debug')->withArgs(
                fn ($message, $context = []) => str_contains((string) $message, '정적 게시 락 미획득')
            );
        } finally {
            $holder->release();
        }
    }

    /**
     * 락 획득이 **예외를 던지면** 경고 + `lock_unavailable` 마커를 남긴다 (O2 b).
     *
     * 락을 제공하지 못하는 캐시 저장소(락 미지원 드라이버, 파일 캐시 디렉토리 권한
     * 불일치)에서는 게시가 매 요청 조용히 스킵된다 — 사유가 마커에 남지 않으면 운영자가
     * 그 사실을 알 방법이 로그뿐이다.
     *
     * @scenario publish_state=unpublished, artifact_integrity=intact, filesystem_writable=writable, environment=production, trigger=lifecycle, process_user=web
     *
     * @effects lock_provider_failure_recorded_in_marker
     */
    public function test_lock_provider_failure_records_marker(): void
    {
        // 마커 스토어를 먼저 확정시킨다(프로세스 1회 메모이즈) — 아래에서 기본 스토어를
        // 갈아끼워도 마커 기록/조회는 같은 스토어를 계속 쓴다.
        $this->assertSame(self::VERSION, ExtensionStaticCacheService::getExtensionCacheVersion());

        Cache::extend('g7-lockless', fn () => new class(new ArrayStore) extends Repository
        {
            public function lock($name, $seconds = 0, $owner = null)
            {
                throw new \BadMethodCallException('This cache store does not support locking.');
            }
        });
        config([
            'cache.stores.g7-lockless' => ['driver' => 'g7-lockless'],
            'cache.default' => 'g7-lockless',
        ]);

        Log::spy();

        $service = $this->service();

        $this->assertFalse($service->publishCurrent());
        $this->assertFalse($service->isPublished(self::VERSION));

        $marker = ExtensionStaticCacheService::failureMarker();

        $this->assertIsArray($marker, '락 저장소 장애가 마커에 남지 않았다 — 진단 표면이 영구 침묵한다');
        $this->assertSame('lock_unavailable', $marker['reason']);
        $this->assertSame(self::VERSION, $marker['version']);
        $this->assertStringContainsString('locking', $marker['message']);

        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $context = []) => str_contains((string) $message, '정적 게시 락 획득 불가')
        );
    }

    /**
     * 게시 트리는 그룹 쓰기(`g+w`)로 정합화된다 (P2 후반).
     *
     * 제보 본건은 **비-root CLI 계정 ≠ 웹 계정** 이었다. CLI 최초 게시가 트리를
     * `0755 deploy:deploy` 로 굳히면 이후 웹(php-fpm) 재게시가 영구 실패한다.
     *
     * POSIX 미지원 환경(Windows)에서는 권한 비트를 관측할 수 없다 — 그 경우에도 검사를
     * 건너뛰지 않고 **계약 자체**(디렉토리 생성이 명시 chmod 를 거치는가, 정합화가 root
     * 갈래와 항상-실행 갈래를 모두 갖는가)를 소스 수준에서 고정한다. 항상-실행 갈래가
     * root 조건 안으로 들어가면 종전(비-root no-op) 동작으로 조용히 되돌아간다.
     *
     * @scenario publish_state=published, artifact_integrity=intact, filesystem_writable=writable, environment=production, trigger=manual_command, process_user=web
     *
     * @effects published_tree_is_group_writable
     */
    public function test_published_tree_is_group_writable(): void
    {
        $this->createActiveTemplate();

        $service = $this->service();
        $this->assertTrue($service->publishCurrent(), $this->publishDiagnostics($service));

        $source = (string) file_get_contents(
            (new \ReflectionClass(ExtensionStaticCacheService::class))->getFileName()
        );

        // ① 디렉토리 생성은 umask 를 무력화하는 명시 chmod 를 거친다.
        $makeDirectory = $this->methodBody($source, 'makeDirectory');
        $this->assertNotSame('', $makeDirectory, 'makeDirectory 를 소스에서 찾지 못했다 — 검사가 공허하다');
        $this->assertStringContainsString(
            '@chmod($dir, self::PUBLISH_DIR_MODE)',
            $makeDirectory,
            'ensureDirectoryExists 의 mode 는 umask 로 깎인다 — 명시 chmod 가 없으면 0755 로 굳는다'
        );
        $this->assertStringContainsString(
            'FilePermissionHelper::inheritOwnershipFromParent($dir)',
            $makeDirectory
        );

        // ② 정합화는 root 갈래와 항상-실행 갈래를 모두 갖는다.
        $normalize = $this->methodBody($source, 'normalizeOwnership');
        $this->assertNotSame('', $normalize, 'normalizeOwnership 을 소스에서 찾지 못했다 — 검사가 공허하다');
        $this->assertStringContainsString('posix_geteuid() === 0', $normalize, 'root 갈래(sudo CLI 게시 대응)가 사라졌다');
        $this->assertStringContainsString('FilePermissionHelper::chownRecursive(', $normalize);

        // 들여쓰기 8칸 = 메서드 본문 최상위. root 조건 블록(12칸) 안으로 들어가면
        // 비-root CLI 계정에서 종전처럼 no-op 이 된다.
        $this->assertStringContainsString(
            "\n        FilePermissionHelper::inheritOwnershipFromParent(\$base);",
            $normalize,
            '소유권 상속이 root 갈래 안으로 들어갔다 — 비-root CLI 게시가 다시 무방비가 된다'
        );
        $this->assertStringContainsString(
            "\n        FilePermissionHelper::syncGroupWritabilityDetailed(\$base, force: true);",
            $normalize,
            'g+w 승격이 root 갈래 안으로 들어갔다 — 그룹을 공유하는 웹 계정이 재게시할 수 없다'
        );

        // ③ POSIX 환경에서는 실제 권한 비트까지 확인한다.
        if (windows_os() || ! function_exists('posix_geteuid')) {
            return;
        }

        clearstatcache();
        $versionDir = $service->versionDir(self::VERSION);
        $nested = $versionDir.'/templates/sirsoft-admin_basic';

        foreach (array_filter([$service->baseDir(), $versionDir, is_dir($nested) ? $nested : null]) as $dir) {
            $this->assertSame(
                0020,
                @fileperms($dir) & 0020,
                "게시 트리에 그룹 쓰기 비트가 없다: {$dir} — 웹 계정의 재게시가 영구 실패한다"
            );
        }
    }

    /**
     * 실패 마커의 백오프는 **같은 버전**만 억제한다.
     *
     * 버전이 오르면 그 버전은 아직 한 번도 시도된 적이 없다. 이전 버전의 마커로 막으면
     * "캐시 버전 갱신 → 게시" 규율이 마커 TTL 창(최대 300초) 동안 도로 끊긴다.
     *
     * @scenario publish_state=unpublished, artifact_integrity=intact, filesystem_writable=writable, environment=production, trigger=lifecycle, process_user=web
     *
     * @effects publish_failure_backoff_is_scoped_to_version
     */
    public function test_previous_version_failure_marker_does_not_suppress_new_version(): void
    {
        $this->app['env'] = 'production';
        ExtensionStaticCacheService::resetPublishScheduleForTesting();
        ExtensionStaticCacheService::fakeRootProcessForTesting(false);

        // 이전 버전(VERSION)의 신선한 실패 마커
        Cache::put('g7:core:ext.static.publish_failure', [
            'version' => self::VERSION,
            'at' => now()->toIso8601String(),
            'reason' => 'parent_not_writable',
            'count' => 2,
            'message' => 'denied',
        ], 300);

        // 버전 bump — 이 버전은 아직 한 번도 시도된 적이 없다.
        Cache::put('g7:core:ext.cache_version', self::VERSION + 1);

        ExtensionStaticCacheService::schedulePublishOnTerminate();

        $this->assertTrue(
            ExtensionStaticCacheService::isPublishScheduledForTesting(),
            '이전 버전의 마커가 새 버전의 게시 예약을 막았다 — 버전 갱신 → 게시 규율이 TTL 창 동안 끊긴다'
        );

        $this->app->terminate();

        $this->assertTrue($this->service()->isPublished(self::VERSION + 1));
    }

    /**
     * 소스에서 메서드 본문을 잘라냅니다 (다음 메서드 선언 직전까지).
     *
     * @param  string  $content  파일 전체 내용
     * @param  string  $method  메서드명
     * @return string 본문 (찾지 못하면 빈 문자열)
     */
    private function methodBody(string $content, string $method): string
    {
        $start = strpos($content, "function {$method}(");

        if ($start === false) {
            return '';
        }

        $rest = substr($content, $start);
        $next = preg_match(
            '/\n    (?:public|protected|private)\s+(?:static\s+)?function\s/',
            substr($rest, 1),
            $m,
            PREG_OFFSET_CAPTURE
        );

        return $next === 1 ? substr($rest, 0, $m[0][1] + 1) : $rest;
    }
}
