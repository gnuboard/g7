<?php

namespace Tests\Feature\Thumbnails\Support;

use App\Thumbnails\Contracts\ThumbnailSourceProvider;
use App\Thumbnails\DTO\ThumbnailSource;

/**
 * 시험용 원본 제공자 — DB 없이 정해 둔 원본 목록을 커서 단위로 돌려준다.
 *
 * 커서는 목록 순번이다. `chunk()` 가 끝에 닿으면 next 가 null 이다(코어 제공자와 같은 계약).
 */
final class FakeThumbnailSourceProvider implements ThumbnailSourceProvider
{
    /**
     * 돌려줄 원본 목록과 판정 기준을 받습니다.
     *
     * @param  list<ThumbnailSource>  $sources  돌려줄 원본
     * @param  string  $key  제공자 키
     * @param  bool  $gated  권한 원본인지
     * @param  string  $pathPrefix  이 제공자 것으로 판정할 경로 접두
     */
    public function __construct(
        private readonly array $sources,
        private readonly string $key = 'test.fake',
        private readonly bool $gated = false,
        private readonly string $pathPrefix = 'fake/',
    ) {}

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return $this->key;
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return 'thumbnail.sources.layout_attachment';
    }

    /**
     * {@inheritDoc}
     */
    public function gated(): bool
    {
        return $this->gated;
    }

    /**
     * {@inheritDoc}
     */
    public function matches(string $disk, string $path): bool
    {
        return str_starts_with(ltrim($path, '/'), $this->pathPrefix);
    }

    /**
     * {@inheritDoc}
     */
    public function count(): int
    {
        return count($this->sources);
    }

    /**
     * {@inheritDoc}
     */
    public function chunk(?string $cursor, int $limit): array
    {
        $offset = (int) $cursor;
        $items = array_slice($this->sources, $offset, $limit);
        $next = $offset + count($items);

        return [
            'items' => $items,
            'next' => $next < count($this->sources) ? (string) $next : null,
        ];
    }
}
