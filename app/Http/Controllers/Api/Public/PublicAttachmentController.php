<?php

namespace App\Http\Controllers\Api\Public;

use App\Helpers\InlineMediaPolicy;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Http\Requests\Public\Attachment\DownloadAttachmentRequest;
use App\Services\AttachmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
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
     * 래스터 이미지(jpeg/png/gif/webp 등)는 캐싱 헤더와 함께 인라인 표시하고,
     * 그 외 파일(SVG 포함)은 다운로드 방식으로 제공합니다. 모든 응답에 nosniff 를 붙입니다.
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

            // 래스터 이미지는 캐싱 헤더와 함께 인라인 응답 (환경설정 레이아웃 캐시 TTL 사용, 기본 24시간)
            // 행 disk 를 따르는 스토리지 스트림 — 로컬 경로 전제 fileResponse 는 S3 행에서 성립하지 않는다 (#99)
            // `image/` 접두사로 판정하면 image/svg+xml 이 인라인으로 열려 앱 오리진에서 스크립트가 돈다 (KVE-2026-2366).
            if (InlineMediaPolicy::isInlineImage($fileInfo['mime_type'])) {
                $response = $this->streamedFileResponse(
                    $fileInfo['response'],
                    $fileInfo['etag_source'],
                    (int) g7_core_settings('cache.layout_ttl', 86400)
                );

                return InlineMediaPolicy::harden($response, $fileInfo['mime_type']);
            }

            // 래스터 이미지가 아닌 파일(SVG 포함)은 다운로드 방식
            $response = $this->attachmentService->download($hash, $user);

            if (! $response) {
                return $this->forbidden('attachment.access_denied');
            }

            return InlineMediaPolicy::harden($response, $fileInfo['mime_type'], $fileInfo['filename'] ?? null);
        } catch (AuthorizationException) {
            return $this->forbidden('attachment.access_denied');
        }
    }
}
