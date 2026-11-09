<?php

namespace Modules\Sirsoft\Board\Services;

use App\Contracts\Extension\StorageInterface;
use App\Enums\ContentMediaKind;
use App\Enums\PermissionType;
use App\Exceptions\ContentMediaLimitExceededException;
use App\Extension\Helpers\StorageTransaction;
use App\Rules\ContentMediaFile;
use App\Seo\SeoRenderRequest;
use App\Support\ContentMedia\ContentMediaPolicy;
use App\Support\ContentMedia\ContentMediaReferenceExtractor;
use App\Support\ContentMedia\ContentMediaResponder;
use App\Support\ContentMedia\ContentMediaUploadTarget;
use App\Support\ContentMedia\ContentMediaUrlRewriter;
use App\Support\ImageResizer;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Enums\TriggerType;
use Modules\Sirsoft\Board\Exceptions\BoardNotFoundException;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Repositories\Contracts\AttachmentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 게시판 본문 미디어 서비스 — 편집기 본문에 넣는 이미지·동영상·오디오 (#883 4단계, D4~D8·D26)
 *
 * 저장은 같은 `board_attachments` 테이블의 `collection = content_media` 이다. 파일은 **처음부터 최종 경로**
 * `{slug}/content-media/{Y/m/d}/{uuid}.ext` 에 두고, 임시 행은 `board_id = 0` + 임시 키다. 글 저장 때 본문이 참조한 것만
 * DB 갱신으로 글에 잇는다 — 롤백되면 DB 가 함께 되돌아가고 큰 동영상을 옮기지 않는다. 끝내 잇지 않은 임시 행은
 * `sirsoft-board:prune-attachments` 의 임시 정리가 지운다.
 *
 * 본문 미디어는 첨부 목록·첨부 개수·목록 썸네일에 들지 않고, 개수는 정책의 문서당 최대 개수로 따로 센다(D8). 업로드 판정은
 * 「파일 첨부 사용」(`use_file_upload`)과 무관하게 본문 미디어 정책과 그 게시판의 `attachments.upload` 권한으로 한다(D26).
 *
 * 본문에 저장하는 주소는 서명 없는 정식 주소이고, 응답을 만들 때 서명 주소로 바꾼다(`presentContent`). 서빙은 서명이 있으면
 * 그것을, 없으면 요청자의 **글 읽기 권한**과 첨부와 같은 콘텐츠 게이트(삭제·비밀·블라인드)를 본다.
 */
class PostContentMediaService
{
    use ChecksBoardPermission;

    /** 서빙 라우트 이름 */
    public const SERVE_ROUTE = 'api.modules.sirsoft-board.boards.content-media.show';

    /** 업로드 시점 상한 배수 — 최종 판정은 저장 때 (남용 방지) */
    public const UPLOAD_HEADROOM = 2;

    /** 저장소 카테고리 */
    private const CATEGORY = 'attachments';

    /**
     * @param  AttachmentRepositoryInterface  $repository  첨부 리포지토리
     * @param  BoardRepositoryInterface  $boardRepository  게시판 리포지토리
     * @param  AttachmentService  $attachmentService  첨부 서비스 (소유 판정·콘텐츠 게이트)
     * @param  StorageInterface  $storage  모듈 저장소
     */
    public function __construct(
        private AttachmentRepositoryInterface $repository,
        private BoardRepositoryInterface $boardRepository,
        private AttachmentService $attachmentService,
        private StorageInterface $storage,
    ) {}

    /**
     * 게시판의 본문 미디어 정책 — 게시판 값, 없으면 첨부 정책으로 만든 대체 정책(이미지 켬·동영상·오디오 끔).
     *
     * @param  Board  $board  게시판
     * @return ContentMediaPolicy 정책
     */
    public function resolvePolicy(Board $board): ContentMediaPolicy
    {
        return ContentMediaPolicy::fromArray(
            is_array($board->content_media_policy) ? $board->content_media_policy : null,
            $this->fallbackPolicy($board)
        );
    }

    /**
     * 게시판 첨부 정책으로 만든 대체 정책 (D7·D21).
     *
     * @param  Board  $board  게시판
     * @return ContentMediaPolicy 대체 정책
     */
    public function fallbackPolicy(Board $board): ContentMediaPolicy
    {
        $maxMb = (int) ($board->max_file_size ?? g7_module_settings('sirsoft-board', 'basic_defaults.max_file_size') ?? 10);
        $extensions = $board->allowed_extensions ?? g7_module_settings('sirsoft-board', 'basic_defaults.allowed_extensions') ?? [];

        return ContentMediaPolicy::fromAttachmentSettings(max(1, $maxMb), is_array($extensions) ? $extensions : []);
    }

    /**
     * 이 게시판 본문 미디어 서빙 주소 정규식(구분자 없음, `hash` 그룹).
     *
     * @param  string  $slug  게시판 슬러그
     * @return string 정규식 본문
     */
    public function servePattern(string $slug): string
    {
        return '/api/modules/sirsoft-board/boards/'.preg_quote($slug, '#').'/content-media/(?<hash>[A-Za-z0-9]{12})';
    }

    /**
     * 본문 미디어를 임시로 올립니다 — 파일은 최종 경로, 행은 임시(board_id=0 + 임시 키).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  UploadedFile  $file  파일 (요청 계층이 정책으로 검증함)
     * @param  string  $tempKey  임시 업로드 키
     * @param  string|null  $guestOwnerHash  비회원 소유 쿠키 해시
     * @return Attachment 본문 미디어 행
     *
     * @throws ContentMediaLimitExceededException 임시 키의 업로드가 상한(문서당 최대 × 2)을 넘을 때
     */
    public function upload(string $slug, UploadedFile $file, string $tempKey, ?string $guestOwnerHash = null): Attachment
    {
        $board = $this->boardOrFail($slug);
        $policy = $this->resolvePolicy($board);

        $limit = $policy->maxPerDocument() * self::UPLOAD_HEADROOM;
        $existing = $this->repository->countTempContentMedia($slug, $tempKey);
        if ($existing + 1 > $limit) {
            throw new ContentMediaLimitExceededException($policy->maxPerDocument(), $existing + 1);
        }

        // 서빙 Content-Type 은 내용으로 추론한 값을 쓴다(검증과 같은 기준) — 클라이언트가 보낸 형식을 믿지 않는다
        $mime = (string) ContentMediaFile::sniffMime($file);
        $kind = ContentMediaKind::fromMime($mime);
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $storedFilename = Str::uuid()->toString().'.'.$extension;
        // audit:allow datetime-display-user-timezone reason: 저장 경로(디렉터리 날짜) — 기계값
        $path = "{$slug}/content-media/".date('Y/m/d')."/{$storedFilename}";

        $meta = ['kind' => $kind?->value];
        if ($kind === ContentMediaKind::Image) {
            // 환경설정 > 업로드의 최대 가로/세로·품질 — 첨부 업로드와 같은 축소
            app(ImageResizer::class)->resizeInPlace($file->getRealPath(), $mime);
            $size = @getimagesize($file->getRealPath());
            if ($size) {
                $meta['width'] = $size[0];
                $meta['height'] = $size[1];
            }
        }

        $stream = fopen($file->getRealPath(), 'rb');
        try {
            StorageTransaction::put($this->storage, self::CATEGORY, $path, $stream !== false ? $stream : (string) $file->get());
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $userId = Auth::id();
        $attachment = $this->repository->create($slug, [
            'board_id' => 0,
            'post_id' => null,
            'temp_key' => $tempKey,
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $storedFilename,
            'disk' => $this->storage->getDisk(),
            'path' => $path,
            'mime_type' => $mime,
            'size' => (int) filesize($file->getRealPath()),
            'collection' => Attachment::COLLECTION_CONTENT_MEDIA,
            'order' => 0,
            'meta' => $meta,
            'created_by' => $userId,
            'guest_owner_hash' => $userId === null ? $guestOwnerHash : null,
        ]);

        Log::info('게시판 본문 미디어 업로드 완료', [
            'board_slug' => $slug,
            'attachment_id' => $attachment->id,
            'kind' => $meta['kind'],
            'size' => $attachment->size,
        ]);

        return $attachment;
    }

    /**
     * 글 본문과 본문 미디어를 맞춥니다 — 글 저장 트랜잭션 안에서 부른다.
     *
     * 1. 본문이 참조하는 이 게시판 본문 미디어 해시를 뽑는다(HTML 모드만, 텍스트 모드는 참조 없음).
     * 2. 문서당 최대 개수를 넘으면 `ContentMediaLimitExceededException`(422) — 저장 전체가 되돌아간다.
     * 3. 참조된 임시 본문 미디어 중 같은 임시 키 + 소유 규칙(첨부와 같음)을 통과한 것만 잇는다(DB 갱신).
     * 4. 이 글에 이어져 있었는데 본문에서 빠진 것은 소프트 삭제(삭제 주체 user|admin).
     * 남의 임시 해시·다른 게시판 해시·다른 글의 해시는 무시한다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  Post  $post  저장된 글
     * @param  string|null  $tempKey  요청의 임시 업로드 키
     * @param  string  $context  호출 경로 (user|admin) — 삭제 주체
     * @return array{linked: int, removed: int} 처리 결과
     *
     * @throws ContentMediaLimitExceededException 문서당 최대 개수 초과
     */
    public function syncWithContent(string $slug, Post $post, ?string $tempKey, string $context = 'user'): array
    {
        $board = $this->boardOrFail($slug);
        $policy = $this->resolvePolicy($board);

        $hashes = ($post->content_mode ?? 'text') === 'html'
            ? ContentMediaReferenceExtractor::hashes((string) $post->content, $this->servePattern($slug))
            : [];

        $linked = $this->repository->getContentMediaByPost($board->id, $post->id);
        $linkedHashes = $linked->pluck('hash')->all();

        $candidates = ($tempKey !== null && $tempKey !== '')
            ? $this->attachmentService->filterOwnedTempAttachments(
                $this->repository->getTempContentMediaByHashes($slug, $tempKey, array_values(array_diff($hashes, $linkedHashes)))
            )
            : collect();

        // 본문 미디어로 셀 것: 이미 이 글에 이어진 것 + 이번에 이을 수 있는 것. 남의·다른 게시판 해시는 세지 않는다.
        $countable = array_values(array_intersect($hashes, array_merge($linkedHashes, $candidates->pluck('hash')->all())));
        if (count($countable) > $policy->maxPerDocument()) {
            throw new ContentMediaLimitExceededException($policy->maxPerDocument(), count($countable));
        }

        $order = array_flip($hashes);
        $linkedCount = 0;
        foreach ($candidates as $attachment) {
            $this->repository->linkContentMedia($attachment, $board->id, $post->id, ($order[$attachment->hash] ?? 0) + 1);
            $linkedCount++;
        }

        $removedCount = 0;
        $trigger = $context === 'admin' ? TriggerType::Admin->value : TriggerType::User->value;
        foreach ($linked as $attachment) {
            if (! isset($order[$attachment->hash])) {
                $this->repository->softDeleteContentMedia($attachment, $trigger);
                $removedCount++;
            }
        }

        if ($linkedCount > 0 || $removedCount > 0) {
            Log::info('게시판 본문 미디어 연결 동기화', [
                'board_slug' => $slug,
                'post_id' => $post->id,
                'linked' => $linkedCount,
                'removed' => $removedCount,
            ]);
        }

        return ['linked' => $linkedCount, 'removed' => $removedCount];
    }

    /**
     * 저장 전 본문의 서명 주소를 정식 주소로 되돌립니다(편집 폼이 받은 서명 주소를 그대로 저장하지 않게).
     *
     * @param  string|null  $html  본문
     * @param  string  $slug  게시판 슬러그
     * @return string|null 정규화한 본문
     */
    public function normalizeContent(?string $html, string $slug): ?string
    {
        return ContentMediaUrlRewriter::normalize($html, $this->servePattern($slug));
    }

    /**
     * 응답 본문의 정식 주소를 서명 주소로 바꿉니다(유효 시간 = 코어 설정 `content.media_url_ttl_minutes`).
     *
     * 본문을 받을 자격(비밀·블라인드·삭제 게이트)을 통과한 직렬화에서만 부른다 — 서명은 그 자격의 위임이다.
     *
     * @param  string|null  $html  본문
     * @param  string  $slug  게시판 슬러그
     * @return string|null 서명 주소로 바꾼 본문
     */
    public function presentContent(?string $html, string $slug): ?string
    {
        if ($html === null || $html === '' || ! str_contains($html, '/content-media/')) {
            return $html;
        }

        // 봇(SEO) 렌더는 정식 주소 — 그 HTML 은 캐시되므로 서명 주소가 들어가면 만료 뒤 깨진다(서빙 게이트가 공개 글을 보낸다)
        if (SeoRenderRequest::matches(request())) {
            return $html;
        }

        $expires = now()->addMinutes(ContentMediaUrlRewriter::ttlMinutes());

        return ContentMediaUrlRewriter::sign($html, $this->servePattern($slug), fn (string $hash) => $this->signedUrl($slug, $hash, $expires));
    }

    /**
     * 본문 미디어 서명 주소.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $hash  해시
     * @param  \DateTimeInterface|null  $expires  만료 시각 (null 이면 설정값)
     * @return string 서명 주소 (같은 출처 상대 경로)
     */
    public function signedUrl(string $slug, string $hash, ?\DateTimeInterface $expires = null): string
    {
        return URL::temporarySignedRoute(
            self::SERVE_ROUTE,
            $expires ?? now()->addMinutes(ContentMediaUrlRewriter::ttlMinutes()),
            ['slug' => $slug, 'hash' => $hash],
            absolute: false
        );
    }

    /**
     * 편집기에 넘길 업로드 계약 — 업로드할 수 있는 종류가 없으면 null.
     *
     * @param  Board  $board  게시판
     * @param  bool  $admin  관리자 화면 여부 (업로드 주소)
     * @return array<string, mixed>|null 계약 (`ContentMediaUploadTarget`)
     */
    public function uploadTarget(Board $board, bool $admin): ?array
    {
        $policy = $this->resolvePolicy($board);

        $accepts = false;
        foreach (ContentMediaKind::cases() as $kind) {
            $accepts = $accepts || $policy->acceptsUpload($kind);
        }
        if (! $accepts) {
            return null;
        }

        $endpoint = $admin
            ? "/api/modules/sirsoft-board/admin/board/{$board->slug}/content-media"
            : "/api/modules/sirsoft-board/boards/{$board->slug}/content-media";

        return ContentMediaUploadTarget::toArray($endpoint, $policy, $this->servePattern($board->slug));
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
     * @throws NotFoundHttpException 없을 때
     * @throws AccessDeniedHttpException 게이트를 통과하지 못할 때
     */
    public function findServable(string $slug, string $hash, bool $signatureVerified): Attachment
    {
        $this->boardOrFail($slug);

        $attachment = $this->repository->findContentMediaByHash($slug, $hash);
        if (! $attachment) {
            throw new NotFoundHttpException(__('sirsoft-board::messages.attachment.not_found'));
        }

        if ($signatureVerified) {
            return $attachment;
        }

        if ($attachment->post_id === null) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }

        // 본문 미디어는 본문의 일부라 다운로드 권한이 아니라 글 읽기 권한을 따른다
        if (! $this->checkBoardPermission($slug, 'posts.read', PermissionType::User)
            && ! $this->hasBoardManagePermission($slug)) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }

        $this->attachmentService->assertContentGates($slug, $attachment);

        return $attachment;
    }

    /**
     * 본문 미디어 서빙 응답 (Range·인라인 판정·보안 헤더).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  Attachment  $attachment  본문 미디어
     * @param  Request  $request  요청
     * @return Response|null 응답 (파일이 없으면 null)
     */
    public function respond(string $slug, Attachment $attachment, Request $request): ?Response
    {
        $board = $this->boardOrFail($slug);

        return ContentMediaResponder::respond($this->storageForRow($attachment->disk), self::CATEGORY, $attachment->path, [
            'mime_type' => $attachment->mime_type,
            'filename' => $attachment->original_filename,
            'playable' => ContentMediaResponder::playableUnder($this->resolvePolicy($board), $attachment->mime_type),
            'private' => true,
            'max_age' => 300,
        ], $request);
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

    /**
     * 행 disk 기준 저장소 (디스크 전환 뒤에도 그 행의 실제 위치에서 읽는다).
     *
     * @param  string|null  $disk  행의 disk
     * @return StorageInterface 저장소
     */
    private function storageForRow(?string $disk): StorageInterface
    {
        if ($disk === null || $disk === '' || $disk === $this->storage->getDisk() || config("filesystems.disks.{$disk}") === null) {
            return $this->storage;
        }

        return $this->storage->withDisk($disk);
    }
}
