<?php

namespace Tests\Feature\Services;

use App\Enums\ExtensionStatus;
use App\Models\Template;
use App\Services\ExtensionStaticCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 정적 게시 — 언어팩 규칙(BCP 47) 로케일의 lang 파일 (#148)
 *
 * 종전 패턴(`[a-z]{2}…`)이 `fil` 을 걸러 정적 게시본에서 빠지고, 매 요청 API 폴백(그 API 도 404)으로 떨어졌다.
 * 게시 트리 격리·버전 시드는 `ExtensionStaticCacheServiceTest` 와 같은 방식이다(운영 게시본을 지우지 않는다).
 */
class ExtensionStaticCacheBcp47LocaleTest extends TestCase
{
    use RefreshDatabase;

    private const VERSION = 987654322;

    /** 테스트 전용 public 루트 (실 게시 트리 격리) */
    private string $isolatedPublicPath;

    /**
     * public 루트를 임시 경로로 돌리고 결정적 버전을 시드합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->isolatedPublicPath = storage_path('framework/testing/public-bcp47-'.getmypid());
        File::ensureDirectoryExists($this->isolatedPublicPath);
        $this->app->usePublicPath($this->isolatedPublicPath);

        Cache::put('g7:core:ext.cache_version', self::VERSION);
        Cache::lock('ext-static.publish.'.self::VERSION, 300)->forceRelease();
        Cache::forget('g7:core:ext.static.publish_failure');

        ExtensionStaticCacheService::resetPublishScheduleForTesting();
        File::deleteDirectory(public_path('build/ext'));
    }

    /**
     * 임시 public 루트를 지우고 게시 예약 상태를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory(public_path('build/ext'));
        File::deleteDirectory($this->isolatedPublicPath);
        ExtensionStaticCacheService::resetPublishScheduleForTesting();

        parent::tearDown();
    }

    /**
     * 세 글자·문자 표기 로케일도 lang 게시 대상이다.
     *
     * @scenario publish_state=unpublished, artifact_integrity=intact, filesystem_writable=writable, environment=production, trigger=manual_command, process_user=web
     *
     * @effects published_lang_covers_bcp47_pack_locales
     *
     * @return void 반환값 없음
     */
    public function test_publishes_lang_for_bcp47_pack_locales(): void
    {
        config(['app.supported_locales' => ['ko', 'en', 'fil', 'zh-Hant']]);
        Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트', 'en' => 'Test'],
        ]);

        $service = app(ExtensionStaticCacheService::class);
        $this->assertTrue(
            $service->publishCurrent(),
            'publish failed — marker='.json_encode(ExtensionStaticCacheService::failureMarker())
        );

        $langDir = $service->versionDir(self::VERSION).'/templates/sirsoft-admin_basic/lang';
        $this->assertFileExists($langDir.'/fil.json');
        $this->assertFileExists($langDir.'/zh-Hant.json');
    }
}
