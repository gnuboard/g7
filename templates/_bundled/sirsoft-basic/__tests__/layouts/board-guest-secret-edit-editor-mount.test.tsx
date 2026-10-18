/**
 * @file board-guest-secret-edit-editor-mount.test.tsx
 * @description 비회원 비밀글 수정 — 본문이 도착한 뒤에 편집기를 띄운다 (KVE-2026-2375 후속)
 *
 * 검증 방식: 레이아웃 JSON 의 편집기 래퍼 표시 조건을 화면 상태 표본으로 평가 (DOM 비의존).
 * 정책: 수정 폼은 비밀글 본문을 비밀번호 확인 전에는 받지 않는다. 비밀번호를 맞히면 폼 값을
 *   다시 받는데, 그 사이에 편집기가 먼저 뜨면 처음 받은 빈 본문으로 세워진 뒤 늦게 온 본문을
 *   반영하지 않는다(편집기는 경로가 바뀔 때만 외부 값을 다시 읽는다). 그 상태에서 입력·저장하면
 *   원문이 지워진다. 그래서 «비밀글 + 비밀번호 확인 직후 + 다시 받은 폼 값 미도착» 동안만
 *   편집기를 그리지 않는다. 다시 받은 폼 값에는 검증 토큰이 실려 오므로 그것을 도착 신호로 쓴다.
 *
 * 회귀 배경: 브라우저 실측(2026-10-02)에서 비회원 작성자가 비밀번호를 맞히자 첨부는 보였지만
 *   본문 칸이 비어 있었고, 한 글자 입력하자 폼 본문이 그 글자로 바뀌었다.
 *
 * @effects guest_secret_post_editor_waits_for_body
 */

import { describe, it, expect } from 'vitest';

import postForm from '../../layouts/partials/board/form/_post_form.json';

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
 * `{{ ... }}` 표시 조건을 화면 상태 표본으로 평가한다. 조건이 없으면 항상 그린다.
 */
function shows(node: Node, local: Record<string, unknown>): boolean {
    if (typeof node.if !== 'string') {
        return true;
    }
    const expr = (node.if as string).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
    // eslint-disable-next-line no-new-func
    return Boolean(new Function('_local', `return (${expr});`)(local));
}

const wrappers = collectNodes(
    postForm,
    (n) => typeof n.comment === 'string' && (n.comment as string).startsWith('내용 에디터 래퍼 (에러 테두리용)'),
);
const wrapper = wrappers[0];

describe('비회원 비밀글 수정 — 편집기 마운트 시점 (_post_form.json)', () => {
    // @scenario viewer=guest_author
    // @effects guest_secret_post_editor_waits_for_body
    it('편집기 래퍼가 정확히 하나 있고 그 안에 본문 편집기가 있다', () => {
        expect(wrappers).toHaveLength(1);
        expect(collectNodes(wrapper, (n) => n.id === 'content_section')).toHaveLength(1);
    });

    // @scenario viewer=guest_author
    // @effects guest_secret_post_editor_waits_for_body
    it('비밀글에서 비밀번호를 맞힌 직후, 다시 받은 폼 값이 오기 전에는 편집기를 그리지 않는다', () => {
        expect(shows(wrapper, {
            passwordVerified: true,
            form: { is_secret: true, content: '', verification_token: '' },
        })).toBe(false);
    });

    // @scenario viewer=guest_author
    // @effects guest_secret_post_editor_waits_for_body
    it('다시 받은 폼 값(검증 토큰 포함)이 도착하면 편집기를 그린다', () => {
        expect(shows(wrapper, {
            passwordVerified: true,
            form: { is_secret: true, content: '<p>본문</p>', verification_token: 'tok' },
        })).toBe(true);
    });

    // @scenario viewer=guest_author
    // @effects guest_secret_post_editor_waits_for_body
    it('비밀글이 아닌 글·비밀번호 창을 거치지 않는 경우(회원·관리자·새 글·답글)는 그대로 바로 그린다', () => {
        // 비회원 일반글 — 본문이 처음부터 실려 온다
        expect(shows(wrapper, { passwordVerified: true, form: { is_secret: false, content: '본문', verification_token: '' } })).toBe(true);
        // 회원 본인·게시판 관리자 — 비밀번호 창이 없다
        expect(shows(wrapper, { form: { is_secret: true, content: '<p>본문</p>' } })).toBe(true);
        // 새 글·답글 — 폼 값이 비어 있다
        expect(shows(wrapper, { form: { is_secret: false, content: '' } })).toBe(true);
        expect(shows(wrapper, {})).toBe(true);
    });
});
