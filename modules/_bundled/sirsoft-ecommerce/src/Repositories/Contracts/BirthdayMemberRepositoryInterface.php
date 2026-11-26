<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

/**
 * 생일 회원 조회 Repository 인터페이스
 */
interface BirthdayMemberRepositoryInterface
{
    /**
     * 생일 항목의 월·일이 일치하는 활성 회원 ID 를 묶음 단위로 넘깁니다.
     *
     * 회원 항목 날짜는 시간대 이동 없이 저장되므로 저장값의 월·일(`MM-DD`)을 그대로 비교한다.
     *
     * @param  int  $fieldDefinitionId  생일 항목 정의 ID
     * @param  array<int, string>  $monthDays  비교할 월·일 목록 (예: ['02-28', '02-29'])
     * @param  int  $chunk  묶음 크기
     * @param  callable(array<int, int>): void  $callback  회원 ID 묶음을 받는 함수
     * @return int 넘긴 회원 수
     */
    public function chunkActiveUserIdsByBirthday(int $fieldDefinitionId, array $monthDays, int $chunk, callable $callback): int;
}
