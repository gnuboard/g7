<?php

namespace Modules\Sirsoft\Board\Http\Controllers\User;

use App\Exceptions\ContentMediaLimitExceededException;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Http\Controllers\Concerns\RespondsWithContentMediaTarget;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Board\Exceptions\BoardNotFoundException;
use Modules\Sirsoft\Board\Http\Controllers\Concerns\RespondsToExpiredSession;
use Modules\Sirsoft\Board\Http\Requests\UploadContentMediaRequest;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\PostContentMediaService;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Support\GuestUploadOwnerCookie;
use Modules\Sirsoft\Board\Support\PostModifyGate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 사용자 게시판 본문 미디어 컨트롤러 — 편집기 본문 이미지·동영상·오디오 업로드와 서빙 (#883 4단계)
 *
 * 업로드는 그 게시판 `attachments.upload` 권한(비회원 포함)과 본문 미디어 정책으로 판정한다. 「파일 첨부 사용」은 보지 않는다(D26).
 * 서빙에는 권한 미들웨어가 없다 — `<img>`·`<video>` 요청에는 Bearer 가 실리지 않으므로, 서명·글 읽기 권한·콘텐츠 게이트를
 * 서비스(`findServable`)가 맡는다. 편집기 업로드 계약은 표준 엔드포인트 `GET boards/{slug}/content-media/target`(#887)이 낸다.
 */
class ContentMediaController extends PublicBaseController
{
    use RespondsToExpiredSession;
    use RespondsWithContentMediaTarget;

    /**
     * @param  PostContentMediaService  $contentMediaService  본문 미디어 서비스
     * @param  PostService  $postService  게시글 서비스 (post_id 의 같은 게시판 글 확인)
     * @param  PostModifyGate  $postModifyGate  글 수정 판정 (post_id 업로드)
     * @param  GuestUploadOwnerCookie  $guestOwnerCookie  비회원 업로드 소유 쿠키
     * @param  BoardService  $boardService  게시판 서비스 (계약 엔드포인트의 게시판 확인)
     */
    public function __construct(
        private PostContentMediaService $contentMediaService,
        private PostService $postService,
        private PostModifyGate $postModifyGate,
        private GuestUploadOwnerCookie $guestOwnerCookie,
        private BoardService $boardService,
    ) {
        parent::__construct();
    }

    /**
     * 편집기 업로드 계약 — 표준 계약 엔드포인트 (#887).
     *
     * 값은 그 게시판 본문 미디어 정책이고(코어는 동영상·오디오 업로드 허용 스위치로만 관여), 판정은 업로드와 같은
     * `attachments.upload` 권한이다. 글쓰기 폼 메타 `content_media` 는 같은 값을 계속 싣는다(호환).
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
            $this->contentMediaService->context($slug, $board),
            'sirsoft-board::messages.content_media.target_success',
        );
    }

    /**
     * 본문 미디어 업로드 — 응답 `data.url` 은 서명된 서빙 주소(편집 중 미리보기)다.
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

            // 기존 글 편집 중 업로드는 그 글을 고칠 수 있는 사람만 (첨부 업로드와 같은 판정, 토큰은 저장에서 다시 쓰므로 소비하지 않음)
            if ($postId !== null) {
                $post = $this->postService->getPost($slug, $postId, 'user');
                if (! $this->postModifyGate->allows($post, $request, consume: false)) {
                    return $this->ownershipDenied($request, 'sirsoft-board::messages.posts.modify_permission_denied');
                }
            }

            // 비회원은 소유 쿠키를 발급(있으면 재사용)해 그 해시를 행에 남긴다 — 로그인 후 연결·소유 판정용
            $guestToken = Auth::id() === null ? $this->guestOwnerCookie->issue($request) : null;

            $attachment = $this->contentMediaService->upload(
                slug: $slug,
                file: $request->file('file'),
                tempKey: (string) $validated['temp_key'],
                guestOwnerHash: $guestToken !== null ? $this->guestOwnerCookie->hash($guestToken) : null,
            );

            $response = $this->success('sirsoft-board::messages.content_media.upload_success', [
                'id' => $attachment->id,
                'hash' => $attachment->hash,
                'kind' => $attachment->meta['kind'] ?? null,
                'url' => $this->contentMediaService->signedUrl($slug, $attachment->hash),
            ], 201);

            return $guestToken !== null ? $this->guestOwnerCookie->attach($response, $guestToken, $request) : $response;
        } catch (ContentMediaLimitExceededException $e) {
            return $this->error($e->getMessageKey(), 422, ['code' => 'content_media_limit_exceeded'], $e->getMessageParams());
        } catch (ValidationException $e) {
            // 업로드 파일 필터(`core.content_media.filter_upload_file`)가 돌려준 파일이 정책에 맞지 않으면 422 (#887)
            throw $e;
        } catch (ModelNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.errors.post_not_found');
        } catch (AccessDeniedHttpException $e) {
            return $this->forbidden('auth.scope_denied');
        } catch (BoardNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.boards.not_found');
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.content_media.upload_failed', 500, $e->getMessage());
        }
    }

    /**
     * 본문 미디어 서빙 (Range 지원).
     *
     * @param  Request  $request  요청 (한시 서명·Range)
     * @param  string  $slug  게시판 슬러그
     * @param  string  $hash  해시 (12자)
     * @return Response|JsonResponse 파일 응답 또는 오류
     */
    // audit:allow controller-base-request-injection reason: GET 미디어 서빙. 요청 URL 서명 검증(hasValidSignature)과 Range 헤더만 읽는다(검증할 본문 없음)
    public function show(Request $request, string $slug, string $hash): Response|JsonResponse
    {
        try {
            $attachment = $this->contentMediaService->findServable(
                $slug,
                $hash,
                signatureVerified: $request->hasValidSignature(absolute: false)
            );

            $response = $this->contentMediaService->respond($slug, $attachment, $request);

            return $response ?? $this->notFound('sirsoft-board::messages.attachment.not_found');
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (NotFoundHttpException $e) {
            return $this->notFound('sirsoft-board::messages.attachment.not_found');
        } catch (BoardNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.boards.not_found');
        } catch (\Exception $e) {
            return $this->error('sirsoft-board::messages.attachment.preview_failed', 500, $e->getMessage());
        }
    }
}
