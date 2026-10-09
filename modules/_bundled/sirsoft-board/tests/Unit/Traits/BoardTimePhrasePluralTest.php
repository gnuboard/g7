<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Traits;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';
// 활성 디렉토리 사본보다 _bundled 원본을 먼저 선언해 원본을 시험한다 (CooldownRuleTest 와 같은 방식)
require_once __DIR__.'/../../../src/Traits/FormatsBoardDate.php';
require_once __DIR__.'/../../../src/Rules/CooldownRule.php';

use Carbon\Carbon;
use Illuminate\Support\Facades\App;
use Modules\Sirsoft\Board\Rules\CooldownRule;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use Modules\Sirsoft\Board\Traits\FormatsBoardDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * 게시판 시간 표기의 단수·복수 테스트
 *
 * en 상대 시각과 쿨다운 기간 문구가 수와 상관없이 복수형으로 고정돼 「1 hours ago」,
 * 「1 minutes intervals」처럼 나왔다. 단위가 하나일 때는 단수, 둘 이상이면 복수여야 하고,
 * 단·복수 구분이 없는 ko 표기는 그대로여야 한다.
 */
class BoardTimePhrasePluralTest extends ModuleTestCase
{
    /**
     * 트레이트를 노출하는 시험용 객체
     */
    private object $subject;

