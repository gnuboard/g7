<?php

namespace Tests\Unit\Services;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Services\ChannelReadinessService;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * ChannelReadinessService 단위 테스트
 */
class ChannelReadinessServiceTest extends TestCase
{
    private ConfigRepositoryInterface $configRepo;

    private ChannelReadinessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configRepo = Mockery::mock(ConfigRepositoryInterface::class);
        $this->service = new ChannelReadinessService($this->configRepo);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * SMTP 설정 완료 시 mail 채널 ready
     */
    public function test_mail_smtp_ready_when_configured(): void
    {
        $this->configRepo->shouldReceive('getCategory')
            ->with('mail')
            ->andReturn([
                'mailer' => 'smtp',
                'host' => 'smtp.gmail.com',
                'port' => 587,
                'from_address' => 'test@example.com',
                'from_name' => 'Test',
            ]);

        $result = $this->service->check('mail');
        $this->assertTrue($result['ready']);
        $this->assertNull($result['reason']);
    }

    /**
     * SMTP host 미설정 시 not ready
     */
    public function test_mail_smtp_not_ready_when_host_empty(): void
    {
        $this->configRepo->shouldReceive('getCategory')
            ->with('mail')
            ->andReturn([
                'mailer' => 'smtp',
                'host' => '',
                'port' => 587,
                'from_address' => 'test@example.com',
            ]);

        $result = $this->service->check('mail');
        $this->assertFalse($result['ready']);
        $this->assertEquals('notification.readiness.mail_smtp_host_empty', $result['reason']);
    }

    /**
     * from_address 기본값(noreply@example.com) 시 not ready
     */
    public function test_mail_not_ready_when_from_address_is_default(): void
    {
        $this->configRepo->shouldReceive('getCategory')
            ->with('mail')
            ->andReturn([
                'mailer' => 'smtp',
                'host' => 'smtp.gmail.com',
                'port' => 587,
                'from_address' => 'noreply@example.com',
            ]);

        $result = $this->service->check('mail');
        $this->assertFalse($result['ready']);
        $this->assertEquals('notification.readiness.mail_from_address_not_configured', $result['reason']);
    }

    /**
     * Mailgun 설정 미완료 시 not ready
     */
    public function test_mail_mailgun_not_ready_when_domain_empty(): void
    {
        $this->configRepo->shouldReceive('getCategory')
            ->with('mail')
            ->andReturn([
                'mailer' => 'mailgun',
                'mailgun_domain' => '',
                'mailgun_secret' => 'secret',
                'from_address' => 'test@example.com',
            ]);

        $result = $this->service->check('mail');
        $this->assertFalse($result['ready']);
        $this->assertEquals('notification.readiness.mail_mailgun_domain_empty', $result['reason']);
    }

    /**
     * SES 설정 미완료 시 not ready
     */
    public function test_mail_ses_not_ready_when_key_empty(): void
    {
        $this->configRepo->shouldReceive('getCategory')
            ->with('mail')
            ->andReturn([
                'mailer' => 'ses',
                'ses_key' => '',
                'ses_secret' => 'secret',
                'from_address' => 'test@example.com',
            ]);

        $result = $this->service->check('mail');
        $this->assertFalse($result['ready']);
        $this->assertEquals('notification.readiness.mail_ses_key_empty', $result['reason']);
    }

    /**
     * log mailer는 항상 ready
     */
    public function test_mail_log_mailer_always_ready(): void
    {
        $this->configRepo->shouldReceive('getCategory')
            ->with('mail')
            ->andReturn([
                'mailer' => 'log',
                'from_address' => 'test@example.com',
            ]);

        $result = $this->service->check('mail');
        $this->assertTrue($result['ready']);
    }

    /**
     * database 채널 — notifications 테이블 존재 시 ready
     */
    public function test_database_ready_when_table_exists(): void
    {
        Schema::shouldReceive('hasTable')
            ->with('notifications')
            ->andReturn(true);

        $result = $this->service->check('database');
        $this->assertTrue($result['ready']);
    }

    /**
     * 알 수 없는 채널은 기본 ready
     */
    public function test_unknown_channel_defaults_to_ready(): void
    {
        $result = $this->service->check('fcm');
        $this->assertTrue($result['ready']);
    }

    /**
     * isReady() 편의 메서드
     */
    public function test_is_ready_returns_boolean(): void
    {
        $this->configRepo->shouldReceive('getCategory')
            ->with('mail')
            ->andReturn([
                'mailer' => 'smtp',
                'host' => '',
                'port' => 587,
                'from_address' => 'test@example.com',
            ]);

        $this->assertFalse($this->service->isReady('mail'));
    }

    /**
     * checkAll() 일괄 체크
     */
    public function test_check_all_returns_all_channels(): void
    {
        $this->configRepo->shouldReceive('getCategory')
            ->with('mail')
            ->andReturn([
                'mailer' => 'smtp',
                'host' => 'smtp.gmail.com',
                'port' => 587,
                'from_address' => 'test@example.com',
            ]);

        Schema::shouldReceive('hasTable')
            ->with('notifications')
            ->andReturn(true);

        $results = $this->service->checkAll(['mail', 'database']);
        $this->assertArrayHasKey('mail', $results);
        $this->assertArrayHasKey('database', $results);
        $this->assertTrue($results['mail']['ready']);
        $this->assertTrue($results['database']['ready']);
    }

    /**
     * 프로퍼티 캐시 — 동일 채널 반복 호출 시 1회만 체크
     */
    public function test_result_is_cached_within_instance(): void
    {
        $this->configRepo->shouldReceive('getCategory')
            ->with('mail')
            ->once()  // 1회만 호출되어야 함
            ->andReturn([
                'mailer' => 'smtp',
                'host' => 'smtp.gmail.com',
                'port' => 587,
                'from_address' => 'test@example.com',
            ]);

        $result1 = $this->service->check('mail');
        $result2 = $this->service->check('mail');
        $result3 = $this->service->isReady('mail');

        $this->assertTrue($result1['ready']);
        $this->assertSame($result1, $result2);
        $this->assertTrue($result3);
    }
}
