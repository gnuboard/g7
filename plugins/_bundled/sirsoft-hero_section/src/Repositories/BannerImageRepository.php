<?php

namespace Plugins\Sirsoft\HeroSection\Repositories;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerImageRepositoryInterface;

/**
 * 배너 이미지 Repository
 */
class BannerImageRepository implements BannerImageRepositoryInterface
{
    /**
     * {@inheritDoc}
     *
     * @param  array<string, mixed>  $data  저장할 값
     * @return BannerImage 만든 이미지
     */
    public function create(array $data): BannerImage
    {
        return BannerImage::query()->create($data);
    }

    /**
     * {@inheritDoc}
     *
     * @param  int  $id  이미지 ID
     * @return BannerImage 이미지
     */
    public function findOrFail(int $id): BannerImage
    {
        return BannerImage::query()->findOrFail($id);
    }

    /**
     * {@inheritDoc}
     *
     * @param  int  $id  이미지 ID
     * @return BannerImage|null 이미지 (없으면 null)
     */
    public function find(int $id): ?BannerImage
    {
        return BannerImage::query()->find($id);
    }

    /**
     * {@inheritDoc}
     *
     * @param  int  $id  이미지 ID
     * @return BannerImage|null 이미지 (없으면 null)
     */
    public function findForUpdate(int $id): ?BannerImage
    {
        return BannerImage::query()->lockForUpdate()->find($id);
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $hash  공개 해시
     * @return BannerImage|null 이미지
     */
    public function findByHash(string $hash): ?BannerImage
    {
        return BannerImage::query()->where('hash', $hash)->first();
    }

    /**
     * {@inheritDoc}
     *
     * @param  int|null  $bannerId  배너 ID
     * @param  string|null  $tempKey  임시 업로드 묶음 키
     * @param  string  $device  기기
     * @param  string  $kind  파일 종류
     * @return BannerImage|null 파일
     */
    public function findByOwnerAndDevice(?int $bannerId, ?string $tempKey, string $device, string $kind = 'image'): ?BannerImage
    {
        $query = BannerImage::query()->where('device', $device)->where('kind', $kind);

        if ($bannerId !== null) {
            $query->where('banner_id', $bannerId);
        } elseif ($tempKey !== null && $tempKey !== '') {
            $query->whereNull('banner_id')->where('temp_key', $tempKey);
        } else {
            return null;
        }

        return $query->latest('id')->first();
    }

    /**
     * {@inheritDoc}
     *
     * @param  BannerImage  $image  붙일 이미지
     * @param  int  $bannerId  배너 ID
     * @return void 반환값 없음
     */
    public function attach(BannerImage $image, int $bannerId): void
    {
        $image->forceFill(['banner_id' => $bannerId, 'temp_key' => null])->save();
    }

    /**
     * {@inheritDoc}
     *
     * @param  BannerImage  $image  뗄 이미지
     * @param  string  $tempKey  돌려 둘 임시 묶음 키
     * @return void 반환값 없음
     */
    public function release(BannerImage $image, string $tempKey): void
    {
        $image->forceFill(['banner_id' => null, 'temp_key' => $tempKey])->save();
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, int>  $bannerIds  배너 ID 목록
     * @return Collection<int, BannerImage> 이미지 목록
     */
    public function forBanners(array $bannerIds): Collection
    {
        return BannerImage::query()->whereIn('banner_id', $bannerIds)->get();
    }

    /**
     * {@inheritDoc}
     *
     * @param  BannerImage  $image  대상 이미지
     * @return bool 삭제 여부
     */
    public function delete(BannerImage $image): bool
    {
        return (bool) $image->delete();
    }

    /**
     * {@inheritDoc}
     *
     * @param  CarbonInterface  $before  이 시각보다 먼저 올라온 것
     * @param  int  $limit  최대 건수
     * @return Collection<int, BannerImage> 고아 임시 이미지
     */
    public function staleTempImages(CarbonInterface $before, int $limit): Collection
    {
        return BannerImage::query()
            ->whereNull('banner_id')
            ->whereNotNull('temp_key')
            ->where('created_at', '<', $before)
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, string>  $mimeTypes  MIME 목록
     * @return int 개수
     */
    public function countAttachedImages(array $mimeTypes): int
    {
        return $this->attachedImages($mimeTypes)->count();
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, string>  $mimeTypes  MIME 목록
     * @param  int  $afterId  이 id 다음부터
     * @param  int  $limit  최대 건수
     * @return Collection<int, BannerImage> 이미지 목록
     */
    public function chunkAttachedImages(array $mimeTypes, int $afterId, int $limit): Collection
    {
        return $this->attachedImages($mimeTypes)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * 배너에 붙은 이미지 종류 행 쿼리
     *
     * @param  array<int, string>  $mimeTypes  MIME 목록
     * @return Builder<BannerImage> 쿼리
     */
    private function attachedImages(array $mimeTypes): Builder
    {
        return BannerImage::query()
            ->whereNotNull('banner_id')
            ->where('kind', 'image')
            ->whereIn('mime', $mimeTypes);
    }

    /**
     * {@inheritDoc}
     *
     * @return array<int, string> disk 이름
     */
    public function distinctDisks(): array
    {
        return BannerImage::query()
            ->whereNotNull('disk')
            ->where('disk', '!=', '')
            ->distinct()
            ->pluck('disk')
            ->map(fn ($disk) => (string) $disk)
            ->values()
            ->all();
    }
}
