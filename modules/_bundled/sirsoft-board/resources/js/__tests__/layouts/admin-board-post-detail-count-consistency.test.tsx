/**
 * @file admin-board-post-detail-count-consistency.test.tsx
 * @description 관리자 게시글 상세 - 카운트 컬럼 정합성 검증 (이슈 #304 Phase 4)
 *
 * 검증 방식: 레이아웃 JSON 트리 직접 분석 (DOM 비의존).
 * 검증 목적:
 *   1. Verification — 답글/댓글/첨부 헤더 카운트가 서버 컬럼(reply_count/comment_count/attachment_count)을 사용
 *   2. Regression — `.length` 기반 카운트 패턴 제거 고정
 *   3. 정책 고정 — del_cmt=1 토글 시 헤더(comment_count) ≠ 표시 항목 수(comments[].length) 의도된 트레이드오프
 */

import { describe, it, expect } from 'vitest';

// 정정 대상 4개 파일
import postDetail from '../../../../resources/layouts/admin/admin_board_post_detail.json';
import commentsPartial from '../../../../resources/layouts/admin/partials/admin_board_post_detail/_comments.json';
import postCardContent from '../../../../resources/layouts/admin/partials/admin_board_post_detail/_post_card_content.json';
import replyCardContent from '../../../../resources/layouts/admin/partials/admin_board_post_detail/_reply_card_content.json';

function stringifyDeep(node: unknown): string {
    return JSON.stringify(node);
}

describe('이슈 #304 Phase 4 — 관리자 게시글 상세 카운트 컬럼 정합성', () => {
    describe('admin_board_post_detail.json — 답글 섹션 헤더', () => {
        it('답글 섹션 표시 조건은 reply_count 서버 컬럼을 사용한다', () => {
            const dump = stringifyDeep(postDetail);
            expect(dump).toContain('post?.data?.reply_count');
        });

        it('답글 헤더 카운트 텍스트가 (post.data.replies ?? []).length 를 사용하지 않는다', () => {
            const dump = stringifyDeep(postDetail);
            expect(dump).not.toMatch(/\(post\?\.data\?\.replies\s*\?\?\s*\[\]\)\.length/);
        });

        it('iteration source 는 여전히 replies 배열을 사용한다 (표시는 배열 그대로)', () => {
            const dump = stringifyDeep(postDetail);
            expect(dump).toContain('post?.data?.replies ?? []');
        });
    });

    describe('_comments.json — 댓글 헤더 / 빈 상태 / 표시 분기', () => {
        it('댓글 헤더 카운트는 comment_count 서버 컬럼을 사용한다', () => {
            const dump = stringifyDeep(commentsPartial);
            expect(dump).toContain('post?.data?.comment_count');
        });

        it('댓글 헤더/빈 상태 분기가 (post.data.comments ?? []).length 를 사용하지 않는다', () => {
            const dump = stringifyDeep(commentsPartial);
            // count={{(post.data.comments ?? []).length}} 와 if 분기 양쪽 모두 제거
            expect(dump).not.toMatch(/\(post\?\.data\?\.comments\s*\?\?\s*\[\]\)\.length/);
        });

        it('iteration source 는 여전히 comments 배열을 사용한다 (del_cmt 정책)', () => {
            // [정책 고정] del_cmt=1 토글 시 헤더(comment_count=활성만) ≠ 표시 항목(comments[]=삭제 포함) 의도된 정책
            const dump = stringifyDeep(commentsPartial);
            expect(dump).toContain('post?.data?.comments ?? []');
        });
    });

    describe('_post_card_content.json — 게시글 카드 댓글 수 / 첨부', () => {
        it('댓글 수 표시는 comment_count 서버 컬럼을 사용한다', () => {
            const dump = stringifyDeep(postCardContent);
            expect(dump).toContain('post?.data?.comment_count');
        });

        it('댓글 수 표시가 (post.data.comments ?? []).length 를 사용하지 않는다', () => {
            const dump = stringifyDeep(postCardContent);
            expect(dump).not.toMatch(/count=\{\{\(post\?\.data\?\.comments\s*\?\?\s*\[\]\)\.length\}\}/);
        });

        it('첨부 섹션 표시 if 는 has_attachment boolean 을 사용한다', () => {
            const dump = stringifyDeep(postCardContent);
            expect(dump).toContain('post?.data?.has_attachment');
        });

        it('첨부 카운트 배지는 attachment_count 서버 컬럼을 사용한다', () => {
            const dump = stringifyDeep(postCardContent);
            expect(dump).toContain('post?.data?.attachment_count');
        });

        it('첨부 섹션 표시 if 가 (post.data.attachments ?? []).length 를 사용하지 않는다', () => {
            const dump = stringifyDeep(postCardContent);
            expect(dump).not.toMatch(/\(post\?\.data\?\.attachments\s*\?\?\s*\[\]\)\.length/);
        });
    });

    describe('_reply_card_content.json — 답글 카드 첨부', () => {
        it('첨부 섹션 표시 if 는 has_attachment 를 사용한다', () => {
            const dump = stringifyDeep(replyCardContent);
            expect(dump).toContain('item.has_attachment');
        });

        it('첨부 카운트 배지는 attachment_count 서버 컬럼을 사용한다', () => {
            const dump = stringifyDeep(replyCardContent);
            expect(dump).toContain('item.attachment_count');
        });

        it('첨부 섹션 표시 if 가 (item.attachments ?? []).length 를 사용하지 않는다', () => {
            const dump = stringifyDeep(replyCardContent);
            expect(dump).not.toMatch(/\(item\.attachments\s*\?\?\s*\[\]\)\.length/);
        });
    });

    describe('del_cmt 정책 고정 — 헤더 카운트는 항상 서버 컬럼, 표시 항목은 응답 배열', () => {
        it('관리자 댓글 헤더는 comment_count, iteration 은 comments 배열 — 분리된 SSoT 사용', () => {
            const dump = stringifyDeep(commentsPartial);
            // 헤더 = 서버 컬럼 (활성 댓글만)
            expect(dump).toContain('post?.data?.comment_count');
            // 표시 = 응답 배열 (del_cmt=1 시 삭제 포함)
            expect(dump).toContain('post?.data?.comments ?? []');
            // 둘이 어긋나는 것이 의도된 정책 — `.length` 기반 합치기 패턴 제거 확정
            expect(dump).not.toMatch(/\(post\?\.data\?\.comments\s*\?\?\s*\[\]\)\.length/);
        });
    });
});
