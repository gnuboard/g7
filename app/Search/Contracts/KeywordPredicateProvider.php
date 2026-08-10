<?php

namespace App\Search\Contracts;

use App\Search\DTO\KeywordSearchContext;
use App\Search\KeywordSearch;
use App\Support\Query\PaginationLimits;
use Illuminate\Database\Eloquent\Builder;

/**
 * 진행 중인 DB 쿼리에 키워드 술어를 붙일 수 있는 검색 엔진 계약
 *
 * Scout 의 `Model::search()` 는 "엔진이 결과를 돌려준다" 모델입니다. 그래서 결과를 받은
 * 뒤 그 ID 로 DB 를 다시 조회해야 하고, 매칭이 많으면 ID 전량을 메모리에 올려 무제한
 * `IN (...)` 을 만들게 됩니다. 페이지네이션·조인·필터를 DB 에 남긴 채 키워드 조건만
 * 얹으려면 **엔진에게 술어를 달라고 요청할 통로**가 따로 필요합니다.
 *
 * 이 계약이 그 통로입니다. 구현하는 엔진은 자기 방식(FULLTEXT MATCH, 외부 엔진이 미리
 * 좁혀 준 키 집합 등)으로 조건을 붙이고, 코어는 무엇이 붙는지 모르는 채 호출만 합니다.
 * 해석과 폴백은 {@see KeywordSearch} 가 단독으로 수행합니다.
 *
 * 구현하지 않은 엔진은 `LIKE` 폴백으로 내려갑니다 — 검색은 동작하지만 관련도 정렬이
 * 사라지고 전체 스캔이 되므로, 폴백 사실은 조용히 넘어가지 않고 기록됩니다.
 *
 * ```php
 * class MeilisearchEngine extends Engine implements KeywordPredicateProvider
 * {
 *     public function applyKeywordPredicate(
 *         Builder $query,
 *         array $columns,
 *         string $keyword,
 *         string $boolean,
 *         KeywordSearchContext $context
 *     ): void {
 *         // 상한을 지킨다 — 지키지 않으면 매칭이 큰 검색어에서 이 배열이 곧 메모리 폭발이다
 *         $ids = $this->lookupIds($keyword, limit: $context->keyCap);
 *         $method = $boolean === 'or' ? 'orWhereIn' : 'whereIn';
 *         $query->$method($query->getModel()->getQualifiedKeyName(), $ids);
 *     }
 * }
 * ```
 */
interface KeywordPredicateProvider
{
    /**
     * 쿼리에 키워드 술어를 붙입니다.
     *
     * 컬럼 목록은 **하나의 조건으로 함께 평가**해 달라는 요청입니다. 엔진이 그 조합을
     * 다룰 수 없으면(예: MySQL 이 그 컬럼 조합의 복합 FULLTEXT 인덱스를 요구하는 경우)
     * 호출자가 {@see KeywordSearch::applyAny()} 로 컬럼별 OR 을 요청합니다.
     *
     * 매칭이 하나도 없을 수 있는 검색어(예: 연산자만 입력)에는 **항상 거짓인 조건**을
     * 붙여야 합니다. 아무 조건도 붙이지 않으면 필터 없는 전체 목록이 검색 결과로 나갑니다.
     *
     * **페이지네이션은 호출자(DB)가 담당합니다.** 엔진에게 페이지 번호를 넘기지 않는 이유는,
     * 엔진이 자기 순서로 한 페이지 분량만 돌려주면 그 뒤에 적용되는 DB 필터(분류·전시상태·
     * 조인)에 일부가 탈락해 페이지가 비고, DB 정렬과 엔진의 관련도 순서가 달라 페이지 경계도
     * 어긋나기 때문입니다. 엔진이 책임지는 것은 **"얼마까지 돌려줄 것인가"** 하나입니다.
     *
     * 키 집합을 만들어 조건으로 붙이는 구현은 `$context->keyCap` 을 반드시 지켜야 합니다.
     * 지키지 않으면 매칭이 큰 검색어에서 그 집합 자체가 메모리 폭발이 됩니다. 상한에 걸려
     * 잘린 경우 그 이상은 도달 불가이며, 총 건수는 "이상" 으로 보고됩니다
     * ({@see PaginationLimits} 와 같은 의미).
     *
     * @param  Builder  $query  조건을 붙일 Eloquent 쿼리 빌더
     * @param  array<int, string>  $columns  검색 대상 컬럼명
     * @param  string  $keyword  검색어 원문 (정제는 엔진이 수행)
     * @param  string  $boolean  기존 조건과의 결합 방식 ('and' 또는 'or')
     * @param  KeywordSearchContext  $context  술어 생성 조건 (키 집합 상한 등)
     */
    public function applyKeywordPredicate(
        Builder $query,
        array $columns,
        string $keyword,
        string $boolean,
        KeywordSearchContext $context
    ): void;
}
