/**
 * 환불 실행 확인 창 — 금액을 상점 통화 표기로 보인다 (S3-D1, 2026-10-07 실측)
 *
 * @description
 * 창의 현금 환불액·사용 마일리지 반환액·최종 환불 합계가 원시 숫자에 통화 코드를 이어 붙인
 * `32500 KRW` 로 나왔다. 같은 신청서 상세의 「현금 환불액」·「환불 합계」는 `32,500원` 이라,
 * 되돌릴 수 없는 실행 직전에 운영자가 대조하는 값만 자릿수 구분 없이 다른 꼴로 보였다.
 * 여는 핸들러가 세 금액의 표기 문자열을 문맥에 싣고, 창은 그 문자열을 그린다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

import { openClaimRefundExecuteHandler } from '../../handlers/claimHandlers';
import refundModal from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_modal_claim_refund_execute.json';

let mockGlobalState: Record<string, any> = {};

const mockG7Core = {
    state: {
        get: () => mockGlobalState,
        getLocal: () => ({}),
        set: vi.fn((updates: Record<string, any>) => {
            mockGlobalState = { ...mockGlobalState, ...updates };
        }),
        setLocal: vi.fn(),
    },
    toast: { success: vi.fn(), warning: vi.fn(), error: vi.fn(), info: vi.fn() },
    modal: { open: vi.fn(), close: vi.fn() },
    t: vi.fn((key: string) => key),
    api: { post: vi.fn(), patch: vi.fn(), put: vi.fn() },
    dispatch: vi.fn(),
    createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
};

const CURRENCIES = [
    { code: 'KRW', symbol: '원', decimal_places: 0, is_default: true },
    { code: 'USD', symbol: '$', decimal_places: 2, is_default: false },
];

beforeEach(() => {
    mockGlobalState = { modules: { 'sirsoft-ecommerce': { language_currency: { currencies: CURRENCIES } } } };
    vi.clearAllMocks();
    (window as any).G7Core = mockG7Core;
});

/**
 * JSON 트리의 모든 노드를 평탄화한다.
 *
 * @param node 시작 노드
 * @param acc 누적 배열
 * @returns 노드 목록
 */
function flatten(node: any, acc: any[] = []): any[] {
    if (!node || typeof node !== 'object') return acc;
    acc.push(node);
    for (const value of Object.values(node)) {
        if (Array.isArray(value)) value.forEach((child) => flatten(child, acc));
        else if (value && typeof value === 'object') flatten(value, acc);
    }

    return acc;
}

/**
 * data-ref 노드의 text 표현식을 `_global` 문맥으로 평가한다.
 *
 * @param ref 노드 data-ref(JSON id)
 * @param global `_global` 상태
 * @returns 화면에 그려질 문자열
 */
function renderText(ref: string, global: any): string {
    const node = flatten(refundModal).find((n) => n?.id === ref);
    expect(node, `${ref} 노드`).toBeTruthy();
    const body = String(node.text).replace(/^\{\{/, '').replace(/\}\}$/, '');
    // eslint-disable-next-line no-new-func
    return String(new Function('_global', `return (${body});`)(global));
}

/**
 * 창을 연다.
 *
 * @param overrides 핸들러 인자 덮어쓰기
 * @returns void
 */
async function open(overrides: Record<string, any> = {}): Promise<void> {
    await openClaimRefundExecuteHandler({
        handler: 'openClaimRefundExecute',
        params: {
            pathSegment: 'refunds',
            claimId: 38,
            claimNumber: 'RF20261006-1511455492',
            currencyCode: 'KRW',
            refundAmount: '32500',
            refundPointsAmount: '1500',
            refundMethod: 'bank',
            refundMethods: [{ value: 'bank', label: '계좌이체 환불' }],
            ...overrides,
        },
    } as any);
}

describe('환불 실행 확인 창 금액 표기', () => {
    /**
     * @scenario currency=krw
     *
     * @effects refund_execute_amounts_are_currency_formatted
     */
    it('현금 환불액·마일리지 반환액·합계를 원화 표기로 그린다', async () => {
        await open();

        expect(renderText('crm_002_pg_amount', mockGlobalState)).toBe('32,500원');
        expect(renderText('crm_003_points_amount', mockGlobalState)).toBe('1,500원');
        expect(renderText('crm_005_total', mockGlobalState)).toBe('34,000원');
    });

    /**
     * @scenario currency=decimal
     *
     * @effects refund_execute_amounts_are_currency_formatted
     */
    it('소수 통화는 그 통화의 자릿수와 기호를 따른다', async () => {
        await open({ currencyCode: 'USD', refundAmount: '12.5', refundPointsAmount: '0.1' });

        expect(renderText('crm_002_pg_amount', mockGlobalState)).toBe('$12.50');
        expect(renderText('crm_005_total', mockGlobalState)).toBe('$12.60');
    });
});
