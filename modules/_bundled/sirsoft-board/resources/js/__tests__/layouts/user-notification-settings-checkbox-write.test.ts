// e2e:allow Vitest 레이아웃 계약 — 회원 등록·수정 화면 체크박스 클릭은 7.1.0 실측 K4(K4-D7)에서 Playwright 로 클릭 전후 checked·_local.form 값을 재측정했다.
/**
 * 관리자 회원 등록·수정 폼의 게시판 알림 수신 설정 체크박스 — 클릭이 불리언으로 저장된다.
 *
 * 회귀 배경 (7.1.0 실측 K4-D7):
 *  - 네 체크박스가 기본 Input 을 폼 자동 바인딩에 맡겼다. 엔진은 바인딩 값이 이미 불리언일 때만
 *    `checked` 로 묶고 그렇지 않으면 `value`(빈 문자열)를 쓴다. 회원 등록 화면은 form 을 비운 채 시작해
 *    값이 없으므로 클릭해도 `_local.form.notify_comment = ""` 가 되고 체크가 켜지지 않았다(수정 화면에서도
 *    처음 false 였던 칸은 같은 길로 켤 수 없음). 관리자가 회원을 추가하며 고른 설정을 저장한다는 7.1.0 약속이
 *    화면에서 성립하지 않았다.
 *  - 마케팅 동의 체크박스처럼 자동 바인딩을 끄고 change 에서 `$event.target.checked` 를 직접 쓴다.
 */

import { describe, it, expect } from 'vitest';

import notificationFormExt from '../../../extensions/user-notification-settings.json';

const FIELDS = ['notify_post_complete', 'notify_post_reply', 'notify_comment', 'notify_reply_comment'];

/**
 * 트리에서 name 이 일치하는 노드를 모두 찾는다(모든 객체 키 순회).
 *
 * @param node 탐색 시작 노드
 * @param name 컴포넌트 이름
 * @returns 일치하는 노드 목록
 */
function findByName(node: any, name: string): any[] {
    const results: any[] = [];
    if (!node || typeof node !== 'object') return results;
    if (node.name === name) results.push(node);
    for (const key of Object.keys(node)) {
        results.push(...findByName(node[key], name));
    }
    return results;
}

describe('게시판 알림 수신 설정 체크박스 — 클릭 값 기록', () => {
    const checkboxes = findByName(notificationFormExt as any, 'Input').filter((c: any) => c.props?.type === 'checkbox');

    // @scenario screen=admin_user_create
    // @effects notification_checkbox_click_writes_boolean
    it('네 체크박스 모두 자동 바인딩을 끄고 change 에서 checked 불리언을 form 에 쓴다', () => {
        expect(checkboxes).toHaveLength(4);
        for (const name of FIELDS) {
            const input = checkboxes.find((c: any) => c.props.name === name);
            expect(input, name).toBeDefined();
            expect(input.props.autoBinding, name).toBe(false);
            const change = (input.actions ?? []).find((a: any) => a.type === 'change');
            expect(change, `${name} change 액션 없음`).toBeDefined();
            expect(change.handler).toBe('setState');
            expect(change.params.target).toBe('local');
            expect(change.params[`form.${name}`]).toBe('{{$event.target.checked}}');
        }
    });

    // @scenario screen=admin_user_edit
    // @effects notification_checkbox_reflects_saved_value
    it('체크 표시는 저장된 값(없으면 꺼짐)을 그대로 따른다', () => {
        for (const name of FIELDS) {
            const input = checkboxes.find((c: any) => c.props.name === name);
            expect(input.props.checked).toBe(`{{_local.form?.${name} ?? false}}`);
        }
    });
});
