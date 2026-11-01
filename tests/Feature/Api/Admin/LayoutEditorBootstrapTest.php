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
 * - G7Config(#g7-config 데이터 블록)의 activeModules / activePlugins 키가 페이지에 노출됨
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

    /**
     * 최고관리자 회원을 만듭니다.
     *
     * @return User 최고관리자
     */
    private function createSuperUser(): User
    {
        return User::factory()->create([
            'is_super' => true,
        ]);
    }

    /**
     * 활성 관리자 템플릿 행을 만듭니다.
     *
     * @return Template 관리자 템플릿
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
     * 편집기 주소가 관리자 셸을 내고, 셸이 G7Config 데이터 블록을 싣습니다.
     *
     * @scenario user_state=authenticated_with_perm, target_template=active
     *
     * @effects check_layout_editor_mode_recognizes_admin_layout_editor_url_pattern
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
        // 셸 설정은 비실행 JSON 데이터 블록(#g7-config)으로 실린다 — window.G7Config 대입은 정적 부트 스크립트가 한다(strict CSP)
        $this->assertIsArray($this->g7Config($response->getContent()));
    }

    /**
     * 관리자 셸의 G7Config 에 활성 확장 키(activeModules·activePlugins)가 있습니다.
     *
     * @scenario user_state=authenticated_with_perm, target_template=active
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
        // G7Config 에 activeModules / activePlugins 키가 포함되어야 함
        $config = $this->g7Config($response->getContent());
        $this->assertArrayHasKey('activeModules', $config);
        $this->assertArrayHasKey('activePlugins', $config);
    }

    /**
     * 관리자 셸의 G7Config 에 옛 키(modules·plugins·moduleAssets·pluginAssets)가 그대로 있습니다.
     *
     * @scenario user_state=authenticated_with_perm, target_template=active
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
     * 셸 HTML 의 G7Config 데이터 블록을 풀어 냅니다.
     *
     * @param  string|false  $html  셸 HTML
     * @return array<string, mixed> 풀어 낸 셸 설정
     */
    private function g7Config(string|false $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" id="g7-config">(.*?)</script>#s', (string) $html, $match), '#g7-config 블록이 없다');
        $config = json_decode($match[1], true);
        $this->assertIsArray($config, '#g7-config 블록이 JSON 으로 풀리지 않는다');

        return $config;
    }
}
