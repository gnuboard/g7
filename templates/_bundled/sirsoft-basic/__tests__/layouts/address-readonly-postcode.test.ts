/**
 * 주소칸 — 우편번호 플러그인이 꺼진 사이트에서는 직접 입력 (#822)
 *
 * @description
 * 우편번호·기본 주소 칸은 주소 검색 플러그인(sirsoft-daum_postcode)이 채우도록 읽기 전용이었다. 플러그인은 선택 연동이라
 * 꺼진 사이트에서는 검색 단추가 없어 주소를 영영 넣을 수 없었다(회원정보 수정·배송지 관리·주문 배송지 변경·결제 배송지 관리).
 * 읽기 전용 여부를 `_global.activePlugins` 로 정하고(활성 목록이 없는 옛 코어에서는 지금처럼 읽기 전용), 직접 친 값이
 * 상태로 가도록 change 액션을 둔다. 회원정보 수정은 폼 자동 바인딩(profileEditForm)이라 액션이 필요 없다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

type Node = Record<string, any>;

const LAYOUT_ROOT = path.resolve(__dirname, '../../layouts');
const POSTCODE_READONLY = "{{_global.activePlugins?.includes('sirsoft-daum_postcode') ?? true}}";

const load = (rel: string): Node => JSON.parse(fs.readFileSync(path.join(LAYOUT_ROOT, `${rel}.json`), 'utf8'));

/** 트리에서 노드를 모은다. */
function nodes(tree: unknown, out: Node[] = []): Node[] {
    if (Array.isArray(tree)) {
        tree.forEach((child) => nodes(child, out));
        return out;
    }
    if (tree && typeof tree === 'object') {
        const obj = tree as Node;
        if (typeof obj.name === 'string') out.push(obj);
        Object.values(obj).forEach((value) => nodes(value, out));
    }
    return out;
}

/** name 이 같은 Input 노드 하나를 찾는다. */
function input(tree: Node, name: string): Node {
    const found = nodes(tree).filter((n) => n.name === 'Input' && n.props?.name === name);
    expect(found, name).toHaveLength(1);
    return found[0];
}

/** change 액션이 쓰는 상태 경로를 모은다. */
function changeTargets(node: Node): string[] {
    return (node.actions ?? [])
        .filter((a: Node) => a.type === 'change')
        .flatMap((a: Node) => Object.keys(a.params ?? {}).filter((k) => k !== 'target'));
}

describe('주소칸 읽기 전용 — 우편번호 플러그인 가드 (#822)', () => {
    // @scenario surface=profile_edit
    // @effects address_input_editable_without_postcode
    it('회원정보 수정(코어 화면)의 우편번호·주소 칸은 플러그인이 켜져 있을 때만 읽기 전용이다(이메일 칸은 그대로 읽기 전용)', () => {
        const edit = load('partials/mypage/profile/_edit');
        expect(input(edit, 'zipcode').props.readOnly).toBe(POSTCODE_READONLY);
        expect(input(edit, 'address').props.readOnly).toBe(POSTCODE_READONLY);
        const email = nodes(edit).find((n) => n.name === 'Input' && n.props?.type === 'email')!;
        expect(email.props.readOnly).toBe(true);
    });

    // @scenario surface=address_modals
    // @effects address_input_editable_without_postcode
    it.each([
        ['partials/mypage/addresses/_modal_address', 'editingAddress'],
        ['partials/mypage/orders/_modal_change_address', 'editingShippingAddress'],
        ['partials/shop/_modal_address_manage', 'checkoutAddressModal.editing'],
    ])('%s 의 우편번호·주소 칸은 플러그인 가드 readOnly + 직접 입력 값을 %s 에 옮기는 change 액션을 둔다', (file, statePath) => {
        const tree = load(file);
        for (const name of ['zipcode', 'address']) {
            const node = input(tree, name);
            expect(node.props.readOnly, `${name}.readOnly`).toBe(POSTCODE_READONLY);
            expect(changeTargets(node), `${name} change`).toEqual([`${statePath}.${name}`]);
        }
    });

    // @scenario surface=checkout_shipping
    // @effects address_input_editable_without_postcode
    it('결제 배송지 칸은 이미 편집 가능(readOnly false)이고 change 액션이 있다 — 바꾸지 않는다', () => {
        const tree = load('partials/shop/_checkout_shipping');
        for (const name of ['zipcode', 'address']) {
            const node = input(tree, name);
            expect(node.props.readOnly).toBe(false);
            expect(changeTargets(node)).toEqual([`shipping.${name}`]);
        }
    });

    // @scenario surface=all_layouts
    // @effects address_input_editable_without_postcode
    it('우편번호 검색 확장 자리 옆의 우편번호·주소 칸에 고정 readOnly(true)가 남아 있지 않다', () => {
        const offenders: string[] = [];
        const walk = (dir: string) => {
            for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
                const full = path.join(dir, entry.name);
                if (entry.isDirectory()) walk(full);
                else if (entry.name.endsWith('.json')) {
                    const tree = JSON.parse(fs.readFileSync(full, 'utf8'));
                    const all = nodes(tree);
                    if (!all.some((n) => n.name === 'address_search_slot' || n.props?.name === 'address_search_slot')) continue;
                    all.filter((n) => n.name === 'Input' && ['zipcode', 'address'].includes(n.props?.name) && n.props?.readOnly === true)
                        .forEach((n) => offenders.push(`${path.relative(LAYOUT_ROOT, full)}:${n.props.name}`));
                }
            }
        };
        walk(LAYOUT_ROOT);
        expect(offenders).toEqual([]);
    });
});
