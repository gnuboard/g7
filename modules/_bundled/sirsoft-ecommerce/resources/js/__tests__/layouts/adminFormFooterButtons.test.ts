/**
 * 쇼핑몰 관리자 등록·수정 화면의 버튼 자리 계약
 *
 * 쿠폰 등록 화면만 제목 왼쪽에 뒤로가기 아이콘을 두고 취소·저장을 화면 위쪽에만 두어, 하단 고정 줄에 버튼을 두는
 * 다른 등록 화면(상품·배송정책·개인결제, 코어 사용자 등록)과 달랐다. 등록 화면은
 * 머리에 제목·설명만 두고, 저장은 하단 고정 줄(`footer_buttons`)에 둔다.
 *
 * 모집단은 `resources/layouts/admin` 의 `*_form.json`·`*_create.json` 을 디렉터리에서 읽어 정한다 — 화면이 늘면 자동으로 들어온다.
 */
import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

type Node = { id?: string; name?: string; props?: Record<string, any>; children?: Node[]; text?: string };

const DIR = path.resolve(__dirname, '../../../layouts/admin');
const FORMS = fs.readdirSync(DIR).filter((f) => /_(form|create)\.json$/.test(f)).sort();

const load = (file: string): any => JSON.parse(fs.readFileSync(path.join(DIR, file), 'utf8'));

function walk(node: unknown, visit: (n: Node) => void): void {
    if (Array.isArray(node)) { node.forEach((n) => walk(n, visit)); return; }
    if (!node || typeof node !== 'object') return;
    const n = node as Node & Record<string, unknown>;
    if (typeof n.name === 'string' || typeof n.id === 'string') visit(n);
    for (const value of Object.values(n)) if (value && typeof value === 'object') walk(value, visit);
}

function find(root: unknown, id: string): Node | null {
    let hit: Node | null = null;
    walk(root, (n) => { if (!hit && n.id === id) hit = n; });
    return hit;
}

describe('쇼핑몰 관리자 등록·수정 화면 — 버튼은 하단 고정 줄', () => {
    /**
     * @scenario area=admin_form_layout
     * @effects admin_form_buttons_in_sticky_footer
     */
    it('모집단이 비지 않고 쿠폰 폼을 포함한다', () => {
        expect(FORMS.length).toBeGreaterThanOrEqual(4);
        expect(FORMS).toContain('admin_ecommerce_promotion_coupon_form.json');
    });

    /**
     * @scenario area=admin_form_layout
     * @effects admin_form_buttons_in_sticky_footer
     */
    it.each(FORMS)('%s — 머리(page_header)에 뒤로가기 아이콘·저장 버튼이 없다', (file) => {
        const header = find(load(file), 'page_header');
        expect(header, 'page_header 없음').not.toBeNull();
        const icons: string[] = [];
        const buttons: string[] = [];
        walk(header, (n) => {
            if (n.name === 'Icon' && typeof n.props?.name === 'string') icons.push(n.props.name);
            if (n.name === 'Button' || n.name === 'A') buttons.push(n.id ?? '(id 없음)');
        });
        expect(icons).not.toContain('arrow-left');
        expect(buttons.filter((id) => /save|cancel|back/.test(id))).toEqual([]);
    });

    /**
     * @scenario area=admin_form_layout
     * @effects admin_form_buttons_in_sticky_footer
     */
    it.each(FORMS)('%s — 하단 고정 줄에 저장 버튼이 있다', (file) => {
        const footer = find(load(file), 'footer_buttons');
        expect(footer, 'footer_buttons 없음').not.toBeNull();
        expect(String(footer!.props?.className ?? '')).toMatch(/ecommerce-sticky-footer-buttons/);
        // 저장 버튼 id 는 화면마다 다르다(footer_save_button·save_button) — 하단 줄 안에 저장 버튼이 있는지만 본다
        const saves: string[] = [];
        walk(footer, (n) => { if (n.name === 'Button' && /save/.test(n.id ?? '')) saves.push(n.id!); });
        expect(saves.length, '하단 줄에 저장 버튼 없음').toBeGreaterThan(0);
    });

    /**
     * @scenario area=admin_form_layout
     * @effects admin_form_buttons_in_sticky_footer
     */
    it('쿠폰 폼의 [취소] 는 하단 줄에 있고 목록 상태를 이고 돌아간다', () => {
        const cancel = find(find(load('admin_ecommerce_promotion_coupon_form.json'), 'footer_buttons'), 'footer_cancel_button');
        expect(cancel).not.toBeNull();
        expect(cancel!.props?.href).toBe('/admin/ecommerce/promotion-coupons');
        expect(cancel!.props?.mergeQuery).toBe(true);
    });
});
