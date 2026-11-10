<?php

namespace Modules\Sirsoft\Board\Http\Controllers\User;

use App\Helpers\InlineMediaPolicy;
use Modules\Sirsoft\Board\Http\Controllers\Concerns\RespondsToExpiredSession;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Thumbnails\ThumbnailQuery;
use App\Thumbnails\ThumbnailResponder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Exceptions\AttachmentLimitExceededException;
use Modules\Sirsoft\Board\Exceptions\BoardNotFoundException;
use Modules\Sirsoft\Board\Http\Requests\ReorderAttachmentsRequest;
use Modules\Sirsoft\Board\Http\Requests\UploadAttachmentRequest;
use Modules\Sirsoft\Board\Services\AttachmentService;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Support\GuestUploadOwnerCookie;
use Modules\Sirsoft\Board\Support\PostModifyGate;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 사용자용 첨부파일 컨트롤러
 *
 * 게시판 첨부파일 업로드, 다운로드, 삭제 기능을 제공합니다.
 * hash 기반 첨부파일 서빙 API도 제공합니다.
 */
class AttachmentController extends PublicBaseController
{
    use RespondsToExpiredSession;

    /**
     * AttachmentController 생성자
     *
     * @param  AttachmentService  $attachmentService  첨부파일 서비스
     * @param  BoardService  $boardService  게시판 서비스
     * @param  ThumbnailResponder  $thumbnails  썸네일 헬퍼
     * @param  PostService  $postService  게시글 서비스 (post_id 업로드의 같은 게시판 글 확인)
     * @param  PostModifyGate  $postModifyGate  글 수정 판정 (post_id 업로드)
     * @param  GuestUploadOwnerCookie  $guestOwnerCookie  비회원 업로드 소유 쿠키
     */
    public function __construct(
        private AttachmentService $attachmentService,
        private BoardService $boardService,
        private ThumbnailResponder $thumbnails,
        private PostService $postService,
        private PostModifyGate $postModifyGate,
        private GuestUploadOwnerCookie $guestOwnerCookie
    ) {
        parent::__construct();
    }

