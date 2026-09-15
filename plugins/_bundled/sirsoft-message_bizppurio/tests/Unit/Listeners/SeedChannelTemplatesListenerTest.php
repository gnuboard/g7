<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Listeners;

use Plugins\Sirsoft\MessageBizppurio\Listeners\SeedChannelTemplatesListener;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * SeedChannelTemplatesListener — 시딩 필터 훅으로 회원 알림에 sms·alimtalk template 증강 검증.
 *
 * 코어/모듈이 알림 정의를 시딩하기 직전에 통과하는 정의 배열에 대해, 회원 대상 알림에만
 * sms·alimtalk 채널 template 을 channels·templates 에 추가한다(결정 D·E). 관리자 전용 알림은
 * 건너뛰고, 기본 body 는 database 채널 body 를 재활용한다.
 */
class SeedChannelTemplatesListenerTest extends PluginTestCase
{
    private function listener(): SeedChannelTemplatesListener
    {
        return new SeedChannelTemplatesListener;
    }

    /** 코어형 연관 배열(키=type) 한 건 정의를 만듭니다. */
    private function coreDefinition(array $recipients = [['type' => 'trigger_user']]): array
    {
        return [
            'welcome' => [
                'hook_prefix' => 'core.auth',
                'channels' => ['mail', 'database'],
                'templates' => [
                    ['channel' => 'mail', 'recipients' => $recipients, 'subject' => ['ko' => '제목'], 'body' => ['ko' => '<h1>{name}</h1>']],
                    ['channel' => 'database', 'recipients' => $recipients, 'subject' => ['ko' => '알림'], 'body' => ['ko' => '{name}님 환영합니다']],
                ],
            ],
        ];
    }

    public function test_회원_알림에_sms와_alimtalk_채널이_추가된다(): void
    {
        $result = $this->listener()->augment($this->coreDefinition());

        $channels = $result['welcome']['channels'];
        $this->assertContains('sms', $channels);
        $this->assertContains('alimtalk', $channels);
        $this->assertContains('mail', $channels, '기존 채널은 보존되어야 한다.');

        $addedChannels = array_column($result['welcome']['templates'], 'channel');
        $this->assertContains('sms', $addedChannels);
        $this->assertContains('alimtalk', $addedChannels);
    }

    public function test_추가된_채널_body는_database_평문을_재활용한다(): void
    {
        $result = $this->listener()->augment($this->coreDefinition());

        $alimtalk = collect($result['welcome']['templates'])->firstWhere('channel', 'alimtalk');
        $this->assertSame('{name}님 환영합니다', $alimtalk['body']['ko'], 'database 평문 body 를 재활용해야 한다(HTML 아님).');
    }

    public function test_database가_없으면_mail_body의_htm_l을_제거해_쓴다(): void
    {
        $def = [
            'welcome' => [
                'channels' => ['mail'],
                'templates' => [
                    ['channel' => 'mail', 'recipients' => [['type' => 'trigger_user']], 'body' => ['ko' => '<h1>{name}님</h1><p>환영합니다</p>']],
                ],
            ],
        ];

        $result = $this->listener()->augment($def);

        $sms = collect($result['welcome']['templates'])->firstWhere('channel', 'sms');
        $this->assertSame('{name}님 환영합니다', $sms['body']['ko'], 'HTML 태그는 제거되고 변수는 보존되어야 한다.');
    }

    public function test_관리자_전용_알림은_증강되지_않는다(): void
    {
        $def = [
            'balance_low' => [
                'channels' => ['mail', 'database'],
                'templates' => [
                    ['channel' => 'mail', 'recipients' => [['type' => 'role', 'value' => 'admin']], 'body' => ['ko' => 'x']],
                ],
            ],
        ];

        $result = $this->listener()->augment($def);

        $channels = $result['balance_low']['channels'];
        $this->assertNotContains('sms', $channels, '관리자 전용 알림에는 문자/알림톡을 추가하지 않는다.');
        $this->assertNotContains('alimtalk', $channels);
    }

    public function test_모듈형_순차배열도_증강한다(): void
    {
        // 모듈: getNotificationDefinitions() 는 순차 배열 [['type'=>..., ...], ...]
        $def = [
            [
                'type' => 'order_confirmed',
                'channels' => ['mail', 'database'],
                'templates' => [
                    ['channel' => 'database', 'recipients' => [['type' => 'trigger_user']], 'body' => ['ko' => '주문 완료']],
                ],
            ],
        ];

        $result = $this->listener()->augment($def);

        $this->assertContains('alimtalk', $result[0]['channels']);
        $this->assertSame('order_confirmed', $result[0]['type'], 'type 등 기존 필드는 보존되어야 한다.');
    }

