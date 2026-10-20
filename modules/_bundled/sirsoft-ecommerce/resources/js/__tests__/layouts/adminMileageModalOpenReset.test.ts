/**
 * @file adminMileageModalOpenReset.test.ts
 * @description 마일리지 수동 지급/회수·만료일 연장 창을 다시 열 때 지난 실패의 오류 표시가 남지 않는다 (7.1.1)
 *
 * 두 창은 입력값을 `_global.mileageManual`·`_global.mileageExtend` 객체로 열지만 `errors`·`isSaving` 은 그 객체에 없어,
 * setState 기본(deep) 병합으로 지난 제출 실패의 오류 배너·칸 오류가 취소 뒤 다시 연 창에 남았다. 여는 쪽이 같은 액션 목록에서
 * 그 상태를 null 로 먼저 비운다.
 *
 * @scenario-manifest tests/scenarios/admin-mileage-modal-open-reset.yaml
 */

import { describe, it, expect } from 'vitest';
import mileageIndex from '../../../layouts/admin/admin_ecommerce_mileage_transaction_index.json';

type Action = { handler?: string; target?: string; params?: Record<string, unknown> };

/**
 * 창(modal)을 여는 액션 목록마다, 상태 key 를 객체로 쓰는 setState 앞에 같은 대상·같은 키를 null 로 쓰는 setState 가 있는지 모은다.
 *
 * @param layout 굽기 산출 JSON
 * @param modal 여는 창 id
 * @param key 상태 키
 * @returns 여는 목록마다 null 선행 여부
 */
function openersOf(layout: unknown, modal: string, key: string): boolean[] {
    const out: boolean[] = [];
    (function visit(o: unknown): void {
        if (Array.isArray(o)) {
            const list = o as Action[];
            if (list.some((a) => a?.handler === 'openModal' && a.target === modal)) {
                const at = list.findIndex((a) => a?.handler === 'setState' && !!a.params?.[key] && typeof a.params[key] === 'object');
                if (at >= 0) {
                    const target = list[at].params!.target;
                    out.push(list.slice(0, at).some((a) => a?.handler === 'setState' && a.params?.[key] === null && a.params?.target === target));
                }
            }
            o.forEach(visit);
        } else if (o && typeof o === 'object') {
            Object.values(o).forEach(visit);
        }
    })(layout);

    return out;
}

describe('마일리지 창 — 여는 쪽이 상태를 null 로 먼저 비운다 (7.1.1)', () => {
    // @scenario modal=manual_transaction
    // @effects mileage_modal_state_reset_on_open
    it('수동 지급/회수 창', () => {
        expect(openersOf(mileageIndex, 'modal_manual_transaction', 'mileageManual')).toEqual([true]);
    });

    // @scenario modal=extend_expiry
    // @effects mileage_modal_state_reset_on_open
    it('만료일 연장 창', () => {
        expect(openersOf(mileageIndex, 'modal_extend_expiry', 'mileageExtend')).toEqual([true]);
    });
});
