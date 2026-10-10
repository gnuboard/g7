<?php

namespace Modules\Sirsoft\Board\Database\Seeders\Sample\Concerns;

/**
 * 게시판 샘플 시더가 자기가 만든 행에 남기는 표식
 *
 * 재시드 때 정리는 이 표식이 있는 행만 지운다 — 운영 중인 사이트에서 `--sample` 을 다시
 * 돌려도 운영자가 처리한 신고, 법정 보관 대상 활동 로그가 지워지면 안 되기 때문이다.
 */
final class BoardSampleMarker
{
    /**
     * 활동 로그 properties.seeded_by 값 (게시판 샘플)
     */
    public const BOARD_ACTIVITY_SEEDED_BY = 'sirsoft-board:sample';

    /**
     * 신고 케이스·신고 로그 metadata 의 표식 키 (값 true)
     */
    public const REPORT_METADATA_KEY = 'sample';

    /**
     * 샘플 게시판 slug 목록 — 게시글·반응·스크랩 샘플은 이 게시판에만 만든다.
     *
     * 운영자가 만든 게시판에는 샘플 글을 넣지 않는다(B-21).
     *
     * @var array<int, string>
     */
    public const SAMPLE_BOARD_SLUGS = [
        'notice', 'free', 'gallery', 'event', 'qna', 'members', 'inquiry', 'archive', 'product-inquiry',
    ];

    /**
     * 이커머스 상품 문의용 샘플 게시판 slug
     *
     * 이커머스 샘플 시더가 `inquiry.board_slug` 가 비어 있을 때 이 slug 를 설정에 기록한다.
     */
    public const PRODUCT_INQUIRY_SLUG = 'product-inquiry';
}
