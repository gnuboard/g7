<?php

namespace Tests\Feature\Template;

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

        // 캐시 초기화
        Cache::forget("template.routes.{$template->identifier}");

        // Act: 첫 번째 요청 (캐시 생성)
        $response1 = $this->getJson("/api/templates/{$template->identifier}/routes.json");
        $response1->assertStatus(200);

        // 캐시가 생성되었는지 확인
        $this->assertTrue(Cache::has("template.routes.{$template->identifier}"));

        // Act: 두 번째 요청 (캐시에서 조회)
        $response2 = $this->getJson("/api/templates/{$template->identifier}/routes.json");
        $response2->assertStatus(200);

        // 두 응답의 데이터가 동일한지 확인
        $this->assertEquals($response1->json('data'), $response2->json('data'));
    }
}
