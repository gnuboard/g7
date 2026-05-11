/**
 * @file board-show-count-consistency.test.tsx
 * @description 게시판 사용자 페이지 - 카운트 컬럼 정합성 검증 (이슈 #304 Phase 3)
 *
 * 검증 방식: 레이아웃 JSON 트리 직접 분석 (DOM 비의존).
 * 검증 목적:
 *   1. Verification — 단수형 응답 키(comment_count / reply_count / attachment_count)를 사용하도록 보정
 *   2. Regression — `.length` 기반 카운트가 서버 컬럼으로 대체됐는지 고정
 *   3. 정책 — `_post_attachments.json` if 분기는 `has_attachment` boolean 사용
 */

import { describe, it, expect } from 'vitest';

// 정정 대상 6개 파일
import usersShow from '../../layouts/users/show.json';
import usersPosts from '../../layouts/users/posts.json';
import myPostsPartial from '../../layouts/partials/mypage/board/_my_posts.json';
import commentSection from '../../layouts/partials/board/show/_comment_section.json';
import replySection from '../../layouts/partials/board/show/_reply_section.json';
import postAttachments from '../../layouts/partials/board/show/_post_attachments.json';
import basicShow from '../../layouts/partials/board/types/basic/show.json';

/**
 * JSON 전체를 문자열화하여 패턴 검색.
 * 표현식 / props / text / if 어디든 등장하는 키를 일괄 검증할 때 사용.
 */
function stringifyDeep(node: unknown): string {
    return JSON.stringify(node);
}

describe('이슈 #304 Phase 3 — 사용자 페이지 카운트 컬럼 정합성', () => {
    describe('users/show.json — 최근 게시글 댓글 수 단수형', () => {
        it('post.comment_count 단수형 키를 사용한다', () => {
            const dump = stringifyDeep(usersShow);
            expect(dump).toContain('post.comment_count');
        });

        it('post.comments_count 복수형 키를 사용하지 않는다', () => {
            const dump = stringifyDeep(usersShow);
            // postStats.data.posts_count 같은 통계 키는 별도 표준이므로 post. 접두사로만 한정
            expect(dump).not.toMatch(/post\.comments_count/);
        });
    });

    describe('users/posts.json — 사용자 게시글 목록 댓글 수 단수형', () => {
        it('post.comment_count 단수형 키를 사용한다', () => {
            const dump = stringifyDeep(usersPosts);
            expect(dump).toContain('post.comment_count');
        });

        it('post.comments_count 복수형 키를 사용하지 않는다', () => {
            const dump = stringifyDeep(usersPosts);
            expect(dump).not.toMatch(/post\.comments_count/);
        });
    });

    describe('partials/mypage/board/_my_posts.json — 마이페이지 댓글 수 단수형', () => {
        it('post.comment_count 단수형 키를 사용한다', () => {
            const dump = stringifyDeep(myPostsPartial);
            expect(dump).toContain('post.comment_count');
        });

        it('post.comments_count 복수형 키를 사용하지 않는다', () => {
            const dump = stringifyDeep(myPostsPartial);
            expect(dump).not.toMatch(/post\.comments_count/);
        });
    });

    describe('_comment_section.json — 댓글 헤더 카운트 / 빈 상태 분기', () => {
        it('헤더 카운트는 post.data.comment_count 서버 컬럼을 사용한다', () => {
            const dump = stringifyDeep(commentSection);
            expect(dump).toContain('post?.data?.comment_count');
        });

        it('헤더 카운트가 (post.data.comments ?? []).length 를 사용하지 않는다', () => {
            const dump = stringifyDeep(commentSection);
            // 댓글 배열의 length 로 카운트 표시하던 패턴 제거 (헤더는 항상 활성 댓글만)
            expect(dump).not.toMatch(/\(post\?\.data\?\.comments\s*\?\?\s*\[\]\)\.length/);
        });

        it('iteration source 는 여전히 comments 배열을 사용한다 (표시는 배열 그대로)', () => {
            // del_cmt=1 토글 시 헤더(comment_count) ≠ 표시(comments[]) 가 의도된 정책
            const dump = stringifyDeep(commentSection);
            expect(dump).toContain('post?.data?.comments ?? []');
        });
    });

    describe('_reply_section.json — 답글 헤더 카운트', () => {
        it('헤더 카운트는 post.data.reply_count 서버 컬럼을 사용한다', () => {
            const dump = stringifyDeep(replySection);
            expect(dump).toContain('post?.data?.reply_count');
        });

        it('헤더 카운트가 (post.data.replies ?? []).length 를 사용하지 않는다', () => {
            const dump = stringifyDeep(replySection);
            // count={{(post.data.replies ?? []).length}} 패턴 제거
            expect(dump).not.toMatch(/count=\{\{\(post\?\.data\?\.replies\s*\?\?\s*\[\]\)\.length\}\}/);
        });

        it('iteration source 는 여전히 replies 배열을 사용한다', () => {
            const dump = stringifyDeep(replySection);
            expect(dump).toContain('post?.data?.replies ?? []');
        });
    });

    describe('partials/board/types/basic/show.json — 답글 섹션 표시 분기', () => {
        it('답글 섹션 표시 조건은 reply_count 서버 컬럼을 사용한다', () => {
            const dump = stringifyDeep(basicShow);
            expect(dump).toContain('post?.data?.reply_count');
        });

        it('답글 섹션 표시 조건이 (post.data.replies ?? []).length 를 사용하지 않는다', () => {
            const dump = stringifyDeep(basicShow);
            expect(dump).not.toMatch(/\(post\?\.data\?\.replies\s*\?\?\s*\[\]\)\.length\s*>\s*0/);
        });
    });

    describe('_post_attachments.json — 첨부파일 표시 / 카운트', () => {
        it('첨부 섹션 표시 if 는 has_attachment boolean 을 사용한다', () => {
            // 루트 if 가 has_attachment 를 사용
            expect(typeof postAttachments.if).toBe('string');
            expect(postAttachments.if).toContain('post?.data?.has_attachment');
        });

        it('첨부 섹션 표시 if 가 attachments?.length 를 사용하지 않는다', () => {
            expect(postAttachments.if).not.toMatch(/attachments\?\.length/);
        });

        it('첨부 카운트 배지는 attachment_count 서버 컬럼을 사용한다', () => {
            const dump = stringifyDeep(postAttachments);
            expect(dump).toContain('post?.data?.attachment_count');
        });

        it('첨부 카운트 배지가 attachments?.length 를 사용하지 않는다', () => {
            const dump = stringifyDeep(postAttachments);
            // text 영역의 attachments?.length 사용 패턴 제거
            expect(dump).not.toMatch(/post\?\.data\?\.attachments\?\.length\s*\?\?\s*0/);
        });

        it('iteration source 는 여전히 attachments 배열을 사용한다', () => {
            const dump = stringifyDeep(postAttachments);
            expect(dump).toContain('post?.data?.attachments ?? []');
        });
    });
});
