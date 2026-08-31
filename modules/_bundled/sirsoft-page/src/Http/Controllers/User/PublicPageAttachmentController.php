<?php

namespace Modules\Sirsoft\Page\Http\Controllers\User;

use App\Enums\PermissionType;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Sirsoft\Page\Services\PageAttachmentService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 공개 페이지 첨부파일 컨트롤러
 *
 * 첨부파일 서빙(다운로드/미리보기)을 처리합니다. 썸네일 <img>·다운로드는 브라우저 직접
 * GET 이라 토큰을 실을 수 없으므로 공개 hash 라우트로 단일화합니다.
 * - 미리보기(썸네일): 발행 첨부는 누구나, 미발행 첨부는 pages.read 관리자만 (download 와 동일 게이트)
 * - 다운로드: 발행 첨부는 누구나, 미발행 첨부는 pages.read 관리자만 (파일 보호)
 *
 * preview 와 download 는 동일한 발행상태 게이트를 공유한다 — 한쪽만 열려 있으면
 * 미발행 콘텐츠가 무인가로 새는 경로가 남는다(preview↔download 정합).
 */
class PublicPageAttachmentController extends PublicBaseController
{
    public function __construct(
        private PageAttachmentService $attachmentService,
    ) {}

    /**
     * 첨부파일을 다운로드합니다 (해시 기반).
     *
     * 발행된 페이지의 첨부는 누구나, 미발행 페이지의 첨부는 페이지 조회 권한
     * (sirsoft-page.pages.read) 관리자만 다운로드할 수 있습니다.
     *
     * @param  Request  $request  HTTP 요청 객체 (다운로드 권한 판정용)
     * @param  string  $hash  첨부파일 해시 (12자)
     * @return StreamedResponse|Response|JsonResponse 파일 스트리밍 응답, 304, 또는 오류
     */
    // audit:allow controller-base-request-injection reason: GET 파일 서빙. 인증 사용자만 read-only 참조($request->user())해 미발행 첨부 다운로드 권한을 판정. 검증할 body 없음(hash 는 라우트 파라미터)
    public function download(Request $request, string $hash): StreamedResponse|Response|JsonResponse
    {
        $attachment = $this->attachmentService->getByHash($hash);
        if (! $attachment) {
            return $this->notFound('sirsoft-page::messages.attachment.not_found');
        }

        // 미발행 페이지의 첨부는 페이지 조회 권한 관리자만 다운로드 가능
        $published = $attachment->page && $attachment->page->published;
        $canReadUnpublished = $request->user()?->hasPermission(
            'sirsoft-page.pages.read',
            PermissionType::Admin
        ) ?? false;

        if (! $published && ! $canReadUnpublished) {
            return $this->notFound('sirsoft-page::messages.attachment.not_found');
        }

        $downloadInfo = $this->attachmentService->getDownloadInfo($attachment);

        if (! $downloadInfo) {
            return $this->error('sirsoft-page::messages.attachment.file_not_found', 404);
        }

        // 다운로드도 코어 단일 지점을 지난다 — 종전에는 스토리지 스트림을 그대로 돌려줘 이
        // 통로만 ETag·304·`nosniff`·압축 제외 선언이 전부 빠졌다(미리보기는 지났다). 코어
        // 첨부(`PublicAttachmentController`)와 같은 형태이며 `Content-Disposition: attachment`
        // 는 헬퍼가 건드리지 않아 그대로 승계된다 (15차 G3 라운드).
        // 키 검증도 그 지점(`streamedFileResponseFromMeta()`)이 한다 (16차 G3 라운드 E-6).
        return $this->streamedFileResponseFromMeta(
            $downloadInfo,
            (int) g7_core_settings('cache.layout_ttl', 86400),
            immutable: false,
            skipCompression: true,
            logContext: ['hash' => $hash],
            errorMessageKey: 'sirsoft-page::messages.attachment.file_not_found'
        );
    }

