<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Listeners;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Modules\Sirsoft\Board\Listeners\UserNotificationSettingsListener;
use Modules\Sirsoft\Board\Models\UserNotificationSetting;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * UserNotificationSettingsListener 단위 테스트
 *
 * 검증 목적:
 * - getSubscribedHooks: 7개 훅 등록, filter 타입 6개 + action 타입 1개
 * - addValidationRules: 4개 알림 필드 규칙 추가
 * - filterCreateData: 알림 필드 분리 → 세션 저장 + 나머지 데이터 반환
 * - filterCreateData: 알림 필드 없을 때 세션 미저장
 * - filterCreateData: 미전송 알림 필드 → false로 채움
 * - filterUpdateData: 알림 필드 즉시 저장 + 데이터에서 제거
 * - filterUpdateData: 알림 필드 없고 기존 설정 없으면 저장 미발생
 * - filterUpdateData: 기존 설정 있으면 미전송 필드 false로 갱신
 * - afterCreate: 세션 데이터 → DB 저장 + 세션 삭제
 * - afterCreate: 세션 비어있으면 저장 미발생
 * - filterResourceData: 설정 있으면 실제 값 반환
 * - filterResourceData: 설정 없으면 기본값(false) 반환
 *
 * @group board
 * @group unit
 * @group listener
 */
