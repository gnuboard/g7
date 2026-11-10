<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Unit\Identity;

use Carbon\Carbon;
use Plugins\Sirsoft\VerificationNhnkcp\Identity\KcpIdentityProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;

/**
 * 만 19세 성인 판정 경계 검증.
 *
 * 생일 당일은 성인으로 인정한다(만 나이 규칙). 형식이 어긋나거나 비어 있으면 성인으로 보지 않는다.
 * 만 나이는 한국 법상 나이라 한국 시간(Asia/Seoul)의 날짜로 센다 — 기준 날짜도 한국 시간으로 만든다.
 *
 * @since 1.0.0
 */
class KcpAdultAgeBoundaryTest extends PluginTestCase
{
    /**
     * 고정 시각을 정리한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * 오늘(한국 시간)이 만 19세 생일이면 성인이다.
     *
     * @scenario age=exactly_19_today
     *
     * @effects nineteenth_birthday_today_counts_as_adult
     *
     * @return void 반환값 없음
     */
    public function test_nineteenth_birthday_today_is_adult(): void
    {
        $birthday = now('Asia/Seoul')->subYears(19)->format('Ymd');

        $this->assertTrue($this->provider()->isAdult($birthday));
    }

    /**
     * 만 19세 생일 하루 전(한국 시간)이면 미성년자다.
     *
     * @scenario age=under_19
     *
     * @effects minor_blocked_with_not_adult_and_no_token
     *
     * @return void 반환값 없음
     */
    public function test_one_day_before_nineteenth_birthday_is_minor(): void
    {
        $birthday = now('Asia/Seoul')->subYears(19)->addDay()->format('Ymd');

        $this->assertFalse($this->provider()->isAdult($birthday));
    }

    /**
     * 생일이 한참 지난 성인은 성인이다.
     *
     * @scenario age=over_19
     *
     * @effects adult_passes_with_token
     *
     * @return void 반환값 없음
     */
    public function test_clearly_adult_birthday_is_adult(): void
    {
        $this->assertTrue($this->provider()->isAdult('19900101'));
    }

    /**
     * 형식이 어긋나거나 비어 있는 생년월일은 성인이 아니다.
     *
     * @scenario age=birthday_missing
     *
     * @effects missing_birthday_blocked_by_incomplete_identity
     *
     * @return void 반환값 없음
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
     * UTC 로는 아직 생일 전날이어도 한국 시간으로 생일이면 성인이다.
     *
     * UTC 11-03 16:00 = 서울 11-04 01:00 — 생일 2007-11-04 는 서울 기준 오늘 만 19세.
     *
     * @scenario age=exactly_19_today
     *
     * @effects adult_age_counts_in_korea_time
     *
     * @return void 반환값 없음
     */
    public function test_adult_age_counts_in_korea_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-03 16:00:00', 'UTC'));

        $this->assertTrue($this->provider()->isAdult('20071104'));
        // 하루 모자라면 서울 기준으로도 아직 만 18세다.
        $this->assertFalse($this->provider()->isAdult('20071105'));
    }

    /**
     * 설정이 주입된 provider 인스턴스.
     *
     * @return KcpIdentityProvider 본인확인 provider
     */
    private function provider(): KcpIdentityProvider
    {
        return app()->makeWith(KcpIdentityProvider::class, ['config' => []]);
    }
}
