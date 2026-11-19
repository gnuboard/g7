<?php

namespace App\Extension\Storage\Concerns;

use App\Thumbnails\ThumbnailGenerator;
use App\Thumbnails\ThumbnailPregenerator;
use App\Thumbnails\ThumbnailStore;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 스토리지 드라이버의 썸네일 수명주기 연동 trait (코어·모듈·플러그인 드라이버 공용)
 *
 * 원본을 덮어쓰거나 지우면 그 파생본도 지운다. trait 은 클래스 메서드를 가로챌 수 없으므로 각 드라이버의
 * `put`·`delete`·`deleteDirectory`·`deleteAll` 본문에서 이미 계산한 `$fullPath` 로 한 줄씩 부른다.
 *
 * 건너뛰기는 디스크 이름이 아니라 **경로**로 판정한다 — `thumbnails/` 로 시작하는 경로(파생본 자신)만 건너뛴다.
 * 공개 썸네일 디스크로 `public`·`s3` 를 골라도 그 디스크의 원본 무효화가 꺼지지 않게.
 *
 * 연동 실패는 저장소 동작을 실패로 만들지 않는다(경고 로그만) — 썸네일은 캐시다.
 *
 * 썸네일이 꺼져 있으면(`core.thumbnail.enabled=false`) put 연동은 건너뛰고 삭제 연동만 한다. 끈 동안 덮어쓴 원본의
 * 옛 파생본은 설정을 다시 켤 때 `PurgeThumbnailsOnReenableJob` 이 비운다.
 *
 * 원본 공개 주소(CDN)의 무효화는 확장자와 무관하다 — 래스터가 아닌 원본(PDF 등)도 덮어쓰거나 지우면 그 주소를 퍼지 묶음에 싣는다.
 */
trait SyncsThumbnails
{
    /**
     * 래스터 이미지 확장자 (파생본 무효화 대상 — 원본 공개 주소 무효화는 모든 확장자)
     *
     * @var list<string>
     */
    private static array $thumbnailRasterExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp'];

    /** 쓰기 전 판정: 이미 있는 원본을 덮어쓴다 (판정 실패도 이쪽 — 파생본이 남는 쪽보다 요청 1회가 싸다) */
    private const THUMBNAIL_PUT_OVERWRITE = 'overwrite';

    /** 쓰기 전 판정을 뺐다 — 새 파일인지 모른다 (판정 요청보다 재확인 요청이 싸거나 같을 때) */
    private const THUMBNAIL_PUT_UNCHECKED = 'unchecked';

    /** 썸네일이 꺼진 채 공개 주소가 있는 원본을 덮어쓴다 — 파생본은 두고 원본 CDN 캐시만 무효화한다 */
    private const THUMBNAIL_PUT_ORIGINAL_ONLY = 'original_only';

    /**
     * 쓰기 전 판정 결과 (경로 → 덮어쓰기·판정 안 함·원본 주소만, 없으면 판정으로 새 파일임을 안 경로)
     *
     * @var array<string, string>
     */
    private array $thumbnailOverwrites = [];

    /**
     * {@inheritDoc}
     */
    public function thumbnailSourceKey(string $category, string $path): array
    {
        return ['disk' => $this->disk, 'path' => $this->resolvePath($category, $path)];
    }

    /**
     * {@inheritDoc}
     */
    public function copyToLocalTemp(string $category, string $path): ?string
    {
        return app(ThumbnailGenerator::class)->copyToTemp($this->disk, $this->resolvePath($category, $path));
    }

