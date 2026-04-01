<?php

namespace Tests\Unit\Providers;

use App\Providers\SettingsServiceProvider;
use App\Repositories\JsonConfigRepository;
use Illuminate\Support\Facades\Config;
use ReflectionMethod;
use Tests\TestCase;

/**
 * SettingsServiceProvider 메일 설정 테스트
 *
 * applyMailConfig()가 Mailgun/SES 드라이버 설정을
 * Laravel config에 올바르게 적용하는지 검증합니다.
 */
class SettingsServiceProviderMailConfigTest extends TestCase
{
    /**
     * applyMailConfig를 리플렉션으로 호출합니다.
     *
     * @param  array  $mailSettings  mail 카테고리 설정 데이터
     */
    private function callApplyMailConfig(array $mailSettings): void
    {
        $configRepository = $this->createMock(JsonConfigRepository::class);
        $configRepository->method('getCategory')
            ->with('mail')
            ->willReturn($mailSettings);

        $provider = new SettingsServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'applyMailConfig');
        $method->invoke($provider, $configRepository);
    }

    /**
     * SMTP 설정이 정상 적용되는지 테스트합니다.
     */
    public function test_smtp_config_applied(): void
    {
        $this->callApplyMailConfig([
            'mailer' => 'smtp',
            'host' => 'smtp.example.com',
            'port' => '587',
            'username' => 'user@example.com',
            'password' => 'secret',
            'encryption' => 'tls',
            'from_address' => 'noreply@example.com',
            'from_name' => 'Test App',
        ]);

        $this->assertEquals('smtp', config('mail.default'));
        $this->assertEquals('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertEquals(587, config('mail.mailers.smtp.port'));
        $this->assertEquals('user@example.com', config('mail.mailers.smtp.username'));
        $this->assertEquals('secret', config('mail.mailers.smtp.password'));
        $this->assertEquals('tls', config('mail.mailers.smtp.encryption'));
        $this->assertEquals('noreply@example.com', config('mail.from.address'));
        $this->assertEquals('Test App', config('mail.from.name'));
    }

    /**
     * Mailgun 설정이 services.mailgun에 적용되는지 테스트합니다.
     */
    public function test_mailgun_config_applied(): void
    {
        $this->callApplyMailConfig([
            'mailer' => 'mailgun',
            'mailgun_domain' => 'sandbox123.mailgun.org',
            'mailgun_secret' => 'key-abc123',
            'mailgun_endpoint' => 'api.eu.mailgun.net',
            'from_address' => 'noreply@example.com',
            'from_name' => 'Test App',
        ]);

        $this->assertEquals('mailgun', config('mail.default'));
        $this->assertEquals('sandbox123.mailgun.org', config('services.mailgun.domain'));
        $this->assertEquals('key-abc123', config('services.mailgun.secret'));
        $this->assertEquals('api.eu.mailgun.net', config('services.mailgun.endpoint'));
        $this->assertEquals('noreply@example.com', config('mail.from.address'));
    }

    /**
     * Mailgun endpoint가 비어있으면 기본값이 적용되는지 테스트합니다.
     */
    public function test_mailgun_default_endpoint(): void
    {
        $this->callApplyMailConfig([
            'mailer' => 'mailgun',
            'mailgun_domain' => 'sandbox123.mailgun.org',
            'mailgun_secret' => 'key-abc123',
        ]);

        $this->assertEquals('api.mailgun.net', config('services.mailgun.endpoint'));
    }

    /**
     * SES 설정이 services.ses에 적용되는지 테스트합니다.
     */
    public function test_ses_config_applied(): void
    {
        $this->callApplyMailConfig([
            'mailer' => 'ses',
            'ses_key' => 'AKIAIOSFODNN7EXAMPLE',
            'ses_secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'ses_region' => 'us-east-1',
            'from_address' => 'noreply@example.com',
            'from_name' => 'Test App',
        ]);

        $this->assertEquals('ses', config('mail.default'));
        $this->assertEquals('AKIAIOSFODNN7EXAMPLE', config('services.ses.key'));
        $this->assertEquals('wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', config('services.ses.secret'));
        $this->assertEquals('us-east-1', config('services.ses.region'));
        $this->assertEquals('noreply@example.com', config('mail.from.address'));
    }

    /**
     * SES region이 비어있으면 기본값이 적용되는지 테스트합니다.
     */
    public function test_ses_default_region(): void
    {
        $this->callApplyMailConfig([
            'mailer' => 'ses',
            'ses_key' => 'AKIAIOSFODNN7EXAMPLE',
            'ses_secret' => 'secret',
        ]);

        $this->assertEquals('ap-northeast-2', config('services.ses.region'));
    }

    /**
     * 빈 메일 설정일 때 config가 변경되지 않는지 테스트합니다.
     */
    public function test_empty_settings_does_not_change_config(): void
    {
        $originalDefault = config('mail.default');

        $this->callApplyMailConfig([]);

        $this->assertEquals($originalDefault, config('mail.default'));
    }

    /**
     * SMTP 선택 시 Mailgun/SES config가 적용되지 않는지 테스트합니다.
     */
    public function test_smtp_does_not_set_mailgun_or_ses_config(): void
    {
        Config::set('services.mailgun.domain', null);
        Config::set('services.ses.key', null);

        $this->callApplyMailConfig([
            'mailer' => 'smtp',
            'host' => 'smtp.example.com',
        ]);

        $this->assertNull(config('services.mailgun.domain'));
        $this->assertNull(config('services.ses.key'));
    }
}
