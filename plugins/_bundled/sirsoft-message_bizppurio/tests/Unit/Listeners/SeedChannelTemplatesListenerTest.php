<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Listeners;

use Plugins\Sirsoft\MessageBizppurio\Listeners\SeedChannelTemplatesListener;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * SeedChannelTemplatesListener — 시딩 필터 훅으로 회원 알림에 sms·alimtalk template 증강 검증.
 *
 * 코어·모듈·플러그인이 알림 정의를 시딩하기 직전에 통과하는 코어 공통 훅
 * (core.notification.filter_seed_definitions)의 정의 배열에 대해, 회원 대상 알림에만
 * sms·alimtalk 채널 template 을 channels·templates 에 추가한다(결정 D·E). 관리자 전용 알림은
 * 건너뛰고, 기본 body 는 database 채널 body 를 재활용한다.
 */
class SeedChannelTemplatesListenerTest extends PluginTestCase
{
    /**
     * 검증 대상 리스너 인스턴스를 만듭니다.
     *
     * @return SeedChannelTemplatesListener 리스너
     */
    private function listener(): SeedChannelTemplatesListener
    {
        return new SeedChannelTemplatesListener;
    }

    /**
     * 코어형 연관 배열(키=type) 한 건 정의를 만듭니다.
     *
     * @param  array<int, array<string, mixed>>  $recipients  수신자 규칙
     * @return array<string, array<string, mixed>> type 키 연관 배열 정의
     */
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

    /**
     * 회원 대상 알림에 sms·alimtalk 채널과 template 이 더해지고 기존 채널은 보존된다.
     *
     * @effects member_facing_definitions_gain_sms_and_alimtalk_templates
     *
     * @return void 반환값 없음
     */
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

    /**
     * 더한 채널의 본문은 database 채널의 평문 본문을 그대로 쓴다.
     *
     * @effects augmented_body_reuses_database_plain_text
     *
     * @return void 반환값 없음
     */
    public function test_추가된_채널_body는_database_평문을_재활용한다(): void
    {
        $result = $this->listener()->augment($this->coreDefinition());

        $alimtalk = collect($result['welcome']['templates'])->firstWhere('channel', 'alimtalk');
        $this->assertSame('{name}님 환영합니다', $alimtalk['body']['ko'], 'database 평문 body 를 재활용해야 한다(HTML 아님).');
    }

    /**
     * database 채널이 없으면 mail 본문에서 HTML 을 걷어낸 평문을 쓰고 변수 표기는 보존한다.
     *
     * @effects augmented_body_strips_mail_html_when_no_database
     *
     * @return void 반환값 없음
     */
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

    /**
     * 수신자가 관리자 역할뿐인 알림에는 문자·알림톡을 더하지 않는다.
     *
     * @effects admin_only_definitions_not_augmented
     *
     * @return void 반환값 없음
     */
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

    /**
     * type 을 담은 순차 배열도 같은 규칙으로 증강하고 기존 필드를 보존한다.
     *
     * @effects member_facing_definitions_gain_sms_and_alimtalk_templates
     *
     * @return void 반환값 없음
     */
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

    /**
     * 이미 선언된 채널 template 은 중복으로 더하지 않는다.
     *
     * @effects existing_channel_template_not_duplicated
     *
     * @return void 반환값 없음
     */
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

    /**
     * 수신자 규칙이 없는 알림은 회원 대상으로 보고 발생 회원 본인을 수신자로 증강한다.
     *
     * @effects definitions_without_recipients_treated_as_member_facing
     *
     * @return void 반환값 없음
     */
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
     *
     * @return void 반환값 없음
     */
    public function test_기본값_복원_필터를_시딩_훅과_같은_메서드로_구독한다(): void
    {
        $hooks = SeedChannelTemplatesListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.notification.filter_default_definitions', $hooks);
        $this->assertSame('augment', $hooks['core.notification.filter_default_definitions']['method']);
        $this->assertSame('filter', $hooks['core.notification.filter_default_definitions']['type'], '반환값을 쓰는 필터다 — type 누락 시 증강이 버려진다.');
        $this->assertGreaterThan(20, $hooks['core.notification.filter_default_definitions']['priority'], '게시판·이커머스가 자기 정의를 보태는 priority 20 뒤에 돌아야 모듈 정의도 증강된다.');

        // 시딩 공통 훅과 동일 메서드 — 시드 출처와 복원 출처가 갈리면 복원값이 시드값과 달라진다.
        $this->assertSame(
            $hooks['core.notification.filter_seed_definitions']['method'],
            $hooks['core.notification.filter_default_definitions']['method']
        );
    }

