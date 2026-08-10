<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Feature\Settings;

use App\Extension\Cache\PluginCacheDriver;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 연결 확인(인증 토큰 재검증) 엔드포인트 테스트.
 *
 * POST /api/plugins/sirsoft-message_bizppurio/admin/token/check 가 저장된 자격증명으로
 * `/v1/token` 을 즉시 재호출해 성공/실패를 그대로 반환하는지, 그리고 인증/권한 가드가
 * 동작하는지 검증한다.
 *
 * @since 1.0.0
 */
class TokenCheckEndpointTest extends PluginTestCase
{
    private const IDENTIFIER = 'sirsoft-message_bizppurio';

    private const ENDPOINT = '/api/plugins/sirsoft-message_bizppurio/admin/token/check';

    /**
     * 지정 권한을 가진 admin 사용자를 생성합니다.
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
            'identifier' => 'bizppurio_token_check_test_'.uniqid(),
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
     *
     * @return array<string, string>
     */
    private function authHeaders(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    /**
     * 비즈뿌리오 아이디/비밀번호를 저장합니다.
     */
    private function storeCredentials(string $id = 'biz-account', string $password = 'secret'): void
    {
        app(PluginSettingsService::class)->save(self::IDENTIFIER, [
            'bizppurio_id' => $id,
            'password' => $password,
            'is_test_mode' => true,
        ]);
    }

    public function test_인증_없이_요청하면_401이다(): void
    {
        $this->postJson(self::ENDPOINT)->assertStatus(401);
    }

    public function test_manage_권한_없이_요청하면_403이다(): void
    {
        $admin = $this->adminWith(['sirsoft-message_bizppurio.messaging.view']);

        $response = $this->withHeaders($this->authHeaders($admin))->postJson(self::ENDPOINT);

        $response->assertStatus(403);
    }

    public function test_자격증명이_유효하면_200을_반환한다(): void
    {
        Http::fake([
            '*/v1/token' => Http::response(['accesstoken' => 'VALID_TOKEN', 'type' => 'Bearer'], 200),
        ]);

        $this->storeCredentials();
        $admin = $this->adminWith(['sirsoft-message_bizppurio.messaging.manage']);

        $response = $this->withHeaders($this->authHeaders($admin))->postJson(self::ENDPOINT);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    public function test_자격증명이_유효하면_캐시가_새_토큰으로_갱신된다(): void
    {
        Http::fake([
            '*/v1/token' => Http::response(['accesstoken' => 'FRESH_TOKEN', 'type' => 'Bearer'], 200),
        ]);

        $this->storeCredentials();
        $admin = $this->adminWith(['sirsoft-message_bizppurio.messaging.manage']);

        // BizppurioTokenService 가 contextual binding 으로 받는 것과 동일한 캐시
        // (식별자 네임스페이스 + 기본 캐시 스토어) — 전역 app(CacheInterface::class) 는
        // 코어 네임스페이스라 별개 저장 공간이므로 사용할 수 없다.
        $cache = new PluginCacheDriver(self::IDENTIFIER, config('cache.default'));
        $cache->flush();

        $response = $this->withHeaders($this->authHeaders($admin))->postJson(self::ENDPOINT);

        $response->assertStatus(200);
        $this->assertSame('FRESH_TOKEN', $cache->get('bizppurio:token'));
    }

    public function test_비즈뿌리오_서버에_연결할_수_없으면_500_대신_422를_반환한다(): void
    {
        Http::fake(['*/v1/token' => fn () => throw new ConnectionException('Connection refused')]);

        $this->storeCredentials();
        $admin = $this->adminWith(['sirsoft-message_bizppurio.messaging.manage']);

        $response = $this->withHeaders($this->authHeaders($admin))->postJson(self::ENDPOINT);

        $response->assertStatus(422);
    }

    public function test_비즈뿌리오가_실패_사유를_반환하면_422와_함께_원문_사유가_노출된다(): void
    {
        Http::fake([
            '*/v1/token' => Http::response(['code' => '3007', 'description' => 'invalid password in bizppurio'], 401),
        ]);

        $this->storeCredentials();
        $admin = $this->adminWith(['sirsoft-message_bizppurio.messaging.manage']);

        $response = $this->withHeaders($this->authHeaders($admin))->postJson(self::ENDPOINT);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.result_code', '3007');
        $this->assertStringContainsString('invalid password in bizppurio', $response->json('message'));
    }

    public function test_자격증명_미설정시_422를_반환한다(): void
    {
        $this->storeCredentials(id: '', password: '');
        $admin = $this->adminWith(['sirsoft-message_bizppurio.messaging.manage']);

        $response = $this->withHeaders($this->authHeaders($admin))->postJson(self::ENDPOINT);

        $response->assertStatus(422);
    }
}
