<?php

namespace Modules\Sirsoft\Board\Http\Controllers\Admin;

use App\Exceptions\ContentMediaLimitExceededException;
use App\Helpers\PermissionHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Controllers\Concerns\RespondsWithContentMediaTarget;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Board\Exceptions\BoardNotFoundException;
use Modules\Sirsoft\Board\Http\Requests\UploadContentMediaRequest;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\PostContentMediaService;
use Modules\Sirsoft\Board\Services\PostService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 관리자 게시판 본문 미디어 컨트롤러 — 관리자 글쓰기 편집기 업로드 (#883 4단계)
 *
 * 권한은 라우트의 `admin.attachments.upload` 이고, 서빙은 사용자 경로(`boards/{slug}/content-media/{hash}`)를 함께 쓴다.
 * 편집기 업로드 계약은 표준 엔드포인트 `GET admin/board/{slug}/content-media/target`(#887)이 낸다.
 */
class ContentMediaController extends AdminBaseController
{
    use RespondsWithContentMediaTarget;

    /**
     * @param  PostContentMediaService  $contentMediaService  본문 미디어 서비스
     * @param  PostService  $postService  게시글 서비스
     * @param  BoardService  $boardService  게시판 서비스 (계약 엔드포인트의 게시판 확인)
     */
    public function __construct(
        private PostContentMediaService $contentMediaService,
        private PostService $postService,
        private BoardService $boardService,
    ) {
        parent::__construct();
    }

    /**
     * 관리자 편집기 업로드 계약 — 표준 계약 엔드포인트 (#887).
     *
     * 판정은 관리자 업로드와 같은 `admin.attachments.upload` 권한이다. 관리자 글쓰기 폼 메타 `content_media` 는 같은 값을
     * 계속 싣는다(호환).
     *
     * @param  string  $slug  게시판 슬러그
     * @return JsonResponse 200 `{data: 계약|null}` / 403 / 404
     */
    public function target(string $slug): JsonResponse
    {
        $board = $this->boardService->getBoardBySlug($slug, checkScope: false);
        if (! $board) {
            return $this->notFound('sirsoft-board::messages.boards.not_found');
        }

        return $this->contentMediaTargetResponse(
            $this->contentMediaService->media(),
            $this->contentMediaService->context($slug, $board)->withExtra(['admin' => true]),
            'sirsoft-board::messages.content_media.target_success',
            admin: true,
        );
    }

    /**
     * 본문 미디어 업로드.
     *
     * @param  UploadContentMediaRequest  $request  업로드 요청
     * @param  string  $slug  게시판 슬러그
     * @return JsonResponse 201 `{data: {id, url, kind, hash}}`
     */
    public function upload(UploadContentMediaRequest $request, string $slug): JsonResponse
    {
        try {
            $validated = $request->validated();
            $postId = isset($validated['post_id']) ? (int) $validated['post_id'] : null;

            // 기존 글 편집 중이면 관리자 글 수정과 같은 스코프 권한 (첨부 업로드와 같은 판정)
            if ($postId !== null) {
                $post = $this->postService->getPost($slug, $postId);
                if (! PermissionHelper::checkScopeAccess($post, "sirsoft-board.{$slug}.admin.posts.write")) {
                    return $this->error('auth.scope_denied', 403);
                }
            }

            $attachment = $this->contentMediaService->upload(
                slug: $slug,
                file: $request->file('file'),
                tempKey: (string) $validated['temp_key'],
            );

            return $this->success('sirsoft-board::messages.content_media.upload_success', [
                'id' => $attachment->id,
                'hash' => $attachment->hash,
                'kind' => $attachment->meta['kind'] ?? null,
                'url' => $this->contentMediaService->signedUrl($slug, $attachment->hash),
            ], 201);
        } catch (ContentMediaLimitExceededException $e) {
            return $this->error($e->getMessageKey(), 422, ['code' => 'content_media_limit_exceeded'], $e->getMessageParams());
        } catch (ValidationException $e) {
            // 업로드 파일 필터(`core.content_media.filter_upload_file`)가 돌려준 파일이 정책에 맞지 않으면 422 (#887)
            throw $e;
        } catch (ModelNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.errors.post_not_found');
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (BoardNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.boards.not_found');
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.content_media.upload_failed', 500, $e->getMessage());
        }
    }
}
