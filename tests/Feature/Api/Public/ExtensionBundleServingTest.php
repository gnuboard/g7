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
     * ExtensionBundleService 를 지정 경로를 반환하도록 mock 바인딩한다.
     */
    private function bindBundleService(string $returnPath): void
    {
        $mock = Mockery::mock(ExtensionBundleService::class);
        $mock->shouldReceive('getCurrentVersion')->andReturn(777);
        $mock->shouldReceive('getBundleFilePath')->andReturn($returnPath);
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
        // 활성 global 에셋이 없으면 서비스가 빈 경로 반환 → 컨트롤러 빈 200
        $this->bindBundleService('');

        $response = $this->get('/api/modules/bundle.js?v=777');

        $response->assertOk();
        $this->assertStringStartsWith('text/javascript', $response->headers->get('Content-Type'));
        // 빈 번들은 일반 Response(스트림 아님) — getContent 로 빈 본문 확인
        $this->assertSame('', $response->getContent());
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
