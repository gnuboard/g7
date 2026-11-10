<?php

namespace Tests\Unit\Support\ContentMedia\Fixtures;

use App\Contracts\ContentMedia\ContentMediaStore;
use App\Enums\ContentMediaKind;
use App\Support\ContentMedia\ContentMediaContext;
use App\Support\ContentMedia\ContentMediaPolicy;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * 시험용 본문 미디어 어댑터 — 메모리 행 목록 + 호출 기록 (#887)
 *
 * 모듈 어댑터(범위 없음, 게시판·쇼핑몰처럼 meta 에 종류를 싣지 않는 쪽과 싣는 쪽을 함께 흉내)와 플러그인 어댑터(범위 = 서빙
 * 주소의 `{scope}` 조각, 비회원 업로드 가능)를 한 클래스로 만든다. 동작 차이는 생성자 인자로 고른다.
 */
class FakeContentMediaStore implements ContentMediaStore
{
    /** @var array<int, FakeContentMediaRow> 행 (id => 행) */
    public array $rows = [];

    /** @var array<int, string> 호출 기록 (`link:{hash}`·`create:{hash}`·`soft:{hash}`·`force:{hash}`) */
    public array $calls = [];

    /** @var string 본문에서 빠진 행 처리 — soft|purge|keep */
    public string $removalMode = 'soft';

    /** @var array<int, int> 요청자가 서명 없이 볼 수 있는 문서 ID */
    public array $viewableDocuments = [];

    /** @var array<int, int> 아무나 볼 수 있는(공개) 문서 ID */
    public array $publicDocuments = [];

    /** @var array<string, mixed>|null 저장된 정책 값 */
    public ?array $policyValue = null;

    /** @var bool 업로드 권한 */
    public bool $uploadAllowed = true;

    /** @var int 다음 행 ID */
    private int $nextId = 1;

    /**
     * 시험용 어댑터를 만듭니다 — 확장 종류·식별자·범위 조각 여부를 고른다.
     *
     * @param  string  $type  확장 종류 (module|plugin)
     * @param  string  $identifier  확장 식별자
     * @param  bool  $scoped  서빙 주소에 범위 조각이 있는지 (플러그인 시험)
     */
    public function __construct(
        private readonly string $type = ContentMediaContext::EXTENSION_MODULE,
        private readonly string $identifier = 'test-notes',
        private readonly bool $scoped = false,
    ) {}

    /**
     * 시험용 문맥을 만듭니다.
     *
     * @param  int|null  $documentId  문서 ID
     * @param  string|null  $tempKey  임시 키
     * @param  int|null  $actorId  요청자
     * @param  string|null  $guestOwnerHash  비회원 소유 해시
     * @return ContentMediaContext 문맥
     */
    public function context(?int $documentId = null, ?string $tempKey = null, ?int $actorId = 7, ?string $guestOwnerHash = null): ContentMediaContext
    {
        return (new ContentMediaContext($this->type, $this->identifier, 'note', $documentId))
            ->withTempKey($tempKey)
            ->withScope($this->scoped ? 'lobby' : null)
            ->withActor($actorId, $guestOwnerHash);
    }

    /**
     * 시험용 행을 바로 넣습니다.
     *
     * @param  array<string, mixed>  $attributes  속성 (hash 를 주지 않으면 만든다)
     * @return FakeContentMediaRow 행
     */
    public function seed(array $attributes): FakeContentMediaRow
    {
        $row = new FakeContentMediaRow(array_replace([
            'id' => $this->nextId++,
            'hash' => Str::random(12),
            'document_id' => null,
            'temp_key' => null,
            'scope' => $this->scoped ? 'lobby' : null,
            'disk' => 'local',
            'path' => 'content-media/seed/'.Str::uuid()->toString().'.png',
            'mime_type' => 'image/png',
            'original_filename' => 'seed.png',
            'stored_filename' => 'seed.png',
            'size' => 3,
            'meta' => null,
            'order' => 0,
            'created_by' => null,
            'guest_owner_hash' => null,
            'deleted' => false,
            'stale' => false,
        ], $attributes));
        $this->rows[(int) $row->getAttribute('id')] = $row;

        return $row;
    }

    /** {@inheritDoc} */
    public function extensionType(): string
    {
        return $this->type;
    }

    /** {@inheritDoc} */
    public function extensionIdentifier(): string
    {
        return $this->identifier;
    }

    /** {@inheritDoc} */
    public function serveRouteName(): string
    {
        return $this->type === ContentMediaContext::EXTENSION_PLUGIN
            ? "api.plugins.{$this->identifier}.notes.content-media.show"
            : "api.modules.{$this->identifier}.content-media.show";
    }

    /** {@inheritDoc} */
    public function serveRouteParameters(ContentMediaContext $context, string $hash): array
    {
        return $this->scoped ? ['scope' => (string) $context->scope, 'hash' => $hash] : ['hash' => $hash];
    }

    /** {@inheritDoc} */
    public function servePattern(ContentMediaContext $context): string
    {
        $base = $this->type === ContentMediaContext::EXTENSION_PLUGIN ? '/api/plugins/' : '/api/modules/';

        return $this->scoped
            ? $base.$this->identifier.'/notes/'.preg_quote((string) $context->scope, '#').'/content-media/(?<hash>[A-Za-z0-9]{12})'
            : $base.$this->identifier.'/content-media/(?<hash>[A-Za-z0-9]{12})';
    }

    /** {@inheritDoc} */
    public function uploadEndpoint(ContentMediaContext $context, bool $admin): string
    {
        $base = $this->type === ContentMediaContext::EXTENSION_PLUGIN ? '/api/plugins/' : '/api/modules/';

        return $base.$this->identifier.($admin ? '/admin' : '').'/content-media';
    }

    /** {@inheritDoc} */
    public function uploadFields(ContentMediaContext $context): array
    {
        return $this->scoped ? ['scope' => (string) $context->scope] : [];
    }

    /** {@inheritDoc} */
    public function policyValue(ContentMediaContext $context): ?array
    {
        return $this->policyValue;
    }

    /** {@inheritDoc} */
    public function fallbackPolicy(ContentMediaContext $context): ContentMediaPolicy
    {
        return ContentMediaPolicy::fromAttachmentSettings(10, ['jpg', 'jpeg', 'png', 'gif', 'mp4']);
    }

    /** {@inheritDoc} */
    public function storageCategory(): string
    {
        return 'attachments';
    }

    /** {@inheritDoc} */
    public function storagePath(ContentMediaContext $context, string $storedFilename): string
    {
        return ($context->scope !== null ? $context->scope.'/' : '').'content-media/2026/11/09/'.$storedFilename;
    }

    /** {@inheritDoc} */
    public function storagePathOf(Model $row): string
    {
        return (string) $row->getAttribute('path');
    }

    /** {@inheritDoc} */
    public function uploadMeta(ContentMediaContext $context, ?ContentMediaKind $kind, ?array $dimensions): ?array
    {
        // 모듈 시험은 게시판·페이지처럼 종류를 싣고, 플러그인 시험은 이전 쇼핑몰처럼 이미지 크기만 싣는다
        if ($this->type === ContentMediaContext::EXTENSION_MODULE) {
            return ['kind' => $kind?->value] + ($dimensions ?? []);
        }

        return $dimensions;
    }

    /** {@inheritDoc} */
    public function countTemp(ContentMediaContext $context): int
    {
        return $this->live()->filter(fn (Model $r) => $r->getAttribute('document_id') === null
            && $r->getAttribute('temp_key') === $context->tempKey)->count();
    }

    /** {@inheritDoc} */
    public function tempRowsByHashes(ContentMediaContext $context, array $hashes): Collection
    {
        return $this->live()->filter(fn (Model $r) => $r->getAttribute('document_id') === null
            && $r->getAttribute('temp_key') === $context->tempKey
            && in_array($r->getAttribute('hash'), $hashes, true))->values();
    }

    /** {@inheritDoc} */
    public function filterLinkableTempRows(ContentMediaContext $context, Collection $rows): Collection
    {
        return $rows->filter(fn (Model $r) => $context->owns($r->getAttribute('created_by'), $r->getAttribute('guest_owner_hash')))->values();
    }

    /** {@inheritDoc} */
    public function ownedRows(ContentMediaContext $context): Collection
    {
        return $this->live()->filter(fn (Model $r) => $r->getAttribute('document_id') === $context->documentId)->values();
    }

    /** {@inheritDoc} */
    public function findByHash(ContentMediaContext $context, string $hash): ?Model
    {
        return $this->live()->first(fn (Model $r) => $r->getAttribute('hash') === $hash
            && (! $this->scoped || $r->getAttribute('scope') === $context->scope));
    }

    /** {@inheritDoc} */
    public function createRow(ContentMediaContext $context, array $attributes, bool $attachToDocument): Model
    {
        $row = $this->seed([
            'document_id' => $attachToDocument ? $context->documentId : null,
            'temp_key' => $attachToDocument ? null : $context->tempKey,
            'scope' => $context->scope,
        ] + $attributes);
        $this->calls[] = 'create:'.$row->getAttribute('hash');

        return $row;
    }

    /** {@inheritDoc} */
    public function linkRow(ContentMediaContext $context, Model $row, int $order): void
    {
        $row->setAttribute('document_id', $context->documentId);
        $row->setAttribute('temp_key', null);
        $row->setAttribute('order', $order);
        $this->calls[] = 'link:'.$row->getAttribute('hash');
    }

    /** {@inheritDoc} */
    public function handleRemoved(ContentMediaContext $context, Model $row, Closure $purge): bool
    {
        if ($this->removalMode === 'keep') {
            return false;
        }

        if ($this->removalMode === 'purge') {
            $purge($row);

            return true;
        }

        $row->setAttribute('deleted', true);
        $this->calls[] = 'soft:'.$row->getAttribute('hash');

        return true;
    }

    /** {@inheritDoc} */
    public function forceDeleteRow(Model $row): void
    {
        unset($this->rows[(int) $row->getAttribute('id')]);
        $this->calls[] = 'force:'.$row->getAttribute('hash');
    }

    /** {@inheritDoc} */
    public function isTemp(Model $row): bool
    {
        return $row->getAttribute('document_id') === null;
    }

    /** {@inheritDoc} */
    public function canView(ContentMediaContext $context, Model $row): bool
    {
        return in_array($row->getAttribute('document_id'), $this->viewableDocuments, true);
    }

    /** {@inheritDoc} */
    public function isPublic(ContentMediaContext $context, ?Model $row = null): bool
    {
        $documentId = $row !== null ? $row->getAttribute('document_id') : $context->documentId;

        return $documentId !== null && in_array($documentId, $this->publicDocuments, true);
    }

    /** {@inheritDoc} */
    public function cacheMaxAge(bool $public): int
    {
        return $public ? 3600 : 300;
    }

    /** {@inheritDoc} */
    public function canUpload(ContentMediaContext $context): bool
    {
        return $this->uploadAllowed;
    }

    /** {@inheritDoc} */
    public function notFoundMessageKey(): string
    {
        return 'attachment.not_found';
    }

    /** {@inheritDoc} */
    public function findCloneSource(ContentMediaContext $context, string $hash): ?Model
    {
        // 복제 범위는 확장 전체(범위 조각과 무관)
        return $this->live()->first(fn (Model $r) => $r->getAttribute('hash') === $hash);
    }

    /** {@inheritDoc} */
    public function mayClone(ContentMediaContext $context, Model $row): bool
    {
        return $this->isTemp($row)
            ? $context->owns($row->getAttribute('created_by'), $row->getAttribute('guest_owner_hash'))
            : $this->canView($context, $row);
    }

    /** {@inheritDoc} */
    public function staleRows(CarbonInterface $threshold, int $limit): Collection
    {
        return collect($this->rows)->filter(fn (Model $r) => (bool) $r->getAttribute('stale'))->take($limit)->values();
    }

    /** {@inheritDoc} */
    public function contextForRow(Model $row): ContentMediaContext
    {
        return (new ContentMediaContext($this->type, $this->identifier, 'note', $row->getAttribute('document_id')))
            ->withScope($row->getAttribute('scope'));
    }

    /**
     * 소프트 삭제되지 않은 행.
     *
     * @return Collection<int, FakeContentMediaRow> 행
     */
    private function live(): Collection
    {
        return collect($this->rows)->reject(fn (Model $r) => (bool) $r->getAttribute('deleted'))->values();
    }
}
