<?php

namespace Plugins\Sirsoft\Popup\Repositories\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\Popup\Models\PopupImage;

/**
 * 팝업 이미지 Repository 계약.
 */
interface PopupImageRepositoryInterface
{
    /**
     * 이미지 행을 만듭니다.
     *
     * @param  array<string, mixed>  $data  저장할 값
     * @return PopupImage 만든 이미지
     */
    public function create(array $data): PopupImage;

    /**
     * 이미지를 찾습니다.
     *
     * @param  int  $id  이미지 ID
     * @return PopupImage|null 이미지 (없으면 null)
     */
    public function find(int $id): ?PopupImage;

    /**
     * 공개 해시로 이미지를 찾습니다.
     *
     * @param  string  $hash  공개 해시
     * @return PopupImage|null 이미지 (없으면 null)
     */
    public function findByHash(string $hash): ?PopupImage;

    /**
     * 팝업 또는 임시 묶음의 기기별 가장 최근 이미지를 찾습니다.
     *
     * @param  int|null  $popupId  팝업 ID
     * @param  string|null  $tempKey  임시 업로드 묶음 키 (팝업 ID 가 없을 때)
     * @param  string  $device  기기 (pc, mobile)
     * @return PopupImage|null 이미지 (없으면 null)
     */
    public function findByOwnerAndDevice(?int $popupId, ?string $tempKey, string $device): ?PopupImage;

    /**
     * 이미지를 팝업에 붙이고 임시 묶음 키를 비웁니다.
     *
     * @param  PopupImage  $image  붙일 이미지
     * @param  int  $popupId  팝업 ID
     * @return void 반환값 없음
     */
    public function attach(PopupImage $image, int $popupId): void;

    /**
     * 이미지를 팝업에서 떼어 임시 이미지로 돌립니다.
     *
     * @param  PopupImage  $image  뗄 이미지
     * @param  string  $tempKey  돌려 둘 임시 묶음 키
     * @return void 반환값 없음
     */
    public function release(PopupImage $image, string $tempKey): void;

    /**
     * 여러 팝업의 이미지를 반환합니다.
     *
     * @param  array<int, int>  $popupIds  팝업 ID 목록
     * @return Collection<int, PopupImage> 이미지 목록
     */
    public function forPopups(array $popupIds): Collection;

    /**
     * 이미지 행을 지웁니다.
     *
     * @param  PopupImage  $image  대상 이미지
     * @return bool 삭제 여부
     */
    public function delete(PopupImage $image): bool;

    /**
     * 기간이 지난 고아 임시 이미지를 반환합니다.
     *
     * @param  CarbonInterface  $before  이 시각보다 먼저 올라온 것
     * @param  int  $limit  최대 건수
     * @return Collection<int, PopupImage> 고아 임시 이미지
     */
    public function staleTempImages(CarbonInterface $before, int $limit): Collection;

    /**
     * 팝업에 붙은 이미지 중 주어진 MIME 의 수를 셉니다 — 썸네일 「기존 이미지 썸네일 만들기」 집계용.
     *
     * 임시 업로드는 세지 않는다 — 저장하지 않은 이미지까지 미리 만들면 정리 작업이 지울 파일에 CPU 를 쓴다.
     *
     * @param  array<int, string>  $mimeTypes  MIME 목록 (래스터 이미지)
     * @return int 개수
     */
    public function countAttachedImages(array $mimeTypes): int;

    /**
     * 팝업에 붙은 이미지를 id 순으로 나눠 읽습니다 — 썸네일 「기존 이미지 썸네일 만들기」 커서 순회용.
     *
     * @param  array<int, string>  $mimeTypes  MIME 목록 (래스터 이미지)
     * @param  int  $afterId  이 id 다음부터 (처음이면 0)
     * @param  int  $limit  최대 건수 (1 미만이면 1)
     * @return Collection<int, PopupImage> 이미지 목록
     */
    public function chunkAttachedImages(array $mimeTypes, int $afterId, int $limit): Collection;

    /**
     * 행에 기록된 disk 이름을 중복 없이 반환합니다 — 「데이터까지 삭제」 때 모든 디스크의 폴더를 비우는 데 쓴다.
     *
     * @return array<int, string> disk 이름 (빈 값 제외)
     */
    public function distinctDisks(): array;
}
