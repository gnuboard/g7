<?php

namespace App\Thumbnails;

use App\Extension\Storage\CoreStorageDriver;
use App\Support\ThumbnailDiskOptions;
use App\Thumbnails\Contracts\ImageProcessorInterface;
use App\Thumbnails\DTO\ThumbnailSource;
use App\Thumbnails\DTO\ThumbnailSpec;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 파생본(썸네일) 저장소
 *
 * 키는 모든 썸네일 디스크에서 고정 루트 아래에 둔다: `thumbnails/{원본 disk}/{원본 전체 경로}/{규격명}_{지문}.{ext}`.
 *  - 고정 루트가 있어야 공용 디스크(public·s3·CDN)를 썸네일 디스크로 골라도 「전체 비우기」가 원본을 건드리지 않는다.
 *  - 원본 경로를 디렉터리로 비추므로 원본 하나·원본 디렉터리째 삭제를 같은 접두 하나로 지우고,
 *    CDN 퍼지도 원본 단위 와일드카드 하나로 보낸다.
 *
 * 저장 위치는 원본 분류별로 따로다 — 완전 공개 원본은 `upload.thumbnail_disk_public`(공개·CDN 디스크 가능),
 * 권한이 걸린 원본은 `upload.thumbnail_disk_gated`(비공개 디스크만 — `thumbnails`·`s3`·비공개 첨부 저장소 디스크, 늘 스트림).
 */
class ThumbnailStore
{
    /** 모든 썸네일 디스크 공통 루트 */
    public const ROOT = 'thumbnails';

    /** 비공개 기본 썸네일 디스크 */
    public const PRIVATE_DISK = 'thumbnails';

    /**
     * @var list<string> 권한 원본의 썸네일 디스크로 늘 허용하는 코어 디스크 (스트림으로만 낸다)
     *
     * 실제 허용 목록은 여기에 비공개 첨부 저장소 카탈로그 디스크를 더한 `ThumbnailDiskOptions::ids('gated')` 다.
     * 이 상수는 그 판정의 출발점이자 판정이 실패했을 때의 폴백이다.
     */
    public const GATED_ALLOWED_DISKS = ['thumbnails', 's3'];

    /** 저장 위치 종류: 공개 */
    public const KIND_PUBLIC = 'public';

    /** 저장 위치 종류: 권한 */
    public const KIND_GATED = 'gated';

    /**
     * ThumbnailStore 생성자
     *
     * @param  ThumbnailSourceProviders  $providers  원본 제공자 목록 (분류 판정)
     * @param  ThumbnailPurgeBuffer  $purgeBuffer  퍼지 묶음
     */
    public function __construct(
        private readonly ThumbnailSourceProviders $providers,
        private readonly ThumbnailPurgeBuffer $purgeBuffer,
    ) {}

    /**
     * 원본 분류의 썸네일 디스크 이름
     *
     * 권한 원본에 허용 목록 밖 디스크(공개 자산 디스크, 꺼진 플러그인 디스크 등)가 설정돼 있으면(저장 검증을 우회한 설정 파일
     * 편집, 저장 뒤 플러그인 비활성화) 비공개 디스크로 강제하고 경고를 남긴다.
     *
     * @param  bool  $gated  권한 원본인지
     * @return string 디스크 이름
     */
    public function diskFor(bool $gated): string
    {
        if ($gated) {
            $disk = (string) config('core.thumbnail.disk_gated', self::PRIVATE_DISK);

            if ($disk === self::PRIVATE_DISK || $disk === '') {
                return self::PRIVATE_DISK;
            }

            // 화면 선택지·저장 검증과 같은 판정(thumbnails·s3·비공개 첨부 저장소 카탈로그 − 공개 자산 디스크)을 쓴다.
            // 판정은 드라이버 카탈로그 필터만 돌고 이 저장소를 다시 부르지 않는다. 판정이 실패하면 코어 기본 목록으로 좁힌다.
            try {
                $allowed = app(ThumbnailDiskOptions::class)->ids(self::KIND_GATED);
            } catch (\Throwable) {
                $allowed = self::GATED_ALLOWED_DISKS;
            }

            if (! in_array($disk, $allowed, true)) {
                Log::warning('권한이 걸린 이미지의 썸네일 디스크로 허용되지 않는 디스크(공개 자산 디스크, 쓸 수 없는 디스크)가 설정돼 비공개 디스크로 바꿔 씁니다', ['disk' => $disk]);

                return self::PRIVATE_DISK;
            }

            return $disk;
        }

        $disk = (string) config('core.thumbnail.disk_public', self::PRIVATE_DISK);

        if ($disk === self::PRIVATE_DISK || $disk === '' || config("filesystems.disks.{$disk}") === null) {
            return self::PRIVATE_DISK;
        }

        // 공개 썸네일은 공개 자산 디스크 카탈로그 안에서만 고른다 — 설정 파일 편집 등으로 `settings`·`local` 같은
        // 다른 용도 디스크가 들어오면 그 트리에 파생본이 쓰인다. 카탈로그 밖이면 비공개 디스크로 쓴다.
        try {
            $allowed = app(ThumbnailDiskOptions::class)->ids(self::KIND_PUBLIC);
        } catch (\Throwable) {
            $allowed = [self::PRIVATE_DISK];
        }

        if (! in_array($disk, $allowed, true)) {
            Log::warning('공개 이미지 썸네일 디스크로 공개 자산 디스크가 아닌 디스크가 설정돼 비공개 디스크로 바꿔 씁니다', ['disk' => $disk]);

            return self::PRIVATE_DISK;
        }

        return $disk;
    }