    /**
     * 이미지 첨부파일을 미리봅니다 (해시 기반, inline).
     *
     * 발행된 페이지의 썸네일은 누구나, 미발행 페이지의 썸네일은 페이지 조회 권한
     * (sirsoft-page.pages.read) 관리자만 미리볼 수 있습니다(download 와 동일 게이트).
     * 편집 중인 초안(미발행)의 <img> 썸네일은 편집 권한을 가진 관리자가 보므로
     * 정상 노출되며, 무인가 사용자에게만 미발행 썸네일이 차단됩니다.
     *
     * @param  Request  $request  HTTP 요청 객체 (미리보기 권한 판정용)
     * @param  string  $hash  첨부파일 해시 (12자)
     * @return StreamedResponse|Response|JsonResponse 파일 스트리밍 응답, 304, 또는 오류
     */
    // audit:allow controller-base-request-injection reason: GET 이미지 서빙. 인증 사용자만 read-only 참조($request->user())해 미발행 첨부 미리보기 권한을 판정. 검증할 body 없음(hash 는 라우트 파라미터)
    public function preview(Request $request, string $hash): StreamedResponse|Response|JsonResponse
    {
        $attachment = $this->attachmentService->getByHash($hash);
        if (! $attachment) {
            return $this->notFound('sirsoft-page::messages.attachment.not_found');
        }

        // 미발행 페이지의 첨부는 페이지 조회 권한 관리자만 미리보기 가능 (download 와 동일 게이트).
        // 브라우저 <img> 는 Authorization 헤더를 실을 수 없으므로, 게이트를 통과한 응답
        // (관리자 상세 직렬화)이 발급한 한시 서명 URL 도 동등한 자격으로 허용한다 —
        // 서명은 발급 시점에 게이트를 통과한 요청에만 실리므로 무서명 게이트는 약화되지 않는다.
        $published = $attachment->page && $attachment->page->published;
        $canReadUnpublished = $request->user()?->hasPermission(
            'sirsoft-page.pages.read',
            PermissionType::Admin
        ) ?? false;
        $hasValidSignature = $request->hasValidSignature(absolute: false);

        if (! $published && ! $canReadUnpublished && ! $hasValidSignature) {
            return $this->notFound('sirsoft-page::messages.attachment.not_found');
        }

        $response = $this->attachmentService->preview($attachment);

        if (! $response) {
            return $this->error('sirsoft-page::messages.attachment.file_not_found', 404);
        }

        // 내보낸 검증자를 **실제로 판정**한다. 서비스는 `ETag`·`Last-Modified` 를 싣는데
        // 종전에는 그것을 돌려보낸 `If-None-Match` / `If-Modified-Since` 를 아무도 보지 않아
        // 재검증이 **영원히 전문 200** 이었다 — 검증자를 싣지 않던 시절과 대역폭이 같다.
        // 본문 `<img>` 로 실려 건수가 유계가 아닌 통로라 그 손해가 페이지 조회마다 누적된다.
        //
        // 판정은 코어 단일 지점(`streamedFileResponse()`)이 소유한다 — `-gzip` 접미사·
        // 다중값·`W/` 약한 검증자 흡수, 304 에 200 의 `Cache-Control`/`Expires`/
        // `Last-Modified` 동반, 라우터가 벗기는 `Last-Modified` 복원까지 그 한 곳이 한다.
        // 형제 게시판(`sirsoft-board` AttachmentController)과 같은 형태다 (13차 G3 라운드).
        //
        // `immutable` 은 `false` 고정 — 이 URL 에는 `?v=` 가 없고 같은 주소의 내용이
        // 교체될 수 있다(코어 파일 서빙 계약).
        //
        // 사용자가 올린 파일이라 크기·건수가 유계가 아니다 — 압축 제외를 응답 클래스에
        // 기대지 않고 **선언**한다(공개 스토리지 통로와 같은 형태, 14차 G3 라운드).
        $validators = $this->attachmentService->previewValidators($attachment);

        // 키 검증도 그 지점(`streamedFileResponseFromMeta()`)이 한다 (16차 G3 라운드 E-6).
        return $this->streamedFileResponseFromMeta(
            [
                ...$validators,
                'response' => $response,
                // MIME 은 행 컬럼이 SSoT — 응답 헤더 폴백에 기대지 않는다(형제 통로와 같은 형태, 15차 G3 라운드).
                // 명시 키(response·mime_type)를 스프레드 **뒤**에 두어 서비스 검증자와 키가 겹쳐도
                // 행 메타가 이긴다(PublicTemplateController 와 같은 형태, 17차 G3 라운드 B-17).
                'mime_type' => (string) $attachment->mime_type,
            ],
            PageAttachmentService::PREVIEW_CACHE_MAX_AGE,
            immutable: false,
            skipCompression: true,
            logContext: ['hash' => $hash],
            errorMessageKey: 'sirsoft-page::messages.attachment.file_not_found'
        );
    }
}
