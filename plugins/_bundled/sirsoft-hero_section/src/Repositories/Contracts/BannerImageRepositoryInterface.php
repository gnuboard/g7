<?php

namespace Plugins\Sirsoft\HeroSection\Repositories\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;

/**
 * 배너 이미지 Repository 인터페이스
 */
interface BannerImageRepositoryInterface
{
    /**
     * 이미지 행을 만듭니다.
     *
     * @param  array<string, mixed>  $data  저장할 값
     * @return BannerImage 만든 이미지
     */
    public function create(array $data): BannerImage;

    /**
     * id 로 이미지를 찾습니다.
     *
     * @param  int  $id  이미지 ID
     * @return BannerImage 이미지
     *
     * @throws ModelNotFoundException 없을 때
     */
    public function findOrFail(int $id): BannerImage;

    /**
     * 공개 해시로 배너에 연결된 이미지를 찾습니다(임시 업로드는 공개하지 않는다).
     *
     * @param  string  $hash  공개 해시
     * @return BannerImage|null 이미지
     */
    public function findPublicByHash(string $hash): ?BannerImage;

    /**
     * 배너 또는 임시 묶음의 기기별 이미지를 찾습니다.
     *
     * @param  int|null  $bannerId  배너 ID
     * @param  string|null  $tempKey  임시 업로드 묶음 키
     * @param  string  $device  기기 (pc, mobile)
     * @return BannerImage|null 이미지
     */
    public function findByOwnerAndDevice(?int $bannerId, ?string $tempKey, string $device): ?BannerImage;

    /**
     * 임시 업로드를 배너에 연결합니다.
     *
     * @param  string  $tempKey  임시 업로드 묶음 키
     * @param  int  $bannerId  배너 ID
     * @return int 연결한 건수
     */
    public function linkTempImages(string $tempKey, int $bannerId): int;

    /**
     * 배너들의 이미지를 모두 반환합니다.
     *
     * @param  array<int, int>  $bannerIds  배너 ID 목록
     * @return Collection<int, BannerImage> 이미지 목록
     */
    public function forBanners(array $bannerIds): Collection;

    /**
     * 이미지 행을 지웁니다(파일은 서비스가 먼저 지운다).
     *
     * @param  BannerImage  $image  대상 이미지
     * @return bool 삭제 여부
     */
    public function delete(BannerImage $image): bool;

    /**
     * 기준 시각 이전에 올라와 끝내 배너에 연결되지 않은 임시 이미지를 반환합니다.
     *
     * @param  CarbonInterface  $before  이 시각보다 먼저 올라온 것
     * @param  int  $limit  최대 건수
     * @return Collection<int, BannerImage> 고아 임시 이미지
     */
    public function staleTempImages(CarbonInterface $before, int $limit): Collection;
}
