<?php

namespace Modules\Sirsoft\Ecommerce\Services\ContentMedia;

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
use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Enums\ContentMediaOwnerType;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Models\ContentMedia;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ContentMediaRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;

/**
 * 이커머스 본문 미디어 어댑터 — 코어 골격(`AbstractContentMediaService`)에 저장소·권한을 답한다 (#887)
 *
 * 문맥(`ContentMediaContext`)의 문서 종류는 소유 종류 값(`product`·`common_info`), 문서 ID 는 상품·공통 정보 ID 다. 범위(scope)는
 * 쓰지 않는다 — 복제·서빙 범위는 모듈 전체다.
 *
 * - 행: 범용 첨부 표 `ecommerce_attachments` 의 `content_media` 컬렉션(`ContentMedia` 범위 모델, 리포지토리 경유).
 * - 파일: 모듈 저장소 카테고리 `content-media`, 경로 `{owner_type}/Y/m/d/{uuid}.ext`(행 `path` = 카테고리 기준 경로 그대로).
 * - 서명 없이 볼 수 있음: 공통 정보는 누구나, 상품은 전시 중이거나 상품 읽기 권한 관리자.
 * - 아무나 볼 수 있음(public 캐시·정식 주소): 공통 정보, 전시 중 상품. 미공개 상품은 private.
 * - 본문에서 빠진 행: 소프트 삭제 → 빠진 지 보존기간(기본 2일) 뒤 정리 명령이 파일째 지운다.
 * - 업로드: 상품은 상품 등록·수정, 공통 정보는 공통 정보 등록·수정 권한(관리자 전용 — 비회원 경로 없음).
 */
class EcommerceContentMediaStore implements ContentMediaStore
{
    /** 모듈 식별자 */
    public const IDENTIFIER = 'sirsoft-ecommerce';

    /** 서빙 라우트 이름 */
    public const SERVE_ROUTE = 'api.modules.sirsoft-ecommerce.content-media.show';

    /** 서빙 주소 앞부분 (정식 주소 = 이 값 + 해시) */
    public const SERVE_URL_PREFIX = '/api/modules/sirsoft-ecommerce/content-media/';

    /** 서빙 주소 정규식 본문 */
    public const SERVE_PATTERN = '/api/modules/sirsoft-ecommerce/content-media/(?<hash>[A-Za-z0-9]{12})';

    /** 업로드 주소 (관리자 전용) */
    public const UPLOAD_ENDPOINT = '/api/modules/sirsoft-ecommerce/admin/content-media';

    /** 저장소 카테고리 */
    public const CATEGORY = ContentMedia::STORAGE_CATEGORY;

    /** 응답 캐시 수명(초) — 공개·비공개 같은 값(종전과 같음) */
    public const CACHE_MAX_AGE = 300;

    /** 상품 이미지와 같은 대체 확장자 */
    private const FALLBACK_EXTENSIONS = ['jpeg', 'png', 'jpg', 'gif', 'webp'];

    /**
     * @param  ContentMediaRepositoryInterface  $repository  본문 미디어 리포지토리
     * @param  ProductRepositoryInterface  $productRepository  상품 리포지토리 (소유 상품 공개 판정)
     */
    public function __construct(
        private readonly ContentMediaRepositoryInterface $repository,
        private readonly ProductRepositoryInterface $productRepository,
    ) {}

    /**
     * 소유자의 문맥을 만듭니다 — 요청자는 지금 로그인한 회원.
     *
     * @param  ContentMediaOwnerType  $ownerType  소유 종류
     * @param  int|null  $ownerId  소유자 ID (저장 전이면 null)
     * @param  string|null  $tempKey  임시 키
     * @return ContentMediaContext 문맥
     */
    public function context(ContentMediaOwnerType $ownerType, ?int $ownerId = null, ?string $tempKey = null): ContentMediaContext
    {
        $actor = Auth::id();

        return ContentMediaContext::module(self::IDENTIFIER, $ownerType->value, $ownerId)
            ->withTempKey($tempKey)
            ->withActor($actor !== null ? (int) $actor : null);
    }

