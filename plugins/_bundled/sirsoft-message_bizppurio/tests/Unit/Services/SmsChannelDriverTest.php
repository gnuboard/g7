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
     * @param  bool  $isActive  행 활성 여부
     * @param  bool  $smsOnly  SMS 단독 발송(알림톡 끔) 여부
     * @return BizppurioTemplate 조립한 템플릿 행
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
     *
     * @param  BizppurioTemplate|null  $row  findByType 이 돌려줄 템플릿 행
     * @param  MessagePayloadBuilder|null  $builder  payload 빌더, null 이면 스파이 빌더
     * @param  bool  $isTestMode  검수 모드 설정값
     * @param  NotificationDefinition|null  $definition  알림 유형 라벨 조회에 쓸 정의, null 이면 코드값 폴백
     * @return SmsChannelDriver 조립한 드라이버
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
            $pluginSettings,
        );
    }

    /**
     * build* 호출 인자를 기록하는 payload 빌더 스파이를 만듭니다.
     *
     * @return MessagePayloadBuilder 반환값
     */
    private function spyBuilder(): MessagePayloadBuilder
    {
        return new class extends MessagePayloadBuilder
        {
            public array $calls = [];

            /**
             * 부모 생성자 의존성 없이 스파이를 만듭니다.
             */
            public function __construct() {}

            /**
             * buildSms 을(를) 검증합니다.
             *
             * @param  string  $to  수신 번호
             * @param  string  $message  치환된 본문
             * @param  string  $refkey  발송 참조 키
             * @return array 반환값
             */
            public function buildSms(string $to, string $message, string $refkey): array
            {
                $this->calls[] = ['type' => 'sms', 'to' => $to, 'message' => $message, 'refkey' => $refkey];

                return ['type' => 'sms', 'to' => $to, 'refkey' => $refkey];
            }

            /**
             * buildLms 을(를) 검증합니다.
             *
             * @param  string  $to  수신 번호
             * @param  string  $message  치환된 본문
             * @param  string  $refkey  발송 참조 키
             * @param  string|null  $subject  LMS 제목
             * @return array 반환값
             */
            public function buildLms(string $to, string $message, string $refkey, ?string $subject = null): array
            {
                $this->calls[] = ['type' => 'lms', 'to' => $to, 'message' => $message, 'refkey' => $refkey, 'subject' => $subject];

                return ['type' => 'lms', 'to' => $to, 'refkey' => $refkey];
            }
        };
    }

    /**
     * notification 을(를) 검증합니다.
     *
     * @param  array<string, mixed>  $data  알림 data
     * @return GenericNotification 반환값
     */
    private function notification(array $data = []): GenericNotification
    {
        return new GenericNotification('order_confirmed', 'sirsoft-ecommerce', $data, 'module', 'sirsoft-ecommerce');
    }

    /**
     * 템플릿 행이 없으면 예외를 던지고 발송하지 않는다.
     *
     * @effects sms_skipped_when_body_empty_or_row_missing
     *
     * @return void 반환값 없음
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
     * sms body가 비어있으면 예외를 던지고 발송하지 않는다.
     *
     * @effects sms_skipped_when_body_empty_or_row_missing
     *
     * @return void 반환값 없음
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

    /**
     * 비활성 행이면 발송하지 않는다.
     *
     * @effects sms_skipped_when_template_row_inactive
     *
     * @return void 반환값 없음
     */
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
     *
     * @param  bool  $smsOnly  SMS 단독 발송 플래그 값
     * @return void 반환값 없음
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
     * sms_only 플래그의 두 값을 공급합니다.
     *
     * @return array<string, array{bool}> 케이스명별 sms_only 값
     */
    public static function smsOnlyProvider(): array
    {
        return [
            'sms_only 꺼짐 (알림톡 병행)' => [false],
            'sms_only 켜짐 (문자 단독)' => [true],
        ];
    }

    /**
     * 회원은 mobile로 발송한다.
     *
     * @effects member_sms_uses_the_member_mobile
     *
     * @return void 반환값 없음
     */
    public function test_회원은_mobile로_발송한다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);
        $builder = $this->spyBuilder();

        $this->makeDriver($this->templateRow(), $builder)->send($member, $this->notification());

        Bus::assertDispatched(SendMessageJob::class);
        $this->assertSame('01012345678', $builder->calls[0]['to']);
    }

    /**
     * 비회원은 data의 전화번호로 발송한다.
     *
     * @effects guest_sms_uses_the_phone_carried_in_the_payload
     *
     * @return void 반환값 없음
     */
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
     * 휴대폰을 등록하지 않은 회원이 수신자면 **발송하지 않는다.**
     *
     * 종전에는 `$notifiable->mobile ?? $data[번호]` 라, 그 알림이 나르는 **고객 번호로**
     * 문자가 나갈 수 있었다. 지금은 주문 6종 화이트리스트가 우연히 막고 있을 뿐이고,
     * 관리자 수신 알림이 늘면 그 우연이 깨진다.
     *
     * @effects member_without_mobile_never_borrows_the_customer_number
     *
     * @return void 반환값 없음
     */
    public function test_휴대폰_미등록_회원에게는_고객_번호를_빌려_쓰지_않는다(): void
    {
        Bus::fake();
        $staff = User::factory()->create(['mobile' => null]);
        $data = [SmsChannelDriver::RECIPIENT_PHONE_KEY => '010-9999-0000'];
        $builder = $this->spyBuilder();

        // 번호를 못 찾은 것으로 취급되어 기존 계약(건너뜀 예외)을 그대로 탄다.
        $this->expectException(NotificationSendSkippedException::class);

        try {
            $this->makeDriver($this->templateRow(), $builder)->send($staff, $this->notification($data));
        } finally {
            Bus::assertNotDispatched(SendMessageJob::class);
            $this->assertSame([], $builder->calls, '고객 번호가 발송 대상으로 넘어가면 안 됩니다.');
        }
    }

    /**
     * sms body의 변수를 알림 data로 치환해 발송한다.
     *
     * @effects sms_body_is_dispatch_source_not_core_template
     *
     * @return void 반환값 없음
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
     *
     * @param  string  $locale  수신자 로케일
     * @param  string  $expected  기대하는 발송 본문
     * @return void 반환값 없음
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
     * 수신자 로케일과 기대 본문 쌍을 공급합니다.
     *
     * @return array<string, array{0: string, 1: string}> 케이스명별 [로케일, 기대 본문]
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
     *
     * @return void 반환값 없음
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
     *
     * @effects sms_skipped_when_fallback_locale_body_missing
     *
     * @return void 반환값 없음
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
     *
     * @effects sms_skipped_when_every_locale_body_is_empty
     *
     * @return void 반환값 없음
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

    /**
     * 회원 발송 시 pending 이력을 생성하고 회원id를 기록한다.
     *
     * @effects member_dispatch_row_records_the_member_id
     *
     * @return void 반환값 없음
     */
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

    /**
     * 비회원 발송 이력은 회원id가 null이다.
     *
     * @effects guest_dispatch_row_has_no_member_id
     *
     * @return void 반환값 없음
     */
    public function test_비회원_발송_이력은_회원id가_null이다(): void
    {
        Bus::fake();
        $guest = new GuestNotifiable('guest@example.com', '홍길동', 'ko');
        $data = [SmsChannelDriver::RECIPIENT_PHONE_KEY => '010-9999-0000'];

        $this->makeDriver($this->templateRow())->send($guest, $this->notification($data));

        $this->assertNull(BizppurioDispatch::first()->to_user_id);
    }

    /**
     * 발송하지 않으면 이력도 생성하지 않는다.
     *
     * @effects no_dispatch_row_when_nothing_is_sent
     *
     * @return void 반환값 없음
     */
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

    /**
     * 전화번호가 없으면 예외를 던지고 발송하지 않는다.
     *
     * @effects sms_skipped_when_no_phone_number_resolves
     *
     * @return void 반환값 없음
     */
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
     * 짧은 본문은 sms로 긴 본문은 lms로 보낸다.
     *
     * @effects sms_lms_split_by_body_bytes_with_definition_label_subject
     *
     * @return void 반환값 없음
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

    /**
     * generic notification이 아니면 무시한다.
     *
     * @effects non_generic_notifications_are_ignored
     *
     * @return void 반환값 없음
     */
    public function test_generic_notification이_아니면_무시한다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '01011112222']);

        $this->makeDriver($this->templateRow())->send($member, new Notification);

        Bus::assertNotDispatched(SendMessageJob::class);
    }

    /**
     * 검수 모드 설정값이 이력에 스냅샷으로 기록된다.
     *
     * @effects test_mode_flag_is_snapshotted_on_the_dispatch_row
     *
     * @return void 반환값 없음
     */
    public function test_검수_모드_설정값이_이력에_스냅샷으로_기록된다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        $this->makeDriver($this->templateRow(), isTestMode: true)->send($member, $this->notification());

        $this->assertTrue(BizppurioDispatch::first()->is_test_mode);
    }

    /**
     * 운영 모드 설정값도 이력에 스냅샷으로 기록된다.
     *
     * @effects live_mode_flag_is_snapshotted_on_the_dispatch_row
     *
     * @return void 반환값 없음
     */
    public function test_운영_모드_설정값도_이력에_스냅샷으로_기록된다(): void
    {
        Bus::fake();
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        $this->makeDriver($this->templateRow(), isTestMode: false)->send($member, $this->notification());

        $this->assertFalse(BizppurioDispatch::first()->is_test_mode);
    }
}
