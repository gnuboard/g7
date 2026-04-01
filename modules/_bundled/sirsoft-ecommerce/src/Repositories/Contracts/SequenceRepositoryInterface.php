<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\Sequence;

/**
 * 시퀀스 Repository 인터페이스
 */
interface SequenceRepositoryInterface
{
    /**
     * 타입으로 시퀀스 조회
     *
     * @param SequenceType $type 시퀀스 타입
     * @return Sequence|null
     */
    public function findByType(SequenceType $type): ?Sequence;

    /**
     * 타입으로 시퀀스 조회 (락 포함)
     *
     * 동시성 제어를 위해 FOR UPDATE 락을 사용합니다.
     *
     * @param SequenceType $type 시퀀스 타입
     * @return Sequence|null
     */
    public function findByTypeForUpdate(SequenceType $type): ?Sequence;

    /**
     * 시퀀스 생성
     *
     * @param array $data 시퀀스 데이터
     * @return Sequence
     */
    public function create(array $data): Sequence;

    /**
     * 시퀀스 값 업데이트
     *
     * @param Sequence $sequence 시퀀스 모델
     * @param int $newValue 새 값
     * @return bool
     */
    public function updateCurrentValue(Sequence $sequence, int $newValue): bool;

    /**
     * 마지막 리셋 날짜 업데이트 (Daily 알고리즘용)
     *
     * @param Sequence $sequence 시퀀스 모델
     * @param Carbon $date 리셋 날짜
     * @return bool
     */
    public function updateLastResetDate(Sequence $sequence, Carbon $date): bool;

    /**
     * 코드 존재 여부 확인
     *
     * @param SequenceType $type 시퀀스 타입
     * @param string $code 확인할 코드
     * @return bool
     */
    public function codeExists(SequenceType $type, string $code): bool;

    /**
     * 코드 이력 삽입
     *
     * @param SequenceType $type 시퀀스 타입
     * @param string $code 발급된 코드
     * @return void
     */
    public function insertCode(SequenceType $type, string $code): void;

    /**
     * 타입별 발급된 코드 수 조회
     *
     * @param SequenceType $type 시퀀스 타입
     * @return int
     */
    public function countCodes(SequenceType $type): int;

    /**
     * 오래된 코드 이력 삭제
     *
     * 지정된 타입의 코드 이력에서 최신 N개를 제외한 나머지를 삭제합니다.
     *
     * @param SequenceType $type 시퀀스 타입
     * @param int $keepCount 유지할 최신 코드 수
     * @return int 삭제된 레코드 수
     */
    public function deleteOldCodes(SequenceType $type, int $keepCount): int;
}
