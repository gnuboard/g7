<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Repositories\JsonConfigRepository;
use App\Services\DriverConnectionTester;
use App\Support\FileServing\FileDelegation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 환경설정 > 드라이버 「웹서버 파일 위임」 저장·카탈로그·시험·프로브 (#878)
 *
 * 저장 게이트(SaveSettingsRequest)가 모르는 방식·접두사 누락·형식 오류를 422 로 막고, 관리자 응답이 선택지
 * (`available_drivers.file_delegation`)와 웹서버 안내용 메타(`_meta.file_delegation`)를 싣는지, 1단계 시험
 * (test-driver)과 2단계 프로브(file-delegation-probe)가 약속한 모양으로 답하는지 확인합니다.
 *
 * @scenario-manifest tests/scenarios/file-serving-delegation.yaml
 */
class SettingsFileDelegationValidationTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    /**
     * 설정 읽기·쓰기 권한을 가진 관리자와 토큰을 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'core.file_serving.delegation' => FileDelegation::MODE_NONE,
            'core.file_serving.accel_prefix' => FileDelegation::DEFAULT_ACCEL_PREFIX,
        ]);

        $this->token = $this->createAdminUser()->createToken('test-token')->plainTextToken;
    }

    /**
     * 관리자 사용자를 만든다.
     *
     * @return User 설정 권한 역할을 단 관리자
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);

        $permissionIds = [];
        foreach (['core.settings.read', 'core.settings.update'] as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }

        $role = Role::create([
            'identifier' => 'admin_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Admin']),
            'description' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);

        $adminBaseRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $user->roles()->attach($adminBaseRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 인증 헤더를 실은 요청을 만든다.
     *
     * @return static Bearer 토큰 헤더를 실은 테스트 인스턴스
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 드라이버 탭 저장 본문 — 필수 칸을 채운 기본값에 덮어쓸 값을 얹는다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 drivers 값
     * @return array<string, mixed> drivers 본문
     */
    private function driversPayload(array $overrides = []): array
    {
        return array_merge([
            'storage_driver' => 'local',
            'cache_driver' => 'file',
            'session_driver' => 'file',
            'queue_driver' => 'sync',
            'log_driver' => 'daily',
            'log_level' => 'error',
        ], $overrides);
    }

    /**
     * 드라이버 탭을 저장한다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 drivers 값
     * @return TestResponse 응답
     */
    private function saveDrivers(array $overrides)
    {
        return $this->authRequest()->postJson('/api/admin/settings', [
            '_tab' => 'drivers',
            'drivers' => $this->driversPayload($overrides),
        ]);
    }

    /**
     * 모르는 방식·접두사 누락·형식 오류는 422 다.
     *
     * @scenario case=save_validation
     *
     * @effects invalid_delegation_settings_rejected_with_422
     *
     * @return void 반환값 없음
     */
    public function test_invalid_delegation_settings_are_rejected(): void
    {
        $this->saveDrivers(['file_delegation' => 'mod_xsendfile'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['drivers.file_delegation']);

        $this->saveDrivers(['file_delegation' => 'x-accel-redirect', 'file_delegation_accel_prefix' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['drivers.file_delegation_accel_prefix']);

        foreach (['no-leading-slash/', '/has space/', '/../escape/', '/a//b/'] as $prefix) {
            $this->saveDrivers(['file_delegation' => 'x-accel-redirect', 'file_delegation_accel_prefix' => $prefix])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['drivers.file_delegation_accel_prefix']);
        }
    }

    /**
     * 정상 값은 저장되고, 접두사는 끝 `/` 가 보정돼 남는다.
     *
     * @scenario case=save_validation
     *
     * @effects invalid_delegation_settings_rejected_with_422, stored_delegation_injects_core_config_with_normalization
     *
     * @return void 반환값 없음
     */
    public function test_valid_delegation_settings_are_saved(): void
    {
        $this->saveDrivers(['file_delegation' => 'x-accel-redirect', 'file_delegation_accel_prefix' => ' /protected-files '])
            ->assertStatus(200);

        $saved = (new JsonConfigRepository)->getCategory('drivers');
        $this->assertSame('x-accel-redirect', $saved['file_delegation'] ?? null);
        $this->assertSame('/protected-files/', $saved['file_delegation_accel_prefix'] ?? null);

        $this->saveDrivers(['file_delegation' => 'x-sendfile'])->assertStatus(200);
        $this->saveDrivers(['file_delegation' => 'none'])->assertStatus(200);

        $this->assertSame('none', (new JsonConfigRepository)->getCategory('drivers')['file_delegation'] ?? null);
    }

    /**
     * 관리자 응답이 선택지 3종과 웹서버 안내용 메타를 싣는다.
     *
     * @scenario case=available_drivers_meta
     *
     * @effects delegation_catalog_and_meta_exposed_to_admin
     *
     * @return void 반환값 없음
     */
    public function test_index_exposes_catalog_and_meta(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/settings')->assertStatus(200);

        $this->assertSame(
            ['none', 'x-sendfile', 'x-accel-redirect'],
            array_column((array) $response->json('data.available_drivers.file_delegation'), 'id')
        );

        $meta = (array) $response->json('data._meta.file_delegation');
        $this->assertSame('none', $meta['mode'] ?? null);
        $this->assertSame(FileDelegation::basePath(), $meta['base_path'] ?? null);
        // nginx 안내용 경로는 구분자가 `/` 다 — nginx 설정은 역슬래시를 이스케이프로 읽는다(Windows 실경로)
        $this->assertSame(str_replace('\\', '/', FileDelegation::basePath()), $meta['nginx_alias_path'] ?? null);
        $this->assertStringNotContainsString('\\', (string) ($meta['nginx_alias_path'] ?? ''));
        $this->assertSame('/_g7_sendfile/', $meta['accel_prefix'] ?? null);
        $this->assertSame(FileDelegation::basePath().DIRECTORY_SEPARATOR.'=/_g7_sendfile/', $meta['accel_mapping'] ?? null);
    }

    /**
     * 1단계 시험(test-driver)은 방식이 실렸을 때 `results.file_delegation` 에 판정·메시지·세부를 낸다.
     *
     * @scenario case=driver_test_shape
     *
     * @effects driver_test_reports_static_delegation_hint
     *
     * @return void 반환값 없음
     */
    public function test_driver_test_reports_file_delegation_result(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings/test-driver', [
            'file_delegation' => 'x-accel-redirect',
            'file_delegation_accel_prefix' => '/protected-files/',
        ])->assertStatus(200);

        $result = (array) $response->json('data.results.file_delegation');
        $this->assertIsBool($result['success'] ?? null);
        $this->assertIsString($result['message'] ?? null);
        $this->assertSame('x-accel-redirect', $result['details']['mode'] ?? null);
        $this->assertSame('/protected-files/', $result['details']['accel_prefix'] ?? null);
        $this->assertSame(FileDelegation::basePath().DIRECTORY_SEPARATOR.'=/protected-files/', $result['details']['accel_mapping'] ?? null);

        $this->authRequest()->postJson('/api/admin/settings/test-driver', ['file_delegation' => 'bogus'])
            ->assertStatus(422);

        // 저장하기 전 입력값(끝 `/` 없음)으로 점검해도 저장 게이트와 같이 다듬어 판정한다
        $unsaved = $this->authRequest()->postJson('/api/admin/settings/test-driver', [
            'file_delegation' => 'x-accel-redirect',
            'file_delegation_accel_prefix' => ' /protected-files ',
        ])->assertStatus(200);
        $this->assertSame('/protected-files/', $unsaved->json('data.results.file_delegation.details.accel_prefix'));

        // 다른 카드의 시험(키 없음)에는 이 판정이 끼지 않는다
        $other = $this->authRequest()->postJson('/api/admin/settings/test-driver', ['cache_driver' => 'file'])->assertStatus(200);
        $this->assertNull($other->json('data.results.file_delegation'));
    }

    /**
     * 정적 판정 — 확정 가능한 실패만 실패다(모듈 없음·웹서버 종류 불일치).
     *
     * @scenario case=driver_test_shape
     *
     * @effects driver_test_reports_static_delegation_hint
     *
     * @return void 반환값 없음
     */
    public function test_static_judgement_branches(): void
    {
        $tester = app(DriverConnectionTester::class);

        $this->assertTrue($tester->testFileDelegation(['file_delegation' => 'none'])['success']);

        $missing = $tester->testFileDelegation(['file_delegation' => 'x-sendfile'], 'Apache/2.4', ['core', 'mod_php']);
        $this->assertFalse($missing['success']);
        $this->assertFalse($missing['details']['module']);

        $ready = $tester->testFileDelegation(['file_delegation' => 'x-sendfile'], 'Apache/2.4', ['core', 'mod_xsendfile']);
        $this->assertTrue($ready['success']);
        $this->assertTrue($ready['details']['module']);

        $this->assertFalse($tester->testFileDelegation(['file_delegation' => 'x-sendfile'], 'nginx/1.27', null)['success']);
        $this->assertFalse($tester->testFileDelegation(['file_delegation' => 'x-accel-redirect'], 'Apache/2.4', null)['success']);

        $nginx = $tester->testFileDelegation(['file_delegation' => 'x-accel-redirect'], 'nginx/1.27', null);
        $this->assertTrue($nginx['success']);
        $this->assertSame('nginx', $nginx['details']['server']);
        $this->assertNull($nginx['details']['module']);

        $this->assertTrue($tester->testFileDelegation(['file_delegation' => 'x-sendfile'], '', null)['success'], '판정 불가는 실패가 아니라 2단계 안내다');
    }

    /**
     * 2단계 프로브는 코어 파일 헬퍼로 JSON 을 no-store 로 내고, 위임이 켜져 있으면 위임 헤더만 세운다.
     *
     * @scenario case=probe_route
     *
     * @effects probe_route_serves_json_through_file_helper
     *
     * @return void 반환값 없음
     */
    public function test_probe_route_serves_the_probe_file(): void
    {
        $response = $this->authRequest()->get('/api/admin/settings/file-delegation-probe');

        $response->assertOk();
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        ob_start();
        $response->baseResponse->sendContent();
        $body = json_decode((string) ob_get_clean(), true);
        $this->assertSame('g7-file-delegation', $body['data']['probe'] ?? null, '화면은 이 값으로 본문 수신을 판정한다');

        config(['core.file_serving.delegation' => FileDelegation::MODE_X_SENDFILE]);
        $delegated = $this->authRequest()->get('/api/admin/settings/file-delegation-probe');

        $delegated->assertOk();
        $this->assertSame(realpath(resource_path('probes/file-delegation-probe.json')), $delegated->headers->get('X-Sendfile'));
    }
}
