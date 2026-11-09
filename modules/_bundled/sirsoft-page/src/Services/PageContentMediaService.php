<?php

namespace Modules\Sirsoft\Page\Services;

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
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Repositories\Contracts\PageAttachmentRepositoryInterface;
use Modules\Sirsoft\Page\Support\AttachmentLimits;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 페이지 본문 미디어 서비스 — 관리자 페이지 편집기 본문의 이미지·동영상·오디오 (#883 4단계)
 *
 * 저장은 `page_attachments` 의 `collection = content_media` 다. 파일은 처음부터 최종 경로 `content-media/{Y/m/d}/{uuid}.ext`,
 * 행은 페이지에 잇기 전까지 `page_id = null` + 임시 키다. 페이지 저장 트랜잭션 안에서 본문(로케일마다)이 참조한 것만 DB 갱신으로
 * 잇고, 본문에서 빠진 것은 지운다(행 삭제 + 커밋 뒤 파일 삭제). 본문 미디어는 첨부 목록·첨부 개수에 들지 않는다.
 *
 * 서빙 게이트는 「발행 또는 열람권(`sirsoft-page.pages.read`) 또는 서명」(기존 미리보기와 같음). 서명은 미발행일 때만 한다 —
 * 발행된 페이지는 정적 캐시와 충돌하지 않게 정식 주소를 쓴다.
 *
 * 정책은 모듈 설정 `content_media`, 비어 있으면 페이지 첨부 정책(파일당 MB·허용 형식 중 이미지)이다(D7).
 */
class PageContentMediaService
{
    /** 서빙 라우트 이름 */
    public const SERVE_ROUTE = 'api.modules.sirsoft-page.pages.content-media.show';

    /** 서빙 주소 앞부분 (정식 주소 = 이 값 + 해시) */
    public const SERVE_PATTERN_URL_PREFIX = '/api/modules/sirsoft-page/pages/content-media/';

    /** 서빙 주소 정규식 본문 */
    public const SERVE_PATTERN = '/api/modules/sirsoft-page/pages/content-media/(?<hash>[A-Za-z0-9]{12})';

    /** 업로드 시점 상한 배수 */
    public const UPLOAD_HEADROOM = 2;

    /** 저장소 카테고리 */
    private const CATEGORY = 'attachments';

    /**
     * @param  PageAttachmentRepositoryInterface  $repository  첨부 리포지토리
     * @param  StorageInterface  $storage  모듈 저장소
     */
    public function __construct(
        private PageAttachmentRepositoryInterface $repository,
        private StorageInterface $storage,
    ) {}

    /**
     * 페이지 본문 미디어 정책 — 모듈 설정 `content_media`, 없으면 첨부 정책으로 만든 대체 정책.
     *
     * @return ContentMediaPolicy 정책
     */
    public function resolvePolicy(): ContentMediaPolicy
    {
        $value = g7_module_settings('sirsoft-page', 'content_media');

        return ContentMediaPolicy::fromArray(is_array($value) ? $value : null, $this->fallbackPolicy());
    }

    /**
     * 페이지 첨부 정책으로 만든 대체 정책 — 허용 MIME 을 확장자로 바꾼다. 동영상·오디오는 코어 「본문 콘텐츠」 스위치를 따른다.
     *
     * @return ContentMediaPolicy 대체 정책
     */
    public function fallbackPolicy(): ContentMediaPolicy
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

    /**
     * 본문 미디어를 임시로 올립니다 — 파일은 최종 경로, 행은 임시(page_id=null + 임시 키).
     *
     * @param  UploadedFile  $file  파일 (요청 계층이 정책으로 검증함)
     * @param  string  $tempKey  임시 키
     * @return PageAttachment 본문 미디어 행
     *
     * @throws ContentMediaLimitExceededException 같은 임시 키 업로드가 상한(문서당 최대 × 2)을 넘을 때
     */
    public function upload(UploadedFile $file, string $tempKey): PageAttachment
    {
        $policy = $this->resolvePolicy();
        $existing = $this->repository->countTempContentMedia($tempKey);
        if ($existing + 1 > $policy->maxPerDocument() * self::UPLOAD_HEADROOM) {
            throw new ContentMediaLimitExceededException($policy->maxPerDocument(), $existing + 1);
        }

        $mime = (string) ContentMediaFile::sniffMime($file);
        $kind = ContentMediaKind::fromMime($mime);
        $storedFilename = Str::uuid()->toString().'.'.strtolower((string) $file->getClientOriginalExtension());
        // audit:allow datetime-display-user-timezone reason: 저장 경로(디렉터리 날짜) — 기계값
        $path = 'content-media/'.date('Y/m/d').'/'.$storedFilename;

        $meta = ['kind' => $kind?->value];
        if ($kind === ContentMediaKind::Image) {
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

        return $this->repository->create([
            'page_id' => null,
            'temp_key' => $tempKey,
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $storedFilename,
            'disk' => $this->storage->getDisk(),
            'path' => $path,
            'mime_type' => $mime,
            'size' => (int) filesize($file->getRealPath()),
            'collection' => PageAttachment::COLLECTION_CONTENT_MEDIA,
            'order' => 0,
            'meta' => $meta,
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * 페이지 본문(로케일마다)과 본문 미디어를 맞춥니다 — 페이지 저장 트랜잭션 안에서 부른다.
     *
     * 참조된 임시 본문 미디어 중 같은 임시 키 + 올린 사람이 같은 것만 잇고, 이 페이지에 이어져 있었는데 어느 로케일 본문에도 없는 것은
     * 지운다(행 삭제, 파일은 커밋 뒤). 문서당 최대 개수는 로케일마다 센다.
     *
     * @param  Page  $page  저장된 페이지
     * @param  string|null  $tempKey  요청의 임시 키
     * @return array{linked: int, removed: int} 처리 결과
     *
     * @throws ContentMediaLimitExceededException 문서당 최대 개수 초과
     */
    public function syncWithContent(Page $page, ?string $tempKey): array
    {
        $policy = $this->resolvePolicy();
        $contents = is_array($page->content) ? $page->content : [];
        $isHtml = ($page->content_mode ?? 'html') === 'html';

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

        $linked = $this->repository->getContentMediaByPage($page->id);
        $linkedHashes = $linked->pluck('hash')->all();

        $userId = Auth::id();
        $candidates = ($tempKey !== null && $tempKey !== '')
            ? $this->repository->getTempContentMediaByHashes($tempKey, array_values(array_diff($all, $linkedHashes)))
                ->filter(fn (PageAttachment $a) => $userId === null || ($a->created_by !== null && (int) $a->created_by === (int) $userId))
                ->values()
            : collect();

        $known = array_merge($linkedHashes, $candidates->pluck('hash')->all());
        foreach ($perLocale as $hashes) {
            $count = count(array_intersect($hashes, $known));
            if ($count > $policy->maxPerDocument()) {
                throw new ContentMediaLimitExceededException($policy->maxPerDocument(), $count);
            }
        }

        $order = array_flip($all);
        foreach ($candidates as $attachment) {
            $this->repository->linkContentMedia($attachment, $page->id, ($order[$attachment->hash] ?? 0) + 1);
        }

        $removed = 0;
        foreach ($linked as $attachment) {
            if (! isset($order[$attachment->hash])) {
                StorageTransaction::deleteAfterCommit($this->storage, self::CATEGORY, $attachment->path);
                $this->repository->delete($attachment);
                $removed++;
            }
        }

        if ($candidates->isNotEmpty() || $removed > 0) {
            Log::info('페이지 본문 미디어 연결 동기화', ['page_id' => $page->id, 'linked' => $candidates->count(), 'removed' => $removed]);
        }

        return ['linked' => $candidates->count(), 'removed' => $removed];
    }

    /**
     * 저장 전 본문(로케일 배열)의 서명 주소를 정식 주소로 되돌립니다.
     *
     * @param  mixed  $content  본문 (로케일 => HTML)
     * @return mixed 정규화한 본문
     */
    public function normalizeContent(mixed $content): mixed
    {
        if (! is_array($content)) {
            return is_string($content) ? ContentMediaUrlRewriter::normalize($content, self::SERVE_PATTERN) : $content;
        }

        return array_map(fn ($html) => is_string($html) ? ContentMediaUrlRewriter::normalize($html, self::SERVE_PATTERN) : $html, $content);
    }

    /**
     * 응답 본문의 정식 주소를 서명 주소로 바꿉니다 — 미발행 페이지만(발행 페이지는 정식 주소, 봇 렌더도 정식 주소).
     *
     * @param  mixed  $content  본문 (로케일 => HTML 또는 HTML)
     * @param  bool  $published  발행 여부
     * @return mixed 바꾼 본문
     */
    public function presentContent(mixed $content, bool $published): mixed
    {
        if ($published || SeoRenderRequest::matches(request())) {
            return $content;
        }

        $expires = now()->addMinutes(ContentMediaUrlRewriter::ttlMinutes());
        $sign = fn ($html) => is_string($html) && str_contains($html, '/content-media/')
            ? ContentMediaUrlRewriter::sign($html, self::SERVE_PATTERN, fn (string $hash) => $this->signedUrl($hash, $expires))
            : $html;

        return is_array($content) ? array_map($sign, $content) : $sign($content);
    }

    /**
     * 본문 미디어 서명 주소.
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
     * 관리자 편집기에 넘길 업로드 계약 — 받는 종류가 없으면 null.
     *
     * @return array<string, mixed>|null 계약
     */
    public function uploadTarget(): ?array
    {
        $policy = $this->resolvePolicy();
        foreach (ContentMediaKind::cases() as $kind) {
            if ($policy->acceptsUpload($kind)) {
                return ContentMediaUploadTarget::toArray('/api/modules/sirsoft-page/admin/content-media', $policy, self::SERVE_PATTERN);
            }
        }

        return null;
    }

    /**
     * 서빙할 본문 미디어를 찾고 게이트를 겁니다 — 발행 또는 열람권 또는 서명.
     *
     * @param  string  $hash  해시
     * @param  bool  $signatureVerified  서명 검증 여부
     * @return PageAttachment 본문 미디어
     *
     * @throws NotFoundHttpException 없을 때
     * @throws AccessDeniedHttpException 게이트를 통과하지 못할 때
     */
    public function findServable(string $hash, bool $signatureVerified): PageAttachment
    {
        $attachment = $this->repository->findContentMediaByHash($hash);
        if (! $attachment) {
            throw new NotFoundHttpException(__('sirsoft-page::messages.attachment.not_found'));
        }

        if ($signatureVerified) {
            return $attachment;
        }

        $page = $attachment->page_id !== null ? $attachment->page : null;
        if ($page && $page->published) {
            return $attachment;
        }

        $user = Auth::user();
        if ($page && $user && $user->hasPermission('sirsoft-page.pages.read', PermissionType::Admin)) {
            return $attachment;
        }

        throw new AccessDeniedHttpException(__('auth.scope_denied'));
    }

    /**
     * 본문 미디어 서빙 응답.
     *
     * @param  PageAttachment  $attachment  본문 미디어
     * @param  Request  $request  요청
     * @return Response|null 응답
     */
    public function respond(PageAttachment $attachment, Request $request): ?Response
    {
        $published = (bool) ($attachment->page?->published ?? false);

        return ContentMediaResponder::respond($this->storage, self::CATEGORY, $attachment->path, [
            'mime_type' => $attachment->mime_type,
            'filename' => $attachment->original_filename,
            'playable' => ContentMediaResponder::playableUnder($this->resolvePolicy(), $attachment->mime_type),
            'private' => ! $published,
            'max_age' => $published ? 3600 : 300,
        ], $request);
    }
}
