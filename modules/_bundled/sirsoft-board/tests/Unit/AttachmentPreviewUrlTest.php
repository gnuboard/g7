<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

use App\Support\AssetUrl;
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
     * 서명 분기는 접두사를 **품지 않는다** — 그리고 그 사실이 계약이다.
     *
     * 8차 라운드의 하위 검증이 「접두사를 붙여도 서명은 성립한다(검증이 `path()` 를 쓰는데
     * 그 값은 base URL 을 제외하므로)」며 형제와 같이 접두사를 붙일 것을 제안했다.
     * **실측이 그 주장을 기각한다** — 이 저장소에서 재현 가능한 모든 형태에서 접두사가
     * 실린 요청은 서명이 깨진다:
     *
     *   signed   = /api/modules/…/preview?expires=…&signature=…
     *   plain    path=api/modules/…/preview      valid=true
     *   prefixed path=g7/api/modules/…/preview   valid=false
     *
     * 접두사가 벗겨지는지는 Laravel 의 base URL 판정에 달렸고, 그것은 웹서버가 넘기는
     * `SCRIPT_NAME`·`PATH_INFO` 가 정한다 — **이 저장소에서는 재현할 수 없는 축**이다.
     * 그래서 고치지 않고 측정 순서를 남긴다: 하위 폴더로 마운트한 P0 환경에서 ①
     * 비밀글 첨부의 서명 썸네일이 200 인가 ② 그때 `Request::path()` 가 접두사를 품는가.
     * ②가 거짓이면 그때 형제와 같이 접두사를 붙인다.
     *
     * 그때까지 이 검사는 **현행 계약을 고정한다** — 서명 분기의 산출물에는 접두사가 없다.
     * 무서명 분기(형제)는 접두사를 갖는다는 것을 위 검사가 함께 잠근다.
     *
     * @scenario base_path=subpath, url_kind=board_attachment
     *
     * @effects board_attachment_urls_carry_the_mount_prefix
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function signed_preview_url_stays_free_of_the_mount_prefix(): void
    {
        AssetUrl::forceBasePrefix('/g7');

        $attachment = new Attachment;
        $attachment->hash = 'abc123def456';
        $attachment->mime_type = 'image/png';
        $attachment->board_id = 0;

        $signed = $attachment->previewUrlForSlug('notice', signed: true);

        $this->assertStringStartsWith(
            '/api/modules/sirsoft-board/',
            $signed,
            '서명 URL 이 접두사를 품었다 — 실측상 그 형태는 서명 검증에 실패한다. '
            .'바꾸려면 먼저 하위 폴더 마운트에서 Request::path() 가 접두사를 벗는지 재야 한다.'
        );

        $this->assertStringContainsString('signature=', $signed);

        // 형제(무서명)는 반대다 — 두 계약을 한자리에서 함께 고정한다.
        $this->assertStringStartsWith('/g7/api/', $attachment->previewUrlForSlug('notice'));
    }
}
