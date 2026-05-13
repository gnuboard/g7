<?php

namespace Modules\Sirsoft\Page\Http\Controllers\User;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Page\Services\PageAttachmentService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 공개 페이지 첨부파일 컨트롤러
 *
 * 발행된 페이지의 첨부파일 다운로드를 처리합니다.
 */
class PublicPageAttachmentController extends PublicBaseController
{
    public function __construct(
        private PageAttachmentService $attachmentService,
    ) {}

    /**
     * 첨부파일을 다운로드합니다 (해시 기반).
     *
     * 발행된 페이지의 첨부파일만 다운로드 가능합니다.
     *
     * @param  string  $hash  첨부파일 해시 (12자)
     * @return StreamedResponse|JsonResponse 파일 스트리밍 응답 또는 오류
     */
    public function download(string $hash): StreamedResponse|JsonResponse
    {
        $attachment = $this->attachmentService->getByHash($hash);
        if (! $attachment) {
            return $this->notFound('sirsoft-page::messages.attachment.not_found');
        }

        // 발행된 페이지의 첨부파일만 다운로드 가능
        if (! $attachment->page || ! $attachment->page->published) {
            return $this->notFound('sirsoft-page::messages.attachment.not_found');
        }

        $response = $this->attachmentService->download($attachment);

        return $response ?: $this->error('sirsoft-page::messages.attachment.file_not_found', 404);
    }

    /**
     * 이미지 첨부파일을 미리봅니다 (해시 기반, inline).
     *
     * 발행된 페이지의 이미지 첨부파일만 미리보기 가능합니다.
     *
     * @param  string  $hash  첨부파일 해시 (12자)
     * @return StreamedResponse|JsonResponse 파일 스트리밍 응답 또는 오류
     */
    public function preview(string $hash): StreamedResponse|JsonResponse
    {
        $attachment = $this->attachmentService->getByHash($hash);
        if (! $attachment) {
            return $this->notFound('sirsoft-page::messages.attachment.not_found');
        }

        // 발행된 페이지의 첨부파일만 미리보기 가능
        if (! $attachment->page || ! $attachment->page->published) {
            return $this->notFound('sirsoft-page::messages.attachment.not_found');
        }

        $response = $this->attachmentService->preview($attachment);

        return $response ?: $this->error('sirsoft-page::messages.attachment.file_not_found', 404);
    }
}
