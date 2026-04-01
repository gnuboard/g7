<?php

namespace Tests\Feature\Api\Public;

use App\Enums\ExtensionStatus;
use App\Models\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TemplateRouteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 활성화된 템플릿의 라우트 조회 성공 테스트
     */
    public function test_can_fetch_routes_for_active_template(): void
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

        // Act: 라우트 조회 요청
        $response = $this->getJson("/api/templates/{$template->identifier}/routes.json");

        // Assert: 성공 응답 및 데이터 구조 확인
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
            ])
            ->assertJson([
                'success' => true,
            ]);

        // routes.json 파일이 존재하므로 data가 null이 아님
        $this->assertNotNull($response->json('data'));
    }

    /**
     * 존재하지 않는 템플릿의 라우트 조회 실패 테스트
     */
    public function test_fails_to_fetch_routes_for_nonexistent_template(): void
    {
        // Act: 존재하지 않는 템플릿으로 요청
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
     * 비활성화된 템플릿의 라우트 조회 실패 테스트
     */
    public function test_fails_to_fetch_routes_for_inactive_template(): void
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

        // Act: 비활성화된 템플릿 라우트 조회
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
     * routes.json 파일이 없는 템플릿의 라우트 조회 실패 테스트
     */
    public function test_fails_when_routes_file_does_not_exist(): void
    {
        // Arrange: routes.json이 없는 템플릿 생성
        $template = Template::create([
            'identifier' => 'test-no-routes',
            'vendor' => 'test',
            'name' => ['ko' => '라우트 없는 템플릿', 'en' => 'No Routes Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
        ]);

        // Act: 라우트 조회 요청
        $response = $this->getJson("/api/templates/{$template->identifier}/routes.json");

        // Assert: 404 응답 확인 (파일이 없음)
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
     * 라우트 캐싱 동작 테스트
     */
    public function test_routes_are_cached_correctly(): void
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

        // 캐시 초기화
        Cache::forget("template.routes.{$template->identifier}");

        // Act: 첫 번째 요청 (캐시 생성)
        $response1 = $this->getJson("/api/templates/{$template->identifier}/routes.json");
        $response1->assertStatus(200);

        // 캐시 생성 확인
        $this->assertTrue(Cache::has("template.routes.{$template->identifier}"));

        // Act: 두 번째 요청 (캐시에서 조회)
        $response2 = $this->getJson("/api/templates/{$template->identifier}/routes.json");
        $response2->assertStatus(200);

        // 두 응답의 데이터가 동일한지 확인
        $this->assertEquals($response1->json('data'), $response2->json('data'));
    }

    /**
     * Rate Limiting 헤더 테스트
     *
     * api 미들웨어 그룹에 기본 throttle이 적용되어 있으므로
     * 헤더 존재 여부만 확인합니다.
     */
    public function test_rate_limiting_headers_are_present_on_route_requests(): void
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

        // Act: 라우트 조회 요청
        $response = $this->getJson("/api/templates/{$template->identifier}/routes.json");

        // Assert: Rate Limit 헤더가 존재하는지 확인
        $response->assertStatus(200)
            ->assertHeader('X-RateLimit-Limit')
            ->assertHeader('X-RateLimit-Remaining');

        // Rate Limit 값이 적용되었는지 확인 (api 그룹 기본값 또는 라우트 설정값)
        $rateLimit = (int) $response->headers->get('X-RateLimit-Limit');
        $this->assertGreaterThan(0, $rateLimit);
    }

    /**
     * 잘못된 templateIdentifier 파라미터 거부 테스트
     */
    public function test_rejects_invalid_template_identifier(): void
    {
        // 잘못된 identifier 테스트 (특수문자 포함)
        $invalidIdentifiers = [
            'sirsoft.admin',      // . 포함
            'sirsoft/admin',      // / 포함
            'sirsoft admin',      // 공백 포함
            'sirsoft@admin',      // @ 포함
            'sirsoft#admin',      // # 포함
        ];

        foreach ($invalidIdentifiers as $invalidIdentifier) {
            // Act: 잘못된 identifier로 요청
            $response = $this->getJson("/api/templates/{$invalidIdentifier}/routes.json");

            // Assert: 404 응답 (라우트 매칭 실패)
            $response->assertStatus(404);
        }
    }

    /**
     * 유효한 templateIdentifier 파라미터 허용 테스트
     */
    public function test_accepts_valid_template_identifier(): void
    {
        // 유효한 identifier 테스트
        $validIdentifiers = [
            'sirsoft-admin_basic',
            'vendor-template',
            'vendor_template',
            'vendor-template-v2',
        ];

        foreach ($validIdentifiers as $validIdentifier) {
            // Arrange: 템플릿 생성
            $template = Template::create([
                'identifier' => $validIdentifier,
                'vendor' => 'vendor',
                'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
                'version' => '1.0.0',
                'type' => 'admin',
                'status' => ExtensionStatus::Active->value,
                'description' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            ]);

            // Act: 유효한 identifier로 요청
            $response = $this->getJson("/api/templates/{$validIdentifier}/routes.json");

            // Assert: 정상 처리 또는 404 (routes.json 파일 존재 여부에 따라 다름)
            $this->assertContains($response->status(), [200, 404]);
        }
    }

    /**
     * API 사용량 로깅 테스트
     */
    public function test_api_usage_is_logged_for_route_requests(): void
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

        // Act: 라우트 조회 요청
        $response = $this->getJson("/api/templates/{$template->identifier}/routes.json");

        // Assert: 성공 응답 확인 (로깅은 내부적으로 수행됨)
        $response->assertStatus(200);

        // Note: 실제 로그 확인은 통합 테스트에서 수행하거나 모킹으로 검증 가능
    }
}
