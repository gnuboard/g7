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
     * id 로 이미지를 찾습니다.
     *
     * @param  int  $id  이미지 ID
     * @return BannerImage|null 이미지 (없으면 null)
     */
    public function find(int $id): ?BannerImage;

    /**
     * id 로 이미지를 찾아 행을 잠급니다 — 트랜잭션 안에서 붙이기 직전에 다른 요청이 먼저 가져갔는지 다시 보려고 쓴다.
     *
     * @param  int  $id  이미지 ID
     * @return BannerImage|null 이미지 (없으면 null)
     */
    public function findForUpdate(int $id): ?BannerImage;

    /**
     * 공개 해시로 이미지를 찾습니다 — 저장 전 미리보기가 임시 업로드도 그려야 해서 연결 여부를 가리지 않는다.
     *
     * @param  string  $hash  공개 해시
     * @return BannerImage|null 이미지
     */
    public function findByHash(string $hash): ?BannerImage;

    /**
     * 배너 또는 임시 묶음의 기기·종류별 파일을 찾습니다.
     *
     * @param  int|null  $bannerId  배너 ID
     * @param  string|null  $tempKey  임시 업로드 묶음 키
     * @param  string  $device  기기 (pc, mobile)
     * @param  string  $kind  파일 종류 (image 기본, video)
     * @return BannerImage|null 파일
     */
    public function findByOwnerAndDevice(?int $bannerId, ?string $tempKey, string $device, string $kind = 'image'): ?BannerImage;

    /**
     * 이미지 한 장을 배너에 붙입니다(임시 묶음에서 뺀다).
     *
     * @param  BannerImage  $image  붙일 이미지
     * @param  int  $bannerId  배너 ID
     * @return void 반환값 없음
     */
    public function attach(BannerImage $image, int $bannerId): void;

    /**
     * 이미지를 배너에서 떼어 임시 이미지로 돌립니다 — 파일 삭제가 실패해도 고아 임시 이미지 정리가 다시 지운다.
     *
     * @param  BannerImage  $image  뗄 이미지
     * @param  string  $tempKey  돌려 둘 임시 묶음 키
     * @return void 반환값 없음
     */
    public function release(BannerImage $image, string $tempKey): void;

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

    /**
     * 배너에 붙은 이미지(영상 제외) 중 주어진 MIME 의 수를 셉니다 — 썸네일 「기존 이미지 썸네일 만들기」 집계용.
     *
     * 임시 업로드는 세지 않는다 — 저장하지 않은 이미지까지 미리 만들면 정리 작업이 지울 파일에 CPU 를 쓴다.
     *
     * @param  array<int, string>  $mimeTypes  MIME 목록 (래스터 이미지)
     * @return int 개수
     */
    public function countAttachedImages(array $mimeTypes): int;

    /**
     * 배너에 붙은 이미지(영상 제외)를 id 순으로 나눠 읽습니다 — 썸네일 「기존 이미지 썸네일 만들기」 커서 순회용.
     *
     * @param  array<int, string>  $mimeTypes  MIME 목록 (래스터 이미지)
     * @param  int  $afterId  이 id 다음부터 (처음이면 0)
     * @param  int  $limit  최대 건수 (1 미만이면 1)
     * @return Collection<int, BannerImage> 이미지 목록
     */
    public function chunkAttachedImages(array $mimeTypes, int $afterId, int $limit): Collection;

    /**
     * 행에 기록된 disk 이름을 중복 없이 반환합니다 — 「데이터까지 삭제」 때 모든 디스크의 폴더를 비우는 데 쓴다.
     *
     * @return array<int, string> disk 이름 (빈 값 제외)
     */
    public function distinctDisks(): array;
}
