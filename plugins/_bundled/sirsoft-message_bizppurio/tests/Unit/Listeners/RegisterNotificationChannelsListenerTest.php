<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Listeners;

use App\Services\PluginSettingsService;
use Plugins\Sirsoft\MessageBizppurio\Listeners\RegisterNotificationChannelsListener;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 채널 등록 리스너 단위 테스트.
 *
 * filter_available_channels(채널 메타), channel_readiness(설정 충족 판정),
 * 3영역 .notification.channels(후보 확장) 각 필터 메서드의 동작을 검증한다.
 */
class RegisterNotificationChannelsListenerTest extends PluginTestCase
{
    /**
     * 설정값 맵으로 PluginSettingsService 를 대체한 리스너를 만든다.
     *
     * @param  array<string, string>  $settings  plugin 설정 키→값
     */
    private function makeListener(array $settings = []): RegisterNotificationChannelsListener
    {
        $stub = new class($settings) extends PluginSettingsService
        {
            /** @param array<string, string> $map */
            public function __construct(private array $map)
            {
                // 부모 생성자(의존성) 우회 — 테스트 stub 은 get()만 사용
            }

            public function get(string $identifier, ?string $key = null, mixed $default = null): mixed
            {
                return $this->map[$key] ?? $default;
            }
        };

        return new RegisterNotificationChannelsListener($stub);
    }

    /** 완비된 설정값 */
    private function fullSettings(): array
    {
        return [
            'bizppurio_id' => 'acme',
            'password' => 'secret',
            'sender_number' => '025550000',
            'api_key' => 'key-40',
            'sender_key' => 'sender-40',
        ];
    }

    public function test_사용가능_채널에_sms와_alimtalk이_추가된다(): void
    {
        $listener = $this->makeListener();

        $channels = $listener->addChannels([
            ['id' => 'mail'],
            ['id' => 'database'],
        ]);

        $ids = array_column($channels, 'id');
        $this->assertContains('sms', $ids);
        $this->assertContains('alimtalk', $ids);

        $sms = collect($channels)->firstWhere('id', 'sms');
        $this->assertTrue($sms['allow_guest'], 'sms 는 비회원 발송 허용(allow_guest:true)이어야 한다.');
        $this->assertArrayHasKey('name_key', $sms);
        $this->assertStringContainsString('sirsoft-message_bizppurio::', $sms['name_key']);

        $alimtalk = collect($channels)->firstWhere('id', 'alimtalk');
        $this->assertTrue($alimtalk['allow_guest']);
    }

    public function test_채널메타에_is_test_mode가_실린다(): void
    {
        // is_test_mode=true(검수) → 상태 배너 노출 기준. 채널 메타에 실려 프론트로 전달된다.
        $listener = $this->makeListener(array_merge($this->fullSettings(), ['is_test_mode' => true]));
        $channels = $listener->addChannels([]);

        $alimtalk = collect($channels)->firstWhere('id', 'alimtalk');
        $this->assertTrue($alimtalk['is_test_mode'], '검수 모드면 채널 메타 is_test_mode=true.');

        $sms = collect($channels)->firstWhere('id', 'sms');
        $this->assertTrue($sms['is_test_mode']);
    }

    public function test_검수모드_해제시_is_test_mode가_false다(): void
    {
        $listener = $this->makeListener(array_merge($this->fullSettings(), ['is_test_mode' => false]));
        $channels = $listener->addChannels([]);

        $alimtalk = collect($channels)->firstWhere('id', 'alimtalk');
        $this->assertFalse($alimtalk['is_test_mode'], '운영 모드면 is_test_mode=false.');
    }

    public function test_어떤_채널에도_uses_custom_list_플래그가_없다(): void
    {
        // Phase 6 재설계 A(⚑⚑ 결정 A): 알림톡 탭도 코어 기본 목록을 그대로 쓴다. 코어 목록을
        // 숨기던 uses_custom_list 게이트·플래그는 폐기되었으므로 어느 채널에도 있으면 안 된다.
        $channels = $this->makeListener()->addChannels([]);

        $alimtalk = collect($channels)->firstWhere('id', 'alimtalk');
        $this->assertArrayNotHasKey('uses_custom_list', $alimtalk, '알림톡은 코어 기본 목록을 쓰므로 플래그가 없어야 한다(재설계 A).');

        $sms = collect($channels)->firstWhere('id', 'sms');
        $this->assertArrayNotHasKey('uses_custom_list', $sms, 'sms 는 코어 기본 목록을 쓰므로 플래그가 없어야 한다.');
    }

