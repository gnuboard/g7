/**
 * @file board-deleted-reply-button.test.tsx
 * @description 삭제된 게시글 상세의 답변 버튼 차단 회귀 (이슈 #413-50-3)
 *
 * 검증 방식: 레이아웃 JSON 트리 직접 분석 (DOM 비의존).
 * 정책: 삭제된 게시글(deleted_at 존재)에서는 답변 버튼을 노출하지 않는다.
 *   기존에는 답변 버튼 if 조건에 status !== 'blinded' 만 있고 삭제 검사(!deleted_at)가
 *   없어, 삭제글에서도 답변 버튼이 노출되고 답글 폼 진입이 가능했다(#413-44 와 동일 계열).
 *
 * 회귀 배경: 답변 버튼은 폼 진입(write?parent_id=)의 입구. 백엔드(#413-44)가 폼 진입을
 *   차단하더라도, 버튼이 노출되면 사용자가 삭제글에 답글을 시도하는 경험상 결함이 남는다.
 *
 * @scenario viewer=regular
 * @effects reply_button_if_blocks_deleted_at, reply_button_keeps_blinded_guard
 */

import { describe, it, expect } from 'vitest';

import basicShow from '../../layouts/partials/board/types/basic/show.json';

type Node = Record<string, unknown> & { children?: unknown };

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

function findByComment(root: unknown, commentText: string): Node[] {
    return collectNodes(root, (n) => typeof n.comment === 'string' && (n.comment as string).includes(commentText));
}

describe('이슈 #413-50-3 — 삭제 게시글 답변 버튼 차단 (basic/show.json)', () => {
    it('답변 버튼의 if 가 삭제 상태(deleted_at)를 차단한다', () => {
        const buttons = findByComment(basicShow, '답변 버튼');
        expect(buttons.length).toBeGreaterThan(0);

        for (const btn of buttons) {
            expect(typeof btn.if).toBe('string');
            // 삭제글(deleted_at 존재)에서 미노출되도록 if 가 deleted_at 을 참조해야 함
            expect(btn.if as string).toContain('deleted_at');
        }
    });

    it('답변 버튼은 블라인드 차단 조건을 유지한다 (회귀 방지)', () => {
        const buttons = findByComment(basicShow, '답변 버튼');
        expect(buttons.length).toBeGreaterThan(0);

        for (const btn of buttons) {
            expect(btn.if as string).toContain("status !== 'blinded'");
        }
    });
});
