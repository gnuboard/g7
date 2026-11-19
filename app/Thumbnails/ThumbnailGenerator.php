<?php

// audit:allow job-generator-needs-scale-test reason: 원본 1장을 규격 1개로 줄이는 단건 생성기 — 데이터셋을 순회하지 않고, 원본 크기는 core.thumbnail.max_source_pixels 로 막는다. 대량 순회는 ThumbnailPregenerator::batch 의 커서·limit 이 맡는다

namespace App\Thumbnails;

use App\Contracts\Extension\CacheInterface;
use App\Extension\HookManager;
use App\Support\ExecutionEnvironment;
use App\Thumbnails\Contracts\ImageProcessorInterface;
use App\Thumbnails\DTO\ThumbnailResult;
use App\Thumbnails\DTO\ThumbnailSource;
use App\Thumbnails\DTO\ThumbnailSpec;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 파생본 준비 — 있으면 쓰고, 없거나 낡았으면 만든다 (서빙·등록 시 생성·미리 만들기 공용).
 *
 * 생성 순서: 잠금(같은 파생본을 동시에 두 번 만들지 않는다) → 원본 임시 파일(스트림 복사) → 픽셀 상한 →
 * 움직이는 이미지(GIF·WebP·APNG) 판정 → 엔진 → 임시 결과 → 썸네일 디스크에 저장 → 임시 파일 정리.
 * 원본으로 돌려보낼 사유(작은 원본·픽셀 초과)와 움직이는 원본이라는 사실은 6시간 기억해 같은 원본을 매번 내려받지 않는다.
 *
 * 움직이는 이미지는 기본으로 원본을 낸다(`core.thumbnail.animated_original`, 확장은 `core.thumbnail.filter_animated_original`).
 * 끄면 첫 프레임 썸네일을 정지 이미지와 다른 키(`ThumbnailStore::key(..., firstFrame: true)`)에 만든다. 다시 켜면
 * 정지 이미지 키에는 아무것도 없으므로 이미 만든 첫 프레임 썸네일을 내지 않고 바로 원본으로 돌아간다.
 */
class ThumbnailGenerator
{
    /** 움직이는 원본이라는 사실의 기억 값 접두 (`animated:{gif|webp|png}`) — 원본을 낼지는 읽을 때마다 판정한다 */
    private const ANIMATED_MEMO_PREFIX = 'animated:';

    /** 원본으로 돌려보낸 사유를 기억하는 시간(초) — 원격 원본은 덮어써도 키가 같아 너무 길면 갱신이 늦다 */
    private const BYPASS_MEMO_TTL = 21600;

    /** 생성 오류를 기억하는 시간(초) */
    private const ERROR_MEMO_TTL = 300;

    /** 생성 잠금 유지 시간(초) — 잠금 대기(`core.thumbnail.lock_wait_seconds`)의 상한 */
    public const LOCK_SECONDS = 30;

    /** 생성 잠금 대기 시간(초)의 기본값 — 서버 설정 `core.thumbnail.lock_wait_seconds` 가 없거나 숫자가 아닐 때 */
    private const LOCK_WAIT_SECONDS = 10;

    /** PNG 파일 서명 */
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** 움직임 판정에서 건너뛸 청크 수 상한 (손상 파일에서 끝없이 돌지 않게) */
    private const MAX_CHUNKS = 10000;

