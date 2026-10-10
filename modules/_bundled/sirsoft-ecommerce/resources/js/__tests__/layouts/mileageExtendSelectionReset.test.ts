/**
 * @file mileageExtendSelectionReset.test.ts
 * @description 마일리지 내역 「유효기간 연장」 성공 뒤 표의 선택이 풀리는지 회귀
 *
 * 연장이 성공하면 창은 닫히고 목록은 새로 받지만, 표에서 체크한 적립건(`_local.selectedItems`)은
 * 그대로 남았다 — 체크가 켜진 채 [유효기간 연장] 이 다시 눌리는 상태라, 한 번 더 누르면 같은 적립건이
 * 또 연장된다(2026-10-07 실측: 적립건 3건 30일 연장 뒤 세 행 체크·버튼 활성 유지). 창의 성공 갈래가
 * 창 바깥(목록 화면)의 선택을 `$parent._local` 로 비우는지 확인한다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const MODAL = path.resolve(
    __dirname,
    '../../../layouts/admin/partials/admin_ecommerce_mileage_transaction_index/_modal_extend_expiry.json'
);

/**
 * 트리를 순회하며 조건에 맞는 노드를 모읍니다.
 *
 * @param node 시작 노드
 * @param predicate 수집 조건
 * @return 일치 노드 목록
 */
function collectNodes(node: any, predicate: (n: any) => boolean): any[] {
    const found: any[] = [];
    const walk = (current: any): void => {
        if (Array.isArray(current)) {
            current.forEach(walk);

            return;
        }
        if (current && typeof current === 'object') {
            if (predicate(current)) {
                found.push(current);
            }
            Object.values(current).forEach(walk);
        }
    };
    walk(node);

    return found;
}

describe('마일리지 유효기간 연장 성공 뒤 선택 해제', () => {
    const layout = JSON.parse(fs.readFileSync(MODAL, 'utf8'));
    const extendCalls = collectNodes(
        layout,
        (n) => n?.handler === 'apiCall' && String(n?.target ?? n?.params?.target ?? '').includes('extend-expiry')
    );

    /**
     * @scenario case=c01
     * @effects mileage_extend_selection_reset
     */
    it('연장 요청 액션이 하나 있다', () => {
        expect(extendCalls.length).toBe(1);
    });

    /**
     * @scenario case=c02
     * @effects mileage_extend_selection_reset
     */
    it('성공 갈래가 목록 화면의 선택(selectedItems)을 비운다', () => {
        const onSuccess = extendCalls[0].onSuccess ?? [];
        const resets = collectNodes(
            onSuccess,
            (n) => n?.handler === 'setState'
                && n?.params?.target === '$parent._local'
                && Array.isArray(n?.params?.selectedItems)
                && n.params.selectedItems.length === 0
        );
        expect(resets.length).toBe(1);
    });

    /**
     * @scenario case=c03
     * @effects mileage_extend_selection_reset
     */
    it('선택 해제는 창을 닫기 전에 한다(닫힌 뒤에는 $parent 문맥이 없어 쓰기가 버려진다)', () => {
        const onSuccess: any[] = extendCalls[0].onSuccess ?? [];
        const resetIdx = onSuccess.findIndex((n) => n?.handler === 'setState' && n?.params?.target === '$parent._local');
        const closeIdx = onSuccess.findIndex((n) => n?.handler === 'closeModal');
        expect(resetIdx).toBeGreaterThanOrEqual(0);
        expect(closeIdx).toBeGreaterThan(resetIdx);
    });

    /**
     * 창의 `$parent._local` 쓰기는 `_global._local` 미러에서 빠진다(docs/frontend/modal-usage.md 「부모 폼 커밋」).
     * 화면 쪽만 비우면 액션이 읽는 저장소에 옛 선택이 남으므로, 같은 값을 전역 `_local` 에도 쓴다.
     *
     * @scenario case=c05
     * @effects mileage_extend_selection_reset
     */
    it('성공 갈래는 저장소 양쪽(global _local·$parent._local)의 선택을 함께 비운다', () => {
        const onSuccess: any[] = extendCalls[0].onSuccess ?? [];
        const globalIdx = onSuccess.findIndex((n) => n?.handler === 'setState' && n?.params?.target === 'global'
            && Array.isArray(n?.params?.['_local.selectedItems']) && n.params['_local.selectedItems'].length === 0);
        const closeIdx = onSuccess.findIndex((n) => n?.handler === 'closeModal');
        expect(globalIdx).toBeGreaterThanOrEqual(0);
        expect(closeIdx).toBeGreaterThan(globalIdx);
    });

    /**
     * @scenario case=c04
     * @effects mileage_extend_selection_reset
     */
    it('실패 갈래는 선택을 건드리지 않는다(창 유지, 고쳐서 다시 시도)', () => {
        const onError = extendCalls[0].onError ?? [];
        const touches = collectNodes(onError, (n) => n?.handler === 'setState' && n?.params?.target === '$parent._local');
        expect(touches.length).toBe(0);
    });
});
