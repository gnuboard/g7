/**
 * @file board-secret-comment-placeholder.test.tsx
 * @description 비밀 댓글 안내 블록 게이트 회귀 (KVE-2026-2371)
 *
 * 검증 방식: 레이아웃 JSON 트리 직접 분석 (DOM 비의존).
 * 정책: 서버(CommentResource)가 열람 권한 없는 사용자에게 비밀 댓글을 content=null + is_secret=true 로
 *   보낸다. 화면은 그 조합일 때 원문 줄 대신 '비밀 댓글입니다' 안내를 그리고, 반응 줄도 숨긴다.
 *   권한자(content 가 실려 옴)에게는 종전대로 원문 줄이 나온다.
 *
 * 회귀 배경: 비밀 '글'에는 안내 분기가 있었지만 댓글에는 없어, 마스킹된 비밀 댓글이 빈 줄로 보였다.
 *
 * @effects secret_comment_placeholder_rendered
 */

import { describe, it, expect } from 'vitest';

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

const MASKED = 'comment?.is_secret && comment?.content === null';

describe('KVE-2026-2371 — 비밀 댓글 안내 (_comment_item.json)', () => {
    // @scenario viewer=outsider
    // @effects secret_comment_placeholder_rendered
    it('안내 블록은 is_secret + content===null 일 때만 그려지고 안내 문구 키를 쓴다', () => {
        const blocks = findByComment(commentItem, '비밀 댓글 (열람 권한 없음)');
        expect(blocks).toHaveLength(1);

        const block = blocks[0];
        expect(typeof block.if).toBe('string');
        expect(block.if as string).toContain('comment?.is_secret');
        expect(block.if as string).toContain('comment?.content === null');

        const texts = collectNodes(block, (n) => n.text === '$t:board.comment.secret_comment');
        expect(texts).toHaveLength(1);
    });

    // @scenario viewer=outsider
    // @effects secret_comment_placeholder_rendered
    it('원문 줄은 마스킹된 비밀 댓글에서 빠진다 (빈 줄 대신 안내만 남게)', () => {
        const lines = findByComment(commentItem, '댓글 내용 (읽기 모드)');
        expect(lines).toHaveLength(1);
        expect(lines[0].if as string).toContain(`!(${MASKED})`);
    });

    // @scenario viewer=outsider
    // @effects secret_comment_placeholder_rendered
    it('반응 줄도 마스킹된 비밀 댓글에서는 그리지 않는다 (서버가 어차피 거절)', () => {
        const rows = findByComment(commentItem, '댓글 반응');
        expect(rows.length).toBeGreaterThan(0);
        for (const row of rows) {
            expect(row.if as string).toContain(`!(${MASKED})`);
        }
    });
});
