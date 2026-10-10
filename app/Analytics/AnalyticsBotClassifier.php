<?php

namespace App\Analytics;

use App\Seo\BotDetector;
use App\Seo\BotDetectorCustomProvider;
use Illuminate\Http\Request;

/**
 * 봇 판별
 *
 * SEO 기능이 쓰는 판별기를 그대로 재사용합니다 — 같은 요청을 두 곳이 다르게 판정하면
 * 「SEO 는 봇으로 보는데 통계는 사람으로 세는」 상태가 됩니다.
 *
 * 다만 SEO 의 봇 감지 토글이 꺼져 있으면 그 판별기는 **무조건 false** 를 돌려줍니다.
 * 그 설정은 「봇에게 캐시된 화면을 줄지」를 정하는 것이지 「봇이 아니다」라는 뜻이
 * 아닙니다. 그래서 꺼져 있을 때는 통계가 같은 크롤러 사전(crawler-detect)에 통계 설정의
 * 제외 User-Agent 패턴을 더해 직접 판정합니다 — 손으로 적은 짧은 목록으로 대신하면
 * 사전이 아는 수백 종의 봇이 사람 방문으로 섞여 인기 페이지가 통째로 뒤집힙니다.
 */
class AnalyticsBotClassifier
{
    /** 판정 결과를 요청에 캐시하는 키 */
    private const ATTRIBUTE_KEY = 'analytics.is_bot';

    /** 봇 이름을 사전에서 못 뽑았을 때 User-Agent 에서 남기는 길이 */
    private const NAME_FALLBACK_LENGTH = 60;

    /**
     * 패턴 목록별 크롤러 사전 캐시
     *
     * 사전은 만들 때 정규식 수백 개를 컴파일하므로 요청마다 새로 만들지 않습니다.
     *
     * @var array<string, BotDetectorCustomProvider>
     */
    private static array $providers = [];

    /**
     * 봇 판별기를 생성합니다.
     *
     * @param  BotDetector  $botDetector  SEO 봇 판별기
     */
    public function __construct(private readonly BotDetector $botDetector) {}

    /**
     * HTTP 요청이 봇인지 판정합니다.
     *
     * 같은 요청에서 여러 번 물어도 한 번만 계산합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  AnalyticsConfig  $config  설정
     * @return bool 봇 여부
     */
    public function isBot(Request $request, AnalyticsConfig $config): bool
    {
        if ($request->attributes->has(self::ATTRIBUTE_KEY)) {
            return (bool) $request->attributes->get(self::ATTRIBUTE_KEY);
        }

        $result = $this->judge(
            $request->userAgent(),
            $config,
            fn (): bool => $this->botDetector->isBot($request),
        );

        $request->attributes->set(self::ATTRIBUTE_KEY, $result);

        return $result;
    }

    /**
     * 수집 요청이 봇인지 판정합니다.
     *
     * 큐로 넘어간 뒤에는 HTTP 요청 객체가 없으므로 User-Agent 만으로 판정합니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @param  AnalyticsConfig  $config  설정
     * @return bool 봇 여부
     */
    public function isBotUserAgent(TrackingRequest $request, AnalyticsConfig $config): bool
    {
        return $this->judge($request->userAgent, $config, fn (): bool => false);
    }

    /**
     * 판정 본체.
     *
     * @param  string|null  $userAgent  User-Agent 문자열
     * @param  AnalyticsConfig  $config  설정
     * @param  callable(): bool  $seoJudgment  SEO 판별기 판정 (HTTP 요청이 있을 때만 의미가 있다)
     * @return bool 봇 여부
     */
    private function judge(?string $userAgent, AnalyticsConfig $config, callable $seoJudgment): bool
    {
        if (! $config->botDetectorEnabled) {
            return false;
        }

        // SEO 봇 감지가 켜져 있으면 그 판정을 그대로 따른다. 기본값은 코어 판별기와
        // 같은 true 를 쓴다 — 여기서만 false 로 읽으면 켜진 사이트에서 폴백 경로로 새어
        // 두 기능의 판정이 갈린다.
        if ((bool) g7_core_settings('seo.bot_detection_enabled', true) && $seoJudgment()) {
            return true;
        }

        $ua = $userAgent ?? '';

        if ($ua === '') {
            // User-Agent 가 없는 요청은 코어 판별기(BotDetector)와 같이 사람으로 둔다 —
            // 두 판별기의 판정이 갈리면 SEO 켬/끔에 따라 같은 요청이 다르게 집계된다.
            return false;
        }

        return self::matchesCrawlerDictionary($ua, $config->excludedUserAgents)
            || self::matchesHeadlessPattern($ua);
    }

    /**
     * 크롤러 사전과 운영자 제외 패턴으로 봇인지 판정합니다.
     *
     * SEO 판별기가 꺼졌을 때의 폴백이며, SEO 판별기와 **같은 사전**을 씁니다. 운영자가
     * 통계 설정에 적은 제외 User-Agent 는 사전에 더해져 함께 판정됩니다.
     *
     * @param  string  $userAgent  User-Agent 문자열
     * @param  array<int, string>  $extraPatterns  통계 설정의 제외 User-Agent 패턴
     * @return bool 봇 여부
     */
    public static function matchesCrawlerDictionary(string $userAgent, array $extraPatterns = []): bool
    {
        return self::provider($extraPatterns)->isCrawler($userAgent);
    }

    /**
     * 자동화 브라우저 표기가 있는지 판정합니다.
     *
     * @param  string  $userAgent  User-Agent 문자열
     * @return bool 자동화 브라우저 여부
     */
    public static function matchesHeadlessPattern(string $userAgent): bool
    {
        foreach ((array) config('analytics.headless_patterns', []) as $pattern) {
            if (is_string($pattern) && $pattern !== '' && stripos($userAgent, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 봇 이름을 뽑습니다.
     *
     * 크롤러 사전이 맞춘 표기를 이름으로 씁니다 — 같은 봇은 버전이 달라도 같은 이름으로
     * 모여야 봇 순위가 의미를 갖습니다. 사전이 모르는 봇은 User-Agent 앞부분으로 대신해
     * 화면에서 「무엇이 왔는지」는 구분되게 합니다.
     *
     * @param  string|null  $userAgent  User-Agent 문자열
     * @param  array<int, string>  $extraPatterns  통계 설정의 제외 User-Agent 패턴
     * @return string 봇 이름
     */
    public static function botName(?string $userAgent, array $extraPatterns = []): string
    {
        $ua = trim($userAgent ?? '');

        if ($ua === '') {
            return 'Unknown';
        }

        $provider = self::provider($extraPatterns);

        if ($provider->isCrawler($ua)) {
            $matched = trim((string) $provider->getMatches());

            if ($matched !== '') {
                return mb_substr($matched, 0, 100);
            }
        }

        return mb_substr($ua, 0, self::NAME_FALLBACK_LENGTH);
    }

    /**
     * 패턴 목록에 맞는 크롤러 사전을 돌려줍니다.
     *
     * @param  array<int, string>  $extraPatterns  사전에 더할 패턴
     * @return BotDetectorCustomProvider 크롤러 사전
     */
    private static function provider(array $extraPatterns): BotDetectorCustomProvider
    {
        $patterns = array_values(array_filter($extraPatterns, static fn ($p): bool => is_string($p) && trim($p) !== ''));
        $cacheKey = md5(implode("\n", $patterns));

        return self::$providers[$cacheKey] ??= new BotDetectorCustomProvider($patterns);
    }
}
