<?php

namespace App\Contracts\Repositories;

use App\Models\FieldValue;
use Illuminate\Support\Collection;

/**
 * field_values 테이블 Repository 계약.
 *
 * @since 7.2.0
 */
interface FieldValueRepositoryInterface
{
    /**
     * 소유자 여럿의 값 행을 한 번에 읽습니다.
     *
     * @param  string  $ownerType  소유자 종류
     * @param  array<int, int>  $ownerIds  소유자 ID 목록
     * @param  array<int, int>  $definitionIds  항목 ID 목록 (비우면 전부)
     * @return Collection<int, FieldValue> 값 행
     */
    public function forOwners(string $ownerType, array $ownerIds, array $definitionIds = []): Collection;

    /**
     * 값 한 행을 저장합니다 (있으면 갱신, 없으면 생성).
     *
     * @param  int  $definitionId  항목 ID
     * @param  string  $ownerType  소유자 종류
     * @param  int  $ownerId  소유자 ID
     * @param  array<string, mixed>  $columns  값 칸 (value_text 등, unique_key 포함)
     * @return FieldValue 저장한 행
     */
    public function upsert(int $definitionId, string $ownerType, int $ownerId, array $columns): FieldValue;

    /**
     * 값 한 행을 지웁니다.
     *
     * @param  int  $definitionId  항목 ID
     * @param  string  $ownerType  소유자 종류
     * @param  int  $ownerId  소유자 ID
     * @return int 지운 행 수
     */
    public function deleteOne(int $definitionId, string $ownerType, int $ownerId): int;

    /**
     * 소유자의 모든 값 행을 지웁니다 (파기).
     *
     * @param  string  $ownerType  소유자 종류
     * @param  int  $ownerId  소유자 ID
     * @return int 지운 행 수
     */
    public function deleteForOwner(string $ownerType, int $ownerId): int;

    /**
     * 소유자 ID 묶음의 모든 값 행을 한 번에 지웁니다 (게시판 삭제처럼 여러 소유자를 함께 파기할 때).
     *
     * @param  string  $ownerType  소유자 종류
     * @param  array<int, int>  $ownerIds  소유자 ID 목록 (비면 지우지 않는다)
     * @return int 지운 행 수
     */
    public function deleteForOwners(string $ownerType, array $ownerIds): int;

    /**
     * 항목의 모든 값 행을 지웁니다 (항목 삭제).
     *
     * @param  int  $definitionId  항목 ID
     * @return int 지운 행 수
     */
    public function deleteForDefinition(int $definitionId): int;

    /**
     * 항목에 값이 있는 소유자 수를 반환합니다.
     *
     * @param  int  $definitionId  항목 ID
     * @return int 값 행 수
     */
    public function countForDefinition(int $definitionId): int;

    /**
     * 선택지별 사용 수를 반환합니다 (색인 값 => 행 수).
     *
     * @param  int  $definitionId  항목 ID
     * @return array<string, int> 색인 값 => 행 수
     */
    public function countByIndex(int $definitionId): array;

    /**
     * 중복 금지 키가 다른 소유자에게 이미 있는지 반환합니다.
     *
     * @param  int  $definitionId  항목 ID
     * @param  string  $uniqueKey  중복 비교 키
     * @param  string|null  $exceptOwnerType  제외할 소유자 종류
     * @param  int|null  $exceptOwnerId  제외할 소유자 ID
     * @return bool 이미 있으면 true
     */
    public function uniqueKeyExists(int $definitionId, string $uniqueKey, ?string $exceptOwnerType = null, ?int $exceptOwnerId = null): bool;

    /**
     * 특정 색인 값을 가진 행을 지웁니다 (선택지 삭제).
     *
     * @param  int  $definitionId  항목 ID
     * @param  array<int, string>  $indexValues  지울 색인 값 목록
     * @return int 지운 행 수
     */
    public function deleteByIndex(int $definitionId, array $indexValues): int;

    /**
     * 항목의 값 행을 청크로 순회합니다 (여러 개 선택 값 정리, 중복 키 재계산용).
     *
     * @param  int  $definitionId  항목 ID
     * @param  callable(Collection<int, FieldValue>): void  $callback  청크 처리기
     * @param  int  $chunk  청크 크기
     * @return void 반환값 없음
     */
    public function chunkForDefinition(int $definitionId, callable $callback, int $chunk = 500): void;

    /**
     * 정의가 없어진 고아 값 행 수를 반환합니다.
     *
     * @return int 고아 행 수
     */
    public function countOrphans(): int;
}
