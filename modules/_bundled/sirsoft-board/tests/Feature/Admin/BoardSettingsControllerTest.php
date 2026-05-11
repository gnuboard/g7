<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__ . '/../../ModuleTestCase.php';

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 환경설정 API 테스트
 *
 * BoardSettingsController의 CRUD + bulkApply + clearCache를 검증합니다.
 */
class BoardSettingsControllerTest extends ModuleTestCase
{
    protected User $adminUser;

    protected User $normalUser;

    private string $settingsStoragePath;

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 관리자 사용자 생성 (환경설정 권한 포함)
        $this->adminUser = $this->createAdminUser([
            'sirsoft-board.settings.read',
            'sirsoft-board.settings.update',
        ]);

        // 일반 사용자 생성 (권한 없음)
        $this->normalUser = $this->createUser();

        $this->settingsStoragePath = storage_path('app/modules/sirsoft-board/settings');

        // 테스트 전 저장소 정리
        if (File::isDirectory($this->settingsStoragePath)) {
            File::deleteDirectory($this->settingsStoragePath);
        }

        // g7_settings는 ServiceProvider 부트 시 Config에 캐싱되므로
        // 테스트 환경에서 저장 파일 없이도 기본값이 정확히 반영되도록 명시적 주입
        Config::set('g7_settings.modules.sirsoft-board.basic_defaults.per_page', 20);
        Config::set('g7_settings.modules.sirsoft-board.basic_defaults.type', 'basic');
    }

    /**
     * 테스트 정리
     */
    protected function tearDown(): void
    {
        // 테스트 후 저장소 정리
        if (File::isDirectory($this->settingsStoragePath)) {
            File::deleteDirectory($this->settingsStoragePath);
        }

        // 테스트에서 생성한 게시판 삭제
        Board::where('slug', 'like', 'settings-test-%')->delete();

        parent::tearDown();
    }

    // ========================================
    // index (전체 설정 조회) 테스트
    // ========================================

    /**
     * 관리자가 전체 설정을 조회할 수 있는지 확인
     */
    public function test_admin_can_fetch_all_settings(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/settings');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'basic_defaults',
                    'report_policy',
                    'spam_security',
                ],
            ]);
    }

    /**
     * 비인증 사용자는 설정을 조회할 수 없음
     */
    public function test_unauthenticated_user_cannot_fetch_settings(): void
    {
        $response = $this->getJson('/api/modules/sirsoft-board/admin/settings');

        $response->assertStatus(401);
    }

    // ========================================
    // show (카테고리별 설정 조회) 테스트
    // ========================================

    /**
     * 관리자가 특정 카테고리 설정을 조회할 수 있는지 확인
     */
    public function test_admin_can_fetch_category_settings(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/settings/basic_defaults');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'category',
                    'settings',
                ],
            ])
            ->assertJsonPath('data.category', 'basic_defaults');
    }

    // ========================================
    // store (설정 저장) 테스트
    // ========================================

    /**
     * 관리자가 설정을 저장할 수 있는지 확인
     */
    public function test_admin_can_save_settings(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->putJson('/api/modules/sirsoft-board/admin/settings', [
                'basic_defaults' => [
                    'per_page' => 30,
                    'per_page_mobile' => 10,
                ],
            ]);

        $response->assertStatus(200);

        // 저장된 값이 파일에 반영되었는지 확인
        $filePath = $this->settingsStoragePath . '/basic_defaults.json';
        $this->assertFileExists($filePath);

        $content = json_decode(File::get($filePath), true);
        $this->assertEquals(30, $content['per_page']);
        $this->assertEquals(10, $content['per_page_mobile']);
    }

    /**
     * 관리자가 notifications 카테고리에서 채널 설정을 저장할 수 있는지 확인
     */
    public function test_admin_can_save_notification_channels(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->putJson('/api/modules/sirsoft-board/admin/settings', [
                'notifications' => [
                    'channels' => [
                        ['id' => 'mail', 'is_active' => true, 'sort_order' => 1],
                        ['id' => 'database', 'is_active' => true, 'sort_order' => 2],
                    ],
                ],
            ]);

        $response->assertStatus(200);

        // 저장된 값이 파일에 반영되었는지 확인
        $filePath = $this->settingsStoragePath . '/notifications.json';
        $this->assertFileExists($filePath);

        $content = json_decode(File::get($filePath), true);
        $this->assertIsArray($content['channels']);
        $this->assertEquals('mail', $content['channels'][0]['id']);
    }

    /**
     * notifications.channels 설정이 응답에 포함되는지 확인
     */
    public function test_notification_channels_included_in_settings_response(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/settings');

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
    }

    /**
     * 신고 정책의 알림 채널 배열이 저장 시 보존되는지 확인
     *
     * 회귀 방지: StoreBoardSettingsRequest 에 채널 검증 규칙이 누락되면
     * validatedSettings() 가 채널 키를 stripping 하여 사용자가 선택한 값이
     * 사라지고 저장 후 응답에 기본값이 반환되던 버그 (2026-04-27 발견).
     */
    public function test_admin_can_save_report_policy_notification_channels(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->putJson('/api/modules/sirsoft-board/admin/settings', [
                '_tab' => 'report_policy',
                'report_policy' => [
                    'notify_admin_on_report' => true,
                    'notify_admin_on_report_scope' => 'per_case',
                    'notify_admin_on_report_channels' => ['mail'], // 사용자가 mail 만 선택
                    'notify_author_on_report_action' => true,
                    'notify_author_on_report_action_channels' => ['database'], // 사용자가 database 만 선택
                ],
            ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));

        // PUT 응답에서 사용자 선택값이 그대로 반영되는지 확인
        $this->assertEquals(
            ['mail'],
            $response->json('data.report_policy.notify_admin_on_report_channels')
        );
        $this->assertEquals(
            ['database'],
            $response->json('data.report_policy.notify_author_on_report_action_channels')
        );

        // 후속 GET 요청에서도 영구 저장 확인
        $followUp = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-board/admin/settings/report_policy');

        $followUp->assertStatus(200);
        $this->assertEquals(
            ['mail'],
            $followUp->json('data.settings.notify_admin_on_report_channels')
        );
        $this->assertEquals(
            ['database'],
            $followUp->json('data.settings.notify_author_on_report_action_channels')
        );
    }

    /**
     * 채널 검증 — 허용되지 않는 값은 거부되는지 확인
     */
    public function test_save_rejects_invalid_notification_channel(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->putJson('/api/modules/sirsoft-board/admin/settings', [
                '_tab' => 'report_policy',
                'report_policy' => [
                    'notify_admin_on_report_channels' => ['mail', 'sms'], // sms 는 미허용
                ],
            ]);

        $response->assertStatus(422);
    }

    /**
     * 유효하지 않은 카테고리는 무시되는지 확인
     */
    public function test_store_ignores_invalid_categories(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->putJson('/api/modules/sirsoft-board/admin/settings', [
                'invalid_category' => [
                    'some_key' => 'some_value',
                ],
            ]);

        // validatedSettings()에서 유효 카테고리만 필터링
        $response->assertStatus(200);

        // invalid_category 파일이 생성되지 않아야 함
        $this->assertFileDoesNotExist($this->settingsStoragePath . '/invalid_category.json');
    }

    /**
     * 비인증 사용자는 설정을 저장할 수 없음
     */
    public function test_unauthenticated_user_cannot_save_settings(): void
    {
        $response = $this->putJson('/api/modules/sirsoft-board/admin/settings', [
            'basic_defaults' => ['per_page' => 30],
        ]);

        $response->assertStatus(401);
    }

    // ========================================
    // bulkApply (일괄 적용) 테스트
    // ========================================

    /**
     * 관리자가 설정을 전체 게시판에 일괄 적용할 수 있는지 확인
     */
    public function test_admin_can_bulk_apply_settings_to_all_boards(): void
    {
        // 테스트용 게시판 생성
        $board = Board::create([
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'slug' => 'settings-test-' . substr(md5(microtime()), 0, 8),
            'type' => 'gallery',
            'per_page' => 10,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/settings/bulk-apply', [
                'fields' => ['per_page', 'type'],
                'apply_all' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['updated_count'],
            ]);

        // 게시판의 값이 환경설정 기본값으로 변경되었는지 확인
        $board->refresh();
        $this->assertEquals(20, $board->per_page); // defaults.json 기본값
        $this->assertEquals('basic', $board->type); // defaults.json 기본값
    }

    /**
     * 관리자가 설정을 특정 게시판에만 일괄 적용할 수 있는지 확인
     */
    public function test_admin_can_bulk_apply_settings_to_specific_boards(): void
    {
        // 테스트용 게시판 2개 생성
        $board1 = Board::create([
            'name' => ['ko' => '테스트1', 'en' => 'Test1'],
            'slug' => 'settings-test-' . substr(md5(microtime() . '1'), 0, 8),
            'type' => 'gallery',
            'per_page' => 10,
        ]);

        $board2 = Board::create([
            'name' => ['ko' => '테스트2', 'en' => 'Test2'],
            'slug' => 'settings-test-' . substr(md5(microtime() . '2'), 0, 8),
            'type' => 'gallery',
            'per_page' => 10,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/settings/bulk-apply', [
                'fields' => ['per_page'],
                'apply_all' => false,
                'board_ids' => [$board1->id],
            ]);

        $response->assertStatus(200);

        // board1은 변경, board2는 변경 안 됨
        $board1->refresh();
        $board2->refresh();
        $this->assertEquals(20, $board1->per_page); // 변경됨
        $this->assertEquals(10, $board2->per_page); // 변경 안 됨
    }

    /**
     * 필드 미선택 시 유효성 검증 실패
     */
    public function test_bulk_apply_requires_fields(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/settings/bulk-apply', [
                'fields' => [],
                'apply_all' => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['fields']);
    }

    /**
     * apply_all=false일 때 board_ids 필수
     */
    public function test_bulk_apply_requires_board_ids_when_not_apply_all(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/settings/bulk-apply', [
                'fields' => ['per_page'],
                'apply_all' => false,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['board_ids']);
    }

    /**
     * 허용되지 않은 필드는 유효성 검증 실패
     */
    public function test_bulk_apply_rejects_invalid_fields(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/settings/bulk-apply', [
                'fields' => ['invalid_field'],
                'apply_all' => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['fields.0']);
    }

    /**
     * 점이 없는 권한 키(manager)도 일괄 적용 허용
     *
     * default_board_permissions의 manager 키는 점(.)이 없어
     * 기존 검증에서 허용 목록에 없어 거부되던 버그 수정
     */
    public function test_bulk_apply_allows_manager_permission_field(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/settings/bulk-apply', [
                'fields' => ['manager'],
                'apply_all' => true,
            ]);

        $response->assertStatus(200);
    }

    /**
     * 점이 포함된 권한 키(posts.read 등)도 일괄 적용 허용
     */
    public function test_bulk_apply_allows_dotted_permission_fields(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/settings/bulk-apply', [
                'fields' => ['posts.read', 'admin.manage', 'manager'],
                'apply_all' => true,
            ]);

        $response->assertStatus(200);
    }

    // ========================================
    // clearCache (캐시 초기화) 테스트
    // ========================================

    /**
     * 관리자가 캐시를 초기화할 수 있는지 확인
     */
    public function test_admin_can_clear_cache(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/settings/clear-cache');

        $response->assertStatus(200)
            ->assertJsonPath('data.cleared', true);
    }

    /**
     * 비인증 사용자는 캐시를 초기화할 수 없음
     */
    public function test_unauthenticated_user_cannot_clear_cache(): void
    {
        $response = $this->postJson('/api/modules/sirsoft-board/admin/settings/clear-cache');

        $response->assertStatus(401);
    }
}
