<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Unit\Identity;

use Carbon\Carbon;
use Plugins\Sirsoft\VerificationKginicis\Identity\InicisIdentityProvider;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;
use ReflectionClass;

/**
 * KG이니시스 성인 판정의 기준 날짜 테스트 (#160 D10)
 *
 * 만 나이는 한국 법상 나이라 한국 시간(Asia/Seoul)의 날짜로 센다 — 서버 시간대(UTC)의 날짜로 세면
 * 한국 시간 0~9시에 생일을 맞은 사람이 하루 늦게 성인이 된다.
 */
class InicisAdultAgeKoreaTimezoneTest extends PluginTestCase
{
    /**
     * 고정 시각을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * UTC 11-03 16:00(서울 11-04 01:00)에 생일 2007-11-04 → 한국 시간으로 오늘 만 19세라 성인.
     *
     * @scenario surface=adult_age_korea_time
     *
     * @effects adult_age_counts_in_korea_time
     *
     * @return void 반환값 없음
     */
    public function test_nineteenth_birthday_in_korea_time_is_adult(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-03 16:00:00', 'UTC'));

        $this->assertTrue($this->isAdult('20071104'));
        // 하루 뒤 생일은 아직 미성년
        $this->assertFalse($this->isAdult('20071105'));
    }

    /**
     * 보호 메서드 isAdult() 를 의존성 없이 호출합니다. (판정은 생년월일과 현재 시각에만 의존한다)
     *
     * @param  string  $birthday  생년월일 (YYYYMMDD)
     * @return bool 만 19세 이상이면 true
     */
    private function isAdult(string $birthday): bool
    {
        $reflection = new ReflectionClass(InicisIdentityProvider::class);
        $provider = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('isAdult');
        $method->setAccessible(true);

        return (bool) $method->invoke($provider, $birthday);
    }
}
