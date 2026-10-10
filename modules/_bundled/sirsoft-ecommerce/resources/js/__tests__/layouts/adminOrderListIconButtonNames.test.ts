/**
 * @file adminOrderListIconButtonNames.test.ts
 * @description 관리자 주문 목록의 아이콘만 있는 버튼이 읽을 수 있는 이름을 갖는지 (S3-D24, 2026-10-07 — S5-D30 같은 결함군 인계)
 *
 * 글자 없이 아이콘만 그리는 버튼은 aria-label 이 없으면 화면 낭독기가 아이콘 이름(「arrow-left」·「trash」 등)으로 읽거나
 * 그냥 「버튼」 이라고만 읽는다. 주문 목록 머리의 [뒤로]·[새로고침], 검색 조건 관리 창의 [편집]·[삭제], 필터의
 * 주문자 선택 해제 [×] 다섯 곳이 그랬다(출시본 1.2.1 JSON 에도 같은 버튼). 판정은 S5 시험
 * `adminIconOnlyButtonNames.test.ts` 와 같다 — 아이콘을 품고 보이는 글자가 없으며 aria-label·title 도 없는 버튼.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const LAYOUTS = resolve(__dirname, '../../../layouts/admin');

type Node = Record<string, any>;

/**
 * 노드의 보이는 글자를 모읍니다. 아이콘은 글자가 아니다.
 *
 * @param n 노드
 * @returns 글자
 */
function textOf(n: any): string {
    if (n == null) return '';
    if (typeof n === 'string') return n;
    if (Array.isArray(n)) return n.map(textOf).join('');
    if (typeof n === 'object') {
        if (n.name === 'Icon') return '';

        return String(n.text ?? '') + textOf(n.children);
    }

    return '';
}

/**
 * 아이콘을 품은 버튼을 모두 모읍니다.
 *
 * @param node 시작 노드
 * @param acc 누적
 * @returns 버튼 목록
 */
function iconButtons(node: any, acc: Node[] = []): Node[] {
    if (Array.isArray(node)) {
        node.forEach((n) => iconButtons(n, acc));

        return acc;
    }
    if (node && typeof node === 'object') {
        if (node.name === 'Button' && JSON.stringify(node.children ?? []).includes('"name":"Icon"')) acc.push(node);
        Object.values(node).forEach((v) => iconButtons(v, acc));
    }

    return acc;
}

/**
 * 버튼이 그리는 아이콘 이름을 꺼냅니다.
 *
 * @param button 버튼 노드
 * @returns 아이콘 이름
 */
const iconOf = (button: Node): string => JSON.stringify(button.children).match(/"name":"Icon","props":\{"name":"([^"]+)"/)?.[1] ?? '?';

const load = (rel: string): Node => JSON.parse(readFileSync(resolve(LAYOUTS, `${rel}.json`), 'utf8'));

/**
 * 아이콘만 있는 버튼 가운데 이름이 없는 것의 아이콘 이름을 돌려줍니다.
 *
 * @param rel 레이아웃 상대 경로
 * @returns 이름 없는 버튼의 아이콘 이름 목록
 */
function unnamed(rel: string): string[] {
    return iconButtons(load(rel))
        .filter((b) => !textOf({ ...b, name: 'Button' }).trim() && !b.props?.['aria-label'] && !b.props?.title)
        .map(iconOf);
}

/**
 * 아이콘 이름으로 버튼 하나를 찾아 aria-label 을 돌려줍니다.
 *
 * @param rel 레이아웃 상대 경로
 * @param icon 아이콘 이름
 * @returns aria-label 목록(같은 아이콘 버튼이 여럿이면 모두)
 */
function labelsOf(rel: string, icon: string): string[] {
    return iconButtons(load(rel)).filter((b) => iconOf(b) === icon).map((b) => String(b.props?.['aria-label'] ?? ''));
}

describe('관리자 주문 목록 — 아이콘 버튼 이름', () => {
    /**
     * @scenario surface=list_header
     *
     * @effects order_list_icon_buttons_named
     */
    it('목록 머리 [뒤로]·[새로고침] 은 하는 일을 이름으로 단다', () => {
        const rel = 'admin_ecommerce_order_list';
        expect(unnamed(rel)).toEqual([]);
        expect(labelsOf(rel, 'arrow-left')).toEqual(['$t:common.back']);
        expect(labelsOf(rel, 'arrows-rotate')).toEqual(['$t:common.refresh']);
    });

    /**
     * @scenario surface=preset_manage_modal
     *
     * @effects order_list_icon_buttons_named
     */
    it('검색 조건 관리 창의 [편집]·[삭제] 는 이름을 단다', () => {
        const rel = 'partials/admin_ecommerce_order_list/_modal_preset_manage';
        expect(unnamed(rel)).toEqual([]);
        expect(labelsOf(rel, 'pen-to-square')).toEqual(['$t:common.edit']);
        expect(labelsOf(rel, 'trash')).toEqual(['$t:common.delete']);
    });

    /**
     * @scenario surface=filter_section
     *
     * @effects order_list_icon_buttons_named
     */
    it('필터의 주문자 선택 해제 [×] 는 이름을 단다', () => {
        const rel = 'partials/admin_ecommerce_order_list/_partial_filter_section';
        expect(unnamed(rel)).toEqual([]);
        expect(labelsOf(rel, 'xmark')).toContain('$t:common.remove');
    });
});