    /**
     * 문맥의 소유 종류.
     *
     * @param  ContentMediaContext  $context  문맥
     * @return ContentMediaOwnerType 소유 종류
     *
     * @throws InvalidArgumentException 이 모듈의 소유 종류가 아닐 때(개발자 오류)
     */
    public function ownerTypeOf(ContentMediaContext $context): ContentMediaOwnerType
    {
        return ContentMediaOwnerType::tryFrom($context->documentType)
            ?? throw new InvalidArgumentException("Unknown ecommerce content media owner type: {$context->documentType}");
    }

    /**
     * 상품이 공개(전시 중)인지.
     *
     * @param  Product  $product  상품
     * @return bool 공개면 true
     */
    public static function isPublicProduct(Product $product): bool
    {
        return $product->display_status === ProductDisplayStatus::VISIBLE && $product->deleted_at === null;
    }

    /** {@inheritDoc} */
    public function extensionType(): string
    {
        return ContentMediaContext::EXTENSION_MODULE;
    }

    /** {@inheritDoc} */
    public function extensionIdentifier(): string
    {
        return self::IDENTIFIER;
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
        return ['owner_type' => $this->ownerTypeOf($context)->value];
    }

    /** {@inheritDoc} */
    public function policyValue(ContentMediaContext $context): ?array
    {
        $value = g7_module_settings(self::IDENTIFIER, 'product_display.content_media');

        return is_array($value) ? $value : null;
    }

    /** {@inheritDoc} */
    public function fallbackPolicy(ContentMediaContext $context): ContentMediaPolicy
    {
        return ContentMediaPolicy::fromAttachmentSettings(
            max(1, (int) ceil(((int) config('attachment.max_file_size', 10240)) / 1024)),
            self::FALLBACK_EXTENSIONS
        );
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
        return $this->ownerTypeOf($context)->value.'/'.date('Y/m/d').'/'.$storedFilename;
    }

    /** {@inheritDoc} */
    public function storagePathOf(Model $row): string
    {
        return (string) $row->getAttribute('path');
    }

    /** {@inheritDoc} */
    public function uploadMeta(ContentMediaContext $context, ?ContentMediaKind $kind, ?array $dimensions): ?array
    {
        // 종류는 싣지 않는다 — 저장한 MIME 으로 판정한다(`ContentMedia::kind`). 이미지면 가로·세로만.
        return $dimensions;
    }

    /** {@inheritDoc} */
    public function countTemp(ContentMediaContext $context): int
    {
        return $this->repository->countTemp($this->ownerTypeOf($context), (string) $context->tempKey);
    }

    /** {@inheritDoc} */
    public function tempRowsByHashes(ContentMediaContext $context, array $hashes): Collection
    {
        return $this->repository->getTempByHashes($this->ownerTypeOf($context), (string) $context->tempKey, $hashes)->toBase();
    }

    /**
     * {@inheritDoc}
     *
     * 회원 요청은 본인이 올린 것만 잇는다. 요청자가 없는 자리(콘솔 시더 등)는 같은 임시 키를 가진 것을 모두 잇는다(종전과 같음 —
     * 업로드가 관리자 전용이라 비회원 업로드 행은 없다).
     */
    public function filterLinkableTempRows(ContentMediaContext $context, Collection $rows): Collection
    {
        if ($context->actorId === null) {
            return $rows->values();
        }

        return $rows->filter(fn (Model $row) => $context->owns($row->getAttribute('created_by')))->values();
    }

    /** {@inheritDoc} */
    public function ownedRows(ContentMediaContext $context): Collection
    {
        if (! $context->hasDocument()) {
            return collect();
        }

        return $this->repository->getByOwner($this->ownerTypeOf($context), (int) $context->documentId)->toBase();
    }

    /** {@inheritDoc} */
    public function findByHash(ContentMediaContext $context, string $hash): ?Model
    {
        return $this->repository->findByHash($hash);
    }

