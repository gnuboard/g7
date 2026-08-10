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
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioNotificationBinding;
use Plugins\Sirsoft\MessageBizppurio\Repositories\BizppurioDispatchRepository;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioNotificationBindingRepositoryInterface;
use Plugins\Sirsoft\MessageBizppurio\Services\AlimtalkChannelDriver;
use Plugins\Sirsoft\MessageBizppurio\Services\AlimtalkPayloadMapper;
use Plugins\Sirsoft\MessageBizppurio\Services\DispatchLinkContext;
use Plugins\Sirsoft\MessageBizppurio\Services\KakaoTemplateContentResolver;
use Plugins\Sirsoft\MessageBizppurio\Services\MessagePayloadBuilder;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * AlimtalkChannelDriver — binding 게이트·변수치환({x}→#{x})·대체발송·Job 위임 검증.
 *
 * 알림톡은 SMS 와 달리 "관리자가 연결한 승인 템플릿(binding)"이 있을 때만 발송된다.
 * 발송 Job dispatch 는 Bus::fake 로 관찰한다(실제 발송은 SendMessageJobTest 가 커버).
 */
class AlimtalkChannelDriverTest extends PluginTestCase
{
    /**
     * 렌더 결과를 반환하는 NotificationTemplate mock 을 만듭니다.
     *
     * @param  array<string, string>  $rendered  replaceVariables 반환값
     */
    private function fakeTemplate(array $rendered = ['subject' => '', 'body' => '{name} 님 주문이 완료되었습니다.']): NotificationTemplate
    {
        $template = Mockery::mock(NotificationTemplate::class)->makePartial();
        $template->is_active = true;
        $template->shouldReceive('replaceVariables')->andReturn($rendered);

        return $template;
    }

    /**
     * findActive 반환값을 지정한 binding 리포지토리 mock 을 만듭니다.
     *
     * @param  BizppurioNotificationBinding|null  $binding  findActive 반환값
     */
    private function fakeBindings(?BizppurioNotificationBinding $binding): BizppurioNotificationBindingRepositoryInterface
    {
        $repo = Mockery::mock(BizppurioNotificationBindingRepositoryInterface::class);
        $repo->shouldReceive('findActive')
            ->with(Mockery::any(), 'alimtalk')
            ->andReturn($binding);

        return $repo;
    }

    /**
     * 연결(binding) 모델을 만듭니다.
     */
    private function binding(bool $fallback = false): BizppurioNotificationBinding
    {
        $binding = new BizppurioNotificationBinding;
        $binding->notification_type = 'order_confirmed';
        $binding->channel = 'alimtalk';
        $binding->template_code = 'TW_1234';
        $binding->template_name = '주문완료';
        $binding->fallback_sms_enabled = $fallback;
        $binding->is_active = true;

        return $binding;
    }

    /**
     * 카카오 상세조회 내용을 반환하는 KakaoTemplateContentResolver mock 을 만듭니다.
     *
     * @param  array<string, mixed>|null  $content  resolve 반환값 (null=조회 실패 → skip)
     */
    private function fakeKakaoContent(?array $content): KakaoTemplateContentResolver
    {
        $resolver = Mockery::mock(KakaoTemplateContentResolver::class);
        $resolver->shouldReceive('resolve')->andReturn($content);

        return $resolver;
    }

    /**
     * 기본 카카오 상세(본문만 있는 단순 승인 템플릿).
     *
     * @return array<string, mixed>
     */
    private function kakaoContent(string $templateContent = '#{name}님 주문이 완료되었습니다.'): array
    {
        return ['templateCode' => 'TW_1234', 'templateContent' => $templateContent];
    }

    /**
     * 템플릿·binding·빌더를 조합한 driver 를 만듭니다.
     *
     * @param  array<string, mixed>|null  $kakao  카카오 상세조회 내용 (null=조회 실패)
     * @param  bool  $isTestMode  검수 모드 설정값 (이력 스냅샷 검증용)
     */
    private function makeDriver(
        ?NotificationTemplate $template,
        ?BizppurioNotificationBinding $binding,
        ?MessagePayloadBuilder $builder = null,
        array|false|null $kakao = false,
        bool $isTestMode = true,
    ): AlimtalkChannelDriver {
        $templateService = Mockery::mock(NotificationTemplateService::class);
        $templateService->shouldReceive('resolve')
            ->with(Mockery::any(), 'alimtalk')
            ->andReturn($template);

        // $kakao 기본값 false = "기본 카카오 내용 제공"(대부분 테스트). null = 조회 실패.
        $content = $kakao === false ? $this->kakaoContent() : $kakao;

        $pluginSettings = Mockery::mock(PluginSettingsService::class);
        $pluginSettings->shouldReceive('get')
            ->with('sirsoft-message_bizppurio', 'is_test_mode', true)
            ->andReturn($isTestMode);

        // 스킵 예외 메시지의 알림 유형 라벨 조회 — 정의 없음(null)이면 resolveTypeLabel 이 코드값으로 폴백.
        $definitionService = Mockery::mock(NotificationDefinitionService::class);
        $definitionService->shouldReceive('resolve')->andReturn(null);

        return new AlimtalkChannelDriver(
            $templateService,
            $definitionService,
            $this->fakeBindings($binding),
            $builder ?? $this->spyBuilder(),
            new BizppurioDispatchRepository,
            new DispatchLinkContext,
            $this->fakeKakaoContent($content),
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

    public function test_연결된_템플릿이_없으면_예외를_던지고_발송하지_않는다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        // binding = null → 미연결 → NotificationSendSkippedException(코어가 실패로 기록하도록)
        $this->expectException(NotificationSendSkippedException::class);

        try {
            $this->makeDriver($this->fakeTemplate(), null)->send($member, $this->notification());
        } finally {
            Bus::assertNotDispatched(SendMessageJob::class);
            $this->assertDatabaseCount('bizppurio_dispatches', 0);
        }
    }

    public function test_스킵_예외_메시지는_알림_유형_코드값_대신_사람이_읽는_이름을_사용한다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        $definition = Mockery::mock(\App\Models\NotificationDefinition::class);
        $definition->shouldReceive('getLocalizedName')->andReturn('회원가입 환영');

        $definitionService = Mockery::mock(\App\Services\NotificationDefinitionService::class);
        $definitionService->shouldReceive('resolve')->with('order_confirmed')->andReturn($definition);

        $templateService = Mockery::mock(\App\Services\NotificationTemplateService::class);
        $templateService->shouldReceive('resolve')->with(Mockery::any(), 'alimtalk')->andReturn(null);

        $pluginSettings = Mockery::mock(\App\Services\PluginSettingsService::class);
        $pluginSettings->shouldReceive('get')->andReturn(true);

        $driver = new AlimtalkChannelDriver(
            $templateService,
            $definitionService,
            $this->fakeBindings(null),
            $this->spyBuilder(),
            new BizppurioDispatchRepository,
            new DispatchLinkContext,
            $this->fakeKakaoContent($this->kakaoContent()),
            new AlimtalkPayloadMapper,
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

    public function test_카카오_템플릿_내용_조회_실패시_예외를_던지고_발송하지_않는다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        // binding 은 있으나 카카오 상세조회 실패(고아·장애) → 알림톡 본문 소스 없음
        // → NotificationSendSkippedException(코어가 실패로 기록하도록)
        $this->expectException(NotificationSendSkippedException::class);

        try {
            $this->makeDriver($this->fakeTemplate(), $this->binding(), kakao: null)
                ->send($member, $this->notification());
        } finally {
            Bus::assertNotDispatched(SendMessageJob::class);
        }
    }

    public function test_카카오_치환_결과_본문이_비어있으면_예외를_던지고_발송하지_않는다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        // 카카오 templateContent 가 치환 후에도 빈 문자열이면 발송 불가
        $this->expectException(NotificationSendSkippedException::class);

        try {
            $this->makeDriver(
                $this->fakeTemplate(),
                $this->binding(),
                kakao: ['templateCode' => 'TW_1234', 'templateContent' => '   '],
            )->send($member, $this->notification());
        } finally {
            Bus::assertNotDispatched(SendMessageJob::class);
        }
    }

    public function test_코어_alimtalk_템플릿이_없어도_카카오_내용으로_발송한다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        // 알림톡 본문은 카카오에서 오므로, 코어 alimtalk 템플릿(SMS 대체용)이 없어도 발송돼야 한다.
        // 대체발송 OFF 이면 코어 템플릿을 아예 조회하지 않는다.
        $this->makeDriver(null, $this->binding(fallback: false))->send($member, $this->notification());

        Bus::assertDispatched(SendMessageJob::class);
    }

    public function test_회원은_mobile로_연결된_템플릿코드로_발송한다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);
        $builder = $this->spyBuilder();

        $this->makeDriver($this->fakeTemplate(), $this->binding(), $builder)
            ->send($member, $this->notification());

        Bus::assertDispatched(SendMessageJob::class);
        $this->assertCount(1, $builder->calls);
        $this->assertSame('01012345678', $builder->calls[0]['to']);
        $this->assertSame('TW_1234', $builder->calls[0]['templateCode'], '연결된 카카오 템플릿 코드로 발송해야 한다.');
    }

    public function test_비회원은_data의_전화번호로_발송한다(): void
    {
        Bus::fake();
        $guest = new GuestNotifiable('guest@example.com', '홍길동', 'ko');
        $data = ['name' => '홍길동', AlimtalkChannelDriver::RECIPIENT_PHONE_KEY => '010-9999-0000'];
        $builder = $this->spyBuilder();

        $this->makeDriver($this->fakeTemplate(), $this->binding(), $builder)
            ->send($guest, $this->notification($data));

        Bus::assertDispatched(SendMessageJob::class);
        $this->assertSame('01099990000', $builder->calls[0]['to']);
    }

    public function test_카카오_본문의_변수를_알림_data로_치환해_발송한다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '01011112222']);
        $builder = $this->spyBuilder();

        // 알림톡 본문은 카카오 승인 템플릿(#{var})에서 오고, 발송 시 알림 data 로 치환된다.
        $this->makeDriver(
            $this->fakeTemplate(),
            $this->binding(),
            $builder,
            kakao: ['templateCode' => 'TW_1234', 'templateContent' => '#{name}님 #{order_number} 주문 완료'],
        )->send($member, $this->notification(['name' => '김철수', 'order_number' => 'A1']));

        $this->assertSame(
            '김철수님 A1 주문 완료',
            $builder->calls[0]['message'],
            '카카오 본문의 #{var} 를 알림 data 로 치환해야 한다.',
        );
    }

    public function test_카카오_버튼을_발송_extra로_전달한다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '01011112222']);

        // 버튼 URL 변수까지 치환돼 payload button 에 실려야 한다(회귀: 이전엔 버튼 자체가 누락).
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
            $this->fakeTemplate(),
            $this->binding(),
            $builder,
            kakao: [
                'templateCode' => 'TW_1234',
                'templateContent' => '#{name}님 주문 완료',
                'buttons' => [
                    ['name' => '주문조회', 'linkType' => 'WL', 'linkMo' => 'https://m.shop/orders/#{order_number}'],
                ],
            ],
        )->send($member, $this->notification(['name' => '김철수', 'order_number' => 'A1']));

        $button = $builder->extras[0]['button'][0];
        $this->assertSame('WL', $button['type']);
        $this->assertSame('https://m.shop/orders/A1', $button['url_mobile'], '버튼 URL 변수도 치환돼 발송돼야 한다.');
    }

    public function test_대체발송_o_n이면_payload에_sms_resend가_병합된다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '01011112222']);

        // 실제 payload 병합을 관찰하려면 실 빌더가 필요하므로, buildAlimtalk 만 최소 stub 하고
        // withSmsFallback 결과를 dispatch 된 Job payload 로 검증한다.
        $builder = new class extends MessagePayloadBuilder
        {
            public function __construct() {}

            public function buildAlimtalk(string $to, string $templateCode, string $message, string $refkey, array $extra = []): array
            {
                return ['type' => 'at', 'to' => $to, 'refkey' => $refkey, 'content' => ['at' => ['message' => $message]]];
            }
        };

        // replaceVariables 는 치환 완료본을 반환하므로 mock body 도 치환 완료 텍스트로 준다.
        // 드라이버는 이 body 를 알림톡 본문(#{var} 변환)과 대체 SMS 본문(원문 그대로) 두 곳에 쓴다.
        $this->makeDriver(
            $this->fakeTemplate(['subject' => '', 'body' => '김철수 님 주문 완료']),
            $this->binding(fallback: true),
            $builder,
        )->send($member, $this->notification());

        Bus::assertDispatched(SendMessageJob::class, function (SendMessageJob $job) {
            $this->assertSame(['first' => 'sms'], $job->payload['resend'] ?? null, '대체발송 ON 은 resend:{first:sms} 를 넣어야 한다.');
            $this->assertArrayHasKey('recontent', $job->payload);
            $this->assertSame('김철수 님 주문 완료', $job->payload['recontent']['sms']['message'] ?? null, '대체 SMS 본문은 치환 완료된 코어 본문(#{var} 미변환)이어야 한다.');

            return true;
        });
    }

    public function test_대체발송_of_f이면_resend가_없다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '01011112222']);

        $this->makeDriver($this->fakeTemplate(), $this->binding(fallback: false))
            ->send($member, $this->notification());

        Bus::assertDispatched(SendMessageJob::class, function (SendMessageJob $job) {
            $this->assertArrayNotHasKey('resend', $job->payload);

            return true;
        });
    }

    public function test_회원_발송_시_pending_이력을_alimtalk_채널로_생성한다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678', 'name' => '김철수']);

        $this->makeDriver($this->fakeTemplate(), $this->binding())->send($member, $this->notification());

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

        $this->makeDriver($this->fakeTemplate(), $this->binding(), $builder)
            ->send($member, $this->notification());

        $dispatch = BizppurioDispatch::first();
        $this->assertNotNull($dispatch->request_payload, '결함① — 실제 비즈뿌리오 전송 payload 가 이력에 저장돼야 한다.');
        $this->assertSame('TW_1234', $dispatch->request_payload['templatecode'] ?? null);
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

        $this->makeDriver($this->fakeTemplate(), $this->binding(), $realBuilder)
            ->send($member, $this->notification());

        $dispatch = BizppurioDispatch::first();
        $payload = $dispatch->request_payload;

        $this->assertArrayNotHasKey('to', $payload, 'to 는 to_number 컬럼과 중복이라 제외돼야 한다.');
        $this->assertArrayNotHasKey('refkey', $payload, 'refkey 는 refkey 컬럼과 중복이라 제외돼야 한다.');
        $this->assertArrayNotHasKey('type', $payload, 'type 은 channel 컬럼과 중복이라 제외돼야 한다.');
        $this->assertArrayNotHasKey('message', $payload['content']['at'] ?? [], 'message 는 content 컬럼과 중복이라 제외돼야 한다.');
        $this->assertArrayHasKey('templatecode', $payload['content']['at'] ?? [], 'templatecode 는 다른 컬럼에 없으므로 남아있어야 한다.');
    }

    public function test_전화번호가_없으면_예외를_던지고_발송하지_않는다(): void
    {
        Bus::fake();
        $guest = new GuestNotifiable('guest@example.com', '홍길동', 'ko');

        $this->expectException(NotificationSendSkippedException::class);

        try {
            $this->makeDriver($this->fakeTemplate(), $this->binding())
                ->send($guest, $this->notification(['name' => '홍길동']));
        } finally {
            Bus::assertNotDispatched(SendMessageJob::class);
        }
    }

    public function test_generic_notification이_아니면_무시한다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '01011112222']);

        $this->makeDriver($this->fakeTemplate(), $this->binding())->send($member, new Notification);

        Bus::assertNotDispatched(SendMessageJob::class);
    }

    public function test_정상_조건에서_발송하고_이력을_생성한다(): void
    {
        // 비활성 확장 채널의 발송 차단은 코어 via() 책임이며 GenericNotificationViaTest 가 검증한다.
        // 이 드라이버 테스트는 정상 조건(활성 전제)에서의 발송·이력 생성만 검증한다.
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        $this->makeDriver($this->fakeTemplate(), $this->binding())->send($member, $this->notification());

        Bus::assertDispatched(SendMessageJob::class);
        $this->assertDatabaseCount('bizppurio_dispatches', 1);
    }

    public function test_검수_모드_설정값이_이력에_스냅샷으로_기록된다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        $this->makeDriver($this->fakeTemplate(), $this->binding(), isTestMode: true)
            ->send($member, $this->notification());

        $this->assertTrue(BizppurioDispatch::first()->is_test_mode);
    }

    public function test_운영_모드_설정값도_이력에_스냅샷으로_기록된다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        $this->makeDriver($this->fakeTemplate(), $this->binding(), isTestMode: false)
            ->send($member, $this->notification());

        $this->assertFalse(BizppurioDispatch::first()->is_test_mode);
    }
}
