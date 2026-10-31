<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Seo\Editor\SeoBotPreviewService;
use App\Seo\TemplateRouteResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

/**
 * 편집기 봇 미리보기가 편집 중인 라우트의 레이아웃 이름으로 모듈·플러그인 식별자를 채우는지 테스트
 *
 * 편집기는 모듈 화면을 미리 볼 때도 `module_id` 를 보내지 않아, 모듈 설정 기반 제목 틀·토글이
 * 빠진 채 운영 봇 응답과 다른 결과를 보여 줬다. 편집기는 이제 라우트의 레이아웃 전체 이름
 * (`layout_name`)을 보내고, 서버가 운영 봇 렌더와 같은 해석기로 식별자를 채운다 (공개#154).
 */
class SeoBotPreviewControllerTest extends TestCase
{
    use DatabaseTransactions;

    private string $adminToken;

    /**
     * 레이아웃 편집 권한을 가진 관리자 토큰을 준비합니다.
     *
     * @return void 반환값 없음
     */
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

    /**
     * 미리보기 서비스를 목으로 바꾸고, render 가 받은 모듈·플러그인 식별자를 기록하게 합니다.
     *
     * @param  array<string, mixed>  $captured  호출 인자를 담을 배열(참조)
     * @return void 반환값 없음
     */
    private function captureServiceCall(array &$captured): void
    {
        $service = Mockery::mock(SeoBotPreviewService::class);
        $service->shouldReceive('render')
            ->once()
            ->andReturnUsing(function (...$args) use (&$captured) {
                $captured = [
                    'moduleId' => $args[5] ?? null,
                    'pluginId' => $args[6] ?? null,
                    'routeTitle' => $args[8] ?? null,
                    'storedLayoutName' => $args[9] ?? null,
                ];

                return '<html><title>메모 목록</title></html>';
            });
        $this->app->instance(SeoBotPreviewService::class, $service);
    }

    /**
     * 미리보기 요청을 보냅니다.
     *
     * @param  array<string, mixed>  $extra  요청 본문에 더할 키
     * @return \Illuminate\Testing\TestResponse 응답
     */
    private function postPreview(array $extra)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$this->adminToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/admin/templates/sirsoft-basic/editor/seo-bot-preview', array_merge([
            'layout' => ['meta' => ['seo' => ['enabled' => true]], 'components' => []],
            'url' => '/memos',
            'locale' => 'ko',
        ], $extra));
    }

    /**
     * layout_name 만 보내면 그 이름의 확장 접두사로 모듈 식별자를 채워 서비스를 부릅니다.
     *
     * @scenario surface=preview_from_layout_name
     *
     * @effects bot_preview_fills_extension_from_layout_name
     *
     * @return void 반환값 없음
     */
    public function test_layout_name_fills_module_identifier(): void
    {
        $captured = [];
        $this->captureServiceCall($captured);

        $this->postPreview(['layout_name' => 'gnuboard7-hello_module.user_memo_list'])
            ->assertStatus(200)
            ->assertJsonPath('data.enabled', true);

        $this->assertSame('gnuboard7-hello_module', $captured['moduleId']);
        $this->assertNull($captured['pluginId']);
    }

    /**
     * 명시한 module_id 가 있으면 layout_name 보다 명시값이 우선합니다.
     *
     * @scenario surface=preview_explicit_wins
     *
     * @effects bot_preview_fills_extension_from_layout_name
     *
     * @return void 반환값 없음
     */
    public function test_explicit_module_id_wins_over_layout_name(): void
    {
        $captured = [];
        $this->captureServiceCall($captured);

        $this->postPreview([
            'layout_name' => 'gnuboard7-hello_module.user_memo_list',
            'module_id' => 'sirsoft-board',
        ])->assertStatus(200);

        $this->assertSame('sirsoft-board', $captured['moduleId']);
    }

    /**
     * 점 없는 템플릿 레이아웃 이름이면 식별자를 채우지 않습니다.
     *
     * @scenario surface=preview_template_layout
     *
     * @effects bot_preview_fills_extension_from_layout_name
     *
     * @return void 반환값 없음
     */
    public function test_template_layout_name_leaves_identifiers_empty(): void
    {
        $captured = [];
        $this->captureServiceCall($captured);

        $this->postPreview(['layout_name' => 'home'])->assertStatus(200);

        $this->assertNull($captured['moduleId']);
        $this->assertNull($captured['pluginId']);
    }

    /**
     * layout_name 으로 찾은 라우트의 meta.title 을 미리보기 렌더에 넘깁니다 — 운영 봇 렌더가 다른 제목 단계가
     * 모두 빌 때 라우트 제목을 쓰는 것과 같게 한다. 사이트 첫 화면(`/`)은 운영과 같이 넘기지 않습니다 (공개#154).
     *
     * @scenario surface=preview_route_title
     *
     * @effects bot_preview_uses_route_meta_title_like_production
     *
     * @return void 반환값 없음
     */
    public function test_layout_name_passes_route_meta_title_to_preview(): void
    {
        $this->mockRouteResolver();

        $captured = [];
        $this->captureServiceCall($captured);
        $this->postPreview(['layout_name' => 'gnuboard7-hello_module.user_memo_list'])->assertStatus(200);
        $this->assertSame('$t:gnuboard7-hello_module.user.memos.index.title', $captured['routeTitle']);
        $this->assertSame('gnuboard7-hello_module.user_memo_list', $captured['storedLayoutName']);
    }

    /**
     * 사이트 첫 화면(`/`) 라우트는 운영과 같이 라우트 제목(「홈」)을 미리보기에 넘기지 않습니다.
     *
     * @scenario surface=preview_route_title
     *
     * @effects bot_preview_uses_route_meta_title_like_production
     *
     * @return void 반환값 없음
     */
    public function test_home_route_title_is_not_passed_to_preview(): void
    {
        $this->mockRouteResolver();

        $captured = [];
        $this->captureServiceCall($captured);
        $this->postPreview(['layout_name' => 'home'])->assertStatus(200);

        $this->assertNull($captured['routeTitle'], '사이트 첫 화면은 라우트 제목을 쓰지 않는다');
    }

    /**
     * 라우트 해석기를 목으로 바꿉니다 — 메모 목록(`/memos`)과 홈(`/`) 두 라우트만 안다.
     *
     * @return void 반환값 없음
     */
    private function mockRouteResolver(): void
    {
        $this->mock(TemplateRouteResolver::class, function ($mock) {
            $mock->shouldReceive('describeRouteLayout')
                ->andReturnUsing(fn (string $name) => str_contains($name, '.')
                    ? ['layoutName' => explode('.', $name, 2)[1], 'storedLayoutName' => $name, 'moduleIdentifier' => explode('.', $name, 2)[0], 'pluginIdentifier' => null]
                    : ['layoutName' => $name, 'storedLayoutName' => $name, 'moduleIdentifier' => null, 'pluginIdentifier' => null]);
            $mock->shouldReceive('findRouteByLayout')
                ->with('gnuboard7-hello_module.user_memo_list')
                ->andReturn(['path' => '/memos', 'meta' => ['title' => '$t:gnuboard7-hello_module.user.memos.index.title']]);
            $mock->shouldReceive('findRouteByLayout')
                ->with('home')
                ->andReturn(['path' => '/', 'meta' => ['title' => '홈']]);
        });
    }
}
