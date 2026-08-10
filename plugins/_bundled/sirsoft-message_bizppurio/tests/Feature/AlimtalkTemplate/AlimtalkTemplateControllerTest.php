<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Feature\AlimtalkTemplate;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Mockery;
use Mockery\MockInterface;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException;
use Plugins\Sirsoft\MessageBizppurio\Services\AlimtalkTemplateService;
use Plugins\Sirsoft\MessageBizppurio\Services\NotificationBindingService;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 알림톡 템플릿 조회 컨트롤러 Feature 테스트 (조회 전용).
 *
 * 라우트 → 컨트롤러 → 서비스 경계를 검증한다. kapi 실제 호출 로직은
 * AlimtalkTemplateServiceTest 가 검증하므로, 여기서는 AlimtalkTemplateService 를
 * 컨테이너에 mock 으로 바인딩해 조회 권한 경계(view)·응답 봉투·kapi 실패(예외)의 422
 * 전파·등록 라우트 제거(405)를 격리 검증한다.
 *
 * @since 1.0.0
 */
class AlimtalkTemplateControllerTest extends PluginTestCase
{
    private const BASE = '/api/plugins/sirsoft-message_bizppurio/admin/alimtalk-templates';

    /**
     * AlimtalkTemplateService mock 을 컨테이너에 바인딩하고 반환한다.
     */
    private function mockService(): MockInterface
    {
        $mock = Mockery::mock(AlimtalkTemplateService::class);
        $this->app->instance(AlimtalkTemplateService::class, $mock);

        return $mock;
    }

