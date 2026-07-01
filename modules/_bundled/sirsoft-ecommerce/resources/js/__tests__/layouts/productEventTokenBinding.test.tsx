/**
 * @file productEventTokenBinding.test.tsx
 * @description A32 — 상품폼 {{$event}} 전수 정정 회귀 (구조 검증)
 *
 * 라벨 기간 date Input, shopping_integration Select 3종, save_template name/category/is_default 의
 * change 액션이 {{$event}} 단독이 아닌 $event.target.value / $event.target.checked 를 사용하는지 검증.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import otherInfo from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_other_info.json';
import shopping from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_shopping_integration.json';
import saveTemplate from '../../../layouts/admin/partials/admin_ecommerce_product_form/_modal_save_template.json';

const flat = (obj: unknown) => JSON.stringify(obj);

describe('A32 — 상품폼 {{$event}} 전수 정정', () => {
    it('어느 partial 에도 {{$event}} 단독 전달이 남아있지 않다', () => {
        for (const layout of [otherInfo, shopping, saveTemplate]) {
            const s = flat(layout);
            // $event.xxx 멤버 접근은 허용, {{$event}} 단독만 금지
            expect(s).not.toMatch(/\{\{\$event\}\}/);
        }
    });

    it('라벨 기간 date input 은 $event.target.value 를 사용', () => {
        const s = flat(otherInfo);
        // started_at / ended_at value 바인딩
        expect(s).toContain('"value":"{{$event.target.value}}"');
    });

    it('shopping_integration Select 3종은 $event.target.value 를 사용', () => {
        const s = flat(shopping);
        const count = (s.match(/\{\{\$event\.target\.value\}\}/g) || []).length;
        expect(count).toBeGreaterThanOrEqual(3);
    });

    it('save_template: name/category 는 value, is_default 는 checked 토큰', () => {
        const s = flat(saveTemplate);
        expect(s).toContain('"ui.saveTemplateData.name":"{{$event.target.value}}"');
        expect(s).toContain('"ui.saveTemplateData.category_id":"{{$event.target.value}}"');
        // Checkbox 는 boolean → target.checked
        expect(s).toContain('"ui.saveTemplateData.is_default":"{{$event.target.checked}}"');
    });
});