    /**
     * 저장 위치 종류의 디스크 이름
     *
     * @param  string  $kind  public·gated
     * @return string 디스크 이름
     */
    public function diskForKind(string $kind): string
    {
        return $this->diskFor($kind === self::KIND_GATED);
    }

    /**
     * 썸네일 디스크로 쓰는 디스크 이름 전부 (중복 없음)
     *
     * @return list<string> 디스크 이름
     */
    public function disks(): array
    {
        return array_values(array_unique([$this->diskFor(false), $this->diskFor(true)]));
    }

    /**
     * 디스크의 파일시스템 — 파생본 키·원본 전체 경로의 메타(존재·목록·수정 시각·크기·스트림)를 읽는다.
     *
     * 썸네일 저장소는 원본·파생본을 **디스크 위 전체 경로**로 다룬다(확장 드라이버의 식별자 접두까지 포함한 키).
     * `StorageInterface` 는 카테고리 상대 경로 표면이라 `directoryExists`·`allFiles`·`lastModified`·`readStream` 을
     * 싣지 않는다. 디스크 직접 접근은 이 한 지점으로 모은다 — 쓰기·삭제·응답은 `storage()`(CoreStorageDriver)를 쓴다.
     *
     * @param  string  $disk  디스크 이름
     * @return FilesystemAdapter 파일시스템
     */
    public function filesystem(string $disk): FilesystemAdapter
    {
        // audit:allow no-storage-disk-direct reason: 썸네일 키는 디스크 전체 경로라 카테고리 드라이버 표면에 없는 메타 조회(directoryExists·allFiles·lastModified·readStream)가 필요하다 — 직접 접근은 이 지점 하나로 모은다
        return Storage::disk($disk);
    }

    /**
     * 디스크 드라이버 (썸네일 키는 카테고리 없이 전체 경로로 다룬다)
     *
     * @param  string  $disk  디스크 이름
     * @return CoreStorageDriver 드라이버
     */
    public function storage(string $disk): CoreStorageDriver
    {
        return new CoreStorageDriver($disk);
    }

    /**
     * 파생본 키
     *
     * @param  ThumbnailSource  $source  원본
     * @param  ThumbnailSpec  $spec  규격
     * @param  ImageProcessorInterface  $engine  엔진 (지문·출력 형식 판정)
     * @param  bool  $firstFrame  움직이는 원본의 첫 프레임 썸네일인지 (지문이 달라 정지 이미지 키와 겹치지 않는다)
     * @return string 디스크 위 키
     */
    public function key(ThumbnailSource $source, ThumbnailSpec $spec, ImageProcessorInterface $engine, bool $firstFrame = false): string
    {
        return $this->sourceDirectory($source->disk, $source->path)
            .'/'.$spec->name.'_'.$spec->fingerprint($engine->id(), $firstFrame).'.'.$this->extension($source->mime, $spec, $engine);
    }

    /**
     * 원본 하나의 파생본 디렉터리
     *
     * @param  string  $sourceDisk  원본 디스크
     * @param  string  $sourcePath  원본 전체 경로 (또는 디렉터리 접두)
     * @return string 키 접두
     */
    public function sourceDirectory(string $sourceDisk, string $sourcePath): string
    {
        return rtrim(self::ROOT.'/'.$sourceDisk.'/'.ltrim($sourcePath, '/'), '/');
    }