    /**
     * 각 테스트 전에 시각을 고정하고 트레이트 노출 객체를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 10, 6, 12, 0, 0, 'UTC'));

        // 번역 이름공간은 부팅 때 활성 디렉토리 사본을 가리킨다 — 이 시험은 _bundled 원본 문구를
        // 재야 하므로 원본 경로로 다시 걸고, 이미 읽힌 묶음을 비운다.
        app('translator')->addNamespace('sirsoft-board', dirname(__DIR__, 3).'/src/lang');
        app('translator')->setLoaded([]);

        $this->subject = new class
        {
            use FormatsBoardDate;

            /**
             * 상대 시각 포맷을 호출합니다.
             *
             * @param  mixed  $dateTime  기준 시각
             * @param  string  $format  표시 형식(표준형 또는 유동형)
             * @return string 포맷한 문구
             */
            public function call(mixed $dateTime, string $format): string
            {
                return $this->formatCreatedAtFormat($dateTime, $format);
            }
        };
    }

    /**
     * 각 테스트 뒤 고정 시각과 로케일을 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        App::setLocale('ko');

        parent::tearDown();
    }

    /**
     * 상대 시각 단수 변종(경과 초, 포맷, 기대 en, 기대 ko)을 돌려줍니다.
     *
     * @return array<string, array{0: int, 1: string, 2: string, 3: string}> 변종 목록
     */
    public static function relativeTimeOneCases(): array
    {
        return [
            '1분' => [60, 'standard', '1 minute ago', '1분 전'],
            '1시간' => [3600, 'standard', '1 hour ago', '1시간 전'],
            '1일' => [86400 + 3600, 'relative', '1 day ago', '1일 전'],
            '1개월' => [86400 * 32, 'relative', '1 month ago', '1개월 전'],
            '1년' => [86400 * 370, 'relative', '1 year ago', '1년 전'],
        ];
    }

    /**
     * 상대 시각 복수 변종(경과 초, 포맷, 기대 en, 기대 ko)을 돌려줍니다.
     *
     * @return array<string, array{0: int, 1: string, 2: string, 3: string}> 변종 목록
     */
    public static function relativeTimeManyCases(): array
    {
        return [
            '2분' => [120, 'standard', '2 minutes ago', '2분 전'],
            '2시간' => [7200, 'standard', '2 hours ago', '2시간 전'],
            '3일' => [86400 * 3, 'relative', '3 days ago', '3일 전'],
            '2개월' => [86400 * 65, 'relative', '2 months ago', '2개월 전'],
            '2년' => [86400 * 740, 'relative', '2 years ago', '2년 전'],
        ];
    }

    /**
     * 상대 시각은 수가 1 이면 en 에서 단수로 나오고 ko 는 종전 표기 그대로다.
     *
     * @scenario surface=relative_time, count=one
     *
     * @effects en_relative_time_uses_singular_for_one_and_plural_otherwise, ko_relative_time_is_unchanged
     *
     * @param  int  $secondsAgo  지금으로부터 지난 초
     * @param  string  $format  표시 형식(표준형 또는 유동형)
     * @param  string  $expectedEn  기대하는 영어 문구
     * @param  string  $expectedKo  기대하는 한국어 문구
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('relativeTimeOneCases')]
    public function relative_time_uses_singular_for_one(int $secondsAgo, string $format, string $expectedEn, string $expectedKo): void
    {
        $this->assertRelativeTime($secondsAgo, $format, $expectedEn, $expectedKo);
    }

    /**
     * 상대 시각은 수가 2 이상이면 en 에서 복수로 나오고 ko 는 종전 표기 그대로다.
     *
     * @scenario surface=relative_time, count=many
     *
     * @effects en_relative_time_uses_singular_for_one_and_plural_otherwise, ko_relative_time_is_unchanged
     *
     * @param  int  $secondsAgo  지금으로부터 지난 초
     * @param  string  $format  표시 형식(표준형 또는 유동형)
     * @param  string  $expectedEn  기대하는 영어 문구
     * @param  string  $expectedKo  기대하는 한국어 문구
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('relativeTimeManyCases')]
    public function relative_time_uses_plural_for_many(int $secondsAgo, string $format, string $expectedEn, string $expectedKo): void
    {
        $this->assertRelativeTime($secondsAgo, $format, $expectedEn, $expectedKo);
    }

    /**
     * 쿨다운 기간 단수 변종(초, 기대 en, 기대 ko)을 돌려줍니다. 단위 가운데 1 인 것이 있다.
     *
     * @return array<string, array{0: int, 1: string, 2: string}> 변종 목록
     */
    public static function cooldownOneCases(): array
    {
        return [
            '1초' => [1, '1 second', '1초'],
            '1분' => [60, '1 minute', '1분'],
            '1분 1초' => [61, '1 minute 1 second', '1분 1초'],
            '1분 30초' => [90, '1 minute 30 seconds', '1분 30초'],
            '1시간' => [3600, '1 hour', '1시간'],
            '1시간 1분' => [3660, '1 hour 1 minute', '1시간 1분'],
            '1시간 30분' => [5400, '1 hour 30 minutes', '1시간 30분'],
        ];
    }

    /**
     * 쿨다운 기간 복수 변종(초, 기대 en, 기대 ko)을 돌려줍니다. 모든 단위가 2 이상이다.
     *
     * @return array<string, array{0: int, 1: string, 2: string}> 변종 목록
     */
    public static function cooldownManyCases(): array
    {
        return [
            '45초' => [45, '45 seconds', '45초'],
            '2분' => [120, '2 minutes', '2분'],
            '2시간' => [7200, '2 hours', '2시간'],
        ];
    }

    /**
     * 쿨다운 기간은 단위마다 수가 1 이면 en 에서 단수로 나오고 ko 는 종전 표기 그대로다.
     *
     * @scenario surface=cooldown_duration, count=one
     *
     * @effects en_cooldown_duration_uses_singular_for_one_and_plural_otherwise, ko_cooldown_duration_is_unchanged
     *
     * @param  int  $seconds  쿨다운 초
     * @param  string  $expectedEn  기대하는 영어 문구
     * @param  string  $expectedKo  기대하는 한국어 문구
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('cooldownOneCases')]
    public function cooldown_duration_uses_singular_for_one(int $seconds, string $expectedEn, string $expectedKo): void
    {
        $this->assertCooldown($seconds, $expectedEn, $expectedKo);
    }

    /**
     * 쿨다운 기간은 단위 수가 모두 2 이상이면 en 에서 복수로 나오고 ko 는 종전 표기 그대로다.
     *
     * @scenario surface=cooldown_duration, count=many
     *
     * @effects en_cooldown_duration_uses_singular_for_one_and_plural_otherwise, ko_cooldown_duration_is_unchanged
     *
     * @param  int  $seconds  쿨다운 초
     * @param  string  $expectedEn  기대하는 영어 문구
     * @param  string  $expectedKo  기대하는 한국어 문구
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('cooldownManyCases')]
    public function cooldown_duration_uses_plural_for_many(int $seconds, string $expectedEn, string $expectedKo): void
    {
        $this->assertCooldown($seconds, $expectedEn, $expectedKo);
    }

    /**
     * 상대 시각을 en, ko 로 차례로 포맷해 기대 문구와 비교합니다.
     *
     * @param  int  $secondsAgo  지금으로부터 지난 초
     * @param  string  $format  표시 형식(표준형 또는 유동형)
     * @param  string  $expectedEn  기대하는 영어 문구
     * @param  string  $expectedKo  기대하는 한국어 문구
     * @return void 반환값 없음
     */
    private function assertRelativeTime(int $secondsAgo, string $format, string $expectedEn, string $expectedKo): void
    {
        $dateTime = Carbon::now()->subSeconds($secondsAgo);

        App::setLocale('en');
        $this->assertSame($expectedEn, $this->subject->call($dateTime, $format));

        App::setLocale('ko');
        $this->assertSame($expectedKo, $this->subject->call($dateTime, $format));
    }

    /**
     * 쿨다운 기간을 en, ko 로 차례로 문구로 만들어 기대 문구와 비교합니다.
     *
     * @param  int  $seconds  쿨다운 초
     * @param  string  $expectedEn  기대하는 영어 문구
     * @param  string  $expectedKo  기대하는 한국어 문구
     * @return void 반환값 없음
     */
    private function assertCooldown(int $seconds, string $expectedEn, string $expectedKo): void
    {
        $rule = new CooldownRule('report', $seconds, 'test-board');
        $method = new \ReflectionMethod($rule, 'formatDuration');

        App::setLocale('en');
        $this->assertSame($expectedEn, $method->invoke($rule, $seconds));

        App::setLocale('ko');
        $this->assertSame($expectedKo, $method->invoke($rule, $seconds));
    }
}