    /**
     * 시딩은 코어 공통 훅 하나에만 답하고, 도메인 이름을 담은 언어팩 시딩 훅은 구독하지 않는다.
     * 도메인 훅을 이름으로 고르면 목록에 없는 새 도메인의 알림에 문자·알림톡 template 이 생기지 않는다.
     *
     * @effects seed_answered_via_single_core_hook_without_domain_names
     *
     * @return void 반환값 없음
     */
    public function test_시딩은_코어_공통_훅_하나에만_답하고_도메인_훅은_구독하지_않는다(): void
    {
        $hooks = SeedChannelTemplatesListener::getSubscribedHooks();

        $this->assertSame(
            ['core.notification.filter_seed_definitions', 'core.notification.filter_default_definitions'],
            array_keys($hooks),
            '구독 훅은 코어 필터 2종뿐이어야 한다.'
        );
        $this->assertSame('augment', $hooks['core.notification.filter_seed_definitions']['method']);
        $this->assertSame('filter', $hooks['core.notification.filter_seed_definitions']['type'], '반환값을 쓰는 필터다 — type 누락 시 증강이 버려진다.');

        foreach (array_keys($hooks) as $hook) {
            $this->assertStringStartsNotWith('seed.', $hook, '도메인별 언어팩 시딩 훅은 구독하지 않는다.');
        }
    }

    /**
     * 공통 시딩 훅이 넘기는 소유자 컨텍스트와 무관하게, 어느 확장의 회원 알림이든 같은 규칙으로 증강한다.
     *
     * @effects arbitrary_extension_definitions_augmented_via_core_seed_hook
     *
     * @return void 반환값 없음
     */
    public function test_공통_시딩_훅의_임의_확장_정의도_같은_규칙으로_증강한다(): void
    {
        $definitions = [
            [
                'type' => 'future_booking_confirmed',
                'extension_type' => 'module',
                'extension_identifier' => 'vendor-future_domain',
                'channels' => ['mail', 'database'],
                'templates' => [
                    ['channel' => 'database', 'recipients' => [['type' => 'trigger_user']], 'body' => ['ko' => '예약이 확정됐습니다']],
                ],
            ],
            [
                'type' => 'future_admin_digest',
                'extension_type' => 'module',
                'extension_identifier' => 'vendor-future_domain',
                'channels' => ['mail'],
                'templates' => [
                    ['channel' => 'mail', 'recipients' => [['type' => 'role', 'value' => 'admin']], 'body' => ['ko' => 'x']],
                ],
            ],
        ];

        $result = $this->listener()->augment($definitions, ['extension_type' => 'module', 'extension_identifier' => 'vendor-future_domain']);

        $this->assertContains('sms', $result[0]['channels']);
        $this->assertContains('alimtalk', $result[0]['channels']);
        $sms = collect($result[0]['templates'])->firstWhere('channel', 'sms');
        $this->assertSame('예약이 확정됐습니다', $sms['body']['ko']);
        $this->assertSame('vendor-future_domain', $result[0]['extension_identifier'], '소유자 필드는 보존된다.');
        $this->assertNotContains('sms', $result[1]['channels'], '관리자 전용 알림은 도메인과 무관하게 건너뛴다.');
    }

    /**
     * [기본값 복원] 경로가 넘기는 순차 정의 배열에서도 시딩과 같은 alimtalk 기본값을 찾을 수 있다.
     *
     * @effects core_reset_restores_seeded_channel_template_defaults
     *
     * @return void 반환값 없음
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
