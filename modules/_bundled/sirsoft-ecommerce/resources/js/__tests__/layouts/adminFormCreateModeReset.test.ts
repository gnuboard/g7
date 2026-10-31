/**
 * @file adminFormCreateModeReset.test.ts
 * @description 같은 레이아웃 재사용(수정 → 등록) 시 등록 폼에 직전 항목의 form 이 남지 않도록
 * 생성 모드 init_actions 가 form 을 null 로 먼저 비우는지 검증한다 (레이아웃 JSON 구조 검증).
 *
 * 배경(브라우저 실측 2026-09-14, #599): 엔진은 layout_name 이 바뀔 때만 `_local` 을 비운다.
 * 상품 수정 → 상품 등록, 배송정책 수정 → 배송정책 등록은 같은 레이아웃이라 `_local.form` 이 그대로
 * 남고, init_actions 의 기본값 setState 는 깊은 병합이라 직전 상품의 product_code·이름, 직전
 * 배송정책의 id·이름이 등록 화면에 살아남았다. sirsoft-page 의 admin_page_form 선례처럼
 * `form: null` 액션을 기본값보다 앞에 둔다(깊은 병합이 객체 대신 null 로 덮어써 완전 삭제).
 * 복사 진입(copy_id)은 copy_source 데이터소스의 initLocal 이 init_actions 뒤에 병합되므로
 * 이 액션의 영향을 받지 않는다(TemplateApp: "localInit 가 우선").
 *
 * 시나리오: tests/scenarios/admin-form-create-mode-reset.yaml
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import { initStateOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import productForm from '../../../layouts/admin/admin_ecommerce_product_form.json';
import shippingPolicyForm from '../../../layouts/admin/admin_ecommerce_shipping_policy_form.json';

type InitAction = { handler?: string; if?: string; params?: Record<string, any> };

function initActions(layout: any): InitAction[] {
    return (layout.init_actions ?? layout.initActions ?? []) as InitAction[];
}

/** 생성 모드 조건으로 `form: null` 을 쓰는 setState(target: local) 액션의 위치 */
function findFormNullReset(actions: InitAction[], createCondition: string): number {
    return actions.findIndex(
        (a) =>
            a.handler === 'setState' &&
            a.if === createCondition &&
            a.params?.target === 'local' &&
            Object.prototype.hasOwnProperty.call(a.params ?? {}, 'form') &&
            a.params?.form === null
    );
}

describe('[case:state-global-same-layout-create-form-residue] 같은 레이아웃 수정 → 등록 전환의 등록 폼 잔존 제거 (troubleshooting-state-global 사례 7)', () => {
describe('admin_ecommerce_product_form.json — 생성 모드 form 잔존 제거', () => {
    const actions = initActions(productForm);
    const CREATE = '{{!route.itemCode}}';

    /**
     * @scenario form=product
     * @effects create_mode_clears_previous_form_before_defaults
     */
    it('생성 모드(!route.itemCode)에서 form 을 null 로 비우는 init 액션이 있다', () => {
        expect(findFormNullReset(actions, CREATE)).toBeGreaterThanOrEqual(0);
    });

    /**
     * @scenario form=product
     * @effects defaults_follow_clear
     */
    it('form=null 액션이 기본값 setState(form 객체)보다 앞에 온다 — 뒤에 오면 기본값을 지운다', () => {
        const resetIdx = findFormNullReset(actions, CREATE);
        const defaultsIdx = actions.findIndex(
            (a) => a.handler === 'setState' && a.params?.form && typeof a.params.form === 'object'
        );
        expect(defaultsIdx).toBeGreaterThanOrEqual(0);
        expect(resetIdx).toBeLessThan(defaultsIdx);
    });

    /**
     * @scenario form=product
     * @effects edit_mode_untouched
     */
    it('form=null 액션은 수정 모드를 건드리지 않도록 생성 조건으로만 발동한다', () => {
        const reset = actions[findFormNullReset(actions, CREATE)];
        expect(reset.if).toBe(CREATE);
    });


    /**
     * @scenario form=product
     * @effects copy_source_payload_not_merged_with_previous_form
     */
    it('복사 진입(copy_id)의 copy_source initLocal 은 replace 맵 형식 — 문자열 initLocal 은 엔진이 기존 form 과 깊은 병합해 수정 화면의 잔존(id 등)을 복사 페이로드에 섞는다', () => {
        const copySource = (productForm as any).data_sources.find((d: any) => d.id === 'copy_source');
        expect(copySource).toBeDefined();
        expect(copySource.initLocal).toEqual({ _merge: 'replace', form: '{{data}}' });
        expect(copySource.if).toContain('copy_id');
    });

    /**
     * @scenario form=product
     * @effects edit_mode_untouched
     */
    it('수정 모드의 product 데이터소스는 그대로(initLocal: form) 유지된다', () => {
        const product = (productForm as any).data_sources.find((d: any) => d.id === 'product');
        expect(product.initLocal).toBe('form');
    });
});

describe('admin_ecommerce_shipping_policy_form.json — 생성 모드 form 잔존 제거', () => {
    const actions = initActions(shippingPolicyForm);
    const CREATE = '{{!route.id}}';

    /**
     * @scenario form=shipping_policy
     * @effects create_mode_clears_previous_form_before_defaults
     */
    it('생성 모드(!route.id)에서 form 을 null 로 비우는 init 액션이 있다', () => {
        expect(findFormNullReset(actions, CREATE)).toBeGreaterThanOrEqual(0);
    });

    /**
     * @scenario form=shipping_policy
     * @effects defaults_follow_clear
     */
    it('form=null 뒤에 레이아웃 state.form 과 같은 기본값을 다시 쓰고 직전 화면의 필드 오류를 지운다', () => {
        const resetIdx = findFormNullReset(actions, CREATE);
        const defaults = actions.slice(resetIdx + 1).find(
            (a) => a.handler === 'setState' && a.if === CREATE && a.params?.target === 'local' && a.params?.form
        );
        expect(defaults).toBeDefined();
        expect(defaults!.params!.form).toEqual(initStateOf(shippingPolicyForm as any).form);
        expect(defaults!.params!.errors).toBeNull();
    });

    /**
     * @scenario form=shipping_policy
     * @effects clear_precedes_country_settings_handler
     */
    it('form=null·기본값 액션이 initShippingPolicyForm(복사 모드에서 form.country_settings 기록)보다 앞에 온다', () => {
        const resetIdx = findFormNullReset(actions, CREATE);
        const handlerIdx = actions.findIndex((a) => a.handler === 'sirsoft-ecommerce.initShippingPolicyForm');
        expect(handlerIdx).toBeGreaterThan(resetIdx + 1);
    });

});
});
