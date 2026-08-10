<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Services;

use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\GenericNotification;
use App\Notifications\GuestNotifiable;
use App\Services\NotificationDefinitionService;
use App\Services\NotificationTemplateService;
use App\Services\PluginSettingsService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\NotificationSendSkippedException;
use Plugins\Sirsoft\MessageBizppurio\Jobs\SendMessageJob;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioDispatch;
use Plugins\Sirsoft\MessageBizppurio\Repositories\BizppurioDispatchRepository;
use Plugins\Sirsoft\MessageBizppurio\Services\DispatchLinkContext;
use Plugins\Sirsoft\MessageBizppurio\Services\MessagePayloadBuilder;
use Plugins\Sirsoft\MessageBizppurio\Services\SmsChannelDriver;
use Plugins\Sirsoft\MessageBizppurio\Services\SmsTypeResolver;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * SmsChannelDriver — 전화번호 해석·템플릿 게이트·SMS/LMS 판별·Job 위임 검증.
 *
 * 발송 Job dispatch 를 Bus::fake 로 관찰한다(실제 발송은 SendMessageJobTest 가 커버).
 */
class SmsChannelDriverTest extends PluginTestCase
{
    /**
     * 렌더 결과를 반환하는 NotificationTemplate mock 을 만듭니다.
     *
     * Eloquent 이벤트 재인스턴스화(new static)와 충돌하지 않도록 서브클래스 대신
     * Mockery mock 을 사용한다(is_active + replaceVariables 만 stub).
     *
     * @param  array<string, string>  $rendered  replaceVariables 반환값
     */
    private function fakeTemplate(array $rendered = ['subject' => '', 'body' => '주문이 완료되었습니다.']): NotificationTemplate
    {
        $template = Mockery::mock(NotificationTemplate::class)->makePartial();
        $template->is_active = true;
        $template->shouldReceive('replaceVariables')->andReturn($rendered);

        return $template;
    }

    /**
     * 템플릿 resolve 결과를 지정한 driver 를 만듭니다.
     *
     * @param  NotificationTemplate|null  $template  resolve 반환값
     * @param  bool  $isTestMode  검수 모드 설정값 (이력 스냅샷 검증용)
     */
    private function makeDriver(
        ?NotificationTemplate $template,
        ?MessagePayloadBuilder $builder = null,
        bool $isTestMode = true,
    ): SmsChannelDriver {
        $templateService = Mockery::mock(NotificationTemplateService::class);
        $templateService->shouldReceive('resolve')
            ->with(Mockery::any(), 'sms')
            ->andReturn($template);

        $pluginSettings = Mockery::mock(PluginSettingsService::class);
        $pluginSettings->shouldReceive('get')
            ->with('sirsoft-message_bizppurio', 'is_test_mode', true)
            ->andReturn($isTestMode);

        // 스킵 예외 메시지의 알림 유형 라벨 조회 — 정의 없음(null)이면 resolveTypeLabel 이 코드값으로 폴백.
        $definitionService = Mockery::mock(NotificationDefinitionService::class);
        $definitionService->shouldReceive('resolve')->andReturn(null);

        return new SmsChannelDriver(
            $templateService,
            $definitionService,
            new SmsTypeResolver,
            $builder ?? $this->spyBuilder(),
            new BizppurioDispatchRepository,
            new DispatchLinkContext,
            $pluginSettings,
        );
    }

    /**
     * build* 호출 인자를 기록하는 payload 빌더 스파이를 만듭니다.
     */
    private function spyBuilder(): MessagePayloadBuilder
    {
        return new class extends MessagePayloadBuilder
        {
            public array $calls = [];

            public function __construct() {}

            public function buildSms(string $to, string $message, string $refkey): array
            {
                $this->calls[] = ['type' => 'sms', 'to' => $to, 'message' => $message, 'refkey' => $refkey];

                return ['type' => 'sms', 'to' => $to, 'refkey' => $refkey];
            }

            public function buildLms(string $to, string $message, string $refkey, ?string $subject = null): array
            {
                $this->calls[] = ['type' => 'lms', 'to' => $to, 'message' => $message, 'refkey' => $refkey, 'subject' => $subject];

                return ['type' => 'lms', 'to' => $to, 'refkey' => $refkey];
            }
        };
    }

    private function notification(array $data = []): GenericNotification
    {
        return new GenericNotification('order_confirmed', 'sirsoft-ecommerce', $data, 'module', 'sirsoft-ecommerce');
    }

    public function test_템플릿이_없으면_예외를_던지고_발송하지_않는다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        $this->expectException(NotificationSendSkippedException::class);

