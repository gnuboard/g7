<?php

namespace App\Thumbnails\DTO;

/**
 * 파생본 준비 결과 — 이미 있었다(hit) / 지금 만들었다(miss) / 원본을 내야 한다(bypass + 사유).
 */
final readonly class ThumbnailResult
{
    /** @var list<string> 원본으로 돌려보내는 사유 (`X-G7-Thumbnail: bypass:{사유}`) */
    public const BYPASS_REASONS = [
        'disabled', 'no-engine', 'not-raster', 'animated', 'unknown-preset',
        'smaller-than-preset', 'rate-limited', 'too-large', 'error',
    ];

    /**
     * ThumbnailResult 생성자
     *
     * @param  string  $status  hit·miss·bypass
     * @param  string|null  $reason  bypass 사유
     * @param  string|null  $disk  파생본 디스크
     * @param  string|null  $key  파생본 키
     * @param  string|null  $mime  파생본 MIME
     */
    private function __construct(
        public string $status,
        public ?string $reason = null,
        public ?string $disk = null,
        public ?string $key = null,
        public ?string $mime = null,
    ) {}

    /**
     * 파생본이 이미 있음
     *
     * @param  string  $disk  디스크
     * @param  string  $key  키
     * @param  string  $mime  MIME
     * @return self 결과
     */
    public static function hit(string $disk, string $key, string $mime): self
    {
        return new self('hit', null, $disk, $key, $mime);
    }

    /**
     * 파생본을 지금 만듦
     *
     * @param  string  $disk  디스크
     * @param  string  $key  키
     * @param  string  $mime  MIME
     * @return self 결과
     */
    public static function miss(string $disk, string $key, string $mime): self
    {
        return new self('miss', null, $disk, $key, $mime);
    }

    /**
     * 원본을 내야 함
     *
     * @param  string  $reason  사유 (BYPASS_REASONS)
     * @return self 결과
     */
    public static function bypass(string $reason): self
    {
        return new self('bypass', in_array($reason, self::BYPASS_REASONS, true) ? $reason : 'error');
    }

    /**
     * 파생본을 낼 수 있는지
     *
     * @return bool hit·miss 면 true
     */
    public function served(): bool
    {
        return $this->status !== 'bypass';
    }

    /**
     * `X-G7-Thumbnail` 헤더 값
     *
     * @return string hit·miss·bypass:{사유}
     */
    public function header(): string
    {
        return $this->status === 'bypass' ? 'bypass:'.$this->reason : $this->status;
    }

    /**
     * 진단·로그에 그대로 넘길 수 있는 배열로 돌려줍니다.
     *
     * @return array{status: string, reason: ?string, disk: ?string, key: ?string, mime: ?string} 결과 값
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'reason' => $this->reason,
            'disk' => $this->disk,
            'key' => $this->key,
            'mime' => $this->mime,
        ];
    }
}
