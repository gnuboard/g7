/**
 * @file board-blinded-original-access.test.tsx
 * @description 블라인드 게시글/댓글 '원글 보기' 게이트 회귀 (이슈 #413-34)
 *
 * 검증 방식: 레이아웃 JSON 트리 직접 분석 (DOM 비의존).
 * 정책: 블라인드 원문은 권한자(관리자+작성자 본인)만 열람.
 *   백엔드(PostResource/CommentResource)가 권한 없는 사용자에게 content=null 을 응답하면
 *   '원글 보기' 버튼·원문 표시 블록의 if 조건이 자동으로 false 가 되어 노출되지 않는다.
 *   본 테스트는 그 게이트(if 가 content 존재에 의존)가 유지되는지 고정한다.
 *
 * 회귀 배경: 과거에는 백엔드가 content 를 권한 무관하게 응답 →
 *   누구나 '원글 보기' 로 원문 열람 가능 (보안 결함). 백엔드 차단 + 본 게이트로 방어.
 */

import { describe, it, expect } from 'vitest';

import basicShow from '../../layouts/partials/board/types/basic/show.json';
import commentItem from '../../layouts/partials/board/show/_comment_item.json';

type Node = Record<string, unknown> & { children?: unknown };

/**
 * 트리를 재귀 순회하며 조건을 만족하는 모든 노드를 수집한다.
 */
function collectNodes(node: unknown, predicate: (n: Node) => boolean): Node[] {
    const result: Node[] = [];
    const walk = (cur: unknown): void => {
        if (Array.isArray(cur)) {
            cur.forEach(walk);
            return;
        }
        if (cur && typeof cur === 'object') {
            const obj = cur as Node;
            if (predicate(obj)) {
                result.push(obj);
            }
            Object.values(obj).forEach(walk);
        }
    };
    walk(node);
    return result;
}

/**
 * comment(주석) 필드로 노드를 찾는다.
 */
function findByComment(root: unknown, commentText: string): Node[] {
    return collectNodes(root, (n) => typeof n.comment === 'string' && (n.comment as string).includes(commentText));
}

describe('이슈 #413-34 — 블라인드 게시글 원문 게이트 (basic/show.json)', () => {
    it("'원글 보기' 토글 버튼의 if 가 content 존재에 의존한다", () => {
        const buttons = findByComment(basicShow, '원글 보기/숨기기 토글 버튼');
        expect(buttons.length).toBeGreaterThan(0);

        for (const btn of buttons) {
            // content 가 null 이면 버튼이 미렌더되도록 if 가 content 를 참조해야 함
            expect(typeof btn.if).toBe('string');
            expect(btn.if as string).toContain('content');
        }
    });

    it('블라인드 원본 내용 표시 블록의 if 가 content 존재에 의존한다', () => {
        const blocks = findByComment(basicShow, '블라인드 게시글 원본 내용 표시');
        expect(blocks.length).toBeGreaterThan(0);

        for (const block of blocks) {
            expect(typeof block.if).toBe('string');
            expect(block.if as string).toContain('content');
            // 블라인드 상태에서만 표시
            expect(block.if as string).toContain("status === 'blinded'");
        }
    });

    it('블라인드 안내·제목은 content 와 무관하게 노출된다 (status 만 게이트)', () => {
        // "블라인드 처리된 게시글 - 상세 사유 표시" 안내 박스는 status 만 보고, content 의존 금지
        const noticeBoxes = findByComment(basicShow, '블라인드 처리된 게시글 - 상세 사유 표시');
        expect(noticeBoxes.length).toBeGreaterThan(0);

        for (const box of noticeBoxes) {
            expect(typeof box.if).toBe('string');
            expect(box.if as string).toContain("status === 'blinded'");
            // 안내 박스 자체는 content 에 의존하지 않아야 함 (권한 없어도 안내는 보여야 함)
            expect(box.if as string).not.toContain('content');
        }
    });
});

describe('이슈 #413-34 — 블라인드 댓글 원문 게이트 (_comment_item.json)', () => {
    it("댓글 '원글 보기' 토글 버튼의 if 가 content 존재에 의존한다", () => {
        const buttons = findByComment(commentItem, '원글 보기/숨기기 토글 버튼');
        expect(buttons.length).toBeGreaterThan(0);

        for (const btn of buttons) {
            expect(typeof btn.if).toBe('string');
            expect(btn.if as string).toContain('content');
        }
    });

    it('블라인드 댓글 원본 내용 표시 블록의 if 가 content 존재에 의존한다', () => {
        const blocks = findByComment(commentItem, '블라인드 댓글 원본 내용 표시');
        expect(blocks.length).toBeGreaterThan(0);

        for (const block of blocks) {
            expect(typeof block.if).toBe('string');
            expect(block.if as string).toContain('content');
        }
    });
});
