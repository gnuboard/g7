<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AttachmentSourceType;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Attachment\ReorderAttachmentsRequest;
use App\Http\Requests\Attachment\UploadAttachmentRequest;
use App\Http\Requests\Attachment\UploadBatchAttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Services\AttachmentService;
use Exception;
use Illuminate\Http\JsonResponse;

/**
 * 관리자용 첨부파일 컨트롤러
 */
class AttachmentController extends AdminBaseController
{
    /**
     * AttachmentController 생성자
     *
     * @param AttachmentService $attachmentService 첨부파일 서비스
     */
    public function __construct(
        private AttachmentService $attachmentService
    ) {
        parent::__construct();
    }

    /**
     * 단일 파일 업로드
     *
     * @param UploadAttachmentRequest $request 업로드 요청
     * @return JsonResponse
     */
    public function upload(UploadAttachmentRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $attachment = $this->attachmentService->upload(
                file: $request->file('file'),
                attachmentableType: $validated['attachmentable_type'] ?? null,
                attachmentableId: $validated['attachmentable_id'] ?? null,
                collection: $validated['collection'] ?? 'default',
                sourceType: isset($validated['source_type'])
                    ? AttachmentSourceType::from($validated['source_type'])
                    : AttachmentSourceType::Core,
                sourceIdentifier: $validated['source_identifier'] ?? null,
            );

            return $this->successWithResource(
                'attachment.upload_success',
                new AttachmentResource($attachment),
                201
            );
        } catch (Exception $e) {
            return $this->error('attachment.upload_failed', 500, $e->getMessage());
        }
    }

    /**
     * 여러 파일 일괄 업로드
     *
     * @param UploadBatchAttachmentRequest $request 일괄 업로드 요청
     * @return JsonResponse
     */
    public function uploadBatch(UploadBatchAttachmentRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $attachments = $this->attachmentService->uploadBatch(
                files: $request->file('files'),
                attachmentableType: $validated['attachmentable_type'] ?? null,
                attachmentableId: $validated['attachmentable_id'] ?? null,
                collection: $validated['collection'] ?? 'default',
                sourceType: isset($validated['source_type'])
                    ? AttachmentSourceType::from($validated['source_type'])
                    : AttachmentSourceType::Core,
                sourceIdentifier: $validated['source_identifier'] ?? null,
            );

            return $this->successWithResource(
                'attachment.upload_batch_success',
                AttachmentResource::collection($attachments),
                201
            );
        } catch (Exception $e) {
            return $this->error('attachment.upload_failed', 500, $e->getMessage());
        }
    }

    /**
     * 첨부파일 삭제
     *
     * @param  Attachment  $attachment  첨부파일 (라우트 모델 바인딩)
     * @return JsonResponse
     */
    public function destroy(Attachment $attachment): JsonResponse
    {
        try {
            $result = $this->attachmentService->delete($attachment->id);

            if (! $result) {
                return $this->error('attachment.delete_failed');
            }

            return $this->success('attachment.delete_success');
        } catch (Exception $e) {
            return $this->error('attachment.delete_failed', 500, $e->getMessage());
        }
    }

    /**
     * 순서 변경
     *
     * @param ReorderAttachmentsRequest $request 순서 변경 요청
     * @return JsonResponse
     */
    public function reorder(ReorderAttachmentsRequest $request): JsonResponse
    {
        try {
            $this->attachmentService->reorder($request->input('order'));

            return $this->success('attachment.reorder_success');
        } catch (Exception $e) {
            return $this->error('attachment.reorder_failed', 500, $e->getMessage());
        }
    }

}
