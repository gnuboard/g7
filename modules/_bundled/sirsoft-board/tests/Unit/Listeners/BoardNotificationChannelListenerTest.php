<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Listeners;

use Modules\Sirsoft\Board\Listeners\BoardNotificationChannelListener;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 게시판 알림 채널 필터 리스너 단위 테스트
 *
 * BoardNotificationChannelListener::filterChannels()가
 * notifications.channels 설정 기반으로 채널을 올바르게 필터링하는지 검증합니다.
 */
class BoardNotificationChannelListenerTest extends ModuleTestCase
{
    private BoardNotificationChannelListener $listener;

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = new BoardNotificationChannelListener();
    }

    // ── 훅 구독 등록 확인 ──

    /**
     * 구독할 훅 목록이 올바르게 정의되어 있는지 확인합니다.
     */
    #[Test]
    public function test_getSubscribedHooks_올바른_훅_구독(): void
    {
        $hooks = BoardNotificationChannelListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-board.notification.channels', $hooks);
        $this->assertEquals('filterChannels', $hooks['sirsoft-board.notification.channels']['method']);
        $this->assertEquals(10, $hooks['sirsoft-board.notification.channels']['priority']);
        $this->assertEquals('filter', $hooks['sirsoft-board.notification.channels']['type']);
    }

    // ── 채널 필터링 ──

    /**
     * notifications.channels 설정이 없으면 원래 채널을 그대로 반환합니다.
     */
    #[Test]
    public function test_filterChannels_설정없음_원래채널_반환(): void
    {
        // 설정 없음 (빈 배열)
        $this->setModuleSettings('sirsoft-board', 'notifications', []);

        $channels = ['mail', 'database'];
        $result = $this->listener->filterChannels($channels);

        $this->assertEquals(['mail', 'database'], $result);
    }

    /**
     * notifications.channels가 비어있으면 원래 채널을 그대로 반환합니다.
     */
    #[Test]
    public function test_filterChannels_빈channels_원래채널_반환(): void
    {
        $this->setModuleSettings('sirsoft-board', 'notifications', [
            'channels' => [],
        ]);

        $channels = ['mail', 'database'];
        $result = $this->listener->filterChannels($channels);

        $this->assertEquals(['mail', 'database'], $result);
    }

    /**
     * 활성화된 채널만 반환합니다.
     */
    #[Test]
    public function test_filterChannels_활성채널만_반환(): void
    {
        $this->setModuleSettings('sirsoft-board', 'notifications', [
            'channels' => [
                ['id' => 'mail', 'is_active' => true, 'sort_order' => 0],
                ['id' => 'database', 'is_active' => false, 'sort_order' => 1],
            ],
        ]);

        $channels = ['mail', 'database'];
        $result = $this->listener->filterChannels($channels);

        $this->assertEquals(['mail'], $result);
    }

    /**
     * 모든 채널이 비활성이면 빈 배열을 반환합니다.
     */
    #[Test]
    public function test_filterChannels_모든채널_비활성_빈배열(): void
    {
        $this->setModuleSettings('sirsoft-board', 'notifications', [
            'channels' => [
                ['id' => 'mail', 'is_active' => false, 'sort_order' => 0],
                ['id' => 'database', 'is_active' => false, 'sort_order' => 1],
            ],
        ]);

        $channels = ['mail', 'database'];
        $result = $this->listener->filterChannels($channels);

        $this->assertEmpty($result);
    }

    /**
     * 모든 채널이 활성이면 모든 채널을 반환합니다.
     */
    #[Test]
    public function test_filterChannels_모든채널_활성_전체반환(): void
    {
        $this->setModuleSettings('sirsoft-board', 'notifications', [
            'channels' => [
                ['id' => 'mail', 'is_active' => true, 'sort_order' => 0],
                ['id' => 'database', 'is_active' => true, 'sort_order' => 1],
            ],
        ]);

        $channels = ['mail', 'database'];
        $result = $this->listener->filterChannels($channels);

        $this->assertEquals(['mail', 'database'], $result);
    }

    /**
     * 설정에 없는 채널은 필터링됩니다.
     */
    #[Test]
    public function test_filterChannels_설정에_없는채널_필터링(): void
    {
        $this->setModuleSettings('sirsoft-board', 'notifications', [
            'channels' => [
                ['id' => 'mail', 'is_active' => true, 'sort_order' => 0],
            ],
        ]);

        $channels = ['mail', 'database', 'slack'];
        $result = $this->listener->filterChannels($channels);

        $this->assertEquals(['mail'], $result);
    }

    /**
     * 모듈 설정에 notifications.channels를 설정하는 헬퍼 메서드
     *
     * @param  string  $moduleId  모듈 식별자
     * @param  string  $category  설정 카테고리
     * @param  array  $value  설정 값
     */
    private function setModuleSettings(string $moduleId, string $category, array $value): void
    {
        // g7_module_settings()는 Config::get("g7_settings.modules.{$identifier}.{$key}") 사용
        \Illuminate\Support\Facades\Config::set("g7_settings.modules.{$moduleId}.{$category}", $value);
    }
}
