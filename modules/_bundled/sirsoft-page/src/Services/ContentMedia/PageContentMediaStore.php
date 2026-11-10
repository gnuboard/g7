<?php

namespace Modules\Sirsoft\Page\Services\ContentMedia;

use App\Contracts\ContentMedia\ContentMediaStore;
use App\Enums\ContentMediaKind;
use App\Enums\PermissionType;
use App\Models\User;
use App\Support\ContentMedia\ContentMediaContext;
use App\Support\ContentMedia\ContentMediaPolicy;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Repositories\Contracts\PageAttachmentRepositoryInterface;
use Modules\Sirsoft\Page\Repositories\Contracts\PageRepositoryInterface;
use Modules\Sirsoft\Page\Repositories\Contracts\PageVersionRepositoryInterface;
use Modules\Sirsoft\Page\Support\AttachmentLimits;

/**
 * 페이지 본문 미디어 어댑터 — 코어 골격(`AbstractContentMediaService`)에 페이지의 저장소·권한을 답한다 (#887)
 *
 * 저장 표는 `page_attachments` 의 `collection = content_media` 행이다. 행 모양은 #883 그대로다 — 파일은 처음부터
 * `content-media/Y/m/d/{uuid}.ext`(저장소 카테고리 `attachments`), 페이지에 잇기 전까지 `page_id = null` + 임시 키,
 * meta 는 `{kind, width?, height?}`.
 *
 * 게이트(서빙·복제 공통): 발행된 페이지 또는 열람권(`sirsoft-page.pages.read`). 업로드는 관리자 화면 전용이라 비회원 경로가 없다.
 *
 * 제거 정책: 본문에서 빠진 행은 **어느 버전 본문도 그 해시를 참조하지 않을 때만** 영구 삭제한다. 버전이
 * 참조하면 페이지에 붙인 채로 둔다 — 그 버전으로 복원했을 때 미디어가 살아 있어야 한다. 버전 스냅샷은 동기화 뒤에 찍히므로
 * 직전 버전이 늘 옛 본문을 가져, 본문에서 뺀 미디어는 사실상 페이지를 지울 때 함께 지워진다(버전 보존 한도는 두지 않는다).
 */
class PageContentMediaStore implements ContentMediaStore
{
    /** 확장 식별자 */
    public const EXTENSION = 'sirsoft-page';

    /** 문서 종류 */
    public const DOCUMENT_TYPE = 'page';

    /** 서빙 라우트 이름 */
    public const SERVE_ROUTE = 'api.modules.sirsoft-page.pages.content-media.show';

    /** 서빙 주소 앞부분 (정식 주소 = 이 값 + 해시) */
    public const SERVE_PATTERN_URL_PREFIX = '/api/modules/sirsoft-page/pages/content-media/';

    /** 서빙 주소 정규식 본문 */
    public const SERVE_PATTERN = '/api/modules/sirsoft-page/pages/content-media/(?<hash>[A-Za-z0-9]{12})';

    /** 업로드 주소 (관리자 화면 전용) */
    public const UPLOAD_ENDPOINT = '/api/modules/sirsoft-page/admin/content-media';

    /** 저장소 카테고리 */
    public const CATEGORY = 'attachments';

    /** 열람권 — 미발행 페이지의 본문 미디어를 서명 없이 받는다 */
    private const READ_PERMISSION = 'sirsoft-page.pages.read';

