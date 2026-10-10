<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Listeners;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Modules\Sirsoft\Board\Listeners\UserNotificationSettingsListener;
use Modules\Sirsoft\Board\Models\UserNotificationSetting;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * UserNotificationSettingsListener 단위 테스트
 *
 * 검증 목적:
 * - getSubscribedHooks: 7개 훅 등록, filter 타입 6개 + action 타입 1개
 * - addValidationRules: 4개 알림 필드 규칙 추가
 * - filterCreateData: 알림 필드 제거 + 나머지 데이터 반환
 * - filterCreateData: 세션을 쓰지 않음(생성 후 액션은 큐 워커에서 돌 수 있음)
 * - afterCreate: 미전송 알림 필드 → false로 채움
 * - filterUpdateData: 알림 필드 즉시 저장 + 데이터에서 제거
 * - filterUpdateData: 알림 필드 없고 기존 설정 없으면 저장 미발생
 * - filterUpdateData: 기존 설정 있으면 미전송 필드 false로 갱신
 * - afterCreate: 원본 요청 데이터(훅 인자) → DB 저장
 * - afterCreate: 알림 필드 없으면 저장 미발생
 * - filterResourceData: 설정 있으면 실제 값 반환
 * - filterResourceData: 설정 없으면 기본값(false) 반환
 */
#[Group('board')]
#[Group('unit')]
#[Group('listener')]
class UserNotificationSettingsListenerTest extends ModuleTestCase
{
    private UserNotificationSettingsListener $listener;

    /**
     * 테스트 대상 리스너를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = app(UserNotificationSettingsListener::class);
    }

    /**
     * 테스트용 회원을 만듭니다.
     *
     * @return User 생성된 회원
     */
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
     *
     * @scenario case=subscription_contract
     *
     * @effects subscribes_user_lifecycle_hooks
     *
     * @return void 반환값 없음
     */
    public function test_subscribed_hooks_returns_seven_hooks(): void
    {
        $hooks = UserNotificationSettingsListener::getSubscribedHooks();
        $this->assertCount(7, $hooks);
    }

    /**
     * filter 타입 훅 6개, action 타입 훅 1개 (after_create)
     *
     * @scenario case=subscription_contract
     *
     * @effects subscribes_user_lifecycle_hooks
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=validation_rules
     *
     * @effects notification_fields_added_to_rules
     *
     * @return void 반환값 없음
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
     * filterCreateData: 알림 필드를 회원 생성 데이터에서 빼고 일반 필드는 둡니다.
     *
     * @scenario case=create_filter
     *
     * @effects notification_fields_stripped_from_create_data
     *
     * @return void 반환값 없음
     */
    public function test_filter_create_data_removes_notification_fields(): void
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
    }

    /**
     * filterCreateData: 세션을 쓰지 않습니다 — 생성 후 액션은 큐 워커(세션 없음)에서 돌 수 있습니다.
     *
     * @scenario case=create_filter
     *
     * @effects notification_settings_saved_without_session
     *
     * @return void 반환값 없음
     */
    public function test_filter_create_data_does_not_use_session(): void
    {
        session()->forget('board_notification_data');

        $this->listener->filterCreateData(['name' => '홍길동', 'notify_post_complete' => true]);

        $this->assertNull(session('board_notification_data'));
    }

    // ==========================================
    // filterUpdateData
    // ==========================================

    /**
     * filterUpdateData: 알림 필드를 즉시 DB에 저장하고 데이터에서 제거합니다.
     *
     * @scenario case=update_filter
     *
     * @effects notification_settings_saved_on_update
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=update_filter
     *
     * @effects notification_settings_saved_on_update
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=update_filter
     *
     * @effects notification_settings_saved_on_update
     *
     * @return void 반환값 없음
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
     * afterCreate: 원본 요청 데이터(훅 인자)의 알림 설정을 저장합니다.
     *
     * @scenario case=after_create
     *
     * @effects notification_settings_saved_without_session
     *
     * @return void 반환값 없음
     */
    public function test_after_create_saves_notification_fields_from_original_data(): void
    {
        $user = $this->makeUser();

        $this->listener->afterCreate($user, [
            'name' => '홍길동',
            'notify_post_complete' => true,
            'notify_post_reply' => false,
            'notify_comment' => true,
            'notify_reply_comment' => false,
        ]);

        $this->assertDatabaseHas('board_user_notification_settings', [
            'user_id' => $user->id,
            'notify_post_complete' => true,
            'notify_comment' => true,
        ]);
    }

    /**
     * afterCreate: 알림 필드 일부만 전송 시 미전송 필드는 false로 채웁니다.
     *
     * @scenario case=after_create
     *
     * @effects notification_settings_saved_without_session
     *
     * @return void 반환값 없음
     */
    public function test_after_create_fills_missing_notification_fields_with_false(): void
    {
        $user = $this->makeUser();

        // notify_post_reply, notify_comment, notify_reply_comment 미전송 (체크박스 해제)
        $this->listener->afterCreate($user, ['name' => '홍길동', 'notify_post_complete' => true]);

        $this->assertDatabaseHas('board_user_notification_settings', [
            'user_id' => $user->id,
            'notify_post_complete' => true,
            'notify_post_reply' => false,
            'notify_comment' => false,
            'notify_reply_comment' => false,
        ]);
    }

    /**
     * afterCreate: 원본 데이터에 알림 필드가 없으면 DB 저장이 발생하지 않습니다.
     *
     * @scenario case=after_create
     *
     * @effects no_settings_row_without_notification_fields
     *
     * @return void 반환값 없음
     */
    public function test_after_create_does_not_save_when_no_notification_fields(): void
    {
        $user = $this->makeUser();

        $this->listener->afterCreate($user, ['name' => '홍길동']);

        $this->assertDatabaseMissing('board_user_notification_settings', ['user_id' => $user->id]);
    }

    // ==========================================
    // filterResourceData
    // ==========================================

    /**
     * filterResourceData: 설정 레코드가 있으면 실제 값을 반환합니다.
     *
     * @scenario case=resource_filter
     *
     * @effects notification_settings_merged_into_user_resource
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=resource_filter
     *
     * @effects notification_settings_merged_into_user_resource
     *
     * @return void 반환값 없음
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
