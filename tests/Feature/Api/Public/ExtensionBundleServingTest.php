<?php

namespace Tests\Feature\Api\Public;

use App\Services\ExtensionBundleService;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

/**
 * 확장 프론트엔드 병합 번들 서빙 엔드포인트 Feature 테스트
 *
 * /api/{modules,plugins}/bundle.{js,css} 의 응답 계약(Content-Type, ETag,
 * 304 Not Modified, 빈 번들 처리)을 검증한다. ExtensionBundleService 를
 * 컨테이너에 mock 바인딩해 활성 확장 조합에 의존하지 않고 컨트롤러 결선을 검증한다.
 */
class ExtensionBundleServingTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = storage_path('framework/testing/ext-bundle-serving');
        File::ensureDirectoryExists($this->fixtureDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->fixtureDir)) {
            foreach (glob($this->fixtureDir.'/*') as $f) {
                @unlink($f);
            }
            @rmdir($this->fixtureDir);
        }
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 번들 서비스를 모킹합니다.
     *
     * `$memoryContent` / `$declaredCount` 는 빈 경로(`$returnPath === ''`)일 때의 분기를
     * 정한다 — 디스크 캐시 실패(메모리 폴백)인지, 선언 0개(정상 빈 번들)인지,
     * 선언은 있는데 결과가 0인지(장애)를 컨트롤러가 이 둘로 가른다.
     *
     * @param  string  $returnPath  getBundleFilePath 반환값
     * @param  string  $memoryContent  buildBundleContent 반환값
     * @param  int  $declaredCount  countAssetDeclaringExtensions 반환값
     */
    private function bindBundleService(string $returnPath, string $memoryContent = '', int $declaredCount = 0): void
    {
        $mock = Mockery::mock(ExtensionBundleService::class);
        $mock->shouldReceive('getCurrentVersion')->andReturn(777);
        $mock->shouldReceive('getBundleFilePath')->andReturn($returnPath);
        $mock->shouldReceive('buildBundleContent')->andReturn($memoryContent);
        $mock->shouldReceive('countAssetDeclaringExtensions')->andReturn($declaredCount);
        $this->app->instance(ExtensionBundleService::class, $mock);
    }

    public function test_module_bundle_js_serves_merged_file_with_etag(): void
    {
        $path = $this->fixtureDir.'/module.777.js';
        File::put($path, "(function(){window.A=1})()\n;\n(function(){window.B=2})()");
        $this->bindBundleService($path);

        $response = $this->get('/api/modules/bundle.js?v=777');

        $response->assertOk();
        $this->assertStringStartsWith('text/javascript', $response->headers->get('Content-Type'));
        $response->assertHeader('ETag');
        $this->assertStringContainsString('window.A=1', $response->streamedContent() ?? $response->getContent());
        $this->assertStringContainsString("\n;\n", $response->streamedContent() ?? $response->getContent());
    }

    public function test_bundle_returns_304_on_matching_etag(): void
    {
        $path = $this->fixtureDir.'/plugin.777.js';
        File::put($path, '(function(){})()');
        $this->bindBundleService($path);

        $first = $this->get('/api/plugins/bundle.js?v=777');
        $first->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotNull($etag);

        $second = $this->get('/api/plugins/bundle.js?v=777', ['If-None-Match' => $etag]);
        $second->assertStatus(304);
    }

    public function test_empty_bundle_returns_empty_ok_response(): void
    {
        // 에셋을 선언한 활성 확장이 0개 → 빈 번들이 정상이다 → 빈 200
        $this->bindBundleService('', memoryContent: '', declaredCount: 0);

        $response = $this->get('/api/modules/bundle.js?v=777');

        $response->assertOk();
        $this->assertStringStartsWith('text/javascript', $response->headers->get('Content-Type'));
        // 빈 번들은 일반 Response(스트림 아님) — getContent 로 빈 본문 확인
        $this->assertSame('', $response->getContent());
    }

    /**
     * 디스크 캐시 실패는 500 이 아니라 메모리 병합 결과 200 이다 (E1 fail-soft).
     *
     * `ext-bundles` 디스크는 `throw => true` 라 권한 문제(uid 독점 0700 등)에서
     * `UnableToWriteFile` 이 그대로 올라온다. 캐시는 최적화일 뿐이므로 그 실패가
     * 모든 확장의 프론트엔드 JS/CSS 를 통째로 막으면 안 된다.
     *
     * @effects bundle_disk_write_failure_falls_back_to_memory
     */
    public function test_disk_cache_failure_serves_memory_merged_result(): void
    {
        // 디스크 캐시 실패 → 경로는 빈 문자열, 그러나 병합 결과는 메모리에 있다
        $this->bindBundleService('', memoryContent: '(function(){window.MEM=1})()', declaredCount: 2);

        $response = $this->get('/api/modules/bundle.js?v=777');

        $response->assertOk();
        $this->assertStringStartsWith('text/javascript', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('window.MEM=1', $response->getContent());
        // 캐시에 실패한 산출물이므로 immutable 로 박제하지 않는다
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * 선언은 있는데 병합 결과가 비면 503 이다 (E2).
     *
     * 배포 중 `dist` 가 잠깐 비면 **모든 확장이 빠진 빈 번들**이 200 으로 나가고, 프론트는
     * 404 도 오류도 받지 못한 채 한참 뒤 "Unknown action handler" 로 죽는다 — 그 시점에는
     * 원인이 번들이라는 사실이 화면에도 로그에도 남아 있지 않다.
     *
     * @effects bundle_empty_with_declared_assets_returns_503
     */
    public function test_empty_bundle_with_declared_assets_returns_503(): void
    {
        $this->bindBundleService('', memoryContent: '', declaredCount: 3);

        $response = $this->get('/api/modules/bundle.js?v=777');

        $response->assertStatus(503);
    }

    /**
     * 플러그인 경로도 같은 판정을 쓴다 — 두 컨트롤러가 갈라지지 않는다 (장애 쪽).
     *
     * @effects bundle_decision_is_shared_across_extension_types
     */
    public function test_plugin_bundle_returns_503_when_declared_but_empty(): void
    {
        $this->bindBundleService('', memoryContent: '', declaredCount: 1);

        $this->get('/api/plugins/bundle.css?v=777')->assertStatus(503);
    }

    /**
     * 플러그인 경로도 같은 판정을 쓴다 — 두 컨트롤러가 갈라지지 않는다 (정상 쪽).
     *
     * @effects bundle_decision_is_shared_across_extension_types
     */
    public function test_plugin_bundle_returns_empty_ok_when_nothing_declared(): void
    {
        $this->bindBundleService('', memoryContent: '', declaredCount: 0);

        $this->get('/api/plugins/bundle.css?v=777')->assertOk();
    }

    public function test_module_bundle_css_serves_with_css_content_type(): void
    {
        $path = $this->fixtureDir.'/module.777.css';
        File::put($path, '.a{color:red}');
        $this->bindBundleService($path);

        $response = $this->get('/api/modules/bundle.css?v=777');

        $response->assertOk();
        $this->assertStringStartsWith('text/css', $response->headers->get('Content-Type'));
    }

    public function test_bundle_routes_do_not_collide_with_asset_route(): void
    {
        // bundle.js 는 assets/{identifier}/{path} 로 매칭되면 안 됨 (정적 세그먼트 우선)
        $path = $this->fixtureDir.'/module.777.js';
        File::put($path, '(function(){window.BUNDLE=1})()');
        $this->bindBundleService($path);

        $response = $this->get('/api/modules/bundle.js?v=777');

        $response->assertOk();
        // 개별 에셋 서빙(serveAsset)이 아니라 번들 서빙(serveBundleJs)이 응답
        $this->assertStringContainsString('window.BUNDLE=1', $response->streamedContent() ?? $response->getContent());
    }
}
