<?php

namespace Tests\Unit\Analytics;

use App\Analytics\ActionNameHasher;
use App\Analytics\UrlNormalizer;
use Tests\TestCase;

/**
 * URL 정규화 — 접두 분리·추적 파라미터 제거·해시 안정성.
 *
 * 같은 페이지가 여러 표기로 들어오면 인기 페이지 목록에서 한 페이지가 여러 줄로
 * 쪼개진다. 반대로 정규화가 과하면 서로 다른 페이지가 한 줄로 합쳐진다 — 둘 다
 * 숫자만 봐서는 틀린 줄 모른다.
 */
class UrlNormalizerTest extends TestCase
{
    /**
     * 스킴과 www 를 접두 코드로 분리한다.
     *
     * @scenario prefix=http|http_www|https|https_www
     *
     * @effects url_normalization_collapses_scheme_www_and_tracking_params
     *
     * @return void 없음
     */
    public function test_splits_scheme_and_www_into_prefix(): void
    {
        $cases = [
            ['http://example.com/a', UrlNormalizer::PREFIX_HTTP],
            ['http://www.example.com/a', UrlNormalizer::PREFIX_HTTP_WWW],
            ['https://example.com/a', UrlNormalizer::PREFIX_HTTPS],
            ['https://www.example.com/a', UrlNormalizer::PREFIX_HTTPS_WWW],
        ];

        foreach ($cases as [$url, $prefix]) {
            $result = UrlNormalizer::normalize($url);
            $this->assertSame('example.com/a', $result['name'], $url);
            $this->assertSame($prefix, $result['url_prefix'], $url);
        }
    }

    /**
     * 접두 코드로 원래 주소를 되돌린다.
     *
     * @scenario restore=all_prefixes
     *
     * @effects url_prefix_is_kept_so_the_original_address_can_be_restored
     *
     * @return void 없음
     */
    public function test_restores_original_url(): void
    {
        $this->assertSame('https://www.example.com/a', UrlNormalizer::restore('example.com/a', UrlNormalizer::PREFIX_HTTPS_WWW));
        $this->assertSame('http://example.com/a', UrlNormalizer::restore('example.com/a', UrlNormalizer::PREFIX_HTTP));
        $this->assertSame('/relative', UrlNormalizer::restore('/relative', null));
    }

    /**
     * 프래그먼트와 추적 파라미터를 버린다.
     *
     * @scenario strip=fragment|utm|fbclid|gclid
     *
     * @effects url_normalization_collapses_scheme_www_and_tracking_params
     *
     * @return void 없음
     */
    public function test_strips_fragment_and_tracking_params(): void
    {
        $result = UrlNormalizer::normalize('https://example.com/x?page=2&utm_source=naver&fbclid=z#comment');

        $this->assertSame('example.com/x?page=2', $result['name']);
    }

    /**
     * 캠페인 추출용 원본 쿼리는 그대로 넘겨준다.
     *
     * 정규화가 utm 을 지우기 전에 캠페인을 뽑아야 하므로, 지운 값도 함께 돌려준다.
     *
     * @scenario query=preserved_for_campaign
     *
     * @effects campaign_parameters_take_precedence_over_referrer
     *
     * @return void 없음
     */
    public function test_returns_original_query_for_campaign_extraction(): void
    {
        $result = UrlNormalizer::normalize('https://example.com/x?utm_campaign=spring');

        $this->assertSame('example.com/x', $result['name']);
        $this->assertSame('spring', $result['query']['utm_campaign']);
    }

    /**
     * 파라미터 순서가 달라도 같은 페이지로 본다.
     *
     * @scenario query=reordered
     *
     * @effects url_normalization_collapses_scheme_www_and_tracking_params
     *
     * @return void 없음
     */
    public function test_query_order_does_not_split_page(): void
    {
        $a = UrlNormalizer::normalize('https://example.com/x?a=1&b=2');
        $b = UrlNormalizer::normalize('https://example.com/x?b=2&a=1');

        $this->assertSame($a['name'], $b['name']);
    }

    /**
     * 사이트 호스트를 떼어 경로만 보여 준다.
     *
     * @scenario display=strip_site_host
     *
     * @effects url_prefix_is_kept_so_the_original_address_can_be_restored
     *
     * @return void 없음
     */
    public function test_strips_site_host_for_display(): void
    {
        $this->assertSame('/board/free', UrlNormalizer::stripSiteHost('example.com/board/free', 'example.com'));
        $this->assertSame('/', UrlNormalizer::stripSiteHost('example.com', 'example.com'));
        $this->assertSame('other.com/x', UrlNormalizer::stripSiteHost('other.com/x', 'example.com'));
    }

