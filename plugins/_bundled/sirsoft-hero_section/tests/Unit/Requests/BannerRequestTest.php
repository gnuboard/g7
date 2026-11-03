<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Unit\Requests;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Plugins\Sirsoft\HeroSection\Http\Requests\Admin\StoreBannerRequest;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;
use Plugins\Sirsoft\HeroSection\Rules\SafeLinkUrl;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 배너 등록·수정 검증 규칙 테스트 (매니페스트 admin-banners.yaml — 검증 케이스)
 *
 * 등록·수정은 같은 규칙(BannerFieldRules)을 쓰므로 등록 규칙으로 확인한다. 링크 규칙은 SafeLinkUrl 단위로도 본다.
 */
class BannerRequestTest extends PluginTestCase
{
    /**
     * 기본 언어(사이트 기본) 제목이 없으면 실패한다.
     *
     * @scenario surface=store_requires_default_locale_title
     *
     * @return void 반환값 없음
     */
    public function test_requires_default_locale_title(): void
    {
        $this->assertFails(['title' => ['en' => 'Only English']], 'title');
        $this->assertFails(['title' => ['ko' => '']], 'title');
        $this->assertPasses(['title' => ['ko' => '제목']]);
    }

    /**
     * 지원하지 않는 언어 키는 실패한다.
     *
     * @scenario surface=store_rejects_inactive_locale_key
     *
     * @return void 반환값 없음
     */
    public function test_rejects_inactive_locale_key(): void
    {
        $this->assertFails(['title' => ['ko' => '제목', 'xx' => 'nope']], 'title');
    }

    /**
     * 기간 지정이면 시작·종료가 필요하고, 상시면 기간을 보지 않는다.
     *
     * @scenario surface=store_period_range_requires_dates
     *
     * @return void 반환값 없음
     */
    public function test_range_requires_dates(): void
    {
        $this->assertFails(['display_period' => 'range'], 'starts_at');
        $this->assertFails(['display_period' => 'range', 'starts_at' => '2026-12-01T09:00'], 'ends_at');
        $this->assertPasses(['display_period' => 'always', 'starts_at' => 'not-a-date']);
    }

    /**
     * 종료는 시작보다 뒤여야 한다.
     *
     * @scenario surface=store_period_end_after_start
     *
     * @return void 반환값 없음
     */
    public function test_end_must_be_after_start(): void
    {
        $this->assertFails(['display_period' => 'range', 'starts_at' => '2026-12-01T09:00', 'ends_at' => '2026-12-01T09:00'], 'ends_at');
        $this->assertPasses(['display_period' => 'range', 'starts_at' => '2026-12-01T09:00', 'ends_at' => '2026-12-01T09:01']);
    }

    /**
     * 색상은 #RRGGBB 만 받는다.
     *
     * @scenario surface=store_color_format
     *
     * @return void 반환값 없음
     */
    public function test_color_format(): void
    {
        foreach (['red', '#FFF', '#GGGGGG', 'FFFFFF'] as $bad) {
            $this->assertFails(['title_color' => $bad], 'title_color');
        }
        $this->assertPasses(['title_color' => '#a1B2c3', 'description_color' => '#000000']);
    }

    /**
     * 정렬은 left·center·right 만 받는다.
     *
     * @scenario surface=store_align_enum
     *
     * @return void 반환값 없음
     */
    public function test_align_enum(): void
    {
        $this->assertFails(['title_align' => 'justify'], 'title_align');
        $this->assertPasses(['title_align' => 'center', 'description_align' => 'right']);
    }

    /**
     * 사이트 안 경로는 받는다.
     *
     * @scenario surface=link_url_accepts_internal_path
     *
     * @return void 반환값 없음
     */
    public function test_link_accepts_internal_path(): void
    {
        foreach (['/', '/shop', '/shop/products?sort=new#top'] as $url) {
            $this->assertTrue(SafeLinkUrl::isSafe($url), $url);
        }
        $this->assertPasses(['link_url' => '/shop']);
    }

    /**
     * http(s) 절대 주소는 받는다.
     *
     * @scenario surface=link_url_accepts_http_absolute
     *
     * @return void 반환값 없음
     */
    public function test_link_accepts_http_absolute(): void
    {
        foreach (['https://example.com', 'http://example.com/a?b=1', 'HTTPS://EXAMPLE.COM/x'] as $url) {
            $this->assertTrue(SafeLinkUrl::isSafe($url), $url);
        }
        $this->assertPasses(['link_url' => 'https://example.com/event']);
    }

    /**
     * javascript:·data:·vbscript: 등 다른 스킴과 공백·제어문자는 거부한다.
     *
     * @scenario surface=link_url_rejects_script_scheme
     *
     * @return void 반환값 없음
     */
    public function test_link_rejects_script_scheme(): void
    {
        foreach (['javascript:alert(1)', 'JavaScript:alert(1)', 'data:text/html,x', 'vbscript:x', 'ftp://example.com', "java\tscript:alert(1)", 'example.com'] as $url) {
            $this->assertFalse(SafeLinkUrl::isSafe($url), $url);
        }
        $this->assertFails(['link_url' => 'javascript:alert(1)'], 'link_url');
    }

    /**
     * //로 시작하는 프로토콜 상대 주소는 / 로 시작해도 거부한다.
     *
     * @scenario surface=link_url_rejects_protocol_relative
     *
     * @return void 반환값 없음
     */
    public function test_link_rejects_protocol_relative(): void
    {
        $this->assertFalse(SafeLinkUrl::isSafe('//evil.example.com'));
        $this->assertFails(['link_url' => '//evil.example.com/path'], 'link_url');
    }

    /**
     * 브라우저가 // 로 읽는 /\ 와 \\ 시작도 거부한다.
     *
     * @scenario surface=link_url_rejects_backslash_variant
     *
     * @return void 반환값 없음
     */
    public function test_link_rejects_backslash_variant(): void
    {
        foreach (['/\\evil.example.com', '\\\\evil.example.com', '\\evil'] as $url) {
            $this->assertFalse(SafeLinkUrl::isSafe($url), $url);
        }
        $this->assertFails(['link_url' => '/\\evil.example.com'], 'link_url');
    }

    /**
     * 기본값에 덮어쓴 값으로 등록 규칙을 돌립니다.
     *
     * @param  array<string, mixed>  $overrides  바꿀 값
     * @return \Illuminate\Validation\Validator 검증기
     */
    private function validate(array $overrides): \Illuminate\Validation\Validator
    {
        $tempKey = (string) Str::uuid();
        BannerImage::factory()->pc()->temp($tempKey)->create();

        $request = new StoreBannerRequest;
        $data = array_replace($this->bannerPayload(), ['temp_key' => $tempKey], $overrides);

        return Validator::make($data, $request->rules(), [], $request->attributes());
    }

    /**
     * 검증이 그 필드에서 실패하는지 확인합니다.
     *
     * @param  array<string, mixed>  $overrides  바꿀 값
     * @param  string  $field  실패해야 하는 필드
     * @return void 반환값 없음
     */
    private function assertFails(array $overrides, string $field): void
    {
        $validator = $this->validate($overrides);
        $this->assertTrue($validator->fails(), json_encode($overrides, JSON_UNESCAPED_UNICODE).' 는 실패해야 한다');
        $this->assertArrayHasKey($field, $validator->errors()->toArray(), json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE));
    }

    /**
     * 검증이 통과하는지 확인합니다.
     *
     * @param  array<string, mixed>  $overrides  바꿀 값
     * @return void 반환값 없음
     */
    private function assertPasses(array $overrides): void
    {
        $validator = $this->validate($overrides);
        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE));
    }
}
