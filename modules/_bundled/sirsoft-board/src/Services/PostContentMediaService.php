<?php

namespace Modules\Sirsoft\Board\Services;

use App\Exceptions\ContentMediaLimitExceededException;
use App\Support\ContentMedia\ContentMediaCloner;
use App\Support\ContentMedia\ContentMediaContext;
use App\Support\ContentMedia\ContentMediaPolicy;
use App\Support\ContentMedia\ContentMediaUrlRewriter;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Board\Enums\TriggerType;
use Modules\Sirsoft\Board\Exceptions\BoardNotFoundException;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Services\ContentMedia\BoardContentMediaService;
use Modules\Sirsoft\Board\Services\ContentMedia\BoardContentMediaStore;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 게시판 본문 미디어 서비스 — 편집기 본문에 넣는 이미지·동영상·오디오 (#883 4단계, D4~D8·D26 / #887 코어 골격)
 *
 * 흐름(업로드·저장 동기화·서명·서빙·정리)과 공용 훅 `core.content_media.*` 는 코어 골격이 돌린다
 * (`ContentMedia\BoardContentMediaService` + 어댑터 `ContentMedia\BoardContentMediaStore`). 이 클래스는 게시판 호출자
 * (`PostService`·리소스·컨트롤러·요청)가 쓰던 시그니처(게시판 슬러그·글 모델)를 지키며 골격 문맥으로 바꿔 넘긴다.
 *
 * 저장은 `board_attachments` 의 `collection = content_media` 이다. 파일은 **처음부터 최종 경로**
 * `{slug}/content-media/{Y/m/d}/{uuid}.ext` 에 두고, 임시 행은 `board_id = 0` + 임시 키다. 글 저장 때 본문이 참조한 것만
 * DB 갱신으로 글에 잇는다. 끝내 잇지 않은 임시 행은 `sirsoft-board:prune-attachments` 의 임시 정리가 지운다.
 *
 * 본문 미디어는 첨부 목록·첨부 개수·목록 썸네일에 들지 않고, 개수는 정책의 문서당 최대 개수로 따로 센다(D8). 업로드 판정은
 * 「파일 첨부 사용」(`use_file_upload`)과 무관하게 본문 미디어 정책과 그 게시판의 `attachments.upload` 권한으로 한다(D26).
 *
 * 본문에 저장하는 주소는 서명 없는 정식 주소이고, 응답을 만들 때 이 글 소유 해시만 서명 주소로 바꾼다(`presentContent`).
 * 서빙은 서명이 있으면 그것을, 없으면 요청자의 **글 읽기 권한**과 첨부와 같은 콘텐츠 게이트(삭제·비밀·블라인드)를 본다.
 * 다른 글(다른 게시판 포함)의 본문 미디어를 쓴 글을 저장하면, 저장하는 사람이 원본을 볼 수 있을 때만 이 글 소유로 복제한다.
 */
class PostContentMediaService
{
    /** 서빙 라우트 이름 */
    public const SERVE_ROUTE = BoardContentMediaStore::SERVE_ROUTE;

    /** 업로드 시점 상한 배수 — 최종 판정은 저장 때 (남용 방지) */
    public const UPLOAD_HEADROOM = BoardContentMediaService::UPLOAD_HEADROOM;

    /**
     * @param  BoardContentMediaService  $media  게시판 본문 미디어 골격 서비스
     * @param  BoardRepositoryInterface  $boardRepository  게시판 리포지토리
     * @param  AttachmentService  $attachmentService  첨부 서비스 (비회원 업로드 소유 쿠키 해시)
     */
    public function __construct(
        private BoardContentMediaService $media,
        private BoardRepositoryInterface $boardRepository,
        private AttachmentService $attachmentService,
    ) {}

    /**
     * 게시판 본문 미디어 골격 서비스 — 표준 계약 엔드포인트·정리처럼 골격 문맥을 직접 쓰는 곳.
     *
     * @return BoardContentMediaService 골격 서비스
     */
    public function media(): BoardContentMediaService
    {
        return $this->media;
    }

    /**
     * 게시판 문맥(요청자 포함)을 만듭니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  Board|null  $board  이미 조회한 게시판
     * @param  int|null  $postId  게시글 ID
     * @return ContentMediaContext 문맥
     */
    public function context(string $slug, ?Board $board = null, ?int $postId = null): ContentMediaContext
    {
        $userId = Auth::id();

        return $this->media->boardStore()->context($slug, $board, $postId)
            ->withActor($userId !== null ? (int) $userId : null, $userId === null ? $this->attachmentService->currentGuestOwnerHash() : null);
    }

    /**
     * 게시판의 본문 미디어 정책 — 게시판 값, 없으면 첨부 정책으로 만든 대체 정책(이미지 켬·동영상·오디오 끔).
     *
     * @param  Board  $board  게시판
     * @return ContentMediaPolicy 정책
     */
    public function resolvePolicy(Board $board): ContentMediaPolicy
    {
        return $this->media->policy($this->media->boardStore()->context((string) $board->slug, $board));
    }

    /**
     * 게시판 첨부 정책으로 만든 대체 정책 (D7·D21).
     *
     * @param  Board  $board  게시판
     * @return ContentMediaPolicy 대체 정책
     */
    public function fallbackPolicy(Board $board): ContentMediaPolicy
    {
        return $this->media->boardStore()->fallbackPolicyFor($board);
    }

    /**
     * 이 게시판 본문 미디어 서빙 주소 정규식(구분자 없음, `hash` 그룹).
     *
     * @param  string  $slug  게시판 슬러그
     * @return string 정규식 본문
     */
    public function servePattern(string $slug): string
    {
        return $this->media->boardStore()->servePattern($this->media->boardStore()->context($slug));
    }

    /**
     * 본문 미디어를 임시로 올립니다 — 파일은 최종 경로, 행은 임시(board_id=0 + 임시 키).
     *
     * 코어 골격이 상한 → `core.content_media.before_upload` → `filter_upload_file` → 저장 → 행 → `after_upload` 순서로 돈다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  UploadedFile  $file  파일 (요청 계층이 정책으로 검증함)
     * @param  string  $tempKey  임시 업로드 키
     * @param  string|null  $guestOwnerHash  비회원 소유 쿠키 해시
     * @return Attachment 본문 미디어 행
     *
     * @throws BoardNotFoundException 게시판이 없을 때
     * @throws ContentMediaLimitExceededException 임시 키의 업로드가 상한(문서당 최대 × 2)을 넘을 때
     * @throws ValidationException 업로드 파일 필터가 돌려준 파일이 정책에 맞지 않을 때
     */
    public function upload(string $slug, UploadedFile $file, string $tempKey, ?string $guestOwnerHash = null): Attachment
    {
        $board = $this->boardOrFail($slug);
        $userId = Auth::id();
        $context = $this->media->boardStore()->context($slug, $board)
            ->withTempKey($tempKey)
            ->withActor($userId !== null ? (int) $userId : null, $userId === null ? $guestOwnerHash : null);

        /** @var Attachment $row */
        $row = $this->media->upload($context, $file);

        return $row;
    }

    /**
     * 글 본문과 본문 미디어를 맞춥니다 — 글 저장 트랜잭션 안에서 부른다.
     *
     * 1. 본문이 참조하는 이 게시판 본문 미디어 해시를 뽑는다(HTML 모드만, 텍스트 모드는 참조 없음).
     * 2. 참조된 임시 본문 미디어 중 같은 임시 키 + 소유 규칙(첨부와 같음)을 통과한 것을 연결 대상으로 고른다.
     * 3. 다른 글(다른 게시판 포함) 소유 본문 미디어와 요청자 본인이 다른 임시 키로 올린 것(비회원은 업로드 소유 쿠키가 맞을 때)은
     *    요청자가 원본을 볼 수 있을 때만 이 글 소유의 새 행·새 해시로 복제하고 본문 주소를 바꾼다(#887). 볼 수 없으면 주소를
     *    그대로 둔다 — 서명은 이 글 소유 해시에만 하므로 그 미디어는 원본 글의 게이트를 따른다.
     * 4. 「이미 이어진 것 + 이을 것 + 복제할 것」이 문서당 최대를 넘으면 `ContentMediaLimitExceededException`(422).
     * 5. 연결 → 복제 → 제거. 이 글에 이어져 있었는데 본문에서 빠진 것은 소프트 삭제(삭제 주체 user|admin).
     *
     * 다른 게시판 주소(`boards/{다른 슬러그}/content-media/{해시}`)는 복제할 수 있는 것만 이 게시판 주소로 바꿔 골격에 넘긴다 —
     * 그래야 복제 뒤 새 해시가 이 게시판 서빙 주소에 놓인다. 복제가 있으면 반환값 `content` 가 바뀐 본문이다 — 호출자가 글에
     * 다시 저장한다(같은 트랜잭션).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  Post  $post  저장된 글
     * @param  string|null  $tempKey  요청의 임시 업로드 키
     * @param  string  $context  호출 경로 (user|admin) — 삭제 주체
     * @return array{linked: int, removed: int, cloned: int, content: string|null} 처리 결과 (`content` = 복제 해시로 바꾼 본문)
     *
     * @throws BoardNotFoundException 게시판이 없을 때
     * @throws ContentMediaLimitExceededException 문서당 최대 개수 초과
     */
    public function syncWithContent(string $slug, Post $post, ?string $tempKey, string $context = 'user'): array
    {
        $board = $this->boardOrFail($slug);
        $mediaContext = $this->context($slug, $board, (int) $post->id)
            ->withTempKey($tempKey)
            ->withExtra(['trigger' => $context === 'admin' ? TriggerType::Admin->value : TriggerType::User->value]);

        $isHtml = ($post->content_mode ?? 'text') === 'html';
        $content = $isHtml ? $this->adoptOtherBoardReferences($mediaContext, (string) $post->content) : $post->content;

        $result = $this->media->syncWithContent($mediaContext, $content, $isHtml);

        return [
            'linked' => $result['linked'],
            'removed' => $result['removed'],
            'cloned' => $result['cloned'],
            'content' => $result['cloned'] > 0 ? $result['content'] : $post->content,
        ];
    }

    /**
     * 글에 이어진 본문 미디어 해시 — 응답 본문에서 서명할 대상 (#887).
     *
     * @param  Post  $post  게시글
     * @return array<int, string> 이 글 소유 본문 미디어 해시
     */
    public function ownedHashes(Post $post): array
    {
        if ($post->id === null || $post->board_id === null) {
            return [];
        }

        return $this->ownedHashesOf((int) $post->board_id, (int) $post->id);
    }

    /**
     * 게시판·글 번호로 그 글에 이어진 본문 미디어 해시 (글 모델이 없는 직렬화용, #887).
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $postId  게시글 ID
     * @return array<int, string> 그 글 소유 본문 미디어 해시
     */
    public function ownedHashesOf(int $boardId, int $postId): array
    {
        $context = ContentMediaContext::module(BoardContentMediaStore::EXTENSION, BoardContentMediaStore::DOCUMENT_TYPE, $postId)
            ->withExtra(['board_id' => $boardId]);

        return $this->media->ownedHashes($context);
    }

    /**
     * 저장 전 본문의 서명 주소를 정식 주소로 되돌립니다(편집 폼이 받은 서명 주소를 그대로 저장하지 않게).
     *
     * 이 게시판 주소는 코어 골격이, 다른 게시판 주소(다른 게시판 글에서 복사해 붙인 본문)는 같은 규칙으로 함께 되돌린다 —
     * 정식 주소여야 복제 판정을 받고, 복제하지 못한 주소는 원본 글의 게이트를 따른다(#887).
     *
     * @param  string|null  $html  본문
     * @param  string  $slug  게시판 슬러그
     * @return string|null 정규화한 본문
     */
    public function normalizeContent(?string $html, string $slug): ?string
    {
        $html = $this->media->normalizeContent($this->media->boardStore()->context($slug), $html);

        return is_string($html) ? ContentMediaUrlRewriter::normalize($html, $this->media->boardStore()->anyBoardServePattern()) : $html;
    }

    /**
     * 응답 본문의 정식 주소를 서명 주소로 바꿉니다(유효 시간 = 코어 설정 `content.media_url_ttl_minutes`).
     *
     * 본문을 받을 자격(비밀·블라인드·삭제 게이트)을 통과한 직렬화에서만 부른다 — 서명은 그 자격의 위임이다.
     *
     * - `$post` 를 주면 **그 글이 소유한 해시만** 서명한다. 다른 글의 해시는 정식 주소로 둔다(원본 글의 게이트를 따른다) —
     *   남의 비밀글 해시를 본문에 넣어 서명 주소를 받아 가지 못하게 한다(#887).
     * - `$signAll = true` 는 관리자 증거 열람 화면(신고 상세)만 — 이 게시판 서빙 주소를 모두 서명한다(#883 검수 동작).
     * - 둘 다 아니면 서명하지 않는다.
     *
     * @param  string|null  $html  본문
     * @param  string  $slug  게시판 슬러그
     * @param  Post|null  $post  본문의 주인 글
     * @param  bool  $signAll  관리자 증거 열람 화면 전용 — 모두 서명
     * @return string|null 서명 주소로 바꾼 본문
     */
    public function presentContent(?string $html, string $slug, ?Post $post = null, bool $signAll = false): ?string
    {
        if ($html === null || $html === '' || ! str_contains($html, '/content-media/')) {
            return $html;
        }

        $context = $this->media->boardStore()->context($slug, null, $post?->id !== null ? (int) $post->id : null);
        if ($post?->board_id !== null) {
            $context = $context->withExtra(['board_id' => (int) $post->board_id]);
        }

        $presented = $this->media->presentContent($context, $html, $signAll);

        return is_string($presented) ? $presented : $html;
    }

    /**
     * 본문 미디어 서명 주소.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $hash  해시
     * @param  DateTimeInterface|null  $expires  만료 시각 (null 이면 설정값)
     * @return string 서명 주소 (같은 출처 상대 경로)
     */
    public function signedUrl(string $slug, string $hash, ?DateTimeInterface $expires = null): string
    {
        return $this->media->signedUrl($this->media->boardStore()->context($slug), $hash, $expires);
    }

    /**
     * 편집기에 넘길 업로드 계약 — 업로드할 수 있는 종류가 없으면 null.
     *
     * 표준 계약 엔드포인트(`…/content-media/target`)와 글쓰기 폼 메타 `content_media`(호환)가 같은 값을 쓴다.
     *
     * @param  Board  $board  게시판
     * @param  bool  $admin  관리자 화면 여부 (업로드 주소)
     * @return array<string, mixed>|null 계약 (`ContentMediaUploadTarget`)
     */
    public function uploadTarget(Board $board, bool $admin): ?array
    {
        return $this->media->uploadTarget($this->media->boardStore()->context((string) $board->slug, $board), $admin);
    }

    /**
     * 서빙할 본문 미디어를 찾고 게이트를 겁니다.
     *
     * - 서명이 검증된 요청: 게이트 통과 자격의 위임으로 보고 통과(임시 행 포함 — 편집 중 미리보기).
     * - 서명 없음: 임시 행은 403. 글에 이어진 행은 요청자의 글 읽기 권한 + 콘텐츠 게이트(삭제·비밀·블라인드).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $hash  해시
     * @param  bool  $signatureVerified  서명 검증 여부
     * @return Attachment 본문 미디어
     *
     * @throws BoardNotFoundException 게시판이 없을 때
     * @throws NotFoundHttpException 없을 때
     * @throws AccessDeniedHttpException 게이트를 통과하지 못할 때
     */
    public function findServable(string $slug, string $hash, bool $signatureVerified): Attachment
    {
        $board = $this->boardOrFail($slug);

        /** @var Attachment $row */
        $row = $this->media->findServable($this->context($slug, $board), $hash, $signatureVerified);

        return $row;
    }

    /**
     * 본문 미디어 서빙 응답 (Range·인라인 판정·보안 헤더, 캐시 private 300초).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  Attachment  $attachment  본문 미디어
     * @param  Request  $request  요청
     * @return Response|null 응답 (파일이 없으면 null)
     *
     * @throws BoardNotFoundException 게시판이 없을 때
     */
    public function respond(string $slug, Attachment $attachment, Request $request): ?Response
    {
        $board = $this->boardOrFail($slug);

        return $this->media->respond($this->context($slug, $board), $attachment, $request);
    }

    /**
     * 다른 게시판 정식 주소 중 복제할 수 있는 것을 이 게시판 주소로 바꿉니다 (#887 게시판 사이 복제).
     *
     * 골격은 이 게시판 서빙 주소만 보고 해시를 뽑고 바꾼다. 다른 게시판 주소는 해시가 같아도 그 게시판 서빙 경로에 놓여 있어,
     * 그대로 복제하면 새 해시가 다른 게시판 경로에 놓여 404 가 된다. 그래서 원본을 찾고(모든 게시판) 복제 게이트(원본 글 게시판의
     * 열람 권한·콘텐츠 게이트, 임시 행은 본인)를 통과한 해시만 이 게시판 주소로 옮겨 골격의 복제 대상이 되게 한다. 통과하지 못한
     * 주소는 그대로 둔다 — 원본 게시판의 게이트를 따르고, 이 글 개수에도 들지 않는다.
     *
     * @param  ContentMediaContext  $context  저장 중인 글의 문맥
     * @param  string  $html  본문
     * @return string 바꾼 본문
     */
    private function adoptOtherBoardReferences(ContentMediaContext $context, string $html): string
    {
        if ($html === '' || ! str_contains($html, '/content-media/')) {
            return $html;
        }

        $store = $this->media->boardStore();
        $pattern = $store->anyBoardServePattern();
        $regex = '#(?<quote>["\'])'.str_replace('#', '\#', $pattern).'(?P=quote)#';
        if (preg_match_all($regex, $html, $matches, PREG_SET_ORDER) === false) {
            return $html;
        }

        // 미디어 태그(img·video·audio·source)의 정식 주소로 쓰인 해시만 — 링크 글자 등은 복제 대상이 아니다
        $tagged = array_flip(ContentMediaCloner::canonicalHashes($html, $pattern));
        $adopt = [];
        foreach ($matches as $match) {
            $hash = $match['hash'];
            if ($match['slug'] === $context->scope || ! isset($tagged[$hash]) || array_key_exists($hash, $adopt)) {
                continue;
            }
            $source = $store->findCloneSource($context, $hash);
            $adopt[$hash] = $source !== null && $store->mayClone($context, $source);
        }

        $adopt = array_filter($adopt);
        if ($adopt === []) {
            return $html;
        }

        $target = '/api/modules/sirsoft-board/boards/'.$context->scope.'/content-media/';

        return preg_replace_callback(
            $regex,
            fn (array $m) => ($m['slug'] !== $context->scope && isset($adopt[$m['hash']]))
                ? $m['quote'].$target.$m['hash'].$m['quote']
                : $m[0],
            $html
        ) ?? $html;
    }

    /**
     * 게시판을 찾습니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return Board 게시판
     *
     * @throws BoardNotFoundException 없을 때
     */
    private function boardOrFail(string $slug): Board
    {
        $board = $this->boardRepository->findBySlug($slug);
        if (! $board) {
            throw new BoardNotFoundException($slug);
        }

        return $board;
    }
}
