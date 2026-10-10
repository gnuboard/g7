<?php

namespace Tests\Unit\Analytics;

use App\Analytics\ReferrerClassifier;
use App\Enums\Analytics\ReferrerAnonymization;
use App\Enums\Analytics\ReferrerType;
use Tests\TestCase;

/**
 * 유입 경로 분류 — 직접·검색·사이트·캠페인.
 *
 * 사이트 안에서의 이동이 「다른 사이트에서 왔다」로 집계되면 유입 목록의 1위가 늘
 * 자기 자신이 된다. 검색 키워드가 비어 오는 것은 정상이며(HTTPS 이후 대부분의 엔진이
 * 넘기지 않는다), 그 사실을 판정 실패와 구분해야 한다.
 */
class ReferrerClassifierTest extends TestCase
{
    /**
     * utm_medium 만 붙은 주소는 유입 종류를 바꾸지 않고 매체만 남긴다.
     *
     * @scenario referrer=absent|search|website, campaign=medium_only
     *
     * @effects utm_medium_alone_is_recorded
     *
     * @return void 없음
     */
    public function test_utm_medium_alone_keeps_the_type_but_records_the_medium(): void
    {
        // 매체만 있으면 어느 캠페인인지 모른다 — 유입 종류는 그대로 두고 매체만 남긴다.
        $direct = ReferrerClassifier::classify(null, ['utm_medium' => 'qa_email']);
        $this->assertSame(ReferrerType::Direct, $direct['type']);
        $this->assertSame('qa_email', $direct['campaign_medium']);
        $this->assertNull($direct['campaign_name']);

        $search = ReferrerClassifier::classify('https://www.google.com/search?q=g7', ['utm_medium' => 'cpc']);
        $this->assertSame(ReferrerType::Search, $search['type']);
        $this->assertSame('cpc', $search['campaign_medium']);

        $site = ReferrerClassifier::classify('https://example.org/post', ['utm_medium' => 'banner']);
        $this->assertSame(ReferrerType::Website, $site['type']);
        $this->assertSame('banner', $site['campaign_medium']);

        // 매체가 없으면 예전처럼 비어 있다.
        $this->assertNull(ReferrerClassifier::classify('https://example.org/post')['campaign_medium']);
    }

    /**
     * 유입 정보가 없으면 직접 유입이다.
     *
     * @scenario referrer=absent|empty
     *
     * @effects referrer_type_is_classified_into_direct_search_website_campaign
     *
     * @return void 없음
     */
    public function test_missing_referrer_is_direct(): void
    {
        $this->assertSame(ReferrerType::Direct, ReferrerClassifier::classify(null)['type']);
        $this->assertSame(ReferrerType::Direct, ReferrerClassifier::classify('')['type']);
    }

    /**
     * 같은 사이트에서 온 이동은 유입이 아니다.
     *
     * @scenario referrer=self_host
     *
     * @effects same_site_navigation_is_not_counted_as_referral
     *
     * @return void 없음
     */
    public function test_same_site_referrer_is_direct(): void
    {
        // 사이트 주소의 출처는 운영자가 저장한 설정이다 — app.url 만 바꾸면 실제 판정에
        // 쓰이는 값이 그대로라 이 테스트가 무의미해진다.
        config(['g7_settings.core.general.site_url' => 'https://example.test']);

        $result = ReferrerClassifier::classify('https://www.example.test/board/free');

        $this->assertSame(ReferrerType::Direct, $result['type']);
        $this->assertNull($result['name']);
    }

    /**
     * 검색엔진을 가르고 키워드를 뽑는다.
     *
     * @scenario referrer=search, keyword=present
     *
     * @effects search_keyword_is_extracted_when_the_engine_sends_it
     *
     * @return void 없음
     */
    public function test_classifies_search_engines_with_keyword(): void
    {
        $google = ReferrerClassifier::classify('https://www.google.com/search?q=그누보드');
        $this->assertSame(ReferrerType::Search, $google['type']);
        $this->assertSame('Google', $google['name']);
        $this->assertSame('그누보드', $google['keyword']);

        $naver = ReferrerClassifier::classify('https://search.naver.com/search.naver?query=게시판');
        $this->assertSame('Naver', $naver['name']);
        $this->assertSame('게시판', $naver['keyword']);
    }

    /**
     * 키워드가 없어도 검색 유입 판정은 유지된다.
     *
     * @scenario referrer=search, keyword=absent
     *
     * @effects referrer_type_is_classified_into_direct_search_website_campaign
     *
     * @return void 없음
     */
    public function test_search_without_keyword_still_classified(): void
    {
        $result = ReferrerClassifier::classify('https://www.google.com/');

        $this->assertSame(ReferrerType::Search, $result['type']);
        $this->assertSame('Google', $result['name']);
        $this->assertNull($result['keyword']);
    }

    /**
     * 그 밖의 사이트는 호스트 이름으로 남는다.
     *
     * @scenario referrer=website
     *
     * @effects referrer_type_is_classified_into_direct_search_website_campaign
     *
     * @return void 없음
     */
    public function test_classifies_other_websites(): void
    {
        $result = ReferrerClassifier::classify('https://example.org/blog/post');

        $this->assertSame(ReferrerType::Website, $result['type']);
        $this->assertSame('example.org', $result['name']);
    }

