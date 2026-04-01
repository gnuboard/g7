<?php

namespace Modules\Sirsoft\Board\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Board\Exceptions\BoardNotFoundException;
use Modules\Sirsoft\Board\Http\Requests\ReorderAttachmentsRequest;
use Modules\Sirsoft\Board\Http\Requests\UploadAttachmentRequest;
use Modules\Sirsoft\Board\Services\AttachmentService;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 관리자용 게시판 첨부파일 컨트롤러
 *
 * 게시판별 동적 테이블을 사용하는 첨부파일 업로드, 삭제, 순서 변경 등을 처리합니다.
 */
class AttachmentController extends AdminBaseController
{
    use ChecksBoardPermission;

    /**
     * AttachmentController 생성자
     *
     * @param  AttachmentService  $attachmentService  첨부파일 서비스
     * @param  BoardService  $boardService  게시판 서비스
     */
    public function __construct(
        private AttachmentService $attachmentService,
        private BoardService $boardService
    ) {
        parent::__construct();
    }

    /**
     * 단일 파일 업로드
     *
     * @param  UploadAttachmentRequest  $request  업로드 요청
     * @param  string  $slug  게시판 슬러그
     */
    public function upload(UploadAttachmentRequest $request, string $slug): JsonResponse
    {
        try {
            // 게시판 존재 여부 확인
            $this->boardService->getBoardBySlug($slug);

            $validated = $request->validated();

            // post_id가 없으면 임시 업로드 (temp_key 필수)
            $postId = $validated['post_id'] ?? null;
            $tempKey = $validated['temp_key'] ?? null;

            $attachment = $this->attachmentService->upload(
                slug: $slug,
                file: $request->file('file'),
                postId: $postId,
                collection: $validated['collection'] ?? 'attachments',
                tempKey: $tempKey
            );

            // FileUploader 컴포넌트가 response.data?.data 형식을 기대하므로
            // data 키 안에 한 번 더 감싸서 반환
            return $this->success(
                'sirsoft-board::messages.attachment.upload_success',
                [
                    'data' => [
                        'id' => $attachment->id,
                        'hash' => $attachment->hash,
                        'original_filename' => $attachment->original_filename,
                        'stored_filename' => $attachment->stored_filename,
                        'mime_type' => $attachment->mime_type,
                        'size' => $attachment->size,
                        'url' => $this->attachmentService->getUrl($slug, $attachment->id),
                        'order' => $attachment->order,
                        'created_at' => $attachment->created_at,
                    ],
                ],
                201
            );
        } catch (BoardNotFoundException $e) {
            return $this->error('sirsoft-board::messages.board.not_found', 404);
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.attachment.upload_failed', 500, $e->getMessage());
        }
    }

    /**
     * 첨부파일 삭제
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  첨부파일 ID
     */
    public function destroy(string $slug, int $id): JsonResponse
    {
        try {
            // 게시판 존재 여부 확인
            $this->boardService->getBoardBySlug($slug);

            // 첨부파일 조회 (Service를 통해)
            $attachment = $this->attachmentService->getById($slug, $id);

            if (! $attachment) {
                return $this->error('sirsoft-board::messages.attachment.not_found', 404);
            }

            // 삭제 (Service에서 처리)
            $result = $this->attachmentService->delete($slug, $id);

            if (! $result) {
                return $this->error('sirsoft-board::messages.attachment.delete_failed', 500);
            }

            return $this->success('sirsoft-board::messages.attachment.delete_success');
        } catch (BoardNotFoundException $e) {
            return $this->error('sirsoft-board::messages.board.not_found', 404);
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.attachment.delete_failed', 500, $e->getMessage());
        }
    }

    /**
     * 첨부파일 순서 변경
     *
     * @param  ReorderAttachmentsRequest  $request  순서 변경 요청
     * @param  string  $slug  게시판 슬러그
     */
    public function reorder(ReorderAttachmentsRequest $request, string $slug): JsonResponse
    {
        try {
            // 게시판 존재 여부 확인
            $this->boardService->getBoardBySlug($slug);

            $validated = $request->validated();

            // FileUploader가 [{id, order}] 형태로 전송 → [ID => order] 매핑으로 변환
            $orders = collect($validated['order'])->pluck('order', 'id')->all();
            $result = $this->attachmentService->reorder($slug, $orders);

            if (! $result) {
                return $this->error('sirsoft-board::messages.attachment.reorder_failed', 500);
            }

            return $this->success('sirsoft-board::messages.attachment.reorder_success');
        } catch (BoardNotFoundException $e) {
            return $this->error('sirsoft-board::messages.board.not_found', 404);
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.attachment.reorder_failed', 500, $e->getMessage());
        }
    }

    /**
     * 첨부파일 다운로드 (해시 기반)
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $hash  첨부파일 해시
     */
    public function download(string $slug, string $hash): StreamedResponse|JsonResponse
    {
        try {
            // 게시판 존재 여부 확인
            $this->boardService->getBoardBySlug($slug);

            // 해시로 첨부파일 조회
            $attachment = $this->attachmentService->getByHash($slug, $hash);

            if (! $attachment) {
                return $this->error('sirsoft-board::messages.attachment.not_found', 404);
            }

            // 다운로드 응답 생성
            $response = $this->attachmentService->download($slug, $attachment->id);

            if (! $response) {
                return $this->error('sirsoft-board::messages.attachment.file_not_found', 404);
            }

            return $response;
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (BoardNotFoundException $e) {
            return $this->error('sirsoft-board::messages.board.not_found', 404);
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.attachment.download_failed', 500, $e->getMessage());
        }
    }
}
