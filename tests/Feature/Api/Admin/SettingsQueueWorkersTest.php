<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Repositories\JsonConfigRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 환경설정 드라이버 탭 「큐 워커」 등록·상태 API 테스트 (#139)
 *
 * 저장 검증 V1~V9, 서버 정규화, 설정 응답의 등록 가능 연결 메타, 큐 워커 상태 API 의 계약 모양과 권한 경계를 잠근다.
 */
class SettingsQueueWorkersTest extends TestCase
{
    use DatabaseTransactions;

    /** @var TestResponse|null 마지막 저장 응답 */
    private ?TestResponse $lastResponse = null;

    /**
     * V1 — 등록할 수 없는 연결은 거부한다.
     *
     * @scenario rule=v1
     *
     * @effects invalid_queue_registration_rejected
     *
     * @return void 반환값 없음
     */
    public function test_v1_unknown_connection_is_rejected(): void
    {
        $this->save(['queue_connections' => ['database', 'nope']])
            ->assertStatus(422);
        $this->assertQueueError('drivers.queue_connections.1', __('validation.settings.queue_connection_invalid'));
        $this->save(['queue_connections' => ['database', 'sync']])->assertStatus(422)->assertJsonValidationErrors(['drivers.queue_connections.1']);
    }

    /**
     * V2 — 같은 연결이 두 번이면 두 번째 칸에 오류.
     *
     * @scenario rule=v2
     *
     * @effects invalid_queue_registration_rejected
     *
     * @return void 반환값 없음
     */
    public function test_v2_duplicate_connection_is_rejected(): void
    {
        $response = $this->save(['queue_connections' => ['database', 'database']])->assertStatus(422);

        $this->assertArrayHasKey('drivers.queue_connections.1', $response->json('errors'));
        $this->assertArrayNotHasKey('drivers.queue_connections.0', $response->json('errors'));
    }

    /**
     * V3 — 현재 큐 드라이버 연결을 뺀 목록은 거부한다. 빈 목록은 통과한다.
     *
     * @scenario rule=v3
     *
     * @effects invalid_queue_registration_rejected
     *
     * @return void 반환값 없음
     */
    public function test_v3_missing_default_connection_is_rejected(): void
    {
        $this->save(['queue_connections' => ['database-long']])
            ->assertStatus(422);
        $this->assertQueueError('drivers.queue_connections', __('validation.settings.queue_connection_default_required', ['driver' => 'database']));
        $this->save(['queue_connections' => []])->assertOk();
        $this->save(['queue_driver' => 'sync', 'queue_connections' => ['database-long']])->assertOk();
    }

    /**
     * V4 — 큐 목록 키가 정의되지 않은 연결이면 거부한다.
     *
     * @scenario rule=v4
     *
     * @effects invalid_queue_registration_rejected
     *
     * @return void 반환값 없음
     */
    public function test_v4_queue_names_for_unknown_connection_is_rejected(): void
    {
        $this->save(['queue_connections' => ['database'], 'queue_names' => ['nope' => ['x']]])
            ->assertStatus(422);
        $this->assertQueueError('drivers.queue_names', __('validation.settings.queue_names_connection_unknown', ['connection' => 'nope']));
    }

    /**
     * V5 — 등록한 연결의 큐 목록이 비면 거부한다.
     *
     * @scenario rule=v5
     *
     * @effects invalid_queue_registration_rejected
     *
     * @return void 반환값 없음
     */
    public function test_v5_empty_queue_list_is_rejected(): void
    {
        $this->save(['queue_connections' => ['database'], 'queue_names' => ['database' => []]])
            ->assertStatus(422);
        $this->assertQueueError('drivers.queue_names.database', __('validation.settings.queue_names_required', ['connection' => 'database']));
    }

    /**
     * V6 — 빈 큐 이름은 거부한다.
     *
     * @scenario rule=v6
     *
     * @effects invalid_queue_registration_rejected
     *
     * @return void 반환값 없음
     */
    public function test_v6_empty_queue_name_is_rejected(): void
    {
        $this->save(['queue_connections' => ['database'], 'queue_names' => ['database' => ['default', '']]])
            ->assertStatus(422);
        $this->assertQueueError('drivers.queue_names.database.1', __('validation.settings.queue_name_required'));
    }

    /**
     * V7 — 허용 외 문자·첫 글자는 거부한다.
     *
     * @scenario rule=v7
     *
     * @effects invalid_queue_registration_rejected
     *
     * @return void 반환값 없음
     */
    public function test_v7_invalid_queue_name_is_rejected(): void
    {
        foreach (['bad name!', '-lead', '<script>', '큐'] as $name) {
            $this->save(['queue_connections' => ['database'], 'queue_names' => ['database' => [$name]]])
                ->assertStatus(422);
            $this->assertQueueError('drivers.queue_names.database.0', __('validation.settings.queue_name_invalid'));
        }
    }

    /**
     * V8 — 64자 초과는 거부하고 64자는 통과한다.
     *
     * @scenario rule=v8
     *
     * @effects invalid_queue_registration_rejected
     *
     * @return void 반환값 없음
     */
    public function test_v8_too_long_queue_name_is_rejected(): void
    {
        $this->save(['queue_connections' => ['database'], 'queue_names' => ['database' => [str_repeat('q', 65)]]])
            ->assertStatus(422);
        $this->assertQueueError('drivers.queue_names.database.0', __('validation.settings.queue_name_max'));
        $this->save(['queue_connections' => ['database'], 'queue_names' => ['database' => [str_repeat('q', 64)]]])->assertOk();
    }

    /**
     * V9 — 한 연결 안의 같은 큐 이름은 거부한다.
     *
     * @scenario rule=v9
     *
     * @effects invalid_queue_registration_rejected
     *
     * @return void 반환값 없음
     */
    public function test_v9_duplicate_queue_in_same_connection_is_rejected(): void
    {
        $this->save(['queue_connections' => ['database'], 'queue_names' => ['database' => ['default', 'default']]])
            ->assertStatus(422);
        $this->assertQueueError('drivers.queue_names.database', __('validation.settings.queue_name_duplicate', ['connection' => 'database', 'name' => 'default']));
    }

    /**
     * 연결이 다르면 같은 큐 이름을 써도 된다.
     *
     * @scenario rule=cross_connection
     *
     * @effects queue_registration_saved
     *
     * @return void 반환값 없음
     */
    public function test_same_queue_name_on_different_connections_is_allowed(): void
    {
        $this->save([
            'queue_connections' => ['database', 'redis'],
            'queue_names' => ['database' => ['default'], 'redis' => ['default']],
        ])->assertOk();
    }

    /**
     * 관리자가 큐 워커 등록을 저장하면 drivers.json 에 그대로 남는다.
     *
     * @scenario rule=valid
     *
     * @effects queue_registration_saved
     *
     * @return void 반환값 없음
     */
    public function test_admin_can_save_queue_workers(): void
    {
        $this->save([
            'queue_connections' => ['database', 'database-long'],
            'queue_names' => ['database' => ['default', 'notifications'], 'database-long' => ['long']],
        ])->assertOk();

        $saved = (new JsonConfigRepository)->getCategory('drivers');
        $this->assertSame(['database', 'database-long'], $saved['queue_connections']);
        $this->assertSame(['database' => ['default', 'notifications'], 'database-long' => ['long']], $saved['queue_names']);
    }

    /**
     * 큐 목록이 없는 등록 연결은 기본값으로 채우고, 등록하지 않은 연결의 목록은 버린다. 빈 연결 목록은 현재 드라이버 연결 하나다.
     *
     * @scenario rule=normalize
     *
     * @effects queue_registration_normalized
     *
     * @return void 반환값 없음
     */
    public function test_server_normalizes_missing_queue_names(): void
    {
        $this->save([
            'queue_connections' => ['database', 'database-long'],
            'queue_names' => ['redis' => ['stale']],
        ])->assertOk();
        $saved = (new JsonConfigRepository)->getCategory('drivers');
        $this->assertSame(['database' => ['default'], 'database-long' => ['long']], $saved['queue_names']);

        $this->save(['queue_connections' => []])->assertOk();
        $saved = (new JsonConfigRepository)->getCategory('drivers');
        $this->assertSame(['database'], $saved['queue_connections']);
        $this->assertSame(['database' => ['default']], $saved['queue_names']);
    }

    /**
     * 설정 조회 응답에 등록 가능 연결(sync 제외)이 실리고, 큐 목록은 비어도 객체다.
     *
     * @scenario endpoint=settings_get
     *
     * @effects connection_options_exposed
     *
     * @return void 반환값 없음
     */
    public function test_settings_get_exposes_queue_connection_options_without_sync(): void
    {
        $response = $this->withToken($this->tokenWith(['core.settings.read']))->getJson('/api/admin/settings')->assertOk();

        $names = array_column($response->json('data._meta.queue_connection_options'), 'name');
        $this->assertContains('database', $names);
        $this->assertContains('database-long', $names);
        $this->assertContains('redis-long', $names);
        $this->assertNotContains('sync', $names);
        $this->assertStringContainsString('"queue_names":{', $response->getContent());
    }

    /**
     * 큐 워커 상태 API 는 계약한 모양을 돌려준다.
     *
     * @scenario endpoint=queue_status
     *
     * @effects status_endpoint_contract
     *
     * @return void 반환값 없음
     */
    public function test_queue_status_endpoint_returns_contract_shape(): void
    {
        config(['queue.default' => 'database', 'g7_settings.core.drivers.queue_connections' => ['database', 'database-long'], 'g7_settings.core.drivers.queue_names' => []]);

        $this->withToken($this->tokenWith(['core.settings.read']))
            ->getJson('/api/admin/settings/queue-status')
            ->assertOk()
            ->assertJsonPath('data.driver', 'database')
            ->assertJsonPath('data.heartbeat_stale_after_minutes', 5)
            ->assertJsonPath('data.long_worker.status', 'registered')
            ->assertJsonStructure(['data' => [
                'driver', 'measured_at', 'heartbeat_stale_after_minutes',
                'rows' => [['connection', 'queue', 'driver', 'pending', 'pending_measurable', 'last_heartbeat_at', 'warnings', 'clamped_timeout_count', 'long_queue_hint']],
                'long_worker' => ['connection', 'queue', 'status'],
                'commands' => [['connection', 'queues', 'command']],
                'placement' => ['timeout_clamped' => ['count', 'last'], 'fallback' => ['count', 'last']],
            ]])
            ->assertJsonCount(2, 'data.rows');
    }

    /**
     * 설정 조회 권한이 없으면 상태 API 는 403 이다.
     *
     * @scenario endpoint=queue_status, permission=missing
     *
     * @effects status_endpoint_requires_permission
     *
     * @return void 반환값 없음
     */
    public function test_queue_status_requires_settings_read_permission(): void
    {
        $this->withToken($this->tokenWith(['core.users.read']))
            ->getJson('/api/admin/settings/queue-status')
            ->assertStatus(403);
    }

    /**
     * 마지막 저장 응답의 오류 키에 기대한 문구가 있는지 확인합니다 — 키에 점이 들어 있어 JSON 경로로 짚지 않는다.
     *
     * @param  string  $key  오류 키 (예: drivers.queue_names.database.1)
     * @param  string  $message  기대 문구
     * @return void 반환값 없음
     */
    private function assertQueueError(string $key, string $message): void
    {
        $errors = $this->lastResponse?->json('errors') ?? [];
        $this->assertArrayHasKey($key, $errors, '오류 키가 없다: '.json_encode(array_keys($errors), JSON_UNESCAPED_UNICODE));
        $this->assertSame($message, $errors[$key][0] ?? null);
    }

    /**
     * 드라이버 탭을 저장합니다.
     *
     * @param  array<string, mixed>  $drivers  덮어쓸 drivers 값
     * @return TestResponse 응답
     */
    private function save(array $drivers): TestResponse
    {
        return $this->lastResponse = $this->withToken($this->tokenWith(['core.settings.read', 'core.settings.update']))
            ->postJson('/api/admin/settings', [
                '_tab' => 'drivers',
                'drivers' => array_merge([
                    'storage_driver' => 'local',
                    'cache_driver' => 'file',
                    'session_driver' => 'file',
                    'queue_driver' => 'database',
                    'log_driver' => 'daily',
                    'log_level' => 'error',
                ], $drivers),
            ]);
    }

    /**
     * 권한을 가진 관리자를 만들고 Bearer 토큰을 돌려줍니다.
     *
     * @param  array<int, string>  $permissions  권한 식별자 목록
     * @return string plainText 토큰
     */
    private function tokenWith(array $permissions): string
    {
        $user = User::factory()->create();
        $permissionIds = [];

        foreach ($permissions as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => ['ko' => $identifier, 'en' => $identifier],
                    'description' => ['ko' => $identifier, 'en' => $identifier],
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }

        $role = Role::create([
            'identifier' => 'queue_workers_test_'.uniqid(),
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'description' => ['ko' => '테스트', 'en' => 'Test'],
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => ['ko' => '관리자', 'en' => 'Administrator'],
                'description' => ['ko' => '관리자', 'en' => 'Administrator'],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh()->createToken('test')->plainTextToken;
    }
}
