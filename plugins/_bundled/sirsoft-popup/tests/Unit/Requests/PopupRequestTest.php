<?php

namespace Plugins\Sirsoft\Popup\Tests\Unit\Requests;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Popup\Http\Requests\Admin\StorePopupRequest;
use Plugins\Sirsoft\Popup\Models\PopupImage;
use Plugins\Sirsoft\Popup\Rules\SafeLinkUrl;
use Plugins\Sirsoft\Popup\Support\RoutePath;
use Plugins\Sirsoft\Popup\Tests\PluginTestCase;

/**
 * 팝업 등록·수정 검증 규칙 테스트 (매니페스트 admin-popups.yaml — 검증 케이스)
 *
 * 등록·수정은 같은 규칙(PopupFieldRules)을 쓰므로 등록 규칙으로 확인한다. 링크 규칙은 SafeLinkUrl 단위로도 본다.
 */
class PopupRequestTest extends PluginTestCase
{
    /**
     * 관리용 제목이 없거나 100자를 넘으면 실패한다.
     *
     * @scenario surface=store_requires_title
     *
     * @return void 반환값 없음
     */
    public function test_requires_title(): void
    {
        $this->assertFails(['title' => ''], 'title');
        $this->assertFails(['title' => str_repeat('가', 101)], 'title');
        $this->assertPasses(['title' => str_repeat('가', 100)]);
    }

    /**
     * 이미지형은 임시 묶음에 PC 이미지가 있어야 한다.
     *
     * @scenario surface=store_image_type_requires_pc_image
     *
     * @return void 반환값 없음
     */
    public function test_image_type_requires_pc_image(): void
    {
        $emptyKey = (string) Str::uuid();
        PopupImage::factory()->mobile()->temp($emptyKey)->create();

        $this->assertFails(['temp_key' => $emptyKey], 'temp_key');
        $this->assertPasses([]);
    }

    /**
     * 직접 작성형은 기본 언어 본문이 필요하고, 이미지 필드는 보지 않는다.
     *
     * @scenario surface=store_html_type_requires_default_locale_body
     *
     * @return void 반환값 없음
     */
    public function test_html_type_requires_default_locale_body(): void
    {
        $this->assertFails($this->htmlPopupPayload(['body_html' => ['en' => '<p>Only English</p>']]), 'body_html');
        $this->assertFails($this->htmlPopupPayload(['body_html' => []]), 'body_html');
        // 이미지형 필드가 엉터리여도 직접 작성형이면 보지 않는다(temp_key 없음·링크 오류)
        $this->assertPasses($this->htmlPopupPayload(['link_url' => 'javascript:alert(1)', 'temp_key' => null]));
    }