class UserNotificationSettingsListenerTest extends ModuleTestCase
{
    private UserNotificationSettingsListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = app(UserNotificationSettingsListener::class);
    }

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Test User '.uniqid(),
            'email' => 'test'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    // ==========================================
    // getSubscribedHooks
    // ==========================================

    /**
     * 7개 훅이 등록되어 있어야 합니다.
     */
    public function test_subscribed_hooks_returns_seven_hooks(): void
    {
        $hooks = UserNotificationSettingsListener::getSubscribedHooks();
        $this->assertCount(7, $hooks);
    }

    /**
     * filter 타입 훅 6개, action 타입 훅 1개 (after_create)
     */
    public function test_subscribed_hooks_has_correct_types(): void
    {
        $hooks = UserNotificationSettingsListener::getSubscribedHooks();

        $filterHooks = array_filter($hooks, fn ($h) => ($h['type'] ?? '') === 'filter');
        $actionHooks = array_filter($hooks, fn ($h) => ! isset($h['type']));

        $this->assertCount(6, $filterHooks, 'filter 타입 훅은 6개여야 합니다.');
        $this->assertCount(1, $actionHooks, 'action 타입 훅(type 없음)은 1개여야 합니다.');
        $this->assertArrayHasKey('core.user.after_create', $hooks, 'after_create 훅이 등록되어야 합니다.');
    }

    // ==========================================
    // addValidationRules
    // ==========================================

    /**
     * addValidationRules: 4개 알림 필드 규칙을 기존 규칙에 추가합니다.
     */
    public function test_add_validation_rules_merges_notification_fields(): void
    {
        $existing = ['name' => 'required|string'];
        $result = $this->listener->addValidationRules($existing);

        $this->assertArrayHasKey('name', $result, '기존 규칙 유지');
        $this->assertArrayHasKey('notify_post_complete', $result);
        $this->assertArrayHasKey('notify_post_reply', $result);
        $this->assertArrayHasKey('notify_comment', $result);
        $this->assertArrayHasKey('notify_reply_comment', $result);
        $this->assertSame('nullable|boolean', $result['notify_post_complete']);
    }

    // ==========================================
    // filterCreateData
    // ==========================================

    /**
     * filterCreateData: 알림 필드가 요청에 있으면 세션에 저장하고 데이터에서 제거합니다.
     */
    public function test_filter_create_data_extracts_notification_fields_to_session(): void
    {
        $data = [
            'name' => '홍길동',
            'email' => 'hong@example.com',
            'notify_post_complete' => true,
            'notify_comment' => false,
        ];

        $result = $this->listener->filterCreateData($data);

        // 알림 필드 제거됨
        $this->assertArrayNotHasKey('notify_post_complete', $result);
        $this->assertArrayNotHasKey('notify_comment', $result);

        // 일반 필드 유지됨
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('email', $result);

        // 세션에 저장됨
        $sessionData = session('board_notification_data');
        $this->assertNotEmpty($sessionData);
        $this->assertTrue($sessionData['notify_post_complete']);
    }

    /**
     * filterCreateData: 알림 필드가 없으면 세션에 저장하지 않습니다.
     */
    public function test_filter_create_data_does_not_set_session_when_no_notification_fields(): void
    {
        session()->forget('board_notification_data');

        $data = ['name' => '홍길동', 'email' => 'hong@example.com'];
        $this->listener->filterCreateData($data);

        $this->assertNull(session('board_notification_data'));
    }

    /**
     * filterCreateData: 알림 필드 일부만 전송 시 미전송 필드는 false로 채웁니다.
     */
    public function test_filter_create_data_fills_missing_notification_fields_with_false(): void
    {
        $data = [
            'name' => '홍길동',
            'notify_post_complete' => true,
            // notify_post_reply, notify_comment, notify_reply_comment 미전송
        ];

        $this->listener->filterCreateData($data);

        $sessionData = session('board_notification_data');
        $this->assertFalse($sessionData['notify_post_reply']);
        $this->assertFalse($sessionData['notify_comment']);
        $this->assertFalse($sessionData['notify_reply_comment']);
    }

    // ==========================================
    // filterUpdateData
    // ==========================================

    /**
     * filterUpdateData: 알림 필드를 즉시 DB에 저장하고 데이터에서 제거합니다.
     */
    public function test_filter_update_data_saves_notification_fields_immediately(): void
    {
        $user = $this->makeUser();
        $data = [
            'name' => '수정된 이름',
            'notify_post_complete' => true,
            'notify_post_reply' => true,
        ];

        $result = $this->listener->filterUpdateData($data, $user);

        // 알림 필드 제거됨
        $this->assertArrayNotHasKey('notify_post_complete', $result);
        $this->assertArrayHasKey('name', $result);

        // DB에 저장됨
        $this->assertDatabaseHas('board_user_notification_settings', [
            'user_id' => $user->id,
            'notify_post_complete' => true,
        ]);
    }

    /**
     * filterUpdateData: 알림 필드 없고 기존 설정도 없으면 저장이 발생하지 않습니다.
     */
    public function test_filter_update_data_does_not_save_when_no_fields_and_no_existing(): void
    {
        $user = $this->makeUser();
        $data = ['name' => '수정된 이름'];

        $this->listener->filterUpdateData($data, $user);

        $this->assertDatabaseMissing('board_user_notification_settings', ['user_id' => $user->id]);
    }

    /**
     * filterUpdateData: 기존 설정이 있으면 미전송 알림 필드는 false로 갱신합니다.
     */
    public function test_filter_update_data_resets_missing_fields_to_false_when_existing(): void
    {
        $user = $this->makeUser();

        // 기존 설정 생성
        UserNotificationSetting::create([
            'user_id' => $user->id,
            'notify_post_complete' => true,
            'notify_post_reply' => true,
            'notify_comment' => true,
            'notify_reply_comment' => true,
        ]);

        // notify_post_complete만 전송 (나머지 체크박스 해제)
        $data = ['notify_post_complete' => true];
        $this->listener->filterUpdateData($data, $user);

        $this->assertDatabaseHas('board_user_notification_settings', [
            'user_id' => $user->id,
            'notify_post_complete' => true,
            'notify_post_reply' => false,
            'notify_comment' => false,
            'notify_reply_comment' => false,
        ]);
    }

    // ==========================================
    // afterCreate
    // ==========================================

    /**
     * afterCreate: 세션 데이터를 DB에 저장하고 세션을 삭제합니다.
     */
    public function test_after_create_saves_session_data_and_clears_session(): void
    {
        $user = $this->makeUser();
        session(['board_notification_data' => [
            'notify_post_complete' => true,
            'notify_post_reply' => false,
            'notify_comment' => true,
            'notify_reply_comment' => false,
        ]]);

        $this->listener->afterCreate($user, []);

        $this->assertDatabaseHas('board_user_notification_settings', [
            'user_id' => $user->id,
            'notify_post_complete' => true,
        ]);
        $this->assertNull(session('board_notification_data'), '세션이 삭제되어야 합니다.');
    }

    /**
     * afterCreate: 세션이 비어있으면 DB 저장이 발생하지 않습니다.
     */
    public function test_after_create_does_not_save_when_session_empty(): void
    {
        $user = $this->makeUser();
        session()->forget('board_notification_data');

        $this->listener->afterCreate($user, []);

        $this->assertDatabaseMissing('board_user_notification_settings', ['user_id' => $user->id]);
    }

    // ==========================================
    // filterResourceData
    // ==========================================

    /**
     * filterResourceData: 설정 레코드가 있으면 실제 값을 반환합니다.
     */
    public function test_filter_resource_data_returns_actual_settings_when_exists(): void
    {
        $user = $this->makeUser();
        UserNotificationSetting::create([
            'user_id' => $user->id,
            'notify_post_complete' => true,
            'notify_post_reply' => false,
            'notify_comment' => true,
            'notify_reply_comment' => true,
        ]);

        $result = $this->listener->filterResourceData(['name' => '홍길동'], $user);

        $this->assertTrue($result['notify_post_complete']);
        $this->assertFalse($result['notify_post_reply']);
        $this->assertTrue($result['notify_comment']);
        $this->assertArrayHasKey('name', $result, '기존 데이터 유지');
    }

    /**
     * filterResourceData: 설정 레코드가 없으면 모든 알림 필드를 false로 반환합니다.
     */
    public function test_filter_resource_data_returns_false_defaults_when_no_settings(): void
    {
        $user = $this->makeUser();

        $result = $this->listener->filterResourceData([], $user);

        $this->assertFalse($result['notify_post_complete']);
        $this->assertFalse($result['notify_post_reply']);
        $this->assertFalse($result['notify_comment']);
        $this->assertFalse($result['notify_reply_comment']);
    }
}
