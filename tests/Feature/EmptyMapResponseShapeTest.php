<?php

namespace Tests\Feature;

use App\Enums\ExtensionOwnerType;
use App\Enums\ExtensionStatus;
use App\Extension\Cache\CoreCacheDriver;
use App\Extension\TemplateManager;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\User;
use App\Services\Analytics\ReportService;
use App\Services\ExtensionStaticCacheService;
use App\Services\TemplateService;
use App\Traits\FiltersFrontendSchema;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 레이아웃 밖 키-값 응답의 빈 맵 모양 테스트 (#758 Q4)
 *
 * 화면이 이름표로 값을 꺼내는 맵이 비면 `[]` 가 아니라 `{}` 로 나가야 한다 — `[]` 면 화면의 `?? {}` 기본값이
 * 동작하지 않고, API 문서가 약속한 모양과도 어긋난다.
 *
 * - N1: `window.G7Config` 의 settings·plugins·modules·appConfig, 노출 카테고리 값
 * - N2: 템플릿 설정(template.json) 서빙의 `dependencies.modules`
 * - N3: 환경설정 `_meta.env_locked`, 분석 리포트 행 `extra`, 첨부파일 `meta`
 *
 * @see tests/scenarios/empty-map-response-shape.yaml
 */
class EmptyMapResponseShapeTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 레이아웃·템플릿 요청 경로가 GDPR 미들웨어를 거치므로 그 마이그레이션을 포함한다.
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    /**
     * 사용자·관리자 셸이 비어 있는 설정 맵을 `{}` 로 심는다.
     *
     * @scenario surface=g7config
     *
     * @effects g7config_empty_maps_render_as_objects
     *
     * @return void 반환값 없음
     */
    public function test_g7config_empty_maps_render_as_objects(): void
    {
        foreach (['app', 'admin'] as $shell) {
            $html = view()->file(resource_path("views/{$shell}.blade.php"), [
                // G7Config 블록은 활성 템플릿이 있을 때만 렌더된다
                'activeUserTemplate' => 'sirsoft-basic',
                'activeAdminTemplate' => 'sirsoft-admin_basic',
                'extensionCacheVersion' => 0,
                'frontendSettings' => [],
                'pluginSettings' => [],
                'moduleSettings' => [],
                'appConfig' => [],
            ])->render();

            foreach (['settings', 'plugins', 'modules', 'appConfig'] as $key) {
                $this->assertMatchesRegularExpression('/\b'.$key.': \{\},/', $html, "{$shell}: {$key}");
            }
        }
    }

    /**
     * 필드 목록 없이 통째로 노출하는 카테고리가 비어 있으면 `{}` 로 담는다.
     *
     * @scenario surface=g7config
     *
     * @effects g7config_empty_maps_render_as_objects
     *
     * @return void 반환값 없음
     */
    public function test_exposed_empty_category_is_an_object(): void
    {
        $filter = new class
        {
            use FiltersFrontendSchema;

            /**
             * 보호 메서드를 시험에서 부릅니다.
             *
             * @param  array<string, mixed>  $settings  카테고리별 설정 값
             * @param  array<string, mixed>  $schema  프론트엔드 노출 스키마
             * @return array<string, mixed> 노출 대상만 남긴 설정
             */
            public function run(array $settings, array $schema): array
            {
                return $this->filterByFrontendSchema($settings, $schema);
            }
        };

        $filtered = $filter->run(
            ['display' => [], 'basic' => ['a' => 1]],
            ['display' => ['expose' => true], 'basic' => ['expose' => true]]
        );

        $this->assertSame('{"display":{},"basic":{"a":1}}', json_encode($filtered));
    }

    /**
     * 템플릿 설정 서빙이 template.json 의 빈 맵(`dependencies.modules: {}`)을 `{}` 로 낸다.
     *
     * @scenario surface=template_config
     *
     * @effects template_config_keeps_empty_maps
     *
     * @return void 반환값 없음
     */
    public function test_template_config_keeps_empty_maps(): void
    {
        $raw = (string) file_get_contents(base_path('templates/sirsoft-admin_basic/template.json'));
        $this->assertStringContainsString('"modules": {}', $raw, '전제: 원본 template.json 이 빈 맵을 담고 있다');

        Template::query()->updateOrCreate(
            ['identifier' => 'sirsoft-admin_basic'],
            Template::factory()->make(['identifier' => 'sirsoft-admin_basic', 'type' => 'admin', 'status' => ExtensionStatus::Active->value])->getAttributes(),
        );

        $body = $this->getJson('/api/templates/sirsoft-admin_basic/config.json')->assertOk()->getContent();

        $this->assertStringContainsString('"modules":{}', $body);
    }

    /**
     * 편집기 routes 응답은 routes.json 의 빈 맵을 `{}` 로 내고, PHP 소비자용 기본 조회는 종전처럼 배열이다.
     *
     * @scenario surface=template_config
     *
     * @effects template_config_keeps_empty_maps
     *
     * @return void 반환값 없음
     */
    public function test_editor_routes_keep_empty_maps(): void
    {
        $identifier = 'test-issue758_'.substr(md5(uniqid('', true)), 0, 8);
        $dir = base_path("templates/{$identifier}");
        File::ensureDirectoryExists($dir.'/layouts');
        file_put_contents($dir.'/routes.json', '{"version":"1.0.0","routes":[{"path":"/probe","layout":"probe","meta":{}}]}');

        try {
            $result = app(TemplateService::class)->getEditorRoutesDataWithModules($identifier);

            $this->assertTrue($result['success']);
            $this->assertStringContainsString('"meta":{}', (string) json_encode($result['data']['routes'][0]));
            $this->assertSame('template', $result['data']['routes'][0]['source']['kind'], 'source 태깅은 종전대로');
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * 공개 routes 응답을 만드는 세 경로(요청 시 조립, 템플릿 활성화의 캐시 예열, 정적 게시본)가 모두
     * routes.json 의 빈 맵을 `{}` 로 낸다. 예열·게시가 비보존판을 쓰면 같은 캐시 키·같은 응답이
     * 만료나 다음 게시까지 `[]` 로 남는다.
     *
     * @scenario surface=template_config
     *
     * @effects template_config_keeps_empty_maps
     *
     * @return void 반환값 없음
     */
    public function test_public_routes_keep_empty_maps_on_every_response_path(): void
    {
        $identifier = 'test-issue758_'.substr(md5(uniqid('', true)), 0, 8);
        $dir = base_path("templates/{$identifier}");
        $tmp = storage_path('framework/testing/issue758_'.substr(md5(uniqid('', true)), 0, 8));
        File::ensureDirectoryExists($dir.'/layouts');
        file_put_contents($dir.'/routes.json', '{"version":"1.0.0","routes":[{"path":"/probe","layout":"probe","meta":{}}]}');
        file_put_contents($dir.'/template.json', json_encode([
            'identifier' => $identifier, 'vendor' => 'test', 'name' => ['ko' => '시험', 'en' => 'Probe'], 'version' => '1.0.0', 'type' => 'user',
        ]));
        // routes 병합은 템플릿 관리자 레지스트리에 올라온 템플릿만 다룬다
        app(TemplateManager::class)->loadTemplates();

        try {
            $template = Template::factory()->create([
                'identifier' => $identifier,
                'type' => 'user',
                'status' => ExtensionStatus::Active->value,
            ]);
            TemplateLayout::factory()->create(['template_id' => $template->id, 'name' => 'probe']);

            // 1) 템플릿 활성화의 캐시 예열 — 공개 응답과 같은 캐시 키를 채운다
            $warm = new \ReflectionMethod(TemplateManager::class, 'warmTemplateCache');
            $warm->invoke(app(TemplateManager::class), $identifier);
            $warmed = (new CoreCacheDriver(config('cache.default', 'array')))
                ->get("template.routes.{$identifier}.v".TemplateManager::getExtensionCacheVersion());
            $this->assertNotNull($warmed, '전제: 예열이 routes 캐시를 채웠다');
            $this->assertStringContainsString('"meta":{}', (string) json_encode($warmed), '예열 캐시');

            // 2) 공개 routes 응답
            $body = $this->getJson("/api/templates/{$identifier}/routes.json")->assertOk()->getContent();
            $this->assertStringContainsString('"meta":{}', $body, '공개 routes 응답');

            // 3) 정적 게시본 routes.json
            $files = [];
            $publish = new \ReflectionMethod(ExtensionStaticCacheService::class, 'publishTemplate');
            $publish->invokeArgs(app(ExtensionStaticCacheService::class), [$tmp, $template, [], &$files]);
            $published = (string) file_get_contents($tmp."/templates/{$identifier}/routes.json");
            $this->assertStringContainsString('"meta":{}', $published, '정적 게시본');
        } finally {
            File::deleteDirectory($dir);
            File::deleteDirectory($tmp);
            app(TemplateManager::class)->loadTemplates();
        }
    }

    /**
     * 환경설정 조회의 `_meta.env_locked` 가 잠긴 키가 없을 때 `{}` 다.
     *
     * @scenario surface=settings_meta
     *
     * @effects settings_env_locked_empty_is_object
     *
     * @return void 반환값 없음
     */
    public function test_settings_env_locked_empty_is_object(): void
    {
        $token = $this->createAdminUser(['core.settings.read'])->createToken('settings')->plainTextToken;

        $body = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->getJson('/api/admin/settings')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('"env_locked":{}', $body);
    }

    /**
     * 분석 리포트 행의 부가 값 `extra` 가 비면 `{}` 다.
     *
     * @scenario surface=analytics_row
     *
     * @effects analytics_extra_empty_is_object
     *
     * @return void 반환값 없음
     */
    public function test_analytics_row_extra_empty_is_object(): void
    {
        $present = new \ReflectionMethod(ReportService::class, 'presentRow');
        $row = (object) [
            'label' => '/', 'label_hash' => 'h', 'visits' => 2, 'hits' => 3, 'entries' => 1, 'exits' => 1,
            'bounces' => 0, 'total_time_seconds' => 10,
        ];

        $empty = $present->invoke(app(ReportService::class), $row, 'page', [], []);
        $filled = $present->invoke(app(ReportService::class), $row, 'page', [], ['h' => ['no_result' => 4]]);

        $this->assertSame('{}', json_encode($empty['extra']));
        $this->assertSame('{"no_result":4}', json_encode($filled['extra']));
    }

    /**
     * 첨부파일 `meta` 가 비면 `{}`, 없으면(null) null 이다 — 문서 attachments.md 의 약속.
     *
     * @scenario surface=attachment_meta
     *
     * @effects attachment_meta_empty_is_object
     *
     * @return void 반환값 없음
     */
    public function test_attachment_meta_empty_is_object(): void
    {
        $request = Request::create('/');

        $empty = (new AttachmentResource(Attachment::factory()->make(['meta' => []])))->toArray($request);
        $missing = (new AttachmentResource(Attachment::factory()->make(['meta' => null])))->toArray($request);

        $this->assertSame('{}', json_encode($empty['meta']));
        $this->assertNull($missing['meta']);
    }

    /**
     * 지정 권한을 붙인 관리자 역할을 가진 회원을 생성합니다.
     *
     * @param  array<int, string>  $permissions  부여할 권한 식별자 목록
     * @return User 생성된 관리자 회원
     */
    private function createAdminUser(array $permissions): User
    {
        $user = User::factory()->create();

        $permissionIds = [];
        foreach ($permissions as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                ]
            )->id;
        }

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'is_system' => true,
                'priority' => 0,
            ]
        );
        $adminRole->permissions()->syncWithoutDetaching($permissionIds);
        $user->roles()->syncWithoutDetaching([$adminRole->id]);

        return $user;
    }
}
