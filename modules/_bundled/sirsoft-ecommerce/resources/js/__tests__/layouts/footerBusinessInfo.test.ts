/**
 * @file footerBusinessInfo.test.ts
 * @description 쇼핑몰 푸터 사업자 정보 조각(user_global_footer)을 잠근다 (#434).
 *
 * - 사용자 템플릿 공용 자리 `user_global_footer` 에 붙는다(어느 템플릿이든 그 자리만 있으면 같다)
 * - 쇼핑몰 설정 「푸터에 사업자 정보 표시」(기본 켜짐)가 꺼지거나 상호·사업자번호가 둘 다 비면 그리지 않는다
 * - 빈 항목은 줄을 만들지 않고, 사업자번호가 있으면 공정위 조회를 새 창(opener 차단)으로 연다
 * - 스타일은 모듈 앞글자(ecommerce:) 클래스만 쓴다
 *
 * @scenario case=fragment
 *
 * @effects footer_business_info_rendered_when_enabled
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';

const ROOT = (() => {
    let current = path.dirname(fileURLToPath(import.meta.url));
    while (!fs.existsSync(path.join(current, 'module.json'))) current = path.dirname(current);

    return current;
})();

type Node = Record<string, any>;
const nodes = (tree: unknown, out: Node[] = []): Node[] => {
    if (Array.isArray(tree)) tree.forEach((child) => nodes(child, out));
    else if (tree && typeof tree === 'object') {
        out.push(tree as Node);
        Object.values(tree as Node).forEach((value) => nodes(value, out));
    }

    return out;
};

const fragment = JSON.parse(fs.readFileSync(path.join(ROOT, 'resources/extensions/user-global-footer-business-info.json'), 'utf-8'));
const all = nodes(fragment);
const root = all.find((n) => n.id === 'ecommerceFooterBusinessInfo')!;
const evaluate = (binding: string, basicInfo: Record<string, unknown> | undefined) =>
    new Function('_global', `return (${binding.replace(/^\{\{|\}\}$/g, '')});`)({ modules: basicInfo ? { 'sirsoft-ecommerce': { basic_info: basicInfo } } : {} });

describe('푸터 사업자 정보 조각 (#434)', () => {
    // @scenario case=fragment
    // @effects footer_business_info_rendered_when_enabled
    it('사용자 템플릿 공용 자리 user_global_footer 에 붙는다', () => {
        expect(fragment.extension_point).toBe('user_global_footer');
    });

    // @scenario case=fragment
    // @effects footer_business_info_rendered_when_enabled
    it.each([
        [{ company_name: 'SIR', footer_business_info_enabled: true }, true],
        [{ business_number: '123-45-67890' }, true],
        [{ company_name: 'SIR', footer_business_info_enabled: false }, false],
        [{ company_name: '', business_number: '' }, false],
        [undefined, false],
    ])('기본정보 %j 이면 표시 %s', (basicInfo, shown) => {
        expect(evaluate(root.if, basicInfo as any)).toBe(shown);
    });

    // @scenario case=fragment
    // @effects footer_business_info_rendered_when_enabled
    it('항목마다 값이 있을 때만 줄을 만들고, 사업자번호 조회는 숫자만 넘겨 새 창으로 연다', () => {
        const company = all.find((n) => n.id === 'ecommerceFooterBiz_company')!;
        expect(evaluate(company.if, { company_name: '' })).toBe(false);
        expect(evaluate(company.if, { company_name: 'SIR' })).toBe(true);

        const verify = all.find((n) => n.id === 'ecommerceFooterBiz_verify')!;
        expect(verify.props.target).toBe('_blank');
        expect(verify.props.rel).toBe('noopener noreferrer');
        // 실제 바인딩 엔진으로 푼다 — 정규식 리터럴은 엔진이 풀지 못해 `{{…}}` 가 글자 그대로 나갔다(실측). 시험 안에서 식을 흉내 내지 않는다
        const href = dataBindingEngine.resolveBindings(String(verify.props.href), { _global: { modules: { 'sirsoft-ecommerce': { basic_info: { business_number: '123-45-67890' } } } } } as any);
        expect(href).toBe('https://www.ftc.go.kr/bizCommPop.do?wrkr_no=1234567890');
    });

    // @scenario case=fragment
    // @effects footer_business_info_rendered_when_enabled
    it('문구는 번역 키, 클래스는 모듈 앞글자(ecommerce:)만 쓴다', () => {
        const classes = all.map((n) => n.props?.className).filter((c): c is string => typeof c === 'string');
        expect(classes.length).toBeGreaterThan(5);
        classes.forEach((className) => className.split(/\s+/).filter(Boolean).forEach((token) => expect(token.startsWith('ecommerce:'), token).toBe(true)));
        expect(JSON.stringify(fragment)).toContain('$t:sirsoft-ecommerce.shop.footer_business_info.company');
    });
});
