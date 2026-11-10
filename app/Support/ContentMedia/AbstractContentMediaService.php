<?php

namespace App\Support\ContentMedia;

use App\Contracts\ContentMedia\ContentMediaStore;
use App\Contracts\Extension\StorageInterface;
use App\Enums\ContentMediaKind;
use App\Exceptions\ContentMediaLimitExceededException;
use App\Extension\Helpers\StorageTransaction;
use App\Extension\HookManager;
use App\Extension\Storage\RowDiskStorage;
use App\Rules\ContentMediaFile;
use App\Seo\SeoRenderRequest;
use App\Support\Files\FileContentSniffer;
use App\Support\ImageResizer;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 본문 미디어 코어 골격 — 업로드·저장 동기화·서명·서빙·정리 흐름 (#887)
 *
 * 편집기 본문에 바로 넣는 이미지·동영상·오디오를 다루는 흐름을 한 곳에서 돌린다. 확장(모듈·플러그인)은 저장소·권한 부분만
 * 어댑터(`ContentMediaStore`)로 구현하고, 이 클래스를 상속한 얇은 서비스에 그 어댑터와 자기 저장소를 넘긴다.
 *
 * ```php
 * final class NoteContentMediaService extends AbstractContentMediaService
 * {
 *     public function __construct(NoteContentMediaStore $store, StorageInterface $storage)
 *     {
 *         parent::__construct($store, $storage);
 *     }
 * }
 * ```
 *
 * 흐름:
 * - 업로드: 상한 → `core.content_media.before_upload` → `filter_upload_file`(돌려준 파일은 판정을 다시 거침) → 최종 경로에 저장
 *   → 임시 행 → `after_upload`.
 * - 저장 동기화: 본문 참조 추출 → 개수 판정(복제분 포함) → **연결 → 복제 → 제거** 순서. 제거는 어댑터 정책(`handleRemoved`),
 *   영구 삭제 때만 `before_delete`.
 * - 응답: 이 문서 소유 해시만 서명(관리자 증거 열람 화면만 `signAll`). 봇 렌더·공개 문서는 정식 주소.
 * - 서빙: 서명이면 통과, 아니면 임시 행 403, 어댑터 `canView`. 캐시는 아무나 볼 수 있을 때만 public.
 * - 모든 파일 접근(서빙·복제 원본·제거·정리)은 행에 기록된 디스크(`RowDiskStorage`)로 한다.
 *
 * @since 7.1.5
 */
abstract class AbstractContentMediaService
{
    /** 업로드 시점 상한 배수 — 임시 키당 문서당 최대 × 이 값, 최종 판정은 저장 때 */
    public const UPLOAD_HEADROOM = 2;

    /**
     * @param  ContentMediaStore  $store  확장 어댑터
     * @param  StorageInterface  $storage  확장 저장소 (새 파일을 쓰는 기본 디스크)
     */
    public function __construct(
        protected readonly ContentMediaStore $store,
        protected readonly StorageInterface $storage,
    ) {}

    /**
     * 확장 어댑터.
     *
     * @return ContentMediaStore 어댑터
     */
    public function store(): ContentMediaStore
    {
        return $this->store;
    }

    /**
     * 문맥의 본문 미디어 정책 — 저장된 값, 없으면 어댑터의 대체 정책.
     *
     * @param  ContentMediaContext  $context  문맥
     * @return ContentMediaPolicy 정책
     */
    public function policy(ContentMediaContext $context): ContentMediaPolicy
    {
        return ContentMediaPolicy::fromArray($this->store->policyValue($context), $this->store->fallbackPolicy($context));
    }

    /**
     * 요청자가 이 문맥에 올릴 수 있는지(어댑터 판정).
     *
     * @param  ContentMediaContext  $context  문맥
     * @return bool 올릴 수 있으면 true
     */
    public function canUpload(ContentMediaContext $context): bool
    {
        return $this->store->canUpload($context);
    }

    /**
     * 본문 미디어를 임시로 올립니다 — 파일은 처음부터 최종 경로, 행은 임시(문서 없음 + 임시 키).
     *
     * 요청 계층(FormRequest + `ContentMediaFile`)이 원본 파일을 이미 검증했다는 전제다. `filter_upload_file` 리스너가 있으면
     * 돌려준 파일을 같은 규칙으로 다시 판정한다 — 리스너가 형식을 바꿔 정책을 우회하지 못하게.
     *
     * @param  ContentMediaContext  $context  문맥 (`tempKey` 필수, 요청자 포함)
     * @param  UploadedFile  $file  업로드 파일
     * @return Model 임시 행
     *
     * @throws InvalidArgumentException 임시 키가 없을 때
     * @throws ContentMediaLimitExceededException 임시 키의 업로드가 상한(문서당 최대 × 2)을 넘을 때
     * @throws ValidationException 필터가 돌려준 파일이 정책에 맞지 않을 때
     */
    public function upload(ContentMediaContext $context, UploadedFile $file): Model
    {
        if ($context->tempKey === null || $context->tempKey === '') {
            throw new InvalidArgumentException('Content media upload requires a temp key (developer error).');
        }

        $policy = $this->policy($context);
        $existing = $this->store->countTemp($context);
        if ($existing + 1 > $policy->maxPerDocument() * self::UPLOAD_HEADROOM) {
            throw new ContentMediaLimitExceededException($policy->maxPerDocument(), $existing + 1);
        }

        $context = $context->withKind(ContentMediaKind::fromMime(FileContentSniffer::mimeType($file)));
        HookManager::doAction('core.content_media.before_upload', $file, $context);
        $file = $this->filterUploadFile($file, $context, $policy);

        // 서빙 Content-Type 은 내용으로 추론한 값이다(검증과 같은 기준) — 클라이언트가 보낸 형식을 믿지 않는다
        $mime = (string) FileContentSniffer::mimeType($file);
        $kind = ContentMediaKind::fromMime($mime);
        $context = $context->withKind($kind);

        $storedFilename = Str::uuid()->toString().'.'.strtolower((string) $file->getClientOriginalExtension());
        $path = $this->store->storagePath($context, $storedFilename);

        $dimensions = null;
        if ($kind === ContentMediaKind::Image) {
            // 환경설정 > 업로드의 최대 가로/세로·품질 — 첨부 업로드와 같은 축소
            app(ImageResizer::class)->resizeInPlace($file->getRealPath(), $mime);
            $size = @getimagesize($file->getRealPath());
            $dimensions = $size ? ['width' => $size[0], 'height' => $size[1]] : null;
        }

        $stream = fopen($file->getRealPath(), 'rb');
        try {
            StorageTransaction::put($this->storage, $this->store->storageCategory(), $path, $stream !== false ? $stream : (string) $file->get());
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $row = $this->store->createRow($context, [
            'temp_key' => $context->tempKey,
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $storedFilename,
            'disk' => $this->storage->getDisk(),
            'path' => $path,
            'mime_type' => $mime,
            'size' => (int) filesize($file->getRealPath()),
            'meta' => $this->store->uploadMeta($context, $kind, $dimensions),
            'order' => 0,
            'created_by' => $context->actorId,
            'guest_owner_hash' => $context->actorId === null ? $context->guestOwnerHash : null,
        ], false);

        HookManager::doAction('core.content_media.after_upload', $row, $context);

        Log::info('본문 미디어 업로드 완료', $context->toArray() + ['row_id' => $row->getKey(), 'size' => $row->getAttribute('size')]);

        return $row;
    }

    /**
     * 문서 본문과 본문 미디어를 맞춥니다 — 문서 저장 트랜잭션 안에서 부른다.
     *
     * 1. 본문(문자열 또는 로케일 배열)이 참조하는 이 문맥 서빙 주소의 해시를 뽑는다(HTML 모드만, 텍스트 모드는 참조 없음).
     * 2. 같은 임시 키 + 올린 사람 규칙(`filterLinkableTempRows`)을 통과한 임시 행을 연결 대상으로 고른다.
     * 3. 그 밖의 참조 해시 중 복제 범위(`findCloneSource`)에서 찾고 복제 게이트(`mayClone`)를 통과한 것을 복제 대상으로 고른다.
     * 4. 로케일마다 「이미 이어진 것 + 이을 것 + 복제할 것」이 문서당 최대를 넘으면 422 — 아무것도 바꾸기 전에 판정한다.
     * 5. **연결 → 복제 → 제거** 순서로 처리한다. 제거는 어댑터 정책(`handleRemoved`)이다.
     *
     * 복제가 있으면 반환값 `content` 가 새 해시로 바꾼 본문이다 — 호출자가 문서에 다시 저장한다(같은 트랜잭션).
     *
     * @param  ContentMediaContext  $context  문맥 (`documentId` 필수, 요청의 `tempKey`·요청자 포함)
     * @param  mixed  $content  저장된 본문 (HTML 문자열 또는 로케일 => HTML)
     * @param  bool  $isHtml  HTML 모드 여부
     * @return array{linked: int, removed: int, cloned: int, content: mixed} 처리 결과
     *
     * @throws InvalidArgumentException 문서 ID 가 없을 때
     * @throws ContentMediaLimitExceededException 문서당 최대 개수 초과
     */
    public function syncWithContent(ContentMediaContext $context, mixed $content, bool $isHtml): array
    {
        if (! $context->hasDocument()) {
            throw new InvalidArgumentException('Content media sync requires a document id (developer error).');
        }

        $policy = $this->policy($context);
        $pattern = $this->store->servePattern($context);
        $contents = is_array($content) ? $content : ['_' => $content];

        $all = [];
        $perLocale = [];
        foreach ($contents as $locale => $html) {
            $hashes = $isHtml && is_string($html) ? ContentMediaReferenceExtractor::hashes($html, $pattern) : [];
            $perLocale[$locale] = $hashes;
            foreach ($hashes as $hash) {
                $all[$hash] = true;
            }
        }
        $all = array_keys($all);

        $linked = $this->store->ownedRows($context);
        $linkedHashes = $linked->pluck('hash')->all();

        $candidates = ($context->tempKey !== null && $context->tempKey !== '')
            ? $this->store->filterLinkableTempRows(
                $context,
                $this->store->tempRowsByHashes($context, array_values(array_diff($all, $linkedHashes)))
            )->values()
            : collect();

        // 다른 문서 소유(또는 요청자가 다른 임시 키로 올린) 본문 미디어 — 원본을 볼 수 있는 것만 이 문서 소유로 복제한다
        $known = array_merge($linkedHashes, $candidates->pluck('hash')->all());
        $sources = $isHtml
            ? $this->cloneSources($context, ContentMediaCloner::foreignHashes($contents, $pattern, $known))
            : collect();

        $countable = array_merge($known, $sources->pluck('hash')->all());
        foreach ($perLocale as $hashes) {
            $count = count(array_intersect($hashes, $countable));
            if ($count > $policy->maxPerDocument()) {
                throw new ContentMediaLimitExceededException($policy->maxPerDocument(), $count);
            }
        }

        $order = array_flip($all);

        // 1) 연결
        foreach ($candidates as $row) {
            $this->store->linkRow($context, $row, ($order[$row->getAttribute('hash')] ?? 0) + 1);
        }

        // 2) 복제
        $map = [];
        foreach ($sources as $source) {
            $clone = $this->cloneRow($context, $source, ($order[$source->getAttribute('hash')] ?? 0) + 1);
            if ($clone !== null) {
                $map[(string) $source->getAttribute('hash')] = (string) $clone->getAttribute('hash');
            }
        }

        // 3) 제거
        $removed = 0;
        foreach ($linked as $row) {
            if (isset($order[$row->getAttribute('hash')])) {
                continue;
            }
            if ($this->store->handleRemoved($context, $row, fn (Model $target) => $this->purgeRow($context, $target))) {
                $removed++;
            }
        }

        if ($candidates->isNotEmpty() || $removed > 0 || $map !== []) {
            Log::info('본문 미디어 연결 동기화', $context->toArray() + [
                'linked' => $candidates->count(),
                'removed' => $removed,
                'cloned' => count($map),
            ]);
        }

        return [
            'linked' => $candidates->count(),
            'removed' => $removed,
            'cloned' => count($map),
            'content' => $map !== [] ? ContentMediaCloner::replaceHashes($content, $pattern, $map) : $content,
        ];
    }

    /**
     * 문서에 이어진 본문 미디어 해시 — 응답 본문에서 서명할 대상.
     *
     * @param  ContentMediaContext  $context  문맥 (문서 ID 가 없으면 빈 목록)
     * @return array<int, string> 해시
     */
    public function ownedHashes(ContentMediaContext $context): array
    {
        if (! $context->hasDocument()) {
            return [];
        }

        return array_values(array_map('strval', $this->store->ownedRows($context)->pluck('hash')->all()));
    }

    /**
     * 저장 전 본문의 서명 주소를 정식 주소로 되돌립니다(편집 폼이 받은 서명 주소를 그대로 저장하지 않게).
     *
     * @param  ContentMediaContext  $context  문맥
     * @param  mixed  $content  본문 (HTML 문자열 또는 로케일 => HTML)
     * @return mixed 정규화한 본문 (형태는 입력과 같다)
     */
    public function normalizeContent(ContentMediaContext $context, mixed $content): mixed
    {
        $pattern = $this->store->servePattern($context);
        $normalize = fn ($html) => is_string($html) ? ContentMediaUrlRewriter::normalize($html, $pattern) : $html;

        return is_array($content) ? array_map($normalize, $content) : $normalize($content);
    }

    /**
     * 응답 본문의 정식 주소를 서명 주소로 바꿉니다 — 이 문서가 소유한 해시만.
     *
     * 서명은 「이 문서 본문을 받을 자격」의 위임이다. 다른 문서 소유 해시를 서명하면 남의 비공개 문서 해시를 자기 문서에 넣어
     * 서명 주소를 받아 갈 수 있으므로 정식 주소로 둔다(원본 문서의 게이트를 따른다).
     *
     * - 봇(SEO) 렌더: 서명하지 않는다 — 그 HTML 은 캐시되므로 서명 주소가 들어가면 만료 뒤 깨진다.
     * - 아무나 볼 수 있는 문서(`isPublic`): 서명하지 않는다 — 정적 캐시와 충돌하지 않게 정식 주소.
     * - `$signAll = true`: 관리자 증거 열람 화면(신고 상세 등)만 — 다른 문서 해시까지 모두 서명한다.
     *
     * @param  ContentMediaContext  $context  문맥 (문서 ID)
     * @param  mixed  $content  본문 (HTML 문자열 또는 로케일 => HTML)
     * @param  bool  $signAll  관리자 증거 열람 화면 전용 — 서빙 주소 전부 서명
     * @param  bool|null  $documentPublic  문서 공개 여부를 호출자가 이미 알면 전달(null 이면 어댑터 `isPublic` 으로 판정)
     * @return mixed 바꾼 본문 (형태는 입력과 같다)
     */
    public function presentContent(ContentMediaContext $context, mixed $content, bool $signAll = false, ?bool $documentPublic = null): mixed
    {
        $pattern = $this->store->servePattern($context);
        if (! $this->mentions($content, $pattern) || SeoRenderRequest::matches(request())) {
            return $content;
        }

        $owned = null;
        if (! $signAll) {
            $public = $documentPublic ?? ($context->hasDocument() && $this->store->isPublic($context, null));
            if ($public) {
                return $content;
            }
            $owned = array_flip($this->ownedHashes($context));
            if ($owned === []) {
                return $content;
            }
        }

        $expires = now()->addMinutes(ContentMediaUrlRewriter::ttlMinutes());
        $sign = fn ($html) => is_string($html)
            ? ContentMediaUrlRewriter::sign(
                $html,
                $pattern,
                fn (string $hash, string $canonical) => ($owned === null || isset($owned[$hash])) ? $this->signedUrl($context, $hash, $expires) : $canonical
            )
            : $html;

        return is_array($content) ? array_map($sign, $content) : $sign($content);
    }

    /**
     * 본문 미디어 서명 주소(같은 출처 상대 경로).
     *
     * @param  ContentMediaContext  $context  문맥
     * @param  string  $hash  해시
     * @param  DateTimeInterface|null  $expires  만료 시각 (null 이면 코어 설정 `content.media_url_ttl_minutes`)
     * @return string 서명 주소
     */
    public function signedUrl(ContentMediaContext $context, string $hash, ?DateTimeInterface $expires = null): string
    {
        return URL::temporarySignedRoute(
            $this->store->serveRouteName(),
            $expires ?? now()->addMinutes(ContentMediaUrlRewriter::ttlMinutes()),
            $this->store->serveRouteParameters($context, $hash),
            absolute: false
        );
    }

    /**
     * 편집기에 넘길 업로드 계약 — 지금 받는 종류가 하나도 없으면 null.
     *
     * 값(종류별 허용·크기·형식·문서당 최대)은 확장 정책이고, 코어는 동영상·오디오 업로드 허용 스위치로만 관여한다
     * (`ContentMediaPolicy::acceptsUpload`).
     *
     * @param  ContentMediaContext  $context  문맥
     * @param  bool  $admin  관리자 화면 여부 (업로드 주소)
     * @param  array<string, string>  $headers  함께 보낼 헤더 (허용 목록만 실린다)
     * @return array<string, mixed>|null 계약 (`ContentMediaUploadTarget`)
     */
    public function uploadTarget(ContentMediaContext $context, bool $admin = false, array $headers = []): ?array
    {
        $policy = $this->policy($context);
        foreach (ContentMediaKind::cases() as $kind) {
            if ($policy->acceptsUpload($kind)) {
                return ContentMediaUploadTarget::toArray(
                    $this->store->uploadEndpoint($context, $admin),
                    $policy,
                    $this->store->servePattern($context),
                    $this->store->uploadFields($context),
                    $headers
                );
            }
        }

        return null;
    }

    /**
     * 서빙할 본문 미디어를 찾고 게이트를 겁니다.
     *
     * - 서명이 검증된 요청: 게이트 통과 자격의 위임으로 보고 통과(임시 행 포함 — 편집 중 미리보기).
     * - 서명 없음: 임시 행은 403, 이어진 행은 어댑터 `canView`.
     *
     * @param  ContentMediaContext  $context  문맥 (요청자 포함)
     * @param  string  $hash  해시
     * @param  bool  $signatureVerified  서명 검증 여부
     * @return Model 행
     *
     * @throws NotFoundHttpException 없을 때
     * @throws AccessDeniedHttpException 게이트를 통과하지 못할 때
     */
    public function findServable(ContentMediaContext $context, string $hash, bool $signatureVerified): Model
    {
        $row = $this->store->findByHash($context, $hash);
        if ($row === null) {
            throw new NotFoundHttpException(__($this->store->notFoundMessageKey()));
        }

        if ($signatureVerified) {
            return $row;
        }

        if ($this->store->isTemp($row) || ! $this->store->canView($context, $row)) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }

        return $row;
    }

    /**
     * 본문 미디어 서빙 응답 (Range·인라인 판정·보안 헤더·캐시).
     *
     * 캐시는 서명 없이 아무나 볼 수 있는 행(`isPublic`)만 public, 그 밖(임시 행·비공개 문서·권한이 필요한 문서)은 private 다.
     *
     * @param  ContentMediaContext  $context  문맥
     * @param  Model  $row  행
     * @param  Request  $request  요청 (Range 헤더)
     * @return Response|null 응답 (파일이 없으면 null)
     */
    public function respond(ContentMediaContext $context, Model $row, Request $request): ?Response
    {
        $public = ! $this->store->isTemp($row) && $this->store->isPublic($context, $row);
        $mime = (string) $row->getAttribute('mime_type');

        return ContentMediaResponder::respond($this->storageFor($row), $this->store->storageCategory(), $this->store->storagePathOf($row), [
            'mime_type' => $mime,
            'filename' => (string) $row->getAttribute('original_filename'),
            'playable' => ContentMediaResponder::playableUnder($this->policy($context), $mime),
            'private' => ! $public,
            'max_age' => $this->store->cacheMaxAge($public),
        ], $request);
    }

    /**
     * 행들을 영구 삭제합니다 — 문서 영구 삭제처럼 확장이 지울 행을 고른 경우(소프트 삭제 행 포함).
     *
     * 행마다 `core.content_media.before_delete` → 파일 커밋 뒤 삭제(행 디스크) → 행 삭제. 트랜잭션 밖이면 파일을 바로 지운다.
     *
     * @param  ContentMediaContext  $context  문맥
     * @param  iterable<int, Model>  $rows  지울 행
     * @return int 지운 행 수
     */
    public function purgeRows(ContentMediaContext $context, iterable $rows): int
    {
        $count = 0;
        foreach ($rows as $row) {
            $this->purgeRow($context, $row);
            $count++;
        }

        return $count;
    }

    /**
     * 오래된 본문 미디어를 파일과 함께 영구 삭제합니다 — 확장의 정리 명령이 부른다.
     *
     * 대상은 어댑터 `staleRows`(보존기간이 지난 임시 행, 소프트 삭제 뒤 정리하는 행)다. 행마다 `before_delete` 를 발화하고
     * 행 디스크에서 파일을 지운 뒤 행을 지운다. 파일 삭제가 실패하면 행을 남기고 `failed` 로 센다(다음 실행이 다시 시도).
     *
     * @param  int  $days  보존기간(일)
     * @param  int  $limit  종류마다 최대 건수
     * @param  bool  $dryRun  true 면 세기만
     * @return array{scanned: int, deleted: int, failed: int} 결과
     */
    public function pruneStale(int $days, int $limit, bool $dryRun = false): array
    {
        $rows = $this->store->staleRows(Carbon::now()->subDays($days), $limit);
        $result = ['scanned' => $rows->count(), 'deleted' => 0, 'failed' => 0];
        if ($dryRun) {
            return $result;
        }

        $category = $this->store->storageCategory();
        foreach ($rows as $row) {
            $context = $this->store->contextForRow($row)->withKind(ContentMediaKind::fromMime((string) $row->getAttribute('mime_type')));
            HookManager::doAction('core.content_media.before_delete', $row, $context);

            $storage = $this->storageFor($row);
            $path = $this->store->storagePathOf($row);
            if ($storage->exists($category, $path) && ! $storage->delete($category, $path)) {
                $result['failed']++;

                continue;
            }

            $this->store->forceDeleteRow($row);
            $result['deleted']++;
        }

        return $result;
    }

    /**
     * 행에 기록된 디스크의 저장소 — 기본 디스크를 바꾼 뒤에도 그 행이 실제로 저장된 자리에서 읽고 지운다.
     *
     * @param  Model  $row  행
     * @return StorageInterface 저장소
     */
    public function storageFor(Model $row): StorageInterface
    {
        $disk = $row->getAttribute('disk');

        return RowDiskStorage::resolve($this->storage, is_string($disk) ? $disk : null);
    }

    /**
     * 복제 원본으로 인정할 행 — 어댑터의 복제 범위에서 찾고 복제 게이트를 통과한 것.
     *
     * @param  ContentMediaContext  $context  저장 중인 문서의 문맥
     * @param  array<int, string>  $hashes  복제 후보 해시
     * @return Collection<int, Model> 원본 행
     */
    protected function cloneSources(ContentMediaContext $context, array $hashes): Collection
    {
        if ($hashes === []) {
            return collect();
        }

        return collect($hashes)
            ->map(fn (string $hash) => $this->store->findCloneSource($context, $hash))
            ->filter(fn (?Model $row) => $row !== null && $this->store->mayClone($context, $row))
            ->values();
    }

    /**
     * 원본 행의 파일을 복사해 이 문서 소유의 새 행을 만듭니다 — 파일은 저장 트랜잭션이 되돌아가면 지워진다.
     *
     * @param  ContentMediaContext  $context  저장 중인 문서의 문맥
     * @param  Model  $source  원본 행
     * @param  int  $order  본문 안 순서
     * @return Model|null 새 행 (원본 파일이 없으면 null — 본문 주소는 그대로 둔다)
     */
    protected function cloneRow(ContentMediaContext $context, Model $source, int $order): ?Model
    {
        $storedFilename = ContentMediaCloner::clonedFilename((string) ($source->getAttribute('stored_filename') ?: $this->store->storagePathOf($source)));
        $path = $this->store->storagePath($context, $storedFilename);
        $category = $this->store->storageCategory();

        if (! ContentMediaCloner::copyFile($this->storageFor($source), $category, $this->store->storagePathOf($source), $this->storage, $category, $path)) {
            Log::warning('본문 미디어 복제 실패 — 원본 파일 없음, 본문 주소 유지', $context->toArray() + ['source_row_id' => $source->getKey()]);

            return null;
        }

        return $this->store->createRow($context, [
            'temp_key' => null,
            'original_filename' => $source->getAttribute('original_filename'),
            'stored_filename' => $storedFilename,
            'disk' => $this->storage->getDisk(),
            'path' => $path,
            'mime_type' => $source->getAttribute('mime_type'),
            'size' => (int) $source->getAttribute('size'),
            'meta' => $source->getAttribute('meta'),
            'order' => $order,
            'created_by' => $context->actorId,
            'guest_owner_hash' => null,
        ], true);
    }

    /**
     * 행 하나를 영구 삭제합니다 — `before_delete` → 파일 커밋 뒤 삭제(행 디스크) → 행 삭제.
     *
     * @param  ContentMediaContext  $context  문맥
     * @param  Model  $row  행
     * @return void 반환값 없음
     */
    protected function purgeRow(ContentMediaContext $context, Model $row): void
    {
        $context = $context->withKind(ContentMediaKind::fromMime((string) $row->getAttribute('mime_type')));
        HookManager::doAction('core.content_media.before_delete', $row, $context);

        StorageTransaction::deleteAfterCommit($this->storageFor($row), $this->store->storageCategory(), $this->store->storagePathOf($row));
        $this->store->forceDeleteRow($row);
    }

    /**
     * `filter_upload_file` 를 적용합니다 — 리스너가 있으면 돌려준 파일을 원본과 같은 규칙으로 다시 판정한다.
     *
     * 리스너가 `UploadedFile` 이 아닌 값을 돌려주면 경고를 남기고 원본을 쓴다.
     *
     * @param  UploadedFile  $file  원본 파일
     * @param  ContentMediaContext  $context  문맥
     * @param  ContentMediaPolicy  $policy  정책
     * @return UploadedFile 저장할 파일
     *
     * @throws ValidationException 돌려준 파일이 정책에 맞지 않을 때
     */
    protected function filterUploadFile(UploadedFile $file, ContentMediaContext $context, ContentMediaPolicy $policy): UploadedFile
    {
        if (! array_key_exists(ContentMediaHooks::FILTER_UPLOAD_FILE, HookManager::getFilters())) {
            return $file;
        }

        $filtered = HookManager::applyFilters('core.content_media.filter_upload_file', $file, $context);
        if (! $filtered instanceof UploadedFile) {
            Log::warning('본문 미디어 업로드 파일 필터가 UploadedFile 이 아닌 값을 돌려줌 — 원본을 저장', $context->toArray());

            return $file;
        }

        // 리스너가 형식을 바꿨을 수 있다 — 내용 MIME·종류·확장자·크기를 요청 계층과 같은 규칙(ContentMediaFile)으로 다시 판정한다
        $errors = ContentMediaFile::violations($policy, $filtered);
        if ($errors !== []) {
            throw ValidationException::withMessages(['file' => $errors]);
        }

        return $filtered;
    }

    /**
     * 본문(문자열 또는 로케일 배열)에 이 서빙 주소가 하나라도 있는지 — 없으면 서명 작업(소유 해시 조회)을 건너뛴다.
     *
     * @param  mixed  $content  본문
     * @param  string  $pattern  서빙 경로 정규식 본문
     * @return bool 있으면 true
     */
    private function mentions(mixed $content, string $pattern): bool
    {
        $regex = '#'.str_replace('#', '\#', $pattern).'#';
        foreach (is_array($content) ? $content : [$content] as $html) {
            if (is_string($html) && $html !== '' && preg_match($regex, $html) === 1) {
                return true;
            }
        }

        return false;
    }
}
