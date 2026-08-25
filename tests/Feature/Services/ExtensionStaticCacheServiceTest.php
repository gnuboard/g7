<?php

namespace Tests\Feature\Services;

use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Models\Template;
use App\Services\ExtensionBundleService;
use App\Services\ExtensionStaticCacheService;
use App\Services\LanguagePackService;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
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

    private function service(): ExtensionStaticCacheService
    {
        return app(ExtensionStaticCacheService::class);
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
     * @scenario publish_state=unpublished, environment=production, trigger=manual_command
     */
    public function test_publishes_empty_tree_when_no_active_templates(): void
    {
        $service = $this->service();

        $this->assertTrue($service->publishCurrent());
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
        $this->assertTrue($service->publishCurrent());

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
        $this->assertTrue($service->publishCurrent());

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
     * components.json 사본과 dist 에셋이 게시되고 `*.map` 은 제외된다.
     *
     * @effects published_tree_excludes_sourcemaps
     */
    public function test_publishes_components_and_dist_assets_excluding_sourcemaps(): void
    {
        $this->createActiveTemplate();

        $service = $this->service();
        $this->assertTrue($service->publishCurrent());

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
        $this->assertTrue($service->publishCurrent());

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
        $this->assertTrue($service->publishCurrent());

        $templatesDir = $service->versionDir(self::VERSION).'/templates';
        $this->assertDirectoryExists($templatesDir.'/sirsoft-admin_basic');
        $entries = array_map('basename', File::directories($templatesDir));
        $this->assertSame(['sirsoft-admin_basic'], $entries, '패턴 불일치 식별자는 트리에 없어야 한다');
    }

    /**
     * 멱등 — 게시 완료 상태에서 재호출은 skip (기존 게시물 유지), force 는 재게시.
     *
     * @scenario publish_state=published, environment=production, trigger=manual_command
     *
     * @effects publish_is_idempotent_until_forced
     */
    public function test_publish_is_idempotent_and_force_republishes(): void
    {
        $service = $this->service();
        $this->assertTrue($service->publishCurrent());

        // 게시 후 심은 마커가 skip 재호출에서 살아남으면 재게시가 없었다는 증거
        $marker = $service->versionDir(self::VERSION).'/idempotency-marker';
        File::put($marker, 'x');

        $this->assertTrue($service->publishCurrent());
        $this->assertFileExists($marker);

        // force 재게시는 디렉토리를 새로 만든다 → 마커 소실
        $this->assertTrue($service->publishCurrent(force: true));
        $this->assertFileDoesNotExist($marker);
        $this->assertTrue($service->isPublished(self::VERSION));
    }

    /**
     * 쓰기 단계 실패 시 예외를 삼키고 false + tmp 잔존물 정리 + manifest 부재.
     *
     * @scenario publish_state=partial, environment=production, trigger=lifecycle
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
     * GC — 현재 버전 + 직전 1개 보존, 그 외 삭제 (tmp 잔존물 포함).
     *
     * @effects cleanup_keeps_current_and_previous_versions
     */
    public function test_cleanup_keeps_current_and_previous_only(): void
    {
        $service = $this->service();
        $this->assertTrue($service->publishCurrent());

        // 과거 버전 3개 + 고아 tmp 시뮬레이션
        foreach ([100, 200, 300] as $old) {
            File::ensureDirectoryExists($service->versionDir($old));
            File::put($service->versionDir($old).'/manifest.json', '{}');
        }
        File::ensureDirectoryExists($service->baseDir().'/400.tmp');

        $deleted = $service->cleanup();

        // 보존: 현재(VERSION) + 직전(300). 삭제: 100, 200, 400.tmp
        $this->assertSame(3, $deleted);
        $this->assertDirectoryExists($service->versionDir(self::VERSION));
        $this->assertDirectoryExists($service->versionDir(300));
        $this->assertDirectoryDoesNotExist($service->versionDir(200));
        $this->assertDirectoryDoesNotExist($service->versionDir(100));
        $this->assertDirectoryDoesNotExist($service->baseDir().'/400.tmp');
    }

    /**
     * kill-switch(core.static_cache.enabled=false) — 게시 자체가 중단된다.
     *
     * @scenario publish_state=unpublished, environment=production, trigger=kill_switch
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
     * @scenario publish_state=unpublished, environment=dev, trigger=lifecycle
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
     * @scenario publish_state=unpublished, environment=production, trigger=lifecycle
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
     * terminating 게시 예약 플래그는 콜백 실행 시점에 리셋된다.
     *
     * 요청마다 앱 인스턴스를 새로 만드는 장수 프로세스(Octane 류)에서는 static 플래그만
     * 프로세스에 살아남는다 — 리셋이 없으면 2번째 이후 bump 는 새 앱에 콜백이 등록되지
     * 않은 채 플래그만 true 라 그 워커에서 영구 미게시가 되고, `AssetUrl` 자가 치유도
     * 같은 플래그를 쓰므로 복구 경로가 없다. FPM(요청=프로세스)에서는 무영향.
     *
     * @scenario publish_state=published, environment=production, trigger=lifecycle
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
     * @scenario publish_state=published, environment=production, trigger=manual_command
     *
     * @effects published_htaccess_declares_compression
     */
    public function test_published_htaccess_declares_compression(): void
    {
        $service = $this->service();

        $this->assertTrue($service->publishCurrent());

        $htaccess = File::get($service->versionDir(self::VERSION).'/.htaccess');

        $this->assertStringContainsString('mod_deflate.c', $htaccess);
        $this->assertStringContainsString('application/json', $htaccess);
    }
}