    /**
     * 화면용 이름은 퍼센트 인코딩을 풀되, 풀어서 글자가 아니면 그대로 둔다.
     *
     * 한국어 주소·검색어가 「/search?q=%ED%85%8C…」로 보이면 운영자가 읽을 수 없다(브라우저 실측 §8 M3).
     * 깨진 바이트나 제어 문자는 풀면 표가 깨지거나 JSON 이 실패하므로 원문을 둔다.
     *
     * @scenario display=decode_for_display
     *
     * @effects display_names_decode_only_readable_text
     *
     * @return void 없음
     */
    public function test_decodes_readable_text_for_display(): void
    {
        $this->assertSame('/search?q=테스트', UrlNormalizer::forDisplay('example.com/search?q=%ED%85%8C%EC%8A%A4%ED%8A%B8', 'example.com'));
        $this->assertSame('/게시판/free', UrlNormalizer::forDisplay('example.com/%EA%B2%8C%EC%8B%9C%ED%8C%90/free', 'example.com'));
        $this->assertSame('other.com/한', UrlNormalizer::forDisplay('other.com/%ED%95%9C', 'example.com'), '다른 호스트는 호스트를 남긴 채 푼다');
        $this->assertSame('/x?q=%FF', UrlNormalizer::forDisplay('example.com/x?q=%FF', 'example.com'), '깨진 바이트는 풀지 않는다');
        $this->assertSame('/x?q=a%0Ab', UrlNormalizer::forDisplay('example.com/x?q=a%0Ab', 'example.com'), '제어 문자는 풀지 않는다');
        $this->assertSame('/a+b', UrlNormalizer::forDisplay('example.com/a+b', 'example.com'), '더하기는 공백으로 바꾸지 않는다');
    }

    /**
     * 상대 경로도 처리한다.
     *
     * @scenario url=relative
     *
     * @effects url_normalization_collapses_scheme_www_and_tracking_params
     *
     * @return void 없음
     */
    public function test_handles_relative_paths(): void
    {
        $result = UrlNormalizer::normalize('/board/free?b=2&a=1#x');

        $this->assertSame('/board/free?a=1&b=2', $result['name']);
        $this->assertNull($result['url_prefix']);
    }

    /**
     * 사전 해시가 종류와 이름 조합으로 안정적이다.
     *
     * 해시가 바뀌면 같은 URL 이 새 사전 행으로 들어가 과거 집계와 끊긴다.
     *
     * @scenario hash=stable|type_separated
     *
     * @effects action_name_dictionary_is_reused_for_repeated_urls
     *
     * @return void 없음
     */
    public function test_action_name_hash_is_stable_and_type_scoped(): void
    {
        $this->assertSame(
            ActionNameHasher::hash('url', '/board/free'),
            ActionNameHasher::hash('url', '/board/free'),
        );

        $this->assertNotSame(
            ActionNameHasher::hash('url', '/board/free'),
            ActionNameHasher::hash('title', '/board/free'),
        );

        $this->assertSame(32, strlen(ActionNameHasher::hash('url', '/board/free')));
    }

    /**
     * 스킴과 호스트는 소문자로 접고 경로의 대소문자는 지킨다.
     *
     * 호스트 표기가 다르다고 같은 페이지가 둘로 갈리면 인기 페이지가 나뉜다. 반대로
     * 경로까지 소문자로 접으면 대소문자를 구분하는 서버의 서로 다른 페이지가 합쳐진다.
     *
     * @scenario prefix=https_www, case=mixed
     *
     * @effects url_normalization_collapses_scheme_www_and_tracking_params, action_name_dictionary_is_reused_for_repeated_urls
     *
     * @return void 없음
     */
    public function test_lowercases_scheme_and_host_but_keeps_path_case(): void
    {
        $mixed = UrlNormalizer::normalize('HTTPS://WWW.Example.COM/Board/Free?Page=2');
        $lower = UrlNormalizer::normalize('https://www.example.com/Board/Free?Page=2');

        $this->assertSame($lower['name'], $mixed['name']);
        $this->assertSame(UrlNormalizer::PREFIX_HTTPS_WWW, $mixed['url_prefix']);
        $this->assertStringStartsWith('example.com/Board/Free', $mixed['name']);
        $this->assertSame(
            ActionNameHasher::hash('url', $lower['name']),
            ActionNameHasher::hash('url', $mixed['name']),
            '표기만 다른 같은 페이지는 같은 사전 항목이어야 한다',
        );
    }
}
