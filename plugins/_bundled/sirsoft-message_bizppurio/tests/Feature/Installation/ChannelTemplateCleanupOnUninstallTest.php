<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Feature\Installation;

use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use Database\Seeders\NotificationDefinitionSeeder;
use Plugins\Sirsoft\MessageBizppurio\Plugin;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 플러그인 제거(uninstall) 시 sms·alimtalk 채널 잔재가 정리되는지 검증한다.
 *
 * 배경: SeedChannelTemplatesListener 는 코어 소유 알림 정의(welcome 등)에 필터 훅으로
 * sms·alimtalk template 을 증강한다. 플러그인을 제거해도 그 정의는 우리 소유가 아니므로
 * PluginManager 의 범용 알림 정의 정리(extension_identifier 기준)에 걸리지 않아, 채널
 * 잔재가 고아로 남는 문제가 있었다. Plugin::uninstall() 이 자체적으로 정리하도록 수정했다.
 */
class ChannelTemplateCleanupOnUninstallTest extends PluginTestCase
{
    private Plugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plugin = new Plugin;
    }

    /**
     * activate() 로 채널이 증강된 상태에서 uninstall() 을 호출하면, 코어 알림 정의는
     * 보존한 채 sms·alimtalk template 과 channels 배열의 우리 채널만 제거된다.
     */
    public function test_uninstall_시_sms_alimtalk_템플릿이_정리되고_코어_정의는_보존된다(): void
    {
        $this->plugin->activate();

        $this->assertGreaterThan(
            0,
            NotificationTemplate::whereIn('channel', ['sms', 'alimtalk'])->count(),
            '사전조건: activate() 후 sms·alimtalk 템플릿이 존재해야 한다.'
        );

        $welcome = NotificationDefinition::where('type', 'welcome')->firstOrFail();
        $this->assertContains('sms', $welcome->channels);
        $this->assertContains('alimtalk', $welcome->channels);

        $this->plugin->uninstall();

        $this->assertSame(
            0,
            NotificationTemplate::whereIn('channel', ['sms', 'alimtalk'])->count(),
            'uninstall() 후에는 sms·alimtalk 채널 템플릿이 하나도 남으면 안 된다.'
        );

        $welcome->refresh();
        $this->assertEqualsCanonicalizing(
            ['mail', 'database'],
            $welcome->channels,
            'welcome 정의는 삭제되지 않고, channels 배열에서 sms·alimtalk 만 제거되어야 한다.'
        );
        $this->assertNotNull(
            NotificationTemplate::where('definition_id', $welcome->id)->where('channel', 'mail')->first(),
            'mail 채널 템플릿은 그대로 보존되어야 한다.'
        );
        $this->assertNotNull(
            NotificationTemplate::where('definition_id', $welcome->id)->where('channel', 'database')->first(),
            'database 채널 템플릿은 그대로 보존되어야 한다.'
        );
    }

    /**
     * 게시판·이커머스처럼 코어가 아닌 다른 모듈이 소유한 알림 정의도 동일하게,
     * 정의 자체는 남고 우리 채널만 제거된다.
     */
    public function test_uninstall_시_모듈_소유_알림도_채널만_정리되고_정의는_보존된다(): void
    {
        $this->plugin->activate();

        $boardDefinition = NotificationDefinition::where('extension_identifier', 'sirsoft-board')->first();

        if (! $boardDefinition) {
            $this->markTestSkipped('sirsoft-board 모듈이 활성화되지 않은 테스트 환경입니다.');
        }

        $this->assertContains('sms', $boardDefinition->channels);

        $this->plugin->uninstall();

        $boardDefinition->refresh();
        $this->assertNotContains('sms', $boardDefinition->channels);
        $this->assertNotContains('alimtalk', $boardDefinition->channels);
        $this->assertSame(
            0,
            NotificationTemplate::where('definition_id', $boardDefinition->id)
                ->whereIn('channel', ['sms', 'alimtalk'])
                ->count()
        );
    }

    /**
     * 제거 후 재설치(재활성화)하면 activate() 의 재시딩 경로로 채널이 다시 생성된다(회귀 방지).
     */
    public function test_제거_후_재활성화하면_채널이_다시_생성된다(): void
    {
        $this->plugin->activate();
        $this->plugin->uninstall();

        $this->assertSame(0, NotificationTemplate::whereIn('channel', ['sms', 'alimtalk'])->count());

        $this->plugin->activate();

        $this->assertGreaterThan(
            0,
            NotificationTemplate::where('channel', 'sms')->count(),
            '재활성화 후 sms 채널 템플릿이 다시 생성되어야 한다.'
        );
        $this->assertGreaterThan(
            0,
            NotificationTemplate::where('channel', 'alimtalk')->count(),
            '재활성화 후 alimtalk 채널 템플릿이 다시 생성되어야 한다.'
        );

        $welcome = NotificationDefinition::where('type', 'welcome')->firstOrFail();
        $this->assertContains('sms', $welcome->channels);
        $this->assertContains('alimtalk', $welcome->channels);
    }

    /**
     * 채널 증강이 전혀 없는 상태(activate 를 호출하지 않은 상태)에서 uninstall() 을
     * 호출해도 예외 없이 안전하게 통과해야 한다(정리 대상 0건).
     */
    public function test_증강된_채널이_없는_상태에서_uninstall해도_안전하다(): void
    {
        app(NotificationDefinitionSeeder::class)->run();

        $this->assertSame(0, NotificationTemplate::whereIn('channel', ['sms', 'alimtalk'])->count());

        $result = $this->plugin->uninstall();

        $this->assertTrue($result);
        $this->assertSame(0, NotificationTemplate::whereIn('channel', ['sms', 'alimtalk'])->count());
    }
}
