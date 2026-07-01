/**
 * 게시판 알림 설정 — 관리자 회원수정 폼(admin_user_form) 주입 정합성 회귀 테스트
 *
 * @description
 * 회귀 배경 (#413-10 검수 중 발견):
 *  - user-notification-settings.json 의 주입 target_id 가 옛 슬롯 `extension_slot` 을
 *    가리켜, 활성 회원수정 폼 레이아웃(현재 슬롯 = user_form_tabs + extension_form_content)에서
 *    알림 토글 4개가 전혀 렌더되지 않던 결함.
 *  - 같은 슬롯을 쓰는 sirsoft-marketing 플러그인(탭 + extension_form_content)은 정상 렌더됨 → 대조로 확정.
 *
 * 검증 포인트:
 *  1. 주입 target_id 가 활성 회원폼 레이아웃에 실제 존재하는 슬롯 ID 와 일치 (extension_slot 회귀 가드)
 *  2. 탭 주입(user_form_tabs _append) + 내용 주입(extension_form_content) 2단 구조
 *  3. 내용 래퍼의 props.id 가 탭 id(ext_notification)와 연결되는 앵커
 *  4. 알림 토글 4개(notify_post_complete/post_reply/comment/reply_comment) 보존
 *  5. 상세 화면(detail) 주입과 탭 id/라벨/아이콘 통일
 */

import { describe, it, expect } from 'vitest';

import notificationFormExt from '../../../extensions/user-notification-settings.json';
import notificationDetailExt from '../../../extensions/user-notification-detail.json';
import adminUserForm from '../../../../../../../templates/sirsoft-admin_basic/layouts/admin_user_form.json';

/**
 * 트리에서 특정 ID 노드를 재귀 탐색합니다.
 * 주입 JSON(components/children) 과 레이아웃 JSON(slots/content 등) 양쪽을 다루기 위해
 * 모든 객체 키를 순회하는 범용 재귀를 사용합니다.
 */
function findById(node: any, id: string): any | null {
    if (!node || typeof node !== 'object') return null;
    if (node.id === id) return node;
    for (const key of Object.keys(node)) {
        const found = findById(node[key], id);
        if (found) return found;
    }
    return null;
}

/**
 * 트리에서 특정 name 컴포넌트 모두를 찾습니다 (모든 객체 키 순회).
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

describe('user-notification-settings.json - admin_user_form 주입 정합성', () => {
    it('admin_user_form 레이아웃을 타겟한다', () => {
        expect(notificationFormExt.target_layout).toBe('admin_user_form');
    });

    it('주입 target_id 들이 활성 회원폼 레이아웃에 실제 존재하는 슬롯과 일치한다 (extension_slot 회귀 가드)', () => {
        const targetIds = notificationFormExt.injections.map((inj: any) => inj.target_id);

        // 회귀 가드: 활성 회원폼에 없는 옛 슬롯 ID 를 다시 타겟하면 토글이 사라진다.
        expect(targetIds).not.toContain('extension_slot');

        // 모든 주입 타겟이 활성 레이아웃에 실제로 존재해야 한다.
        for (const tid of targetIds) {
            expect(findById(adminUserForm as any, tid), `회원폼 레이아웃에 슬롯 '${tid}' 없음`).not.toBeNull();
        }
    });

    it('탭 주입 + 내용 주입 2단 구조를 가진다', () => {
        const tabInj = notificationFormExt.injections.find((i: any) => i.target_id === 'user_form_tabs');
        const contentInj = notificationFormExt.injections.find((i: any) => i.target_id === 'extension_form_content');
        expect(tabInj).toBeDefined();
        expect(contentInj).toBeDefined();
        expect(tabInj.position).toBe('inject_props');
        expect(contentInj.position).toBe('append_child');
    });

    it('탭 주입은 ext_notification 탭을 _append 한다', () => {
        const tabInj = notificationFormExt.injections.find((i: any) => i.target_id === 'user_form_tabs');
        const appended = tabInj.props.tabs._append;
        expect(Array.isArray(appended)).toBe(true);
        expect(appended[0].id).toBe('ext_notification');
        expect(appended[0].label).toBe('$t:sirsoft-board.admin.users.form.sections.notification_settings');
    });

    it('내용 래퍼 props.id 가 탭 id(ext_notification)와 연결되는 앵커다', () => {
        const contentInj = notificationFormExt.injections.find((i: any) => i.target_id === 'extension_form_content');
        const wrapper = contentInj.components[0];
        expect(wrapper.props.id).toBe('ext_notification');
        // 탭 스크롤 앵커 클래스 (마케팅 플러그인과 동일 패턴)
        expect(wrapper.props.className).toContain('scroll-mt-32');
    });

    it('알림 토글 4개가 보존된다', () => {
        for (const fieldId of [
            'field_notify_post_complete',
            'field_notify_post_reply',
            'field_notify_comment',
            'field_notify_reply_comment',
        ]) {
            expect(findById(notificationFormExt as any, fieldId), `토글 '${fieldId}' 누락`).not.toBeNull();
        }
    });

    it('각 토글은 checkbox Input 과 _local.form 바인딩을 가진다', () => {
        const names = ['notify_post_complete', 'notify_post_reply', 'notify_comment', 'notify_reply_comment'];
        const inputs = findByName(notificationFormExt as any, 'Input').filter((c: any) => c.props?.type === 'checkbox');
        expect(inputs).toHaveLength(4);
        for (const name of names) {
            const input = inputs.find((c: any) => c.props.name === name);
            expect(input, `checkbox '${name}' 없음`).toBeDefined();
            expect(input.props.checked).toBe(`{{_local.form?.${name} ?? false}}`);
        }
    });

    it('ExtensionBadge 가 module/sirsoft-board 로 표기된다', () => {
        const badges = findByName(notificationFormExt as any, 'ExtensionBadge');
        expect(badges).toHaveLength(1);
        expect(badges[0].props.type).toBe('module');
        expect(badges[0].props.identifier).toBe('sirsoft-board');
    });
});

describe('user-notification-settings.json - 상세(detail) 주입과 탭 통일', () => {
    it('수정/상세 화면이 동일한 탭 id·라벨·아이콘을 사용한다', () => {
        const formTab = notificationFormExt.injections.find((i: any) => i.target_id === 'user_form_tabs')
            .props.tabs._append[0];
        const detailTab = notificationDetailExt.injections.find((i: any) => i.target_id === 'user_detail_tabs')
            .props.tabs._append[0];
        expect(formTab.id).toBe(detailTab.id);
        expect(formTab.label).toBe(detailTab.label);
        expect(formTab.iconName).toBe(detailTab.iconName);
    });
});