    /**
     * @param  PageAttachmentRepositoryInterface  $attachments  첨부 리포지토리 (본문 미디어 행)
     * @param  PageVersionRepositoryInterface  $versions  버전 리포지토리 (제거 정책의 참조 판정)
     * @param  PageRepositoryInterface  $pages  페이지 리포지토리 (문서 공개 판정)
     */
    public function __construct(
        private readonly PageAttachmentRepositoryInterface $attachments,
        private readonly PageVersionRepositoryInterface $versions,
        private readonly PageRepositoryInterface $pages,
    ) {}

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
        return ['hash' => $hash];
    }

    /** {@inheritDoc} */
    public function servePattern(ContentMediaContext $context): string
    {
        return self::SERVE_PATTERN;
    }

    /** {@inheritDoc} */
    public function uploadEndpoint(ContentMediaContext $context, bool $admin): string
    {
        return self::UPLOAD_ENDPOINT;
    }

    /** {@inheritDoc} */
    public function uploadFields(ContentMediaContext $context): array
    {
        return [];
    }

    /** {@inheritDoc} */
    public function policyValue(ContentMediaContext $context): ?array
    {
        $value = g7_module_settings(self::EXTENSION, 'content_media');

        return is_array($value) ? $value : null;
    }

    /**
     * 페이지 첨부 정책으로 만든 대체 정책 — 허용 MIME 을 확장자로 바꾼다. 동영상·오디오는 코어 「본문 콘텐츠」 스위치를 따른다.
     *
     * @param  ContentMediaContext  $context  문맥
     * @return ContentMediaPolicy 대체 정책
     */
    public function fallbackPolicy(ContentMediaContext $context): ContentMediaPolicy
    {
        $extensions = [];
        foreach (AttachmentLimits::allowedTypes() as $mime) {
            foreach (ContentMediaKind::cases() as $kind) {
                foreach ($kind->mimeMap() as $extension => $mimes) {
                    if (in_array(strtolower((string) $mime), $mimes, true)) {
                        $extensions[] = $extension;
                    }
                }
            }
        }

        return ContentMediaPolicy::fromAttachmentSettings(max(1, AttachmentLimits::maxSizeMb()), array_values(array_unique($extensions)));
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
        return 'content-media/'.Carbon::now()->format('Y/m/d').'/'.$storedFilename;
    }

    /** {@inheritDoc} */
    public function storagePathOf(Model $row): string
    {
        return (string) $row->getAttribute('path');
    }

    /** {@inheritDoc} */
    public function uploadMeta(ContentMediaContext $context, ?ContentMediaKind $kind, ?array $dimensions): ?array
    {
        return ['kind' => $kind?->value] + ($dimensions ?? []);
    }

    /** {@inheritDoc} */
    public function countTemp(ContentMediaContext $context): int
    {
        return $this->attachments->countTempContentMedia((string) $context->tempKey);
    }

    /** {@inheritDoc} */
    public function tempRowsByHashes(ContentMediaContext $context, array $hashes): Collection
    {
        return collect($this->attachments->getTempContentMediaByHashes((string) $context->tempKey, $hashes)->all());
    }

    /**
     * 이을 임시 행 — 올린 관리자 본인 것만. 요청자가 없는 실행(콘솔 시더 등)은 같은 임시 키면 잇는다(#883 그대로).
     *
     * @param  ContentMediaContext  $context  문맥
     * @param  Collection<int, Model>  $rows  같은 임시 키의 임시 행
     * @return Collection<int, Model> 이을 행
     */
    public function filterLinkableTempRows(ContentMediaContext $context, Collection $rows): Collection
    {
        if ($context->actorId === null) {
            return $rows->values();
        }

        return $rows->filter(fn (Model $row) => $row->getAttribute('created_by') !== null
            && (int) $row->getAttribute('created_by') === $context->actorId)->values();
    }

    /** {@inheritDoc} */
    public function ownedRows(ContentMediaContext $context): Collection
    {
        if ($context->documentId === null) {
            return collect();
        }

        return collect($this->attachments->getContentMediaByPage($context->documentId)->all());
    }

    /** {@inheritDoc} */
    public function findByHash(ContentMediaContext $context, string $hash): ?Model
    {
        return $this->attachments->findContentMediaByHash($hash);
    }

    /** {@inheritDoc} */
    public function createRow(ContentMediaContext $context, array $attributes, bool $attachToDocument): Model
    {
        return $this->attachments->create([
            'page_id' => $attachToDocument ? $context->documentId : null,
            'temp_key' => $attachToDocument ? null : $context->tempKey,
            'original_filename' => $attributes['original_filename'] ?? '',
            'stored_filename' => $attributes['stored_filename'] ?? '',
            'disk' => $attributes['disk'] ?? null,
            'path' => $attributes['path'] ?? '',
            'mime_type' => $attributes['mime_type'] ?? '',
            'size' => (int) ($attributes['size'] ?? 0),
            'collection' => PageAttachment::COLLECTION_CONTENT_MEDIA,
            'order' => (int) ($attributes['order'] ?? 0),
            'meta' => $attributes['meta'] ?? null,
            'created_by' => $attributes['created_by'] ?? null,
        ]);
    }

    /** {@inheritDoc} */
    public function linkRow(ContentMediaContext $context, Model $row, int $order): void
    {
        /** @var PageAttachment $row */
        $this->attachments->linkContentMedia($row, (int) $context->documentId, $order);
    }

    /**
     * 본문에서 빠진 행 — 어느 버전 본문도 참조하지 않을 때만 영구 삭제, 참조하면 페이지에 붙인 채 둔다.
     *
     * @param  ContentMediaContext  $context  문맥
     * @param  Model  $row  본문에서 빠진 행
     * @param  Closure(Model): void  $purge  골격의 영구 삭제 (`before_delete` → 커밋 뒤 파일 삭제 → 행 삭제)
     * @return bool 지웠으면 true, 버전이 참조해 남겼으면 false
     */
    public function handleRemoved(ContentMediaContext $context, Model $row, Closure $purge): bool
    {
        $pageId = $context->documentId ?? (int) $row->getAttribute('page_id');
        if ($this->versions->referencesHash($pageId, (string) $row->getAttribute('hash'))) {
            return false;
        }

        $purge($row);

        return true;
    }

    /** {@inheritDoc} */
    public function forceDeleteRow(Model $row): void
    {
        /** @var PageAttachment $row */
        $this->attachments->delete($row);
    }

    /** {@inheritDoc} */
    public function isTemp(Model $row): bool
    {
        return $row->getAttribute('page_id') === null;
    }

    /**
     * 서명 없이 볼 수 있는지 — 발행된 페이지, 또는 열람권(`sirsoft-page.pages.read`)이 있는 관리자. 페이지가 없는 행은 거부.
     *
     * @param  ContentMediaContext  $context  문맥 (요청자)
     * @param  Model  $row  문서에 이어진 행
     * @return bool 볼 수 있으면 true
     */
    public function canView(ContentMediaContext $context, Model $row): bool
    {
        /** @var PageAttachment $row */
        $page = $row->page_id !== null ? $row->page : null;
        if ($page === null) {
            return false;
        }

        if ($page->published) {
            return true;
        }

        $user = $this->actor($context);

        return $user !== null && $user->hasPermission(self::READ_PERMISSION, PermissionType::Admin);
    }

    /** {@inheritDoc} */
    public function isPublic(ContentMediaContext $context, ?Model $row = null): bool
    {
        if ($row !== null) {
            /** @var PageAttachment $row */
            return $row->page_id !== null && (bool) ($row->page?->published ?? false);
        }

        if ($context->documentId === null) {
            return false;
        }

        return (bool) ($this->pages->findById($context->documentId)?->published ?? false);
    }

    /** {@inheritDoc} */
    public function cacheMaxAge(bool $public): int
    {
        return $public ? 3600 : 300;
    }

    /**
     * 업로드 권한 — 등록 화면과 수정 화면이 같이 쓰므로 등록·수정 어느 쪽이든(업로드 라우트 권한과 같다).
     *
     * @param  ContentMediaContext  $context  문맥 (요청자)
     * @return bool 올릴 수 있으면 true
     */
    public function canUpload(ContentMediaContext $context): bool
    {
        $user = $this->actor($context);

        return $user !== null
            && $user->hasPermissions(['sirsoft-page.pages.create', 'sirsoft-page.pages.update'], false, PermissionType::Admin);
    }

    /** {@inheritDoc} */
    public function notFoundMessageKey(): string
    {
        return 'sirsoft-page::messages.attachment.not_found';
    }

    /** {@inheritDoc} */
    public function findCloneSource(ContentMediaContext $context, string $hash): ?Model
    {
        // 복제 범위는 모듈 전체 — 페이지 사이
        return $this->attachments->findContentMediaByHash($hash);
    }

    /**
     * 복제 게이트 — 임시 행은 요청자 본인이 올린 것, 다른 페이지에 이어진 행은 서빙 게이트(`canView`)와 같은 기준.
     * 지워진 페이지의 행·이 페이지 소유 행은 복제하지 않는다.
     *
     * @param  ContentMediaContext  $context  저장 중인 페이지의 문맥
     * @param  Model  $row  원본 행
     * @return bool 복제하면 true
     */
    public function mayClone(ContentMediaContext $context, Model $row): bool
    {
        if ($this->isTemp($row)) {
            return $context->actorId !== null
                && $row->getAttribute('created_by') !== null
                && (int) $row->getAttribute('created_by') === $context->actorId;
        }

        if ($context->documentId !== null && (int) $row->getAttribute('page_id') === $context->documentId) {
            return false;
        }

        return $this->canView($context, $row);
    }

    /** {@inheritDoc} */
    public function staleRows(CarbonInterface $threshold, int $limit): Collection
    {
        return collect($this->attachments->findStaleTempContentMedia(Carbon::instance($threshold), $limit)->all());
    }

    /** {@inheritDoc} */
    public function contextForRow(Model $row): ContentMediaContext
    {
        $pageId = $row->getAttribute('page_id');

        return ContentMediaContext::module(self::EXTENSION, self::DOCUMENT_TYPE, $pageId !== null ? (int) $pageId : null)
            ->withTempKey($row->getAttribute('temp_key'));
    }

    /**
     * 문맥의 요청자 — 지금 로그인한 회원이 문맥의 요청자일 때만 돌려준다(권한 판정용).
     *
     * @param  ContentMediaContext  $context  문맥
     * @return User|null 요청자
     */
    private function actor(ContentMediaContext $context): ?User
    {
        if ($context->actorId === null) {
            return null;
        }

        $user = Auth::user();

        return $user instanceof User && (int) $user->getAuthIdentifier() === $context->actorId ? $user : null;
    }
}
