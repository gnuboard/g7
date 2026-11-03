<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

use App\Support\AssetUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 게시판 첨부 미리보기 URL 조립 단일 지점(previewUrlForSlug) 회귀 테스트
 *
 * URL 중복 조립 정리(#100) 후에도 응답 문자열이 기존과 동일함을 고정합니다.
 * board 는 직접 URL 배선 대상이 아니므로 문자열 회귀 0 이 계약입니다.
 */
class AttachmentPreviewUrlTest extends ModuleTestCase
{
    /**
     * previewUrlForSlug 가 슬러그와 해시로 기존과 같은 미리보기 경로 문자열을 만드는지 확인한다.
     *
     * @effects board_response_strings_unchanged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function preview_url_for_slug_builds_board_preview_url(): void
    {
        $attachment = new Attachment;
        $attachment->hash = 'abc123def456';

        $this->assertSame(
            '/api/modules/sirsoft-board/boards/notice/attachment/abc123def456/preview',
            $attachment->previewUrlForSlug('notice')
        );
    }

    /**
     * board 관계가 없을 때 preview_url 접근자가 빈 슬러그로 같은 조립 지점을 거쳐 기존 문자열을 유지하는지 확인한다.
     *
     * @effects board_response_strings_unchanged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function preview_url_accessor_delegates_to_single_assembly_point(): void
    {
        $attachment = new Attachment;
        $attachment->hash = 'abc123def456';
        $attachment->mime_type = 'image/png';
        $attachment->board_id = 0;

        // board 관계 미로딩 + 존재하지 않는 board_id → slug 는 빈 문자열로 조립 (기존 동작 보존)
        $this->assertSame(
            '/api/modules/sirsoft-board/boards//attachment/abc123def456/preview',
            $attachment->preview_url
        );
    }

    /**
     * 강제로 넣은 마운트 접두사를 걷어 다음 시험에 새지 않게 한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        AssetUrl::forceBasePrefix(null);

        parent::tearDown();
    }

    /**
     * 하위 폴더 마운트에서 두 URL 이 **마운트 접두사**를 갖는다.
     *
     * 코어 형제(`App\Models\Attachment::getUrlAttribute`)는 이 접두사를 이미 붙이고,
     * 그 자리의 주석이 「형제 자산 통로는 전부 `AssetUrl` 을 거치는데 첨부·아바타만
     * 리터럴 조립이라 그 배치에서 전부 404 였다」고 적는다. 게시판 첨부는 그 정정에서
     * 빠져 있었다 — 소비 지점이 `Img.src` 라 라우터를 거치지 않으므로 접두사를 붙일
     * 주체가 이 조립 지점밖에 없고, 증상은 오류 없이 **썸네일이 전부 깨지는 것**이다.
     *
     * @scenario base_path=subpath, url_kind=board_attachment
     *
     * @effects board_attachment_urls_carry_the_mount_prefix
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function attachment_urls_carry_the_mount_prefix(): void
    {
        AssetUrl::forceBasePrefix('/g7');

        $attachment = new Attachment;
        $attachment->hash = 'abc123def456';
        $attachment->mime_type = 'image/png';
        $attachment->board_id = 0;

        $this->assertSame(
            '/g7/api/modules/sirsoft-board/boards/notice/attachment/abc123def456/preview',
            $attachment->previewUrlForSlug('notice')
        );

        $this->assertStringStartsWith(
            '/g7/api/modules/sirsoft-board/',
            $attachment->download_url
        );
    }

    /**
     * 서명 분기도 마운트 접두사를 갖고, 그 주소로 들어온 요청의 서명이 유효하다.
     *
     * 종전에는 「접두사를 실은 요청은 서명이 깨진다」는 측정을 근거로 접두사 없는 형태를 고정했다.
     * 그 측정은 base URL 이 빈 요청(`Request::create('/g7/api/…')`)으로 잰 것이라 하위 폴더 설치의
     * 실제 요청과 다르다. 하위 폴더 설치에서는 진입점의 위임 정규화(`public/index.php`)가 `SCRIPT_NAME`
     * 을 `/g7/index.php` 로 맞추므로 요청의 base URL 이 `/g7` 이고, Laravel 은 두 쪽 모두에서 base 를
     * 뺀다. 생성(`RouteUrlGenerator::to()`, absolute=false)은 상대 URL 에서 base 를 벗긴 값에 서명하고,
     * 검증(`UrlGenerator::hasCorrectSignature()`, absolute=false)은 `'/'.$request->path()` 로 다시
     * 조립하는데 `Request::path()` 는 base 를 뺀 path info 다. 그래서 접두사는 서명된 값 **밖**에서
     * 붙이고, 붙여도 서명은 성립한다. 붙이지 않으면 `<img src>` 가 사이트 밖(`/api/…`)을 가리킨다.
     *
     * 이 시험은 그 두 사실을 하위 폴더 요청 형태(SCRIPT_NAME `/g7/index.php`)로 함께 잰다.
     *
     * @scenario base_path=subpath, url_kind=board_attachment
     *
     * @effects signed_attachment_urls_carry_the_mount_prefix_and_verify
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function signed_preview_url_carries_the_mount_prefix_and_still_verifies(): void
    {
        $original = app('request');
        $this->bindMountedRequest('/g7/board/notice/1');

        try {
            $attachment = new Attachment;
            $attachment->hash = 'abc123def456';
            $attachment->mime_type = 'image/png';
            $attachment->board_id = 0;

            $signed = $attachment->previewUrlForSlug('notice', signed: true);

            $this->assertStringStartsWith('/g7/api/modules/sirsoft-board/boards/notice/attachment/abc123def456/preview?', $signed);
            $this->assertStringContainsString('signature=', $signed);

            // 그 주소 그대로 들어온 요청(base `/g7`)의 서명이 유효해야 한다.
            $this->assertTrue(URL::hasValidSignature($this->mountedRequest($signed), false));

            // 무서명 형제와 같은 앞부분을 갖는다.
            $this->assertStringStartsWith('/g7/api/', $attachment->previewUrlForSlug('notice'));
        } finally {
            app()->instance('request', $original);
            URL::setRequest($original);
        }
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

    /**
     * 하위 폴더 요청을 현재 요청으로 묶는다 — URL 생성기와 `AssetUrl::basePrefix()` 가 함께 이 값을 본다.
     *
     * @param  string  $uri  브라우저 주소 (`/g7/…`)
     * @return void 반환값 없음
     */
    private function bindMountedRequest(string $uri): void
    {
        $request = $this->mountedRequest($uri);
        app()->instance('request', $request);
        URL::setRequest($request);
    }
}