    public function test_이미_존재하는_채널은_중복_추가되지_않는다(): void
    {
        $channels = $this->makeListener()->addChannels([['id' => 'sms', 'source' => 'other']]);

        $smsCount = count(array_filter($channels, fn ($c) => $c['id'] === 'sms'));
        $this->assertSame(1, $smsCount, 'sms 가 중복 추가되면 안 된다.');
    }

    public function test_readiness_sms는_필수설정_부족시_ready_false다(): void
    {
        $listener = $this->makeListener([]);

        $result = $listener->checkReadiness(['ready' => true, 'reason' => null], 'sms');

        $this->assertFalse($result['ready']);
        $this->assertStringContainsString('readiness.sms_credentials_missing', $result['reason']);
    }

    public function test_readiness_sms는_발신번호만_없으면_해당_사유를_반환한다(): void
    {
        $listener = $this->makeListener([
            'bizppurio_id' => 'acme',
            'password' => 'secret',
        ]);

        $result = $listener->checkReadiness(['ready' => true, 'reason' => null], 'sms');

        $this->assertFalse($result['ready']);
        $this->assertStringContainsString('readiness.sms_sender_number_missing', $result['reason']);
    }

    public function test_readiness_sms는_완비시_ready_true다(): void
    {
        $listener = $this->makeListener($this->fullSettings());

        $result = $listener->checkReadiness(['ready' => true, 'reason' => null], 'sms');

        $this->assertTrue($result['ready']);
        $this->assertNull($result['reason']);
    }

    public function test_readiness_alimtalk는_apikey나_senderkey_없으면_ready_false다(): void
    {
        $listener = $this->makeListener([
            'bizppurio_id' => 'acme',
            'password' => 'secret',
            'sender_number' => '025550000',
            // api_key / sender_key 누락
        ]);

        $result = $listener->checkReadiness(['ready' => true, 'reason' => null], 'alimtalk');

        $this->assertFalse($result['ready']);
        $this->assertStringContainsString('readiness.alimtalk_api_key_missing', $result['reason']);
    }

    public function test_readiness_alimtalk는_완비시_ready_true다(): void
    {
        $listener = $this->makeListener($this->fullSettings());

        $result = $listener->checkReadiness(['ready' => true, 'reason' => null], 'alimtalk');

        $this->assertTrue($result['ready']);
    }

    public function test_readiness_우리채널이_아니면_원본_판정을_통과시킨다(): void
    {
        $listener = $this->makeListener([]);
        $original = ['ready' => false, 'reason' => 'notification.readiness.mail_smtp_host_empty'];

        $result = $listener->checkReadiness($original, 'mail');

        $this->assertSame($original, $result, 'mail 등 타 채널 판정은 변형 없이 통과해야 한다.');
    }

    public function test_채널후보에_sms와_alimtalk이_더해진다(): void
    {
        $result = $this->makeListener()->addChannelCandidates(['mail'], 'welcome', null);

        $this->assertContains('mail', $result);
        $this->assertContains('sms', $result);
        $this->assertContains('alimtalk', $result);
    }

    public function test_채널후보_중복은_제거된다(): void
    {
        $result = $this->makeListener()->addChannelCandidates(['sms'], 'welcome', null);

        $this->assertSame(1, count(array_filter($result, fn ($c) => $c === 'sms')));
    }

    public function test_구독_훅에_3영역_channels가_포함된다(): void
    {
        $hooks = RegisterNotificationChannelsListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.notification.filter_available_channels', $hooks);
        $this->assertArrayHasKey('core.notification.channel_readiness', $hooks);
        $this->assertArrayHasKey('core.notification.channel_enabled', $hooks);
        $this->assertArrayHasKey('core.auth.notification.channels', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.notification.channels', $hooks);
        $this->assertArrayHasKey('sirsoft-board.notification.channels', $hooks);

        // 전부 filter 타입 명시(반환값 무시 회귀 차단)
        foreach ($hooks as $config) {
            $this->assertSame('filter', $config['type']);
        }
    }

    public function test_channel_enabled_우리채널이_아니면_원본을_통과시킨다(): void
    {
        $listener = $this->makeListener();

        // mail/database 등 타 채널은 저장소 조회 없이 코어 판정($enabled)을 그대로 반환
        $this->assertTrue($listener->gateChannelEnabled(true, 'core', 'core', 'mail'));
        $this->assertFalse($listener->gateChannelEnabled(false, 'core', 'core', 'database'));
        $this->assertTrue($listener->gateChannelEnabled(true, 'module', 'sirsoft-board', 'slack'));
    }
}
