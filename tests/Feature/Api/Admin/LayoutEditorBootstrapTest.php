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
 * - G7 설정 데이터 블록(`<script type="application/json" id="g7-config">`)에 activeModules / activePlugins 키가 실림
 * - 기존 modules / plugins 키도 보존됨(비파괴적 보강)
 * - strict CSP 대응(#850) 이후 window.G7Config 대입은 g7-shell-boot.js 가 이 블록을 읽어 한다 —
 *   페이지 HTML 에는 인라인 대입문이 없으므로 데이터 블록을 JSON 으로 읽어 키를 확인한다
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

    /**
     * 레이아웃 편집 권한을 모두 가진 슈퍼관리자를 만듭니다.
     *
     * @return User 슈퍼관리자
     */
    private function createSuperUser(): User
    {
        return User::factory()->create([
            'is_super' => true,
        ]);
    }

    /**
     * 편집 대상인 활성 관리자 템플릿 행을 만듭니다.
     *
     * @return Template 활성 관리자 템플릿
     */
    private function createActiveAdminTemplate(): Template
    {
        return Template::factory()->create([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);
    }

    /**
     * 레이아웃 편집기 주소가 관리자 화면(admin.blade)과 G7 설정 데이터 블록을 내보내는지 확인한다.
     *
     * @scenario user_state=authenticated_with_perm, ui_mode=edit_mode, target_template=active
     *
     * @effects admin_blade_emits_active_modules_active_plugins_keys_to_window_g7config
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function admin_layout_editor_url_serves_admin_blade(): void
    {
        $this->createActiveAdminTemplate();

        $user = $this->createSuperUser();
        $this->actingAs($user, 'web');

        $response = $this->get('/admin/layout-editor/sirsoft-admin_basic');

        $response->assertStatus(200);
        $this->assertIsArray($this->g7Config($response->getContent()));
    }

    /**
     * G7 설정 데이터 블록에 활성 확장 메타(activeModules / activePlugins) 키가 실리는지 확인한다.
     *
     * @scenario user_state=authenticated_with_perm, ui_mode=edit_mode, target_template=active
     *
     * @effects admin_blade_emits_active_modules_active_plugins_keys_to_window_g7config
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function admin_blade_emits_active_modules_meta_key_in_g7config(): void
    {
        $this->createActiveAdminTemplate();

        $user = $this->createSuperUser();
        $this->actingAs($user, 'web');

        $response = $this->get('/admin/layout-editor/sirsoft-admin_basic');

        $response->assertStatus(200);
        // G7 설정 데이터 블록에 activeModules / activePlugins 키가 포함되어야 함
        $config = $this->g7Config($response->getContent());
        $this->assertArrayHasKey('activeModules', $config);
        $this->assertArrayHasKey('activePlugins', $config);
    }

    /**
     * G7 설정 데이터 블록이 기존 키(modules / plugins / moduleAssets / pluginAssets)를 그대로 싣는지 확인한다.
     *
     * @scenario user_state=authenticated_with_perm, ui_mode=edit_mode, target_template=active
     *
     * @effects g7config_modules_plugins_legacy_keys_remain_unchanged_for_backward_compat
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function admin_blade_preserves_legacy_modules_plugins_keys(): void
    {
        $this->createActiveAdminTemplate();

        $user = $this->createSuperUser();
        $this->actingAs($user, 'web');

        $response = $this->get('/admin/layout-editor/sirsoft-admin_basic');

        $response->assertStatus(200);
        // 기존 키 (modules / plugins / moduleAssets / pluginAssets) 보존
        $config = $this->g7Config($response->getContent());
        foreach (['modules', 'plugins', 'moduleAssets', 'pluginAssets'] as $key) {
            $this->assertArrayHasKey($key, $config);
        }
    }

    /**
     * 응답 HTML 의 G7 설정 데이터 블록을 배열로 읽습니다.
     *
     * @param  string  $html  응답 본문
     * @return array<string, mixed> 설정 키-값
     */
    private function g7Config(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" id="g7-config">(.*?)</script>#s', $html, $m), 'G7 설정 데이터 블록이 없습니다');

        $config = json_decode($m[1], true);
        $this->assertIsArray($config, 'G7 설정 데이터 블록이 JSON 이 아닙니다');

        return $config;
    }
}