    public function test_이미_alimtalk이_있으면_중복_추가하지_않는다(): void
    {
        $def = [
            'welcome' => [
                'channels' => ['mail', 'alimtalk'],
                'templates' => [
                    ['channel' => 'mail', 'recipients' => [['type' => 'trigger_user']], 'body' => ['ko' => 'x']],
                    ['channel' => 'alimtalk', 'recipients' => [['type' => 'trigger_user']], 'body' => ['ko' => '기존']],
                ],
            ],
        ];

        $result = $this->listener()->augment($def);

        $alimtalkCount = count(array_filter($result['welcome']['templates'], fn ($t) => $t['channel'] === 'alimtalk'));
        $this->assertSame(1, $alimtalkCount, 'alimtalk template 이 중복 추가되면 안 된다.');
    }

    public function test_recipients가_없는_알림은_회원_대상으로_보고_증강한다(): void
    {
        $def = [
            'generic' => [
                'channels' => ['mail'],
                'templates' => [
                    ['channel' => 'mail', 'body' => ['ko' => '알림']],
                ],
            ],
        ];

        $result = $this->listener()->augment($def);

        $this->assertContains('alimtalk', $result['generic']['channels']);
        // recipients 미지정 → 기본 trigger_user
        $alimtalk = collect($result['generic']['templates'])->firstWhere('channel', 'alimtalk');
        $this->assertSame('trigger_user', $alimtalk['recipients'][0]['type']);
    }

    /**
     * 코어 [기본값 복원] 경로(getDefaultTemplateData)가 통과하는 필터에도 같은 증강을 건다
     * (#597 §18.7 C2 — 시드만 하고 복원 출처에 빠져 있어 "기본 템플릿 데이터를 찾을 수 없습니다" 로 실패했다).
     *
     * @effects core_reset_restores_seeded_channel_template_defaults
     */
    public function test_기본값_복원_필터를_시딩_훅과_같은_메서드로_구독한다(): void
    {
        $hooks = SeedChannelTemplatesListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.notification.filter_default_definitions', $hooks);
        $this->assertSame('augment', $hooks['core.notification.filter_default_definitions']['method']);
        $this->assertSame('filter', $hooks['core.notification.filter_default_definitions']['type'], '반환값을 쓰는 필터다 — type 누락 시 증강이 버려진다.');
        $this->assertGreaterThan(20, $hooks['core.notification.filter_default_definitions']['priority'], '게시판·이커머스가 자기 정의를 보태는 priority 20 뒤에 돌아야 모듈 정의도 증강된다.');

        // 시딩 3훅과 동일 메서드 — 시드 출처와 복원 출처가 갈리면 복원값이 시드값과 달라진다.
        foreach (['seed.notifications.translations', 'seed.sirsoft-board.notifications.translations', 'seed.sirsoft-ecommerce.notifications.translations'] as $seedHook) {
            $this->assertSame($hooks[$seedHook]['method'], $hooks['core.notification.filter_default_definitions']['method']);
        }
    }

    /**
     * @effects core_reset_restores_seeded_channel_template_defaults
     */
    public function test_기본값_복원_경로의_순차_정의_배열에서_alimtalk_기본값을_찾을_수_있다(): void
    {
        // getDefaultTemplateData 는 config/core.php 정의에 type/extension_* 를 붙인 순차 배열을 필터에 넘긴다
        $definitions = [
            array_merge($this->coreDefinition()['welcome'], ['type' => 'welcome', 'extension_type' => 'core', 'extension_identifier' => 'core']),
        ];

        $result = $this->listener()->augment($definitions, ['type' => 'welcome', 'channel' => 'alimtalk']);

        $alimtalk = collect($result[0]['templates'])->firstWhere('channel', 'alimtalk');
        $this->assertNotNull($alimtalk, '복원 경로가 찾는 channel=alimtalk template 이 있어야 한다.');
        $this->assertSame([['type' => 'trigger_user']], $alimtalk['recipients']);
        $this->assertSame('{name}님 환영합니다', $alimtalk['body']['ko'], '시딩과 같은 database 평문 body 가 복원값이다.');
        $this->assertSame('welcome', $result[0]['type'], '기존 필드는 보존된다.');
    }
}
