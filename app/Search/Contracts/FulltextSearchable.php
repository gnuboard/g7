<?php

namespace App\Search\Contracts;

/**
 * FULLTEXT 검색 가능 모델 인터페이스
 *
 * MySQL FULLTEXT + ngram 파서를 사용하는 모델이 구현합니다.
 * DatabaseFulltextEngine이 MATCH...AGAINST 쿼리를 생성할 때 참조합니다.
 */
interface FulltextSearchable
{
    /**
     * FULLTEXT 검색 대상 컬럼 목록을 반환합니다.
     *
     * @return array<string> 컬럼명 배열
     */
    public function searchableColumns(): array;

    /**
     * 컬럼별 검색 가중치를 반환합니다.
     *
     * 가중치가 높을수록 해당 컬럼 매칭 시 점수가 높아집니다.
     *
     * @return array<string, float> 컬럼명 => 가중치 매핑
     */
    public function searchableWeights(): array;
}
