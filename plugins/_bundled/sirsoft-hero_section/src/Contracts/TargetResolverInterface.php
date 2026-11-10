<?php

namespace Plugins\Sirsoft\HeroSection\Contracts;

/**
 * 동적 위치의 대상 해석기 — 공개 확장 계약.
 *
 * 동적 위치(게시판마다·상품마다처럼 같은 화면이 대상마다 따로 그려지는 위치)를 등록하는 확장은 위치 정의의
 * `target.resolver` 에 이 계약을 구현한 클래스 이름을 준다. 히어로는 컨테이너로 만들어 쓴다.
 *
 * 저장 키는 대상의 바뀌지 않는 식별자(보통 id)를 문자열로 쓴다. 화면은 slug·코드처럼 주소에 보이는 값을 넘기므로
 * `resolve()` 가 그 값을 저장 키로 푼다.
 */
interface TargetResolverInterface
{
    /**
     * 화면이 넘긴 값(slug·코드·id)을 저장 키로 풉니다.
     *
     * @param  string  $value  화면 값
     * @return string|null 저장 키 (대상이 없으면 null)
     */
    public function resolve(string $value): ?string;

    /**
     * 저장 키의 대상이 지금 있는지 확인합니다.
     *
     * @param  string  $key  저장 키
     * @return bool 있으면 true
     */
    public function exists(string $key): bool;

    /**
     * 저장 키들의 화면 이름을 한 번에 돌려줍니다(현재 언어). 없는 대상은 빠진다.
     *
     * @param  array<int, string>  $keys  저장 키 목록
     * @return array<string, string> 저장 키 => 이름
     */
    public function labels(array $keys): array;

    /**
     * 관리자 대상 고르기 목록을 검색합니다.
     *
     * @param  string  $keyword  검색어 (빈 값이면 앞에서부터)
     * @param  int  $limit  최대 건수
     * @return array<int, array{key: string, label: string, hint?: string}> 고르기 항목
     */
    public function search(string $keyword, int $limit): array;

    /**
     * 대상 화면의 사이트 경로를 돌려줍니다(「사이트에서 보기」).
     *
     * @param  string  $key  저장 키
     * @return string|null 사이트 상대 경로 (대상이 없으면 null)
     */
    public function siteUrl(string $key): ?string;
}
