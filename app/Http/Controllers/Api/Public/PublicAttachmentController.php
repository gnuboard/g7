<?php

namespace App\Http\Controllers\Api\Public;

use App\Helpers\InlineMediaPolicy;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Http\Requests\Public\Attachment\DownloadAttachmentRequest;
use App\Http\Responses\FileServingMeta;
use App\Services\AttachmentService;
use App\Thumbnails\ThumbnailResponder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * 첨부파일 다운로드 컨트롤러
 *
 * 권한 정책에 따라 로그인/비로그인 사용자 모두 접근 가능합니다.
 * 권한 체크는 AttachmentService에서 하이브리드 방식으로 처리합니다.
 */
class PublicAttachmentController extends PublicBaseController
{
    /**
     * PublicAttachmentController 생성자
     *
     * @param  AttachmentService  $attachmentService  첨부파일 서비스
     * @param  ThumbnailResponder  $thumbnails  썸네일 응답기 (size·w 요청을 원본 응답보다 먼저 받는다)
     */
    public function __construct(
        private AttachmentService $attachmentService,
        private ThumbnailResponder $thumbnails
    ) {
        parent::__construct();
    }

    /**
     * 첨부파일 다운로드
     *
     * 이미지 파일은 인라인 표시하고 그 외 파일은 다운로드 방식(`Content-Disposition:
     * attachment`)으로 제공합니다. **두 통로가 같은 캐시 계약**을 갖는다 — 검증자(ETag ·
     * Last-Modified)·조건부 요청 판정·304 헤더 동반은 코어 단일 지점
     * (`streamedFileResponse()`)이 소유한다. 종전에는 비이미지 다운로드가 그 지점을 지나지
     * 않아 검증자가 하나도 없었고, 같은 첨부가 유형에 따라 재검증 가능 여부가 갈렸다
     * (14차 G3 라운드).
     *
     * 인라인은 래스터 이미지(jpeg/png/gif/webp 등)만이고 그 외 파일(SVG 포함)은 다운로드 방식이다.
     * 파일 응답에는 모두 nosniff 를 붙인다 (KVE-2026-2366).
     *
     * `?size={규격}`·`?w={숫자}` 가 붙으면 래스터 이미지는 권한 검사(`getFileInfo()`)를 지난 뒤 썸네일로 낸다.
     * 코어 첨부는 게이트 원본이라 썸네일도 비공개 디스크·`private` 캐시다. 낼 수 없으면 원본에
     * `X-G7-Thumbnail: bypass:{사유}` 를 붙인다.
     *
     * 원본은 권한 훅이나 켜진 본인인증 정책(hook 범위 `core.attachment.before_download_action`, route 범위
     * `api.attachment.download`)이 걸린 사이트에서만 `private` 캐시로 낸다. 둘 다 없으면 누구나 받는 공개 첨부라 종전처럼
     * `public` 이다. hook 범위 정책의 액션 훅과 필터 훅 `core.attachment.before_download` 는 `getFileInfo()` 가 이미지·비이미지
     * 모두 한 요청에 한 번씩 돌리므로 이미지·썸네일도 같은 정책을 받는다. 비이미지는 `getFileInfo()` 가 다운로드 응답을 돌려준다.
     *
     * @param  DownloadAttachmentRequest  $request  다운로드 요청
     * @param  string  $hash  첨부파일 해시 (12자)
     * @return SymfonyResponse|JsonResponse 파일·썸네일 응답(200·304) 또는 에러 응답
     */
    public function download(DownloadAttachmentRequest $request, string $hash): SymfonyResponse|JsonResponse
    {
        $user = $request->user();

        try {
            // 파일 정보 조회 (권한 체크 포함)
            $fileInfo = $this->attachmentService->getFileInfo($hash, $user);

            if (! $fileInfo) {
                $attachment = $this->attachmentService->findByHash($hash);

                if (! $attachment) {
                    return $this->notFound('attachment.not_found');
                }

                return $this->forbidden('attachment.access_denied');
            }

            // 서빙 메타 키 검증을 mime_type 선읽기보다 **앞**에 둔다. 아래 이미지 판정이 헬퍼보다
            // 먼저 `$fileInfo['mime_type']` 을 읽으므로, 서비스가 그 키를 빠뜨리면 헬퍼의 명시 실패
            // 통로(FileServingMeta SSoT)에 닿기 전에 배열 접근 경고가 무인증 표면의 500 이 된다.
            // 서비스는 확장에 열려 있어 「지금 구현이 채운다」는 방어가 아니다 (17차 G3 라운드 B-4).
            $missing = FileServingMeta::missingKey($fileInfo);

            if ($missing !== null) {
                Log::error('파일 서빙 메타 누락', ['hash' => $hash, 'missing' => $missing]);

                return $this->error('errors.500.message', 500);
            }

            // 이미지 파일은 캐싱 헤더와 함께 인라인 응답 (환경설정 레이아웃 캐시 TTL 사용, 기본 24시간)
            // 행 disk 를 따르는 스토리지 스트림 — 로컬 경로 전제 fileResponse 는 S3 행에서 성립하지 않는다 (#99)
            //
            // 서빙 메타 키의 부재는 **조용히 폴백하지 않는다** — 그 판정은 코어 헬퍼
            // (`streamedFileResponseFromMeta()`)가 소유한다. 종전에는 이 컨트롤러가 `array_key_exists`
            // 가드 사본을 들고 있었고 형제 통로 대부분에는 그 가드가 없었다 (11차 B11-5 → 16차 E-6).
            // `immutable` 은 `false` 고정 — 이 URL 에는 `?v=` 가 없다(코어 파일 서빙 계약).
            // 압축 제외를 **선언**한다 — 사용자 업로드물은 크기·건수가 유계가 아니라 압축 경로의
            // 전체 적재가 동시 요청 수만큼 증폭된다(형제 `PublicStorageController` 와 같은 선언).
            // `image/` 접두사로 판정하면 image/svg+xml 이 인라인으로 열려 앱 오리진에서 스크립트가 돈다 (KVE-2026-2366).
            // 갈래는 서비스가 필터를 지난 행으로 정한 `inline` 을 따른다 — 여기서 MIME 으로 다시 고르면 필터 구독자가 MIME 을
            // 바꿀 때 서비스가 만든 응답(내려받기·인라인)과 다른 갈래를 탄다. 그 키가 없는 서비스(확장 대체)만 MIME 으로 고른다.
            if ((bool) ($fileInfo['inline'] ?? InlineMediaPolicy::isInlineImage($fileInfo['mime_type']))) {
                // 게이트(권한 훅·before_download 필터)를 지난 뒤, 원본 응답 바로 앞
                if ($thumbnail = $this->thumbnails->respond($request, $fileInfo['thumbnail_source'] ?? null)) {
                    return $thumbnail;
                }

                $response = $this->streamedFileResponseFromMeta(
                    $fileInfo,
                    (int) g7_core_settings('cache.layout_ttl', 86400),
                    immutable: false,
                    skipCompression: true,
                    logContext: ['hash' => $hash],
                    private: (bool) ($fileInfo['private'] ?? false)
                );

                return $response instanceof JsonResponse
                    ? $response
                    : ThumbnailResponder::markBypass(InlineMediaPolicy::harden($response, $fileInfo['mime_type']), $request);
            }

            // 래스터가 아닌 파일(SVG 등)에 썸네일을 요청했으면 사유(not-raster)만 남긴다 — 응답은 기존 다운로드 그대로.
            $this->thumbnails->respond($request, null);

            // 래스터 이미지가 아닌 파일(SVG 포함)은 다운로드 방식(attachment) — 단, 캐시 계약은 이미지와 같다.
            // getFileInfo() 가 이미 권한을 지난 행으로 다운로드 응답을 만들어 돌려준다(액션 훅·필터 훅 각 한 번). 종전에는 여기서
            // getDownloadInfo() 를 다시 불러 같은 행을 다시 찾고 권한 확인·공유 캐시 판정을 한 번씩 더 했다.
            $downloadInfo = $fileInfo;

            $response = $this->streamedFileResponseFromMeta(
                $downloadInfo,
                (int) g7_core_settings('cache.layout_ttl', 86400),
                immutable: false,
                skipCompression: true,
                logContext: ['hash' => $hash],
                private: (bool) ($downloadInfo['private'] ?? false)
            );

            // 메타 누락 500(JSON)은 그대로 — 파일 응답에만 다운로드 강제·nosniff 를 붙인다
            return $response instanceof JsonResponse
                ? $response
                : ThumbnailResponder::markBypass(
                    InlineMediaPolicy::harden($response, $downloadInfo['mime_type'], $downloadInfo['filename'] ?? null),
                    $request
                );
        } catch (AuthorizationException) {
            return $this->forbidden('attachment.access_denied');
        }
    }
}
