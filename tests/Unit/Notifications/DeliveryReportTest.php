<?php

namespace Tests\Unit\Notifications;

use App\Contracts\Notifications\ChannelReadinessCheckerInterface;
use App\Models\NotificationDefinition;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\DeliveryReport;
use App\Notifications\GenericNotification;
use App\Notifications\GuestNotifiable;
use App\Services\NotificationChannelService;
use App\Services\NotificationDefinitionService;
use App\Services\NotificationTemplateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * 알림 채널별 발송 결과 보고서(DeliveryReport) 테스트 (#699)
 *
 * 본인인증 메일은 "보내지 못했으면 실패로 알려야" 한다. 알림 파이프라인은 채널을 조용히 빼거나
 * 예외를 삼키므로, 보고서가 각 지점(via 제외·템플릿 비활성·발송 성공·발송 예외)의 결과를
 * 빠짐없이 기록하는지, 그리고 보고서를 붙이지 않으면 동작이 그대로인지 잠근다.
 */
class DeliveryReportTest extends TestCase
{
    use DatabaseTransactions;

    private const TYPE = 'test_delivery_report';

    private NotificationDefinition $definition;

    /**
     * 테스트용 알림 정의(mail 채널)를 만들고 채널·readiness 를 기본 통과로 격리합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->definition = NotificationDefinition::create([
            'type' => self::TYPE,
            'hook_prefix' => 'core.test',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '보고서 테스트', 'en' => 'Report test'],
            'variables' => [],
            'channels' => ['mail'],
            'hooks' => [],
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->createMailTemplate(true);
        $this->mockChannels([]);
        $this->mockReadiness(true);
    }

    /**
     * 캐시를 비워 다음 테스트에 남지 않게 합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        app(NotificationTemplateService::class)->invalidateCache(self::TYPE, 'mail');
        app(NotificationDefinitionService::class)->invalidateAllCache();
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 순수 값 의미 — 건너뜀·실패가 먼저 기록된 채널은 뒤이은 성공이 덮지 않는다.
     *
     * @scenario surface=delivery_report
     *
     * @effects report_records_sent
     *
     * @return void 반환값 없음
     */
    public function test_value_semantics(): void
    {
        $report = new DeliveryReport;

        $this->assertNull($report->statusFor('mail'));
        $this->assertFalse($report->wasSent('mail'));

        $report->markSkipped('mail', '꺼짐');
        $report->markSent('mail');
        $this->assertSame(DeliveryReport::SKIPPED, $report->statusFor('mail'));
        $this->assertSame('꺼짐', $report->reasonFor('mail'));

        $report->markFailed('database', 'boom');
        $report->markSkipped('database', 'late');
        $report->markSent('database');
        $this->assertSame(DeliveryReport::FAILED, $report->statusFor('database'));
        $this->assertSame('boom', $report->reasonFor('database'));

        $report->markSent('sms');
        $this->assertTrue($report->wasSent('sms'));
        $this->assertNull($report->reasonFor('sms'));
        $this->assertCount(3, $report->all());
    }

    /**
     * 성공 — 실제 메일이 나가고 sent 로 기록된다.
     *
     * @scenario surface=delivery_report
     *
     * @effects report_records_sent
     *
     * @return void 반환값 없음
     */
    public function test_sent_is_recorded(): void
    {
        Mail::fake();
        $report = $this->sendToUser();

        $this->assertTrue($report->wasSent('mail'));
        $this->assertSame(DeliveryReport::SENT, $report->statusFor('mail'));
    }

    /**
     * 채널 꺼짐(확장 토글) — skipped + 사유, 발송 0.
     *
     * @scenario surface=delivery_report
     *
     * @effects report_records_channel_disabled
     *
     * @return void 반환값 없음
     */
    public function test_channel_disabled_is_skipped_with_reason(): void
    {
        Mail::fake();
        $this->mockChannels(['mail' => false]);

        $report = $this->sendToUser();

        $this->assertSame(DeliveryReport::SKIPPED, $report->statusFor('mail'));
        $this->assertSame(__('notification.channel_disabled_by_extension'), $report->reasonFor('mail'));
        Mail::assertNothingOutgoing();
    }

    /**
     * 채널 미준비(readiness 실패) — skipped + readiness 사유.
     *
     * @scenario surface=delivery_report
     *
     * @effects report_records_not_ready
     *
     * @return void 반환값 없음
     */
    public function test_channel_not_ready_is_skipped_with_reason(): void
    {
        Mail::fake();
        $this->mockReadiness(false);

        $report = $this->sendToUser();

        $this->assertSame(DeliveryReport::SKIPPED, $report->statusFor('mail'));
        $this->assertSame(__('notification.readiness.mail_from_address_not_configured'), $report->reasonFor('mail'));
    }

    /**
     * 게스트 차단 — 비회원 발송을 허용하지 않는 채널이면 skipped.
     *
     * @scenario surface=delivery_report
     *
     * @effects report_records_guest_blocked
     *
     * @return void 반환값 없음
     */
    public function test_guest_blocked_is_skipped(): void
    {
        Mail::fake();
        $this->mockChannels([], ['mail'], guestAllowed: false);

        $report = new DeliveryReport;
        (new GuestNotifiable('guest-report@example.test'))->notifyNow($this->notification()->withDeliveryReport($report));

        $this->assertSame(DeliveryReport::SKIPPED, $report->statusFor('mail'));
        $this->assertSame(__('notification.channel_guest_not_allowed'), $report->reasonFor('mail'));
    }

    /**
     * 채널 미가용(제공 확장 비활성) — 로그 없이 빠지던 경로도 보고서에는 skipped 로 남는다.
     *
     * @scenario surface=delivery_report
     *
     * @effects report_records_unavailable_channel
     *
     * @return void 반환값 없음
     */
    public function test_unavailable_channel_is_skipped_without_log(): void
    {
        Mail::fake();
        $this->mockChannels([], ['database']);
        $before = NotificationLog::where('notification_type', self::TYPE)->count();

        $report = $this->sendToUser();

        $this->assertSame(DeliveryReport::SKIPPED, $report->statusFor('mail'));
        $this->assertSame(__('notification.channel_unavailable'), $report->reasonFor('mail'));
        $this->assertSame($before, NotificationLog::where('notification_type', self::TYPE)->count());
    }

    /**
     * 템플릿 비활성 — toMail 의 skipped 분기가 기록되고, 뒤이은 성공 훅이 덮지 않는다.
     *
     * @scenario surface=delivery_report
     *
     * @effects report_records_inactive_template
     *
     * @return void 반환값 없음
     */
    public function test_inactive_template_is_skipped(): void
    {
        Mail::fake();
        $this->createMailTemplate(false);

        $report = $this->sendToUser();

        $this->assertSame(DeliveryReport::SKIPPED, $report->statusFor('mail'));
        $this->assertSame(__('notification.template_inactive'), $report->reasonFor('mail'));
        $this->assertFalse($report->wasSent('mail'));
    }

    /**
     * 발송 예외 — NotificationDispatcher 가 삼키는 예외도 failed + 메시지로 남는다.
     *
     * @scenario surface=delivery_report
     *
     * @effects report_records_transport_exception
     *
     * @return void 반환값 없음
     */
    public function test_exception_is_failed_with_message(): void
    {
        // 닫힌 포트로 SMTP 연결 — 실제 전송 계층 예외를 NotificationDispatcher 가 삼키는 경로
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 2,
        ]);

        $report = $this->sendToUser();

        $this->assertSame(DeliveryReport::FAILED, $report->statusFor('mail'));
        $this->assertNotEmpty($report->reasonFor('mail'));
    }

    /**
     * 보고서를 붙이지 않으면 동작이 바뀌지 않는다 — 발송·예외 없음.
     *
     * @scenario surface=delivery_report
     *
     * @effects report_absent_behaviour_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_without_report_behaviour_is_unchanged(): void
    {
        Mail::fake();
        $notification = $this->notification();
        $before = NotificationLog::where('notification_type', self::TYPE)->where('status', 'sent')->count();

        User::factory()->create()->notifyNow($notification);

        $this->assertNull($notification->getDeliveryReport());
        // 보고서가 없어도 발송 성공 훅(→ 발송 로그)은 종전대로 1회 지난다
        $this->assertSame(
            $before + 1,
            NotificationLog::where('notification_type', self::TYPE)->where('status', 'sent')->count()
        );
    }

    /**
     * 회원에게 보고서를 붙여 즉시 발송하고 보고서를 반환합니다.
     *
     * @return DeliveryReport 기록된 보고서
     */
    private function sendToUser(): DeliveryReport
    {
        $report = new DeliveryReport;
        User::factory()->create()->notifyNow($this->notification()->withDeliveryReport($report));

        return $report;
    }

    /**
     * mail 채널 지정 테스트 알림을 만듭니다.
     *
     * @return GenericNotification 알림
     */
    private function notification(): GenericNotification
    {
        return new GenericNotification(
            type: self::TYPE,
            hookPrefix: 'core.test',
            data: [],
            extensionType: 'core',
            extensionIdentifier: 'core',
            channel: 'mail',
        );
    }

    /**
     * 테스트 정의의 mail 템플릿을 만들거나 활성 상태를 바꿉니다.
     *
     * @param  bool  $active  활성 여부
     * @return void 반환값 없음
     */
    private function createMailTemplate(bool $active): void
    {
        NotificationTemplate::updateOrCreate(
            ['definition_id' => $this->definition->id, 'channel' => 'mail'],
            [
                'subject' => ['ko' => '제목', 'en' => 'Subject'],
                'body' => ['ko' => '본문', 'en' => 'Body'],
                'is_active' => $active,
                'is_default' => false,
            ]
        );
        app(NotificationTemplateService::class)->invalidateCache(self::TYPE, 'mail');
    }

    /**
     * 채널 서비스를 격리합니다.
     *
     * @param  array<string, bool>  $enabledMap  채널별 확장 토글 (미지정 true)
     * @param  array<int, string>  $available  사용 가능한 채널 목록
     * @param  bool  $guestAllowed  게스트 발송 허용 여부
     * @return void 반환값 없음
     */
    private function mockChannels(array $enabledMap, array $available = ['mail', 'database'], bool $guestAllowed = true): void
    {
        $mock = Mockery::mock(NotificationChannelService::class);
        $mock->shouldReceive('isChannelEnabledForExtension')
            ->andReturnUsing(fn ($type, $identifier, $channel) => $enabledMap[$channel] ?? true);
        $mock->shouldReceive('isChannelAvailable')
            ->andReturnUsing(fn (string $channel) => in_array($channel, $available, true));
        $mock->shouldReceive('isChannelGuestAllowed')->andReturn($guestAllowed);
        $mock->shouldReceive('getAvailableChannels')
            ->andReturn(array_map(static fn (string $id) => ['id' => $id], $available));
        $this->app->instance(NotificationChannelService::class, $mock);
    }

    /**
     * 채널 readiness 를 격리합니다.
     *
     * @param  bool  $ready  준비 여부
     * @return void 반환값 없음
     */
    private function mockReadiness(bool $ready): void
    {
        $mock = Mockery::mock(ChannelReadinessCheckerInterface::class);
        $mock->shouldReceive('isReady')->andReturn($ready);
        $mock->shouldReceive('check')->andReturn([
            'ready' => $ready,
            'reason' => $ready ? null : 'notification.readiness.mail_from_address_not_configured',
        ]);
        $this->app->instance(ChannelReadinessCheckerInterface::class, $mock);
    }
}
