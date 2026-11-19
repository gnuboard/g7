<?php

namespace Plugins\Sirsoft\Popup\Repositories;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\Popup\Models\PopupImage;
use Plugins\Sirsoft\Popup\Repositories\Contracts\PopupImageRepositoryInterface;

/**
 * 팝업 이미지 Repository
 */
class PopupImageRepository implements PopupImageRepositoryInterface
{
    /**
     * {@inheritDoc}
     *
     * @param  array<string, mixed>  $data  저장할 값
     * @return PopupImage 만든 이미지
     */
    public function create(array $data): PopupImage
    {
        return PopupImage::query()->create($data);
    }

    /**
     * {@inheritDoc}
     *
     * @param  int  $id  이미지 ID
     * @return PopupImage|null 이미지 (없으면 null)
     */
    public function find(int $id): ?PopupImage
    {
        return PopupImage::query()->find($id);
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $hash  공개 해시
     * @return PopupImage|null 이미지
     */
    public function findByHash(string $hash): ?PopupImage
    {
        return PopupImage::query()->where('hash', $hash)->first();
    }

    /**
     * {@inheritDoc}
     *
     * @param  int|null  $popupId  팝업 ID
     * @param  string|null  $tempKey  임시 업로드 묶음 키
     * @param  string  $device  기기
     * @return PopupImage|null 이미지
     */
    public function findByOwnerAndDevice(?int $popupId, ?string $tempKey, string $device): ?PopupImage
    {
        $query = PopupImage::query()->where('device', $device);

        if ($popupId !== null) {
            $query->where('popup_id', $popupId);
        } elseif ($tempKey !== null && $tempKey !== '') {
            $query->whereNull('popup_id')->where('temp_key', $tempKey);
        } else {
            return null;
        }

        return $query->latest('id')->first();
    }

    /**
     * {@inheritDoc}
     *
     * @param  PopupImage  $image  붙일 이미지
     * @param  int  $popupId  팝업 ID
     * @return void 반환값 없음
     */
    public function attach(PopupImage $image, int $popupId): void
    {
        $image->forceFill(['popup_id' => $popupId, 'temp_key' => null])->save();
    }

    /**
     * {@inheritDoc}
     *
     * @param  PopupImage  $image  뗄 이미지
     * @param  string  $tempKey  돌려 둘 임시 묶음 키
     * @return void 반환값 없음
     */
    public function release(PopupImage $image, string $tempKey): void
    {
        $image->forceFill(['popup_id' => null, 'temp_key' => $tempKey])->save();
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, int>  $popupIds  팝업 ID 목록
     * @return Collection<int, PopupImage> 이미지 목록
     */
    public function forPopups(array $popupIds): Collection
    {
        // audit:allow query-unbounded-get reason: 팝업마다 기기별 1장(최대 2장) — 일괄 처리 상한 100건 × 2
        return PopupImage::query()->whereIn('popup_id', $popupIds)->get();
    }

    /**
     * {@inheritDoc}
     *
     * @param  PopupImage  $image  대상 이미지
     * @return bool 삭제 여부
     */
    public function delete(PopupImage $image): bool
    {
        return (bool) $image->delete();
    }

    /**
     * {@inheritDoc}
     *
     * @param  CarbonInterface  $before  이 시각보다 먼저 올라온 것
     * @param  int  $limit  최대 건수
     * @return Collection<int, PopupImage> 고아 임시 이미지
     */
    public function staleTempImages(CarbonInterface $before, int $limit): Collection
    {
        return PopupImage::query()
            ->whereNull('popup_id')
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
     * @return Collection<int, PopupImage> 이미지 목록
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
     * 팝업에 붙은 이미지 행 쿼리
     *
     * @param  array<int, string>  $mimeTypes  MIME 목록
     * @return Builder<PopupImage> 쿼리
     */
    private function attachedImages(array $mimeTypes): Builder
    {
        return PopupImage::query()
            ->whereNotNull('popup_id')
            ->whereIn('mime', $mimeTypes);
    }

    /**
     * {@inheritDoc}
     *
     * @return array<int, string> disk 이름
     */
    public function distinctDisks(): array
    {
        return PopupImage::query()
            ->whereNotNull('disk')
            ->where('disk', '!=', '')
            ->distinct()
            ->pluck('disk')
            ->map(fn ($disk) => (string) $disk)
            ->values()
            ->all();
    }
}