    /**
     * 쓰기 전 — 원본이 이미 있는지(덮어쓰기인지) 판정만 한다. 파생본 삭제는 쓴 뒤 한 번에 한다.
     *
     * 썸네일이 꺼져 있거나 래스터가 아닌 원본(PDF 등)이면 파생본 연동을 하지 않는다. 원본에 공개 주소(CDN)가 있을 때만 판정해 덮어쓴 원본 주소의
     * CDN 무효화를 남긴다(원본 캐시는 썸네일 기능과 별개다). 끈 동안 덮어쓴 원본의 옛 파생본은 다시 켤 때
     * `PurgeThumbnailsOnReenableJob` 이 썸네일 디스크째 비운다.
     *
     * @param  string  $fullPath  디스크 위 전체 경로
     * @return void 반환값 없음
     */
    protected function syncThumbnailsBeforePut(string $fullPath): void
    {
        unset($this->thumbnailOverwrites[$fullPath]);

        if ($this->isThumbnailPath($fullPath)) {
            return;
        }

        // 썸네일 연동은 래스터 원본만 한다. 래스터가 아니면(PDF 등) 꺼진 경우처럼 원본 주소 CDN 무효화만 남긴다
        $enabled = $this->isThumbnailRaster($fullPath) && $this->thumbnailPutSyncEnabled();
        $overwriteState = $enabled ? self::THUMBNAIL_PUT_OVERWRITE : self::THUMBNAIL_PUT_ORIGINAL_ONLY;

        try {
            $store = app(ThumbnailStore::class);

            if (! $enabled) {
                // 끈 상태 — 원본 공개 주소가 없으면 판정할 이유가 없다(이미지 put 마다 원격 확인 요청을 내지 않는다)
                if (! $store->hasOriginalPublicUrl($this->disk, $fullPath)) {
                    return;
                }
            } elseif (! $store->needsOverwriteCheck($this->disk, $fullPath)) {
                // 판정이 요청을 줄이지 못하면(원본 공개 주소가 없고, 원격 썸네일 디스크 수가 원본 확인 요청 수 이하) 빼고
                // 쓴 뒤 모든 썸네일 디스크를 재확인한다 — 원격 원본 + 로컬 썸네일 디스크면 put 부가 원격 요청이 0 이다.
                $this->thumbnailOverwrites[$fullPath] = self::THUMBNAIL_PUT_UNCHECKED;

                return;
            }

            // 파일만 확인한다 — `exists()` 는 Flysystem `has()` 라 파일이 없으면 디렉터리 확인까지 해서
            // 원격 디스크의 새 파일 업로드마다 목록 요청이 한 번 더 나간다(계획서 §12 실측).
            // 서드파티 디스크 구현이 Laravel 어댑터가 아니어서 `fileExists` 가 없으면 `exists()` 로 물러선다
            $filesystem = Storage::disk($this->disk);
            $exists = method_exists($filesystem, 'fileExists') ? $filesystem->fileExists($fullPath) : $filesystem->exists($fullPath);

            if ($exists) {
                $this->thumbnailOverwrites[$fullPath] = $overwriteState;
            }
        } catch (\Throwable $e) {
            // 판정을 못 했으면 덮어쓰기로 보고 쓴 뒤 모든 썸네일 디스크를 재확인한다(파생본이 남는 쪽보다 요청 1회가 싸다)
            $this->thumbnailOverwrites[$fullPath] = $overwriteState;
            Log::warning('원본 덮어쓰기 판정 실패', ['disk' => $this->disk, 'path' => $fullPath, 'error' => $e->getMessage()]);
        }
    }

    /**
     * 쓴 뒤 — 같은 경로의 옛 파생본을 지우고, 등록 시 미리 생성할 규격이 있으면 예약한다.
     *
     * @param  string  $fullPath  디스크 위 전체 경로
     * @param  bool  $written  쓰기 성공 여부
     * @return void 반환값 없음
     */
    protected function syncThumbnailsAfterPut(string $fullPath, bool $written = true): void
    {
        $state = $this->thumbnailOverwrites[$fullPath] ?? null;
        unset($this->thumbnailOverwrites[$fullPath]);

        if ($this->isThumbnailPath($fullPath)) {
            return;
        }

        if ($state === self::THUMBNAIL_PUT_ORIGINAL_ONLY || ! $this->isThumbnailRaster($fullPath) || ! $this->thumbnailPutSyncEnabled()) {
            if ($state === self::THUMBNAIL_PUT_ORIGINAL_ONLY) {
                try {
                    app(ThumbnailStore::class)->purgeOriginalUrl($this->disk, $fullPath);
                } catch (\Throwable $e) {
                    Log::warning('원본 덮어쓰기 뒤 CDN 무효화 등록 실패', ['disk' => $this->disk, 'path' => $fullPath, 'error' => $e->getMessage()]);
                }
            }

            return;
        }

        // 덮어쓰기는 쓰기가 실패해도 지운다 — 쓰다 만 원본(로컬 부분 쓰기)과 옛 파생본이 어긋날 수 있고, 파생본은 다시 만든다
        if (! $written && $state !== self::THUMBNAIL_PUT_OVERWRITE) {
            return;
        }

        // 파생본 삭제는 쓴 뒤 한 번만 한다(종전에는 쓰기 전 비우기 + 쓴 뒤 재확인으로 원격 썸네일 디스크 조회가 2회였다).
        // 쓰기 전에 비워도 쓰는 동안 옛 원본으로 다시 만든 파생본은 쓴 뒤에야 걷히므로, 쓴 뒤 한 번이 같은 경우를 다 덮는다.
        //  - 덮어쓰기: 모든 썸네일 디스크, 원본 공개 주소도 CDN 무효화에 싣는다
        //  - 판정 안 함: 모든 썸네일 디스크(원본 공개 주소가 없는 경우만 여기 온다)
        //  - 새 파일(판정으로 앎): 같은 경로에 남은 옛 파생본(원본을 앱 밖에서 지운 경우 등)을 조회가 거의 공짜인 로컬
        //    썸네일 디스크에서만 걷는다 — 원격 썸네일 디스크에서 업로드마다 목록 요청이 나가지 않게(계획서 §12 실측).
        //    새 업로드마다 CDN 무효화를 보내지 않게 원본 주소는 싣지 않는다.
        try {
            $store = app(ThumbnailStore::class);

            match ($state) {
                self::THUMBNAIL_PUT_OVERWRITE => $store->purgePrefix($this->disk, $fullPath),
                self::THUMBNAIL_PUT_UNCHECKED => $store->purgePrefix($this->disk, $fullPath, false, false),
                default => $store->purgePrefix($this->disk, $fullPath, false, false, true),
            };
        } catch (\Throwable $e) {
            Log::warning('원본 쓰기 뒤 썸네일 삭제 실패', ['disk' => $this->disk, 'path' => $fullPath, 'error' => $e->getMessage()]);
        }

        if (! $written) {
            return;
        }

        try {
            app(ThumbnailPregenerator::class)->scheduleForStoredFile($this->disk, $fullPath);
        } catch (\Throwable $e) {
            Log::warning('등록 시 썸네일 생성 예약 실패', ['disk' => $this->disk, 'path' => $fullPath, 'error' => $e->getMessage()]);
        }
    }