    /** {@inheritDoc} */
    public function createRow(ContentMediaContext $context, array $attributes, bool $attachToDocument): Model
    {
        return $this->repository->create($this->ownerTypeOf($context), $attachToDocument ? $context->documentId : null, [
            'temp_key' => $attachToDocument ? null : $context->tempKey,
            'original_filename' => $attributes['original_filename'] ?? '',
            'stored_filename' => $attributes['stored_filename'] ?? '',
            'disk' => $attributes['disk'] ?? null,
            'path' => $attributes['path'] ?? '',
            'mime_type' => $attributes['mime_type'] ?? '',
            'size' => (int) ($attributes['size'] ?? 0),
            'meta' => $attributes['meta'] ?? null,
            'order' => (int) ($attributes['order'] ?? 0),
            'created_by' => $attributes['created_by'] ?? null,
        ]);
    }

    /** {@inheritDoc} */
    public function linkRow(ContentMediaContext $context, Model $row, int $order): void
    {
        /** @var ContentMedia $row */
        $this->repository->link($row, (int) $context->documentId, $order);
    }

    /**
     * {@inheritDoc}
     *
     * 소프트 삭제한다 — 같은 저장 트랜잭션·편집 화면이 아직 그 주소를 들고 있을 수 있어 바로 지우지 않고, 빠진 지 보존기간이
     * 지나면 정리 명령(`staleRows`)이 파일째 지운다.
     */
    public function handleRemoved(ContentMediaContext $context, Model $row, Closure $purge): bool
    {
        /** @var ContentMedia $row */
        $this->repository->softDelete($row);

        return true;
    }

    /** {@inheritDoc} */
    public function forceDeleteRow(Model $row): void
    {
        /** @var ContentMedia $row */
        $this->repository->forceDelete($row);
    }

    /** {@inheritDoc} */
    public function isTemp(Model $row): bool
    {
        return $row->getAttribute('attachmentable_id') === null;
    }

    /** {@inheritDoc} */
    public function canView(ContentMediaContext $context, Model $row): bool
    {
        /** @var ContentMedia $row */
        if ($row->owner_type === ContentMediaOwnerType::CommonInfo) {
            return true;
        }
        if ($row->owner_type !== ContentMediaOwnerType::Product || $row->owner_id === null) {
            return false;
        }

        $product = $this->ownerProduct($row->owner_id);
        if ($product === null) {
            return false;
        }

        return self::isPublicProduct($product) || $this->canReadProducts($context);
    }

    /** {@inheritDoc} */
    public function isPublic(ContentMediaContext $context, ?Model $row = null): bool
    {
        if ($row !== null) {
            /** @var ContentMedia $row */
            $ownerType = $row->owner_type;
            $ownerId = $row->owner_id;
        } else {
            $ownerType = ContentMediaOwnerType::tryFrom($context->documentType);
            $ownerId = $context->documentId;
        }

        if ($ownerType === ContentMediaOwnerType::CommonInfo) {
            return true;
        }
        if ($ownerType !== ContentMediaOwnerType::Product || $ownerId === null) {
            return false;
        }

        $product = $this->ownerProduct($ownerId);

        return $product !== null && self::isPublicProduct($product);
    }

    /** {@inheritDoc} */
    public function cacheMaxAge(bool $public): int
    {
        return self::CACHE_MAX_AGE;
    }

    /** {@inheritDoc} */
    public function canUpload(ContentMediaContext $context): bool
    {
        $user = $this->actor($context);
        if ($user === null) {
            return false;
        }

        $resource = $this->ownerTypeOf($context) === ContentMediaOwnerType::Product ? 'products' : 'product-common-infos';

        return $user->hasPermission("sirsoft-ecommerce.{$resource}.create", PermissionType::Admin)
            || $user->hasPermission("sirsoft-ecommerce.{$resource}.update", PermissionType::Admin);
    }

