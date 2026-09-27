<?php

namespace App\Contracts\Repositories;

use App\Models\SitemapUrl;

/**
 * 사이트맵 URL 저장소 인터페이스
 *
 * sitemap_urls 테이블에 대한 리소스 단위 증분 갱신과 스트리밍 조회를 제공합니다.
 * 리스너는 SitemapIndexer 를 경유해 이 저장소를 사용하며(직접 Model/DB 접근 금지),
 * 전체 재생성/증분 파일 작성은 이 저장소의 스트림을 소비합니다.
 */
interface SitemapUrlRepositoryInterface
{
    /**
     * 한 리소스의 사이트맵 URL 행을 현재 상태로 대체합니다(멱등).
     *
     * (resource_type, resource_id) 로 기존 행을 제거한 뒤 전달된 항목을 삽입하므로,
     * 같은 입력으로 여러 번 호출해도 테이블 내용이 동일합니다(loc 변경/슬러그 변경 흡수).
     *
     * @param  string  $type  리소스 유형
     * @param  string  $id  리소스 PK (문자열)
     * @param  array<int, array{loc: string, contributor: string, lastmod?: mixed, changefreq?: ?string, priority?: ?float}>  $entries  URL 항목
     * @return void
     */
    public function upsertForResource(string $type, string $id, array $entries): void;

    /**
     * 한 리소스의 사이트맵 URL 행을 모두 제거합니다(비공개/삭제 시).
     *
     * @param  string  $type  리소스 유형
     * @param  string  $id  리소스 PK (문자열)
     * @return void
     */
    public function removeForResource(string $type, string $id): void;

    /**
     * 같은 유형의 여러 리소스 사이트맵 URL 행을 한 번에 제거합니다(부모 삭제·비공개 전환 시 자식 일괄 정리).
     *
     * 게시판 삭제처럼 자식 리소스가 한꺼번에 사라지는 경로에서 리소스마다 삭제 쿼리를 내지 않도록
     * 청크 단위로 whereIn 삭제합니다. 빈 목록이면 아무것도 하지 않습니다.
     *
     * @param  string  $type  리소스 유형
     * @param  array<int, string|int>  $ids  리소스 PK 목록
     * @return int 삭제된 행 수
     */
    public function removeManyForResource(string $type, array $ids): int;

    /**
     * 같은 유형의 여러 리소스 사이트맵 URL 행을 한 번에 교체합니다(부모 재공개 시 자식 일괄 재색인).
     *
     * 주어진 리소스들의 기존 행을 지우고 새 항목을 넣는다. 항목이 빈 리소스는 행이 지워지기만 한다.
     * 청크마다 삭제 1회·삽입 1회를 한 트랜잭션으로 처리합니다.
     *
     * @param  string  $type  리소스 유형
     * @param  array<string, array<int, array{loc: string, contributor: string, lastmod?: mixed, changefreq?: ?string, priority?: ?float}>>  $entriesById  리소스 PK(문자열) => URL 항목 목록
     * @return int 삽입된 행 수
     */
    public function replaceManyForResources(string $type, array $entriesById): int;

    /**
     * 공개(is_visible) URL 을 id 순으로 스트리밍합니다(유계 메모리).
     *
     * @param  string|null  $contributor  기여자 식별자로 스코핑 (null = 전체)
     * @return iterable<int, SitemapUrl> URL 순회자
     */
    public function streamVisible(?string $contributor = null): iterable;

    /**
     * 공개(is_visible) URL 총 개수를 반환합니다.
     *
     * @return int 공개 URL 수
     */
    public function countVisible(): int;

    /**
     * 한 기여자의 모든 사이트맵 URL 행을 전량 대체합니다(전체 재생성).
     *
     * 기여자 스코프로 기존 행을 제거한 뒤 스트림을 청크 삽입하여
     * 대용량에서도 메모리를 유계로 유지합니다.
     *
     * @param  string  $contributor  기여자 식별자
     * @param  iterable<int, array{loc: string, resource_type?: string, resource_id?: mixed, lastmod?: mixed, changefreq?: ?string, priority?: ?float}>  $entries  URL 항목 스트림
     * @return int 삽입된 행 수 (진행상황 누적 URL 표기용 — count 쿼리 없이 스트림 누적)
     */
    public function replaceAllForContributor(string $contributor, iterable $entries): int;
}
