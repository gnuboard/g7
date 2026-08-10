<?php

namespace App\Search\DTO;

/**
 * FULLTEXT 인덱스 정의 (Value Object)
 *
 * `INFORMATION_SCHEMA` + `SHOW CREATE TABLE` 에서 읽어 온 실제 인덱스 구성입니다.
 * 재생성 시 이 값을 그대로 다시 써서 검색 동작이 달라지지 않게 합니다.
 */
readonly class FulltextIndexDefinition
{
    /**
     * @param  string  $table  테이블명 (프리픽스 포함 — 스키마에서 읽은 실제 이름)
     * @param  string  $name  인덱스명
     * @param  array<int, string>  $columns  색인 컬럼 목록
     * @param  string|null  $parser  파서명 (예: ngram). 지정되지 않은 인덱스는 null
     */
    public function __construct(
        public string $table,
        public string $name,
        public array $columns,
        public ?string $parser = null,
    ) {}

    /**
     * 백틱을 두른 테이블명을 반환합니다.
     *
     * @return string 예: `g7_pages`
     */
    public function quotedTable(): string
    {
        return '`'.$this->table.'`';
    }

    /**
     * 백틱을 두른 컬럼 목록을 반환합니다.
     *
     * @return string 예: `title`, `content`
     */
    public function quotedColumns(): string
    {
        return '`'.implode('`, `', $this->columns).'`';
    }

    /**
     * `ALTER TABLE ... ADD FULLTEXT` 에 붙일 파서 절을 반환합니다.
     *
     * @return string 파서가 없으면 빈 문자열
     */
    public function parserClause(): string
    {
        return $this->parser === null ? '' : ' WITH PARSER '.$this->parser;
    }

    /**
     * 사람이 읽는 식별자를 반환합니다.
     *
     * @return string 예: g7_pages.ft_pages_title
     */
    public function label(): string
    {
        return $this->table.'.'.$this->name;
    }
}
