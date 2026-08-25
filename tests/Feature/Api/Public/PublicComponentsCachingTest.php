<?php

namespace Tests\Feature\Api\Public;

use App\Enums\ExtensionStatus;
use App\Models\Module;
use App\Models\Plugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 모듈/플러그인 components.json 서빙의 폴백 API 캐시 계약 (#122 작업 C).
 *
 * `cachedJsonResponse` 가 ETag/304 조건부 캐시와 환경 분기(prod: public
 * max-age / dev: no-cache)를 적용하는지 검증한다. 정적 게시본(fast path)
 * 미스 시 이 API 가 폴백 경로이므로, 폴백 품질이 곧 미게시 상태의 부트 성능이다.
 */
class PublicComponentsCachingTest extends TestCase
{
    use RefreshDatabase;

    private string $moduleIdentifier;

    private string $pluginIdentifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->moduleIdentifier = 'test-module'.uniqid();
        $this->pluginIdentifier = 'test-plugin'.uniqid();

        Module::factory()->create([
            'identifier' => $this->moduleIdentifier,
            'status' => ExtensionStatus::Active->value,
        ]);

        Plugin::factory()->create([
            'identifier' => $this->pluginIdentifier,
            'status' => ExtensionStatus::Active->value,
        ]);

        mkdir(base_path("modules/{$this->moduleIdentifier}"), 0755, true);
        file_put_contents(
            base_path("modules/{$this->moduleIdentifier}/components.json"),
            json_encode(['composite' => ['TestWidget' => ['path' => 'components/TestWidget.tsx']]])
        );

        mkdir(base_path("plugins/{$this->pluginIdentifier}"), 0755, true);
        file_put_contents(
            base_path("plugins/{$this->pluginIdentifier}/components.json"),
            json_encode(['composite' => ['TestPanel' => ['path' => 'components/TestPanel.tsx']]])
        );
    }

    protected function tearDown(): void
    {
        foreach ([
            base_path("modules/{$this->moduleIdentifier}"),
            base_path("plugins/{$this->pluginIdentifier}"),
        ] as $dir) {
            if (file_exists($dir)) {
                array_map('unlink', glob($dir.'/*') ?: []);
                rmdir($dir);
            }
        }

        parent::tearDown();
    }

    /**
     * 프로덕션: 200 응답에 ETag + public max-age + Vary 부착
     */
    public function test_module_components_has_etag_and_cache_headers_in_production(): void
    {
        app()['env'] = 'production';

        $response = $this->getJson("/api/modules/{$this->moduleIdentifier}/components.json");

        $response->assertStatus(200);
        $this->assertNotNull($response->headers->get('ETag'));
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=3600', $cacheControl);
        $this->assertStringContainsString('Accept-Encoding', (string) $response->headers->get('Vary'));
    }

    /**
     * 프로덕션: If-None-Match 일치 시 304 (본문 미전송)
     *
     * @effects fallback_api_serves_etag_304
     */
    public function test_module_components_returns_304_with_matching_etag(): void
    {
        app()['env'] = 'production';

        $first = $this->getJson("/api/modules/{$this->moduleIdentifier}/components.json");
        $etag = $first->headers->get('ETag');
        $this->assertNotNull($etag);

        $second = $this->getJson(
            "/api/modules/{$this->moduleIdentifier}/components.json",
            ['If-None-Match' => $etag]
        );

        $second->assertStatus(304);
        $this->assertSame('', $second->getContent());
        $this->assertSame($etag, $second->headers->get('ETag'));
        $this->assertStringContainsString('max-age=3600', (string) $second->headers->get('Cache-Control'));
    }

    /**
     * 개발: no-cache (파일 수정 즉시 반영 — 브라우저 1h 캐시 비대칭 해소 F10)
     */
    public function test_module_components_no_cache_in_development(): void
    {
        app()['env'] = 'local';

        $response = $this->getJson("/api/modules/{$this->moduleIdentifier}/components.json");

        $response->assertStatus(200);
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('max-age=3600', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * 플러그인도 동일 계약 (200 헤더 + 304)
     */
    public function test_plugin_components_caching_contract(): void
    {
        app()['env'] = 'production';

        $first = $this->getJson("/api/plugins/{$this->pluginIdentifier}/components.json");
        $first->assertStatus(200);
        $etag = $first->headers->get('ETag');
        $this->assertNotNull($etag);
        $this->assertStringContainsString('max-age=3600', (string) $first->headers->get('Cache-Control'));

        $second = $this->getJson(
            "/api/plugins/{$this->pluginIdentifier}/components.json",
            ['If-None-Match' => $etag]
        );
        $second->assertStatus(304);
    }

    /**
     * 플러그인도 개발 환경에서는 no-cache (모듈과 동일 축 — 한쪽만 잠그면 비대칭이 남는다)
     */
    public function test_plugin_components_no_cache_in_development(): void
    {
        app()['env'] = 'local';

        $response = $this->getJson("/api/plugins/{$this->pluginIdentifier}/components.json");

        $response->assertStatus(200);
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('max-age=3600', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * 내용 변경 시 ETag 불일치 → 200 재전송 (ETag 는 내용 해시)
     */
    public function test_etag_changes_when_content_changes(): void
    {
        app()['env'] = 'production';

        $first = $this->getJson("/api/modules/{$this->moduleIdentifier}/components.json");
        $etag = $first->headers->get('ETag');

        file_put_contents(
            base_path("modules/{$this->moduleIdentifier}/components.json"),
            json_encode(['composite' => ['Changed' => ['path' => 'components/Changed.tsx']]])
        );

        $second = $this->getJson(
            "/api/modules/{$this->moduleIdentifier}/components.json",
            ['If-None-Match' => (string) $etag]
        );

        $second->assertStatus(200);
        $this->assertNotSame($etag, $second->headers->get('ETag'));
    }
}
