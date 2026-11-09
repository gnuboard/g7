<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\ContentMediaOwnerType;
use Modules\Sirsoft\Ecommerce\Models\ContentMedia;

/**
 * 이커머스 본문 미디어 Repository 인터페이스
 */
interface ContentMediaRepositoryInterface
{
    /**
     * 본문 미디어 행을 만듭니다.
     *
     * @param  array<string, mixed>  $data  행 데이터
     * @return ContentMedia 만든 행
     */
    public function create(array $data): ContentMedia;

    /**
     * 임시 키로 올린, 아직 잇지 않은 그 소유 종류의 본문 미디어 수.
     *
     * @param  ContentMediaOwnerType  $ownerType  소유 종류
     * @param  string  $tempKey  임시 키
     * @return int 개수
     */
    public function countTemp(ContentMediaOwnerType $ownerType, string $tempKey): int;

    /**
     * 임시 키로 올린 임시 본문 미디어 중 해시가 맞는 것.
     *
     * @param  ContentMediaOwnerType  $ownerType  소유 종류
     * @param  string  $tempKey  임시 키
     * @param  array<int, string>  $hashes  해시
     * @return Collection<int, ContentMedia> 임시 본문 미디어
     */
    public function getTempByHashes(ContentMediaOwnerType $ownerType, string $tempKey, array $hashes): Collection;

    /**
     * 소유자에 이어진 본문 미디어.
     *
     * @param  ContentMediaOwnerType  $ownerType  소유 종류
     * @param  int  $ownerId  소유자 ID
     * @return Collection<int, ContentMedia> 본문 미디어
     */
    public function getByOwner(ContentMediaOwnerType $ownerType, int $ownerId): Collection;

    /**
     * 해시로 찾습니다.
     *
     * @param  string  $hash  해시
     * @return ContentMedia|null 본문 미디어
     */
    public function findByHash(string $hash): ?ContentMedia;

    /**
     * 임시 본문 미디어를 소유자에 잇습니다.
     *
     * @param  ContentMedia  $media  임시 본문 미디어
     * @param  int  $ownerId  소유자 ID
     * @return void 반환값 없음
     */
    public function link(ContentMedia $media, int $ownerId): void;

    /**
     * 소프트 삭제합니다(본문에서 빠진 미디어).
     *
     * @param  ContentMedia  $media  본문 미디어
     * @return void 반환값 없음
     */
    public function softDelete(ContentMedia $media): void;

    /**
     * 끝내 잇지 않은 오래된 임시 행.
     *
     * @param  Carbon  $threshold  기준 시각
     * @param  int  $limit  최대 건수
     * @return Collection<int, ContentMedia> 임시 행
     */
    public function findStaleTemp(Carbon $threshold, int $limit): Collection;

    /**
     * 행을 영구 삭제합니다.
     *
     * @param  ContentMedia  $media  본문 미디어
     * @return bool 성공 여부
     */
    public function forceDelete(ContentMedia $media): bool;
}