    /**
     * 결과 확장자 — 엔진이 규격 형식(WebP·AVIF)을 쓸 수 없으면 원본 형식, BMP 는 PNG.
     *
     * @param  string  $mime  원본 MIME
     * @param  ThumbnailSpec  $spec  규격
     * @param  ImageProcessorInterface  $engine  엔진
     * @return string 확장자
     */
    public function extension(string $mime, ThumbnailSpec $spec, ImageProcessorInterface $engine): string
    {
        if ($spec->format !== 'original' && $engine->supportsOutput($spec->format)) {
            return $spec->format;
        }

        $original = match (strtolower($mime)) {
            'image/jpeg', 'image/jpg', 'image/pjpeg' => 'jpeg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default => 'png',
        };

        if (! $engine->supportsOutput($original)) {
            return 'png';
        }

        return $original === 'jpeg' ? 'jpg' : $original;
    }

    /**
     * 원본(또는 원본 디렉터리)의 파생본을 **모든 썸네일 디스크**에서 지웁니다.
     *
     * 원본이 어느 분류였는지 지우는 쪽은 모를 수 있다(저장소 계층) — 두 디스크를 다 본다.
     *
     * 원본이 공개 자산 디스크에 있으면 파생본이 없어도 원본 URL 을 퍼지 묶음에 싣는다(CDN 이 원본도 캐시한다).
     *
     * @param  string  $sourceDisk  원본 디스크
     * @param  string  $prefix  원본 전체 경로 또는 디렉터리 접두
     * @param  bool  $directory  `prefix` 가 디렉터리인지 (원본 디렉터리째 지움 — CDN 원본 무효화를 와일드카드로)
     * @param  bool  $includeOriginal  파생본이 없어도 원본 URL 을 퍼지할지 (새 파일을 쓴 직후의 재확인은 false)
     * @param  bool  $localOnly  로컬 썸네일 디스크만 볼지 (판정으로 새 파일임을 안 쓰기 뒤 재확인 — 원격 디스크 목록 요청을 업로드마다 내지 않게)
     * @return int 지운 디렉터리 수
     */
    public function purgePrefix(string $sourceDisk, string $prefix, bool $directory = false, bool $includeOriginal = true, bool $localOnly = false): int
    {
        $thumbnailDirectory = $this->sourceDirectory($sourceDisk, $prefix);

        // 접두가 비면 원본 디스크 하나의 파생본 전부를 지운다는 뜻이다 — 저장소 계층에서 오지 않는 값이라 막는다.
        if ($thumbnailDirectory === self::ROOT.'/'.$sourceDisk && trim($prefix, '/') === '') {
            return 0;
        }

        $deleted = 0;
        $originalUrl = $this->originalPublicUrl($sourceDisk, $prefix);

        foreach ($this->disks() as $disk) {
            if ($localOnly && ! $this->isLocalDisk($disk)) {
                continue;
            }

            try {
                $filesystem = $this->filesystem($disk);

                if (! $filesystem->directoryExists($thumbnailDirectory)) {
                    continue;
                }

                $filesystem->deleteDirectory($thumbnailDirectory);
                $deleted++;

                $this->purgeBuffer->add([
                    'source_disk' => $sourceDisk,
                    'prefix' => trim($prefix, '/'),
                    'thumbnail_disk' => $disk,
                    'public_url_prefix' => $this->publicUrlPrefix($disk, $thumbnailDirectory),
                    'original_public_url' => $originalUrl,
                    'original_is_directory' => $directory,
                ]);
            } catch (\Throwable $e) {
                Log::warning('썸네일 파생본 삭제 실패', ['disk' => $disk, 'directory' => $thumbnailDirectory, 'error' => $e->getMessage()]);
            }
        }

        if ($deleted === 0 && $includeOriginal && $originalUrl !== null) {
            $this->purgeBuffer->add([
                'source_disk' => $sourceDisk,
                'prefix' => trim($prefix, '/'),
                'thumbnail_disk' => '',
                'public_url_prefix' => null,
                'original_public_url' => $originalUrl,
                'original_is_directory' => $directory,
            ]);
        }

        return $deleted;
    }

