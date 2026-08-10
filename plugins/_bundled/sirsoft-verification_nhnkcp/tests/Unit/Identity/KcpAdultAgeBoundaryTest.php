<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Unit\Identity;

use Plugins\Sirsoft\VerificationNhnkcp\Identity\KcpIdentityProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;

/**
 * 만 19세 성인 판정 경계 검증.
 *
 * 생일 당일은 성인으로 인정한다(만 나이 규칙). 형식이 어긋나거나 비어 있으면 성인으로 보지 않는다.
 *
 * @since 1.0.0
 */
class KcpAdultAgeBoundaryTest extends PluginTestCase
{
    /**
     * @scenario age=exactly_19_today
     *
     * @effects nineteenth_birthday_today_counts_as_adult
     */
    public function test_nineteenth_birthday_today_is_adult(): void
    {
        $birthday = now()->subYears(19)->format('Ymd');

        $this->assertTrue($this->provider()->isAdult($birthday));
    }

    /**
     * @scenario age=under_19
     *
     * @effects minor_blocked_with_not_adult_and_no_token
     */
    public function test_one_day_before_nineteenth_birthday_is_minor(): void
    {
        $birthday = now()->subYears(19)->addDay()->format('Ymd');

        $this->assertFalse($this->provider()->isAdult($birthday));
    }

    /**
     * @scenario age=over_19
     *
     * @effects adult_passes_with_token
     */
    public function test_clearly_adult_birthday_is_adult(): void
    {
        $this->assertTrue($this->provider()->isAdult('19900101'));
    }

    /**
     * @scenario age=birthday_missing
     *
     * @effects missing_birthday_blocked_by_incomplete_identity
     */
    public function test_invalid_or_missing_birthday_is_not_adult(): void
    {
        $provider = $this->provider();

        $this->assertFalse($provider->isAdult(''));
        $this->assertFalse($provider->isAdult('1990-01-01'));
        $this->assertFalse($provider->isAdult('19901'));
        $this->assertFalse($provider->isAdult('99999999'));
    }

    /**
     * 설정이 주입된 provider 인스턴스.
     */
    private function provider(): KcpIdentityProvider
    {
        return app()->makeWith(KcpIdentityProvider::class, ['config' => []]);
    }
}
