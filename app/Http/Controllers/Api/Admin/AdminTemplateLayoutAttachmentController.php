<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\Template\ListTemplateLayoutAttachmentsRequest;
use App\Http\Requests\Admin\Template\UploadTemplateLayoutAttachmentRequest;
use App\Models\TemplateLayoutAttachment;
use App\Services\TemplateLayoutAttachmentService;
use Illuminate\Http\JsonResponse;

/**
 * 템플릿 레이아웃 첨부 파일 어드민 컨트롤러
 *
 * 레이아웃 편집 중 업로드되는 파일(배경 이미지 등)의 업로드·조회·삭제를 제공한다.
 * ImagePickerControl(11.2.2)이 본 API 를 호출한다. 권한은 라우트의 permission
 * 미들웨어(core.templates.layouts.edit)가 담당한다.
 */
class AdminTemplateLayoutAttachmentController extends AdminBaseController
{
    public function __construct(
        private TemplateLayoutAttachmentService $service,
    ) {
        parent::__construct();
    }

    /**
     * 첨부 파일 목록 조회 — 그 템플릿의 첨부(이미지 재선택용).
     *
     * @param  ListTemplateLayoutAttachmentsRequest  $request  검증된 요청 (layout_name 쿼리 선택)
     * @param  string  $identifier  템플릿 식별자
     * @return JsonResponse 첨부 목록 응답
     */
    public function index(ListTemplateLayoutAttachmentsRequest $request, string $identifier): JsonResponse
    {
        $layoutName = $request->validated('layout_name');
        $result = $this->service->list($identifier, is_string($layoutName) ? $layoutName : null);

        if (! $result['success']) {
            return $this->notFound(__('templates.errors.not_found', ['template' => $identifier]));
        }

        $items = $result['attachments']->map(fn (TemplateLayoutAttachment $a) => [
            'id' => $a->id,
            'layout_name' => $a->layout_name,
            'original_name' => $a->original_name,
            'mime_type' => $a->mime_type,
            'size' => $a->size,
            'url' => $this->service->resolveUrl($a),
            'created_at' => $a->created_at?->toIso8601String(),
        ])->all();

        return $this->success(__('templates.layout_attachments.messages.listed'), $items);
    }

    /**
     * 첨부 파일 업로드 → 스토리지 저장 + 행 생성 → 접근 URL 반환.
     *
     * @param  UploadTemplateLayoutAttachmentRequest  $request  검증된 요청
     * @param  string  $identifier  템플릿 식별자
     * @return JsonResponse 업로드 결과 응답
     */
    public function store(UploadTemplateLayoutAttachmentRequest $request, string $identifier): JsonResponse
    {
        $result = $this->service->upload(
            $identifier,
            $request->file('file'),
            $request->input('layout_name'),
        );

        if (! $result['success']) {
            return match ($result['error']) {
                'template_not_found' => $this->notFound(__('templates.errors.not_found', ['template' => $identifier])),
                default => $this->error(__('templates.layout_attachments.errors.upload_failed'), 500),
            };
        }

        $attachment = $result['attachment'];

        return $this->success(__('templates.layout_attachments.messages.uploaded'), [
            'id' => $attachment->id,
            'layout_name' => $attachment->layout_name,
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->size,
            'url' => $result['url'],
        ]);
    }

    /**
     * 첨부 파일 삭제 — 스토리지 파일 실삭제 + DB 행 삭제.
     *
     * @param  TemplateLayoutAttachment  $attachment  라우트 모델 바인딩된 첨부
     * @return JsonResponse 삭제 결과 응답
     */
    public function destroy(TemplateLayoutAttachment $attachment): JsonResponse
    {
        $deleted = $this->service->delete($attachment);

        if (! $deleted) {
            return $this->error(__('templates.layout_attachments.errors.delete_failed'), 500);
        }

        return $this->success(__('templates.layout_attachments.messages.deleted'), null);
    }
}