    /**
     * 원본 쓰기 연동에 덮어쓰기 판정(원본 파일 확인 요청)이 필요한지
     *
     * 판정 결과가 쓰이는 곳은 둘이다. (1) 원본에 공개 주소가 있으면 덮어쓸 때만 그 주소를 CDN 무효화에 싣는다.
     * (2) 새 파일이면 원격 썸네일 디스크의 쓴 뒤 재확인을 건너뛴다. (1)이 없을 때는 판정 요청과 재확인 요청의 수를
     * 견준다 — 판정을 빼면 모든 썸네일 디스크를 쓴 뒤 한 번씩 재확인하고, 판정을 하면 원본 확인 1회(원본이 원격일 때)에
     * 덮어쓰기면 원격 썸네일 디스크 재확인이 더 붙는다. 원격 썸네일 디스크 수가 원본 확인 요청 수 이하이면 판정을 뺀다.
     * 로컬 디스크 조회는 원격 요청이 아니라 세지 않는다.
     *
     * @param  string  $sourceDisk  원본 디스크
     * @param  string  $path  원본 전체 경로
     * @return bool 판정해야 하면 true
     */
    public function needsOverwriteCheck(string $sourceDisk, string $path): bool
    {
        if ($this->hasOriginalPublicUrl($sourceDisk, $path)) {
            return true;
        }

        $remoteThumbnailDisks = count(array_filter($this->disks(), fn (string $disk) => ! $this->isLocalDisk($disk)));

        return $remoteThumbnailDisks > ($this->isLocalDisk($sourceDisk) ? 0 : 1);
    }

    /**
     * 원본에 공개 주소(CDN 이 캐시할 수 있는 직접 URL)가 있는지
     *
     * @param  string  $sourceDisk  원본 디스크
     * @param  string  $path  원본 전체 경로
     * @return bool 있으면 true
     */
    public function hasOriginalPublicUrl(string $sourceDisk, string $path): bool
    {
        return $this->originalPublicUrl($sourceDisk, $path) !== null;
    }

    /**
     * 원본 공개 주소만 퍼지 묶음에 싣습니다 (썸네일을 끈 동안의 덮어쓰기 — 파생본은 건드리지 않고 원본 CDN 캐시만 무효화).
     *
     * @param  string  $sourceDisk  원본 디스크
     * @param  string  $path  원본 전체 경로
     * @return bool 실었으면 true (공개 주소가 없으면 false)
     */
    public function purgeOriginalUrl(string $sourceDisk, string $path): bool
    {
        $originalUrl = $this->originalPublicUrl($sourceDisk, $path);

        if ($originalUrl === null) {
            return false;
        }

        $this->purgeBuffer->add([
            'source_disk' => $sourceDisk,
            'prefix' => trim($path, '/'),
            'thumbnail_disk' => '',
            'public_url_prefix' => null,
            'original_public_url' => $originalUrl,
            'original_is_directory' => false,
        ]);

        return true;
    }

    /**
     * 지금 쓰는 썸네일 디스크 전부의 파생본을 지웁니다 (썸네일을 다시 켤 때 — 끈 동안의 덮어쓰기는 무효화하지 않았다).
     *
     * 디렉터리 삭제는 파일 수만큼 걸리므로 설정 저장 요청에서 부르지 않는다 — `PurgeThumbnailsOnReenableJob` 이 돈다.
     *
     * @return int 지운 디스크 수
     */
    public function purgeCurrentDisks(): int
    {
        $deleted = 0;

        foreach ($this->disks() as $disk) {
            try {
                $filesystem = $this->filesystem($disk);

                if (! $filesystem->directoryExists(self::ROOT)) {
                    continue;
                }

                $filesystem->deleteDirectory(self::ROOT);
                $deleted++;

                $this->purgeBuffer->add([
                    'source_disk' => '*',
                    'prefix' => '',
                    'thumbnail_disk' => $disk,
                    'public_url_prefix' => $this->publicUrlPrefix($disk, self::ROOT),
                    'original_public_url' => null,
                    'original_is_directory' => false,
                ]);
            } catch (\Throwable $e) {
                Log::warning('썸네일 디스크 비우기 실패', ['disk' => $disk, 'error' => $e->getMessage()]);
            }
        }

        return $deleted;
    }

