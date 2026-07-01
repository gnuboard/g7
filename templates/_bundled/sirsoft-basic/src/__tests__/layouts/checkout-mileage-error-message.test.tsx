/**
 * @file checkout-mileage-error-message.test.tsx
 * @description 마일리지 적용 실패 토스트 메시지 바인딩 검증 (U15 후속)
 *
 * 배경: 보유 마일리지 초과 사용 시 백엔드는 422 + errors.{code,message}(구체 안내)를
 * 내려보내는데, 프론트 onError 토스트가 상단 generic message(error.message)만 노출해
 * "주문서 수정에 실패했습니다"만 보이던 결함. error.errors?.message 우선 fallback 으로 정정.
 */

import { describe, it, expect } from 'vitest';
import mileagePartial from '../../../layouts/partials/shop/_checkout_mileage.json';

/**
 * JSON 트리에서 조건을 만족하는 첫 노드를 재귀 탐색한다.
 */
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

describe('_checkout_mileage.json - 적용 실패 토스트 메시지 (U15)', () => {
    // 마일리지 적용 apiCall(PUT /checkout) 노드
    // (method 는 params.method, target 은 액션 top-level)
    const applyAction = findNode(
        mileagePartial,
        (n) =>
            n.handler === 'apiCall' &&
            n.params?.method === 'PUT' &&
            typeof n.target === 'string' &&
            n.target.includes('/checkout')
    );

    it('마일리지 적용 apiCall(PUT /checkout)이 존재한다', () => {
        expect(applyAction, '마일리지 적용 apiCall 이 없다').toBeTruthy();
    });

    it('onError 토스트가 error.errors?.message 를 우선 사용한다', () => {
        const onError = applyAction.onError ?? [];
        const toast = (Array.isArray(onError) ? onError : [onError]).find(
            (a: any) => a.handler === 'toast'
        );

        expect(toast, 'onError 토스트가 없다').toBeTruthy();
        // 구체 안내(errors.message) 우선, 없으면 상단 generic(message) fallback
        expect(toast.params.message).toContain('error.errors?.message');
        expect(toast.params.message).toContain('error.message');
    });

    it('onError 토스트가 generic message 만 단독 참조하지 않는다 (회귀 가드)', () => {
        const onError = applyAction.onError ?? [];
        const toast = (Array.isArray(onError) ? onError : [onError]).find(
            (a: any) => a.handler === 'toast'
        );

        // "{{error.message}}" 단독이면 구체 안내가 묻힘 → 금지
        expect(toast.params.message).not.toBe('{{error.message}}');
    });
});