    /**
     * 첨부파일 다운로드 (서빙)
     *
     * 모든 파일(이미지 포함)을 다운로드 방식(Content-Disposition: attachment)으로 제공합니다.
     * 이미지 미리보기는 별도의 preview() 메서드를 사용합니다.
     * 응답은 글 상태와 무관하게 항상 `private` 캐시로 냅니다(공유 캐시 저장 금지).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $hash  첨부파일 해시 (12자)
     * @return StreamedResponse|Response|JsonResponse 파일 응답, 304 또는 에러 응답
     */
    public function download(string $slug, string $hash): StreamedResponse|Response|JsonResponse
    {
        try {
            // 게시판 존재 여부 확인
            $this->boardService->getBoardBySlug($slug, checkScope: false);

            // 해시로 첨부파일 조회
            $attachment = $this->attachmentService->getByHash($slug, $hash);

            if (! $attachment) {
                return $this->notFound('sirsoft-board::messages.attachment.not_found');
            }

            // 모든 파일(이미지 포함) 다운로드 방식으로 제공 — 서빙 정보(스트림 + 검증자 메타)
            $info = $this->attachmentService->getDownloadInfo($slug, $attachment->id, context: 'user');

            if (! $info) {
                return $this->notFound('sirsoft-board::messages.attachment.not_found');
            }

            // 캐시 계약(검증자 비교·304·200 헤더 동반)은 코어 단일 지점이 입힌다 — 미리보기
            // 통로와 같은 형태다. 종전에는 다운로드에 검증자가 없었다 (14차 G3 라운드).
            // `immutable` 은 `false`(이 URL 에는 `?v=` 가 없다), 압축은 선언으로 제외한다
            // (사용자 업로드물 — 형제 `PublicStorageController` 와 같은 선언).
            // `Content-Disposition: attachment` 는 스토리지가 세운 것을 헬퍼가 그대로 승계한다.
            // 키 검증도 그 지점(`streamedFileResponseFromMeta()`)이 한다 (16차 G3 라운드 E-6).
            return $this->streamedFileResponseFromMeta(
                $info,
                (int) g7_core_settings('cache.layout_ttl', 86400),
                immutable: false,
                skipCompression: true,
                logContext: ['board_slug' => $slug, 'hash' => $hash],
                errorMessageKey: 'sirsoft-board::messages.attachment.download_failed',
                // 공유 캐시 여부는 서비스가 판정한다(`getDownloadInfo()` 의 private — 지금은 글 상태와 무관하게 늘 true).
                // 다운로드 권한은 요청자마다 갈려, 공개 캐시로 나가면 앞단 CDN 사본을 권한 없는 방문자가 받고 기록 훅도
                // 건너뛴다. 서비스가 값을 빠뜨리면 private 쪽으로 판정한다.
                private: (bool) ($info['private'] ?? true)
            );
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (BoardNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.boards.not_found');
        } catch (\Exception $e) {
            Log::error('게시판 첨부 처리 실패', ['action' => 'download', 'exception' => $e]);

            return $this->error('sirsoft-board::messages.attachment.download_failed', 500);
        }
    }

    /**
     * 이미지 미리보기 (권한 체크 없이, 캐싱 헤더 포함)
     *
     * 이미지 파일만 미리보기를 제공합니다.
     * 비회원도 이미지를 볼 수 있습니다.
     *
     * 비밀글·삭제글 첨부는 서비스의 콘텐츠 상태 게이트가 차단하되, 게이트를 통과한
     * 응답 직렬화(PostResource)가 발급한 한시 서명 URL 은 동등한 자격으로 허용한다 —
     * <img> 는 Authorization 헤더를 실을 수 없는 렌더 경로이기 때문이다.
     *
     * `?size={규격}`·`?w={숫자}` 가 붙으면 콘텐츠 상태 게이트(`getFileInfo()`)를 지난 뒤 썸네일로 낸다.
     * 게시판 첨부는 게이트 원본이라 썸네일은 비공개 디스크·`private` 캐시다. 낼 수 없으면 원본에
     * `X-G7-Thumbnail: bypass:{사유}` 를 붙인다. 서명은 `size`·`w` 를 빼고 검증한다 — 화면이 서명
     * 주소 뒤에 `size` 를 덧붙여도 서명이 깨지지 않게(썸네일은 같은 게이트를 지난 뒤 크기만 바꾼다).
     *
     * 비밀글·삭제글·블라인드 글 첨부와 서명으로 연 원본은 `private` 캐시로 낸다 — 공유 캐시에 남으면
     * 다음 방문자가 게이트 없이 받는다.
     *
     * @param  Request  $request  HTTP 요청 객체 (한시 서명 검증·썸네일 파라미터용)
     * @param  string  $slug  게시판 슬러그
     * @param  string  $hash  첨부파일 해시 (12자)
     * @return SymfonyResponse|JsonResponse 이미지·썸네일 응답(200·304) 또는 에러 응답
     */
    // audit:allow controller-base-request-injection reason: GET 이미지 서빙. 요청 URL 의 한시 서명 검증과 썸네일 쿼리(size·w) 판독만 수행. 검증할 body 없음(slug/hash 는 라우트 파라미터)
    public function preview(Request $request, string $slug, string $hash): SymfonyResponse|JsonResponse
    {
        try {
            // 게시판 존재 여부 확인
            $this->boardService->getBoardBySlug($slug, checkScope: false);

            // 해시로 첨부파일 조회
            $attachment = $this->attachmentService->getByHash($slug, $hash);

            if (! $attachment) {
                return $this->notFound('sirsoft-board::messages.attachment.not_found');
            }

            // 이미지가 아닌 경우
            if (! $attachment->is_image) {
                // 존재하지 않는 badRequest() 를 부르던 자리 — Error 로 500 이 나던 것을 400 으로 바로잡는다
                return $this->error('sirsoft-board::messages.attachment.not_image', 400);
            }

            // 파일 정보 조회 (유효 서명은 콘텐츠 상태 게이트 통과 자격의 위임).
            // 서명은 썸네일 파라미터(size·w)를 빼고 검증한다 — 서명 주소에 size 를 덧붙인 <img> 가 403 이 되지 않게.
            $fileInfo = $this->attachmentService->getFileInfo(
                $slug,
                $attachment->id,
                signatureVerified: $request->hasValidRelativeSignatureWhileIgnoring(ThumbnailQuery::ignoreClosure())
            );

            if (! $fileInfo) {
                return $this->notFound('sirsoft-board::messages.attachment.not_found');
            }

            // 게이트(삭제글·비밀글·블라인드 글)를 지난 뒤, 원본 응답 바로 앞
            if ($thumbnail = $this->thumbnails->respond($request, $fileInfo['thumbnail_source'] ?? null)) {
                return $thumbnail;
            }

            // 캐싱 헤더와 함께 응답 (환경설정 레이아웃 캐시 TTL 사용, 기본 24시간).
            //
            // `fileResponse()` 를 쓰면 안 된다 — 그 헬퍼는 로컬 절대 경로 전제
            // (`filemtime`/`filesize`)라 원격 첨부 디스크(S3 등)에서는 파일을 찾지 못하고,
            // 이 통로는 그 실패를 404 로 접어 **썸네일이 오류 없이 전부 사라진다**.
            // 코어 형제는 같은 사유로 이미 스트림 서빙으로 옮겼다 (8차 G3 라운드 C-2).
            //
            // 파일 이름은 스토리지가 원본 이름으로 선언한다 — `fileResponse()` 는
            // `basename()` 고정이라 저장 UUID 가 노출됐다 (같은 라운드 C-3).
            $stream = $this->attachmentService->streamFile($fileInfo);

            if (! $stream) {
                return $this->notFound('sirsoft-board::messages.attachment.not_found');
            }

            // 수정 시각을 함께 넘긴다 — ETag 를 쓰지 않고 `If-Modified-Since` 로만 재검증하는
            // 프록시는 `Last-Modified` 가 없으면 매 요청 전문을 받아 간다. 값은 ETag 소스와
            // 같은 행 메타(`updated_at`)라 파일 stat 없이 결정적이다. 코어 형제 둘
            // (PublicAttachmentController · PublicTemplateController)과 같은 형태다.
            //
            // ETag 소스는 폴백하지 않는다 — `getFileInfo()` 가 항상 채우며, `path` 로 폴백하면
            // 그 값이 내용과 무관한 불변값이라 재검증이 영원히 304 가 된다.
            //
            // 다만 서비스는 확장에 열려 있다 — 서브클래스가 그 키 없이 돌려주면 종전에는
            // 배열 접근 경고가 `ErrorException` 으로 올라 500 이 됐고, 원인이 응답에
            // 드러나지 않았다. 키 부재의 **명시 실패** + 로그는 코어 헬퍼
            // (`streamedFileResponseFromMeta()`)가 소유한다 — 종전에는 이 통로가
            // `array_key_exists` 가드 사본을 들고 있었다 (10차 B10-10 · E-09 → 16차 E-6).
            //
            // `immutable` 은 `false`(이 URL 에는 `?v=` 가 없다). `Vary` 판정 근거(MIME)는 200 과
            // 같은 값(행 메타)을 넘기고, 압축은 선언으로 제외한다 — 사용자 업로드물은 크기·건수가
            // 유계가 아니라 압축 경로의 전체 적재가 동시 요청 수만큼 증폭된다 (형제
            // `PublicStorageController` 와 같은 선언 · 14차 G3 라운드).
            //
            // 비밀글·삭제글·블라인드 글 첨부와 서명으로 연 원본은 `private` 다 — 요청자마다 볼 수 있는지가
            // 갈리는 응답이 공유 캐시(CDN·프록시)에 남으면 다음 방문자가 게이트 없이 받는다.
            $response = $this->streamedFileResponseFromMeta(
                ['response' => $stream, ...$fileInfo],
                (int) g7_core_settings('cache.layout_ttl', 86400),
                immutable: false,
                skipCompression: true,
                logContext: ['board_slug' => $slug, 'hash' => $hash],
                errorMessageKey: 'sirsoft-board::messages.attachment.preview_failed',
                private: (bool) ($fileInfo['private'] ?? false)
            );

            // 인라인 표시는 래스터 이미지만 — SVG 는 is_image 에서 이미 걸러지고, 응답에는 nosniff (KVE-2026-2366).
            // 썸네일을 요청했는데 원본을 내면 사유를 붙인다(X-G7-Thumbnail: bypass:{사유}).
            return ThumbnailResponder::markBypass(InlineMediaPolicy::harden($response, $fileInfo['mime_type']), $request);
        } catch (AccessDeniedHttpException $e) {
            // 삭제글 첨부 등 권한 차단은 403 으로 응답
            return $this->error('auth.scope_denied', 403);
        } catch (BoardNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.boards.not_found');
        } catch (\Exception $e) {
            Log::error('게시판 첨부 처리 실패', ['action' => 'preview', 'exception' => $e]);

            return $this->error('sirsoft-board::messages.attachment.preview_failed', 500);
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
                return $this->forbidden('sirsoft-board::messages.attachment.upload_disabled');
            }

            $validated = $request->validated();
            $postId = isset($validated['post_id']) ? (int) $validated['post_id'] : null;

            // 기존 글에 붙이는 업로드는 그 글을 고칠 수 있는 사람만 — 같은 게시판 글인지 보고(아니면 404),
            // 글 수정과 같은 판정을 한다(아니면 403). 종전에는 업로드 권한만 보아 남의 글·다른 게시판 글 번호로
            // 파일을 붙일 수 있었다(#883). 비회원 글의 검증 토큰은 저장(PUT)에서 다시 쓰므로 소비하지 않는다.
            if ($postId !== null) {
                $post = $this->postService->getPost($slug, $postId, 'user');

                if (! $this->postModifyGate->allows($post, $request, consume: false)) {
                    // 만료 토큰 회원이면 세션 만료(401) — 비회원으로 통과해 본인 확인이 실패했을 뿐이다 (#883 후속)
                    return $this->ownershipDenied($request, 'sirsoft-board::messages.posts.modify_permission_denied');
                }
            }

            // 비회원 임시 업로드는 소유 쿠키를 발급(있으면 재사용)해 그 해시를 첨부에 남긴다 — 연결 전 삭제·순서 판정용 (#883)
            $guestToken = (Auth::id() === null && $postId === null) ? $this->guestOwnerCookie->issue($request) : null;

            // 첨부파일 업로드 (Service에서 처리)
            $attachment = $this->attachmentService->upload(
                slug: $slug,
                file: $request->file('file'),
                postId: $postId,
                collection: $validated['collection'] ?? 'attachments',
                tempKey: $validated['temp_key'] ?? null,
                guestOwnerHash: $guestToken !== null ? $this->guestOwnerCookie->hash($guestToken) : null
            );

            // FileUploader 컴포넌트가 response.data?.data 형식을 기대하므로
            // data 키 안에 한 번 더 감싸서 반환
            $response = $this->success(
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
                        // 올린 본인에게만 주는 임시 키 — 키 없이 올렸으면 서버가 만든 키다. 비회원은 첨부 번호로 이을 때
                        // 이 키를 함께 보내야 한다(번호만으로는 잇지 않는다, #883). 글에 바로 붙인 업로드는 null.
                        'temp_key' => $attachment->temp_key,
                    ],
                ],
                201
            );

