<?php

namespace Tests\Feature\Template;

use App\Contracts\Extension\CacheInterface;
use App\Enums\ExtensionStatus;
use App\Models\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PublicTemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 활성화된 템플릿의 라우트 정보 조회 성공 테스트
     */
    public function test_can_get_routes_for_active_template(): void
    {
        // Arrange: 활성화된 템플릿 생성
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        // Act: 라우트 정보 조회
        $response = $this->getJson("/api/templates/{$template->identifier}/routes.json");

        // Assert: 성공 응답 확인
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
            ])
            ->assertJson([
                'success' => true,
            ]);

        // routes.json 파일이 실제로 존재하므로 data가 있어야 함
        $this->assertNotNull($response->json('data'));
    }

    /**
     * 비활성화된 템플릿의 라우트 정보 조회 실패 테스트
     */
    public function test_cannot_get_routes_for_inactive_template(): void
    {
        // Arrange: 비활성화된 템플릿 생성
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Inactive->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        // Act: 라우트 정보 조회
        $response = $this->getJson("/api/templates/{$template->identifier}/routes.json");

        // Assert: 404 응답 확인
        $response->assertStatus(404)
            ->assertJsonStructure([
                'success',
                'message',
            ])
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * 존재하지 않는 템플릿의 라우트 정보 조회 실패 테스트
     */
    public function test_cannot_get_routes_for_nonexistent_template(): void
    {
        // Act: 존재하지 않는 템플릿 식별자로 조회
        $response = $this->getJson('/api/templates/nonexistent-template/routes.json');

        // Assert: 404 응답 확인
        $response->assertStatus(404)
            ->assertJsonStructure([
                'success',
                'message',
            ])
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * routes.json 파일이 없는 템플릿의 라우트 정보 조회 실패 테스트
     */
    public function test_cannot_get_routes_when_routes_file_not_exists(): void
    {
        // Arrange: 활성화된 템플릿 생성 (routes.json이 없는 템플릿)
        $template = Template::create([
            'identifier' => 'test-no-routes',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
        ]);

        // Act: 라우트 정보 조회
        $response = $this->getJson("/api/templates/{$template->identifier}/routes.json");

        // Assert: 404 응답 확인 (routes.json 파일이 없음)
        $response->assertStatus(404)
            ->assertJsonStructure([
                'success',
                'message',
            ])
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * 캐싱 동작 테스트
     */
    public function test_routes_are_cached(): void
    {
        // Arrange: 활성화된 템플릿 생성
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        // `?v` 생략 시 현재 확장 캐시 버전으로 폴백하므로 (#588 — `.v0` 사각 키 방지)
        // 버전을 시드해 결정적 키로 검증한다 (getExtensionCacheVersion 은 0 을 반환하지 않음)
        Cache::put('g7:core:ext.cache_version', 1234);

        $cache = app(CacheInterface::class);
        $cacheKey = "template.routes.{$template->identifier}.v1234";
        $cache->forget($cacheKey);

        // Act: 첫 번째 요청 (캐시 생성)
        $response1 = $this->getJson("/api/templates/{$template->identifier}/routes.json");
        $response1->assertStatus(200);

        // 캐시가 생성되었는지 확인
        $this->assertNotNull($cache->get($cacheKey));

        // Act: 두 번째 요청 (캐시에서 조회)
        $response2 = $this->getJson("/api/templates/{$template->identifier}/routes.json");
        $response2->assertStatus(200);

        // 두 응답의 데이터가 동일한지 확인
        $this->assertEquals($response1->json('data'), $response2->json('data'));
    }

    /**
     * config.json 서빙이 캐시를 생성하고 installed manifest 버전을 반환하는지 테스트 (#588, 공개 #119)
     *
     * @scenario cache_state=cold,template_type=admin
     *
     * @effects serve_config_caches_manifest_and_embeds_cache_version
     */
    public function test_serve_config_caches_manifest_and_returns_version(): void
    {
        // Arrange: 활성화된 템플릿 생성 (실제 번들 파일을 읽는 경량 픽스처)
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        $cache = app(CacheInterface::class);
        $cacheKey = "template.config.{$template->identifier}";
        $cache->forget($cacheKey);

        // Act
        $response = $this->getJson("/api/templates/{$template->identifier}/config.json");

        // Assert: 성공 + installed template.json 의 실제 version 반환
        $response->assertStatus(200)->assertJson(['success' => true]);

        $manifest = json_decode(
            file_get_contents(base_path("templates/{$template->identifier}/template.json")),
            true
        );
        $this->assertSame($manifest['version'], $response->json('data.version'));

        // cache_version 은 캐시 read 후 현재값이 주입되는 유효 정수
        $this->assertIsInt($response->json('data.cache_version'));
        $this->assertGreaterThan(0, $response->json('data.cache_version'));

        // 서버 캐시 키가 생성됨
        $this->assertNotNull($cache->get($cacheKey), 'config 조회 후 서버 캐시가 생성되어야 합니다.');
    }

    /**
     * config.json 캐시 삭제 후 최신 manifest 가 반환되는지 테스트 (#588, 공개 #119)
     *
     * 캐시 히트 증명(변조본 서빙) → forget → 실제 파일 버전 복귀 체인.
     * 라이브 재현(sentinel 주입 → cache-clear 무효 → 직접 forget 으로만 해소)의 자동화판.
     *
     * @scenario cache_state=warm_stale,template_type=admin
     *
     * @effects serve_config_returns_installed_manifest_after_invalidation
     */
    public function test_serve_config_returns_fresh_manifest_after_cache_cleared(): void
    {
        // Arrange
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        $cache = app(CacheInterface::class);
        $cacheKey = "template.config.{$template->identifier}";

        // 캐시에 변조된 manifest 심기 (파일 무수정 — stale 상태 재현)
        $cache->put($cacheKey, [
            'success' => true,
            'data' => ['version' => '9.9.9-stale-sentinel'],
        ], 3600);

        // Act & Assert 1: 캐시 히트 경로 증명 — 변조본이 그대로 서빙됨
        $stale = $this->getJson("/api/templates/{$template->identifier}/config.json");
        $stale->assertStatus(200);
        $this->assertSame('9.9.9-stale-sentinel', $stale->json('data.version'));

        // Act & Assert 2: forget 후 실제 파일 버전 복귀
        $cache->forget($cacheKey);

        $fresh = $this->getJson("/api/templates/{$template->identifier}/config.json");
        $fresh->assertStatus(200);

        $manifest = json_decode(
            file_get_contents(base_path("templates/{$template->identifier}/template.json")),
            true
        );
        $this->assertSame($manifest['version'], $fresh->json('data.version'));
    }
}
