<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Services;

use App\Models\NotificationDefinition;
use App\Models\User;
use App\Notifications\GenericNotification;
use App\Notifications\GuestNotifiable;
use App\Services\NotificationDefinitionService;
use App\Services\PluginSettingsService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery;
use Plugins\Sirsoft\MessageBizppurio\Enums\BizppurioTemplateStatus;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\NotificationSendSkippedException;
use Plugins\Sirsoft\MessageBizppurio\Jobs\SendMessageJob;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioDispatch;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioTemplate;
use Plugins\Sirsoft\MessageBizppurio\Repositories\BizppurioDispatchRepository;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioTemplateRepositoryInterface;
use Plugins\Sirsoft\MessageBizppurio\Services\AlimtalkChannelDriver;
use Plugins\Sirsoft\MessageBizppurio\Services\AlimtalkPayloadMapper;
use Plugins\Sirsoft\MessageBizppurio\Services\DispatchLinkContext;
use Plugins\Sirsoft\MessageBizppurio\Services\MessagePayloadBuilder;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * AlimtalkChannelDriver — 승인 스냅샷 게이트·변수치환·대체발송·Job 위임 검증 (#597 §3.5).
 *
 * 알림톡 본문·요소의 출처는 bizppurio_templates 의 approved_content(승인 스냅샷)다.
 * 게이트 = alimtalk_enabled + is_active + status=approved + approved_content 존재.
 * 발송 Job dispatch 는 Bus::fake 로 관찰한다(실제 발송은 SendMessageJobTest 가 커버).
 */
class AlimtalkChannelDriverTest extends PluginTestCase
{
    /**
     * 승인 스냅샷을 담은 템플릿 행을 만듭니다.
     *
     * @param  array<string, mixed>|null  $approved  approved_content
     */
    private function templateRow(
        ?array $approved = null,
        BizppurioTemplateStatus $status = BizppurioTemplateStatus::Approved,
        bool $alimtalkEnabled = true,
        bool $isActive = true,
        bool $fallback = false,
        string|array|null $smsBody = null,
    ): BizppurioTemplate {
        $row = new BizppurioTemplate;
        $row->notification_type = 'order_confirmed';
        $row->alimtalk_enabled = $alimtalkEnabled;
        $row->template_code = 'g7_abcd1234_1';
        $row->status = $status;
        $row->approved_content = $approved ?? ['templateContent' => '#{name}님 주문이 완료되었습니다.'];
        $row->fallback_sms_enabled = $fallback;
        // sms_body 는 로케일 맵이다(#597 §14.3). 문자열은 ko 본문으로 취급한다.
        $row->sms_body = is_string($smsBody) ? ['ko' => $smsBody] : $smsBody;
        $row->is_active = $isActive;

        return $row;
    }

    /**
     * findByType 반환값을 지정한 템플릿 리포지토리 mock 을 만듭니다.
     */
    private function fakeTemplates(?BizppurioTemplate $row): BizppurioTemplateRepositoryInterface
    {
        $repo = Mockery::mock(BizppurioTemplateRepositoryInterface::class);
        $repo->shouldReceive('findByType')->andReturn($row);

        return $repo;
    }

    /**
     * 드라이버를 조립합니다.
     */
    private function makeDriver(
        ?BizppurioTemplate $row,
        ?MessagePayloadBuilder $builder = null,
        bool $isTestMode = true,
    ): AlimtalkChannelDriver {
        $pluginSettings = Mockery::mock(PluginSettingsService::class);
        $pluginSettings->shouldReceive('get')
            ->with('sirsoft-message_bizppurio', 'is_test_mode', true)
            ->andReturn($isTestMode);

        // 스킵 예외 메시지의 알림 유형 라벨 조회 — 정의 없음(null)이면 resolveTypeLabel 이 코드값으로 폴백.
        $definitionService = Mockery::mock(NotificationDefinitionService::class);
        $definitionService->shouldReceive('resolve')->andReturn(null);

        return new AlimtalkChannelDriver(
            $definitionService,
            $this->fakeTemplates($row),
            $builder ?? $this->spyBuilder(),
            new BizppurioDispatchRepository,
            new DispatchLinkContext,
            new AlimtalkPayloadMapper,
            $pluginSettings,
        );
    }

    /**
     * buildAlimtalk 호출 인자를 기록하는 payload 빌더 스파이를 만듭니다.
     */
    private function spyBuilder(): MessagePayloadBuilder
    {
        return new class extends MessagePayloadBuilder
        {
            /** @var array<int, array<string, mixed>> */
            public array $calls = [];

            public function __construct() {}

            public function buildAlimtalk(
                string $to,
                string $templateCode,
                string $message,
                string $refkey,
                array $extra = [],
            ): array {
                $this->calls[] = [
                    'to' => $to,
                    'templateCode' => $templateCode,
                    'message' => $message,
                    'refkey' => $refkey,
                ];

                return ['type' => 'at', 'to' => $to, 'refkey' => $refkey, 'templatecode' => $templateCode];
            }
        };
    }

    private function notification(array $data = ['name' => '김철수']): GenericNotification
    {
        return new GenericNotification('order_confirmed', 'sirsoft-ecommerce', $data, 'module', 'sirsoft-ecommerce');
    }

    /**
     * @scenario template_state=row_missing, fallback_sms=off, sms_only=off, recipient=member
     *
     * @effects alimtalk_skipped_when_row_missing, no_kapi_call_at_dispatch_time
     */
    public function test_템플릿_행이_없으면_예외를_던지고_발송하지_않는다(): void
    {
        Bus::fake();
        Http::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        $this->expectException(NotificationSendSkippedException::class);

        try {
            $this->makeDriver(null)->send($member, $this->notification());
        } finally {
            Bus::assertNotDispatched(SendMessageJob::class);
            $this->assertDatabaseCount('bizppurio_dispatches', 0);
            // 발송 판정은 DB 행만 본다 — 게이트 불충족 시에도 카카오 조회가 없어야 한다(§3.4).
            Http::assertNothingSent();
        }
    }

    /**
     * @scenario template_state=unapproved, fallback_sms=off, sms_only=off, recipient=member
     *
     * @effects alimtalk_skipped_when_not_approved, no_kapi_call_at_dispatch_time
     */
    public function test_미승인_상태면_예외를_던지고_발송하지_않는다(): void
    {
        Bus::fake();
        Http::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        // draft 상태 — 승인 스냅샷이 남아 있어도(승인취소 복귀) status 가 approved 가 아니면 차단.
        $this->expectException(NotificationSendSkippedException::class);

        try {
            $this->makeDriver($this->templateRow(status: BizppurioTemplateStatus::Draft))
                ->send($member, $this->notification());
        } finally {
            Bus::assertNotDispatched(SendMessageJob::class);
            Http::assertNothingSent();
        }
    }

    /**
     * @scenario template_state=disabled, fallback_sms=off, sms_only=off, recipient=member
     *
     * @effects alimtalk_skipped_when_alimtalk_disabled, no_kapi_call_at_dispatch_time
     */
    public function test_알림톡_사용이_꺼져_있으면_발송하지_않는다(): void
    {
        Bus::fake();
        Http::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        $this->expectException(NotificationSendSkippedException::class);

        try {
            $this->makeDriver($this->templateRow(alimtalkEnabled: false))
                ->send($member, $this->notification());
        } finally {
            Bus::assertNotDispatched(SendMessageJob::class);
            Http::assertNothingSent();
        }
    }

    /**
     * @scenario template_state=disabled, fallback_sms=off, sms_only=off, recipient=member
     *
     * @effects alimtalk_skipped_when_row_inactive
     */
    public function test_비활성_행이면_발송하지_않는다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        $this->expectException(NotificationSendSkippedException::class);

        try {
            $this->makeDriver($this->templateRow(isActive: false))
                ->send($member, $this->notification());
        } finally {
            Bus::assertNotDispatched(SendMessageJob::class);
        }
    }

    /**
     * @scenario template_state=unapproved, fallback_sms=off, sms_only=off, recipient=member
     *
     * @effects alimtalk_skipped_when_snapshot_missing
     */
    public function test_승인_스냅샷이_없으면_발송하지_않는다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        $row = $this->templateRow();
        $row->approved_content = null;

        $this->expectException(NotificationSendSkippedException::class);

        try {
            $this->makeDriver($row)->send($member, $this->notification());
        } finally {
            Bus::assertNotDispatched(SendMessageJob::class);
        }
    }

    /**
     * @scenario template_state=approved, fallback_sms=off, sms_only=on, recipient=member
     *
     * @effects alimtalk_skipped_when_sms_only_selected, no_kapi_call_at_dispatch_time
     */
    public function test_sms_단독을_선택한_알림은_승인되어도_알림톡을_발송하지_않는다(): void
    {
        Bus::fake();
        Http::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        $row = $this->templateRow();
        $row->sms_only = true;

        $this->expectException(NotificationSendSkippedException::class);

        try {
            $this->makeDriver($row)->send($member, $this->notification());
        } finally {
            Bus::assertNotDispatched(SendMessageJob::class);
            Http::assertNothingSent();
        }
    }

    /**
     * @effects skip_exception_uses_human_readable_type_label
     */
    public function test_스킵_예외_메시지는_알림_유형_코드값_대신_사람이_읽는_이름을_사용한다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        $definition = Mockery::mock(NotificationDefinition::class);
        $definition->shouldReceive('getLocalizedName')->andReturn('주문 완료');

        $definitionService = Mockery::mock(NotificationDefinitionService::class);
        $definitionService->shouldReceive('resolve')->with('order_confirmed')->andReturn($definition);

        $pluginSettings = Mockery::mock(PluginSettingsService::class);
        $pluginSettings->shouldReceive('get')->andReturn(true);

        $driver = new AlimtalkChannelDriver(
            $definitionService,
            $this->fakeTemplates(null),
            $this->spyBuilder(),
            new BizppurioDispatchRepository,
            new DispatchLinkContext,
            new AlimtalkPayloadMapper,
            $pluginSettings,
        );

        try {
            $driver->send($member, $this->notification());
            $this->fail('NotificationSendSkippedException 가 발생해야 한다.');
        } catch (NotificationSendSkippedException $e) {
            $this->assertStringContainsString('주문 완료', $e->getMessage(), '코드값(order_confirmed) 대신 사람이 읽는 이름이 노출돼야 한다.');
            $this->assertStringNotContainsString('order_confirmed', $e->getMessage());
        }
    }

    public function test_스냅샷_치환_결과_본문이_비어있으면_예외를_던지고_발송하지_않는다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        $this->expectException(NotificationSendSkippedException::class);

        try {
            $this->makeDriver($this->templateRow(approved: ['templateContent' => '   ']))
                ->send($member, $this->notification());
        } finally {
            Bus::assertNotDispatched(SendMessageJob::class);
        }
    }

    /**
     * @scenario template_state=approved, fallback_sms=off, sms_only=off, recipient=member
     *
     * @effects dispatch_uses_row_template_code, no_kapi_call_at_dispatch_time
     */
    public function test_회원은_mobile로_자체_채번_템플릿코드로_발송한다(): void
    {
        Bus::fake();
        Http::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);
        $builder = $this->spyBuilder();

        $this->makeDriver($this->templateRow(), $builder)->send($member, $this->notification());

        Bus::assertDispatched(SendMessageJob::class);
        $this->assertCount(1, $builder->calls);
        $this->assertSame('01012345678', $builder->calls[0]['to']);
        $this->assertSame('g7_abcd1234_1', $builder->calls[0]['templateCode'], '행의 template_code 로 발송해야 한다.');
        // 본문·버튼은 승인 스냅샷에서 온다 — 발송 시점에 카카오를 조회하지 않는다(§3.4).
        Http::assertNothingSent();
    }

    /**
     * @scenario template_state=approved, fallback_sms=off, sms_only=off, recipient=guest
     *
     * @effects guest_phone_resolved_from_data_recipient_phone
     */
    public function test_비회원은_data의_전화번호로_발송한다(): void
    {
        Bus::fake();
        $guest = new GuestNotifiable('guest@example.com', '홍길동', 'ko');
        $data = ['name' => '홍길동', AlimtalkChannelDriver::RECIPIENT_PHONE_KEY => '010-9999-0000'];
        $builder = $this->spyBuilder();

        $this->makeDriver($this->templateRow(), $builder)->send($guest, $this->notification($data));

        Bus::assertDispatched(SendMessageJob::class);
        $this->assertSame('01099990000', $builder->calls[0]['to']);
    }

    /**
     * @effects snapshot_content_substituted_with_notification_data
     */
    public function test_스냅샷_본문의_변수를_알림_data로_치환해_발송한다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '01011112222']);
        $builder = $this->spyBuilder();

        $this->makeDriver(
            $this->templateRow(approved: ['templateContent' => '#{name}님 #{order_number} 주문 완료']),
            $builder,
        )->send($member, $this->notification(['name' => '김철수', 'order_number' => 'A1']));

        $this->assertSame(
            '김철수님 A1 주문 완료',
            $builder->calls[0]['message'],
            '승인 스냅샷의 #{var} 를 알림 data 로 치환해야 한다.',
        );
    }

    /**
     * @effects snapshot_buttons_mapped_to_dispatch_button_fields
     */
    public function test_스냅샷_버튼을_발송_extra로_전달한다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '01011112222']);

        // 등록 페이로드(buttons[].linkMo)와 발송 규격(button[].url_mobile)의 필드 대응이
        // 매퍼에서 유지돼야 한다 — 스냅샷은 kapi add 페이로드 형태 그대로다.
        $builder = new class extends MessagePayloadBuilder
        {
            /** @var array<int, array<string, mixed>> */
            public array $extras = [];

            public function __construct() {}

            public function buildAlimtalk(string $to, string $templateCode, string $message, string $refkey, array $extra = []): array
            {
                $this->extras[] = $extra;

                return ['type' => 'at', 'to' => $to, 'refkey' => $refkey, 'content' => ['at' => array_merge(['message' => $message], $extra)]];
            }
        };

        $this->makeDriver(
            $this->templateRow(approved: [
                'templateContent' => '#{name}님 주문 완료',
                'buttons' => [
                    ['name' => '주문조회', 'linkType' => 'WL', 'linkMo' => 'https://m.shop/orders/#{order_number}'],
                ],
            ]),
            $builder,
        )->send($member, $this->notification(['name' => '김철수', 'order_number' => 'A1']));

        $button = $builder->extras[0]['button'][0];
        $this->assertSame('WL', $button['type']);
        $this->assertSame('https://m.shop/orders/A1', $button['url_mobile'], '버튼 URL 변수도 치환돼 발송돼야 한다.');
    }

    /**
     * @scenario template_state=approved, fallback_sms=on, sms_only=off, recipient=member
     *
     * @effects fallback_on_merges_resend_recontent_from_sms_body
     */
    public function test_대체발송_on이면_행의_sms_body가_resend로_병합된다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '01011112222']);

        $builder = new class extends MessagePayloadBuilder
        {
            public function __construct() {}

            public function buildAlimtalk(string $to, string $templateCode, string $message, string $refkey, array $extra = []): array
            {
                return ['type' => 'at', 'to' => $to, 'refkey' => $refkey, 'content' => ['at' => ['message' => $message]]];
            }
        };

        // 대체 SMS 본문 소스는 코어 템플릿이 아니라 행의 sms_body(#{var} 치환)다 (#597 §3.5).
        $this->makeDriver(
            $this->templateRow(fallback: true, smsBody: '[샵] #{name} 님 주문 완료'),
            $builder,
        )->send($member, $this->notification(['name' => '김철수']));

        Bus::assertDispatched(SendMessageJob::class, function (SendMessageJob $job) {
            $this->assertSame(['first' => 'sms'], $job->payload['resend'] ?? null, '대체발송 ON 은 resend:{first:sms} 를 넣어야 한다.');
            $this->assertSame('[샵] 김철수 님 주문 완료', $job->payload['recontent']['sms']['message'] ?? null, '대체 SMS 본문은 행의 sms_body 를 치환한 값이어야 한다.');

            return true;
        });
    }

    /**
     * @scenario template_state=approved, fallback_sms=on, sms_only=off, recipient=guest
     *
     * @effects fallback_on_merges_resend_recontent_from_sms_body, guest_phone_resolved_from_data_recipient_phone
     */
    public function test_비회원도_대체발송_on이면_같은_전화번호로_resend가_병합된다(): void
    {
        Bus::fake();
        $guest = new GuestNotifiable('guest@example.com', '홍길동', 'ko');
        $data = ['name' => '홍길동', AlimtalkChannelDriver::RECIPIENT_PHONE_KEY => '010-9999-0000'];

        // 대체 SMS 는 비즈뿌리오가 같은 수신번호로 재발송한다(resend 위임) — 비회원도 동일 경로다.
        $this->makeDriver($this->templateRow(fallback: true, smsBody: '[샵] #{name} 님 주문 완료'))
            ->send($guest, $this->notification($data));

        Bus::assertDispatched(SendMessageJob::class, function (SendMessageJob $job) {
            $this->assertSame(['first' => 'sms'], $job->payload['resend'] ?? null);
            $this->assertSame('[샵] 홍길동 님 주문 완료', $job->payload['recontent']['sms']['message'] ?? null);
            $this->assertSame('01099990000', $job->payload['to'] ?? null, '대체 SMS 도 알림톡과 같은 수신번호를 쓴다.');

            return true;
        });
    }

    /**
     * 대체 SMS 도 수신자 로케일로 렌더된다 (#597 §14.3).
     *
     * 같은 알림의 SMS 단독 발송(SmsChannelDriver)과 대체발송이 서로 다른 언어로 나가면
     * 안 된다 — 두 경로가 같은 sms_body 맵을 같은 규칙으로 읽는지 고정한다.
     *
     * @scenario template_state=approved, fallback_sms=on, sms_only=off, recipient=guest
     *
     * @effects fallback_on_merges_resend_recontent_from_sms_body, sms_body_rendered_in_recipient_locale
     */
    public function test_대체발송_본문도_수신자_로케일로_렌더된다(): void
    {
        Bus::fake();
        $guest = new GuestNotifiable('guest@example.com', 'John', 'en');
        $data = ['name' => 'John', AlimtalkChannelDriver::RECIPIENT_PHONE_KEY => '010-9999-0000'];

        $this->makeDriver($this->templateRow(fallback: true, smsBody: [
            'ko' => '[샵] #{name} 님 주문 완료',
            'en' => '[Shop] #{name}, your order is complete.',
        ]))->send($guest, $this->notification($data));

        Bus::assertDispatched(SendMessageJob::class, function (SendMessageJob $job) {
            $this->assertSame(
                '[Shop] John, your order is complete.',
                $job->payload['recontent']['sms']['message'] ?? null,
                '대체 SMS 본문은 수신자 로케일(en)로 렌더되어야 한다.'
            );

            return true;
        });
    }

    /**
     * @effects fallback_on_with_empty_sms_body_skips_merge
     */
    public function test_대체발송_on이라도_sms_body가_비어있으면_resend를_병합하지_않는다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '01011112222']);

        $this->makeDriver($this->templateRow(fallback: true, smsBody: '  '))
            ->send($member, $this->notification());

        Bus::assertDispatched(SendMessageJob::class, function (SendMessageJob $job) {
            $this->assertArrayNotHasKey('resend', $job->payload, '빈 대체 본문으로 빈 SMS 를 보내면 안 된다.');

            return true;
        });
    }

    /**
     * @effects fallback_off_has_no_resend
     */
    public function test_대체발송_off이면_resend가_없다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '01011112222']);

        $this->makeDriver($this->templateRow(fallback: false, smsBody: '대체 본문'))
            ->send($member, $this->notification());

        Bus::assertDispatched(SendMessageJob::class, function (SendMessageJob $job) {
            $this->assertArrayNotHasKey('resend', $job->payload);

            return true;
        });
    }

    /**
     * @effects pending_dispatch_row_created_with_channel_and_user
     */
    public function test_회원_발송_시_pending_이력을_alimtalk_채널로_생성한다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678', 'name' => '김철수']);

        $this->makeDriver($this->templateRow())->send($member, $this->notification());

        $this->assertDatabaseCount('bizppurio_dispatches', 1);
        $dispatch = BizppurioDispatch::first();
        $this->assertSame('pending', $dispatch->status->value);
        $this->assertSame('alimtalk', $dispatch->channel->value);
        $this->assertSame($member->id, $dispatch->to_user_id);
        $this->assertSame('order_confirmed', $dispatch->notification_type);
    }

    public function test_발송_시_실제_전송_payload가_이력에_저장된다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);
        $builder = $this->spyBuilder();

        $this->makeDriver($this->templateRow(), $builder)->send($member, $this->notification());

        $dispatch = BizppurioDispatch::first();
        $this->assertNotNull($dispatch->request_payload, '실제 비즈뿌리오 전송 payload 가 이력에 저장돼야 한다.');
        $this->assertSame('g7_abcd1234_1', $dispatch->request_payload['templatecode'] ?? null);
    }

    public function test_전화번호가_없으면_예외를_던지고_발송하지_않는다(): void
    {
        Bus::fake();
        $guest = new GuestNotifiable('guest@example.com', '홍길동', 'ko');

        $this->expectException(NotificationSendSkippedException::class);

        try {
            $this->makeDriver($this->templateRow())->send($guest, $this->notification(['name' => '홍길동']));
        } finally {
            Bus::assertNotDispatched(SendMessageJob::class);
        }
    }

    public function test_generic_notification이_아니면_무시한다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '01011112222']);

        $this->makeDriver($this->templateRow())->send($member, new Notification);

        Bus::assertNotDispatched(SendMessageJob::class);
    }

    public function test_검수_모드_설정값이_이력에_스냅샷으로_기록된다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        $this->makeDriver($this->templateRow(), isTestMode: true)->send($member, $this->notification());

        $this->assertTrue(BizppurioDispatch::first()->is_test_mode);
    }

    public function test_운영_모드_설정값도_이력에_스냅샷으로_기록된다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        $this->makeDriver($this->templateRow(), isTestMode: false)->send($member, $this->notification());

        $this->assertFalse(BizppurioDispatch::first()->is_test_mode);
    }
}