            return $guestToken !== null ? $this->guestOwnerCookie->attach($response, $guestToken, $request) : $response;
        } catch (ModelNotFoundException $e) {
            // 이 게시판에 없는 글 번호 — 다른 게시판 글로 업로드하려던 요청
            return $this->notFound('sirsoft-board::messages.errors.post_not_found');
        } catch (AccessDeniedHttpException $e) {
            return $this->forbidden('auth.scope_denied');
        } catch (AttachmentLimitExceededException $e) {
            // 게시판 첨부 개수 상한 초과 — generic 500 이 아닌 422 명시 차단
            return $this->error(
                $e->getMessageKey(),
                422,
                ['code' => 'attachment_limit_exceeded'],
                $e->getMessageParams()
            );
        } catch (BoardNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.boards.not_found');
        } catch (\Exception $e) {
            Log::error('게시판 첨부 처리 실패', ['action' => 'upload', 'exception' => $e]);

            return $this->error('sirsoft-board::messages.attachment.upload_failed', 500);
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
            // 비회원은 업로드 때 받은 소유 쿠키로 자기 임시 첨부의 순서를 바꾼다 (#883)
            $result = $this->attachmentService->reorder($slug, $orders, 'user', $this->guestOwnerCookie->resolveToken($request));

            if (! $result) {
                return $this->error('sirsoft-board::messages.attachment.reorder_failed', 500);
            }

            return $this->success('sirsoft-board::messages.attachment.reorder_success');
        } catch (BoardNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.boards.not_found');
        } catch (AccessDeniedHttpException $e) {
            // 남이 올린 임시 첨부 등 스코프 밖 대상 — 서버 오류(500)가 아니라 403 으로 알린다
            return $this->forbidden('auth.scope_denied');
        } catch (\Exception $e) {
            Log::error('게시판 첨부 처리 실패', ['action' => 'reorder', 'exception' => $e]);

            return $this->error('sirsoft-board::messages.attachment.reorder_failed', 500);
        }
    }

    /**
     * 첨부파일 삭제
     *
     * @param  Request  $request  HTTP 요청 (비회원 소유 쿠키)
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  첨부파일 ID
     * @return JsonResponse 삭제 결과 응답
     */
    // audit:allow controller-base-request-injection reason: DELETE 본문 없음 — 비회원 소유 쿠키만 읽는다(검증할 입력 없음)
    public function destroy(Request $request, string $slug, int $id): JsonResponse
    {
        try {
            // 게시판 존재 여부 확인
            $this->boardService->getBoardBySlug($slug, checkScope: false);

            // 첨부파일 조회 (Service를 통해)
            $attachment = $this->attachmentService->getById($slug, $id);

            if (! $attachment) {
                return $this->notFound('sirsoft-board::messages.attachment.not_found');
            }

            // 권한 확인 (Service에서 처리) — 비회원은 업로드 때 받은 소유 쿠키로 연결 전 자기 임시 첨부만 (#883)
            $guestToken = $this->guestOwnerCookie->resolveToken($request);
            if (! $this->attachmentService->canDelete($attachment, Auth::id(), $guestToken)) {
                return $this->forbidden('sirsoft-board::messages.attachment.delete_forbidden');
            }

            // 삭제 (Service에서 처리)
            $result = $this->attachmentService->delete($slug, $id, 'user', $guestToken);

            if (! $result) {
                return $this->error('sirsoft-board::messages.attachment.delete_failed', 500);
            }

            return $this->success('sirsoft-board::messages.attachment.delete_success');
        } catch (BoardNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.boards.not_found');
        } catch (AccessDeniedHttpException $e) {
            // 남이 올린 임시 첨부 등 스코프 밖 대상 — 서버 오류(500)가 아니라 403 으로 알린다
            return $this->forbidden('auth.scope_denied');
        } catch (\Exception $e) {
            Log::error('게시판 첨부 처리 실패', ['action' => 'delete', 'exception' => $e]);

            return $this->error('sirsoft-board::messages.attachment.delete_failed', 500);
        }
    }
}
