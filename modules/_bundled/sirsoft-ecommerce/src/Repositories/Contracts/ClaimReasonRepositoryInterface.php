<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;

/**
 * 클레임 사유 Repository 인터페이스
 */
interface ClaimReasonRepositoryInterface
{
    /**
     * 클레임 사유 목록 조회
     *
     * @param  array  $filters  필터 조건
     * @param  array  $with  Eager loading 관계
     * @return Collection 조회된 클레임 사유 컬렉션
     */
    public function getAll(array $filters = [], array $with = []): Collection;

    /**
     * ID로 클레임 사유 조회
     *
     * @param  int  $id  클레임 사유 ID
     * @param  array  $with  Eager loading 관계
     * @return ClaimReason|null 조회된 클레임 사유 (없으면 null)
     */
    public function findById(int $id, array $with = []): ?ClaimReason;

    /**
     * 코드로 클레임 사유 조회
     *
     * @param  string  $code  사유 코드
     * @param  string  $type  사유 유형
     * @return ClaimReason|null 조회된 클레임 사유 (없으면 null)
     */
    public function findByCode(string $code, string $type = 'refund'): ?ClaimReason;

    /**
     * 클레임 사유 생성
     *
     * @param  array  $data  사유 데이터
     * @return ClaimReason 생성된 클레임 사유
     */
    public function create(array $data): ClaimReason;

    /**
     * 클레임 사유 수정
     *
     * @param  int  $id  사유 ID
     * @param  array  $data  수정 데이터
     * @return ClaimReason 수정된 클레임 사유
     */
    public function update(int $id, array $data): ClaimReason;

    /**
     * 클레임 사유 삭제
     *
     * @param  int  $id  사유 ID
     * @return bool 삭제 성공 여부
     */
    public function delete(int $id): bool;

    /**
     * 코드 중복 확인
     *
     * @param  string  $code  사유 코드
     * @param  string  $type  사유 유형
     * @param  int|null  $excludeId  제외할 사유 ID
     * @return bool 같은 코드가 이미 존재하면 true
     */
    public function existsByCode(string $code, string $type = 'refund', ?int $excludeId = null): bool;

    /**
     * 활성 클레임 사유 목록 조회
     *
     * @param  string  $type  사유 유형
     * @return Collection 활성 클레임 사유 컬렉션
     */
    public function getActiveReasons(string $type = 'refund'): Collection;

    /**
     * 사용자 선택 가능한 클레임 사유 목록 조회
     *
     * @param  string  $type  사유 유형
     * @return Collection 사용자가 선택 가능한 클레임 사유 컬렉션
     */
    public function getUserSelectableReasons(string $type = 'refund'): Collection;

    /**
     * 특정 유형의 클레임 사유 ID 목록을 조회합니다.
     *
     * 설정 화면의 일괄 동기화에서 "DB 에 있는데 payload 에 없는 항목"을 가려낼 때 사용합니다.
     *
     * @param  string  $type  사유 유형
     * @return array<int> 클레임 사유 ID 배열
     */
    public function getIdsByType(string $type): array;
}
