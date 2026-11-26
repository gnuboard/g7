<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Services\AttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 업로드한 파비콘 서빙 — 공개
 *
 * 일반 첨부 다운로드(`/api/attachment/{hash}`)는 ico·svg 를 다운로드(attachment)로 내보내고 캐시 헤더가 없어
 * 탭 아이콘으로 쓸 수 없다. 전역 인라인 정책은 넓히지 않고, 파비콘 컬렉션 첨부만 이 전용 경로로 내보낸다.
 *
 * - Content-Type 은 파비콘 MIME 목록으로 정규화(ico → image/x-icon), `X-Content-Type-Options: nosniff`
 * - svg 는 스크립트 실행을 막는 CSP 를 함께 단다(업로드 때 이미 검사했지만 서빙에서도 막는다)
 * - 교체하면 새 첨부 = 새 해시라 1년 immutable 캐시
 */
class FaviconController extends PublicBaseController
{
    /**
     * svg 파비콘 응답 CSP — 스크립트·외부 자원 차단.
     *
     * 일반 첨부의 `sandbox` 는 붙이지 않는다 — 탭 아이콘은 이미지 문맥이라 스크립트가 원래 돌지 않고, 주소창으로
     * 직접 열었을 때도 `default-src 'none'` 이 스크립트를 막는다(계획 리스크 표 「sandbox 붙은 svg 를 탭 아이콘으로 안 그림」 대응).
     */
    private const SVG_CSP = "default-src 'none'; style-src 'unsafe-inline'";

    /**
     * @param  AttachmentService  $attachmentService  첨부 서비스
     */
    public function __construct(
        private AttachmentService $attachmentService,
    ) {
        parent::__construct();
    }

    /**
     * 파비콘을 내보냅니다.
     *
     * @param  string  $hash  첨부 해시
     * @return StreamedResponse|Response|JsonResponse 파일 응답 (파비콘 컬렉션 첨부가 아니면 404)
     */
    public function show(string $hash): StreamedResponse|Response|JsonResponse
    {
        $fileInfo = $this->attachmentService->getFaviconFileInfo($hash);

        if ($fileInfo === null) {
            return $this->notFound('attachment.not_found');
        }

        $response = $this->streamedFileResponseFromMeta(
            $fileInfo,
            31536000,
            immutable: true,
            skipCompression: true,
            logContext: ['hash' => $hash]
        );

        if ($response instanceof JsonResponse) {
            return $response;
        }

        $response->headers->set('Content-Type', $fileInfo['mime_type']);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Disposition', 'inline');

        if ($fileInfo['mime_type'] === 'image/svg+xml') {
            $response->headers->set('Content-Security-Policy', self::SVG_CSP);
        }

        return $response;
    }
}
