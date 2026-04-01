<?php

namespace Tests\Unit\Services;

use App\Models\Template;
use App\Models\TemplateLayoutPreview;
use App\Services\LayoutPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LayoutPreviewService 유닛 테스트
 */
class LayoutPreviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private LayoutPreviewService $service;
    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(LayoutPreviewService::class);
        $this->template = Template::factory()->create();
    }

    /**
     * 미리보기 생성 성공
     */
    public function test_create_preview(): void
    {
        // Arrange
        $content = [
            'version' => '1.0.0',
            'layout_name' => 'main',
            'components' => [['id' => 'root']],
        ];

        // Act
        $preview = $this->service->createPreview(
            $this->template->id,
            'main',
            $content,
            1
        );

        // Assert
        $this->assertInstanceOf(TemplateLayoutPreview::class, $preview);
        $this->assertEquals($this->template->id, $preview->template_id);
        $this->assertEquals('main', $preview->layout_name);
        $this->assertEquals($content, $preview->content);
        $this->assertEquals(1, $preview->admin_id);
        $this->assertNotEmpty($preview->token);
        $this->assertTrue($preview->expires_at->isFuture());
    }

    /**
     * 동일 조합으로 미리보기 생성 시 이전 것 삭제
     */
    public function test_create_preview_replaces_existing(): void
    {
        // Arrange
        $preview1 = $this->service->createPreview(
            $this->template->id,
            'main',
            ['version' => '1.0.0', 'components' => [['id' => 'v1']]],
            1
        );

        // Act
        $preview2 = $this->service->createPreview(
            $this->template->id,
            'main',
            ['version' => '1.0.0', 'components' => [['id' => 'v2']]],
            1
        );

        // Assert
        $this->assertDatabaseMissing('template_layout_previews', ['token' => $preview1->token]);
        $this->assertDatabaseHas('template_layout_previews', ['token' => $preview2->token]);
    }

    /**
     * 다른 관리자의 미리보기는 삭제하지 않음
     */
    public function test_create_preview_does_not_delete_other_admins_preview(): void
    {
        // Arrange
        $preview1 = $this->service->createPreview(
            $this->template->id,
            'main',
            ['version' => '1.0.0', 'components' => []],
            1 // admin 1
        );

        // Act
        $preview2 = $this->service->createPreview(
            $this->template->id,
            'main',
            ['version' => '1.0.0', 'components' => []],
            2 // admin 2
        );

        // Assert - 둘 다 존재
        $this->assertDatabaseHas('template_layout_previews', ['token' => $preview1->token]);
        $this->assertDatabaseHas('template_layout_previews', ['token' => $preview2->token]);
    }

    /**
     * 만료된 미리보기 정리
     */
    public function test_cleanup_expired(): void
    {
        // Arrange - 만료된 미리보기
        TemplateLayoutPreview::create([
            'token' => 'expired-token-1',
            'template_id' => $this->template->id,
            'layout_name' => 'main',
            'content' => ['components' => []],
            'admin_id' => 1,
            'expires_at' => now()->subMinutes(1),
        ]);

        // Arrange - 유효한 미리보기
        TemplateLayoutPreview::create([
            'token' => 'valid-token-1',
            'template_id' => $this->template->id,
            'layout_name' => 'main',
            'content' => ['components' => []],
            'admin_id' => 2,
            'expires_at' => now()->addMinutes(30),
        ]);

        // Act
        $deleted = $this->service->cleanupExpired();

        // Assert
        $this->assertEquals(1, $deleted);
        $this->assertDatabaseMissing('template_layout_previews', ['token' => 'expired-token-1']);
        $this->assertDatabaseHas('template_layout_previews', ['token' => 'valid-token-1']);
    }

    /**
     * 특정 관리자/레이아웃 조합 미리보기 삭제
     */
    public function test_delete_by_layout_and_admin(): void
    {
        // Arrange
        TemplateLayoutPreview::create([
            'token' => 'target-token',
            'template_id' => $this->template->id,
            'layout_name' => 'main',
            'content' => ['components' => []],
            'admin_id' => 1,
            'expires_at' => now()->addMinutes(30),
        ]);

        TemplateLayoutPreview::create([
            'token' => 'other-token',
            'template_id' => $this->template->id,
            'layout_name' => 'sidebar',
            'content' => ['components' => []],
            'admin_id' => 1,
            'expires_at' => now()->addMinutes(30),
        ]);

        // Act
        $deleted = $this->service->deleteByLayoutAndAdmin($this->template->id, 'main', 1);

        // Assert
        $this->assertEquals(1, $deleted);
        $this->assertDatabaseMissing('template_layout_previews', ['token' => 'target-token']);
        $this->assertDatabaseHas('template_layout_previews', ['token' => 'other-token']);
    }
}
