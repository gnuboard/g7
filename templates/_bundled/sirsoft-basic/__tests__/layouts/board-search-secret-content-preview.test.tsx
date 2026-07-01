/**
 * @file board-search-secret-content-preview.test.tsx
 * @description 게시판 목록/검색 미리보기 비밀글·블라인드 차단 게이트 회귀 (이슈 #413-37)
 *
 * 검증 방식: 레이아웃 JSON 트리 직접 분석 (DOM 비의존).
 * 정책(PO 확정): 목록/검색 미리보기(content_preview)는 비밀글·블라인드 글에서
 *   권한과 무관하게 빈 문자열로 차단된다. 백엔드(PostResource::toListArray ->
 *   getMaskedContentPreviewForList)가 빈 문자열을 응답하면, 미리보기 노드의 if 가
 *   content_preview 존재(truthy)에 의존하므로 자동으로 미렌더된다.
 *
 * 본 테스트는 그 게이트(미리보기 노드의 if 가 content_preview 에 의존)가 유지되는지 고정한다.
 *
 * 회귀 배경: 과거 백엔드가 비밀글 본문 요약을 권한 무관하게 응답 →
 *   비로그인·제3자도 검색 결과에서 비밀글 본문 일부 열람 가능 (보안 결함, #413-37).
 *   백엔드 차단 + 본 게이트로 방어.
 */

import { describe, it, expect } from 'vitest';

import cardIndex from '../../layouts/partials/board/types/card/index.json';

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

describe('이슈 #413-37 — 게시판 카드 목록 미리보기 차단 게이트 (card/index.json)', () => {
    it('본문 요약 노드의 if 가 content_preview 존재에 의존한다 (빈 문자열이면 미렌더)', () => {
        const previews = findByComment(cardIndex, '본문 요약');
        expect(previews.length).toBeGreaterThan(0);

        for (const node of previews) {
            // 백엔드가 비밀글/블라인드에 content_preview='' 를 응답하면 falsy 가 되어
            // if 조건이 false → 미리보기 미노출. if 가 content_preview 를 참조해야 함.
            expect(typeof node.if).toBe('string');
            expect(node.if as string).toContain('content_preview');
        }
    });

    it('본문 요약 노드는 content_preview 를 그대로 바인딩한다 (별도 가공 없이 빈 문자열 전달 시 빈 출력)', () => {
        const previews = findByComment(cardIndex, '본문 요약');
        expect(previews.length).toBeGreaterThan(0);

        for (const node of previews) {
            const props = node.props as Record<string, unknown> | undefined;
            expect(props).toBeDefined();
            expect(typeof props?.content).toBe('string');
            // content 바인딩이 content_preview 를 참조해야 한다 (빈 문자열 전달 시 빈 출력)
            expect(props?.content as string).toContain('content_preview');
        }
    });
});