    /**
     * ThumbnailGenerator 생성자
     *
     * @param  ImageProcessorResolver  $processors  엔진 해석기
     * @param  ThumbnailStore  $store  파생본 저장소
     * @param  CacheInterface  $cache  코어 캐시 (원본으로 돌려보낸 사유 기억)
     */
    public function __construct(
        private readonly ImageProcessorResolver $processors,
        private readonly ThumbnailStore $store,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 파생본을 준비합니다.
     *
     * @param  ThumbnailSource  $source  원본
     * @param  ThumbnailSpec  $spec  규격
     * @param  bool  $allowGenerate  없을 때 만들어도 되는지 (false 면 hit 이나 기억한 사유가 아니면 bypass:rate-limited)
     * @return ThumbnailResult 결과
     */
    public function prepare(ThumbnailSource $source, ThumbnailSpec $spec, bool $allowGenerate = true): ThumbnailResult
    {
        $engine = $this->processors->resolve();

        if ($engine === null) {
            return ThumbnailResult::bypass('no-engine');
        }

        if (! $engine->supportsInput($source->mime)) {
            return ThumbnailResult::bypass('not-raster');
        }

        if (! $spec->upscale && $source->width !== null && $source->height !== null
            && $spec->containsSource($source->width, $source->height)) {
            return ThumbnailResult::bypass('smaller-than-preset');
        }

        $disk = $this->store->diskFor($source->gated);
        $key = $this->store->key($source, $spec, $engine);
        $mime = $this->mimeForKey($key);

        if ($this->isFresh($source, $disk, $key)) {
            return ThumbnailResult::hit($disk, $key, $mime);
        }

        $memoKey = $this->memoKey($source, $disk, $key);

        $reason = $this->cache->get($memoKey);

        // 판정 기억이 없어도(만료·캐시 비움) 이미 만든 첫 프레임 썸네일이 있으면 원본을 다시 받지 않는다 —
        // 첫 프레임 키는 움직이는 원본에만 생기므로 파일이 있다는 것이 곧 움직이는 원본이라는 증거다.
        if (! is_string($reason) && ($format = self::animatedCandidateFormat($source->mime)) !== null
            && $this->isFresh($source, $disk, $this->store->key($source, $spec, $engine, firstFrame: true))) {
            $reason = self::ANIMATED_MEMO_PREFIX.$format;
            $this->cache->put($memoKey, $reason, self::BYPASS_MEMO_TTL);
        }

        if (is_string($reason)) {
            $animated = str_starts_with($reason, self::ANIMATED_MEMO_PREFIX) ? substr($reason, strlen(self::ANIMATED_MEMO_PREFIX)) : null;

            if ($animated === null) {
                return ThumbnailResult::bypass($reason);
            }

            if ($this->servesAnimatedOriginal($animated, $source, $spec)) {
                return ThumbnailResult::bypass('animated');
            }

            $frameKey = $this->store->key($source, $spec, $engine, firstFrame: true);

            if ($this->isFresh($source, $disk, $frameKey)) {
                return ThumbnailResult::hit($disk, $frameKey, $mime);
            }
        }

        if (! $allowGenerate) {
            return ThumbnailResult::bypass('rate-limited');
        }

        try {
            return Cache::lock('thumb:'.md5($disk.'|'.$key), self::LOCK_SECONDS)->block(
                self::lockWaitSeconds(),
                function () use ($source, $spec, $engine, $disk, $key, $mime, $memoKey) {
                    // 잠금을 기다리는 동안 다른 요청이 만들었을 수 있다.
                    if ($this->isFresh($source, $disk, $key)) {
                        return ThumbnailResult::hit($disk, $key, $mime);
                    }

                    $result = $this->generate($source, $spec, $engine, $disk, $key, $memoKey);

                    // 움직이는 원본의 기억은 generate() 가 형식과 함께 남긴다
                    if ($result->status === 'bypass' && in_array($result->reason, ['smaller-than-preset', 'too-large'], true)) {
                        $this->cache->put($memoKey, $result->reason, self::BYPASS_MEMO_TTL);
                    } elseif ($result->status === 'bypass' && $result->reason === 'error') {
                        // 손상 이미지·읽을 수 없는 원본을 요청마다 다시 내려받아 디코드하지 않게 짧게 기억한다
                        $this->cache->put($memoKey, 'error', self::ERROR_MEMO_TTL);
                    }

                    return $result;
                }
            );
        } catch (LockTimeoutException) {
            return ThumbnailResult::bypass('error');
        }
    }

    /**
     * 파생본이 있고 원본보다 새로운지 — 로컬 원본만 수정 시각을 비교한다(원격은 HEAD 요청 비용 때문에 보지 않는다).
     *
     * @param  ThumbnailSource  $source  원본
     * @param  string  $disk  썸네일 디스크
     * @param  string  $key  파생본 키
     * @return bool 쓸 수 있으면 true
     */
    public function isFresh(ThumbnailSource $source, string $disk, string $key): bool
    {
        try {
            $thumbnails = $this->store->filesystem($disk);

            if (! $thumbnails->exists($key)) {
                return false;
            }

            if (! $this->store->isLocalDisk($source->disk) || ! $this->store->isLocalDisk($disk)) {
                return true;
            }

            $original = $this->store->filesystem($source->disk);

            return ! $original->exists($source->path) || $original->lastModified($source->path) <= $thumbnails->lastModified($key);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 파생본을 만들어 저장합니다.
     *
     * @param  ThumbnailSource  $source  원본
     * @param  ThumbnailSpec  $spec  규격
     * @param  ImageProcessorInterface  $engine  엔진
     * @param  string  $disk  썸네일 디스크
     * @param  string  $key  파생본 키
     * @param  string  $memoKey  판정 기억 키 (움직이는 원본이면 형식을 남긴다)
     * @return ThumbnailResult 결과
     */
    private function generate(ThumbnailSource $source, ThumbnailSpec $spec, ImageProcessorInterface $engine, string $disk, string $key, string $memoKey): ThumbnailResult
    {
        $input = null;
        $output = false;

        try {
            $input = $this->copyToTemp($source->disk, $source->path);

            if ($input === null) {
                return ThumbnailResult::bypass('error');
            }

            $output = tempnam(self::temporaryDirectory(), 'g7thumb_out_');
            $size = @getimagesize($input);

            if ($size === false) {
                return ThumbnailResult::bypass('not-raster');
            }

            [$width, $height] = [(int) $size[0], (int) $size[1]];

            if ($width * $height > self::maxSourcePixels()) {
                Log::warning('썸네일 원본 픽셀 상한 초과 — 원본을 냅니다', ['disk' => $source->disk, 'path' => $source->path, 'width' => $width, 'height' => $height]);

                return ThumbnailResult::bypass('too-large');
            }

            if (! $spec->upscale && $spec->containsSource($width, $height)) {
                return ThumbnailResult::bypass('smaller-than-preset');
            }

            $animated = self::animatedFormat($input);

            if ($animated !== null) {
                $this->cache->put($memoKey, self::ANIMATED_MEMO_PREFIX.$animated, self::BYPASS_MEMO_TTL);

                if ($this->servesAnimatedOriginal($animated, $source, $spec)) {
                    return ThumbnailResult::bypass('animated');
                }

                // 첫 프레임 썸네일은 정지 이미지 키와 다른 이름 — 판정을 다시 켜면 정지 이미지 키 조회가 비어 원본으로 돌아간다
                $key = $this->store->key($source, $spec, $engine, firstFrame: true);

                if ($this->isFresh($source, $disk, $key)) {
                    return ThumbnailResult::hit($disk, $key, $this->mimeForKey($key));
                }
            }

            $resultMime = $output !== false ? $engine->thumbnail($input, $source->mime, $spec, $output) : null;

            if ($resultMime === null || ! is_file((string) $output) || filesize((string) $output) === 0) {
                if ($animated !== null) {
                    // 엔진이 움직이는 원본의 첫 프레임을 읽지 못한다(GD 의 움직이는 WebP) — 원본을 낸다.
                    // 형식 없는 기억 값이라 다음 요청은 다시 판정하지 않고 원본으로 간다(엔진이 바뀌면 파생본 키가 바뀐다).
                    $this->cache->put($memoKey, 'animated', self::BYPASS_MEMO_TTL);

                    return ThumbnailResult::bypass('animated');
                }

                return ThumbnailResult::bypass('error');
            }

            $stream = fopen((string) $output, 'rb');
            $stored = $stream !== false && $this->store->storage($disk)->put('', $key, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            return $stored ? ThumbnailResult::miss($disk, $key, $resultMime) : ThumbnailResult::bypass('error');
        } catch (\Throwable $e) {
            Log::warning('썸네일 생성 실패 — 원본을 냅니다', ['disk' => $source->disk, 'path' => $source->path, 'preset' => $spec->name, 'error' => $e->getMessage()]);

            return ThumbnailResult::bypass('error');
        } finally {
            if (is_string($input)) {
                @unlink($input);
            }

            if (is_string($output)) {
                @unlink($output);
            }
        }
    }

    /**
     * 원본으로 돌려보낸 사유의 기억 키 — 로컬 원본은 수정 시각을 넣어, 원본을 바꾸면 기억이 자연히 무효가 된다.
     *
     * @param  ThumbnailSource  $source  원본
     * @param  string  $disk  썸네일 디스크
     * @param  string  $key  파생본 키
     * @return string 캐시 키
     */
    private function memoKey(ThumbnailSource $source, string $disk, string $key): string
    {
        $version = '';

        if ($this->store->isLocalDisk($source->disk)) {
            try {
                $version = (string) $this->store->filesystem($source->disk)->lastModified($source->path);
            } catch (\Throwable) {
                $version = '';
            }
        }

        return 'thumb:bypass:'.md5($disk.'|'.$key.'|'.$version);
    }

    /**
     * 디스크 파일을 로컬 임시 파일로 스트림 복사합니다 (원격 디스크 포함 — 통째 적재 금지).
     *
     * @param  string  $disk  디스크
     * @param  string  $path  디스크 위 경로
     * @return string|null 임시 파일 경로 (실패하면 null)
     */
    public function copyToTemp(string $disk, string $path): ?string
    {
        try {
            $read = $this->store->filesystem($disk)->readStream($path);
        } catch (\Throwable) {
            return null;
        }

        if (! is_resource($read)) {
            return null;
        }

        $temp = tempnam(self::temporaryDirectory(), 'g7thumb_src_');
        $write = $temp !== false ? @fopen($temp, 'wb') : false;

        if ($write === false) {
            fclose($read);

            if (is_string($temp)) {
                @unlink($temp);
            }

            return null;
        }

        try {
            $copied = stream_copy_to_stream($read, $write);
        } catch (\Throwable) {
            $copied = false;
        } finally {
            fclose($read);
            fclose($write);
        }

        // 원격 스트림이 일찍 끊기면 잘린 파일이 남는다 — GD 는 잘린 JPEG 도 회색으로 채워 열어, 깨진 썸네일이
        // 영구히 저장된다(원격 원본은 수정 시각을 보지 않는다). 복사가 실패했거나 비었으면 버린다.
        // 일부만 받고 정상 종료한 스트림은 위 검사를 지나므로 원격 원본은 디스크가 말하는 크기와 맞춰 본다.
        if ($copied === false || $copied === 0 || filesize($temp) === 0 || ! $this->copyIsComplete($disk, $path, $copied)) {
            @unlink($temp);

            return null;
        }

        return $temp;
    }

    /**
     * 복사한 바이트 수가 원본 크기와 같은지 — 로컬 디스크는 스트림이 도중에 끊기지 않아 보지 않는다.
     *
     * 크기를 읽지 못하면 맞춰 볼 수 없으니 불완전으로 본다. 원본을 낼 뿐이고, 깨진 썸네일을 영구히 남기는 쪽보다 낫다.
     *
     * @param  string  $disk  디스크
     * @param  string  $path  디스크 위 경로
     * @param  int  $copied  복사한 바이트 수
     * @return bool 온전하면 true
     */
    private function copyIsComplete(string $disk, string $path, int $copied): bool
    {
        if ($this->store->isLocalDisk($disk)) {
            return true;
        }

        try {
            $expected = (int) $this->store->filesystem($disk)->size($path);
        } catch (\Throwable $e) {
            Log::warning('썸네일 원본 크기 확인 실패 — 원본을 냅니다', ['disk' => $disk, 'path' => $path, 'error' => $e->getMessage()]);

            return false;
        }

        if ($copied !== $expected) {
            Log::warning('썸네일 원본 복사가 잘렸습니다 — 원본을 냅니다', ['disk' => $disk, 'path' => $path, 'copied' => $copied, 'expected' => $expected]);

            return false;
        }

        return true;
    }

    /**
     * 임시 파일을 만들 디렉터리 — 호스팅이 `sys_get_temp_dir()` 를 막으면 앱 저장소 아래 임시 디렉터리.
     *
     * @return string 디렉터리 경로
     */
    private static function temporaryDirectory(): string
    {
        if (ExecutionEnvironment::hasFunction('sys_get_temp_dir')) {
            $directory = sys_get_temp_dir();

            if (is_dir($directory) && is_writable($directory)) {
                return $directory;
            }
        }

        $fallback = storage_path('app/tmp/thumbnails');

        if (! is_dir($fallback)) {
            @mkdir($fallback, 0775, true);
        }

        return $fallback;
    }

    /**
     * 생성 잠금 대기 시간(초) — 서버 설정 `core.thumbnail.lock_wait_seconds`(기본 10)를 1초 이상, 잠금 유지 시간 이하로 묶는다.
     *
     * 같은 원본·같은 규격을 다른 요청이 만들고 있을 때 이만큼 기다리고, 넘으면 원본을 낸다(`bypass:error`). 유지 시간보다
     * 길게 기다리면 앞 요청의 잠금이 저절로 풀린 뒤에도 기다리다 같은 파생본을 또 만들 수 있다.
     *
     * @return int 대기 시간(초)
     */
    public static function lockWaitSeconds(): int
    {
        $configured = config('core.thumbnail.lock_wait_seconds', self::LOCK_WAIT_SECONDS);
        $seconds = is_numeric($configured) ? (int) $configured : self::LOCK_WAIT_SECONDS;

        return max(1, min(self::LOCK_SECONDS, $seconds));
    }

    /**
     * 원본 픽셀 상한 — 설정값과 `memory_limit` 중 작은 쪽.
     *
     * GD 는 픽셀당 약 4~5바이트를 쓴다(2000×2000 원본에 약 17~31MB, 6000×6000 에 약 150MB 실측). 상한을 설정값으로만
     * 두면 `memory_limit` 이 작은 서버에서 잡을 수 없는 치명 오류가 난다 — 남은 메모리의 절반 안에서만 연다.
     *
     * @return int 픽셀 수 상한
     */
    private static function maxSourcePixels(): int
    {
        $configured = max(1, (int) config('core.thumbnail.max_source_pixels', 16000000));
        $limit = ExecutionEnvironment::hasFunction('ini_get') ? trim((string) ini_get('memory_limit')) : '';

        if ($limit === '' || $limit === '-1') {
            return $configured;
        }

        $bytes = (int) $limit;
        $unit = strtolower(substr($limit, -1));
        $bytes *= match ($unit) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        $available = max(0, $bytes - memory_get_usage(true));

        return max(1, min($configured, intdiv($available, 2 * 5)));
    }

    /**
     * 움직이는 원본을 썸네일로 바꾸지 않고 원본 그대로 낼지 — 설정 `core.thumbnail.animated_original`(기본 true)을
     * 기본값으로 확장 필터 `core.thumbnail.filter_animated_original` 가 형식·원본·규격별로 바꿀 수 있다. 필터가 bool 이
     * 아닌 값을 돌려주면 설정값을 쓴다.
     *
     * @param  string  $format  움직이는 형식 (gif·webp·png)
     * @param  ThumbnailSource  $source  원본
     * @param  ThumbnailSpec  $spec  규격
     * @return bool 원본을 내면 true, 첫 프레임 썸네일을 만들면 false
     */
    private function servesAnimatedOriginal(string $format, ThumbnailSource $source, ThumbnailSpec $spec): bool
    {
        $default = (bool) config('core.thumbnail.animated_original', true);
        $filtered = HookManager::applyFilters('core.thumbnail.filter_animated_original', $default, $format, $source, $spec);

        // 형제 썸네일 필터와 같이 형태가 틀리면 기본값 — 반환을 빠뜨린 리스너(null)가 판정을 뒤집지 않게
        return is_bool($filtered) ? $filtered : $default;
    }

    /**
     * 움직일 수 있는 형식의 MIME 이면 그 형식 (판정 기억이 없을 때 첫 프레임 키를 찾아볼지 정하는 데만 쓴다)
     *
     * @param  string  $mime  원본 MIME
     * @return string|null gif·webp·png, 아니면 null
     */
    private static function animatedCandidateFormat(string $mime): ?string
    {
        return match (strtolower($mime)) {
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/png', 'image/apng' => 'png',
            default => null,
        };
    }

    /**
     * 움직이는 이미지 형식 — 파일 머리로 GIF·WebP·PNG 를 가린 뒤 프레임이 둘 이상인지 본다(MIME 은 믿지 않는다).
     *
     * @param  string  $path  로컬 파일 경로
     * @return string|null 움직이면 gif·webp·png, 아니면 null
     */
    public static function animatedFormat(string $path): ?string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 12);

        return match (true) {
            str_starts_with($head, 'GIF8') => self::isAnimatedGif($path) ? 'gif' : null,
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => self::isAnimatedWebp($path) ? 'webp' : null,
            str_starts_with($head, self::PNG_SIGNATURE) => self::isAnimatedPng($path) ? 'png' : null,
            default => null,
        };
    }

    /**
     * 움직이는 WebP 인지 — RIFF 청크 머리만 읽으며 건너뛰어 `ANMF`(프레임) 청크가 두 개 이상이면 움직인다.
     *
     * `VP8X` 의 애니메이션 표시만 있고 프레임이 하나면 정지 그림이다.
     *
     * @param  string  $path  WebP 파일 경로
     * @return bool 움직이면 true
     */
    public static function isAnimatedWebp(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            $head = (string) fread($handle, 12);

            if (strlen($head) < 12 || ! str_starts_with($head, 'RIFF') || substr($head, 8, 4) !== 'WEBP') {
                return false;
            }

            $frames = 0;

            // 청크 머리 8바이트(형식 4 + 길이 4)만 읽고 본문은 건너뛴다 — 읽는 양이 파일 크기가 아니라 청크 수에 비례한다
            for ($i = 0; $i < self::MAX_CHUNKS && $frames < 2; $i++) {
                $chunk = fread($handle, 8);

                if ($chunk === false || strlen($chunk) < 8) {
                    break;
                }

                $length = unpack('V', substr($chunk, 4, 4))[1];
                $frames += substr($chunk, 0, 4) === 'ANMF' ? 1 : 0;

                if (fseek($handle, $length + ($length % 2), SEEK_CUR) !== 0) {
                    break;
                }
            }

            return $frames > 1;
        } finally {
            fclose($handle);
        }
    }

    /**
     * 움직이는 PNG(APNG) 인지 — 첫 `IDAT` 앞에 `acTL` 이 있고 프레임 수가 둘 이상이면 움직인다.
     *
     * @param  string  $path  PNG 파일 경로
     * @return bool 움직이면 true
     */
    public static function isAnimatedPng(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            if (fread($handle, 8) !== self::PNG_SIGNATURE) {
                return false;
            }

            // APNG 규격상 acTL 은 IDAT 앞에 온다 — IDAT 를 만나면 정지 PNG 로 끝낸다
            for ($i = 0; $i < self::MAX_CHUNKS; $i++) {
                $chunk = fread($handle, 8);

                if ($chunk === false || strlen($chunk) < 8) {
                    return false;
                }

                $length = unpack('N', substr($chunk, 0, 4))[1];
                $type = substr($chunk, 4, 4);

                if ($type === 'acTL') {
                    $control = (string) fread($handle, 8);

                    return strlen($control) === 8 && unpack('N', substr($control, 0, 4))[1] > 1;
                }

                if ($type === 'IDAT' || $type === 'IEND') {
                    return false;
                }

                // 본문과 CRC(4바이트)를 건너뛴다
                if (fseek($handle, $length + 4, SEEK_CUR) !== 0) {
                    return false;
                }
            }

            return false;
        } finally {
            fclose($handle);
        }
    }

