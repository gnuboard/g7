<?php

namespace Plugins\Sirsoft\HeroSection\Repositories;

use Carbon\CarbonInterface;
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
     * @param  string  $hash  공개 해시
     * @return BannerImage|null 이미지
     */
    public function findPublicByHash(string $hash): ?BannerImage
    {
        return BannerImage::query()->where('hash', $hash)->whereNotNull('banner_id')->first();
    }

    /**
     * {@inheritDoc}
     *
     * @param  int|null  $bannerId  배너 ID
     * @param  string|null  $tempKey  임시 업로드 묶음 키
     * @param  string  $device  기기
     * @return BannerImage|null 이미지
     */
    public function findByOwnerAndDevice(?int $bannerId, ?string $tempKey, string $device): ?BannerImage
    {
        $query = BannerImage::query()->where('device', $device);

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
     * @param  string  $tempKey  임시 업로드 묶음 키
     * @param  int  $bannerId  배너 ID
     * @return int 연결한 건수
     */
    public function linkTempImages(string $tempKey, int $bannerId): int
    {
        return BannerImage::query()
            ->whereNull('banner_id')
            ->where('temp_key', $tempKey)
            ->update(['banner_id' => $bannerId, 'temp_key' => null, 'updated_at' => now()]);
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
}