    /**
     * 지원하지 않는 언어 키는 실패한다(alt·본문 모두).
     *
     * @scenario surface=store_rejects_inactive_locale_key
     *
     * @return void 반환값 없음
     */
    public function test_rejects_inactive_locale_key(): void
    {
        $this->assertFails(['image_alt' => ['ko' => '대체', 'xx' => 'nope']], 'image_alt');
        $this->assertFails($this->htmlPopupPayload(['body_html' => ['ko' => '<p>본문</p>', 'xx' => '<p>nope</p>']]), 'body_html');
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
     * 라우트 지정이면 경로가 하나 이상 필요하고, 전체 페이지면 경로를 보지 않는다.
     *
     * @scenario surface=store_routes_required_when_scope_routes
     *
     * @return void 반환값 없음
     */
    public function test_routes_required_when_scope_routes(): void
    {
        $this->assertFails(['page_scope' => 'routes'], 'routes');
        $this->assertFails(['page_scope' => 'routes', 'routes' => []], 'routes');
        $this->assertPasses(['page_scope' => 'routes', 'routes' => ['/shop']]);
        $this->assertPasses(['page_scope' => 'all', 'routes' => ['not a path']]);
    }

    /**
     * 경로 형식 — `/` 로 시작하고 `//`·`..`·`?`·`#`·`*`·공백이 없어야 한다(중복도 실패).
     *
     * @scenario surface=store_routes_reject_invalid_format
     *
     * @return void 반환값 없음
     */
    public function test_routes_reject_invalid_format(): void
    {
        foreach (['shop', '//evil', '/shop//x', '/a/../b', '/shop?x=1', '/shop#a', '/shop/*', '/sh op'] as $bad) {
            $this->assertFails(['page_scope' => 'routes', 'routes' => [$bad]], 'routes.0');
        }
        $this->assertFails(['page_scope' => 'routes', 'routes' => ['/shop', '/shop']], 'routes.1');
        $this->assertPasses(['page_scope' => 'routes', 'routes' => ['/', '/shop', '/board/notice', '/page/회사소개', '/a-b_c.d~e', '/%EA%B0%80']]);
    }

    /**
     * 경로는 20개까지.
     *
     * @scenario surface=store_routes_max_count
     *
     * @return void 반환값 없음
     */
    public function test_routes_max_count(): void
    {
        $routes = array_map(fn (int $i) => '/p'.$i, range(1, RoutePath::MAX_COUNT));
        $this->assertPasses(['page_scope' => 'routes', 'routes' => $routes]);
        $this->assertFails(['page_scope' => 'routes', 'routes' => [...$routes, '/extra']], 'routes');
    }

    /**
     * 노출 대상·기기는 정해진 값만.
     *
     * @scenario surface=store_audience_and_device_enum
     *
     * @return void 반환값 없음
     */
    public function test_audience_and_device_enum(): void
    {
        $this->assertFails(['audience' => 'vip'], 'audience');
        $this->assertFails(['device' => 'tablet'], 'device');
        foreach (['all', 'guest', 'member'] as $audience) {
            $this->assertPasses(['audience' => $audience]);
        }
        foreach (['all', 'pc', 'mobile'] as $device) {
            $this->assertPasses(['device' => $device]);
        }
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
        $this->assertTrue(SafeLinkUrl::isSafe('/event/autumn?ref=popup#top'));
        $this->assertPasses(['link_url' => '/event/autumn']);
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
        $this->assertPasses(['link_url' => 'https://example.com/promo']);
        $this->assertPasses(['link_url' => 'http://example.com']);
    }

    /**
     * 다른 스킴(javascript:·data:·vbscript:)은 거부한다.
     *
     * @scenario surface=link_url_rejects_script_scheme
     *
     * @return void 반환값 없음
     */
    public function test_link_rejects_script_scheme(): void
    {
        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,x', 'vbscript:x', 'ftp://example.com'] as $bad) {
            $this->assertFails(['link_url' => $bad], 'link_url');
        }
    }

    /**
     * `//` 로 시작하는 프로토콜 상대 주소는 거부한다.
     *
     * @scenario surface=link_url_rejects_protocol_relative
     *
     * @return void 반환값 없음
     */
    public function test_link_rejects_protocol_relative(): void
    {
        $this->assertFails(['link_url' => '//evil.example.com'], 'link_url');
    }

    /**
     * 브라우저가 `//` 로 읽는 `/\`·`\\` 시작과 공백·제어문자는 거부한다.
     *
     * @scenario surface=link_url_rejects_backslash_variant
     *
     * @return void 반환값 없음
     */
    public function test_link_rejects_backslash_variant(): void
    {
        foreach (['/\\evil.example.com', '\\\\evil.example.com', "/ok\tjavascript:x", "/a\nb"] as $bad) {
            $this->assertFails(['link_url' => $bad], 'link_url');
        }
    }

    /**
     * 검증이 실패하고 그 필드에 오류가 있는지 확인합니다.
     *
     * @param  array<string, mixed>  $overrides  바꿀 값 (직접 작성형은 htmlPopupPayload() 전체를 넘긴다)
     * @param  string  $field  오류가 있어야 할 필드
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

    /**
     * 등록 규칙으로 검증기를 만듭니다 — 이미지형 기본 값에는 PC 이미지가 있는 임시 묶음을 붙입니다.
     *
     * @param  array<string, mixed>  $overrides  바꿀 값
     * @return \Illuminate\Validation\Validator 검증기
     */
    private function validate(array $overrides): \Illuminate\Validation\Validator
    {
        $tempKey = (string) Str::uuid();
        PopupImage::factory()->pc()->temp($tempKey)->create();

        $request = new StorePopupRequest;
        $data = array_replace($this->popupPayload(), ['temp_key' => $tempKey], $overrides);

        return Validator::make($data, $request->rules(), [], $request->attributes());
    }
}
