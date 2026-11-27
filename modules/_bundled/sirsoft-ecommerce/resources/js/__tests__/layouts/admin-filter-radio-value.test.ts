/**
 * @file admin-filter-radio-value.test.ts
 * @description 이커머스 목록 필터 — 이름 있는 라디오의 value 가 폼 자동 바인딩으로 덮이지 않는다 (P9, 트러블슈팅 form 사례 4)
 *
 * 카드에 `data-key` 가 있으면 엔진은 그 아래 `name` 있는 입력을 자동 바인딩한다. 상태값이 boolean 이 아닌 라디오는
 * value 가 **현재 상태값으로 덮여** 같은 이름의 라디오 전부가 같은 value 를 갖는다(스케줄 필터 D3 실측 — 5개 모두 "all").
 * 화면은 라벨·checked 식으로 동작해 보여도 DOM 의 value 계약(자동화·보조기술·폼 직렬화)이 깨진다.
 * 그래서 라디오는 `:autoBinding="false"` 로 끄고 value·checked·change 를 스스로 갖는다.
 *
 * 시나리오 매니페스트: modules/_bundled/sirsoft-ecommerce/tests/scenarios/filter-radio-value-binding.yaml
 */
import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const LAYOUTS: Array<[string, string]> = [
    ['상품 목록 필터', '../../../layouts/admin/partials/admin_ecommerce_product_list/_partial_filter_section.json'],
    ['쿠폰 목록 필터', '../../../layouts/admin/partials/admin_ecommerce_promotion_coupon_list/_partial_filter_section.json'],
    ['배송지 목록 필터', '../../../layouts/admin/partials/admin_ecommerce_user_address_index/_partial_filter_section.json'],
];

/** dataKey 컨테이너 아래의 이름 있는 라디오를 모읍니다. */
function boundRadios(node: any, insideDataKey = false, out: any[] = []): any[] {
    if (!node || typeof node !== 'object') return out;
    if (Array.isArray(node)) {
        node.forEach((n) => boundRadios(n, insideDataKey, out));
        return out;
    }
    const here = insideDataKey || typeof node.dataKey === 'string';
    if (here && node.name === 'Input' && node.props?.type === 'radio' && node.props?.name) out.push(node);
    Object.values(node).forEach((v) => boundRadios(v, here, out));
    return out;
}

describe('이커머스 목록 필터 — 라디오 value 계약', () => {
    // @scenario case=ecommerce_list_filters
    // @effects radio_value_not_overwritten_by_auto_binding
    it.each(LAYOUTS)('%s', (_label, rel) => {
        const json = JSON.parse(fs.readFileSync(path.resolve(__dirname, rel), 'utf8'));
        const radios = boundRadios(json);
        expect(radios.length, '이름 있는 라디오').toBeGreaterThan(0);
        const byName: Record<string, string[]> = {};
        for (const r of radios) {
            const id = `${r.props.name}=${r.props.value}`;
            expect(r.props.autoBinding, `${id} autoBinding`).toBe(false);
            expect(typeof r.props.value === 'string' && r.props.value !== '', `${id} value`).toBe(true);
            expect(String(r.props.checked ?? ''), `${id} checked 식`).toContain(`'${r.props.value}'`);
            const change = (r.actions ?? []).find((a: any) => a.type === 'change');
            expect(change, `${id} change 액션(키보드 방향키 선택)`).toBeDefined();
            (byName[r.props.name] ??= []).push(r.props.value);
        }
        for (const [name, values] of Object.entries(byName)) {
            expect(new Set(values).size, `${name} value 가 서로 달라야 한다`).toBe(values.length);
        }
    });
});
