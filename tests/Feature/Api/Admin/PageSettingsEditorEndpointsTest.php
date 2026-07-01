<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 페이지 설정 편집기 엔드포인트 4종 — 가드/응답/403 경계.
 *
 * 대상:
 *  - GET  seo-candidates.json  (SeoCandidateController) — page_type/toggle/vars 후보
 *  - POST seo-og-preview       (SeoOgPreviewController) — og/twitter cascade + 필터 diff
 *  - POST seo-bot-preview      (SeoBotPreviewController) — dirty 레이아웃 봇 HTML
 *  - GET  broadcast-catalog.json (BroadcastCatalogController) — 채널/이벤트 카탈로그
 *
 * 모두 `core.templates.layouts.edit` 가드(편집 권한자만, Bearer fetch). 후보 미존재 시
 * 빈 목록 디그레이드(편집기 자유 텍스트 폴백). 백엔드 정합의 SSoT.
 */
class PageSettingsEditorEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $editPermission = Permission::firstOrCreate([
            'identifier' => 'core.templates.layouts.edit',
        ], [
            'name' => '레이아웃 편집',
            'display_name' => '레이아웃 편집',
            'type' => 'admin',
        ]);

        $role = Role::firstOrCreate(['identifier' => 'super-admin'], [
            'name' => 'Super Admin',
            'display_name' => 'Super Admin',
            'is_default' => false,
        ]);
        $role->permissions()->syncWithoutDetaching([$editPermission->id]);

        $admin = User::factory()->create();
        $admin->roles()->syncWithoutDetaching([$role->id]);
        $this->adminToken = $admin->createToken('admin')->plainTextToken;
    }

    private function authHeaders(): array
    {
        return [
            'Authorization' => "Bearer {$this->adminToken}",
            'Accept' => 'application/json',
        ];
    }

    private function noPermToken(): string
    {
        return User::factory()->create()->createToken('noperm')->plainTextToken;
    }

    // ── SEO 후보 ──

    public function test_seo_candidates_returns_200_with_candidate_shape(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/admin/templates/sirsoft-basic/editor/seo-candidates.json?page_type=post');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => ['identifier', 'page_types', 'toggle_settings', 'vars', 'extensions'],
        ]);
        $this->assertIsArray($response->json('data.page_types'));
        $this->assertIsArray($response->json('data.toggle_settings'));
        $this->assertIsArray($response->json('data.vars'));
        $this->assertIsArray($response->json('data.extensions'));
    }

    public function test_seo_candidates_extensions_carry_type_id_and_label(): void
    {
        // 확장 SEO 연동 칩(`g7le-seo-extensions`)의 활성 모듈/플러그인 후보 shape.
        // 각 항목은 {type, id, label} — label 은 확장 getName() 로케일 해석값(폴백=id).
        // (RefreshDatabase 환경엔 활성 확장이 0건일 수 있으므로 빈 배열 허용 — shape 만 보증.
        //  requiredExtensions 는 마이그레이션 경로만 등록하지 활성화하지 않는다.)
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/admin/templates/sirsoft-basic/editor/seo-candidates.json');

        $response->assertStatus(200);
        $extensions = $response->json('data.extensions');
        $this->assertIsArray($extensions);
        foreach ($extensions as $ext) {
            $this->assertArrayHasKey('type', $ext);
            $this->assertContains($ext['type'], ['module', 'plugin']);
            $this->assertArrayHasKey('id', $ext);
            $this->assertIsString($ext['id']);
            $this->assertArrayHasKey('label', $ext);
            $this->assertIsString($ext['label']);
            $this->assertNotSame('', $ext['label'], 'label 은 폴백(id)이라도 비어선 안 된다');
        }
    }

    public function test_seo_candidates_requires_edit_permission(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->noPermToken(),
            'Accept' => 'application/json',
        ])->getJson('/api/admin/templates/sirsoft-basic/editor/seo-candidates.json');

        $response->assertStatus(403);
    }

    // ── OG 미리보기 ──

    public function test_seo_og_preview_computes_cascade_and_missing(): void
    {
        // extensions/page_type 둘 다 비면 defaultsAvailable=false + missing 표기.
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/admin/templates/sirsoft-basic/editor/seo-og-preview', [
                'seo' => ['enabled' => true, 'og' => ['title' => '테스트 제목']],
                'seed_context' => [],
                'route_params' => [],
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['defaultsAvailable', 'missing', 'og', 'twitter', 'structured'],
        ]);
        $this->assertFalse($response->json('data.defaultsAvailable'));
        $this->assertContains('extensions', $response->json('data.missing'));
        $this->assertContains('page_type', $response->json('data.missing'));
        // og 키별 cascade 항목 shape.
        $ogKeys = array_column($response->json('data.og'), 'key');
        $this->assertContains('title', $ogKeys);
        foreach ($response->json('data.og') as $row) {
            $this->assertArrayHasKey('source', $row);
            $this->assertArrayHasKey('overriddenByLayout', $row);
            $this->assertArrayHasKey('lockedByFilter', $row);
        }
    }

    public function test_seo_og_preview_layout_override_source(): void
    {
        // 레이아웃이 og.title 을 직접 선언 → 그 키 source=layout, overriddenByLayout=true.
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/admin/templates/sirsoft-basic/editor/seo-og-preview', [
                'seo' => ['enabled' => true, 'og' => ['title' => '레이아웃 제목']],
            ]);

        $response->assertStatus(200);
        $titleRow = collect($response->json('data.og'))->firstWhere('key', 'title');
        $this->assertNotNull($titleRow);
        $this->assertTrue($titleRow['overriddenByLayout']);
        $this->assertSame('layout', $titleRow['source']);
    }

    public function test_seo_og_preview_requires_edit_permission(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->noPermToken(),
            'Accept' => 'application/json',
        ])->postJson('/api/admin/templates/sirsoft-basic/editor/seo-og-preview', ['seo' => []]);

        $response->assertStatus(403);
    }

    public function test_seo_og_preview_validates_required_seo(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/admin/templates/sirsoft-basic/editor/seo-og-preview', []);

        $response->assertStatus(422);
    }

    // ── 봇 HTML 미리보기 ──

    public function test_seo_bot_preview_disabled_returns_null_html(): void
    {
        // meta.seo.enabled=false → renderFromResolved null → enabled=false.
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/admin/templates/sirsoft-basic/editor/seo-bot-preview', [
                'layout' => ['meta' => ['seo' => ['enabled' => false]], 'components' => []],
                'url' => '/test',
                'locale' => 'ko',
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['identifier', 'enabled', 'html']]);
        $this->assertFalse($response->json('data.enabled'));
        $this->assertNull($response->json('data.html'));
    }

    public function test_seo_bot_preview_enabled_returns_html(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/admin/templates/sirsoft-basic/editor/seo-bot-preview', [
                'layout' => [
                    'layout_name' => 'preview/test',
                    'meta' => ['seo' => ['enabled' => true, 'title' => '봇 미리보기']],
                    'components' => [],
                ],
                'url' => '/preview/test',
                'locale' => 'ko',
                'seed_context' => [],
            ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.enabled'));
        $html = $response->json('data.html');
        $this->assertIsString($html);
        // 봇 미리보기는 SEO 설정 산출물만 — head 안 SEO 메타(og/twitter)·title 태그는 포함.
        $this->assertStringContainsString('<head>', $html);
        $this->assertStringContainsString('<title>', $html);
        $this->assertStringContainsString('property="og:', $html);
        // body 컴포넌트 마크업·CSS·시스템 메타는 SEO 산출물이 아니므로 미포함.
        $this->assertStringNotContainsString('<body', $html);
        $this->assertStringNotContainsString('stylesheet', $html);
        $this->assertStringNotContainsString('charset', $html);
    }

    public function test_seo_bot_preview_validates_required_layout(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/admin/templates/sirsoft-basic/editor/seo-bot-preview', []);

        $response->assertStatus(422);
    }

    public function test_seo_bot_preview_requires_edit_permission(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->noPermToken(),
            'Accept' => 'application/json',
        ])->postJson('/api/admin/templates/sirsoft-basic/editor/seo-bot-preview', [
            'layout' => ['meta' => ['seo' => ['enabled' => false]]],
        ]);

        $response->assertStatus(403);
    }

    // ── 웹소켓 카탈로그 ──

    public function test_broadcast_catalog_returns_channels_with_core(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/admin/templates/sirsoft-basic/editor/broadcast-catalog.json');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['identifier', 'channels', 'events']]);
        $channelNames = array_column($response->json('data.channels'), 'name');
        $this->assertContains('core.admin.dashboard', $channelNames, '코어 채널이 카탈로그에 포함되어야 한다');
        // 각 채널에 출처(source.kind) 부착.
        foreach ($response->json('data.channels') as $ch) {
            $this->assertArrayHasKey('source', $ch);
            $this->assertArrayHasKey('kind', $ch['source']);
        }
        // 이벤트는 동적 발행이라 빈 목록(자유 텍스트 폴백).
        $this->assertSame([], $response->json('data.events'));
    }

    public function test_broadcast_catalog_requires_edit_permission(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->noPermToken(),
            'Accept' => 'application/json',
        ])->getJson('/api/admin/templates/sirsoft-basic/editor/broadcast-catalog.json');

        $response->assertStatus(403);
    }
}
