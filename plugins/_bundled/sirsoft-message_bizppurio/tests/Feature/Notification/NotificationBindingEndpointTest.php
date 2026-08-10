<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Feature\Notification;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Mockery;
use Mockery\MockInterface;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioNotificationBinding;
use Plugins\Sirsoft\MessageBizppurio\Services\AlimtalkTemplateService;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 알림↔알림톡 템플릿 연동 조회 엔드포인트 테스트 (Phase 6 재설계 A, §6-2).
 *
 * 알림톡 탭은 코어 기본 목록·편집 모달을 그대로 쓴다. 이 엔드포인트는 코어 편집 모달 전용 칸이
 * 소비하는 조회(연동 맵 index·승인 템플릿 드롭다운 approved-templates)와 즉시 저장(store)을
 * 제공한다. 값 변경 즉시 store 로 저장되며(빈 코드=해제), 코어 저장 버튼과 무관하다(무오염).
 * 조회는 messaging.view, 저장은 messaging.manage 권한을 요구한다(라우트 미들웨어).
 */
class NotificationBindingEndpointTest extends PluginTestCase
{
    private const BASE = '/api/plugins/sirsoft-message_bizppurio/admin/notification-bindings';

    /**
     * AlimtalkTemplateService mock 을 컨테이너에 바인딩하고 반환한다.
     *
     * bind() 가 저장 전 카카오 승인 목록을 조회하므로(회귀 방지 재검증), 실제 kapi 호출 없이
     * 저장 경로를 검증하려면 이 mock 으로 승인 템플릿 목록을 지정해야 한다.
     */
    private function mockTemplateService(): MockInterface
    {
        $mock = Mockery::mock(AlimtalkTemplateService::class);
        $this->app->instance(AlimtalkTemplateService::class, $mock);

        return $mock;
    }

    /**
     * 지정 template_code 목록을 승인(ACT) 상태로 반환하는 mock 을 등록한다.
     *
     * @param  array<int, string>  $codes  승인 처리할 template_code 목록
     */
    private function stubApprovedTemplates(array $codes): void
    {
        $templates = array_map(
            fn (string $code) => ['templateCode' => $code, 'templateName' => $code, 'serviceStatus' => 'ACT'],
            $codes,
        );

        $this->mockTemplateService()
            ->shouldReceive('list')
            ->andReturn(['templates' => $templates, 'pagination' => []]);
    }

