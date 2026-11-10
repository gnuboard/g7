<?php

namespace Modules\Sirsoft\Ecommerce\Thumbnails;

use App\Extension\Storage\ModuleStorageDriver;
use App\Helpers\InlineMediaPolicy;
use App\Thumbnails\Contracts\ThumbnailSourceProvider;
use App\Thumbnails\DTO\ThumbnailSource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * 이커머스 이미지 썸네일 원본 제공자 공용 골격
 *
 * 상품·카테고리·리뷰·클레임 이미지는 모두 모듈 스토리지 `images` 카테고리에 있다. 드라이버가 디스크 위에 쓰는
 * 전체 경로는 `sirsoft-ecommerce/images/{행 path}` 이고, 행 path 의 첫 조각(`products/`·`category/`·
 * `reviews/`·`claims/`)이 종류를 가른다 — 그래서 `matches()` 는 경로 접두로 판정한다.
 *
 * 디스크 이름으로는 거르지 않는다. 운영자가 이미지 디스크를 바꿔도 이전 행은 옛 disk 를 그대로 기록하고 그
 * 자리에서 계속 서빙되므로(`ResolvesRowStorage`), 지금 디스크만 내 것으로 치면 옛 행의 파생본이 어느 분류에도
 * 들지 않아 캐시 관리 화면의 집계와 비우기에서 빠진다. 경로 접두가 모듈 식별자로 시작하므로 다른 확장·코어
 * 원본과 겹치지 않는다.
 */
abstract class EcommerceImageSourceProvider implements ThumbnailSourceProvider
{
    /** 모듈 스토리지 `images` 카테고리의 디스크 위 접두 — imagesRoot() 가 처음 쓸 때 드라이버에서 얻는다 */
    private ?string $imagesRoot = null;

    /**
     * 이 종류의 행 path 접두 (예: `products/`)
     *
     * @return string 접두
     */
    abstract protected function pathPrefix(): string;

    /**
     * 래스터 이미지 행 수를 셉니다.
     *
     * @param  list<string>  $mimeTypes  MIME 목록
     * @return int 행 수
     */
    abstract protected function countRows(array $mimeTypes): int;

    /**
     * 래스터 이미지 행을 id 순으로 나눠 읽습니다.
     *
     * @param  list<string>  $mimeTypes  MIME 목록
     * @param  int  $afterId  이 id 보다 큰 행부터
     * @param  int  $limit  최대 행 수
     * @return Collection<int, Model> 행
     */
    abstract protected function chunkRows(array $mimeTypes, int $afterId, int $limit): Collection;

    /**
     * 행의 썸네일 원본
     *
     * @param  Model  $row  이미지 행
     * @return ThumbnailSource|null 원본 (만들 수 없으면 null)
     */
    abstract protected function sourceForRow(Model $row): ?ThumbnailSource;

    /**
     * 이 종류에서 빼는 행 path 접두 (임시 업로드 등)
     *
     * @return list<string> 접두
     */
    protected function excludedPrefixes(): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function matches(string $disk, string $path): bool
    {
        $normalized = ltrim(str_replace('\\', '/', $path), '/');

        $root = $this->imagesRoot();

        if (! str_starts_with($normalized, $root)) {
            return false;
        }

        $relative = substr($normalized, strlen($root));

        if (! str_starts_with($relative, $this->pathPrefix())) {
            return false;
        }

        foreach ($this->excludedPrefixes() as $excluded) {
            if (str_starts_with($relative, $excluded)) {
                return false;
            }
        }

        return true;
    }

    /**
     * `images` 카테고리의 디스크 위 접두 — 저장 경로 규칙은 드라이버가 소유하므로 그 값을 받는다(디스크와 무관하다).
     *
     * @return string 접두 (끝 `/` 포함)
     */
    private function imagesRoot(): string
    {
        return $this->imagesRoot ??= (new ModuleStorageDriver('sirsoft-ecommerce'))->thumbnailSourceKey('images', '')['path'].'/';
    }

    /**
     * {@inheritDoc}
     */
    public function count(): int
    {
        return $this->countRows(InlineMediaPolicy::RASTER_IMAGE_MIME_TYPES);
    }

    /**
     * {@inheritDoc}
     */
    public function chunk(?string $cursor, int $limit): array
    {
        $rows = $this->chunkRows(InlineMediaPolicy::RASTER_IMAGE_MIME_TYPES, (int) $cursor, $limit);

        return [
            'items' => $rows->map(fn (Model $row) => $this->sourceForRow($row))->filter()->values()->all(),
            'next' => $rows->count() < $limit ? null : (string) $rows->last()?->getKey(),
        ];
    }
}
