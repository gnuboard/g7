<?php

namespace Tests\Feature\Api\Public;

use App\Models\Template;
use App\Models\TemplateLayoutPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 레이아웃 미리보기 서빙의 컴포넌트 권한 필터 (core-backend #11).
 */
class LayoutPreviewPermissionFilterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 미리보기 대상 템플릿
     */
    private Template $template;

    /**
     * 미리보기를 저장할 템플릿을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->template = Template::factory()->create();
    }

    /**
     * 미리보기 응답도 실제 서빙과 같이 컴포넌트 permissions 를 걸러 내고 속성을 지운다 (core-backend #11).
     *
     * 미리보기가 걸러지지 않으면 편집자가 보는 화면이 실제 서빙과 다르고, 권한 식별자도 응답에 남는다.
     *
     * @scenario endpoint=layouts.preview.serve,viewer=guest,component_permission=present
     *
     * @effects preview_filters_component_permissions_like_serving
     *
     * @return void 반환값 없음
     */
    public function test_preview_filters_component_permissions(): void
    {
        $content = [
            'version' => '1.0.0',
            'layout_name' => 'main',
            'components' => [
                ['id' => 'root', 'type' => 'layout', 'name' => 'Container', 'children' => [
                    ['id' => 'public_text', 'type' => 'basic', 'name' => 'Span', 'text' => '공개'],
                    ['id' => 'admin_only', 'type' => 'basic', 'name' => 'Button', 'permissions' => ['core.settings.update']],
                ]],
            ],
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

        $response = $this->getJson("/api/layouts/preview/{$preview->token}.json");

        $response->assertStatus(200);
        $body = $response->getContent();
        $this->assertStringContainsString('public_text', $body);
        $this->assertStringNotContainsString('admin_only', $body);
        $this->assertStringNotContainsString('core.settings.update', $body);
    }
}
