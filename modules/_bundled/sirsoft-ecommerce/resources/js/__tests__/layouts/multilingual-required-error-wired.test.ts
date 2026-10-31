/**
 * @file multilingual-required-error-wired.test.ts
 * @description 이커머스 이름 칸 — 탭형 필수 다국어 칸은 서버 오류를 컴포넌트 error 로 받는다 (#17 P10-a, M1 2차 재측정)
 *
 * MultilingualInput 은 error 가 새로 오고 필수 언어 칸이 비어 있으면 그 언어 탭을 연다(P10). 오류를 컴포넌트 밖의
 * 별도 문구 줄로만 그리면 컴포넌트가 오류를 몰라 다른 언어 탭에 머문다 — 게시판 기본 탭·배송정책에서 PUT 422 토스트만
 * 뜨고 English 탭에 머물렀다. 오류는 error prop 으로 넘기고 문구는 컴포넌트가 칸 아래 그린다.
 *
 * 시나리오 매니페스트: templates/_bundled/sirsoft-admin_basic/tests/scenarios/multilingual-required-tab-reveal.yaml
 */
import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const LAYOUTS: Array<[string, string]> = [
    ['브랜드', '../../../layouts/admin/partials/admin_ecommerce_brand_index/_panel_form.json'],
    ['카테고리', '../../../layouts/admin/partials/admin_ecommerce_category_index/_panel_form.json'],
    ['배송정책', '../../../layouts/admin/partials/admin_ecommerce_shipping_policy_form/_partial_basic_info.json'],
];

/** 조건에 맞는 노드를 모읍니다. */
function collect(node: any, predicate: (n: any) => boolean, out: any[] = []): any[] {
    if (!node || typeof node !== 'object') return out;
    if (Array.isArray(node)) {
        node.forEach((n) => collect(n, predicate, out));
        return out;
    }
    if (predicate(node)) out.push(node);
    Object.values(node).forEach((v) => collect(v, predicate, out));
    return out;
}

describe('이커머스 이름 칸 — 필수 다국어 칸 오류 연결', () => {
    // @scenario case=layout_error_wired
    // @effects required_locale_tab_opens_on_error
    it.each(LAYOUTS)('%s', (_label, rel) => {
        const json = JSON.parse(fs.readFileSync(path.resolve(__dirname, rel), 'utf8'));
        const inputs = collect(json, (n) => n.name === 'MultilingualInput' && ['tabs', 'compact'].includes(n.props?.layout)
            && n.props?.required !== undefined && n.props?.required !== false);
        expect(inputs.length).toBeGreaterThan(0);
        for (const input of inputs) {
            expect(String(input.props.error ?? '').toLowerCase(), `${input.props.name} error`).toContain(`errors?.${input.props.name}?.[0]`);
        }
    });
});
