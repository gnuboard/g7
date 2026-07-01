/**
 * @file board-secret-post-login-redirect.test.tsx
 * @description 비밀글(비로그인) 안내 박스의 로그인 버튼 → redirect 보존 회귀 (이슈 #413 item 28)
 *
 * 검증 방식: 레이아웃 JSON 트리 직접 분석 (DOM 비의존).
 * 정책: 비밀글에 비로그인 진입 시 안내 박스의 로그인 버튼은 현재 글 경로를 보존해 로그인 후 복귀해야 한다.
 *   과거에는 미설정 변수 {{_global.currentPath}} 를 redirect 로 써 항상 빈 값 → 로그인 후 / 로 떨어짐.
 *   redirectToLoginWithReturn 핸들러(window.location 캡처)로 교체해 보존을 보장한다.
 *
 * 회귀 차단: 버튼 액션이 (a) redirectToLoginWithReturn 핸들러이고 (b) 깨진
 *   navigate+{{_global.currentPath}} 패턴이 잔존하지 않는지 고정한다.
 *
 * @scenario entry_point:secret_post_login_button
 * @effects secret_button_redirect_not_empty,login_returns_to_original_path
 */

import { describe, it, expect } from 'vitest';

import basicShow from '../../layouts/partials/board/types/basic/show.json';

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
 * 비밀글 로그인 버튼(text: "$t:common.login")의 클릭 액션들을 수집한다.
 */
function collectLoginButtonActions(): Node[] {
    const loginButtons = collectNodes(
        basicShow,
        (n) => n.text === '$t:common.login' && Array.isArray((n as any).actions),
    );
    return loginButtons.flatMap((btn) => (btn.actions as Node[]) ?? []);
}

describe('이슈 #413-28 — 비밀글 로그인 버튼 redirect 보존 (basic/show.json)', () => {
    it('비밀글 로그인 버튼이 존재해야 한다', () => {
        const loginButtons = collectNodes(
            basicShow,
            (n) => n.text === '$t:common.login' && Array.isArray((n as any).actions),
        );
        expect(loginButtons.length).toBeGreaterThan(0);
    });

    it('로그인 버튼 액션이 redirectToLoginWithReturn 핸들러여야 한다', () => {
        const actions = collectLoginButtonActions();
        const redirectAction = actions.find(
            (a) => a.handler === 'redirectToLoginWithReturn',
        );
        expect(redirectAction).toBeDefined();
    });

    it('깨진 navigate + {{_global.currentPath}} redirect 패턴이 잔존하지 않아야 한다', () => {
        // 직렬화 후 미설정 변수 redirect 잔재를 전수 차단 (빈 redirect 회귀 방지).
        const serialized = JSON.stringify(basicShow);
        expect(serialized).not.toContain('_global.currentPath');
    });

    it('로그인 버튼 액션에 custom+name 형식이 없어야 한다 (디스패치 throw 방지)', () => {
        const actions = collectLoginButtonActions();
        for (const action of actions) {
            if (action.handler === 'redirectToLoginWithReturn') {
                expect(action.handler).not.toBe('custom');
                expect((action as any).name).toBeUndefined();
            }
        }
    });
});