    /**
     * 캠페인 표식이 있으면 유입 출처보다 캠페인이 우선한다.
     *
     * @scenario campaign=present, referrer=website
     *
     * @effects campaign_parameters_take_precedence_over_referrer
     *
     * @return void 없음
     */
    public function test_campaign_parameters_take_precedence(): void
    {
        $result = ReferrerClassifier::classify('https://t.co/abc', [
            'utm_source' => 'twitter',
            'utm_campaign' => 'spring',
            'utm_medium' => 'social',
        ]);

        $this->assertSame(ReferrerType::Campaign, $result['type']);
        $this->assertSame('twitter', $result['campaign_source']);
        $this->assertSame('spring', $result['campaign_name']);
        $this->assertSame('social', $result['campaign_medium']);
    }

    /**
     * 익명화를 켜면 도메인까지만 남는다.
     *
     * 경로와 쿼리에는 방문자가 그 사이트에서 무엇을 보고 있었는지가 담긴다.
     *
     * @scenario referrer_anon=domain_only
     *
     * @effects referrer_anonymization_keeps_domain_only
     *
     * @return void 없음
     */
    public function test_domain_only_anonymization_drops_path(): void
    {
        $result = ReferrerClassifier::classify(
            'https://example.org/blog/post?secret=1',
            [],
            ReferrerAnonymization::DomainOnly,
        );

        $this->assertSame('https://example.org', $result['url']);
    }

    /**
     * 익명화를 끄면 원본 URL 이 그대로 남는다.
     *
     * @scenario referrer_anon=none
     *
     * @effects referrer_anonymization_keeps_domain_only
     *
     * @return void 없음
     */
    public function test_no_anonymization_keeps_full_url(): void
    {
        $url = 'https://example.org/blog/post?page=2';

        $this->assertSame($url, ReferrerClassifier::classify($url)['url']);
    }

    /**
     * 유입 주소는 조각(#…)과 추적 파라미터를 떼고 남긴다 — 나머지 쿼리는 원래 순서 그대로다.
     *
     * 조각에는 다른 사이트가 붙인 인증 토큰 같은 값이 실릴 수 있고(브라우저가 서버에 보내지 않는 자리라
     * 그 사이트도 남기려던 값이 아니다), 추적 파라미터는 같은 글을 광고 링크마다 다른 주소로 갈라 놓는다.
     *
     * @scenario referrer=website
     *
     * @effects referrer_url_drops_fragment_and_tracking_params
     *
     * @return void 없음
     */
    public function test_referrer_url_drops_fragment_and_tracking_params(): void
    {
        $url = 'https://example.org/blog/post?b=2&utm_source=news&a=1&fbclid=XYZ#access_token=secret';

        $this->assertSame('https://example.org/blog/post?b=2&a=1', ReferrerClassifier::classify($url)['url']);
        $this->assertSame('https://example.org/blog/post', ReferrerClassifier::classify('https://example.org/blog/post?gclid=1#top')['url']);
    }

    /**
     * 검색엔진 10종이 각자의 키워드 파라미터(q·query·p·wd·text)로 분류된다.
     *
     * 표에서 엔진 하나가 빠지거나 파라미터 이름이 틀리면 그 엔진 유입이 「일반 사이트」로
     * 새고 키워드가 비어 보인다 — 검색어가 원래 잘 안 오는 시대라 그 결손이 눈에 안 띈다.
     *
     * @scenario referrer=search, engine=google|naver|daum|bing|yahoo|duckduckgo|baidu|yandex|zum|nate
     *
     * @effects search_keyword_is_extracted_when_the_engine_sends_it, referrer_type_is_classified_into_direct_search_website_campaign
     *
     * @return void 없음
     */
    public function test_ten_search_engines_and_their_keyword_parameters(): void
    {
        $cases = [
            ['https://www.google.co.kr/search?q=그누보드', 'Google', '그누보드'],
            ['https://search.naver.com/search.naver?where=nexearch&query=게시판', 'Naver', '게시판'],
            ['https://search.daum.net/search?w=tot&q=다음검색', 'Daum', '다음검색'],
            ['https://www.bing.com/search?q=bing+query', 'Bing', 'bing query'],
            ['https://search.yahoo.com/search?p=yahoo+words', 'Yahoo', 'yahoo words'],
            ['https://search.yahoo.co.jp/search?q=fallback', 'Yahoo', 'fallback'],
            ['https://duckduckgo.com/?q=privacy', 'DuckDuckGo', 'privacy'],
            ['https://www.baidu.com/s?wd=百度', 'Baidu', '百度'],
            ['https://yandex.ru/search/?text=яндекс', 'Yandex', 'яндекс'],
            ['https://search.zum.com/search.zum?query=줌', 'Zum', '줌'],
            ['https://search.nate.com/search/all.html?q=네이트', 'Nate', '네이트'],
        ];

        foreach ($cases as [$referrer, $engine, $keyword]) {
            $result = ReferrerClassifier::classify($referrer);
            $this->assertSame(ReferrerType::Search, $result['type'], $referrer);
            $this->assertSame($engine, $result['name'], $referrer);
            $this->assertSame($keyword, $result['keyword'], $referrer);
        }

        $this->assertSame(10, count(array_unique(array_column($cases, 1))), '검색엔진 10종을 전부 덮는다');
    }
}
