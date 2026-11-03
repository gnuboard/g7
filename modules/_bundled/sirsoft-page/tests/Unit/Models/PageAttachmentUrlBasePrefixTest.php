<?php

namespace Modules\Sirsoft\Page\Tests\Unit\Models;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 페이지 첨부의 미리보기·다운로드 주소가 하위 폴더 설치에서 마운트 접두사를 갖는지 고정한다.
 *
 * 세 주소(다운로드, 미리보기, 한시 서명 미리보기)는 `route(..., absolute: false)` 와
 * `temporarySignedRoute(..., absolute: false)` 로 만든다. 상대 URL 생성은 요청의 base URL
 * (마운트 접두사)을 벗기므로(`RouteUrlGenerator::to()`), 접두사를 다시 붙이지 않으면 `<img src>`
 * 가 사이트 밖(`/api/…`)을 가리킨다. 서명은 base 를 뺀 경로에 걸리고 검증
 * (`hasValidSignature(absolute: false)`)도 base 를 뺀 `Request::path()` 로 하므로, 서명된 값 밖에
 * 붙인 접두사는 서명을 깨지 않는다.
 *
 * @scenario base_path=subpath, url_kind=page_attachment
 */
class PageAttachmentUrlBasePrefixTest extends ModuleTestCase
{
    /**
     * 주소 생성이 이름으로 라우트를 찾으므로 모듈 라우트를 등록한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerModuleRoutes();
    }

    /**
     * 하위 폴더 요청에서 세 주소가 `/g7/` 로 시작하고, 서명 주소로 들어온 요청의 서명이 유효한지 확인한다.
     *
     * @scenario base_path=subpath, url_kind=page_attachment
     *
     * @effects signed_attachment_urls_carry_the_mount_prefix_and_verify
     *
     * @return void 반환값 없음
     */
    public function test_attachment_urls_carry_the_mount_prefix_and_signed_url_still_verifies(): void
    {
        $original = app('request');
        $mounted = $this->mountedRequest('/g7/admin/pages/1');
        app()->instance('request', $mounted);
        URL::setRequest($mounted);

        try {
            $attachment = new PageAttachment(['mime_type' => 'image/png']);
            $attachment->hash = 'abcdef123456';

            $this->assertSame('/g7/api/modules/sirsoft-page/pages/attachment/abcdef123456', $attachment->downloadUrl());
            $this->assertSame('/g7/api/modules/sirsoft-page/pages/attachment/abcdef123456/preview', $attachment->previewUrl());

            $signed = (string) $attachment->previewUrl(signed: true);

            $this->assertStringStartsWith('/g7/api/modules/sirsoft-page/pages/attachment/abcdef123456/preview?', $signed);
            $this->assertStringContainsString('signature=', $signed);
            $this->assertTrue(URL::hasValidSignature($this->mountedRequest($signed), false));
        } finally {
            app()->instance('request', $original);
            URL::setRequest($original);
        }
    }

    /**
     * 루트 설치에서는 종전 문자열 그대로인지 확인한다 (회귀 0).
     *
     * @scenario base_path=root, url_kind=page_attachment
     *
     * @effects signed_attachment_urls_carry_the_mount_prefix_and_verify
     *
     * @return void 반환값 없음
     */
    public function test_attachment_urls_are_unchanged_on_a_root_install(): void
    {
        $attachment = new PageAttachment(['mime_type' => 'image/png']);
        $attachment->hash = 'abcdef123456';

        $this->assertSame('/api/modules/sirsoft-page/pages/attachment/abcdef123456', $attachment->downloadUrl());
        $this->assertSame('/api/modules/sirsoft-page/pages/attachment/abcdef123456/preview', $attachment->previewUrl());
    }

    /**
     * 하위 폴더(`/g7`) 설치의 웹 요청을 만든다 — 진입점 위임 정규화 뒤의 `SCRIPT_NAME` 과 같은 형태다.
     *
     * @param  string  $uri  브라우저 주소 (`/g7/…`)
     * @return Request 요청
     */
    private function mountedRequest(string $uri): Request
    {
        return Request::create('http://localhost'.$uri, 'GET', [], [], [], [
            'SCRIPT_FILENAME' => base_path('public/index.php'),
            'SCRIPT_NAME' => '/g7/index.php',
            'PHP_SELF' => '/g7/index.php',
        ]);
    }
}
