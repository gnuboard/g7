<?php

namespace Modules\Sirsoft\Board\Http\Controllers\User;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Exceptions\BoardNotFoundException;
use Modules\Sirsoft\Board\Http\Requests\ReorderAttachmentsRequest;
use Modules\Sirsoft\Board\Http\Requests\UploadAttachmentRequest;
use Modules\Sirsoft\Board\Services\AttachmentService;
use Modules\Sirsoft\Board\Services\BoardService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 사용자용 첨부파일 컨트롤러
 *
 * 게시판 첨부파일 업로드, 다운로드, 삭제 기능을 제공합니다.
 * hash 기반 첨부파일 서빙 API도 제공합니다.
 */
class AttachmentController extends PublicBaseController
{
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
     * 첨부파일 다운로드 (서빙)
     *
     * 모든 파일(이미지 포함)을 다운로드 방식(Content-Disposition: attachment)으로 제공합니다.
     * 이미지 미리보기는 별도의 preview() 메서드를 사용합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $hash  첨부파일 해시 (12자)
     * @return StreamedResponse|JsonResponse 파일 응답 또는 에러 응답
     */
    public function download(string $slug, string $hash): StreamedResponse|JsonResponse
    {
        try {
            // 게시판 존재 여부 확인
            $this->boardService->getBoardBySlug($slug, checkScope: false);

            // 해시로 첨부파일 조회
            $attachment = $this->attachmentService->getByHash($slug, $hash);

            if (! $attachment) {
                return $this->notFound(__('sirsoft-board::messages.attachment.not_found'));
            }

            // 모든 파일(이미지 포함) 다운로드 방식으로 제공
            $response = $this->attachmentService->download($slug, $attachment->id, context: 'user');

            if (! $response) {
                return $this->notFound(__('sirsoft-board::messages.attachment.not_found'));
            }

            return $response;
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (BoardNotFoundException $e) {
            return $this->notFound(__('sirsoft-board::messages.boards.not_found'));
        } catch (\Exception $e) {
            return $this->error(__('sirsoft-board::messages.attachment.download_failed'), 500, $e->getMessage());
        }
    }

    /**
     * 이미지 미리보기 (권한 체크 없이, 캐싱 헤더 포함)
     *
     * 이미지 파일만 미리보기를 제공합니다.
     * 비회원도 이미지를 볼 수 있습니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $hash  첨부파일 해시 (12자)
     * @return BinaryFileResponse|Response|JsonResponse 이미지 응답 또는 에러 응답
     */
    public function preview(string $slug, string $hash): BinaryFileResponse|Response|JsonResponse
    {
        try {
            // 게시판 존재 여부 확인
            $this->boardService->getBoardBySlug($slug, checkScope: false);

            // 해시로 첨부파일 조회
            $attachment = $this->attachmentService->getByHash($slug, $hash);

            if (! $attachment) {
                return $this->notFound(__('sirsoft-board::messages.attachment.not_found'));
            }

            // 이미지가 아닌 경우
            if (! $attachment->is_image) {
                return $this->badRequest(__('sirsoft-board::messages.attachment.not_image'));
            }

            // 파일 정보 조회
            $fileInfo = $this->attachmentService->getFileInfo($slug, $attachment->id);

            if (! $fileInfo) {
                return $this->notFound(__('sirsoft-board::messages.attachment.not_found'));
            }

            // 캐싱 헤더와 함께 응답 (환경설정 레이아웃 캐시 TTL 사용, 기본 24시간)
            return $this->fileResponse(
                $fileInfo['path'],
                $fileInfo['mime_type'],
                (int) g7_core_settings('cache.layout_ttl', 86400)
            );
        } catch (BoardNotFoundException $e) {
            return $this->notFound(__('sirsoft-board::messages.boards.not_found'));
        } catch (\Exception $e) {
            return $this->error(__('sirsoft-board::messages.attachment.preview_failed'), 500, $e->getMessage());
        }
    }

    /**
     * 단일 파일 업로드
     *
     * @param  UploadAttachmentRequest  $request  업로드 요청
     * @param  string  $slug  게시판 슬러그
     * @return JsonResponse 업로드 결과 응답
     */
    public function upload(UploadAttachmentRequest $request, string $slug): JsonResponse
    {
        try {
            // 게시판 존재 여부 및 설정 확인
            $board = $this->boardService->getBoardBySlug($slug, checkScope: false);

            // 첨부파일 사용 설정 확인
            if (! $board->use_file_upload) {
                return $this->forbidden(__('sirsoft-board::messages.attachment.upload_disabled'));
            }

            $validated = $request->validated();

            // 첨부파일 업로드 (Service에서 처리)
            $attachment = $this->attachmentService->upload(
                slug: $slug,
                file: $request->file('file'),
                postId: $validated['post_id'] ?? null,
                collection: $validated['collection'] ?? 'attachments',
                tempKey: $validated['temp_key'] ?? null
            );

            // FileUploader 컴포넌트가 response.data?.data 형식을 기대하므로
            // data 키 안에 한 번 더 감싸서 반환
            return $this->success(
                __('sirsoft-board::messages.attachment.upload_success'),
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
            return $this->notFound(__('sirsoft-board::messages.board.not_found'));
        } catch (\Exception $e) {
            return $this->error(__('sirsoft-board::messages.attachment.upload_failed'), 500, $e->getMessage());
        }
    }

    /**
     * 첨부파일 순서 변경
     *
     * @param  ReorderAttachmentsRequest  $request  순서 변경 요청
     * @param  string  $slug  게시판 슬러그
     * @return JsonResponse 순서 변경 결과 응답
     */
    public function reorder(ReorderAttachmentsRequest $request, string $slug): JsonResponse
    {
        try {
            // 게시판 존재 여부 확인
            $this->boardService->getBoardBySlug($slug, checkScope: false);

            $validated = $request->validated();

            // FileUploader가 [{id, order}] 형태로 전송 → [ID => order] 매핑으로 변환
            $orders = collect($validated['order'])->pluck('order', 'id')->all();
            $result = $this->attachmentService->reorder($slug, $orders);

            if (! $result) {
                return $this->error(__('sirsoft-board::messages.attachment.reorder_failed'), 500);
            }

            return $this->success(__('sirsoft-board::messages.attachment.reorder_success'));
        } catch (BoardNotFoundException $e) {
            return $this->notFound(__('sirsoft-board::messages.board.not_found'));
        } catch (\Exception $e) {
            return $this->error(__('sirsoft-board::messages.attachment.reorder_failed'), 500, $e->getMessage());
        }
    }

    /**
     * 첨부파일 삭제
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  첨부파일 ID
     * @return JsonResponse 삭제 결과 응답
     */
    public function destroy(string $slug, int $id): JsonResponse
    {
        try {
            // 게시판 존재 여부 확인
            $this->boardService->getBoardBySlug($slug, checkScope: false);

            // 첨부파일 조회 (Service를 통해)
            $attachment = $this->attachmentService->getById($slug, $id);

            if (! $attachment) {
                return $this->notFound(__('sirsoft-board::messages.attachment.not_found'));
            }

            // 권한 확인 (Service에서 처리)
            if (! $this->attachmentService->canDelete($attachment, Auth::id())) {
                return $this->forbidden(__('sirsoft-board::messages.attachment.delete_forbidden'));
            }

            // 삭제 (Service에서 처리)
            $result = $this->attachmentService->delete($slug, $id);

            if (! $result) {
                return $this->error(__('sirsoft-board::messages.attachment.delete_failed'), 500);
            }

            return $this->success(__('sirsoft-board::messages.attachment.delete_success'));
        } catch (BoardNotFoundException $e) {
            return $this->notFound(__('sirsoft-board::messages.board.not_found'));
        } catch (\Exception $e) {
            return $this->error(__('sirsoft-board::messages.attachment.delete_failed'), 500, $e->getMessage());
        }
    }
}
