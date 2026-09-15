<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Feature\AlimtalkTemplate;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Mockery;
use Mockery\MockInterface;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException;
use Plugins\Sirsoft\MessageBizppurio\Services\AlimtalkTemplateService;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 알림톡 작성 모달 참조 조회 컨트롤러 Feature 테스트 (#597).
 *
 * 라우트 → 컨트롤러 → 서비스 경계를 검증한다. kapi 실제 호출 로직은
 * AlimtalkTemplateServiceTest 가 검증하므로, 여기서는 AlimtalkTemplateService 를
 * 컨테이너에 mock 으로 바인딩해 조회 권한 경계(view)·응답 봉투·kapi 실패(예외)의
 * 422 전파를 격리 검증한다. 실시간 목록/상세 화면(구 Phase 5)은 DB 기반
 * 라이프사이클(BizppurioTemplateController)로 대체되어 제거됐다 — 남은 라우트는
 * categories/profiles 둘뿐이다.
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
     * view 권한으로 카테고리 전체를 조회한다 (data.categories 봉투).
     */
    public function test_view권한으로_카테고리를_조회한다(): void
    {
        $this->mockService()->shouldReceive('categories')->once()->andReturn([
            ['code' => '001001', 'name' => '회원가입', 'groupName' => '회원'],
        ]);

        $response = $this->withHeaders($this->authHeaders(['sirsoft-message_bizppurio.messaging.view']))
            ->getJson(self::BASE.'/categories');

        $response->assertStatus(200);
        $response->assertJsonPath('data.categories.0.code', '001001');
        $response->assertJsonPath('data.categories.0.groupName', '회원');
    }

    /**
     * view 권한으로 발신프로필 목록을 조회한다 (data.profiles 봉투).
     */
    public function test_view권한으로_발신프로필을_조회한다(): void
    {
        $this->mockService()->shouldReceive('senderProfiles')->once()->andReturn([
            ['senderKey' => 'SK_40', 'name' => '테스트채널', 'status' => 'A'],
        ]);

        $response = $this->withHeaders($this->authHeaders(['sirsoft-message_bizppurio.messaging.view']))
            ->getJson(self::BASE.'/profiles');

        $response->assertStatus(200);
        $response->assertJsonPath('data.profiles.0.senderKey', 'SK_40');
        $response->assertJsonPath('data.profiles.0.name', '테스트채널');
    }

    /**
     * 비인증 요청은 401 이다.
     */
    public function test_비인증은_401(): void
    {
        $this->getJson(self::BASE.'/categories')->assertStatus(401);
        $this->getJson(self::BASE.'/profiles')->assertStatus(401);
    }

    /**
     * view 권한이 없으면 두 라우트 모두 403 이다.
     */
    public function test_view권한_없으면_403(): void
    {
        $headers = $this->authHeaders(['sirsoft-message_bizppurio.messaging.other']);

        $this->withHeaders($headers)->getJson(self::BASE.'/categories')->assertStatus(403);
        $this->withHeaders($headers)->getJson(self::BASE.'/profiles')->assertStatus(403);
    }

    /**
     * kapi 실패는 카카오 사유(message)·결과코드가 담긴 422 로 전파된다 (GuardsKakaoRequests 규약).
     */
    public function test_kapi_실패는_422로_전파된다(): void
    {
        $this->mockService()->shouldReceive('categories')->once()
            ->andThrow(new BizppurioApiException('접근할 수 없는 IP 입니다.', resultCode: '403'));

        $response = $this->withHeaders($this->authHeaders(['sirsoft-message_bizppurio.messaging.view']))
            ->getJson(self::BASE.'/categories');

        $response->assertStatus(422);
        $response->assertJsonPath('errors.bizppurio_message', '접근할 수 없는 IP 입니다.');
        $response->assertJsonPath('errors.result_code', '403');
    }

    /**
     * 발신프로필 조회의 kapi 실패도 동일한 422 규약으로 전파된다.
     */
    public function test_발신프로필_kapi_실패도_422로_전파된다(): void
    {
        $this->mockService()->shouldReceive('senderProfiles')->once()
            ->andThrow(new BizppurioApiException('발신프로필을 찾을 수 없습니다.', resultCode: '7204'));

        $response = $this->withHeaders($this->authHeaders(['sirsoft-message_bizppurio.messaging.view']))
            ->getJson(self::BASE.'/profiles');

        $response->assertStatus(422);
        $response->assertJsonPath('errors.bizppurio_message', '발신프로필을 찾을 수 없습니다.');
        $response->assertJsonPath('errors.result_code', '7204');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
