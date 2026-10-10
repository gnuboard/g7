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
     * `$filters['type']` 은 **필수**다. 카탈로그가 유형별로 나뉘어 있으므로 유형 없는 조회는
     * 의미가 없고, 기본값을 두면 유형을 빠뜨린 호출부가 조용히 한 유형만 받는다.
     * 미지정 시 InvalidArgumentException 을 던진다.
     *
     * @param  array  $filters  필터 조건 (`type` 필수 · `fault_type`·`is_active`·`search` 선택)
     * @param  array  $with  Eager loading 관계
     * @return Collection 조회된 클레임 사유 컬렉션
     *
     * @throws \InvalidArgumentException type 필터 미지정 시
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
     * 유형 안에서 여러 코드를 한 번에 조회 (목록 표기용 묶음 조회)
     *
     * 활성 여부로 거르지 않는다 — 이미 접수된 신청서의 사유가 나중에 비활성화되어도 그 신청서의
     * 라벨은 계속 보여야 하므로, 단건 게터(`findByCode`)와 같은 모집단을 유지한다.
     *
     * @param  string  $type  사유 유형
     * @param  array<int, string>  $codes  사유 코드 목록
     * @return Collection<int, ClaimReason> 조회된 사유 목록
     */
    public function findByCodes(string $type, array $codes): Collection;

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
    public function getActiveReasons(string $type): Collection;

    /**
     * 사용자 선택 가능한 클레임 사유 목록 조회
     *
     * @param  string  $type  사유 유형
     * @return Collection 사용자가 선택 가능한 클레임 사유 컬렉션
     */
    public function getUserSelectableReasons(string $type): Collection;

    /**
     * 특정 유형의 클레임 사유 ID 목록을 조회합니다.
     *
     * 설정 화면의 일괄 동기화에서 "DB 에 있는데 payload 에 없는 항목"을 가려낼 때 사용합니다.
     *
     * @param  string  $type  사유 유형
     * @return array<int> 클레임 사유 ID 배열
     */
    public function getIdsByType(string $type): array;

    /**
     * 특정 유형에 이미 저장된 클레임 사유 코드 목록을 조회합니다.
     *
     * 코드 형식 검증의 **면제 대상**을 가려낼 때 사용합니다. 형식 규칙이 나중에 좁혀지면
     * 그 이전에 저장된 코드가 규칙 밖에 놓이는데, 설정 탭은 저장할 때마다 사유 전체를
     * 되보내므로 운영자가 손대지 않은 행 하나 때문에 탭 전체가 저장 불가가 됩니다.
     * 이미 저장된 값은 그대로 통과시키고, 새로 만들거나 바꾸는 값에만 형식을 적용합니다.
     *
     * @param  string  $type  사유 유형
     * @return array<int, string> 클레임 사유 코드 배열
     */
    public function getCodesByType(string $type): array;
}
