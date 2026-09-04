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
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\MessageBizppurio\Enums\BizppurioTemplateStatus;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\NotificationSendSkippedException;
use Plugins\Sirsoft\MessageBizppurio\Jobs\SendMessageJob;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioDispatch;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioTemplate;
use Plugins\Sirsoft\MessageBizppurio\Repositories\BizppurioDispatchRepository;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioTemplateRepositoryInterface;
use Plugins\Sirsoft\MessageBizppurio\Services\AlimtalkPayloadMapper;
use Plugins\Sirsoft\MessageBizppurio\Services\DispatchLinkContext;
use Plugins\Sirsoft\MessageBizppurio\Services\MessagePayloadBuilder;
use Plugins\Sirsoft\MessageBizppurio\Services\SmsChannelDriver;
use Plugins\Sirsoft\MessageBizppurio\Services\SmsTypeResolver;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * SmsChannelDriver — 전화번호 해석·sms_body 게이트·#{var} 치환·SMS/LMS 판별·Job 위임 검증
 * (#597 §3.5 — 본문 소스가 코어 템플릿에서 bizppurio_templates.sms_body 로 전환됨).
 *
 * 발송 Job dispatch 를 Bus::fake 로 관찰한다(실제 발송은 SendMessageJobTest 가 커버).
 */
class SmsChannelDriverTest extends PluginTestCase
{
    /**
     * sms_body 를 담은 템플릿 행을 만듭니다.
     *
     * sms_body 는 로케일 맵이다(#597 §14.3). 문자열을 넘기면 ko 본문으로 취급한다 —
     * 로케일 축과 무관한 기존 케이스가 맵 표기를 반복하지 않게 하기 위한 편의다.
     *
     * @param  string|array<string, string>|null  $smsBody  로케일 맵 또는 ko 본문
     */
    private function templateRow(
        string|array|null $smsBody = '주문이 완료되었습니다.',
        bool $isActive = true,
        bool $smsOnly = false,
    ): BizppurioTemplate {
        $row = new BizppurioTemplate;
        $row->notification_type = 'order_confirmed';
        $row->status = BizppurioTemplateStatus::Draft;
        $row->sms_body = is_string($smsBody) ? ['ko' => $smsBody] : $smsBody;
        $row->sms_only = $smsOnly;
        $row->is_active = $isActive;

        return $row;
    }

    /**
     * findByType 반환값을 지정한 driver 를 만듭니다.
     */
    private function makeDriver(
        ?BizppurioTemplate $row,
        ?MessagePayloadBuilder $builder = null,
        bool $isTestMode = true,
        ?NotificationDefinition $definition = null,
    ): SmsChannelDriver {
        $templates = Mockery::mock(BizppurioTemplateRepositoryInterface::class);
        $templates->shouldReceive('findByType')->andReturn($row);

        $pluginSettings = Mockery::mock(PluginSettingsService::class);
        $pluginSettings->shouldReceive('get')
            ->with('sirsoft-message_bizppurio', 'is_test_mode', true)
            ->andReturn($isTestMode);

        // 스킵 예외 메시지의 알림 유형 라벨 조회 — 정의 없음(null)이면 resolveTypeLabel 이 코드값으로 폴백.
        $definitionService = Mockery::mock(NotificationDefinitionService::class);
        $definitionService->shouldReceive('resolve')->andReturn($definition);

        return new SmsChannelDriver(
            $definitionService,
            $templates,
            new AlimtalkPayloadMapper,
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

    /**
     * @effects sms_skipped_when_body_empty_or_row_missing
     */
    public function test_템플릿_행이_없으면_예외를_던지고_발송하지_않는다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        $this->expectException(NotificationSendSkippedException::class);

        try {
            $this->makeDriver(null)->send($member, $this->notification());
        } finally {
            Bus::assertNotDispatched(SendMessageJob::class);
            $this->assertDatabaseCount('bizppurio_dispatches', 0);
        }
    }

    /**
     * @effects sms_skipped_when_body_empty_or_row_missing
     */
    public function test_sms_body가_비어있으면_예외를_던지고_발송하지_않는다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        $this->expectException(NotificationSendSkippedException::class);

        try {
            $this->makeDriver($this->templateRow(smsBody: '  '))->send($member, $this->notification());
        } finally {
            Bus::assertNotDispatched(SendMessageJob::class);
        }
    }

    public function test_비활성_행이면_발송하지_않는다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        $this->expectException(NotificationSendSkippedException::class);

        try {
            $this->makeDriver($this->templateRow(isActive: false))->send($member, $this->notification());
        } finally {
            Bus::assertNotDispatched(SendMessageJob::class);
        }
    }

    /**
     * sms_only 는 알림톡을 끄는 플래그지 SMS 를 켜거나 끄는 플래그가 아니다 (#597 §3.5).
     *
     * 두 값을 모두 태워야 "무관" 이 증명된다 — 한쪽만(특히 기본값과 같은 false 만) 넣으면
     * 이후 누군가 SmsChannelDriver 에 sms_only 분기를 넣어도 이 테스트는 green 으로 남는다.
     *
     * @effects sms_dispatches_regardless_of_sms_only_flag
     */
    #[DataProvider('smsOnlyProvider')]
    public function test_sms_body가_있으면_sms_only_값과_무관하게_발송한다(bool $smsOnly): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        $this->makeDriver($this->templateRow(smsOnly: $smsOnly))->send($member, $this->notification());

        Bus::assertDispatched(SendMessageJob::class);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function smsOnlyProvider(): array
    {
        return [
            'sms_only 꺼짐 (알림톡 병행)' => [false],
            'sms_only 켜짐 (문자 단독)' => [true],
        ];
    }

    public function test_회원은_mobile로_발송한다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);
        $builder = $this->spyBuilder();

        $this->makeDriver($this->templateRow(), $builder)->send($member, $this->notification());

        Bus::assertDispatched(SendMessageJob::class);
        $this->assertSame('01012345678', $builder->calls[0]['to']);
    }

    public function test_비회원은_data의_전화번호로_발송한다(): void
    {
        Bus::fake();
        $guest = new GuestNotifiable('guest@example.com', '홍길동', 'ko');
        $data = [SmsChannelDriver::RECIPIENT_PHONE_KEY => '010-9999-0000'];
        $builder = $this->spyBuilder();

        $this->makeDriver($this->templateRow(), $builder)->send($guest, $this->notification($data));

        Bus::assertDispatched(SendMessageJob::class);
        $this->assertSame('01099990000', $builder->calls[0]['to']);
    }

    /**
     * @effects sms_body_is_dispatch_source_not_core_template
     */
    public function test_sms_body의_변수를_알림_data로_치환해_발송한다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '01011112222']);
        $builder = $this->spyBuilder();

        // sms_body 는 알림톡 본문과 동일한 #{var} 표기를 쓴다 (#597 §3.1).
        $this->makeDriver($this->templateRow(smsBody: '[샵] #{name}님 #{order_number} 주문 완료'), $builder)
            ->send($member, $this->notification(['name' => '김철수', 'order_number' => 'A1']));

        $this->assertSame('[샵] 김철수님 A1 주문 완료', $builder->calls[0]['message']);
    }

    /**
     * 수신자 로케일별 본문 선택 (#597 §14.3).
     *
     * 개편 과정에서 sms_body 가 단일 문자열이 되며 이 축이 사라진 적이 있다 — 코드에서
     * 로케일 해석을 빼도 어떤 테스트도 red 가 되지 않았고, 다국어 사이트의 en/ja 수신자가
     * ko 본문을 받는 것이 유일한 증상이었다. 이 테스트가 그 축을 고정한다.
     *
     * @effects sms_body_rendered_in_recipient_locale
     */
    #[DataProvider('recipientLocaleProvider')]
    public function test_sms_body는_수신자_로케일로_렌더된다(string $locale, string $expected): void
    {
        Bus::fake();
        $guest = new GuestNotifiable('guest@example.com', '홍길동', $locale);
        $builder = $this->spyBuilder();

        $this->makeDriver($this->templateRow(smsBody: [
            'ko' => '[샵] 주문이 완료되었습니다.',
            'en' => '[Shop] Your order is complete.',
        ]), $builder)->send($guest, $this->notification([
            SmsChannelDriver::RECIPIENT_PHONE_KEY => '010-9999-0000',
        ]));

        $this->assertSame($expected, $builder->calls[0]['message']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function recipientLocaleProvider(): array
    {
        return [
            'ko 수신자' => ['ko', '[샵] 주문이 완료되었습니다.'],
            'en 수신자' => ['en', '[Shop] Your order is complete.'],
            // 본문이 없는 로케일은 fallback_locale(ko)로 발송한다 — 빈 문자열로 스킵하지 않는다.
            '본문 없는 로케일 → ko 폴백' => ['ja', '[샵] 주문이 완료되었습니다.'],
        ];
    }

    /**
     * LMS 제목도 본문과 같은 수신자 로케일로 렌더된다 (#597 §15.1).
     *
     * 본문만 로케일을 따르고 제목이 앱 로케일이면 한 통 안에서 언어가 갈린다. 이 축은
     * 라운드 5 까지 무단언이었다 — effect 이름은 "…_definition_label_subject" 인데 어느
     * 테스트도 subject 를 읽지 않아, resolveTypeLabel 의 $locale 인자를 지워도 green 이었다.
     *
     * @effects sms_lms_split_by_body_bytes_with_definition_label_subject, sms_body_rendered_in_recipient_locale
     */
    public function test_lms_제목도_수신자_로케일로_렌더된다(): void
    {
        Bus::fake();
        $definition = new NotificationDefinition;
        $definition->forceFill([
            'type' => 'order_completed',
            'name' => ['ko' => '주문 완료', 'en' => 'Order completed'],
        ]);

        foreach ([['ko', '주문 완료'], ['en', 'Order completed']] as [$locale, $expected]) {
            $builder = $this->spyBuilder();
            $guest = new GuestNotifiable('guest@example.com', '홍길동', $locale);

            // 바이트 길이로 LMS 분기가 되도록 두 로케일 본문을 모두 길게 둔다.
            $this->makeDriver($this->templateRow(smsBody: [
                'ko' => str_repeat('가', 100),
                'en' => str_repeat('a', 300),
            ]), $builder, true, $definition)->send($guest, $this->notification([
                SmsChannelDriver::RECIPIENT_PHONE_KEY => '010-9999-0000',
            ]));

            $this->assertSame('lms', $builder->calls[0]['type']);
            $this->assertSame($expected, $builder->calls[0]['subject'], "{$locale} 수신자의 LMS 제목");
        }
    }

    /**
     * 행 게이트는 맵 전체를 보고, 본문 선택은 로케일별로 다시 판정한다 (#597 §14.3).
     *
     * 두 판정은 층이 다르다. 행 게이트(hasSmsBody)가 "현재 앱 로케일의 본문" 만 보면
     * ko 만 채운 알림이 en 관리자 세션에서 "본문 없음" 으로 잘못 판정된다. 반대로 행
     * 게이트만 통과시키고 끝내면 그 수신자의 로케일에 본문이 없을 때 빈 SMS 가 나간다.
     *
     * 로케일 해석은 코어 NotificationContentBehavior 와 동일한 체인이다 —
     * `수신자 로케일 → fallback_locale → ''`. fallback_locale(ko) 이 비어 있으면
     * 발송하지 않는다(개편 전 코어 템플릿 경로와 같은 결과).
     */
    public function test_fallback_locale에_본문이_없으면_발송하지_않는다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '01011112222']);

        // en 만 채운 행 — 행 게이트(hasSmsBody)는 통과하지만 ko 수신자용 본문이 없다.
        $row = $this->templateRow(smsBody: ['en' => '[Shop] Done']);
        $this->assertTrue($row->hasSmsBody(), '행 게이트는 어느 로케일에든 본문이 있으면 통과한다.');

        $this->expectException(NotificationSendSkippedException::class);

        try {
            $this->makeDriver($row)->send($member, $this->notification());
        } finally {
            Bus::assertNothingDispatched();
        }
    }

    /**
     * 모든 로케일이 빈 문자열이면 발송하지 않는다 (#597 §14.3).
     *
     * 맵이 존재한다는 사실만으로 게이트를 통과하면 빈 SMS 가 나간다.
     */
    public function test_모든_로케일이_비어있으면_발송하지_않는다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '01011112222']);

        $this->expectException(NotificationSendSkippedException::class);

        try {
            $this->makeDriver($this->templateRow(smsBody: ['ko' => '  ', 'en' => '']))
                ->send($member, $this->notification());
        } finally {
            Bus::assertNothingDispatched();
        }
    }

    public function test_회원_발송_시_pending_이력을_생성하고_회원id를_기록한다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678', 'name' => '김철수']);

        $this->makeDriver($this->templateRow())->send($member, $this->notification());

        $this->assertDatabaseCount('bizppurio_dispatches', 1);
        $dispatch = BizppurioDispatch::first();
        $this->assertSame('pending', $dispatch->status->value);
        $this->assertSame($member->id, $dispatch->to_user_id);
        $this->assertSame('order_confirmed', $dispatch->notification_type);
    }

    public function test_비회원_발송_이력은_회원id가_null이다(): void
    {
        Bus::fake();
        $guest = new GuestNotifiable('guest@example.com', '홍길동', 'ko');
        $data = [SmsChannelDriver::RECIPIENT_PHONE_KEY => '010-9999-0000'];

        $this->makeDriver($this->templateRow())->send($guest, $this->notification($data));

        $this->assertNull(BizppurioDispatch::first()->to_user_id);
    }

    public function test_발송하지_않으면_이력도_생성하지_않는다(): void
    {
        Bus::fake();
        $guest = new GuestNotifiable('guest@example.com', '홍길동', 'ko');

        try {
            $this->makeDriver($this->templateRow())->send($guest, $this->notification());
        } catch (NotificationSendSkippedException) {
            // 전화번호 없음 skip — 이력 미생성 검증이 목적
        }

        $this->assertDatabaseCount('bizppurio_dispatches', 0);
    }

    public function test_전화번호가_없으면_예외를_던지고_발송하지_않는다(): void
    {
        Bus::fake();
        $guest = new GuestNotifiable('guest@example.com', '홍길동', 'ko');

        $this->expectException(NotificationSendSkippedException::class);

        try {
            $this->makeDriver($this->templateRow())->send($guest, $this->notification());
        } finally {
            Bus::assertNotDispatched(SendMessageJob::class);
        }
    }

    /**
     * @effects sms_lms_split_by_body_bytes_with_definition_label_subject
     */
    public function test_짧은_본문은_sms로_긴_본문은_lms로_보낸다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '01011112222']);

        $shortBuilder = $this->spyBuilder();
        $this->makeDriver($this->templateRow(smsBody: '짧은 본문'), $shortBuilder)
            ->send($member, $this->notification());
        $this->assertSame('sms', $shortBuilder->calls[0]['type']);

        $longBuilder = $this->spyBuilder();
        $this->makeDriver($this->templateRow(smsBody: str_repeat('가', 100)), $longBuilder)
            ->send($member, $this->notification());
        $this->assertSame('lms', $longBuilder->calls[0]['type']);
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
