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
     * @return RegisterNotificationChannelsListener 결과 객체
     */
    private function makeListener(array $settings = []): RegisterNotificationChannelsListener
    {
        $stub = new class($settings) extends PluginSettingsService
        {
            /**
             * 설정 stub 의 키-값 표를 받습니다.
             *
             * @param  array<string, string>  $map  설정 키 => 값 표
             */
            public function __construct(private array $map)
            {
                // 부모 생성자(의존성) 우회 — 테스트 stub 은 get()만 사용
            }

            /**
             * 설정 stub 에서 키 값을 돌려줍니다.
             *
             * @param  string  $identifier  확장 식별자
             * @param  ?string  $key  설정 키
             * @param  mixed  $default  기본값
             * @return mixed 설정 값
             */
            public function get(string $identifier, ?string $key = null, mixed $default = null): mixed
            {
                return $this->map[$key] ?? $default;
            }
        };

        return new RegisterNotificationChannelsListener($stub);
    }

    /**
     *  완비된 설정값
     *
     * @return array 결과 배열
     */
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

    /**
     * 사용가능 채널에 sms와 alimtalk이 추가된다.
     *
     * @effects sms_channel_exposed_in_available_channels, alimtalk_channel_exposed_in_available_channels
     *
     * @return void 반환값 없음
     */
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

    /**
     * 채널메타에 is test mode가 실린다.
     *
     * @effects channel_meta_carries_test_mode_flag
     *
     * @return void 반환값 없음
     */
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

    /**
     * 검수모드 해제시 is test mode가 false다.
     *
     * @effects channel_meta_carries_test_mode_flag
     *
     * @return void 반환값 없음
     */
    public function test_검수모드_해제시_is_test_mode가_false다(): void
    {
        $listener = $this->makeListener(array_merge($this->fullSettings(), ['is_test_mode' => false]));
        $channels = $listener->addChannels([]);

        $alimtalk = collect($channels)->firstWhere('id', 'alimtalk');
        $this->assertFalse($alimtalk['is_test_mode'], '운영 모드면 is_test_mode=false.');
    }

    /**
     * 채널메타에 비즈뿌리오 통합 탭 필드가 실린다.
     *
     * @effects channel_meta_carries_unified_tab_fields
     *
     * @return void 반환값 없음
     */
    public function test_채널메타에_비즈뿌리오_통합_탭_필드가_실린다(): void
    {
        // #597 §3.3 '비즈뿌리오' 통합 탭: sms 는 탭만 숨기고(hidden_tab — 채널 토글 카드·발송
        // 축은 유지), alimtalk 이 두 채널을 묶은 통합 탭을 대표한다(tab_channels/tab_label_key).
        // 코어 getAvailableChannels 는 임의 필드를 보존하므로 프론트까지 그대로 도달한다.
        $channels = $this->makeListener()->addChannels([]);

        $sms = collect($channels)->firstWhere('id', 'sms');
        $this->assertTrue($sms['hidden_tab'], 'sms 는 자체 탭을 숨겨야 한다(hidden_tab:true).');
        $this->assertArrayNotHasKey('tab_channels', $sms, '통합 탭 대표는 alimtalk 하나뿐이어야 한다.');

        $alimtalk = collect($channels)->firstWhere('id', 'alimtalk');
        $this->assertArrayNotHasKey('hidden_tab', $alimtalk, 'alimtalk 탭은 숨기지 않는다(통합 탭 대표).');
        $this->assertSame(
            ['sms', 'alimtalk'],
            $alimtalk['tab_channels'],
            'tab_channels 중 하나라도 활성 저장이면 통합 탭을 노출한다.'
        );
        $this->assertSame(
            'sirsoft-message_bizppurio.channels.bizppurio_tab',
            $alimtalk['tab_label_key'],
            '탭 라벨은 프론트 lang 키($t 해석)로 선언한다(카드 라벨 name_key 와 분리).'
        );
    }

    /**
     * 어떤 채널에도 uses custom list 플래그가 없다.
     *
     * @effects alimtalk_has_no_uses_custom_list_flag_yet
     *
     * @return void 반환값 없음
     */
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

    /**
     * 이미 존재하는 채널은 중복 추가되지 않는다.
     *
     * @effects existing_channel_not_duplicated
     *
     * @return void 반환값 없음
     */
    public function test_이미_존재하는_채널은_중복_추가되지_않는다(): void
    {
        $channels = $this->makeListener()->addChannels([['id' => 'sms', 'source' => 'other']]);

        $smsCount = count(array_filter($channels, fn ($c) => $c['id'] === 'sms'));
        $this->assertSame(1, $smsCount, 'sms 가 중복 추가되면 안 된다.');
    }

    /**
     * readiness sms는 필수설정 부족시 ready false다.
     *
     * @effects sms_not_ready_reports_credentials_missing_key
     *
     * @return void 반환값 없음
     */
    public function test_readiness_sms는_필수설정_부족시_ready_false다(): void
    {
        $listener = $this->makeListener([]);

        $result = $listener->checkReadiness(['ready' => true, 'reason' => null], 'sms');

        $this->assertFalse($result['ready']);
        $this->assertStringContainsString('readiness.sms_credentials_missing', $result['reason']);
    }

    /**
     * readiness sms는 발신번호만 없으면 해당 사유를 반환한다.
     *
     * @effects sms_not_ready_reports_sender_number_missing_key
     *
     * @return void 반환값 없음
     */
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

    /**
     * readiness sms는 완비시 ready true다.
     *
     * @effects sms_ready_when_id_password_sender_number_set
     *
     * @return void 반환값 없음
     */
    public function test_readiness_sms는_완비시_ready_true다(): void
    {
        $listener = $this->makeListener($this->fullSettings());

        $result = $listener->checkReadiness(['ready' => true, 'reason' => null], 'sms');

        $this->assertTrue($result['ready']);
        $this->assertNull($result['reason']);
    }

    /**
     * readiness alimtalk는 apikey나 senderkey 없으면 ready false다.
     *
     * @effects alimtalk_not_ready_when_api_key_or_sender_key_missing
     *
     * @return void 반환값 없음
     */
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

    /**
     * readiness alimtalk는 완비시 ready true다.
     *
     * @effects alimtalk_ready_when_all_keys_set
     *
     * @return void 반환값 없음
     */
    public function test_readiness_alimtalk는_완비시_ready_true다(): void
    {
        $listener = $this->makeListener($this->fullSettings());

        $result = $listener->checkReadiness(['ready' => true, 'reason' => null], 'alimtalk');

        $this->assertTrue($result['ready']);
    }

    /**
     * readiness 우리채널이 아니면 원본 판정을 통과시킨다.
     *
     * @effects non_owned_channel_readiness_passes_through
     *
     * @return void 반환값 없음
     */
    public function test_readiness_우리채널이_아니면_원본_판정을_통과시킨다(): void
    {
        $listener = $this->makeListener([]);
        $original = ['ready' => false, 'reason' => 'notification.readiness.mail_smtp_host_empty'];

        $result = $listener->checkReadiness($original, 'mail');

        $this->assertSame($original, $result, 'mail 등 타 채널 판정은 변형 없이 통과해야 한다.');
    }

    /**
     * 채널후보에 sms와 alimtalk이 더해진다.
     *
     * @effects channel_candidates_answered_via_single_core_hook
     *
     * @return void 반환값 없음
     */
    public function test_채널후보에_sms와_alimtalk이_더해진다(): void
    {
        $result = $this->makeListener()->addChannelCandidates(['mail'], 'welcome', null);

        $this->assertContains('mail', $result);
        $this->assertContains('sms', $result);
        $this->assertContains('alimtalk', $result);
    }

    /**
     * 채널후보 중복은 제거된다.
     *
     * @effects channel_candidates_deduplicated
     *
     * @return void 반환값 없음
     */
    public function test_채널후보_중복은_제거된다(): void
    {
        $result = $this->makeListener()->addChannelCandidates(['sms'], 'welcome', null);

        $this->assertSame(1, count(array_filter($result, fn ($c) => $c === 'sms')));
    }

    /**
     * 구독 훅은 코어 알림 계약 4종뿐이다 — 도메인 접두사별 채널 훅을 직접 구독하지 않는다.
     *
     * 채널 후보는 코어 훅(core.notification.channels) 하나에 답하고, 도메인별 후보 훅은 코어가
     * 그 뒤에 도메인에게 넘긴다. 플러그인이 도메인 이름(게시판·이커머스 등)을 알면 새 도메인에서
     * 문자가 빠지고, 도메인이 없는 설치에서도 그 이름을 들고 있게 된다.
     *
     * @scenario channel=sms
     *
     * @effects channel_candidates_answered_via_single_core_hook
     *
     * @return void 반환값 없음
     */
    public function test_구독_훅은_코어_계약_4종뿐이다(): void
    {
        $hooks = RegisterNotificationChannelsListener::getSubscribedHooks();

        $this->assertSame([
            'core.notification.filter_available_channels',
            'core.notification.channel_readiness',
            'core.notification.channel_enabled',
            'core.notification.channels',
        ], array_keys($hooks));

        foreach (array_keys($hooks) as $hook) {
            $this->assertStringStartsWith('core.notification.', $hook, '도메인 접두사 훅을 구독하면 안 됩니다.');
        }

        // 전부 filter 타입 명시(반환값 무시 회귀 차단)
        foreach ($hooks as $config) {
            $this->assertSame('filter', $config['type']);
        }
    }

    /**
     * channel enabled 우리채널이 아니면 원본을 통과시킨다.
     *
     * @effects channel_enabled_gate_passes_non_owned_channels
     *
     * @return void 반환값 없음
     */
    public function test_channel_enabled_우리채널이_아니면_원본을_통과시킨다(): void
    {
        $listener = $this->makeListener();

        // mail/database 등 타 채널은 저장소 조회 없이 코어 판정($enabled)을 그대로 반환
        $this->assertTrue($listener->gateChannelEnabled(true, 'core', 'core', 'mail'));
        $this->assertFalse($listener->gateChannelEnabled(false, 'core', 'core', 'database'));
        $this->assertTrue($listener->gateChannelEnabled(true, 'module', 'sirsoft-board', 'slack'));
    }
}
