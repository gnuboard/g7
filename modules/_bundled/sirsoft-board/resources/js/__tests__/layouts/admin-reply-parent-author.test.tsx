/**
 * 관리자 답변하기 화면 - 원글 작성자 표시 키 경로 회귀 테스트 (#413-70-1)
 *
 * @description
 * 답변하기 폼 상단의 원글 정보 헤더에서 작성자 이름이 '알 수 없음'으로
 * 표시되던 버그의 회귀 방지.
 *
 * 근본 원인: 레이아웃이 `parent_post.user.name`(존재하지 않는 키)을 참조.
 * 백엔드(getFormMeta → PostResource::toArray)는 작성자를 `author` 키로 노출하므로
 * `parent_post.author.name`을 참조해야 한다.
 */

import { describe, it, expect } from 'vitest';

import parentPost from '../../../layouts/admin/partials/admin_board_post_form/_parent_post.json';

/**
 * JSON 트리에서 text 표현식을 가진 모든 노드를 재귀 수집합니다.
 *
 * @param node 탐색 시작 노드
 * @returns text 문자열 배열
 */
function collectTexts(node: any): string[] {
    const results: string[] = [];
    if (!node || typeof node !== 'object') return results;

    if (typeof node.text === 'string') {
        results.push(node.text);
    }

    if (Array.isArray(node.children)) {
        for (const child of node.children) {
            results.push(...collectTexts(child));
        }
    }

    if (node.slots) {
        for (const slotChildren of Object.values(node.slots)) {
            if (Array.isArray(slotChildren)) {
                for (const child of slotChildren) {
                    results.push(...collectTexts(child as any));
                }
            }
        }
    }

    return results;
}

describe('관리자 답변하기 - 원글 작성자 키 경로 (#413-70-1)', () => {
    const texts = collectTexts(parentPost);

    it('원글 작성자 이름을 parent_post.author.name 경로로 참조한다', () => {
        const authorText = texts.find((t) => t.includes('parent_post') && t.includes('.name'));

        expect(authorText, '원글 작성자 이름 표현식이 존재해야 한다').toBeDefined();
        expect(authorText).toContain('parent_post?.author?.name');
    });

    it('존재하지 않는 parent_post.user.name 경로를 참조하지 않는다 (알 수 없음 회귀 방지)', () => {
        const usesWrongPath = texts.some((t) => t.includes('parent_post?.user?.name'));

        expect(usesWrongPath, 'parent_post.user.name 은 백엔드에 없는 키 — author 로 참조해야 함').toBe(false);
    });
});
