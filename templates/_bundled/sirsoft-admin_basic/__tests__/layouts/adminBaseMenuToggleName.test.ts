/**
 * @file adminBaseMenuToggleName.test.ts
 * @description 관리자 머리말의 메뉴(☰) 버튼이 읽을 수 있는 이름과 펼침 상태를 갖는지
 *
 * 관리자 모든 화면 머리말 왼쪽의 메뉴 버튼은 아이콘(`<i class="fas fa-bars">`)만 그려, 화면 낭독기가 이름 없는
 * 「버튼」 으로만 읽었다(이커머스 전수 실측 S5, 2026-10-07 — 쇼핑몰 환경설정 화면에서 발견). 이 버튼은 데스크톱
 * 사이드바를 접고 펼친다(`toggleSidebar` → `_global.sidebarCollapsed`). 접혀 있으면 「사이드바 펼치기」, 펼쳐져
 * 있으면 「사이드바 접기」 로 읽고, 펼침 여부를 aria-expanded 로 알린다.
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

type Node = Record<string, any>;

const layout: Node = JSON.parse(readFileSync(resolve(__dirname, '../../layouts/_admin_base.json'), 'utf8'));

/**
 * 레이아웃 트리에서 id 로 노드를 찾습니다.
 *
 * @param node 시작 노드
 * @param id 노드 id
 * @return 찾은 노드
 */
function findById(node: any, id: string): Node | undefined {
    if (Array.isArray(node)) {
        for (const n of node) {
            const hit = findById(n, id);
            if (hit) return hit;
        }

        return undefined;
    }
    if (node && typeof node === 'object') {
        if (node.id === id) return node;
        for (const v of Object.values(node)) {
            const hit = findById(v, id);
            if (hit) return hit;
        }
    }

    return undefined;
}

const button = findById(layout, 'menu_toggle_btn');

describe('관리자 머리말 메뉴 버튼 이름', () => {
    /**
     * @scenario case=label
     * @effects menu_toggle_named_by_sidebar_state
     */
    it('접힘 상태에 따라 「사이드바 펼치기」/「사이드바 접기」 를 이름으로 단다', () => {
        expect(button).toBeDefined();
        expect(button?.props?.['aria-label']).toBe("{{_global.sidebarCollapsed ? '$t:common.expand_sidebar' : '$t:common.collapse_sidebar'}}");
    });

    /**
     * @scenario case=expanded
     * @effects menu_toggle_announces_expanded
     */
    it('펼침 여부를 aria-expanded 로 알린다', () => {
        // 불리언 식으로 둔다 — React 가 aria-* 불리언을 "true"/"false" 로 쓴다(저장소 관례)
        expect(button?.props?.['aria-expanded']).toBe('{{!_global.sidebarCollapsed}}');
    });

    /**
     * 좁은 화면 머리말의 ☰ 버튼도 같다 — 글자 「☰」 만 있어 화면 낭독기가 기호 이름으로 읽었다.
     * 이 버튼은 모바일 사이드바를 열고 닫는다(`_global.sidebarOpen`).
     *
     * @scenario case=mobile
     * @effects menu_toggle_named_by_sidebar_state, menu_toggle_announces_expanded
     */
    it('모바일 ☰ 버튼도 열림 상태에 따른 이름·aria-expanded·type=button 을 갖는다', () => {
        const mobile = findById(layout, 'mobile_menu_toggle');
        expect(mobile).toBeDefined();
        expect(mobile?.props?.['aria-label']).toBe("{{_global.sidebarOpen ? '$t:common.collapse_sidebar' : '$t:common.expand_sidebar'}}");
        expect(mobile?.props?.['aria-expanded']).toBe('{{!!_global.sidebarOpen}}');
        expect(mobile?.props?.type).toBe('button');
    });
});