    /**
     * 지정 권한을 가진 admin 사용자를 생성한다.
     *
     * @param  array<int, string>  $permissionIdentifiers
     */
    private function adminWith(array $permissionIdentifiers): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            ['name' => json_encode(['ko' => '관리자', 'en' => 'Admin']), 'type' => 'admin']
        );

        $testRole = Role::create([
            'identifier' => 'bizppurio_tpl_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'type' => 'admin',
        ]);

        $permissionIds = [];
        foreach ($permissionIdentifiers as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => json_encode(['ko' => $identifier, 'en' => $identifier]), 'type' => 'admin']
            )->id;
        }
        $testRole->permissions()->sync($permissionIds);

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($testRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array<string, string>
     */
    private function authHeaders(array $permissions): array
    {
        $user = $this->adminWith($permissions);
        $token = $user->createToken('test-token')->plainTextToken;

        return [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ];
    }

    /**
     * @scenario auth=view,service=ok
     *
     * @effects list_returns_templates_with_status_badge
     */
    public function test_view권한으로_목록을_조회한다(): void
    {
        $this->mockService()->shouldReceive('list')->once()->andReturn([
            'templates' => [
                ['templateCode' => 'TW_1', 'templateName' => '주문완료', 'status_badge' => ['variant' => 'green']],
            ],
            'pagination' => ['total' => 1, 'total_page' => 1, 'current_page' => 1, 'per_page' => 20],
        ]);

        $response = $this->withHeaders($this->authHeaders(['sirsoft-message_bizppurio.messaging.view']))
            ->getJson(self::BASE);

        $response->assertStatus(200);
        $response->assertJsonPath('data.templates.0.templateCode', 'TW_1');
        $response->assertJsonPath('data.templates.0.status_badge.variant', 'green');
    }

    /**
     * @scenario auth=guest
     *
     * @effects list_requires_authentication_returns_401
     */
    public function test_비인증은_401(): void
    {
        $this->getJson(self::BASE)->assertStatus(401);
    }

    /**
     * @scenario auth=none_of_required,action=index
     *
     * @effects list_requires_view_permission_returns_403
     */
    public function test_view권한_없으면_목록조회_403(): void
    {
        $response = $this->withHeaders($this->authHeaders(['sirsoft-message_bizppurio.messaging.other']))
            ->getJson(self::BASE);

        $response->assertStatus(403);
    }

    /**
     * @scenario auth=view,service=ok
     *
     * @effects show_returns_template_detail_with_status_badge
     */
    public function test_view권한으로_상세를_조회한다(): void
    {
        $this->mockService()->shouldReceive('detail')->once()->with('TW_1')->andReturn([
            'templateCode' => 'TW_1',
            'templateName' => '주문완료',
            'status_badge' => ['variant' => 'green'],
        ]);

        $response = $this->withHeaders($this->authHeaders(['sirsoft-message_bizppurio.messaging.view']))
            ->getJson(self::BASE.'/TW_1');

        $response->assertStatus(200);
        $response->assertJsonPath('data.template.templateCode', 'TW_1');
        $response->assertJsonPath('data.template.status_badge.variant', 'green');
    }

    /**
     * @scenario auth=view,action=store
     *
     * @effects store_route_removed_returns_405_read_only
     */
    public function test_등록_라우트는_제거되어_조회전용이다(): void
    {
        // 조회 전용 전환으로 등록(POST)·상태변경 라우트를 제거했다. manage 미들웨어 블록이
        // 사라졌으므로 POST 는 라우트 미매칭(405)이 되어야 한다(등록은 비즈뿌리오 콘솔).
        $response = $this->withHeaders($this->authHeaders(['sirsoft-message_bizppurio.messaging.view']))
            ->postJson(self::BASE, [
                'templateName' => 'T',
                'templateContent' => '본문',
                'categoryCode' => '001',
                'templateEmphasizeType' => 'NONE',
            ]);

        $response->assertStatus(405);
    }

    /**
     * @scenario auth=view,service=throws
     *
     * @effects kapi_failure_is_surfaced_as_422_with_result_code
     */
    public function test_kapi_실패는_422로_전파된다(): void
    {
        $this->mockService()->shouldReceive('list')->once()
            ->andThrow(new BizppurioApiException('발신프로필을 찾을 수 없습니다.', resultCode: '7204'));

        $response = $this->withHeaders($this->authHeaders(['sirsoft-message_bizppurio.messaging.view']))
            ->getJson(self::BASE);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.result_code', '7204');
        $response->assertJsonPath('errors.kakao_message', '발신프로필을 찾을 수 없습니다.');
    }

    /**
     * @scenario auth=view,kapi_result=not_found
     *
     * @effects list_treats_kapi_508_as_empty_result_not_error
     */
    public function test_kapi_508은_에러가_아니라_빈_목록으로_응답한다(): void
    {
        $this->mockService()->shouldReceive('list')->once()->andReturn([
            'templates' => [],
            'pagination' => ['total' => 0, 'total_page' => 1, 'current_page' => 1, 'per_page' => 20],
        ]);

        $response = $this->withHeaders($this->authHeaders(['sirsoft-message_bizppurio.messaging.view']))
            ->getJson(self::BASE.'?keyword=대글');

        $response->assertStatus(200);
        $response->assertJsonPath('data.templates', []);
        $response->assertJsonPath('data.pagination.total', 0);
    }

    /**
     * @scenario auth=manage,action=clear_cache
     *
     * @effects clear_cache_delegates_to_binding_service_and_returns_count
     */
    public function test_manage권한으로_발송내용_캐시를_초기화한다(): void
    {
        $bindings = Mockery::mock(NotificationBindingService::class);
        $bindings->shouldReceive('clearTemplateContentCache')->once()->andReturn(3);
        $this->app->instance(NotificationBindingService::class, $bindings);

        $response = $this->withHeaders($this->authHeaders(['sirsoft-message_bizppurio.messaging.manage']))
            ->postJson(self::BASE.'/cache/clear');

        $response->assertStatus(200);
        $response->assertJsonPath('data.cleared', 3);
    }

    /**
     * @scenario auth=view_only,action=clear_cache
     *
     * @effects clear_cache_requires_manage_permission_returns_403
     */
    public function test_캐시초기화는_manage권한이_없으면_403(): void
    {
        // 조회(view) 권한만으로는 캐시 초기화(쓰기)를 할 수 없다.
        $response = $this->withHeaders($this->authHeaders(['sirsoft-message_bizppurio.messaging.view']))
            ->postJson(self::BASE.'/cache/clear');

        $response->assertStatus(403);
    }

    /**
     * @scenario auth=guest,action=clear_cache
     *
     * @effects clear_cache_requires_authentication_returns_401
     */
    public function test_캐시초기화는_비인증이면_401(): void
    {
        $this->postJson(self::BASE.'/cache/clear')->assertStatus(401);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
