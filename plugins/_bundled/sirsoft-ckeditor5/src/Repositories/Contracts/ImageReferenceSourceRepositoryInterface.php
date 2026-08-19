<?php

namespace Plugins\Sirsoft\Ckeditor5\Repositories\Contracts;

/**
 * 에디터 이미지 참조 소스 조회 Repository 인터페이스
 *
 * 참조 소스는 확장이 훅으로 등록하는 "테이블명 + 컬럼명" 이라 전용 모델이 없습니다.
 * 그래서 이 Repository 는 특정 엔티티가 아니라 **선언된 소스에 대한 조회**를 소유합니다 —
 * Service 는 어느 소스를 볼지와 무엇을 찾을지만 정하고, 실제 데이터 접근은 여기로 모읍니다.
 */
interface ImageReferenceSourceRepositoryInterface
{
    /**
     * 소스 선언이 실제 스키마에 존재하는지 검증하고, 존재하는 컬럼만 남깁니다.
     *
     * @param  string  $table  테이블명 (프리픽스 제외)
     * @param  array<int, string>  $columns  컬럼명 목록
     * @return array<int, string> 실재하는 컬럼 목록 (테이블이 없으면 빈 배열)
     */
    public function resolveExistingColumns(string $table, array $columns): array;

    /**
     * 지정 소스의 컬럼 중 하나라도 토큰을 포함하는 행이 있는지 확인합니다.
     *
     * @param  string  $table  테이블명 (프리픽스 제외)
     * @param  array<int, string>  $columns  검사할 컬럼 목록
     * @param  array<int, string>  $tokens  판정 토큰 (LIKE 이스케이프는 구현이 담당)
     * @return bool 하나라도 등장하면 true
     */
    public function containsAnyToken(string $table, array $columns, array $tokens): bool;

    /**
     * 지정 소스에 등장하는 토큰들을 한 번의 순회로 찾아 반환합니다.
     *
     * 토큰별로 LIKE 전체 스캔을 반복하면 비용이 (토큰 수 × 테이블 크기) 로 커진다 —
     * 일괄 판정(관리 화면 미참조 필터·prune)은 이 메서드로 소스당 1회 순회만 수행한다.
     *
     * @param  string  $table  테이블명 (프리픽스 제외)
     * @param  array<int, string>  $columns  검사할 컬럼 목록
     * @param  array<int, string>  $tokens  판정 토큰
     * @return array<int, string> 소스에 등장하는 토큰 목록 (부분집합)
     */
    public function findTokensInSource(string $table, array $columns, array $tokens): array;
}
