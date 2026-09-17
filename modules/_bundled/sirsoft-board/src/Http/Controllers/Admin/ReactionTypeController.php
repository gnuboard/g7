<?php

namespace Modules\Sirsoft\Board\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Models\Attachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Http\Requests\Admin\UploadReactionTypeIconRequest;
use Modules\Sirsoft\Board\Http\Resources\ReactionTypeResource;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionTypeRepositoryInterface;
use Modules\Sirsoft\Board\Services\ReactionTypeService;

/**
 * 관리자용 반응 유형 컨트롤러
 *
 * 게시판 설정 화면의 "사용할 반응 유형" 체크박스 옵션 소스로 활성 유형 목록을 제공하고,
 * 유형별 아이콘 이미지의 업로드·삭제를 처리합니다.
 *
 * 유형 자체의 추가·수정·삭제는 전용 CRUD 엔드포인트가 아니라 게시판 환경설정 저장
 * (BoardSettingsController::store)이 ReactionTypeService::syncTypes() 로 일괄 반영합니다
 * (PO 결정 R2 — 기본 설정 안 인라인 편집).
 */
class ReactionTypeController extends AdminBaseController
{
    /**
     * ReactionTypeController 생성자
     *
     * @param  ReactionTypeRepositoryInterface  $reactionTypeRepository  반응 유형 Repository
     * @param  ReactionTypeService  $reactionTypeService  반응 유형 서비스
     */
    public function __construct(
        private ReactionTypeRepositoryInterface $reactionTypeRepository,
        private ReactionTypeService $reactionTypeService,
    ) {
        parent::__construct();
    }

    /**
     * 활성 반응 유형 전체를 display_order 순으로 반환합니다.
     *
     * @return JsonResponse 반응 유형 목록 응답
     */
    public function index(): JsonResponse
    {
        try {
            $types = $this->reactionTypeRepository->getActive();

            return $this->success(
                'sirsoft-board::messages.reaction.list_success',
                ['reaction_types' => ReactionTypeResource::collection($types)]
            );
        } catch (\Exception $e) {
            Log::error('반응 유형 목록 조회 실패', ['error' => $e->getMessage()]);

            return $this->error('sirsoft-board::messages.reaction.failed', 500);
        }
    }

    /**
     * 반응 유형 아이콘 이미지를 업로드합니다.
     *
     * 응답 형태는 코어 <file-uploader> 계약(`data.data` 안에 파일 1건)을 따른다 —
     * 게시판 첨부 업로드와 같은 모양이어야 컴포넌트가 그대로 붙는다.
     *
     * @param  UploadReactionTypeIconRequest  $request  업로드 요청
     * @return JsonResponse 업로드된 첨부 정보 (201)
     */
    public function uploadIcon(UploadReactionTypeIconRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $attachment = $this->reactionTypeService->uploadIcon(
                $request->file('file'),
                isset($validated['reaction_type_id']) ? (int) $validated['reaction_type_id'] : null,
            );

            $url = Attachment::urlForHash($attachment->hash);

            return $this->success(
                'sirsoft-board::messages.reaction_type.icon_upload_success',
                [
                    'data' => [
                        'id' => $attachment->id,
                        'hash' => $attachment->hash,
                        'original_filename' => $attachment->original_filename,
                        'mime_type' => $attachment->mime_type,
                        'size' => $attachment->size,
                        'url' => $url,
                        'download_url' => $url,
                        'is_image' => true,
                        'order' => 1,
                    ],
                ],
                201
            );
        } catch (\Exception $e) {
            Log::error('반응 유형 아이콘 업로드 실패', ['error' => $e->getMessage()]);

            return $this->error('sirsoft-board::messages.reaction_type.icon_upload_failed', 500);
        }
    }

    /**
     * 반응 유형 아이콘 첨부를 삭제합니다.
     *
     * 이 모듈이 만든 아이콘 첨부가 아니면 404 로 응답한다 — 이 엔드포인트로 남의
     * 첨부를 지울 수 없어야 한다.
     *
     * @param  int  $attachmentId  첨부 ID
     * @return JsonResponse 삭제 결과
     */
    public function destroyIcon(int $attachmentId): JsonResponse
    {
        try {
            if (! $this->reactionTypeService->deleteIcon($attachmentId)) {
                return $this->error('sirsoft-board::messages.reaction_type.icon_not_found', 404);
            }

            return $this->success('sirsoft-board::messages.reaction_type.icon_deleted');
        } catch (\Exception $e) {
            Log::error('반응 유형 아이콘 삭제 실패', ['error' => $e->getMessage(), 'attachment_id' => $attachmentId]);

            return $this->error('sirsoft-board::messages.reaction_type.icon_delete_failed', 500);
        }
    }
}
