<?php

namespace Modules\Sirsoft\Page\Http\Controllers\User;

use App\Enums\PermissionType;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Thumbnails\ThumbnailQuery;
use App\Thumbnails\ThumbnailResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Sirsoft\Page\Services\PageAttachmentService;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
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
    /**
     * 컨트롤러를 생성합니다.
     *
     * @param  PageAttachmentService  $attachmentService  첨부파일 서비스
     * @param  ThumbnailResponder  $thumbnails  썸네일 헬퍼
     */
    public function __construct(
        private PageAttachmentService $attachmentService,
        private ThumbnailResponder $thumbnails,
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
        // 는 헬퍼가 건드리지 않아 그대로 승계된다.
        // 키 검증도 그 지점(`streamedFileResponseFromMeta()`)이 한다.
        return $this->streamedFileResponseFromMeta(
            $downloadInfo,
            (int) g7_core_settings('cache.layout_ttl', 86400),
            immutable: false,
            skipCompression: true,
            logContext: ['hash' => $hash],
            errorMessageKey: 'sirsoft-page::messages.attachment.file_not_found',
            // 미리보기와 같은 판정 — 미발행 첨부는 관리자만 받는 응답이라 공유 캐시에 남기지 않는다.
            private: ! $published
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
     * `?size={규격}`·`?w={숫자}` 가 붙으면 발행상태 게이트를 지난 뒤 썸네일로 낸다. 페이지 첨부는 게이트
     * 원본이라 썸네일은 비공개 디스크·`private` 캐시다. 낼 수 없으면 원본에 `X-G7-Thumbnail: bypass:{사유}`
     * 를 붙인다. 서명은 `size`·`w` 를 빼고 검증한다 — 관리자 화면이 서명 주소 뒤에 `size` 를 덧붙여도
     * 서명이 깨지지 않게(썸네일은 같은 게이트를 지난 뒤 크기만 바꾼다).
     *
     * 미발행 페이지 첨부의 원본은 `private` 캐시로 낸다 — 관리자·서명 요청만 볼 수 있는 응답이 공유
     * 캐시(CDN·프록시)에 남으면 다음 방문자가 게이트 없이 받는다.
     *
     * @param  Request  $request  HTTP 요청 객체 (미리보기 권한 판정·서명 검증·썸네일 파라미터용)
     * @param  string  $hash  첨부파일 해시 (12자)
     * @return SymfonyResponse|JsonResponse 파일·썸네일 스트리밍 응답(200·304) 또는 오류
     */
    // audit:allow controller-base-request-injection reason: GET 이미지 서빙. 인증 사용자만 read-only 참조($request->user())해 미발행 첨부 미리보기 권한을 판정하고, 서명 검증과 썸네일 쿼리(size·w)를 읽는다. 검증할 body 없음(hash 는 라우트 파라미터)
    public function preview(Request $request, string $hash): SymfonyResponse|JsonResponse
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
        // 서명은 썸네일 파라미터(size·w)를 빼고 검증한다 — 서명 주소에 size 를 덧붙인 <img> 가 404 가 되지 않게.
        $hasValidSignature = $request->hasValidRelativeSignatureWhileIgnoring(ThumbnailQuery::ignoreClosure());

        if (! $published && ! $canReadUnpublished && ! $hasValidSignature) {
            return $this->notFound('sirsoft-page::messages.attachment.not_found');
        }

        // 미리보기는 일반 이미지만 띄운다. SVG·TXT 등은 파일이 있어도 미리보기를 주지 않으므로
        // 「저장소에 없음」이 아니라 「미리보기 미지원 형식」으로 알린다. 상태코드는 종전 계약(404)을 지킨다.
        // 게이트 뒤에 두어 미발행 첨부의 형식이 무인가 요청에 드러나지 않게 한다.
        if (! $attachment->isImage()) {
            return $this->error('sirsoft-page::messages.attachment.preview_not_supported', 404);
        }

        // 게이트(발행상태·권한·서명)를 지난 뒤, 원본 응답 바로 앞.
        // 원본 정보는 썸네일을 요청했을 때만 만든다(무파라미터 요청은 헬퍼가 곧바로 null).
        // 그때는 원본이 저장소에 있는지부터 본다 — 원격 저장소에서 원본만 지워진 경우 남은 파생본으로 200 을 내면
        // 무파라미터 주소(아래 `preview()` 가 null → 404)와 갈린다.
        $source = null;

        if (ThumbnailQuery::requested($request)) {
            if (! $this->attachmentService->originalExists($attachment)) {
                return $this->error('sirsoft-page::messages.attachment.file_not_found', 404);
            }

            $source = $this->attachmentService->thumbnailSourceFor($attachment);
        }

        if ($thumbnail = $this->thumbnails->respond($request, $source)) {
            return $thumbnail;
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
        // 형제 게시판(`sirsoft-board` AttachmentController)과 같은 형태다.
        //
        // `immutable` 은 `false` 고정 — 이 URL 에는 `?v=` 가 없고 같은 주소의 내용이
        // 교체될 수 있다(코어 파일 서빙 계약).
        //
        // 사용자가 올린 파일이라 크기·건수가 유계가 아니다 — 압축 제외를 응답 클래스에
        // 기대지 않고 **선언**한다(공개 스토리지 통로와 같은 형태).
        $validators = $this->attachmentService->previewValidators($attachment);

        // 키 검증도 그 지점(`streamedFileResponseFromMeta()`)이 한다.
        //
        // 미발행 페이지의 첨부는 `private` 다 — 관리자·서명 요청만 통과한 응답이 공유 캐시(CDN·프록시)에
        // 남으면 다음 방문자가 게이트 없이 받는다. 발행 페이지는 종전처럼 public 이다.
        $response = $this->streamedFileResponseFromMeta(
            [
                ...$validators,
                'response' => $response,
                // MIME 은 행 컬럼이 SSoT — 응답 헤더 폴백에 기대지 않는다(형제 통로와 같은 형태).
                // 명시 키(response·mime_type)를 스프레드 **뒤**에 두어 서비스 검증자와 키가 겹쳐도
                // 행 메타가 이긴다(PublicTemplateController 와 같은 형태).
                'mime_type' => (string) $attachment->mime_type,
            ],
            PageAttachmentService::PREVIEW_CACHE_MAX_AGE,
            immutable: false,
            skipCompression: true,
            logContext: ['hash' => $hash],
            errorMessageKey: 'sirsoft-page::messages.attachment.file_not_found',
            private: ! $published
        );

        // 썸네일을 요청했는데 원본을 내면 사유를 붙인다(X-G7-Thumbnail: bypass:{사유}).
        return ThumbnailResponder::markBypass($response, $request);
    }
}
