/**
 * @file checkout-default-address-prefill.test.tsx
 * @description 주문서 배송지 칸의 «기본 채움» 과 «저장된 배송지 갱신» 계약 (2026-09-21 요구사항).
 *
 * 두 가지를 고정한다:
 *  1. 저장된 배송지가 있으면 첫 줄(= 기본 배송지)로 배송지 칸이 채워져 열린다. 옮겨 담는 모양은
 *     칩을 누른 경로와 같아야 한다 — 해외 주소는 도시·주·우편번호가 `intl_*` 로 갈리므로 한 칸씩
 *     옮기지 않으면 국내 주소만 맞고 해외 주소는 빈 칸으로 열린다.
 *     채운 뒤 주문서를 다시 받는 것은 배송비 때문이다. 우편번호가 정해졌는데 미리보기가 그것을
 *     모르면, 주문 확정에서 도서산간 할증이 붙어 결제예정금액과 어긋난다.
 *  2. 고른 배송지를 그 자리에서 «고쳤을 때만» 갱신 여부를 묻고, 그 답이 주문 본문에 실린다.
 *     고치지도 않았는데 뜨는 칸은 무엇을 묻는지 알 수 없어 칸 자체를 세우지 않는다.
 */

import { describe, it, expect } from 'vitest';
import checkoutLayout from '../../../layouts/shop/checkout.json';
import shippingPartial from '../../../layouts/partials/shop/_checkout_shipping.json';
import summaryPartial from '../../../layouts/partials/shop/_checkout_summary.json';

/** JSON 트리에서 조건을 만족하는 첫 노드를 재귀 탐색한다. */
function findNode(obj: any, predicate: (node: any) => boolean): any | null {
    if (!obj || typeof obj !== 'object') {
        return null;
    }
    if (predicate(obj)) {
        return obj;
    }
    for (const value of Object.values(obj)) {
        if (Array.isArray(value)) {
            for (const item of value) {
                const found = findNode(item, predicate);
                if (found) {
                    return found;
                }
            }
        } else if (value && typeof value === 'object') {
            const found = findNode(value, predicate);
            if (found) {
                return found;
            }
        }
    }
    return null;
}

/** 액션 트리를 평탄화한다 — sequence 는 자식 액션을 접어 둔다. */
function flattenActions(node: unknown): any[] {
    const out: any[] = [];
    const visit = (value: any): void => {
        if (!value || typeof value !== 'object') return;
        if (Array.isArray(value)) {
            value.forEach(visit);
            return;
        }
        if (typeof value.handler === 'string') out.push(value);
        for (const child of Object.values(value)) visit(child);
    };
    visit(node);
    return out;
}

const addressSource = (checkoutLayout as any).data_sources.find((d: any) => d.id === 'userAddresses');
const prefill = ([] as any[]).concat(addressSource.onSuccess ?? [])[0];
const prefillActions = flattenActions(prefill);

describe('shop/checkout.json — 기본 배송지 채움', () => {
    // @scenario stage=prefill
    // @effects checkout_prefills_only_when_saved_address_exists
    it('저장된 배송지가 있을 때만 채운다', () => {
        expect(prefill.handler).toBe('sequence');
        expect(prefill.if).toMatch(/addresses\?\.data \?\? \[\]\)\.length > 0/);
    });

    // @scenario stage=prefill
    // @effects checkout_prefill_maps_intl_address_fields
    it('첫 줄(기본 배송지)을 주문서 형식으로 한 칸씩 옮겨 담는다', () => {
        const shipping = prefillActions
            .map((a: any) => a.params?.shipping)
            .find((v: any) => !!v);

        // 저장 형식(UserAddress) ↔ 주문서 형식(shipping)의 이름이 다른 세 칸이 요점이다.
        expect(shipping.intl_city).toMatch(/\.city/);
        expect(shipping.intl_state).toMatch(/\.state/);
        expect(shipping.intl_postal_code).toMatch(/\.postal_code/);
        expect(shipping.recipient_name).toMatch(/addresses\.data\)\[0\]\.recipient_name/);
    });

    // @scenario stage=prefill
    // @effects checkout_prefill_refetches_order_for_shipping_fee
    it('고른 배송지를 손에 들고, 배송비 기준(우편번호·국가)을 세운 뒤 주문서를 다시 받는다', () => {
        const ids = prefillActions.find((a: any) => a.params?.selectedAddressId !== undefined);

        expect(ids.params.selectedAddressObj).toMatch(/addresses\.data\)\[0\]/);

        const fee = prefillActions.find((a: any) => a.params?.['checkout.zipcode'] !== undefined);

        expect(fee.params['checkout.country_code']).toMatch(/country_code/);

        // refetch 는 «현재» 로컬 상태를 읽으므로 setState 보다 뒤에 있어야 한다.
        const order = prefillActions.map((a: any) => a.handler);

        expect(order.indexOf('refetchDataSource')).toBeGreaterThan(order.indexOf('setState'));
        expect(prefillActions.find((a: any) => a.handler === 'refetchDataSource').params.dataSourceId)
            .toBe('checkoutData');
    });

    // @scenario stage=prefill
    // @effects checkout_get_carries_zipcode_for_fee_preview
    it('주문서 조회가 우편번호를 함께 싣는다 — 미리보기와 확정 재계산이 갈라지지 않게', () => {
        const checkoutSource = (checkoutLayout as any).data_sources.find((d: any) => d.id === 'checkoutData');

        expect(checkoutSource.params.zipcode).toMatch(/_local\.checkout\?\.zipcode/);
    });
});

describe('주문서 — 저장된 배송지 갱신', () => {
    // @scenario stage=update
    // @effects checkout_update_prompt_only_when_address_changed
    it('고친 칸이 있을 때만 갱신 여부를 묻는다', () => {
        const dirty = (checkoutLayout as any).computed.shippingAddressDirty;

        expect(dirty).toMatch(/selectedAddressId/);
        // 이름이 갈리는 칸(intl_*)까지 비교에 들어가야 해외 주소를 고친 것이 «고침» 으로 잡힌다.
        expect(dirty).toMatch(/intl_postal_code/);

        const box = findNode(
            shippingPartial,
            (n: any) => n?.props?.id === 'update_selected_address'
        );

        expect(box).toBeTruthy();

        const row = findNode(
            shippingPartial,
            (n: any) => typeof n?.if === 'string' && n.if.includes('shippingAddressDirty')
        );

        expect(row).toBeTruthy();
    });

    // @scenario stage=update
    // @effects checkout_order_payload_carries_address_to_update
    it('갱신 대상 배송지 ID 가 주문 본문에 실린다', () => {
        const submit = findNode(
            summaryPartial,
            (n: any) => n?.handler === 'apiCall' && typeof n?.params?.body === 'string'
                && n.params.body.includes('temp_order_id')
        );

        expect(submit.params.body).toMatch(/save_shipping_address_id:/);
        // 고치지 않았거나 갱신을 껐으면 null — 그때는 주소록이 그대로 남는다.
        expect(submit.params.body).toMatch(/shippingAddressDirty && \(_local\.updateSelectedAddress \?\? true\)/);
    });
});