    /**
     * 움직이는 GIF 인지 — 그래픽 제어 확장 블록이 두 개 이상이면 움직인다.
     *
     * 블록 앞 바이트(`\x00`)는 보지 않는다. 반복 표시(NETSCAPE2.0) 블록이 없는 GIF 는 첫 블록 바로 앞이
     * 전역 색상표의 마지막 바이트라 0 이 아닐 수 있고, 앞 바이트까지 요구하면 한 번만 재생하는 2프레임 GIF 를
     * 정지 그림으로 보고 첫 프레임 썸네일을 낸다(D19 위반).
     *
     * @param  string  $path  GIF 파일 경로
     * @return bool 움직이면 true
     */
    public static function isAnimatedGif(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $count = 0;
        $carry = '';

        while (! feof($handle) && $count < 2) {
            $chunk = $carry.fread($handle, 1024 * 100);
            $count += preg_match_all('#\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $chunk);
            // 블록(9바이트)이 청크 경계에 걸쳐도 놓치지 않게 끝 8바이트를 다음 청크에 붙인다.
            // 블록 길이 이상을 넘기면 앞 청크에서 이미 센 블록을 다음 청크에서 또 센다 — 정지 GIF 가 움직이는 것으로 판정된다.
            $carry = substr($chunk, -8);
        }

        fclose($handle);

        return $count > 1;
    }

    /**
     * 파생본 키의 확장자로 MIME 을 정합니다.
     *
     * @param  string  $key  파생본 키
     * @return string MIME
     */
    private function mimeForKey(string $key): string
    {
        return match (strtolower(pathinfo($key, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            default => 'image/png',
        };
    }
}