    /** {@inheritDoc} */
    public function notFoundMessageKey(): string
    {
        return 'sirsoft-ecommerce::messages.content_media.not_found';
    }

    /** {@inheritDoc} */
    public function findCloneSource(ContentMediaContext $context, string $hash): ?Model
    {
        return $this->repository->findByHash($hash);
    }

    /**
     * {@inheritDoc}
     *
     * - 임시 행(다른 임시 키·다른 소유 종류): 편집 화면 저장(요청에 임시 키가 있음)이고 요청자 본인이 올린 것만. 임시 키 없이 본문만
     *   보낸 저장(API·프로그램 저장)은 임시 행을 잇지도 복제하지도 않는다 — 「다른 임시 키」를 판정할 기준이 없고, 종전처럼 본문 주소를
     *   그대로 둔다(임시 행은 보존기간 뒤 정리).
     * - 이 소유자 자신의 행: 복제하지 않는다.
     * - 다른 소유자에 이어진 행: 서명 없이 볼 수 있을 때만(`canView` — 공통 정보는 공개, 상품은 전시 중이거나 상품 읽기 권한).
     */
    public function mayClone(ContentMediaContext $context, Model $row): bool
    {
        /** @var ContentMedia $row */
        if ($this->isTemp($row)) {
            return $context->tempKey !== null && $context->tempKey !== ''
                && $context->actorId !== null && $context->owns($row->getAttribute('created_by'));
        }

        if ($row->owner_type === ContentMediaOwnerType::tryFrom($context->documentType) && $row->owner_id === $context->documentId) {
            return false;
        }

        return $this->canView($context, $row);
    }

    /**
     * {@inheritDoc}
     *
     * 끝내 잇지 않은 임시 행(올린 지 보존기간이 지난 것)과 본문에서 빠져 소프트 삭제된 지 보존기간이 지난 행 — 상품·공통 정보 모두.
     */
    public function staleRows(CarbonInterface $threshold, int $limit): Collection
    {
        $threshold = Carbon::instance($threshold);

        return $this->repository->findStaleTemp($threshold, $limit)->toBase()
            ->concat($this->repository->findStaleRemoved($threshold, $limit)->all())
            ->values();
    }

    /** {@inheritDoc} */
    public function contextForRow(Model $row): ContentMediaContext
    {
        /** @var ContentMedia $row */
        $ownerType = $row->owner_type ?? ContentMediaOwnerType::Product;

        return ContentMediaContext::module(self::IDENTIFIER, $ownerType->value, $row->owner_id);
    }

    /**
     * 본문 미디어를 소유한 상품 — 휴지통에 든 상품 포함(공개 판정에서 삭제 여부를 본다).
     *
     * @param  int  $productId  상품 ID
     * @return Product|null 상품
     */
    private function ownerProduct(int $productId): ?Product
    {
        return $this->productRepository->findWithTrashed($productId);
    }

    /**
     * 문맥의 요청자 — 지금 로그인한 회원이 문맥의 요청자일 때 그 인스턴스.
     *
     * 이 모듈의 문맥은 늘 지금 로그인한 회원으로 만든다(`context()`). 다른 회원 ID 를 든 문맥(직접 만든 문맥)은 권한을 주지 않는다.
     *
     * @param  ContentMediaContext  $context  문맥
     * @return User|null 회원 (요청자 없음·다른 회원이면 null)
     */
    private function actor(ContentMediaContext $context): ?User
    {
        if ($context->actorId === null) {
            return null;
        }

        $current = Auth::user();

        return $current instanceof User && (int) $current->getAuthIdentifier() === $context->actorId ? $current : null;
    }

    /**
     * 요청자가 상품 읽기 권한(관리자)을 가졌는지.
     *
     * @param  ContentMediaContext  $context  문맥
     * @return bool 권한이 있으면 true
     */
    private function canReadProducts(ContentMediaContext $context): bool
    {
        $user = $this->actor($context);

        return $user !== null && $user->hasPermission('sirsoft-ecommerce.products.read', PermissionType::Admin);
    }
}