        try {
            $this->makeDriver(null)->send($member, $this->notification());
        } finally {
            Bus::assertNotDispatched(SendMessageJob::class);
        }
    }

    public function test_스킵_예외_메시지는_알림_유형_코드값_대신_사람이_읽는_이름을_사용한다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        $definition = Mockery::mock(\App\Models\NotificationDefinition::class);
        $definition->shouldReceive('getLocalizedName')->andReturn('회원가입 환영');

        $definitionService = Mockery::mock(NotificationDefinitionService::class);
        $definitionService->shouldReceive('resolve')->with('order_confirmed')->andReturn($definition);

        $templateService = Mockery::mock(NotificationTemplateService::class);
        $templateService->shouldReceive('resolve')->with(Mockery::any(), 'sms')->andReturn(null);

        $pluginSettings = Mockery::mock(PluginSettingsService::class);
        $pluginSettings->shouldReceive('get')->andReturn(true);

        $driver = new SmsChannelDriver(
            $templateService,
            $definitionService,
            new SmsTypeResolver,
            $this->spyBuilder(),
            new BizppurioDispatchRepository,
            new DispatchLinkContext,
            $pluginSettings,
        );

        try {
            $driver->send($member, $this->notification());
            $this->fail('NotificationSendSkippedException 가 발생해야 한다.');
        } catch (NotificationSendSkippedException $e) {
            $this->assertStringContainsString('회원가입 환영', $e->getMessage(), '코드값(order_confirmed) 대신 사람이 읽는 이름이 노출돼야 한다.');
            $this->assertStringNotContainsString('order_confirmed', $e->getMessage());
        }
    }

    public function test_회원은_mobile로_발송한다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);
        $builder = $this->spyBuilder();

        $this->makeDriver($this->fakeTemplate(), $builder)->send($member, $this->notification());

        Bus::assertDispatched(SendMessageJob::class);
        $this->assertCount(1, $builder->calls);
        $this->assertSame('01012345678', $builder->calls[0]['to'], '하이픈 제거된 회원 mobile 로 발송해야 한다.');
    }

    public function test_비회원은_data의_전화번호로_발송한다(): void
    {
        Bus::fake();
        $guest = new GuestNotifiable('guest@example.com', '홍길동', 'ko');
        $data = [SmsChannelDriver::RECIPIENT_PHONE_KEY => '010-9999-0000'];
        $builder = $this->spyBuilder();

        $this->makeDriver($this->fakeTemplate(), $builder)->send($guest, $this->notification($data));

        Bus::assertDispatched(SendMessageJob::class);
        $this->assertSame('01099990000', $builder->calls[0]['to']);
    }

    public function test_회원_발송_시_pending_이력을_생성하고_회원id를_기록한다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678', 'name' => '김철수']);

        $this->makeDriver($this->fakeTemplate())->send($member, $this->notification());

        $this->assertDatabaseCount('bizppurio_dispatches', 1);
        $dispatch = BizppurioDispatch::first();
        $this->assertSame('pending', $dispatch->status->value);
        $this->assertSame('01012345678', $dispatch->to_number);
        $this->assertSame($member->id, $dispatch->to_user_id, '회원 발송은 to_user_id 를 채워야 한다.');
        $this->assertSame('order_confirmed', $dispatch->notification_type);
    }

    public function test_비회원_발송_이력은_회원id가_null이다(): void
    {
        Bus::fake();
        $guest = new GuestNotifiable('guest@example.com', '홍길동', 'ko');
        $data = [SmsChannelDriver::RECIPIENT_PHONE_KEY => '010-9999-0000'];

        $this->makeDriver($this->fakeTemplate())->send($guest, $this->notification($data));

        $dispatch = BizppurioDispatch::first();
        $this->assertNotNull($dispatch);
        $this->assertNull($dispatch->to_user_id, '비회원 발송은 to_user_id 가 null 이어야 한다.');
    }

    public function test_발송_시_실제_전송_payload가_이력에_저장된다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);
        $builder = $this->spyBuilder();

        $this->makeDriver($this->fakeTemplate(), $builder)->send($member, $this->notification());

        $dispatch = BizppurioDispatch::first();
        $this->assertNotNull($dispatch->request_payload, '결함① — 실제 비즈뿌리오 전송 payload 가 이력에 저장돼야 한다.');
        $this->assertArrayNotHasKey('to', $dispatch->request_payload, 'to 는 to_number 컬럼과 중복이라 제외돼야 한다.');
    }

    public function test_이력_저장_payload에서_개인식별_정보는_제외된다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        // 실제 MessagePayloadBuilder(스파이 아님)로 진짜 조립 로직을 태워야 forHistory() 제외
        // 규칙(to/refkey/type/message 제거)을 검증할 수 있다.
        $pluginSettings = Mockery::mock(PluginSettingsService::class);
        $pluginSettings->shouldReceive('get')->andReturn('');
        $realBuilder = new MessagePayloadBuilder($pluginSettings);

        $this->makeDriver($this->fakeTemplate(), $realBuilder)->send($member, $this->notification());

        $dispatch = BizppurioDispatch::first();
        $payload = $dispatch->request_payload;

        $this->assertArrayNotHasKey('to', $payload, 'to 는 to_number 컬럼과 중복이라 제외돼야 한다.');
        $this->assertArrayNotHasKey('refkey', $payload, 'refkey 는 refkey 컬럼과 중복이라 제외돼야 한다.');
        $this->assertArrayNotHasKey('type', $payload, 'type 은 channel 컬럼과 중복이라 제외돼야 한다.');
        $this->assertArrayNotHasKey('message', $payload['content']['sms'] ?? [], 'message 는 content 컬럼과 중복이라 제외돼야 한다.');
    }

    public function test_발송하지_않으면_이력도_생성하지_않는다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        // 템플릿 없음 → NotificationSendSkippedException → 이력도 없어야 함
        $this->expectException(NotificationSendSkippedException::class);

        try {
            $this->makeDriver(null)->send($member, $this->notification());
        } finally {
            $this->assertDatabaseCount('bizppurio_dispatches', 0);
        }
    }

    public function test_전화번호가_없으면_예외를_던지고_발송하지_않는다(): void
    {
        Bus::fake();
        $guest = new GuestNotifiable('guest@example.com', '홍길동', 'ko');

        // data 에 전화번호 없음 + 게스트라 mobile 속성 없음
        $this->expectException(NotificationSendSkippedException::class);

        try {
            $this->makeDriver($this->fakeTemplate())->send($guest, $this->notification([]));
        } finally {
            Bus::assertNotDispatched(SendMessageJob::class);
        }
    }

    public function test_짧은_본문은_sm_s로_긴_본문은_lm_s로_보낸다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '01011112222']);

        // SMS (90 byte 이하)
        $smsBuilder = $this->spyBuilder();
        $this->makeDriver($this->fakeTemplate(['subject' => '제목', 'body' => '짧은 본문']), $smsBuilder)
            ->send($member, $this->notification());
        $this->assertSame('sms', $smsBuilder->calls[0]['type']);

        // LMS (90 byte 초과 — 한글 45자 이상 = EUC-KR 90byte 초과)
        $longBody = str_repeat('가', 100);
        $lmsBuilder = $this->spyBuilder();
        $this->makeDriver($this->fakeTemplate(['subject' => '제목', 'body' => $longBody]), $lmsBuilder)
            ->send($member, $this->notification());
        $this->assertSame('lms', $lmsBuilder->calls[0]['type']);
        $this->assertSame('제목', $lmsBuilder->calls[0]['subject'], 'LMS 는 subject(코어 제목)를 재사용해야 한다.');
    }

    public function test_본문이_비어있으면_예외를_던지고_발송하지_않는다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '01011112222']);

        $this->expectException(NotificationSendSkippedException::class);

        try {
            $this->makeDriver($this->fakeTemplate(['subject' => '', 'body' => '   ']))
                ->send($member, $this->notification());
        } finally {
            Bus::assertNotDispatched(SendMessageJob::class);
        }
    }

    public function test_generic_notification이_아니면_무시한다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '01011112222']);
        $other = new Notification;

        $this->makeDriver($this->fakeTemplate())->send($member, $other);

        Bus::assertNotDispatched(SendMessageJob::class);
    }

    public function test_정상_조건에서_발송하고_이력을_생성한다(): void
    {
        // 비활성 확장 채널의 발송 차단은 코어 via() 책임이며 GenericNotificationViaTest 가 검증한다.
        // 이 드라이버 테스트는 정상 조건(활성 전제)에서의 발송·이력 생성만 검증한다.
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        $this->makeDriver($this->fakeTemplate())->send($member, $this->notification());

        Bus::assertDispatched(SendMessageJob::class);
        $this->assertDatabaseCount('bizppurio_dispatches', 1);
    }

    public function test_검수_모드_설정값이_이력에_스냅샷으로_기록된다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        $this->makeDriver($this->fakeTemplate(), isTestMode: true)->send($member, $this->notification());

        $this->assertTrue(BizppurioDispatch::first()->is_test_mode);
    }

    public function test_운영_모드_설정값도_이력에_스냅샷으로_기록된다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        $this->makeDriver($this->fakeTemplate(), isTestMode: false)->send($member, $this->notification());

        $this->assertFalse(BizppurioDispatch::first()->is_test_mode);
    }
}
