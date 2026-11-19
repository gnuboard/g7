<?php

namespace Modules\Sirsoft\Board\Contracts\Data;

use Modules\Sirsoft\Board\Models\Board;

/**
 * 다른 확장에 건네는 게시판 요약 (공개 계약 DTO)
 *
 * 게시판 Model 대신 이 값만 넘긴다 — 게시판 테이블·관계 구조가 바뀌어도 다른 확장은 깨지지 않는다.
 *
 * @since 1.3.0
 */
final readonly class BoardSummary
{
    /**
     * @param  int  $id  게시판 id
     * @param  string  $slug  게시판 slug(만든 뒤 바뀌지 않는다)
     * @param  string  $label  현재 언어 게시판 이름(요청 언어 → 기본 언어 → 첫 값)
     * @param  string  $url  사이트 게시판 목록 경로
     */
    public function __construct(
        public int $id,
        public string $slug,
        public string $label,
        public string $url,
    ) {}

    /**
     * 게시판 Model 에서 요약을 만듭니다.
     *
     * @internal 게시판 구현 전용 — 다른 확장은 계약 메서드의 반환값만 받는다.
     *
     * @param  Board  $board  게시판
     * @return self 요약
     */
    public static function fromModel(Board $board): self
    {
        return new self(
            id: (int) $board->id,
            slug: (string) $board->slug,
            label: $board->getLocalizedName(),
            url: '/board/'.$board->slug,
        );
    }
}
