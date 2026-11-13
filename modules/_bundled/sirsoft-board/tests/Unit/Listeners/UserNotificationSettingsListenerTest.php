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
 * 알림 수신 설정 4칸의 검증과 저장은 회원 항목 선언과 값 핸들러가 맡는다(`Feature/User/UserNotificationFieldSetTest`).
 * 이 리스너에 남은 것은 회원 응답에 값을 싣는 일 하나다.
 *
 * 검증 목적:
 * - getSubscribedHooks: 응답 필터 하나만 구독(검증, 저장 훅 구독 없음)
 * - 걷어 낸 검증, 저장 메서드가 남아 있지 않음
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
     * 응답 필터 하나만 구독합니다 — 검증 규칙 필터, 저장 데이터 필터, 생성 후 액션은 항목 엔진과 겹치므로 구독하지 않습니다.
     *
     * @scenario case=subscription_contract
     *
     * @effects subscribes_user_lifecycle_hooks
     *
     * @return void 반환값 없음
     */
    public function test_subscribed_hooks_contains_resource_filter_only(): void
    {
        $hooks = UserNotificationSettingsListener::getSubscribedHooks();

        $this->assertSame(['core.user.filter_resource_data'], array_keys($hooks));
        $this->assertSame('filter', $hooks['core.user.filter_resource_data']['type']);
        $this->assertSame('filterResourceData', $hooks['core.user.filter_resource_data']['method']);
    }

    /**
     * 걷어 낸 검증, 저장 메서드가 리스너에 남아 있지 않습니다.
     *
     * @scenario case=subscription_contract
     *
     * @effects subscribes_user_lifecycle_hooks
     *
     * @return void 반환값 없음
     */
    public function test_validation_and_save_methods_are_removed(): void
    {
        foreach (['addValidationRules', 'filterCreateData', 'filterUpdateData', 'afterCreate'] as $method) {
            $this->assertFalse(method_exists($this->listener, $method), "{$method} 가 남아 있으면 항목 엔진과 이중으로 처리한다");
        }
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
        $this->assertTrue($result['notify_reply_comment']);
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
