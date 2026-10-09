<?php

namespace Tests\Unit\Seo;

use App\Seo\SeoRenderRequest;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * SEO 렌더 내부 호출 표식 테스트
 *
 * SEO 렌더러가 자기 자신에게 보내는 데이터 소스 호출을 받는 쪽이 알아볼 수 있게 붙이는 헤더다.
 * 값은 앱 키로 서명해 바깥에서 흉내 낼 수 없어야 한다(7.1.0 실측 O-K3-1).
 */
class SeoRenderRequestTest extends TestCase
{
    /**
     * 서명이 맞는 헤더를 단 요청만 내부 렌더 호출로 판정한다.
     *
     * @scenario viewer=seo_render
     *
     * @return void 반환값 없음
     */
    public function test_matches_only_request_with_valid_signature(): void
    {
        $valid = Request::create('/api/x', 'GET', server: ['HTTP_'.strtoupper(str_replace('-', '_', SeoRenderRequest::HEADER)) => SeoRenderRequest::signature()]);
        $this->assertTrue(SeoRenderRequest::matches($valid));

        $this->assertFalse(SeoRenderRequest::matches(Request::create('/api/x')));
    }

    /**
     * 서명이 틀린 헤더는 내부 렌더 호출이 아니다.
     *
     * @scenario viewer=forged_header
     *
     * @return void 반환값 없음
     */
    public function test_forged_signature_does_not_match(): void
    {
        $forged = Request::create('/api/x');
        $forged->headers->set(SeoRenderRequest::HEADER, 'forged');

        $this->assertFalse(SeoRenderRequest::matches($forged));
    }

    /**
     * 서명은 앱 키에 묶인다 — 키가 다른 사이트의 값은 통하지 않는다.
     *
     * @scenario viewer=seo_render
     *
     * @effects seo_render_signature_bound_to_app_key
     *
     * @return void 반환값 없음
     */
    public function test_signature_depends_on_app_key(): void
    {
        $original = SeoRenderRequest::signature();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

        $this->assertNotSame($original, SeoRenderRequest::signature());
        $this->assertSame([SeoRenderRequest::HEADER => SeoRenderRequest::signature()], SeoRenderRequest::headers());
    }
}
