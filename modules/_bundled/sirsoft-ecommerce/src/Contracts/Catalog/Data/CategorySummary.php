<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Catalog\Data;

/**
 * 다른 확장에 건네는 상품 카테고리 요약 (공개 계약 DTO)
 *
 * @since 1.4.0
 */
final readonly class CategorySummary
{
    /** 경로 이름 구분자 */
    public const PATH_SEPARATOR = ' › ';

    /**
     * CategorySummary 생성자
     *
     * @param  int  $id  카테고리 id
     * @param  string  $slug  카테고리 slug(사이트 주소 조각)
     * @param  string  $label  현재 언어 카테고리 이름(자기 이름만)
     * @param  string  $pathLabel  최상위부터 이은 이름(예: 「의류 › 남성」) — 같은 이름의 하위 카테고리를 구분할 때 쓴다
     * @param  string  $url  사이트 카테고리 목록 경로(쇼핑몰 경로 접두 반영)
     */
    public function __construct(
        public int $id,
        public string $slug,
        public string $label,
        public string $pathLabel,
        public string $url,
    ) {}
}
