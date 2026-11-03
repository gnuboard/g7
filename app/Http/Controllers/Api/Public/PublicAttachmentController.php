<?php

namespace App\Http\Controllers\Api\Public;

use App\Helpers\InlineMediaPolicy;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Http\Requests\Public\Attachment\DownloadAttachmentRequest;
use App\Http\Responses\FileServingMeta;
use App\Services\AttachmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     */
    public function __construct(
        private AttachmentService $attachmentService
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
     * @param  DownloadAttachmentRequest  $request  다운로드 요청
     * @param  string  $hash  첨부파일 해시 (12자)
     * @return BinaryFileResponse|StreamedResponse|Response|JsonResponse 파일 응답 또는 에러 응답
     */
    public function download(DownloadAttachmentRequest $request, string $hash): BinaryFileResponse|StreamedResponse|Response|JsonResponse
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
            if (InlineMediaPolicy::isInlineImage($fileInfo['mime_type'])) {
                $response = $this->streamedFileResponseFromMeta(
                    $fileInfo,
                    (int) g7_core_settings('cache.layout_ttl', 86400),
                    immutable: false,
                    skipCompression: true,
                    logContext: ['hash' => $hash]
                );

                return $response instanceof JsonResponse ? $response : InlineMediaPolicy::harden($response, $fileInfo['mime_type']);
            }

            // 래스터 이미지가 아닌 파일(SVG 포함)은 다운로드 방식(attachment) — 단, 캐시 계약은 이미지와 같다.
            // 액션 훅(IDV 가드)·다운로드 카운트 필터는 이 경로가 발화한다(종전 `download()` 와 같다).
            $downloadInfo = $this->attachmentService->getDownloadInfo($hash, $user);

            if (! $downloadInfo) {
                return $this->forbidden('attachment.access_denied');
            }

            $response = $this->streamedFileResponseFromMeta(
                $downloadInfo,
                (int) g7_core_settings('cache.layout_ttl', 86400),
                immutable: false,
                skipCompression: true,
                logContext: ['hash' => $hash]
            );

            // 메타 누락 500(JSON)은 그대로 — 파일 응답에만 다운로드 강제·nosniff 를 붙인다
            return $response instanceof JsonResponse
                ? $response
                : InlineMediaPolicy::harden($response, $downloadInfo['mime_type'], $downloadInfo['filename'] ?? null);
        } catch (AuthorizationException) {
            return $this->forbidden('attachment.access_denied');
        }
    }
}
