<?php

namespace App\Contracts\Extension;

/**
 * 썸네일 원본을 내줄 수 있는 스토리지 드라이버 (코어·모듈·플러그인 드라이버가 구현)
 *
 * `StorageInterface` 를 넓히지 않고 작은 인터페이스로 둔다 — 그 인터페이스만 구현한 서드파티 저장소와
 * 테스트 대역이 깨지지 않게. 이 인터페이스가 없는 저장소의 이미지는 썸네일 없이 원본을 낸다.
 */
interface ProvidesThumbnailSource
{
    /**
     * 카테고리·상대 경로가 디스크 위 어디에 있는지 돌려줍니다 (파생본 키의 근거).
     *
     * @param  string  $category  카테고리
     * @param  string  $path  카테고리 하위 경로
     * @return array{disk: string, path: string} 디스크 이름과 디스크 위 전체 경로
     */
    public function thumbnailSourceKey(string $category, string $path): array;

    /**
     * 원본을 로컬 임시 파일로 복사합니다 (원격 디스크 포함, 스트림 복사 — 통째 적재 금지).
     *
     * @param  string  $category  카테고리
     * @param  string  $path  카테고리 하위 경로
     * @return string|null 임시 파일 경로 (호출자가 지운다, 실패하면 null)
     */
    public function copyToLocalTemp(string $category, string $path): ?string;
}
