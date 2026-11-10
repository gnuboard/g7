<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\ContentMediaOwnerType;
use Modules\Sirsoft\Ecommerce\Models\ContentMedia;

/**
 * 이커머스 본문 미디어 Repository 인터페이스
 *
 * 저장은 이 모듈 범용 첨부 표(`ecommerce_attachments`)의 본문 미디어 행이다(`ContentMedia` 범위 모델 — collection = content_media, #887).
 */
interface ContentMediaRepositoryInterface
{
    /**
     * 본문 미디어 행을 만듭니다 — 컬렉션(`content_media`)과 해시는 모델이 채운다.
     *
     * @param  ContentMediaOwnerType  $ownerType  소유 종류
     * @param  int|null  $ownerId  소유자 ID (null: 저장 전 임시)
     * @param  array<string, mixed>  $data  행 데이터 (`ecommerce_attachments` 열 이름 — temp_key·disk·path·파일 정보·meta·order·created_by)
     * @return ContentMedia 만든 행
     */
    public function create(ContentMediaOwnerType $ownerType, ?int $ownerId, array $data): ContentMedia;

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
     * 소유자에 이어진 본문 미디어 — 본문에서 빠져 소프트 삭제된 것까지 (소유자 삭제 때 영구 삭제 대상, #887).
     *
     * @param  ContentMediaOwnerType  $ownerType  소유 종류
     * @param  int  $ownerId  소유자 ID
     * @return Collection<int, ContentMedia> 본문 미디어 (소프트 삭제 포함)
     */
    public function getByOwnerWithTrashed(ContentMediaOwnerType $ownerType, int $ownerId): Collection;

    /**
     * 해시로 찾습니다.
     *
     * @param  string  $hash  해시
     * @return ContentMedia|null 본문 미디어
     */
    public function findByHash(string $hash): ?ContentMedia;

    /**
     * 임시 본문 미디어를 소유자에 잇습니다(임시 키를 지운다).
     *
     * @param  ContentMedia  $media  임시 본문 미디어
     * @param  int  $ownerId  소유자 ID
     * @param  int  $order  본문 안 순서 (1부터, 모르면 0)
     * @return void 반환값 없음
     */
    public function link(ContentMedia $media, int $ownerId, int $order = 0): void;

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
     * 본문에서 빠져 소프트 삭제된 지 오래된 행 — 소유자에 이어졌던 것만 (본문 미디어 파일 정리, #887).
     *
     * @param  Carbon  $threshold  기준 시각 (이보다 먼저 소프트 삭제된 것)
     * @param  int  $limit  최대 건수
     * @return Collection<int, ContentMedia> 소프트 삭제 행
     */
    public function findStaleRemoved(Carbon $threshold, int $limit): Collection;

    /**
     * 행을 영구 삭제합니다.
     *
     * @param  ContentMedia  $media  본문 미디어
     * @return bool 성공 여부
     */
    public function forceDelete(ContentMedia $media): bool;
}
