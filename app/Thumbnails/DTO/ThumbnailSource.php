<?php

namespace App\Thumbnails\DTO;

use App\Contracts\Extension\ProvidesThumbnailSource;
use App\Contracts\Extension\StorageInterface;

/**
 * 썸네일을 만들 원본 하나 — 행(disk·경로·MIME)과 게이트 여부.
 *
 * `path` 는 드라이버가 디스크 위에 실제로 쓴 **전체 경로**다(모듈 드라이버면 `{identifier}/{category}/…`).
 * 파생본 키(`thumbnails/{disk}/{path}/…`)와 저장소 계층 무효화가 같은 값을 써야 원본 삭제가 파생본까지 닿는다.
 */
final readonly class ThumbnailSource
{
    /**
     * ThumbnailSource 생성자
     *
     * @param  string  $disk  원본 디스크 이름
     * @param  string  $path  디스크 위 원본 전체 경로
     * @param  string  $mime  원본 MIME
     * @param  bool  $gated  권한이 걸린 원본인지 (true 면 파생본은 비공개 디스크·`private` 캐시)
     * @param  int|null  $width  원본 가로 (알면)
     * @param  int|null  $height  원본 세로 (알면)
     * @param  int|null  $maxAge  썸네일 캐시 유지 상한(초) — 원본이 더 짧게 캐시되는 통로(예: 클레임 증빙 300초)는
     *                            그 값을 넘겨 썸네일이 원본보다 오래 브라우저에 남지 않게 한다 (null 이면 레이아웃 캐시 TTL)
     */
    public function __construct(
        public string $disk,
        public string $path,
        public string $mime,
        public bool $gated,
        public ?int $width = null,
        public ?int $height = null,
        public ?int $maxAge = null,
    ) {}

    /**
     * 스토리지 드라이버의 카테고리·상대 경로로 원본을 만듭니다.
     *
     * 드라이버가 `ProvidesThumbnailSource` 를 구현하지 않으면(서드파티 저장소) null — 썸네일 없이 원본을 낸다.
     *
     * @param  StorageInterface  $storage  원본 행의 스토리지 드라이버 (행 disk 로 맞춘 것)
     * @param  string  $category  카테고리
     * @param  string  $path  카테고리 하위 경로
     * @param  string|null  $mime  원본 MIME
     * @param  bool  $gated  게이트 원본인지
     * @param  int|null  $width  원본 가로
     * @param  int|null  $height  원본 세로
     * @param  int|null  $maxAge  썸네일 캐시 유지 상한(초, null 이면 레이아웃 캐시 TTL)
     * @return self|null 원본 (만들 수 없으면 null)
     */
    public static function fromStorage(
        StorageInterface $storage,
        string $category,
        string $path,
        ?string $mime,
        bool $gated,
        ?int $width = null,
        ?int $height = null,
        ?int $maxAge = null,
    ): ?self {
        if (! $storage instanceof ProvidesThumbnailSource || $mime === null || $mime === '') {
            return null;
        }

        try {
            $key = $storage->thumbnailSourceKey($category, $path);
        } catch (\Throwable) {
            return null;
        }

        return new self($key['disk'], $key['path'], $mime, $gated, $width, $height, $maxAge);
    }

    /**
     * 진단·로그에 그대로 넘길 수 있는 배열로 돌려줍니다.
     *
     * @return array{disk: string, path: string, mime: string, gated: bool, width: ?int, height: ?int, max_age: ?int} 원본 값
     */
    public function toArray(): array
    {
        return [
            'disk' => $this->disk,
            'path' => $this->path,
            'mime' => $this->mime,
            'gated' => $this->gated,
            'width' => $this->width,
            'height' => $this->height,
            'max_age' => $this->maxAge,
        ];
    }
}