    /**
     * 지운 뒤 — 래스터 원본이면 그 파생본을 지운다. 래스터가 아니면 원본 공개 주소(CDN)만 무효화에 싣는다.
     *
     * @param  string  $fullPath  디스크 위 전체 경로
     * @param  bool  $deleted  삭제 성공 여부
     * @return void 반환값 없음
     */
    protected function syncThumbnailsAfterDelete(string $fullPath, bool $deleted = true): void
    {
        if (! $deleted || $this->isThumbnailPath($fullPath)) {
            return;
        }

        if ($this->isThumbnailRaster($fullPath)) {
            $this->purgeThumbnailPrefix($fullPath);

            return;
        }

        // 공개 주소 계산은 로컬 연산이라 원격 요청이 없다
        try {
            app(ThumbnailStore::class)->purgeOriginalUrl($this->disk, $fullPath);
        } catch (\Throwable $e) {
            Log::warning('원본 삭제 뒤 CDN 무효화 등록 실패', ['disk' => $this->disk, 'path' => $fullPath, 'error' => $e->getMessage()]);
        }
    }

    /**
     * 디렉터리를 지운 뒤 — 확장자와 무관하게 그 아래 파생본 전부를 지운다.
     *
     * @param  string  $fullPath  디스크 위 디렉터리 전체 경로
     * @param  bool  $deleted  삭제 성공 여부
     * @return void 반환값 없음
     */
    protected function syncThumbnailsAfterDeleteDirectory(string $fullPath, bool $deleted = true): void
    {
        if (! $deleted || $this->isThumbnailPath($fullPath) || trim($fullPath, '/') === '') {
            return;
        }

        $this->purgeThumbnailPrefix($fullPath, true);
    }

    /**
     * 파생본 접두를 지웁니다 (실패는 경고만).
     *
     * @param  string  $fullPath  원본 전체 경로 또는 디렉터리
     * @param  bool  $directory  디렉터리째 지운 경우인지 (CDN 원본 무효화를 와일드카드로)
     * @return void 반환값 없음
     */
    private function purgeThumbnailPrefix(string $fullPath, bool $directory = false): void
    {
        try {
            app(ThumbnailStore::class)->purgePrefix($this->disk, $fullPath, $directory);
        } catch (\Throwable $e) {
            Log::warning('원본 삭제 뒤 썸네일 삭제 실패', ['disk' => $this->disk, 'path' => $fullPath, 'error' => $e->getMessage()]);
        }
    }

    /**
     * put 연동을 할지 — 썸네일이 꺼져 있으면 하지 않는다(삭제 연동은 꺼도 한다: 지운 원본의 파생본이 남지 않게).
     *
     * @return bool 켜져 있으면 true
     */
    private function thumbnailPutSyncEnabled(): bool
    {
        return (bool) config('core.thumbnail.enabled', true);
    }

    /**
     * 파생본 자신의 경로인지 (`thumbnails/` 로 시작)
     *
     * @param  string  $fullPath  디스크 위 전체 경로
     * @return bool 파생본 경로면 true
     */
    private function isThumbnailPath(string $fullPath): bool
    {
        $normalized = ltrim(str_replace('\\', '/', $fullPath), '/');

        return $normalized === ThumbnailStore::ROOT || str_starts_with($normalized, ThumbnailStore::ROOT.'/');
    }

    /**
     * 파생본 경로가 아닌 래스터 이미지 경로인지
     *
     * @param  string  $fullPath  디스크 위 전체 경로
     * @return bool 래스터 원본이면 true
     */
    private function isThumbnailRaster(string $fullPath): bool
    {
        if ($this->isThumbnailPath($fullPath)) {
            return false;
        }

        return in_array(strtolower(pathinfo($fullPath, PATHINFO_EXTENSION)), self::$thumbnailRasterExtensions, true);
    }
}
