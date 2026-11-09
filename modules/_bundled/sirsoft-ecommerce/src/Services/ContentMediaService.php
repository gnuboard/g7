<?php

namespace Modules\Sirsoft\Ecommerce\Services;

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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\ContentMediaOwnerType;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Models\ContentMedia;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ContentMediaRepositoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 이커머스 본문 미디어 서비스 — 상품 설명·상품 공통 정보 편집기 본문의 이미지·동영상·오디오 (#883 4단계)
 *
 * 저장은 `ecommerce_content_media` 다. 파일은 처음부터 최종 경로 `{owner_type}/{Y/m/d}/{uuid}.ext`, 행은 저장 전까지 임시
 * (`owner_id` null + 임시 키)다. 상품·공통 정보 저장 트랜잭션 안에서 본문(로케일마다)이 참조한 것만 잇고, 빠진 것은 소프트 삭제한다.
 *
 * 서빙 게이트: 상품은 「전시 중(visible) 또는 관리자(상품 읽기 권한) 또는 서명」, 공통 정보는 공개. 응답 본문은 공개되지 않은
 * 상품일 때만 서명한다. 정책은 모듈 설정 `product_display.content_media`, 비어 있으면 상품 이미지 규칙(동영상·오디오는 코어 「본문 콘텐츠」 스위치)이다.
 */
class ContentMediaService
{
    /** 서빙 라우트 이름 */
    public const SERVE_ROUTE = 'api.modules.sirsoft-ecommerce.content-media.show';

    /** 서빙 주소 앞부분 (정식 주소 = 이 값 + 해시) */
    public const SERVE_URL_PREFIX = '/api/modules/sirsoft-ecommerce/content-media/';

    /** 서빙 주소 정규식 본문 */
    public const SERVE_PATTERN = '/api/modules/sirsoft-ecommerce/content-media/(?<hash>[A-Za-z0-9]{12})';

    /** 업로드 시점 상한 배수 */
    public const UPLOAD_HEADROOM = 2;

    /** 저장소 카테고리 */
    public const CATEGORY = 'content-media';

    /** 상품 이미지와 같은 대체 확장자 */
    private const FALLBACK_EXTENSIONS = ['jpeg', 'png', 'jpg', 'gif', 'webp'];

    /**
     * @param  ContentMediaRepositoryInterface  $repository  본문 미디어 리포지토리
     * @param  StorageInterface  $storage  모듈 저장소
     */
    public function __construct(
        private ContentMediaRepositoryInterface $repository,
        private StorageInterface $storage,
    ) {}

    /**
     * 본문 미디어 정책 — 모듈 설정 `product_display.content_media`, 없으면 상품 이미지 규칙(동영상·오디오는 코어 「본문 콘텐츠」 스위치).
     *
     * @return ContentMediaPolicy 정책
     */
    public function resolvePolicy(): ContentMediaPolicy
    {
        $value = g7_module_settings('sirsoft-ecommerce', 'product_display.content_media');
        $fallback = ContentMediaPolicy::fromAttachmentSettings(
            max(1, (int) ceil(((int) config('attachment.max_file_size', 10240)) / 1024)),
            self::FALLBACK_EXTENSIONS
        );

        return ContentMediaPolicy::fromArray(is_array($value) ? $value : null, $fallback);
    }

    /**
     * 본문 미디어를 임시로 올립니다.
     *
     * @param  ContentMediaOwnerType  $ownerType  소유 종류
     * @param  UploadedFile  $file  파일 (요청 계층이 정책으로 검증함)
     * @param  string  $tempKey  임시 키
     * @return ContentMedia 본문 미디어 행
     *
     * @throws ContentMediaLimitExceededException 같은 임시 키 업로드가 상한을 넘을 때
     */
    public function upload(ContentMediaOwnerType $ownerType, UploadedFile $file, string $tempKey): ContentMedia
    {
        $policy = $this->resolvePolicy();
        $existing = $this->repository->countTemp($ownerType, $tempKey);
        if ($existing + 1 > $policy->maxPerDocument() * self::UPLOAD_HEADROOM) {
            throw new ContentMediaLimitExceededException($policy->maxPerDocument(), $existing + 1);
        }

        $mime = (string) ContentMediaFile::sniffMime($file);
        $kind = ContentMediaKind::fromMime($mime) ?? ContentMediaKind::Image;
        $storedFilename = Str::uuid()->toString().'.'.strtolower((string) $file->getClientOriginalExtension());
        // audit:allow datetime-display-user-timezone reason: 저장 경로(디렉터리 날짜) — 기계값
        $path = $ownerType->value.'/'.date('Y/m/d').'/'.$storedFilename;

        $meta = null;
        if ($kind === ContentMediaKind::Image) {
            app(ImageResizer::class)->resizeInPlace($file->getRealPath(), $mime);
            $size = @getimagesize($file->getRealPath());
            $meta = $size ? ['width' => $size[0], 'height' => $size[1]] : null;
        }

        $stream = fopen($file->getRealPath(), 'rb');
        try {
            StorageTransaction::put($this->storage, self::CATEGORY, $path, $stream !== false ? $stream : (string) $file->get());
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $this->repository->create([
            'owner_type' => $ownerType->value,
            'owner_id' => null,
            'temp_key' => $tempKey,
            'disk' => $this->storage->getDisk(),
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $storedFilename,
            'mime_type' => $mime,
            'size' => (int) filesize($file->getRealPath()),
            'kind' => $kind->value,
            'meta' => $meta,
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * 본문(로케일 배열 또는 HTML)과 본문 미디어를 맞춥니다 — 저장 트랜잭션 안에서 부른다.
     *
     * @param  ContentMediaOwnerType  $ownerType  소유 종류
     * @param  int  $ownerId  소유자 ID
     * @param  mixed  $content  저장된 본문
     * @param  bool  $isHtml  HTML 모드 여부
     * @param  string|null  $tempKey  요청의 임시 키
     * @return array{linked: int, removed: int} 처리 결과
     *
     * @throws ContentMediaLimitExceededException 문서당 최대 개수 초과
     */
    public function syncWithContent(ContentMediaOwnerType $ownerType, int $ownerId, mixed $content, bool $isHtml, ?string $tempKey): array
    {
        $policy = $this->resolvePolicy();
        $contents = is_array($content) ? $content : ['_' => $content];

        $all = [];
        $perLocale = [];
        foreach ($contents as $locale => $html) {
            $hashes = $isHtml && is_string($html) ? ContentMediaReferenceExtractor::hashes($html, self::SERVE_PATTERN) : [];
            $perLocale[$locale] = $hashes;
            foreach ($hashes as $hash) {
                $all[$hash] = true;
            }
        }
        $all = array_keys($all);

        $linked = $this->repository->getByOwner($ownerType, $ownerId);
        $linkedHashes = $linked->pluck('hash')->all();

        $userId = Auth::id();
        $candidates = ($tempKey !== null && $tempKey !== '')
            ? $this->repository->getTempByHashes($ownerType, $tempKey, array_values(array_diff($all, $linkedHashes)))
                ->filter(fn (ContentMedia $m) => $userId === null || ($m->created_by !== null && (int) $m->created_by === (int) $userId))
                ->values()
            : collect();

        $known = array_merge($linkedHashes, $candidates->pluck('hash')->all());
        foreach ($perLocale as $hashes) {
            $count = count(array_intersect($hashes, $known));
            if ($count > $policy->maxPerDocument()) {
                throw new ContentMediaLimitExceededException($policy->maxPerDocument(), $count);
            }
        }

        foreach ($candidates as $media) {
            $this->repository->link($media, $ownerId);
        }

        $removed = 0;
        $present = array_flip($all);
        foreach ($linked as $media) {
            if (! isset($present[$media->hash])) {
                $this->repository->softDelete($media);
                $removed++;
            }
        }

        if ($candidates->isNotEmpty() || $removed > 0) {
            Log::info('이커머스 본문 미디어 연결 동기화', ['owner_type' => $ownerType->value, 'owner_id' => $ownerId, 'linked' => $candidates->count(), 'removed' => $removed]);
        }

        return ['linked' => $candidates->count(), 'removed' => $removed];
    }

    /**
     * 저장 전 본문(로케일 배열 또는 HTML)의 서명 주소를 정식 주소로 되돌립니다.
     *
     * @param  mixed  $content  본문
     * @return mixed 정규화한 본문
     */
    public function normalizeContent(mixed $content): mixed
    {
        $normalize = fn ($html) => is_string($html) ? ContentMediaUrlRewriter::normalize($html, self::SERVE_PATTERN) : $html;

        return is_array($content) ? array_map($normalize, $content) : $normalize($content);
    }

    /**
     * 응답 본문의 정식 주소를 서명 주소로 바꿉니다 — 공개되지 않은 소유자일 때만(공개면 정식 주소, 봇 렌더도 정식 주소).
     *
     * @param  mixed  $content  본문
     * @param  bool  $public  공개 소유자 여부
     * @return mixed 바꾼 본문
     */
    public function presentContent(mixed $content, bool $public): mixed
    {
        if ($public || SeoRenderRequest::matches(request())) {
            return $content;
        }

        $expires = now()->addMinutes(ContentMediaUrlRewriter::ttlMinutes());
        $sign = fn ($html) => is_string($html) && str_contains($html, '/content-media/')
            ? ContentMediaUrlRewriter::sign($html, self::SERVE_PATTERN, fn (string $hash) => $this->signedUrl($hash, $expires))
            : $html;

        return is_array($content) ? array_map($sign, $content) : $sign($content);
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

    /**
     * 서명 주소.
     *
     * @param  string  $hash  해시
     * @param  \DateTimeInterface|null  $expires  만료 (null 이면 설정값)
     * @return string 서명 주소 (상대 경로)
     */
    public function signedUrl(string $hash, ?\DateTimeInterface $expires = null): string
    {
        return URL::temporarySignedRoute(
            self::SERVE_ROUTE,
            $expires ?? now()->addMinutes(ContentMediaUrlRewriter::ttlMinutes()),
            ['hash' => $hash],
            absolute: false
        );
    }

    /**
     * 편집기에 넘길 업로드 계약 — 받는 종류가 없으면 null.
     *
     * @param  ContentMediaOwnerType  $ownerType  소유 종류
     * @return array<string, mixed>|null 계약
     */
    public function uploadTarget(ContentMediaOwnerType $ownerType): ?array
    {
        $policy = $this->resolvePolicy();
        foreach (ContentMediaKind::cases() as $kind) {
            if ($policy->acceptsUpload($kind)) {
                return ContentMediaUploadTarget::toArray(
                    '/api/modules/sirsoft-ecommerce/admin/content-media',
                    $policy,
                    self::SERVE_PATTERN,
                    ['owner_type' => $ownerType->value]
                );
            }
        }

        return null;
    }

    /**
     * 서빙할 본문 미디어를 찾고 게이트를 겁니다.
     *
     * @param  string  $hash  해시
     * @param  bool  $signatureVerified  서명 검증 여부
     * @return ContentMedia 본문 미디어
     *
     * @throws NotFoundHttpException 없을 때
     * @throws AccessDeniedHttpException 게이트를 통과하지 못할 때
     */
    public function findServable(string $hash, bool $signatureVerified): ContentMedia
    {
        $media = $this->repository->findByHash($hash);
        if (! $media) {
            throw new NotFoundHttpException(__('sirsoft-ecommerce::messages.content_media.not_found'));
        }

        if ($signatureVerified) {
            return $media;
        }

        if ($media->owner_id === null) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }

        if ($media->owner_type === ContentMediaOwnerType::CommonInfo) {
            return $media;
        }

        $product = Product::withTrashed()->find($media->owner_id);
        if ($product && self::isPublicProduct($product)) {
            return $media;
        }

        $user = Auth::user();
        if ($product && $user && $user->hasPermission('sirsoft-ecommerce.products.read', PermissionType::Admin)) {
            return $media;
        }

        throw new AccessDeniedHttpException(__('auth.scope_denied'));
    }

    /**
     * 서빙 응답.
     *
     * @param  ContentMedia  $media  본문 미디어
     * @param  Request  $request  요청
     * @return Response|null 응답
     */
    public function respond(ContentMedia $media, Request $request): ?Response
    {
        return ContentMediaResponder::respond($this->storage, self::CATEGORY, $media->path, [
            'mime_type' => $media->mime_type,
            'filename' => $media->original_filename,
            'playable' => ContentMediaResponder::playableUnder($this->resolvePolicy(), $media->mime_type),
            'private' => $media->owner_id === null,
            'max_age' => 300,
        ], $request);
    }

    /**
     * 끝내 잇지 않은 오래된 임시 본문 미디어를 파일과 함께 지웁니다 (임시 상품 이미지 정리 명령이 함께 부른다).
     *
     * @param  int  $days  보존기간(일)
     * @param  int  $limit  최대 건수
     * @param  bool  $dryRun  true 면 세기만
     * @return array{scanned: int, deleted: int, failed: int} 결과
     */
    public function pruneTempUploads(int $days, int $limit, bool $dryRun = false): array
    {
        $rows = $this->repository->findStaleTemp(Carbon::now()->subDays($days), $limit);
        $result = ['scanned' => $rows->count(), 'deleted' => 0, 'failed' => 0];
        if ($dryRun) {
            return $result;
        }

        foreach ($rows as $media) {
            if ($this->storage->exists(self::CATEGORY, $media->path) && ! $this->storage->delete(self::CATEGORY, $media->path)) {
                $result['failed']++;

                continue;
            }
            $this->repository->forceDelete($media);
            $result['deleted']++;
        }

        return $result;
    }
}
