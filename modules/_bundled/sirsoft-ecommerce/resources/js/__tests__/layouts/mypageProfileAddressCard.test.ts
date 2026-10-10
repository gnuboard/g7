/**
 * 마이페이지 내 정보 — 기본 배송지 카드 주입(mypage-profile-address-card.json) 계약 테스트
 *
 * @description
 * 사용자 템플릿(sirsoft-basic)에 주입되는 조각이라, 그 템플릿에 **등록된 컴포넌트만** 써야 한다.
 * 관리자 템플릿에만 있는 `Badge` 를 쓰면 사용자 화면에서 「컴포넌트를 찾을 수 없습니다」 로 그 자리가
 * 비고 오류도 화면에 남지 않는다(#599 validate 2026-09-23 — 카드의 「기본」 배지가 그랬다).
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

import card from '../../../extensions/mypage-profile-address-card.json';

/** 조각이 쓰는 컴포넌트 이름을 모두 모은다. */
function collectNames(node: any, out: Set<string> = new Set()): Set<string> {
    if (Array.isArray(node)) {
        node.forEach((child) => collectNames(child, out));
        return out;
    }
    if (!node || typeof node !== 'object') return out;
    if (typeof node.name === 'string' && typeof node.type === 'string') out.add(node.name);
    for (const value of Object.values(node)) collectNames(value, out);
    return out;
}

/** 조건을 만족하는 첫 노드 */
function findFirst(node: any, predicate: (n: any) => boolean): any | null {
    if (Array.isArray(node)) {
        for (const child of node) {
            const found = findFirst(child, predicate);
            if (found) return found;
        }
        return null;
    }
    if (!node || typeof node !== 'object') return null;
    if (predicate(node)) return node;
    for (const value of Object.values(node)) {
        const found = findFirst(value, predicate);
        if (found) return found;
    }
    return null;
}

/** sirsoft-basic 가 등록한 컴포넌트 이름 목록 */
function userTemplateComponents(): Set<string> {
    const manifest = JSON.parse(
        fs.readFileSync(
            path.resolve(__dirname, '../../../../../../../templates/_bundled/sirsoft-basic/components.json'),
            'utf-8'
        )
    );
    const names = new Set<string>();
    for (const group of Object.values(manifest.components ?? {})) {
        if (Array.isArray(group)) group.forEach((entry: any) => names.add(entry.name));
    }
    return names;
}

describe('기본 배송지 카드 주입 (mypage-profile-address-card.json)', () => {
    /**
     * @scenario screen=mypage_profile, address=default
     * @effects mypage_address_card_uses_user_template_components
     */
    it('mypage/profile 의 mypage_tab_content 에 append 한다', () => {
        expect(card.target_layout).toBe('mypage/profile');
        const injection = card.injections.find((i: any) => i.target_id === 'mypage_tab_content');
        expect(injection?.position).toBe('append_child');
    });

    /**
     * 사용자 템플릿에 없는 컴포넌트를 쓰면 그 자리가 조용히 빈다.
     *
     * @scenario screen=mypage_profile, address=default
     * @effects mypage_address_card_uses_user_template_components
     */
    it('사용자 템플릿(sirsoft-basic)에 등록된 컴포넌트만 쓴다', () => {
        const registered = userTemplateComponents();
        const used = Array.from(collectNames(card.injections));
        const missing = used.filter((name) => !registered.has(name));

        expect(missing).toEqual([]);
    });

    /**
     * 「기본」 배지는 등록된 기본 컴포넌트(Span)로 그리고, 라이트·다크 색을 짝으로 둔다.
     *
     * @scenario screen=mypage_profile, address=default
     * @effects mypage_address_card_uses_user_template_components
     */
    it('기본 배지는 Span 으로 그리고 다크 모드 색을 함께 갖는다', () => {
        const badge = findFirst(
            card.injections,
            (n) => typeof n?.text === 'string' && n.text.includes('user_address_card.default_badge')
        );

        expect(badge?.name).toBe('Span');
        expect(badge?.props?.className).toMatch(/dark:bg-/);
        expect(badge?.props?.className).toMatch(/dark:text-/);
    });

    /**
     * 등록한 배송지가 없으면 안내문이, 있으면 본문이 선다 — 카드 자체는 언제나 남는다.
     *
     * @scenario screen=mypage_profile, address=none
     * @effects mypage_address_card_uses_user_template_components
     */
    it('배송지가 없으면 안내문, 있으면 본문을 세운다', () => {
        const json = JSON.stringify(card);
        expect(json).toContain('user_address_card.empty_notice');
        expect(json).toContain('ecommerce_default_address?.address_summary');
        const root = card.injections[0].components[0];
        expect(root.if).toBeUndefined();
    });
});
