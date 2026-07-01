/**
 * @file productCreateDefaults.test.tsx
 * @description 신규 등록 시 기본값 (구조 검증)
 *
 * 상품 "등록"(수정 아님) 화면의 기본값을 검증한다.
 *  1. 옵션 추가(addOptionRow / generateOptions / 재생성 확정) 액션이 등록/수정 구분을
 *     params.isCreate = {{!route.itemCode}} 로 핸들러에 전달한다 → 핸들러가 재고 기본값을 결정.
 *  2. init_actions 의 form 초기값에 과세=taxable, 판매상태=on_sale, 전시상태=visible 가 시드된다.
 *     수정 시에는 product 데이터소스(initLocal:form)가 deepMerge 우선으로 서버 값을 덮어쓴다
 *     (TemplateApp.ts: "localInit 가 우선 (API 데이터가 init_actions 기본값을 덮어씀)").
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import productForm from '../../../layouts/admin/admin_ecommerce_product_form.json';
import optionsPartial from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_product_options.json';
import regenerateModal from '../../../layouts/admin/partials/admin_ecommerce_product_form/_modal_confirm_regenerate.json';

const flat = (obj: unknown) => JSON.stringify(obj);

/**
 * init_actions 의 setState(target:local) form 초기값 객체를 추출한다.
 */
function getInitFormState(): Record<string, any> {
    const initActions = (productForm as any).init_actions ?? [];
    const setStateAction = initActions.find(
        (a: any) => a.handler === 'setState' && a.params?.form
    );
    return setStateAction?.params?.form ?? {};
}

describe('신규 등록 시 상태 필드 기본값', () => {
    it('init_actions form 초기값에 과세=taxable 가 시드됨', () => {
        expect(getInitFormState().tax_status).toBe('taxable');
    });

    it('init_actions form 초기값에 판매상태=on_sale 가 시드됨', () => {
        expect(getInitFormState().sales_status).toBe('on_sale');
    });

    it('init_actions form 초기값에 전시상태=visible 가 시드됨', () => {
        expect(getInitFormState().display_status).toBe('visible');
    });
});

describe('옵션 추가 액션이 등록 모드를 핸들러에 전달', () => {
    it('generateOptions 버튼이 isCreate={{!route.itemCode}} 를 전달', () => {
        const s = flat(optionsPartial);
        expect(s).toContain('"sirsoft-ecommerce.generateOptions"');
        expect(s).toContain('"isCreate":"{{!route.itemCode}}"');
    });

    it('addOptionRow 버튼이 isCreate={{!route.itemCode}} 를 전달', () => {
        const s = flat(optionsPartial);
        expect(s).toContain('"sirsoft-ecommerce.addOptionRow"');
        // addOptionRow / generateOptions 양쪽 모두 isCreate 표현식을 보유
        const occurrences = s.split('"isCreate":"{{!route.itemCode}}"').length - 1;
        expect(occurrences).toBe(2);
    });

    it('재생성 확정 모달이 skipConfirm 재호출 시에도 isCreate 를 보존', () => {
        const s = flat(regenerateModal);
        expect(s).toContain('"skipConfirm":true');
        expect(s).toContain('"isCreate":"{{!route.itemCode}}"');
    });
});
