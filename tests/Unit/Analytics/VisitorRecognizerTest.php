<?php

namespace Tests\Unit\Analytics;

use App\Analytics\AnalyticsConfig;
use App\Analytics\TrackingRequest;
use App\Analytics\VisitExclusionPipeline;
use App\Analytics\VisitorRecognizer;
use App\Contracts\Repositories\Analytics\SaltRepositoryInterface;
use App\Enums\Analytics\ExclusionReason;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * 방문자 식별 — 쿠키 키·일자 해시·솔트 회전.
 *
 * 방문자 키가 틀리면 방문이 갈라지거나 합쳐진다. 두 사람이 한 방문이 되거나 한 사람이
 * 매 요청마다 새 방문이 되어도 화면에는 숫자만 보인다 — 키가 어떻게 만들어지는지를
 * 직접 단언해야 드러난다.
 *
 * 솔트 저장소는 메모리 가짜로 바꿔 DB 없이 판정한다 — 솔트는 「그날 하나의 문자열」
 * 이라는 계약만 지키면 되고, 그 저장 자체는 저장소 테스트가 본다.
 */
class VisitorRecognizerTest extends TestCase
{
    private VisitorRecognizer $recognizer;

    /** @var array<string, string> 날짜별 솔트 (가짜 저장소) */
    private array $salts = [];

    /**
     * 테스트 준비 — 메모리 솔트 저장소를 꽂는다.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $salts = &$this->salts;

        $this->app->instance(SaltRepositoryInterface::class, new class($salts) implements SaltRepositoryInterface
        {
            /**
             * 메모리 솔트 저장소를 만듭니다.
             *
             * @param  array<string, string>  $salts  날짜별 솔트 저장소 (참조)
             */
            public function __construct(private array &$salts) {}

            /**
             * 그날 솔트를 돌려주고 없으면 만듭니다.
             *
             * @param  string  $date  일자 (Y-m-d)
             * @return string 솔트
             */
            public function forDate(string $date): string
            {
                return $this->salts[$date] ??= bin2hex(random_bytes(32));
            }

            /**
             * 그날 솔트가 있으면 돌려줍니다.
             *
             * @param  string  $date  일자 (Y-m-d)
             * @return string|null 솔트 (없으면 null)
             */
            public function findForDate(string $date): ?string
            {
                return $this->salts[$date] ?? null;
            }

            /**
             * 기준일 이전의 솔트를 지웁니다.
             *
             * @param  Carbon  $before  기준 시각
             * @return int 지운 건수
             */
            public function deleteOlderThan(Carbon $before): int
            {
                $deleted = 0;

                foreach (array_keys($this->salts) as $date) {
                    if ($date < $before->format('Y-m-d')) {
                        unset($this->salts[$date]);
                        $deleted++;
                    }
                }

                return $deleted;
            }

