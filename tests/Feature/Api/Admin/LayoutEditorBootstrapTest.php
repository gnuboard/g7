<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionStatus;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 레이아웃 편집기 부팅 응답 검증
 *
 * /admin/layout-editor/{identifier} 진입 시:
 * - admin.blade.php 가 서빙됨
 * - window.G7Config.activeModules / activePlugins 키가 페이지에 노출됨
 * - 기존 modules / plugins 키도 보존됨(비파괴적 보강)
 * - 비활성 템플릿도 부팅 응답 정상 반환(편집은 가능)
 *
 * 코어 라우팅(/admin/{any?})이 모든 admin 경로를 admin.blade 로 보내므로
 * 별도 라우트 추가 없이 클라이언트 사이드 분기(template-engine.ts 의
 * checkLayoutEditorMode)가 편집 모드 진입을 결정한다.
 */
class LayoutEditorBootstrapTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 같은 스위트의 GDPR 미들웨어 의존 테스트와 migrate:fresh 정합성을 맞추기 위해.
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    private function createSuperUser(): User
    {
        return User::factory()->create([
            'is_super' => true,
        ]);
    }

    private function createActiveAdminTemplate(): Template
    {
        return Template::factory()->create([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);
    }

    #[Test]
    public function admin_layout_editor_url_serves_admin_blade(): void
    {
        $this->createActiveAdminTemplate();

        $user = $this->createSuperUser();
        $this->actingAs($user, 'web');

        $response = $this->get('/admin/layout-editor/sirsoft-admin_basic');

        $response->assertStatus(200);
        $response->assertSee('window.G7Config', false);
    }

    #[Test]
    public function admin_blade_emits_active_modules_meta_key_in_g7config(): void
    {
        $this->createActiveAdminTemplate();

        $user = $this->createSuperUser();
        $this->actingAs($user, 'web');

        $response = $this->get('/admin/layout-editor/sirsoft-admin_basic');

        $response->assertStatus(200);
        // window.G7Config 객체에 activeModules / activePlugins 키가 포함되어야 함
        $response->assertSee('activeModules:', false);
        $response->assertSee('activePlugins:', false);
    }

    #[Test]
    public function admin_blade_preserves_legacy_modules_plugins_keys(): void
    {
        $this->createActiveAdminTemplate();

        $user = $this->createSuperUser();
        $this->actingAs($user, 'web');

        $response = $this->get('/admin/layout-editor/sirsoft-admin_basic');

        $response->assertStatus(200);
        // 기존 키 (modules / plugins / moduleAssets / pluginAssets) 보존
        $response->assertSee('modules:', false);
        $response->assertSee('plugins:', false);
        $response->assertSee('moduleAssets:', false);
        $response->assertSee('pluginAssets:', false);
    }
}