    /**
     * 더는 쓰지 않는 썸네일 디스크(저장 위치를 바꾼 뒤의 옛 디스크)의 파생본 전부를 지웁니다.
     *
     * 원본 삭제·정리·비우기는 「지금 설정된」 디스크만 보므로, 옛 디스크에 남은 파생본은 이 호출이 아니면 영원히 남는다.
     * 공개 자산 디스크였다면 원본을 지운 뒤에도 직접 URL 로 계속 열린다.
     *
     * 디렉터리 삭제는 파일 수만큼 걸리므로 설정 저장 요청에서 부르지 않는다 — `PurgeRetiredThumbnailDiskJob` 이 돈다.
     *
     * @param  string  $disk  옛 썸네일 디스크
     * @return bool 지웠으면 true (지금 쓰는 디스크·없는 디스크·비어 있는 디스크·실패는 false)
     */
    public function purgeRetiredDisk(string $disk): bool
    {
        if (in_array($disk, $this->disks(), true) || config("filesystems.disks.{$disk}") === null) {
            return false;
        }

        try {
            $filesystem = $this->filesystem($disk);

            if (! $filesystem->directoryExists(self::ROOT)) {
                return false;
            }

            $url = $disk === self::PRIVATE_DISK ? null : $this->storage($disk)->url('', self::ROOT);
            $filesystem->deleteDirectory(self::ROOT);

            $this->purgeBuffer->add([
                'source_disk' => '*',
                'prefix' => '',
                'thumbnail_disk' => $disk,
                'public_url_prefix' => $url,
                'original_public_url' => null,
                'original_is_directory' => false,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('옛 썸네일 디스크 정리 실패', ['disk' => $disk, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * 확장 하나의 파생본을 모든 썸네일 디스크에서 지웁니다 (확장 제거 — 드라이버 밖 디렉터리 삭제 대응).
     *
     * @param  string  $identifier  확장 식별자
     * @return int 지운 디렉터리 수
     */
    public function purgeIdentifier(string $identifier): int
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $identifier) !== 1) {
            return 0;
        }

        $deleted = 0;

        foreach ($this->disks() as $disk) {
            try {
                foreach ($this->filesystem($disk)->directories(self::ROOT) as $sourceRoot) {
                    $deleted += $this->purgePrefix(basename($sourceRoot), $identifier, true);
                }
            } catch (\Throwable $e) {
                Log::warning('확장 썸네일 삭제 실패', ['disk' => $disk, 'identifier' => $identifier, 'error' => $e->getMessage()]);
            }
        }

        return $deleted;
    }

    /**
     * 저장 위치 하나(공개·권한)의 파생본을 끝까지 지웁니다 (시간 예산 없이 — 명령·내부 호출용).
     *
     * @param  string  $kind  public·gated
     * @param  string|null  $preset  규격 이름 (null 이면 전 규격)
     * @return int 지운 파일 수 (디스크를 통째로 비우면 -1, 지울 것이 없으면 0)
     */
    public function purgeLocation(string $kind, ?string $preset = null): int
    {
        return $this->purgeLocationStep($kind, $preset)['deleted'];
    }

    /**
     * 저장 위치 하나(공개·권한)의 파생본을 한 걸음 지웁니다 (관리자 「비우기」 한 번분).
     *
     * 두 분류가 같은 디스크를 쓰거나 규격 하나만 지우면 파일마다 판정해야 한다(원본 제공자 분류·규격 이름). 이때 디스크
     * 전체 목록(`allFiles`)을 한 요청에서 만들지 않고 `thumbnails/` 트리를 디렉터리 단위로 이름 순서대로 걷는다.
     * 시간 예산을 넘기면 마지막으로 본 파일 경로를 커서로 돌려주고, 다음 걸음은 그 경로보다 뒤에 오는 것부터 걷는다.
     * 경로 비교라 직전 걸음이 지운 파일이 사라져도 자리가 밀리지 않고, 그 사이 새로 생긴 파일도 건너뛰지 않는다
     * (커서보다 앞 자리에 생긴 파일만 이번 비우기에서 빠진다 — 비우기 시작 뒤에 만든 캐시다).
     *
     * 다른 분류와 디스크를 나눠 쓰고 전 규격을 지우면 판정 없이 지운다.
     *  - 로컬 디스크: `thumbnails/` 를 한 번에 지운다(파일 수를 세지 않아 -1). 원격 왕복 없이 파일시스템이 지우는 일이다.
     *  - 원격 디스크(S3 등): 한 번에 지우면 파일 1000개마다 목록·일괄 삭제 요청이 나가 요청 하나가 파일 수만큼 길어진다.
     *    판정하는 경우처럼 걷되 판정 없이 모두 지우고, CDN 무효화는 비우기가 끝나는 걸음에 루트 와일드카드 하나로 싣는다
     *    (걸음마다 실으면 무효화 경로가 걸음 수만큼 는다 — 도중에 멈추면 다음 비우기가 끝날 때 싣는다).
     *
     * @param  string  $kind  public·gated
     * @param  string|null  $preset  규격 이름 (null 이면 전 규격)
     * @param  string|null  $after  이전 걸음의 커서 (마지막으로 본 파생본 키, 처음이면 null)
     * @param  float|null  $timeBudget  이번 걸음의 시간 예산(초, null 이면 끝까지 — 예산을 넘겨도 파일 하나는 본다)
     * @return array{deleted: int, next_cursor: ?string} 지운 파일 수(로컬 디스크를 통째로 비우면 -1)와 다음 커서(끝이면 null)
     */
    public function purgeLocationStep(string $kind, ?string $preset = null, ?string $after = null, ?float $timeBudget = null): array
    {
        $disk = $this->diskForKind($kind);
        $shared = $this->diskForKind($kind === self::KIND_GATED ? self::KIND_PUBLIC : self::KIND_GATED) === $disk;
        $filesystem = $this->filesystem($disk);

        $wholeDisk = ! $shared && $preset === null;

        if ($wholeDisk && $this->isLocalDisk($disk)) {
            if (! $filesystem->directoryExists(self::ROOT)) {
                return ['deleted' => 0, 'next_cursor' => null];
            }

            $filesystem->deleteDirectory(self::ROOT);

            $this->purgeBuffer->add([
                'source_disk' => '*',
                'prefix' => '',
                'thumbnail_disk' => $disk,
                'public_url_prefix' => $this->publicUrlPrefix($disk, self::ROOT),
                'original_public_url' => null,
                'original_is_directory' => false,
            ]);

            return ['deleted' => -1, 'next_cursor' => null];
        }

        $deleted = 0;
        $prefixes = [];
        $last = null;
        $startedAt = microtime(true);
        $providers = $this->providers->all();

        // 커서는 thumbnails/ 아래 경로만 받는다 — 비교에만 쓰고 그 경로를 직접 열거나 지우지 않는다
        $resume = $after !== null && str_starts_with($after, self::ROOT.'/')
            ? explode('/', substr($after, strlen(self::ROOT) + 1))
            : [];

        $finished = $this->walkFiles($filesystem, self::ROOT, $resume, function (string $file) use ($kind, $preset, $disk, $providers, $filesystem, $timeBudget, $startedAt, $wholeDisk, &$deleted, &$prefixes, &$last): bool {
            $last = $file;

            if ($wholeDisk || ($this->classifyFile($file, $disk, $providers) === $kind
                && ($preset === null || self::presetOfFile($file) === $preset))) {
                $filesystem->delete($file);
                $deleted++;

                if (! $wholeDisk) {
                    $prefixes[dirname($file)] = true;
                }
            }

            return $timeBudget === null || microtime(true) - $startedAt < $timeBudget;
        });

        // 원격 디스크 통째 비우기 — 끝나는 걸음에 루트 하나(앞 걸음에서 지운 것이 있었거나 이번에 지웠을 때)
        if ($wholeDisk && $finished && ($deleted > 0 || $after !== null)) {
            $this->purgeBuffer->add([
                'source_disk' => '*',
                'prefix' => '',
                'thumbnail_disk' => $disk,
                'public_url_prefix' => $this->publicUrlPrefix($disk, self::ROOT),
                'original_public_url' => null,
                'original_is_directory' => false,
            ]);
        }

        foreach (array_keys($prefixes) as $directory) {
            $this->purgeBuffer->add([
                'source_disk' => explode('/', $directory, 3)[1] ?? '*',
                'prefix' => explode('/', $directory, 3)[2] ?? '',
                'thumbnail_disk' => $disk,
                'public_url_prefix' => $this->publicUrlPrefix($disk, $directory),
                'original_public_url' => null,
                'original_is_directory' => false,
            ]);
        }

        return ['deleted' => $deleted, 'next_cursor' => $finished ? null : $last];
    }

    /**
     * 디렉터리 트리를 이름 순서(바이트 비교)로 깊이 우선 걷습니다 — 한 번에 디렉터리 하나의 바로 아래만 읽는다.
     *
     * `resume` 은 루트 아래 커서 경로의 조각이다. 조각보다 앞 이름은 건너뛰고, 같은 이름의 디렉터리는 남은 조각으로
     * 들어가 이어 걷고, 같은 이름의 파일(마지막으로 본 파일)은 건너뛴다. 그 뒤는 처음부터 걷는다.
     *
     * @param  FilesystemAdapter  $filesystem  파일시스템
     * @param  string  $directory  걸을 디렉터리
     * @param  list<string>  $resume  이어 걸을 커서 조각 (빈 목록이면 처음부터)
     * @param  callable(string): bool  $visit  파일마다 부른다 — false 를 돌려주면 멈춘다
     * @return bool 끝까지 걸었으면 true, 멈췄으면 false
     */
    private function walkFiles(FilesystemAdapter $filesystem, string $directory, array $resume, callable $visit): bool
    {
        $entries = [];

        foreach ($filesystem->getDriver()->listContents($directory, false) as $item) {
            $path = trim(str_replace('\\', '/', $item->path()), '/');
            $entries[] = ['name' => basename($path), 'path' => $path, 'dir' => $item->isDir()];
        }

        usort($entries, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        $target = $resume[0] ?? null;
        $rest = array_slice($resume, 1);

        foreach ($entries as $entry) {
            if ($target !== null) {
                $order = strcmp($entry['name'], $target);

                if ($order < 0) {
                    continue;
                }

                if ($order === 0) {
                    $target = null;

                    if ($entry['dir'] && $rest !== [] && ! $this->walkFiles($filesystem, $entry['path'], $rest, $visit)) {
                        return false;
                    }

                    continue;
                }

                $target = null;
            }

            if ($entry['dir']) {
                if (! $this->walkFiles($filesystem, $entry['path'], [], $visit)) {
                    return false;
                }

                continue;
            }

            if (! $visit($entry['path'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * 저장 위치 하나의 사용량 (로컬 디스크만 집계, 원격은 null)
     *
     * @param  string  $kind  public·gated
     * @return array{kind: string, disk: string, remote: bool, count: ?int, bytes: ?int, by_preset: ?array<string, int>} 사용량
     */
    public function stats(string $kind): array
    {
        return $this->statsAll()[$kind === self::KIND_GATED ? 1 : 0];
    }

    /**
     * 두 저장 위치(공개·권한)의 사용량을 한 번에 셉니다 — 같은 디스크를 쓰면 그 디스크를 **한 번만** 훑는다.
     *
     * 원본 제공자 목록도 호출 한 번에 한 번만 해석한다(파일마다 필터를 다시 돌리지 않는다).
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [공개, 권한] 사용량
     */
    public function statsAll(): array
    {
        $kinds = [self::KIND_PUBLIC, self::KIND_GATED];
        $results = [];
        $scanned = [];
        $providers = $this->providers->all();

        foreach ($kinds as $kind) {
            $disk = $this->diskForKind($kind);
            $results[$kind] = ['kind' => $kind, 'disk' => $disk, 'remote' => ! $this->isLocalDisk($disk), 'count' => null, 'bytes' => null, 'by_preset' => null];
        }

        foreach ($kinds as $kind) {
            $disk = $results[$kind]['disk'];

            if ($results[$kind]['remote'] || isset($scanned[$disk])) {
                continue;
            }

            $scanned[$disk] = true;
            $tally = [];
            $filesystem = $this->filesystem($disk);

            foreach ($filesystem->allFiles(self::ROOT) as $file) {
                $owner = $this->classifyFile($file, $disk, $providers);
                $tally[$owner] ??= ['count' => 0, 'bytes' => 0, 'by_preset' => []];
                $tally[$owner]['count']++;
                $tally[$owner]['bytes'] += (int) $filesystem->size($file);
                $preset = self::presetOfFile($file);
                $tally[$owner]['by_preset'][$preset] = ($tally[$owner]['by_preset'][$preset] ?? 0) + 1;
            }

            foreach ($kinds as $owner) {
                if ($this->diskForKind($owner) === $disk) {
                    $results[$owner] = array_merge($results[$owner], $tally[$owner] ?? ['count' => 0, 'bytes' => 0, 'by_preset' => []]);
                }
            }
        }

        return [$results[self::KIND_PUBLIC], $results[self::KIND_GATED]];
    }

    /**
     * 파생본 파일이 어느 저장 위치 것인지 — 원본 제공자로 판정, 모르면 그 디스크를 쓰는 분류(공유면 권한 쪽).
     *
     * @param  string  $file  파생본 키
     * @param  string  $disk  썸네일 디스크
     * @param  array<string, Contracts\ThumbnailSourceProvider>|null  $providers  미리 해석한 제공자 목록 (null 이면 지금 해석)
     * @return string public·gated
     */
    public function classifyFile(string $file, string $disk, ?array $providers = null): string
    {
        $parts = explode('/', $file, 3);
        $sourceDisk = $parts[1] ?? '';
        $sourcePath = isset($parts[2]) ? dirname($parts[2]) : '';
        $provider = $sourceDisk !== '' ? $this->providers->classify($sourceDisk, $sourcePath, $providers) : null;

        if ($provider !== null) {
            return $provider->gated() ? self::KIND_GATED : self::KIND_PUBLIC;
        }

        // 모르는 원본은 보수적으로 권한 쪽에 둔다 — 공개만 비우기가 그 파일을 지우지 않게.
        return $this->diskForKind(self::KIND_GATED) === $disk ? self::KIND_GATED : self::KIND_PUBLIC;
    }

    /**
     * 파생본 파일명에서 규격 이름을 꺼냅니다 — `{규격명}_{지문}.{ext}` 의 마지막 `_` 앞.
     *
     * 규격 이름에도 `_` 가 들어갈 수 있어(`banner_wide`) 앞에서 자르면 안 된다.
     *
     * @param  string  $file  파생본 키
     * @return string 규격 이름
     */
    public static function presetOfFile(string $file): string
    {
        $name = basename($file);
        $position = strrpos($name, '_');

        return $position === false ? $name : substr($name, 0, $position);
    }

    /**
     * 파생본 파일명에서 규격 지문을 꺼냅니다.
     *
     * @param  string  $file  파생본 키
     * @return string|null 지문 (형식이 아니면 null)
     */
    public static function fingerprintOfFile(string $file): ?string
    {
        return preg_match('/_([0-9a-f]{8})\.[a-z0-9]+$/', basename($file), $matches) === 1 ? $matches[1] : null;
    }

    /**
     * 로컬 파일시스템 디스크인지
     *
     * @param  string  $disk  디스크 이름
     * @return bool local·local-safe 이면 true
     */
    public function isLocalDisk(string $disk): bool
    {
        return in_array(config("filesystems.disks.{$disk}.driver"), ['local', 'local-safe'], true);
    }

    /**
     * CDN 퍼지 대상이 될 파생본 디렉터리의 공개 URL — 공개 이미지 썸네일 디스크일 때만.
     *
     * 권한 썸네일 디스크로 `s3` 를 고르면 그 디스크도 직접 URL 을 낼 수 있지만, 권한 썸네일은 늘 스트림으로만
     * 나가고 CDN 이 캐시하지 않는다 — 그 경로를 퍼지 대상으로 실어 보내지 않는다.
     *
     * @param  string  $disk  썸네일 디스크
     * @param  string  $directory  파생본 디렉터리 키
     * @return string|null 공개 URL (공개 썸네일 디스크가 아니거나 직접 URL 이 없으면 null)
     */
    private function publicUrlPrefix(string $disk, string $directory): ?string
    {
        if ($disk !== $this->diskFor(false) || $disk === self::PRIVATE_DISK) {
            return null;
        }

        return $this->storage($disk)->url('', $directory);
    }

    /**
     * 원본이 공개 자산 디스크에 있으면 그 공개 URL (CDN 이 원본도 캐시하므로 함께 퍼지한다)
     *
     * @param  string  $sourceDisk  원본 디스크
     * @param  string  $path  원본 경로
     * @return string|null 공개 URL (공개 디스크가 아니면 null)
     */
    private function originalPublicUrl(string $sourceDisk, string $path): ?string
    {
        if ($sourceDisk !== 'public') {
            $base = config("filesystems.disks.{$sourceDisk}.url");

            if (! is_string($base) || trim($base) === '') {
                return null;
            }
        }

        try {
            return $this->filesystem($sourceDisk)->url(ltrim($path, '/'));
        } catch (\Throwable) {
            return null;
        }
    }
}
