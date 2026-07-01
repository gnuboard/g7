<?php

namespace Tests\Feature\Api\Admin;

use App\Http\Controllers\Api\Admin\AdminTemplateAssetController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AdminTemplateAssetController 회귀 테스트
 *
 * 회귀 원인: 본 컨트롤러가 `$this->logApiUsage(...)` 를 호출했으나
 * `AdminBaseController` 에는 그 메서드가 정의되어 있지 않아 500 에러 발생.
 * 또한 `getEditorAssets` 가 `public/manifest.json` 위치를 가정했지만 실제
 * 빌드 결과물은 `dist/js/components.iife.js` 에 위치해 manifest_present=false
 * 로 떨어졌다. 본 테스트는 두 결함을 함께 가드.
 */
class AdminTemplateAssetControllerTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
        'modules/sirsoft-ecommerce',
    ];

    private User $adminUser;

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

        $this->adminUser = User::factory()->create();
        $this->adminUser->roles()->syncWithoutDetaching([$role->id]);

        $this->adminToken = $this->adminUser->createToken('admin')->plainTextToken;
    }

    public function test_get_editor_assets_returns_200_without_fatal_error(): void
    {
        // sirsoft-basic 템플릿이 활성 디렉토리 또는 _bundled 디렉토리에 존재해야 한다
        // (본 저장소에는 _bundled/sirsoft-basic 이 들어 있으므로 항상 통과)
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->adminToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/admin/templates/sirsoft-basic/editor-assets');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => ['identifier', 'js', 'css', 'manifest_present'],
        ]);

        // 빌드된 IIFE 파일이 _bundled 또는 활성 디렉토리에 존재하면 manifest_present=true
        // 빌드되지 않았으면 false (빌드 누락 케이스도 200 으로 흘러야 함)
        $data = $response->json('data');
        $this->assertIsArray($data['js']);
        $this->assertIsArray($data['css']);
        $this->assertIsBool($data['manifest_present']);

        // 빌드되어 있다면 js 배열에 components.iife.js URL 이 1 개 이상 들어 있어야 함
        if ($data['manifest_present']) {
            $this->assertNotEmpty($data['js']);
            $this->assertStringContainsString('components.iife.js', $data['js'][0]);
        }
    }

    public function test_serve_components_returns_200_without_fatal_error(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->adminToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/admin/templates/sirsoft-basic/editor/components.json');

        $response->assertStatus(200);
        $response->assertJsonStructure(['success', 'message', 'data']);
    }

    public function test_serve_routes_returns_200_without_fatal_error(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->adminToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/admin/templates/sirsoft-basic/editor/routes.json');

        $response->assertStatus(200);
        $response->assertJsonStructure(['success', 'message', 'data']);
    }

    /**
     * 편집기 routes 응답의 각 라우트에 source 태깅(`{kind, identifier}`)이 포함된다.
     *
     * 회귀: 편집기 진입 routes fetch 를 가드 엔드포인트로 전환할 때,
     * 가드 컨트롤러가 raw routes.json 을 그대로 반환해 source 태깅이 빠졌다. 편집기
     * `buildRouteTree`(useRouteTree)는 `route.source.kind` 로 그룹핑하므로, source 가
     * 없으면 클라이언트가 `undefined.kind` 접근에서 throw → 라우트 트리 전체가 network
     * 에러로 무너졌다(유효한 토큰으로도 편집기 진입 불가). public getRoutes 와 동일하게
     * 모든 라우트에 source 를 태깅해야 한다.
     */
    public function test_serve_routes_tags_every_route_with_source(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->adminToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/admin/templates/sirsoft-basic/editor/routes.json');

        $response->assertStatus(200);
        $routes = $response->json('data.routes');
        $this->assertIsArray($routes);
        $this->assertNotEmpty($routes, '편집기 routes 응답에 라우트가 1개 이상 있어야 한다');

        foreach ($routes as $route) {
            $this->assertArrayHasKey('source', $route, "라우트({$route['path']})에 source 태깅 누락");
            $this->assertIsArray($route['source']);
            $this->assertArrayHasKey('kind', $route['source']);
            $this->assertContains(
                $route['source']['kind'],
                ['template', 'module', 'plugin', 'core'],
                "라우트({$route['path']}) source.kind 가 허용 값이 아님"
            );
        }
    }

    /**
     * 편집기 routes 응답이 base 레이아웃 + 인라인 모달 목록을 함께 반환한다.
     *
     * 위지윅 편집기 라우트 트리의 `[공통 레이아웃]` ·
     * `[모달]` 그룹은 base/modal 정보가 필요하나, 종전 routes 응답은 `{version, routes}`
     * 만 반환해 두 그룹이 영구히 미표시였다(클라이언트가 `baseLayouts:[], modals:[]` 하드
     * 코딩으로 디그레이드). 백엔드가 레이아웃 파일에서 `meta.is_base` base 레이아웃과
     * 인라인 `modals[]` 를 수집해 응답에 실어야 두 그룹이 트리에 표시된다.
     */
    public function test_serve_routes_includes_base_layouts_and_modals(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->adminToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/admin/templates/sirsoft-basic/editor/routes.json');

        $response->assertStatus(200);

        // base_layouts / modals 키가 응답에 존재하고 배열이어야 한다.
        $response->assertJsonStructure([
            'data' => ['version', 'routes', 'base_layouts', 'modals'],
        ]);

        $baseLayouts = $response->json('data.base_layouts');
        $modals = $response->json('data.modals');
        $this->assertIsArray($baseLayouts);
        $this->assertIsArray($modals);

        // sirsoft-basic 은 `_user_base`(meta.is_base) 를 가진다 — base 레이아웃 1개 이상.
        $this->assertNotEmpty($baseLayouts, 'base_layouts 에 base 레이아웃이 1개 이상 있어야 한다');
        $baseNames = array_column($baseLayouts, 'layout_name');
        $this->assertContains('_user_base', $baseNames, '`_user_base` 가 base_layouts 에 포함돼야 한다');
        foreach ($baseLayouts as $base) {
            $this->assertArrayHasKey('layout_name', $base);
            $this->assertArrayHasKey('label', $base);
        }

        // sirsoft-basic 의 모달은 전부 partial 참조(`{"partial": "..."}`)이며
        // 라우트 레이아웃은 하위 디렉토리(`auth/`, `shop/` 등)에 있다. 종전 수집 로직은
        // (1) partial 참조 모달을 건너뛰고 (2) 최상위 디렉토리만 읽어 모달을 전부 누락했다.
        // 이제 partial 을 해석하고 재귀 순회하므로 모달이 1건 이상 수집돼야 한다.
        $this->assertNotEmpty($modals, 'partial 참조 모달도 수집돼 `[모달]` 그룹이 표시돼야 한다');

        // 모달 항목 구조 검증.
        foreach ($modals as $modal) {
            $this->assertArrayHasKey('modal_id', $modal);
            $this->assertArrayHasKey('host_layout', $modal);
            $this->assertArrayHasKey('label', $modal);
            $this->assertIsString($modal['modal_id']);
            $this->assertIsString($modal['host_layout']);
            $this->assertNotSame('', $modal['modal_id'], 'partial 해석 후 modal_id 가 실제 값이어야 한다');
        }

        // base 호스트(`_user_base`)의 partial 모달이 host_layout 매칭으로 수집됐는지 확인.
        $hostLayouts = array_column($modals, 'host_layout');
        $this->assertContains('_user_base', $hostLayouts, 'base 호스트의 partial 모달이 수집돼야 한다');

        // 하위 디렉토리 라우트(`auth/register`)의 partial 모달도 재귀 순회로 수집됐는지 확인.
        $this->assertContains('auth/register', $hostLayouts, '하위 디렉토리 라우트의 모달이 수집돼야 한다');
    }

    /**
     * 편집기 `[모달]` 그룹은 활성 모듈/플러그인 레이아웃의 모달도 수집해야 한다.
     *
     * 회귀: `collectEditorBaseAndModals()` 가 `templates/{id}/layouts` 만 스캔하고
     * `modules/{id}/resources/layouts` · `plugins/{id}/resources/layouts` 는 누락해,
     * 모듈/플러그인 화면(편집기 트리에 라우트로 노출됨)의 모달이 `[모달]` 그룹에
     * 영구히 미표시였다(예: ecommerce 주문 상세의 비회원 비밀번호 재설정 모달).
     * 계획서 8.4.5("대상 템플릿의 모든 레이아웃의 modals 섹션을 스캔") 위반.
     *
     * host_layout 은 라우트 트리 layout 규약(`{moduleId}.admin/...`)과 일치해야
     * 트리에서 호스트 화면 노드와 매칭된다.
     */
    public function test_serve_routes_includes_module_layout_modals(): void
    {
        // 모달 수집은 `getActiveModules()`(modules 테이블 status=active) 기준이므로
        // ecommerce 모듈을 활성 상태로 시드한다. requiredExtensions 는 마이그레이션 경로만
        // 등록하고 active 행을 만들지 않는다.
        \App\Models\Module::query()->updateOrCreate(
            ['identifier' => 'sirsoft-ecommerce'],
            [
                'vendor' => 'sirsoft',
                'name' => 'E-Commerce',
                'version' => '1.0.0',
                'status' => \App\Enums\ExtensionStatus::Active->value,
            ]
        );
        app(\App\Contracts\Repositories\ModuleRepositoryInterface::class); // 바인딩 보장
        \Illuminate\Support\Facades\Cache::flush(); // 활성 식별자 캐시 무효화

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->adminToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/admin/templates/sirsoft-admin_basic/editor/routes.json');

        $response->assertStatus(200);

        $modals = $response->json('data.modals');
        $this->assertIsArray($modals);

        $hostLayouts = array_column($modals, 'host_layout');
        $modalIds = array_column($modals, 'modal_id');

        // ecommerce 주문 상세는 모달 5개를 partial 참조로 선언한다.
        // host_layout 은 모듈 라우트 layout 규약(`{moduleId}.{layout_name}`)을 따른다.
        $this->assertContains(
            'sirsoft-ecommerce.admin_ecommerce_order_detail',
            $hostLayouts,
            '모듈 레이아웃(주문 상세)의 모달이 `[모달]` 그룹에 수집돼야 한다'
        );

        // develop 신규 비회원 비밀번호 재설정 모달이 편집 진입점으로 노출돼야 한다.
        $this->assertContains(
            'modal_reset_guest_password',
            $modalIds,
            '비회원 비밀번호 재설정 모달이 편집기 트리에 노출돼야 한다'
        );

        // 모달 라벨은 modal_id 원문이 아니라 Modal 컴포넌트 props.title 의 친화 제목(대개 $t: 키)
        // 으로 채워져야 한다. G7 모달은 제목을 props.title 에 두므로 최상위 title 만 읽으면
        // 라벨이 비어 트리에 modal_id 원문이 노출되는 회귀를 차단한다.
        $resetGuest = collect($modals)->firstWhere('modal_id', 'modal_reset_guest_password');
        $this->assertNotEmpty(
            $resetGuest['label'] ?? null,
            'props.title 기반 모달 라벨이 채워져야 한다 (modal_id 원문 노출 방지)'
        );
        $this->assertStringStartsWith(
            '$t:',
            $resetGuest['label'],
            '모달 라벨은 props.title 의 다국어 키를 그대로 전달해야 한다'
        );

        // 모든 모달은 친화 라벨을 가져야 한다 — (1) 라벨 미부여(modal_id 원문 노출) 0건,
        // (2) props.title 이 표현식(`{{...}}` 삼항·필터)인 모달은 라벨로 부적합하므로
        //     meta.editor_label 정적 키가 부여돼 표현식 라벨이 트리에 노출되지 않아야 한다.
        $noLabel = collect($modals)->filter(fn ($m) => empty($m['label']))->pluck('modal_id')->all();
        $this->assertSame([], $noLabel, '라벨 미부여 모달이 없어야 한다(트리에 modal_id 원문 노출 방지): '.implode(', ', $noLabel));

        $exprLabel = collect($modals)
            ->filter(fn ($m) => is_string($m['label']) && str_contains($m['label'], '{{'))
            ->pluck('modal_id')->all();
        $this->assertSame([], $exprLabel, '표현식 라벨 모달은 meta.editor_label 정적 키가 부여돼야 한다: '.implode(', ', $exprLabel));
    }

    /**
     * 편집기 routes 응답에 레이아웃별 현재(최신) 저장 버전 맵이 동봉된다.
     *
     * 라우트 트리 버전 배지의 데이터 소스 — 버전 이력이 있는 레이아웃만 최신 버전
     * 번호로 포함되고, 이력이 없는(원본) 레이아웃은 맵에 없어야 한다(배지 미표시).
     */
    public function test_serve_routes_includes_layout_versions_map(): void
    {
        $template = Template::where('identifier', 'sirsoft-basic')->first()
            ?? Template::factory()->create(['identifier' => 'sirsoft-basic']);
        $layout = TemplateLayout::factory()->create([
            'template_id' => $template->id,
            'name' => 'home',
        ]);
        TemplateLayoutVersion::factory()->create(['layout_id' => $layout->id, 'version' => 1]);
        TemplateLayoutVersion::factory()->create(['layout_id' => $layout->id, 'version' => 2]);
        TemplateLayout::factory()->create([
            'template_id' => $template->id,
            'name' => 'never-saved',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->adminToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/admin/templates/sirsoft-basic/editor/routes.json');

        $response->assertStatus(200);
        $versions = $response->json('data.layout_versions');
        $this->assertIsArray($versions);
        $this->assertSame(2, $versions['home'] ?? null, '이력 보유 레이아웃은 최신 버전 번호로 표시');
        $this->assertArrayNotHasKey('never-saved', $versions, '이력 없는 레이아웃은 맵 미포함(배지 미표시)');
    }

    /**
     * 편집기 routes 엔드포인트는 비로그인 시 401 로 진입을 차단한다.
     *
     * 종전엔 편집기가 공개 routes.json 을 호출해 세션 만료 상태에서도
     * 200 을 받아 편집기 접근이 허용됐다. 가드 엔드포인트는 auth:sanctum 으로 차단.
     */
    public function test_serve_routes_requires_authentication(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->getJson('/api/admin/templates/sirsoft-basic/editor/routes.json');

        $response->assertStatus(401);
    }

    public function test_serve_editor_spec_returns_200_without_fatal_error(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->adminToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/admin/templates/sirsoft-basic/editor/editor-spec.json');

        $response->assertStatus(200);
        $response->assertJsonStructure(['success', 'message', 'data' => ['identifier', 'spec']]);
    }

    public function test_serve_language_returns_200_without_fatal_error(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->adminToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/admin/templates/sirsoft-basic/editor/lang/ko.json');

        $response->assertStatus(200);
        $response->assertJsonStructure(['success', 'message', 'data']);
    }

    /**
     * 표시 권한 후보 엔드포인트 — 코어 + 활성 확장 권한을 {key, name} 으로 반환.
     *
     * 속성 모달 표시 권한 TagInput 후보 주입. 종전 G7Config 상시 노출에서
     * 편집 권한 가드된 편집기 전용 엔드포인트로 전환(노출 범위 한정).
     */
    public function test_serve_permission_candidates_returns_key_name_list(): void
    {
        Permission::firstOrCreate([
            'identifier' => 'core.users.read',
        ], [
            'name' => ['ko' => '사용자 조회', 'en' => 'View Users'],
            'type' => 'admin',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->adminToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/admin/templates/sirsoft-basic/editor/permission-candidates.json');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => ['identifier', 'permissions' => [['key', 'name']]],
        ]);

        $keys = array_column($response->json('data.permissions'), 'key');
        $this->assertContains('core.users.read', $keys, '표시 권한 후보에 코어 권한 키가 포함되어야 한다');
        // 모든 항목이 {key, name} 형태이며 key 가 비어있지 않다
        foreach ($response->json('data.permissions') as $row) {
            $this->assertArrayHasKey('key', $row);
            $this->assertArrayHasKey('name', $row);
            $this->assertNotSame('', $row['key']);
        }
    }

    /**
     * 표시 권한 후보 엔드포인트는 편집 권한(core.templates.layouts.edit) 가드 하에만 접근 가능.
     * 권한 없는 사용자는 403 — 권한 카탈로그 노출이 편집 권한자에게만 한정됨을 보장.
     */
    public function test_serve_permission_candidates_requires_edit_permission(): void
    {
        $noPermUser = User::factory()->create();
        $token = $noPermUser->createToken('noperm')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/admin/templates/sirsoft-basic/editor/permission-candidates.json');

        $response->assertStatus(403);
    }

    /**
     * 편집기 프리뷰 CSS 엔드포인트 — text/css 200 + 다크 셀렉터 격리 치환.
     *
     * sirsoft-basic 의 darkMode.previewIsolation 선언(.dark → .g7le-preview-dark)에 따라,
     * 빌드 CSS 의 다크 조상 셀렉터(`.dark .foo`)가 프리뷰 마커로 치환돼야 한다. 동시에
     * 유틸리티 클래스(`.dark\:bg-x` — 이스케이프 콜론)는 보존돼야 한다(사용자 토큰 무손실).
     */
    public function test_serve_editor_css_isolates_dark_ancestor_selector(): void
    {
        $cssPath = base_path('templates/_bundled/sirsoft-basic/dist/css/components.css');
        if (! file_exists($cssPath)) {
            $this->markTestSkipped('sirsoft-basic dist CSS 미빌드 — 빌드 후 검증');
        }

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->adminToken}",
            'Accept' => 'text/css',
        ])->get('/api/admin/templates/sirsoft-basic/editor/components.css');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/css', (string) $response->headers->get('Content-Type'));

        $css = $response->getContent();
        // 다크 조상 셀렉터가 프리뷰 마커로 치환됨 — 원본 빌드에 `.dark ` 가 있었다면.
        $original = (string) file_get_contents($cssPath);
        if (preg_match('/\.dark(?=[\s,){>~+])/', $original)) {
            $this->assertStringContainsString('.g7le-preview-dark', $css, '다크 조상 셀렉터가 프리뷰 마커로 치환되어야 한다');
            // 원본 `.dark ` 조상 셀렉터(공백/조합자 경계)는 더 이상 남지 않아야 한다.
            $this->assertSame(0, preg_match('/\.dark(?=[\s,){>~+])/', $css), '치환 후 원본 다크 조상 셀렉터가 남으면 안 된다');
        }
        // 유틸리티 클래스(.dark\:...)는 보존 — 이스케이프 콜론은 치환 대상 아님.
        if (str_contains($original, '.dark\\:')) {
            $this->assertStringContainsString('.dark\\:', $css, '다크 유틸리티 클래스(.dark\\:)는 보존되어야 한다');
        }

        // @layer 평탄화 — 편집기 CSS 는 어드민 호스트 CSS 와의 cross-build 레이어 우선순위
        // 충돌을 피하려 unlayered 로 평탄화돼야 한다(결함). 원본에 @layer {} 블록이
        // 있었다면 서빙된 CSS 에는 블록 오프너가 남지 않아야 한다.
        if (preg_match('/@layer\s+[^;{]*\{/', $original)) {
            $this->assertSame(0, preg_match('/@layer\s+[^;{]*\{/', $css), '서빙 CSS 에 @layer 블록 래퍼가 남으면 cross-build 우선순위 충돌');
        }
    }

    /**
     * 편집기 CSS 엔드포인트는 편집 권한 가드 하에만 접근 가능 (403 경계).
     */
    public function test_serve_editor_css_requires_edit_permission(): void
    {
        $noPermUser = User::factory()->create();
        $token = $noPermUser->createToken('noperm')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'text/css',
        ])->get('/api/admin/templates/sirsoft-basic/editor/components.css');

        $response->assertStatus(403);
    }

    /**
     * editor-assets 매니페스트의 css URL 은 편집기 전용 CSS 엔드포인트를 가리켜야 한다
     * 일반 자산 서빙(public)이 아님.
     */
    public function test_editor_assets_css_points_to_editor_endpoint(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->adminToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/admin/templates/sirsoft-basic/editor-assets');

        $response->assertStatus(200);
        $css = $response->json('data.css');
        if (! empty($css)) {
            $this->assertStringContainsString('/editor/components.css', $css[0], 'CSS URL 은 편집기 전용 엔드포인트여야 한다');
        }
    }

    /**
     * private 메서드를 reflection 으로 호출하는 헬퍼 (순수 CSS 변환 로직 단위 검증용).
     *
     * @param  string  $method  메서드명
     * @param  array<int, mixed>  $args  인자
     * @return mixed 반환값
     */
    private function invokePrivate(string $method, array $args): mixed
    {
        $ctrl = app(AdminTemplateAssetController::class);
        $ref = new \ReflectionMethod($ctrl, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($ctrl, $args);
    }

    /**
     * flattenCssLayers — `@layer NAME { ... }` 블록 래퍼를 제거해 내부 규칙을 unlayered 로
     * 평탄화한다. cross-build 레이어 우선순위 충돌 방지의 핵심 로직(결함). 중첩 레이어·
     * 짝 불일치 폴백·@layer 선언만(블록 없음) 보존을 함께 가드.
     */
    public function test_flatten_css_layers_unwraps_blocks_preserves_rules(): void
    {
        $css = '@layer base{a{color:red}}@layer utilities{.x{top:0}.y{left:0}}';
        $out = $this->invokePrivate('flattenCssLayers', [$css]);

        $this->assertSame(0, preg_match('/@layer\s+[^;{]*\{/', $out), '@layer 블록 래퍼가 모두 제거되어야 한다');
        $this->assertStringContainsString('a{color:red}', $out, '내부 규칙은 보존');
        $this->assertStringContainsString('.x{top:0}', $out);
        $this->assertStringContainsString('.y{left:0}', $out);
    }

    public function test_flatten_css_layers_handles_nested_layers(): void
    {
        $css = '@layer outer{.a{top:0}@layer inner{.b{left:0}}.c{right:0}}';
        $out = $this->invokePrivate('flattenCssLayers', [$css]);

        $this->assertSame(0, preg_match('/@layer\s+[^;{]*\{/', $out), '중첩 @layer 도 전부 평탄화');
        $this->assertStringContainsString('.a{top:0}', $out);
        $this->assertStringContainsString('.b{left:0}', $out);
        $this->assertStringContainsString('.c{right:0}', $out);
    }

    public function test_flatten_css_layers_preserves_bare_layer_declaration(): void
    {
        // 블록 없는 선언(`@layer a, b;`)은 레이어 순서 선언이므로 그대로 보존.
        $css = '@layer base, utilities;@layer base{.a{top:0}}';
        $out = $this->invokePrivate('flattenCssLayers', [$css]);

        $this->assertStringContainsString('@layer base, utilities;', $out, '블록 없는 @layer 선언은 보존');
        $this->assertStringContainsString('.a{top:0}', $out);
        $this->assertSame(0, preg_match('/@layer\s+[^;{]*\{/', $out), '블록 래퍼만 제거');
    }

    public function test_flatten_css_layers_unbalanced_brace_falls_back_safely(): void
    {
        // 짝 불일치(`}` 부족) — 평탄화 포기하고 원문 보존(프리뷰가 깨지지 않도록 안전 폴백).
        $css = '@layer base{.a{top:0}'; // 닫는 중괄호 1개 부족
        $out = $this->invokePrivate('flattenCssLayers', [$css]);

        $this->assertStringContainsString('.a{top:0}', $out, '폴백 시 내용 보존');
    }

    public function test_flatten_css_layers_noop_when_no_layers(): void
    {
        // @layer 비사용 CSS(예 Bootstrap)는 변형 없이 그대로(no-op).
        $css = '.btn{color:blue}[data-bs-theme=dark] .btn{color:white}';
        $out = $this->invokePrivate('flattenCssLayers', [$css]);

        $this->assertSame($css, $out, '@layer 가 없으면 변형하지 않는다');
    }

    /**
     * rewriteDarkSelectors — 다크 조상 셀렉터(`.dark` 후손 결합자/그룹 경계 앞)만 치환하고,
     * 유틸리티 클래스(`.dark\:bg-x` — 이스케이프 콜론)는 보존(라이브러리 중립 치환).
     */
    public function test_rewrite_dark_selectors_only_ancestor_not_utility(): void
    {
        $css = '.dark .foo{color:red}.dark\\:bg-x{background:blue}:where(.dark .dark\\:bg-y){top:0}';
        $out = $this->invokePrivate('rewriteDarkSelectors', [$css, '.dark', '.g7le-preview-dark']);

        // 조상 셀렉터(.dark 공백/그룹경계 앞)는 치환
        $this->assertStringContainsString('.g7le-preview-dark .foo{', $out);
        $this->assertStringContainsString(':where(.g7le-preview-dark .dark\\:bg-y)', $out);
        // 유틸리티 클래스(.dark\:)는 보존
        $this->assertStringContainsString('.dark\\:bg-x{background:blue}', $out);
        // 치환 후 조상 .dark 잔존 없음
        $this->assertSame(0, preg_match('/\.dark(?=[\s,){>~+])/', $out));
    }

    /**
     * darkMode.previewIsolation.flattenLayers 미선언 시 평탄화 옵트아웃 — 라이브러리 중립.
     *
     * resolveDarkPreviewIsolation 가 `flattenLayers` 를 반환하고, false 면 serveEditorCss 가
     * flattenCssLayers 를 호출하지 않아 @layer 가 보존됨을 검증. 디스크 fixture 대신 isolation
     * 분기 로직을 직접 호출해 가드(라이브러리 중립 핵심 분기).
     */
    public function test_resolve_dark_preview_isolation_includes_flatten_flag(): void
    {
        // sirsoft-basic 은 editor-spec 에 flattenLayers:true 선언 — 반환값에 flag=true.
        $iso = $this->invokePrivate('resolveDarkPreviewIsolation', ['sirsoft-basic']);

        $this->assertIsArray($iso);
        $this->assertArrayHasKey('flattenLayers', $iso, '반환값에 flattenLayers 키 포함');
        $this->assertTrue($iso['flattenLayers'], 'sirsoft-basic 은 flattenLayers:true 옵트인');
        $this->assertSame('.dark', $iso['rewrite']);
        $this->assertSame('.g7le-preview-dark', $iso['replace']);
    }
}