    /**
     * 지정 권한 식별자들을 가진 admin 사용자를 만듭니다.
     *
     * @param  array<int, string>  $permissionIds  부여할 권한 식별자
     */
    private function adminWith(array $permissionIds): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            ['name' => json_encode(['ko' => '관리자', 'en' => 'Admin']), 'type' => 'admin']
        );

        $permIds = [];
        foreach ($permissionIds as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => json_encode(['ko' => $identifier, 'en' => $identifier]), 'type' => 'admin']
            );
            $permIds[] = $permission->id;
        }

        $testRole = Role::create([
            'identifier' => 'bizppurio_binding_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'type' => 'admin',
        ]);
        $testRole->permissions()->sync($permIds);

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($testRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 사용자 토큰으로 요청 헤더를 만듭니다.
     */
    private function authHeaders(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    public function test_인증_없이_목록_조회는_401이다(): void
    {
        $this->getJson(self::BASE)->assertStatus(401);
    }

    public function test_view_권한으로_연동_맵을_조회한다(): void
    {
        BizppurioNotificationBinding::create([
            'notification_type' => 'welcome',
            'channel' => 'alimtalk',
            'template_code' => 'TW_1236',
            'template_name' => '가입환영',
            'fallback_sms_enabled' => true,
            'is_active' => true,
        ]);
        $admin = $this->adminWith(['sirsoft-message_bizppurio.messaging.view']);

        $response = $this->withHeaders($this->authHeaders($admin))->getJson(self::BASE);

        $response->assertStatus(200);
        $response->assertJsonPath('data.bindings.welcome.template_code', 'TW_1236');
        $response->assertJsonPath('data.bindings.welcome.fallback_sms_enabled', true);
    }

    public function test_연동이_없으면_빈_맵을_반환한다(): void
    {
        $admin = $this->adminWith(['sirsoft-message_bizppurio.messaging.view']);

        $response = $this->withHeaders($this->authHeaders($admin))->getJson(self::BASE);

        $response->assertStatus(200);
        $response->assertJsonPath('data.bindings', []);
    }

    public function test_권한_없는_사용자는_승인템플릿_조회_403이다(): void
    {
        $admin = $this->adminWith([]); // messaging.view 없음

        $response = $this->withHeaders($this->authHeaders($admin))
            ->getJson(self::BASE.'/approved-templates');

        $response->assertStatus(403);
    }

    public function test_view_권한만으로는_저장할_수_없다(): void
    {
        $admin = $this->adminWith(['sirsoft-message_bizppurio.messaging.view']);

        $response = $this->withHeaders($this->authHeaders($admin))->postJson(self::BASE, [
            'notification_type' => 'welcome',
            'template_code' => 'TW_1236',
            'template_name' => '가입환영',
        ]);

        $response->assertStatus(403);
    }

    public function test_manage_권한으로_연동을_즉시_저장한다(): void
    {
        $this->stubApprovedTemplates(['TW_1236']);
        $admin = $this->adminWith([
            'sirsoft-message_bizppurio.messaging.view',
            'sirsoft-message_bizppurio.messaging.manage',
        ]);

        $response = $this->withHeaders($this->authHeaders($admin))->postJson(self::BASE, [
            'notification_type' => 'welcome',
            'template_code' => 'TW_1236',
            'template_name' => '가입환영',
            'fallback_sms_enabled' => true,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('bizppurio_notification_bindings', [
            'notification_type' => 'welcome',
            'channel' => 'alimtalk',
            'template_code' => 'TW_1236',
            'fallback_sms_enabled' => true,
        ]);
    }

    public function test_미승인_템플릿_코드로_저장하면_422이고_저장되지_않는다(): void
    {
        // 회귀: 화면 드롭다운은 승인 템플릿만 보여주지만, 그 필터를 우회해 API 를 직접
        // 호출하면 미승인 template_code 도 저장되던 결함. bind() 서버측 재검증으로 차단.
        $this->stubApprovedTemplates(['TW_OTHER']);
        $admin = $this->adminWith([
            'sirsoft-message_bizppurio.messaging.view',
            'sirsoft-message_bizppurio.messaging.manage',
        ]);

        $response = $this->withHeaders($this->authHeaders($admin))->postJson(self::BASE, [
            'notification_type' => 'welcome',
            'template_code' => 'TW_UNAPPROVED',
            'template_name' => '미승인',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('bizppurio_notification_bindings', [
            'notification_type' => 'welcome',
            'template_code' => 'TW_UNAPPROVED',
        ]);
    }

    public function test_빈_코드로_저장하면_연동을_해제한다(): void
    {
        BizppurioNotificationBinding::create([
            'notification_type' => 'welcome',
            'channel' => 'alimtalk',
            'template_code' => 'TW_1236',
            'template_name' => '가입환영',
            'fallback_sms_enabled' => false,
            'is_active' => true,
        ]);

        $admin = $this->adminWith([
            'sirsoft-message_bizppurio.messaging.view',
            'sirsoft-message_bizppurio.messaging.manage',
        ]);

        // 해제는 카카오 승인 조회를 거치지 않으므로 mock 불필요.
        $response = $this->withHeaders($this->authHeaders($admin))->postJson(self::BASE, [
            'notification_type' => 'welcome',
            'template_code' => '',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('bizppurio_notification_bindings', [
            'notification_type' => 'welcome',
            'channel' => 'alimtalk',
        ]);
    }

    public function test_notification_type_누락_시_422다(): void
    {
        $admin = $this->adminWith([
            'sirsoft-message_bizppurio.messaging.view',
            'sirsoft-message_bizppurio.messaging.manage',
        ]);

        // FormRequest 검증(notification_type required)이 서비스 호출보다 먼저 실패하므로 mock 불필요.
        $response = $this->withHeaders($this->authHeaders($admin))->postJson(self::BASE, [
            'template_code' => 'TW_1236',
        ]);

        $response->assertStatus(422);
    }
}
