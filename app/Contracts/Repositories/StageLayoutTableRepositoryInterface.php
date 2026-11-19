<?php

namespace App\Contracts\Repositories;

/**
 * 검수 서버(stage)용 레이아웃 테이블 저장소 인터페이스.
 *
 * @since 7.2.0
 */
interface StageLayoutTableRepositoryInterface
{
    /**
     * 스테이지 테이블이 모두 있는지.
     *
     * @return bool 있으면 true
     */
    public function available(): bool;

    /**
     * 운영 레이아웃·조각을 스테이지 테이블에 ID 그대로 복사합니다 — 한 트랜잭션.
     *
     * @return array{layouts: int, extensions: int, checksum_match: bool} 복사 결과
     */
    public function resetFromLive(): array;

    /**
     * 스테이지 레이아웃 테이블의 (템플릿 ID, 레이아웃 이름) 목록 — 캐시를 버릴 대상을 고를 때 쓴다.
     *
     * @return array<int, array{template_id: int, name: string}> 레이아웃 키
     */
    public function stageLayoutKeys(): array;

    /**
     * 운영 레이아웃으로 덮어쓰면 사라질 검수 서버 편집본 수 — 운영과 본문·원본 해시가 다르거나 운영에 없는 행, 검수 서버 버전 기록.
     *
     * @return array{layouts: int, extensions: int, versions: int} 지워질 건수
     */
    public function stageDivergence(): array;

    /**
     * 운영·스테이지 테이블의 본문 체크섬 (ID·본문·원본 해시).
     *
     * @param  string  $base  원 테이블
     * @param  bool  $stage  스테이지 테이블이면 true
     * @return string 체크섬
     */
    public function checksum(string $base, bool $stage): string;

    /**
     * 템플릿을 제거할 때 다른 역할 테이블의 그 템플릿 행을 명시적으로 지웁니다(버전 → 본 행 순).
     *
     * @param  int  $templateId  템플릿 ID
     * @return int 지운 레이아웃·조각 수
     */
    public function deleteOtherRoleRowsForTemplate(int $templateId): int;
}