            /**
             * 기준일 이전의 솔트 수를 셉니다.
             *
             * @param  Carbon  $before  기준 시각
             * @return int 해당 건수
             */
            public function countOlderThan(Carbon $before): int
            {
                return count(array_filter(
                    array_keys($this->salts),
                    fn (string $date): bool => $date < $before->format('Y-m-d'),
                ));
            }
        });

        $this->recognizer = app(VisitorRecognizer::class);
    }

    /**
     * 테스트 정리 — 고정한 시각을 되돌린다.
     *
     * @return void 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 수집 요청 하나를 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return TrackingRequest 수집 요청
     */
    private function request(array $overrides = []): TrackingRequest
    {
        return new TrackingRequest(...array_merge([
            'type' => 'pageview',
            'url' => 'https://example.test/',
            'ip' => '203.0.113.50',
            'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0.0.0 Safari/537.36',
            'browserLanguage' => 'ko-KR',
            'resolution' => '1920x1080',
            'receivedAt' => now()->format('Y-m-d H:i:s'),
        ], $overrides));
    }

    /**
     * 쿠키가 있으면 그 값이 방문자 키가 되고 재방문으로 본다.
     *
     * @scenario mode=full, visitor=guest
     *
     * @effects full_mode_reports_returning_visitors
     *
     * @return void 없음
     */
    public function test_existing_cookie_is_the_visitor_key(): void
    {
        $cookie = str_repeat('c', 32);

        $identity = $this->recognizer->resolve(
            $this->request(['cookies' => ['g7_vid' => $cookie]]),
            AnalyticsConfig::fromSettings(),
            false,
        );

        $this->assertSame($cookie, $identity['key'], '쿠키 값이 그대로 키여야 같은 방문으로 묶인다');
        $this->assertSame('cookie', $identity['key_type']);
        $this->assertNull($identity['new_cookie'], '이미 있는 쿠키를 다시 심지 않는다');
        $this->assertTrue($identity['is_returning']);
    }

    /**
     * 쿠키가 없으면 32자 새 키를 만들고 쿠키 발급을 예약한다.
     *
     * @scenario mode=full, visitor=guest, visit_state=new
     *
     * @effects full_mode_stores_masked_ip_and_issues_cookie
     *
     * @return void 없음
     */
    public function test_missing_cookie_creates_new_key_and_schedules_cookie(): void
    {
        $identity = $this->recognizer->resolve($this->request(), AnalyticsConfig::fromSettings(), false);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $identity['key']);
        $this->assertSame($identity['key'], $identity['new_cookie'], '응답에 심을 쿠키는 키와 같은 값이어야 한다');
        $this->assertFalse($identity['is_returning']);
    }

    /**
     * 형식이 어긋난 쿠키 값은 믿지 않고 새 키를 만든다.
     *
     * @scenario mode=full, visitor=guest
     *
     * @effects full_mode_stores_masked_ip_and_issues_cookie
     *
     * @return void 없음
     */
    public function test_malformed_cookie_is_replaced(): void
    {
        $identity = $this->recognizer->resolve(
            $this->request(['cookies' => ['g7_vid' => 'not-a-hex-key']]),
            AnalyticsConfig::fromSettings(),
            false,
        );

        $this->assertNotSame('not-a-hex-key', $identity['key']);
        $this->assertNotNull($identity['new_cookie']);
    }

    /**
     * 동의 모드에서 동의가 없으면 식별 전에 제외된다.
     *
     * 식별기는 동의를 모른다 — 그 앞의 제외 판정이 동의 없는 요청을 걸러 식별기까지
     * 오지 않게 한다. 그 순서가 계약이다.
     *
     * @scenario mode=consent, exclusion=consent
     *
     * @effects consent_mode_with_declined_consent_records_nothing
     *
     * @return void 없음
     */
    public function test_declined_consent_is_excluded_before_identification(): void
    {
        config(['g7_settings.core.analytics.privacy_mode' => 'consent']);

        $reason = app(VisitExclusionPipeline::class)->evaluate(
            $this->request(),
            AnalyticsConfig::fromSettings(),
            null,
            false,
        );

        $this->assertSame(ExclusionReason::Consent, $reason);
    }

    /**
     * 익명 모드의 키는 같은 입력에 같은 값이다.
     *
     * @scenario mode=anonymous
     *
     * @effects anonymous_visitor_key_is_deterministic_within_a_day
     *
     * @return void 없음
     */
    public function test_anonymous_key_is_deterministic(): void
    {
        $first = $this->recognizer->resolve($this->request(), AnalyticsConfig::fromSettings(), true);
        $second = $this->recognizer->resolve($this->request(), AnalyticsConfig::fromSettings(), true);

        $this->assertSame('config', $first['key_type']);
        $this->assertSame($first['key'], $second['key'], '같은 환경은 같은 날 같은 키여야 방문이 묶인다');
        $this->assertSame(32, strlen($first['key']));
        $this->assertNull($first['new_cookie'], '익명 모드는 쿠키를 심지 않는다');
        $this->assertNull($first['is_returning'], '익명 모드는 재방문을 판정하지 않는다');
    }

    /**
     * 익명 모드의 키는 환경이 다르면 다르다.
     *
     * @scenario mode=anonymous
     *
     * @effects anonymous_visitor_key_differs_across_environments
     *
     * @return void 없음
     */
    public function test_anonymous_key_differs_by_environment(): void
    {
        $base = $this->recognizer->configId($this->request());
        $otherBrowser = $this->recognizer->configId($this->request([
            'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0',
        ]));
        $otherIp = $this->recognizer->configId($this->request(['ip' => '198.51.100.9']));
        $otherLanguage = $this->recognizer->configId($this->request(['browserLanguage' => 'ja-JP']));

        $this->assertNotSame($base, $otherBrowser);
        $this->assertNotSame($base, $otherIp);
        $this->assertNotSame($base, $otherLanguage);

        // 해상도·클라이언트 힌트는 서버 폴백 요청에 없다 — 재료에 넣으면 서버 폴백과
        // 비콘이 영원히 다른 키를 얻어 매 로드가 방문 2건이 된다.
        $noResolution = $this->recognizer->configId($this->request(['resolution' => null]));
        $withHints = $this->recognizer->configId($this->request([
            'clientHints' => ['brands' => [['brand' => 'Chromium', 'version' => '128']], 'platform' => 'Windows'],
        ]));

        $this->assertSame($base, $noResolution, '해상도는 키 재료가 아니다');
        $this->assertSame($base, $withHints, '클라이언트 힌트는 키 재료가 아니다');
    }

    /**
     * 날짜가 바뀌면 같은 환경도 다른 키가 된다.
     *
     * 이것이 익명 모드의 목적이다 — 어제와 오늘을 이어 붙일 수 없어야 한다.
     *
     * @scenario mode=anonymous
     *
     * @effects anonymous_visitor_key_is_deterministic_within_a_day
     *
     * @return void 없음
     */
    public function test_anonymous_key_rotates_daily(): void
    {
        Carbon::setTestNow('2026-09-16 12:00:00');
        $yesterday = $this->recognizer->configId($this->request());

        Carbon::setTestNow('2026-09-17 12:00:00');
        $today = $this->recognizer->configId($this->request());

        $this->assertNotSame($yesterday, $today);
    }

    /**
     * 솔트는 날마다 다르고, 지워진 날의 해시는 다시 만들 수 없다.
     *
     * @scenario mode=anonymous
     *
     * @effects anonymous_mode_stores_salted_ip_hash_instead, past_date_ip_hash_is_unavailable_once_the_salt_is_gone
     *
     * @return void 없음
     */
    public function test_salt_rotates_and_past_hash_is_unavailable_once_gone(): void
    {
        $hashDayOne = $this->recognizer->ipHash('203.0.113.50', '2026-09-10');
        $hashDayTwo = $this->recognizer->ipHash('203.0.113.50', '2026-09-11');

        $this->assertNotSame($hashDayOne, $hashDayTwo, '날마다 솔트가 달라야 날을 넘겨 이어 붙일 수 없다');
        $this->assertSame($hashDayOne, $this->recognizer->ipHashForPastDate('203.0.113.50', '2026-09-10'));

        // 솔트 정리 뒤에는 그날 해시를 재계산할 수 없다 — 화면이 「찾을 수 없음」을 안내한다.
        app(SaltRepositoryInterface::class)->deleteOlderThan(Carbon::parse('2026-09-11'));

        $this->assertNull($this->recognizer->ipHashForPastDate('203.0.113.50', '2026-09-10'));
    }

    /**
     * 익명 키는 마스킹 전 원본 IP 로 만든다.
     *
     * 마스킹된 IP 로 만들면 같은 /24 안의 모든 방문자가 한 사람이 된다.
     *
     * @scenario mode=anonymous, mask_octets=1
     *
     * @effects anonymous_visitor_key_differs_across_environments
     *
     * @return void 없음
     */
    public function test_anonymous_key_uses_raw_ip_not_masked(): void
    {
        config(['g7_settings.core.analytics.ip_mask_octets' => 1]);

        $first = $this->recognizer->configId($this->request(['ip' => '203.0.113.50']));
        $second = $this->recognizer->configId($this->request(['ip' => '203.0.113.51']));

        $this->assertNotSame($first, $second, '마지막 옥텟만 다른 두 사람이 한 키로 합쳐지면 안 된다');
    }
}
