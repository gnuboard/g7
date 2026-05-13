<?php

namespace Modules\Sirsoft\Page\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Page\Http\Requests\ReorderPageAttachmentsRequest;
use Modules\Sirsoft\Page\Http\Requests\UploadPageAttachmentRequest;
use Modules\Sirsoft\Page\Http\Resources\PageAttachmentResource;
use Modules\Sirsoft\Page\Services\PageAttachmentService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 관리자 페이지 첨부파일 컨트롤러
 *
 * 페이지 첨부파일 업로드/삭제/다운로드/순서변경을 처리합니다.
 */
class PageAttachmentController extends AdminBaseController
{
    public function __construct(
        private PageAttachmentService $attachmentService,
    ) {
        parent::__construct();
    }

    /**
     * 첨부파일을 업로드합니다.
     *
     * @param  UploadPageAttachmentRequest  $request  업로드 요청
     * @return JsonResponse 업로드된 첨부파일 정보
     */
    public function upload(UploadPageAttachmentRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $attachment = $this->attachmentService->upload(
                file: $request->file('file'),
                pageId: $validated['page_id'] ?? null,
                collection: $validated['collection'] ?? 'attachments',
                tempKey: $validated['temp_key'] ?? null
            );

            // FileUploader 컴포넌트 형식: response.data?.data
            return $this->success(
                'sirsoft-page::messages.attachment.upload_success',
                [
                    'data' => new PageAttachmentResource($attachment),
                ],
                201
            );
        } catch (\Exception $e) {
            return $this->error('sirsoft-page::messages.attachment.upload_failed', 500, $e->getMessage());
        }
    }

    /**
     * 첨부파일을 삭제합니다.
     *
     * @param  int  $id  첨부파일 ID
     * @return JsonResponse 삭제 결과
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $attachment = $this->attachmentService->getById($id);

            $result = $this->attachmentService->deleteAttachment($attachment);
            if (! $result) {
                return $this->error('sirsoft-page::messages.attachment.delete_failed', 500);
            }

            return $this->success('sirsoft-page::messages.attachment.delete_success');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFound('sirsoft-page::messages.attachment.not_found');
        } catch (\Exception $e) {
            return $this->error('sirsoft-page::messages.attachment.delete_failed', 500, $e->getMessage());
        }
    }

    /**
     * 첨부파일 순서를 변경합니다.
     *
     * @param  ReorderPageAttachmentsRequest  $request  순서 변경 요청
     * @return JsonResponse 변경 결과
     */
    public function reorder(ReorderPageAttachmentsRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            // FileUploader가 [{id, order}] 형태로 전송 → [ID => order] 매핑으로 변환
            $orders = collect($validated['order'])->pluck('order', 'id')->all();

            $this->attachmentService->reorder($orders);

            return $this->success('sirsoft-page::messages.attachment.reorder_success');
        } catch (\Exception $e) {
            return $this->error('sirsoft-page::messages.attachment.reorder_failed', 500, $e->getMessage());
        }
    }

    /**
     * 첨부파일을 다운로드합니다 (해시 기반).
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

        $response = $this->attachmentService->download($attachment);

        return $response ?: $this->error('sirsoft-page::messages.attachment.file_not_found', 404);
    }

    /**
     * 이미지 첨부파일을 미리봅니다 (해시 기반, inline).
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

        $response = $this->attachmentService->preview($attachment);

        return $response ?: $this->error('sirsoft-page::messages.attachment.file_not_found', 404);
    }
}
