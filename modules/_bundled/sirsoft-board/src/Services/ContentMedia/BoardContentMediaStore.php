<?php

namespace Modules\Sirsoft\Board\Services\ContentMedia;

use App\Contracts\ContentMedia\ContentMediaStore;
use App\Enums\ContentMediaKind;
use App\Enums\PermissionType;
use App\Support\ContentMedia\ContentMediaContext;
use App\Support\ContentMedia\ContentMediaPolicy;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Board\Enums\TriggerType;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Repositories\Contracts\AttachmentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Services\AttachmentService;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 게시판 본문 미디어 어댑터 — 코어 골격(`AbstractContentMediaService`)에 게시판의 저장소·권한을 답한다 (#887)
 *
 * 행은 `board_attachments` 의 `collection = content_media` 이다(#883 모양 그대로).
 *
 * | 항목 | 게시판 답 |
 * | --- | --- |
 * | 문맥 | 모듈 `sirsoft-board`, 문서 종류 `post`, 범위(`scope`) = 게시판 슬러그 |
 * | 임시 행 | `board_id = 0` + 글 없음 + 임시 키, 경로 `{slug}/content-media/…` 로 게시판을 가린다 |
 * | 저장 경로 | `{slug}/content-media/{Y/m/d}/{uuid}.ext` (카테고리 `attachments`) — 이미 저장된 파일 경로를 바꾸지 않는다 |
 * | meta | `{kind, width?, height?}` |
 * | 제거 정책 | 소프트 삭제(휴지통) — 삭제 주체는 문맥 `extra['trigger']`(user|admin). 파일은 소프트 삭제 첨부 영구 정리가 지운다 |
 * | 열람 게이트 | 그 행이 속한 게시판의 `posts.read`(사용자) 또는 게시판 관리 권한 + 첨부와 같은 콘텐츠 게이트(삭제·비밀·블라인드) |
 * | 복제 범위 | 모든 게시판 — 이어진 행은 열람 게이트, 임시 행은 올린 본인(회원 ID, 비회원은 업로드 소유 쿠키 해시) |
 * | 캐시 | 늘 private 300초 — 열람이 요청자 권한·글 상태에 달려 있어 공유 캐시에 두지 않는다 |
 *
 * 문맥 `extra` 키(게시판 전용): `board`(이미 손에 쥔 `Board` — 다시 조회하지 않게), `board_id`(글 소유 조회용),
 * `trigger`(본문에서 빠진 행의 삭제 주체), `admin`(관리자 화면 업로드 권한 판정).
 */
class BoardContentMediaStore implements ContentMediaStore
{
    use ChecksBoardPermission;

    /** 모듈 식별자 */
    public const EXTENSION = 'sirsoft-board';

    /** 문서 종류 */
    public const DOCUMENT_TYPE = 'post';

    /** 서빙 라우트 이름 */
    public const SERVE_ROUTE = 'api.modules.sirsoft-board.boards.content-media.show';

    /** 저장소 카테고리 */
    public const CATEGORY = 'attachments';

    /** 서빙 주소 앞부분 (게시판 슬러그 앞까지) */
    private const SERVE_PREFIX = '/api/modules/sirsoft-board/boards/';

    /** 서빙 주소 해시 정규식 */
    private const HASH_PATTERN = '(?<hash>[A-Za-z0-9]{12})';

    /** 게시판 슬러그 정규식 (게시판 생성 규칙 `^[a-z][a-z0-9-]*$`) */
    private const SLUG_PATTERN = '[a-z][a-z0-9-]*';

    /**
     * 정리 대상 지정 — 첨부 정리 명령이 이미 고른 행을 골격 정리(`pruneStale`)에 넘길 때만 쓴다
     *
     * @var Collection<int, Model>|null
     */
    private ?Collection $selectedStaleRows = null;

    /**
     * @param  AttachmentRepositoryInterface  $repository  첨부 리포지토리
     * @param  BoardRepositoryInterface  $boardRepository  게시판 리포지토리
     * @param  AttachmentService  $attachmentService  첨부 서비스 (임시 행 소유 판정·콘텐츠 게이트)
     */
    public function __construct(
        private AttachmentRepositoryInterface $repository,
        private BoardRepositoryInterface $boardRepository,
        private AttachmentService $attachmentService,
    ) {}

    /**
     * 게시판 문맥을 만듭니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  Board|null  $board  이미 조회한 게시판 (없으면 필요할 때 조회)
     * @param  int|null  $postId  게시글 ID
     * @return ContentMediaContext 문맥
     */
    public function context(string $slug, ?Board $board = null, ?int $postId = null): ContentMediaContext
    {
        $context = ContentMediaContext::module(self::EXTENSION, self::DOCUMENT_TYPE, $postId)->withScope($slug);

        return $board !== null ? $context->withExtra(['board' => $board, 'board_id' => (int) $board->id]) : $context;
    }

    /**
     * 모든 게시판의 서빙 주소 정규식 본문(명명 그룹 `slug`·`hash`) — 다른 게시판 주소 정규화·게시판 사이 복제 판정용.
     *
     * @return string 정규식 본문
     */
    public function anyBoardServePattern(): string
    {
        return self::SERVE_PREFIX.'(?<slug>'.self::SLUG_PATTERN.')/content-media/'.self::HASH_PATTERN;
    }

    /**
     * 게시판 첨부 정책으로 만든 대체 정책 (#883 D7·D21 — 이미지 켬, 동영상·오디오는 코어 업로드 스위치).
     *
     * @param  Board  $board  게시판
     * @return ContentMediaPolicy 대체 정책
     */
    public function fallbackPolicyFor(Board $board): ContentMediaPolicy
    {
        $maxMb = (int) ($board->max_file_size ?? g7_module_settings('sirsoft-board', 'basic_defaults.max_file_size') ?? 10);
        $extensions = $board->allowed_extensions ?? g7_module_settings('sirsoft-board', 'basic_defaults.allowed_extensions') ?? [];

        return ContentMediaPolicy::fromAttachmentSettings(max(1, $maxMb), is_array($extensions) ? $extensions : []);
    }

    /**
     * 문맥의 게시판 — 문맥이 실어 온 모델, 없으면 슬러그로 조회.
     *
     * @param  ContentMediaContext  $context  문맥
     * @return Board|null 게시판
     */
    public function boardOf(ContentMediaContext $context): ?Board
    {
        $board = $context->extra('board');
        if ($board instanceof Board && $board->slug === $context->scope) {
            return $board;
        }

        return $context->scope !== null ? $this->boardRepository->findBySlug($context->scope) : null;
    }

    /**
     * 정리 명령이 이미 고른 행을 정리 대상으로 지정한 채 실행합니다 — 끝나면 지정을 푼다.
     *
     * 첨부 정리(임시·소프트 삭제·보유기간 파기)는 일반 첨부와 본문 미디어를 한 쿼리로 고른다. 그중 본문 미디어만 골격
     * `pruneStale` 로 넘겨 `core.content_media.before_delete` 를 발화하고, 파일 → 행 순서와 파일 삭제 실패 시 행 보존
     * (다음 실행이 다시 시도)을 그대로 지키게 하는 자리다.
     *
     * @template T
     *
     * @param  Collection<int, Model>  $rows  정리할 행
     * @param  Closure(): T  $run  실행
     * @return T 실행 결과
     */
    public function withSelectedStaleRows(Collection $rows, Closure $run): mixed
    {
        $previous = $this->selectedStaleRows;
        $this->selectedStaleRows = $rows->values();

        try {
            return $run();
        } finally {
            $this->selectedStaleRows = $previous;
        }
    }

    /** {@inheritDoc} */
    public function extensionType(): string
    {
        return ContentMediaContext::EXTENSION_MODULE;
    }

    /** {@inheritDoc} */
    public function extensionIdentifier(): string
    {
        return self::EXTENSION;
    }

    /** {@inheritDoc} */
    public function serveRouteName(): string
    {
        return self::SERVE_ROUTE;
    }

    /** {@inheritDoc} */
    public function serveRouteParameters(ContentMediaContext $context, string $hash): array
    {
        return ['slug' => (string) $context->scope, 'hash' => $hash];
    }

    /** {@inheritDoc} */
    public function servePattern(ContentMediaContext $context): string
    {
        return self::SERVE_PREFIX.preg_quote((string) $context->scope, '#').'/content-media/'.self::HASH_PATTERN;
    }

    /** {@inheritDoc} */
    public function uploadEndpoint(ContentMediaContext $context, bool $admin): string
    {
        return $admin
            ? "/api/modules/sirsoft-board/admin/board/{$context->scope}/content-media"
            : self::SERVE_PREFIX."{$context->scope}/content-media";
    }

    /** {@inheritDoc} */
    public function uploadFields(ContentMediaContext $context): array
    {
        return [];
    }

    /** {@inheritDoc} */
    public function policyValue(ContentMediaContext $context): ?array
    {
        $policy = $this->boardOf($context)?->content_media_policy;

        return is_array($policy) ? $policy : null;
    }

    /** {@inheritDoc} */
    public function fallbackPolicy(ContentMediaContext $context): ContentMediaPolicy
    {
        $board = $this->boardOf($context);

        return $board !== null
            ? $this->fallbackPolicyFor($board)
            : ContentMediaPolicy::fromAttachmentSettings(10, []);
    }

    /** {@inheritDoc} */
    public function storageCategory(): string
    {
        return self::CATEGORY;
    }

    /** {@inheritDoc} */
    public function storagePath(ContentMediaContext $context, string $storedFilename): string
    {
        // audit:allow datetime-display-user-timezone reason: 저장 경로(디렉터리 날짜) — 기계값
        return "{$context->scope}/content-media/".date('Y/m/d')."/{$storedFilename}";
    }

    /** {@inheritDoc} */
    public function storagePathOf(Model $row): string
    {
        return (string) $row->getAttribute('path');
    }

    /** {@inheritDoc} */
    public function uploadMeta(ContentMediaContext $context, ?ContentMediaKind $kind, ?array $dimensions): ?array
    {
        $meta = ['kind' => $kind?->value];
        if ($dimensions !== null) {
            $meta['width'] = $dimensions['width'];
            $meta['height'] = $dimensions['height'];
        }

        return $meta;
    }

    /** {@inheritDoc} */
    public function countTemp(ContentMediaContext $context): int
    {
        return $this->repository->countTempContentMedia((string) $context->scope, (string) $context->tempKey);
    }

    /** {@inheritDoc} */
    public function tempRowsByHashes(ContentMediaContext $context, array $hashes): Collection
    {
        return $this->repository->getTempContentMediaByHashes((string) $context->scope, (string) $context->tempKey, $hashes);
    }

    /**
     * {@inheritDoc}
     *
     * 첨부 임시 키 연결과 같은 소유 규칙이다(`AttachmentService::filterOwnedTempAttachments` — 회원은 본인 업로드, 비회원은
     * 비회원 업로드, 로그인 전 비회원 업로드는 소유 쿠키가 맞을 때).
     */
    public function filterLinkableTempRows(ContentMediaContext $context, Collection $rows): Collection
    {
        return $this->attachmentService->filterOwnedTempAttachments($rows);
    }

    /** {@inheritDoc} */
    public function ownedRows(ContentMediaContext $context): Collection
    {
        if (! $context->hasDocument()) {
            return collect();
        }

        $boardId = $this->boardIdOf($context);

        return $boardId === null ? collect() : $this->repository->getContentMediaByPost($boardId, (int) $context->documentId);
    }

    /** {@inheritDoc} */
    public function findByHash(ContentMediaContext $context, string $hash): ?Model
    {
        return $this->repository->findContentMediaByHash((string) $context->scope, $hash);
    }

    /** {@inheritDoc} */
    public function createRow(ContentMediaContext $context, array $attributes, bool $attachToDocument): Model
    {
        $boardId = $attachToDocument ? $this->boardIdOf($context) : 0;

        return $this->repository->create((string) $context->scope, [
            'board_id' => $boardId ?? 0,
            'post_id' => $attachToDocument ? $context->documentId : null,
            'temp_key' => $attachToDocument ? null : $attributes['temp_key'],
            'original_filename' => $attributes['original_filename'],
            'stored_filename' => $attributes['stored_filename'],
            'disk' => $attributes['disk'],
            'path' => $attributes['path'],
            'mime_type' => $attributes['mime_type'],
            'size' => $attributes['size'],
            'collection' => Attachment::COLLECTION_CONTENT_MEDIA,
            'order' => $attributes['order'],
            'meta' => $attributes['meta'],
            'created_by' => $attributes['created_by'],
            'guest_owner_hash' => $attachToDocument ? null : $attributes['guest_owner_hash'],
        ]);
    }

    /** {@inheritDoc} */
    public function linkRow(ContentMediaContext $context, Model $row, int $order): void
    {
        /** @var Attachment $row */
        $this->repository->linkContentMedia($row, (int) $this->boardIdOf($context), (int) $context->documentId, $order);
    }

    /**
     * {@inheritDoc}
     *
     * 게시판은 휴지통 정책이다 — 행만 소프트 삭제하고(복원·감사 대상) 파일은 소프트 삭제 첨부 영구 정리가 지운다.
     */
    public function handleRemoved(ContentMediaContext $context, Model $row, Closure $purge): bool
    {
        /** @var Attachment $row */
        $trigger = $context->extra('trigger') === TriggerType::Admin->value ? TriggerType::Admin->value : TriggerType::User->value;
        $this->repository->softDeleteContentMedia($row, $trigger);

        return true;
    }

    /** {@inheritDoc} */
    public function forceDeleteRow(Model $row): void
    {
        /** @var Attachment $row */
        $this->repository->forceDelete($row);
    }

    /** {@inheritDoc} */
    public function isTemp(Model $row): bool
    {
        return $row->getAttribute('post_id') === null;
    }

    /**
     * {@inheritDoc}
     *
     * 본문 미디어는 본문의 일부라 다운로드 권한이 아니라 그 행이 속한 게시판의 글 읽기 권한을 따른다.
     */
    public function canView(ContentMediaContext $context, Model $row): bool
    {
        if ($this->isTemp($row)) {
            return false;
        }

        $slug = $this->slugOfRow($context, $row);
        if ($slug === null) {
            return false;
        }

        if (! $this->checkBoardPermission($slug, 'posts.read', PermissionType::User) && ! $this->hasBoardManagePermission($slug)) {
            return false;
        }

        try {
            /** @var Attachment $row */
            $this->attachmentService->assertContentGates($slug, $row);
        } catch (AccessDeniedHttpException) {
            return false;
        }

        return true;
    }

    /** {@inheritDoc} */
    public function isPublic(ContentMediaContext $context, ?Model $row = null): bool
    {
        return false;
    }

    /** {@inheritDoc} */
    public function cacheMaxAge(bool $public): int
    {
        return 300;
    }

    /**
     * {@inheritDoc}
     *
     * 글쓰기 폼 메타의 계약과 같은 판정이다 — 사용자 화면은 `attachments.upload`, 관리자 화면(`extra['admin']`)은
     * `admin.attachments.upload`. 「파일 첨부 사용」은 보지 않는다(#883 D26).
     */
    public function canUpload(ContentMediaContext $context): bool
    {
        $slug = (string) $context->scope;

        return $context->extra('admin') === true
            ? $this->checkBoardPermission($slug, 'admin.attachments.upload')
            : $this->checkBoardPermission($slug, 'attachments.upload', PermissionType::User);
    }

    /** {@inheritDoc} */
    public function notFoundMessageKey(): string
    {
        return 'sirsoft-board::messages.attachment.not_found';
    }

    /**
     * {@inheritDoc}
     *
     * 복제 범위는 모든 게시판이다 — 다른 게시판 글의 미디어도 원본을 볼 수 있으면 이 글 소유로 복제한다.
     */
    public function findCloneSource(ContentMediaContext $context, string $hash): ?Model
    {
        return $this->repository->findContentMediaByHashInAnyBoard($hash);
    }

    /**
     * {@inheritDoc}
     *
     * - 임시 행(다른 임시 키): 올린 본인만 — 회원은 올린 회원 ID, 비회원은 업로드 소유 쿠키 해시가 같을 때.
     * - 이어진 행: 원본 글이 속한 게시판 기준의 열람 게이트(`canView`) — 복제가 열람 권한을 넓히지 않게.
     */
    public function mayClone(ContentMediaContext $context, Model $row): bool
    {
        if ($this->isTemp($row)) {
            return $context->owns($row->getAttribute('created_by'), $row->getAttribute('guest_owner_hash'));
        }

        return $this->canView($context, $row);
    }

    /**
     * {@inheritDoc}
     *
     * 정리 명령이 행을 지정했으면(`withSelectedStaleRows`) 그 행을, 아니면 기준 시각 전의 임시 본문 미디어와 소프트 삭제된
     * 본문 미디어를 돌려준다.
     */
    public function staleRows(CarbonInterface $threshold, int $limit): Collection
    {
        if ($this->selectedStaleRows !== null) {
            return $this->selectedStaleRows;
        }

        return $this->repository->findStaleContentMedia(Carbon::instance($threshold), $limit);
    }

    /** {@inheritDoc} */
    public function contextForRow(Model $row): ContentMediaContext
    {
        $postId = $row->getAttribute('post_id');
        $context = ContentMediaContext::module(self::EXTENSION, self::DOCUMENT_TYPE, $postId !== null ? (int) $postId : null);
        $slug = $this->slugOfRow($context, $row);
        $boardId = (int) $row->getAttribute('board_id');

        return $context->withScope($slug)->withExtra($boardId > 0 ? ['board_id' => $boardId] : []);
    }

    /**
     * 문맥 게시판의 ID — 문맥이 실어 온 값, 없으면 슬러그로 조회.
     *
     * @param  ContentMediaContext  $context  문맥
     * @return int|null 게시판 ID
     */
    private function boardIdOf(ContentMediaContext $context): ?int
    {
        $boardId = $context->extra('board_id');
        if (is_int($boardId) && $boardId > 0) {
            return $boardId;
        }

        $board = $this->boardOf($context);

        return $board !== null ? (int) $board->id : null;
    }

    /**
     * 행이 속한 게시판의 슬러그 — 문맥 게시판의 행이면 문맥 범위, 다른 게시판 글의 행이면 그 게시판, 임시 행이면 경로 앞부분.
     *
     * @param  ContentMediaContext  $context  문맥
     * @param  Model  $row  행
     * @return string|null 게시판 슬러그
     */
    private function slugOfRow(ContentMediaContext $context, Model $row): ?string
    {
        $boardId = (int) $row->getAttribute('board_id');
        if ($boardId === 0) {
            $path = (string) $row->getAttribute('path');
            $position = strpos($path, '/content-media/');

            return $position !== false && $position > 0 ? substr($path, 0, $position) : $context->scope;
        }

        if ($context->scope !== null && $this->boardIdOf($context) === $boardId) {
            return $context->scope;
        }

        return $this->boardRepository->find($boardId)?->slug;
    }
}
