<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Extension\PluginManager;
use App\Models\Permission;
use App\Models\Plugin;
use App\Models\Role;
use App\Models\User;
use App\Support\ExtensionStoragePath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * PluginSettingsController API 테스트
 *
 * 플러그인 설정 조회, 수정, 레이아웃 조회 API 엔드포인트를 테스트합니다.
 * 설정은 파일 기반으로 storage/app/plugins/{identifier}/settings/setting.json에 저장됩니다.
 */
class PluginSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    private PluginManager $pluginManager;

    /**
     * 각 테스트 전에 공통 픽스처를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
        $this->pluginManager = app(PluginManager::class);
    }

    /**
     * 관리자 역할 생성 및 할당
     *
     * @param  array  $permissions  사용자에게 부여할 권한 식별자 목록
     * @return User User 인스턴스
     */
    private function createAdminUser(array $permissions = ['core.plugins.read', 'core.plugins.update']): User
    {
        $user = User::factory()->create();

        // 권한 생성
        $permissionIds = [];
        foreach ($permissions as $permIdentifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $permIdentifier],
                [
                    'name' => json_encode(['ko' => $permIdentifier, 'en' => $permIdentifier]),
                    'description' => json_encode(['ko' => $permIdentifier.' 권한', 'en' => $permIdentifier.' Permission']),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            );
            $permissionIds[] = $permission->id;
        }

        // 고유한 식별자로 테스트용 역할 생성
        $roleIdentifier = 'admin_settings_test_'.uniqid();
        $testRole = Role::create([
            'identifier' => $roleIdentifier,
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'description' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'is_active' => true,
        ]);

        // admin 역할도 추가 (admin 미들웨어 통과용)
        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        // 테스트용 역할에 권한 할당
        $testRole->permissions()->sync($permissionIds);

        // 사용자에게 admin 역할과 테스트용 역할 모두 할당
        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);
        $user->roles()->attach($testRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        return $user->fresh();
    }

    /**
     * 인증된 요청 헬퍼 메서드
     *
     * @return static 체이닝용 자기 자신
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 테스트용 플러그인 생성 (DB 레코드만)
     *
     * @param  $identifier  식별자
     * @return Plugin Plugin 인스턴스
     */
    private function createTestPlugin(string $identifier = 'test-settings-plugin'): Plugin
    {
        return Plugin::create([
            'identifier' => $identifier,
            'vendor' => 'test',
            'name' => json_encode(['ko' => '테스트 플러그인', 'en' => 'Test Plugin']),
            'version' => '1.0.0',
            'description' => json_encode(['ko' => '테스트용 플러그인', 'en' => 'Test Plugin']),
            'status' => 'active',
        ]);
    }

    /**
     * 테스트용 설정 파일 생성
     *
     * @param  $identifier  식별자
     * @param  $settings  설정 배열
     * @return void 반환값 없음
     */
    private function createTestSettingsFile(string $identifier, array $settings): void
    {
        $settingsDir = ExtensionStoragePath::plugin($identifier, 'settings');
        $settingsPath = $settingsDir.'/setting.json';

        if (! File::isDirectory($settingsDir)) {
            File::makeDirectory($settingsDir, 0755, true);
        }

        File::put($settingsPath, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 테스트용 설정 파일 삭제
     *
     * @param  $identifier  식별자
     * @return void 반환값 없음
     */
    private function cleanupTestSettings(string $identifier): void
    {
        $pluginDir = ExtensionStoragePath::plugin($identifier);
        if (File::isDirectory($pluginDir)) {
            File::deleteDirectory($pluginDir);
        }
    }

    // ========================================================================
    // 인증/권한 테스트
    // ========================================================================

    /**
     * 인증 없이 설정 조회 시 401 반환
     *
     * @scenario endpoint=show,authentication=none
     *
     * @effects show_without_authentication_returns_401
     *
     * @return void 반환값 없음
     */
    public function test_show_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/plugins/test-plugin/settings');

        $response->assertStatus(401);
    }

    /**
     * 권한 없이 설정 조회 시 403 반환
     *
     * @scenario endpoint=show,authentication=authenticated,permission=denied
     *
     * @effects show_without_permission_returns_403
     *
     * @return void 반환값 없음
     */
    public function test_show_returns_403_without_permission(): void
    {
        // 권한 없는 관리자 생성
        $user = User::factory()->create();
        $adminRole = Role::where('identifier', 'admin')->first();

        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/plugins/test-plugin/settings');

        $response->assertStatus(403);
    }

    /**
     * 인증 없이 설정 업데이트 시 401 반환
     *
     * @scenario endpoint=update,authentication=none
     *
     * @effects update_without_authentication_returns_401
     *
     * @return void 반환값 없음
     */
    public function test_update_returns_401_without_authentication(): void
    {
        $response = $this->putJson('/api/admin/plugins/test-plugin/settings', [
            'key' => 'value',
        ]);

        $response->assertStatus(401);
    }

    /**
     * 권한 없이 설정 업데이트 시 403 반환
     *
     * @scenario endpoint=update,authentication=authenticated,permission=denied
     *
     * @effects update_without_permission_returns_403
     *
     * @return void 반환값 없음
     */
    public function test_update_returns_403_without_permission(): void
    {
        // read 권한만 있는 관리자 생성
        $user = $this->createAdminUser(['core.plugins.read']);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->putJson('/api/admin/plugins/test-plugin/settings', [
            'key' => 'value',
        ]);

        $response->assertStatus(403);
    }

    /**
     * 인증 없이 레이아웃 조회 시 401 반환
     *
     * @scenario endpoint=layout,authentication=none
     *
     * @effects layout_without_authentication_returns_401
     *
     * @return void 반환값 없음
     */
    public function test_layout_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/plugins/test-plugin/settings/layout');

        $response->assertStatus(401);
    }

    // ========================================================================
    // show() 테스트 - 설정 조회
    // ========================================================================

    /**
     * 존재하지 않는 플러그인 설정 조회 시 404 반환
     *
     * @scenario endpoint=show,plugin_existence=nonexistent
     *
     * @effects show_for_nonexistent_plugin_returns_404
     *
     * @return void 반환값 없음
     */
    public function test_show_returns_404_for_nonexistent_plugin(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/plugins/nonexistent-plugin/settings');

        $response->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    /**
     * 실제 설치된 Daum 우편번호 플러그인 설정 조회 테스트
     *
     * @scenario endpoint=show,plugin_existence=installed,permission=granted
     *
     * @effects show_returns_settings_for_installed_plugin
     *
     * @return void 반환값 없음
     */
    public function test_show_returns_settings_for_installed_plugin(): void
    {
        // Daum 우편번호 플러그인이 설치되어 있는지 확인
        $pluginInstance = $this->pluginManager->getPlugin('sirsoft-daum_postcode');

        if (! $pluginInstance) {
            $this->markTestSkipped('sirsoft-daum_postcode plugin not installed');
        }

        // DB에 플러그인 레코드가 있는지 확인
        $plugin = Plugin::where('identifier', 'sirsoft-daum_postcode')->first();
        if (! $plugin) {
            $this->createTestPlugin('sirsoft-daum_postcode');
        }

        $response = $this->authRequest()->getJson('/api/admin/plugins/sirsoft-daum_postcode/settings');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // 기본 설정값이 반환되는지 확인
        $data = $response->json('data');
        $this->assertIsArray($data);
    }

    // ========================================================================
    // update() 테스트 - 설정 업데이트
    // ========================================================================

    /**
     * 존재하지 않는 플러그인 설정 업데이트 시 에러 반환
     *
     * @scenario endpoint=update,plugin_existence=nonexistent
     *
     * @effects update_for_nonexistent_plugin_returns_error
     *
     * @return void 반환값 없음
     */
    public function test_update_returns_error_for_nonexistent_plugin(): void
    {
        $response = $this->authRequest()->putJson('/api/admin/plugins/nonexistent-plugin/settings', [
            'key' => 'value',
        ]);

        // 플러그인 인스턴스가 없으면 저장 실패
        $response->assertStatus(500)
            ->assertJson(['success' => false]);
    }

    /**
     * 실제 설치된 플러그인 설정 업데이트 테스트
     *
     * @scenario endpoint=update,plugin_existence=installed,payload_scope=within_schema
     *
     * @effects update_saves_settings_for_installed_plugin
     *
     * @return void 반환값 없음
     */
    public function test_update_saves_settings_for_installed_plugin(): void
    {
        $pluginInstance = $this->pluginManager->getPlugin('sirsoft-daum_postcode');

        if (! $pluginInstance) {
            $this->markTestSkipped('sirsoft-daum_postcode plugin not installed');
        }

        // DB에 플러그인 레코드 생성
        $plugin = Plugin::where('identifier', 'sirsoft-daum_postcode')->first();
        if (! $plugin) {
            $this->createTestPlugin('sirsoft-daum_postcode');
        }

        // 플러그인이 선언한 설정 스키마의 실제 키를 사용한다.
        // 스키마 밖의 키는 검증을 통과하지 못해 저장되지 않는다.
        $response = $this->authRequest()->putJson('/api/admin/plugins/sirsoft-daum_postcode/settings', [
            'display_mode' => 'popup',
            'popup_width' => 640,
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // 파일에서 값 확인
        $settingsPath = ExtensionStoragePath::plugin('sirsoft-daum_postcode', 'settings').'/setting.json';
        // 저장이 200 으로 끝났으므로 파일은 반드시 있어야 한다 — `File::exists` 조건 안에
        // 단언을 두면 경로가 어긋났을 때 검사가 조용히 공허해진다.
        $this->assertFileExists($settingsPath);

        $savedContent = json_decode(File::get($settingsPath), true);
        $this->assertSame('popup', $savedContent['display_mode'] ?? null);
        $this->assertSame(640, $savedContent['popup_width'] ?? null);

        // 정리
        $this->cleanupTestSettings('sirsoft-daum_postcode');
    }

    /**
     * 스키마에 없는 필드는 저장되지 않음 (mass-assignment 방지)
     *
     * 과거 구현은 `validated()` 가 비면 `$request->all()` 로 폴백했다. 폴백의 명분이던
     * "PluginManager 미등록 플러그인" 은 `PluginSettingsService::save()` 가 이미 false 로
     * 차단하므로 도달할 수 없고, 실제로는 스키마 밖 키만 담긴 요청이 그대로 설정 파일에
     * 병합되는 경로로만 동작했다.
     *
     * @scenario endpoint=update,plugin_existence=installed,payload_scope=outside_schema
     *
     * @effects update_ignores_fields_outside_settings_schema
     *
     * @return void 반환값 없음
     */
    public function test_update_ignores_fields_outside_settings_schema(): void
    {
        $pluginInstance = $this->pluginManager->getPlugin('sirsoft-daum_postcode');

        if (! $pluginInstance) {
            $this->markTestSkipped('sirsoft-daum_postcode plugin not installed');
        }

        $plugin = Plugin::where('identifier', 'sirsoft-daum_postcode')->first();
        if (! $plugin) {
            $this->createTestPlugin('sirsoft-daum_postcode');
        }

        // 스키마에 정의되지 않은 키만 담아 전송 → validated() 가 빈 배열이 되는 조건
        $this->authRequest()->putJson('/api/admin/plugins/sirsoft-daum_postcode/settings', [
            'injected_field' => 'evil',
            'another_unknown' => ['nested' => true],
        ]);

        $settingsPath = ExtensionStoragePath::plugin('sirsoft-daum_postcode', 'settings').'/setting.json';
        // 스키마 밖 키만 담긴 요청은 저장 자체가 일어나지 않을 수 있다. 그 경우에도 단언은
        // 수행한다 — 조건 안에 두면 파일이 없을 때 검사가 0건이 되어 공허하게 통과한다.
        $savedContent = File::exists($settingsPath)
            ? (json_decode(File::get($settingsPath), true) ?? [])
            : [];

        $this->assertArrayNotHasKey('injected_field', $savedContent);
        $this->assertArrayNotHasKey('another_unknown', $savedContent);

        $this->cleanupTestSettings('sirsoft-daum_postcode');
    }

    // ========================================================================
    // layout() 테스트 - 레이아웃 조회
    // ========================================================================

    /**
     * 존재하지 않는 플러그인 레이아웃 조회 시 404 반환
     *
     * @scenario endpoint=layout,plugin_existence=nonexistent
     *
     * @effects layout_for_nonexistent_plugin_returns_404
     *
     * @return void 반환값 없음
     */
    public function test_layout_returns_404_for_nonexistent_plugin(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/plugins/nonexistent-plugin/settings/layout');

        $response->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    /**
     * Daum 우편번호 플러그인 레이아웃 조회 성공
     *
     * @scenario endpoint=layout,plugin_existence=installed,permission=granted
     *
     * @effects layout_returns_layout_for_installed_plugin
     *
     * @return void 반환값 없음
     */
    public function test_layout_returns_layout_for_daum_postcode_plugin(): void
    {
        $layoutPath = base_path('plugins/sirsoft-daum_postcode/resources/layouts/admin/plugin_settings.json');

        if (! file_exists($layoutPath)) {
            $this->markTestSkipped('Daum postcode plugin layout file not found');
        }

        $pluginInstance = $this->pluginManager->getPlugin('sirsoft-daum_postcode');

        if (! $pluginInstance) {
            $this->markTestSkipped('sirsoft-daum_postcode plugin not installed');
        }

        // DB에 플러그인 레코드 생성
        $plugin = Plugin::where('identifier', 'sirsoft-daum_postcode')->first();
        if (! $plugin) {
            $this->createTestPlugin('sirsoft-daum_postcode');
        }

        $response = $this->authRequest()->getJson('/api/admin/plugins/sirsoft-daum_postcode/settings/layout');

        // `schema` 는 레이아웃 계약에 없다 — 번들 플러그인 12개 중 어느 것도 이 키를 내보내지
        // 않으며(2026-09-15 전수 실측), 설정 폼은 `slots` 안의 컴포넌트가 그린다. 종전 단언은
        // 존재한 적 없는 키를 요구해 이 테스트를 상시 red 로 두고 있었다.
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'version',
                    'meta',
                    'slots',
                ],
            ]);
    }

    // ========================================================================
    // 응답 구조 테스트
    // ========================================================================

    /**
     * 설정 조회 응답 구조 검증
     *
     * @scenario endpoint=show,plugin_existence=installed,permission=granted
     *
     * @effects show_response_has_documented_structure
     *
     * @return void 반환값 없음
     */
    public function test_show_response_structure(): void
    {
        $pluginInstance = $this->pluginManager->getPlugin('sirsoft-daum_postcode');

        if (! $pluginInstance) {
            $this->markTestSkipped('sirsoft-daum_postcode plugin not installed');
        }

        // DB에 플러그인 레코드 생성
        $plugin = Plugin::where('identifier', 'sirsoft-daum_postcode')->first();
        if (! $plugin) {
            $this->createTestPlugin('sirsoft-daum_postcode');
        }

        $response = $this->authRequest()->getJson('/api/admin/plugins/sirsoft-daum_postcode/settings');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
            ])
            ->assertJson([
                'success' => true,
            ]);
    }

    /**
     * 설정 조회 응답에 저장 권한(abilities.can_update)이 실린다 — 저장 권한 보유자
     *
     * 플러그인 설정 화면은 이 키로 읽기 전용 여부를 정한다. 키가 없으면 화면이 쓰기 권한을
     * 알 길이 없어 읽기만 가진 운영자에게도 입력·저장이 살아 있었다.
     *
     * @scenario endpoint=show,plugin_existence=installed,permission=granted
     *
     * @effects show_response_includes_abilities_can_update_true
     *
     * @return void 반환값 없음
     */
    public function test_show_includes_can_update_true_for_update_holder(): void
    {
        $this->ensureDaumPostcodePlugin();

        $response = $this->authRequest()->getJson('/api/admin/plugins/sirsoft-daum_postcode/settings');

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.abilities.can_update'));
    }

    /**
     * 설정 조회 응답의 abilities.can_update 는 조회 권한만 가진 운영자에게 false 다
     *
     * @scenario endpoint=show,plugin_existence=installed,permission=read_only
     *
     * @effects show_response_includes_abilities_can_update_false
     *
     * @return void 반환값 없음
     */
    public function test_show_includes_can_update_false_for_read_only(): void
    {
        $this->ensureDaumPostcodePlugin();

        $user = $this->createAdminUser(['core.plugins.read']);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/plugins/sirsoft-daum_postcode/settings');

        $response->assertStatus(200);
        $this->assertFalse($response->json('data.abilities.can_update'));
    }

    /**
     * 저장 요청 본문의 abilities 키는 설정 파일에 남지 않는다
     *
     * 화면은 조회 응답을 그대로 폼에 담아 다시 보내므로 abilities 가 저장 본문에 섞인다.
     * 권한 값은 서버가 매번 계산하는 것이라 저장하면 안 된다.
     *
     * @scenario endpoint=update,plugin_existence=installed,payload_scope=with_abilities
     *
     * @effects update_discards_abilities_key
     *
     * @return void 반환값 없음
     */
    public function test_update_discards_abilities_key(): void
    {
        $this->ensureDaumPostcodePlugin();

        $response = $this->authRequest()->putJson('/api/admin/plugins/sirsoft-daum_postcode/settings', [
            'display_mode' => 'popup',
            'abilities' => ['can_update' => true],
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.abilities.can_update'));

        $settingsPath = ExtensionStoragePath::plugin('sirsoft-daum_postcode', 'settings').'/setting.json';
        $this->assertFileExists($settingsPath);
        $savedContent = json_decode(File::get($settingsPath), true);
        $this->assertArrayNotHasKey('abilities', $savedContent);

        $this->cleanupTestSettings('sirsoft-daum_postcode');
    }

    /**
     * Daum 우편번호 플러그인이 등록돼 있는지 확인하고 DB 레코드를 맞춘다.
     *
     * @return void 반환값 없음
     */
    private function ensureDaumPostcodePlugin(): void
    {
        if (! $this->pluginManager->getPlugin('sirsoft-daum_postcode')) {
            $this->markTestSkipped('sirsoft-daum_postcode plugin not installed');
        }

        if (! Plugin::where('identifier', 'sirsoft-daum_postcode')->exists()) {
            $this->createTestPlugin('sirsoft-daum_postcode');
        }
    }

    /**
     * 설정 업데이트 응답에 업데이트된 설정 포함
     *
     * @scenario endpoint=update,plugin_existence=installed,payload_scope=within_schema
     *
     * @effects update_response_includes_updated_settings
     *
     * @return void 반환값 없음
     */
    public function test_update_response_includes_updated_settings(): void
    {
        $pluginInstance = $this->pluginManager->getPlugin('sirsoft-daum_postcode');

        if (! $pluginInstance) {
            $this->markTestSkipped('sirsoft-daum_postcode plugin not installed');
        }

        // DB에 플러그인 레코드 생성
        $plugin = Plugin::where('identifier', 'sirsoft-daum_postcode')->first();
        if (! $plugin) {
            $this->createTestPlugin('sirsoft-daum_postcode');
        }

        $response = $this->authRequest()->putJson('/api/admin/plugins/sirsoft-daum_postcode/settings', [
            'display_mode' => 'popup',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        // 응답에 업데이트된 설정이 포함되어 있는지 확인
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertArrayHasKey('display_mode', $data);
        $this->assertSame('popup', $data['display_mode']);

        // 정리
        $this->cleanupTestSettings('sirsoft-daum_postcode');
    }

    /**
     * 우편번호 팝업 크기는 스키마의 범위(min·max) 밖이면 422 로 거절하고, 설정 파일을 만들지 않는다.
     *
     * 종전 스키마는 정수 여부만 걸어 너비 -10 도 저장됐다(7.1.0 실측 M1-O11). 숫자 칸 둘(너비·높이) 모두 본다.
     *
     * @scenario endpoint=update,plugin_existence=installed,payload_scope=out_of_range
     *
     * @effects update_rejects_out_of_range_plugin_setting
     *
     * @return void 반환값 없음
     */
    public function test_update_rejects_out_of_range_postcode_popup_size(): void
    {
        if (! $this->pluginManager->getPlugin('sirsoft-daum_postcode')) {
            $this->markTestSkipped('sirsoft-daum_postcode plugin not installed');
        }
        if (! Plugin::where('identifier', 'sirsoft-daum_postcode')->exists()) {
            $this->createTestPlugin('sirsoft-daum_postcode');
        }

        // 개발 사이트와 같은 저장 경로를 쓰므로, 시작 때 없던 설정 파일은 끝나면 지운다(수정 전 실행이 200 으로 저장해도 남지 않게)
        $settingsDir = ExtensionStoragePath::plugin('sirsoft-daum_postcode');
        $existedBefore = File::isDirectory($settingsDir);

        try {
            app()->setLocale('ko');
            foreach (['popup_width', 'popup_height'] as $field) {
                foreach ([-10, 0, 99, 2001] as $value) {
                    $response = $this->authRequest()->withHeaders(['Accept-Language' => 'ko'])
                        ->putJson('/api/admin/plugins/sirsoft-daum_postcode/settings', [$field => $value]);

                    $response->assertStatus(422)->assertJsonValidationErrors($field);
                    $message = $response->json('errors.'.$field.'.0');
                    $this->assertStringContainsString($field === 'popup_width' ? '팝업 너비' : '팝업 높이', $message);
                    $this->assertMatchesRegularExpression('/100|2000/', $message);
                }
            }

            $this->authRequest()->putJson('/api/admin/plugins/sirsoft-daum_postcode/settings', ['popup_width' => 100, 'popup_height' => 2000])
                ->assertStatus(200);
        } finally {
            if (! $existedBefore) {
                $this->cleanupTestSettings('sirsoft-daum_postcode');
            }
        }
    }
}
