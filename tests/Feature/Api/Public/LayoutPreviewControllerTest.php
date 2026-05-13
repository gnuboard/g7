<?php

namespace Tests\Feature\Api\Public;

use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 공개 레이아웃 미리보기 API 테스트
 *
 * GET /api/layouts/preview/{token}.json 엔드포인트 테스트
 */
class LayoutPreviewControllerTest extends TestCase
{
    use RefreshDatabase;

    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->template = Template::factory()->create();
    }

    /**
     * 유효한 토큰으로 미리보기 레이아웃 조회 성공
     */
    public function test_can_serve_preview_with_valid_token(): void
    {
        // Arrange
        $content = [
            'version' => '1.0.0',
            'layout_name' => 'main',
            'components' => [['id' => 'root', 'type' => 'layout', 'name' => 'Container']],
            'data_sources' => [],
        ];

        $preview = TemplateLayoutPreview::create([
            'token' => (string) Str::uuid(),
            'template_id' => $this->template->id,
            'layout_name' => 'main',
            'content' => $content,
            'admin_id' => 1,
            'expires_at' => now()->addMinutes(30),
        ]);

        // Act
        $response = $this->getJson("/api/layouts/preview/{$preview->token}.json");

        // Assert
        $response->assertStatus(200);
        $response->assertJsonFragment(['layout_name' => 'main']);
    }

    /**
     * 만료된 토큰으로 조회 시 404
     */
    public function test_expired_preview_returns_404(): void
    {
        // Arrange
        $preview = TemplateLayoutPreview::create([
            'token' => (string) Str::uuid(),
            'template_id' => $this->template->id,
            'layout_name' => 'main',
            'content' => ['version' => '1.0.0', 'components' => []],
            'admin_id' => 1,
            'expires_at' => now()->subMinutes(1), // 이미 만료
        ]);

        // Act
        $response = $this->getJson("/api/layouts/preview/{$preview->token}.json");

        // Assert
        $response->assertStatus(404);
    }

    /**
     * 존재하지 않는 토큰으로 조회 시 404
     */
    public function test_nonexistent_token_returns_404(): void
    {
        // Arrange
        $fakeToken = (string) Str::uuid();

        // Act
        $response = $this->getJson("/api/layouts/preview/{$fakeToken}.json");

        // Assert
        $response->assertStatus(404);
    }

    /**
     * 인증 없이도 미리보기 조회 가능 (토큰이 보안 메커니즘)
     */
    public function test_preview_does_not_require_authentication(): void
    {
        // Arrange
        $preview = TemplateLayoutPreview::create([
            'token' => (string) Str::uuid(),
            'template_id' => $this->template->id,
            'layout_name' => 'dashboard',
            'content' => ['version' => '1.0.0', 'components' => [['id' => 'test']]],
            'admin_id' => 1,
            'expires_at' => now()->addMinutes(30),
        ]);

        // Act - 인증 헤더 없이 요청
        $response = $this->getJson("/api/layouts/preview/{$preview->token}.json");

        // Assert
        $response->assertStatus(200);
    }
}
