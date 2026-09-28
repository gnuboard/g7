/**
 * DiagnosticEngine — spa-local-leak 감지기 오탐 (#599 validate P7)
 *
 * 종전 감지기는 「navigate 뒤에 visibleColumns 를 `$args[0]` 로 되쓰는 setState」 만 보고 발화했다.
 * 그런데 컬럼 선택기가 있는 목록 화면은 **정상 진입에서도** 마운트 콜백이 그 setState 를 낸다 —
 * 목록 화면을 오갈 때마다 누수로 진단됐다. 누수의 실제 증거는 **현재 레이아웃이 알지 못하는 `_local`
 * 키가 남아 있는 것**이다.
 */

import { describe, it, expect, vi } from 'vitest';

vi.mock('../G7DevToolsCore', () => ({
    G7DevToolsCore: { getInstance: () => ({}) },
}));

import { DiagnosticEngine } from '../DiagnosticEngine';

const rule = new DiagnosticEngine().getAllRules().find((r) => r.id === 'spa-local-leak')!;

/** 목록 이동 뒤 컬럼 선택기 마운트 콜백이 이어지는 액션 이력 */
const actionHistory = [
    { type: 'navigate', params: { path: '/admin/ecommerce/coupons' } },
    { type: 'setState', params: { target: 'local', visibleColumns: '{{$args[0]}}' } },
] as any[];

/** 쿠폰 목록 레이아웃 — 자기 state 와 바인딩만 안다 */
const couponLayout = {
    layout_name: 'admin_ecommerce_coupon_list',
    state: { visibleColumns: [], filter: {} },
    components: [{ name: 'DataGrid', props: { rows: '{{_local.filter?.keyword}}' } }],
};

function context(local: Record<string, unknown>, layout: unknown) {
    return {
        actionHistory,
        stateHistory: [],
        currentState: { _global: {}, _local: local },
        currentLayoutJson: layout,
    } as any;
}

describe('spa-local-leak 감지기', () => {
    /**
     * 현재 레이아웃이 아는 키만 남아 있으면(정상 진입) 발화하지 않는다.
     *
     * @scenario case=declared_only
     * @effects spa_local_leak_ignores_normal_list_entry
     */
    it('현재 레이아웃 키만 있으면 발화하지 않는다', () => {
        expect(rule.detector(context({ visibleColumns: ['code'], filter: {} }, couponLayout))).toBe(false);
    });

    /**
     * 이전 화면 전용 키(주문 목록의 bulkCourier 등)가 남아 있으면 발화한다.
     *
     * @scenario case=stale_from_previous_page
     * @effects spa_local_leak_fires_on_keys_outside_current_layout
     */
    it('현재 레이아웃 밖 키가 남아 있으면 발화한다', () => {
        const local = { visibleColumns: ['ordered_at'], filter: {}, bulkCourier: 'cj', ordererSearchResults: [] };

        expect(rule.detector(context(local, couponLayout))).toBe(true);
    });

    /**
     * 현재 레이아웃을 모르면 대조할 수 없으므로 발화하지 않는다(추정 진단 금지).
     *
     * @scenario case=layout_unknown
     * @effects spa_local_leak_ignores_normal_list_entry
     */
    it('현재 레이아웃이 없으면 발화하지 않는다', () => {
        expect(rule.detector(context({ bulkCourier: 'cj' }, null))).toBe(false);
    });
});
