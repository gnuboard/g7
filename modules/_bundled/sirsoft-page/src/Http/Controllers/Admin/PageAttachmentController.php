<?php

namespace Modules\Sirsoft\Page\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Page\Exceptions\AttachmentLimitExceededException;
use Modules\Sirsoft\Page\Http\Requests\ReorderPageAttachmentsRequest;
use Modules\Sirsoft\Page\Http\Requests\UploadPageAttachmentRequest;
use Modules\Sirsoft\Page\Http\Resources\PageAttachmentResource;
use Modules\Sirsoft\Page\Services\PageAttachmentService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 관리자 페이지 첨부파일 컨트롤러
 *
 * 페이지 첨부파일 업로드/삭제/순서변경을 처리합니다.
 * 파일 서빙(다운로드/미리보기)은 공개 hash 라우트(PublicPageAttachmentController)로 단일화됨.
 */
class PageAttachmentController extends AdminBaseController
{
    /**
     * @param  PageAttachmentService  $attachmentService  첨부 업로드·삭제·순서 서비스
     */
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
        } catch (AttachmentLimitExceededException $e) {
            // 첨부 개수 상한 초과 — generic 500 이 아닌 422 명시 차단
            return $this->error(
                $e->getMessageKey(),
                422,
                ['code' => 'attachment_limit_exceeded'],
                $e->getMessageParams()
            );
        } catch (AccessDeniedHttpException $e) {
            // 대상 페이지의 수정 권한·스코프 밖 — 서버 오류가 아니라 권한 없음으로 안내한다
            return $this->error('auth.scope_denied', 403);
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
        } catch (ModelNotFoundException $e) {
            return $this->notFound('sirsoft-page::messages.attachment.not_found');
        } catch (AccessDeniedHttpException) {
            return $this->error('auth.scope_denied', 403);
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
        } catch (ModelNotFoundException) {
            // 범위 검사가 대상 첨부를 하나씩 찾으므로, 없는 ID 는 500 이 아니라 404 로 끊는다(삭제와 같은 응답).
            return $this->notFound('sirsoft-page::messages.attachment.not_found');
        } catch (AccessDeniedHttpException) {
            return $this->error('auth.scope_denied', 403);
        } catch (\Exception $e) {
            return $this->error('sirsoft-page::messages.attachment.reorder_failed', 500, $e->getMessage());
        }
    }

    /**
     * 첨부 상한·허용 형식을 반환합니다. (화면 전용 경량 조회)
     *
     * 페이지 작성 화면은 **신규 작성 시 읽을 상세가 없어** 상한을 실어 줄 봉투가 없다.
     * 그래서 화면이 리터럴로 적고 있었고, 운영자가 설정을 바꾸면 화면만 옛 값으로 남아
     * 서버가 거절할 때까지 아무 안내도 없었다.
     *
     * 별도 FormRequest 를 두지 않는다 — 파라미터가 없고 본문도 없다.
     *
     * @return JsonResponse 공개 설정
     */
    public function settings(): JsonResponse
    {
        return $this->success(
            'sirsoft-page::messages.attachment.settings_success',
            $this->attachmentService->publicLimits()
        );
    }
}
