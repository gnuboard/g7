/**
 * 통화 탭 저장 몸통의 관리 환율 제외 검증 (#703)
 *
 * 회귀 배경: 환율 제공자가 관리하는 통화의 환율 칸은 잠겨 있어 운영자가 바꿀 수 없는데, 저장 버튼이
 * 화면이 받아 둔 환율을 그대로 다시 보냈다. 화면을 연 사이 제공자가 환율을 갱신하면 저장값과 달라져
 * 통화 설정 저장 전체가 「관리 환율 변경」 422 로 막혔다. 저장 몸통은 저장돼 있는 관리 통화의 환율을
 * 빼고 보내고(서버가 저장값으로 채운다), 새로 추가하는 통화·비관리 통화의 환율은 그대로 보낸다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import mainLayout from '../../../layouts/admin/admin_ecommerce_settings.json';
import { asArray, callbacksOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

/**
 * 트리에서 조건을 만족하는 첫 노드를 찾는다.
 *
 * @param node 탐색 시작 노드
 * @param predicate 판정 함수
 * @returns 찾은 노드 또는 null
 */
function findFirst(node: any, predicate: (n: any) => boolean): any {
    if (!node || typeof node !== 'object') return null;
    if (predicate(node)) return node;
    for (const value of Object.values(node)) {
        const found = Array.isArray(value)
            ? value.map((v) => findFirst(v, predicate)).find(Boolean)
            : findFirst(value, predicate);
        if (found) return found;
    }
    return null;
}

/**
 * 저장 버튼 apiCall 의 body 표현식을 주어진 컨텍스트로 평가한다.
 *
 * @param ctx 표현식이 읽는 컨텍스트 값
 * @returns 평가된 요청 몸통
 */
function evaluateSaveBody(ctx: Record<string, any>): any {
    const saveButton = findFirst((mainLayout as any).slots.content[0], (n) => n.id === 'save_button');
    const click = asArray(saveButton?.actions).find((a: any) => a.type === 'click');
    const apiCall = findFirst(callbacksOf(click, 'actions').length ? callbacksOf(click, 'actions') : click, (n) => n.handler === 'apiCall');
    const raw = String(apiCall.params.body).trim();
    const expr = raw.replace(/^\{\{/, '').replace(/\}\}$/, '');
    // eslint-disable-next-line no-new-func
    return new Function('_global', 'query', '_local', '_computed', 'settings', `return ${expr};`)(
        ctx._global, ctx.query, ctx._local, ctx._computed, ctx.settings
    );
}

describe('통화 탭 저장 몸통 — 관리 환율 제외 (#703)', () => {
    const ctx = {
        _global: { activeEcommerceSettingsTab: 'language_currency' },
        query: {},
        _computed: { managedRateCodes: ['USD'] },
        settings: { data: { language_currency: { currencies: [{ code: 'KRW' }, { code: 'USD' }, { code: 'JPY' }] } } },
        _local: {
            form: {
                language_currency: {
                    default_currency: 'KRW',
                    currencies: [
                        { code: 'KRW', exchange_rate: 1, is_default: true },
                        { code: 'USD', exchange_rate: 0.00074, decimal_places: 2 },
                        { code: 'JPY', exchange_rate: 0.11 },
                        { code: 'AUD', exchange_rate: 0.0011 },
                    ],
                },
                inquiry: {},
            },
        },
    };

    /**
     * @scenario managed_rate_param=omitted, provider_refreshed=yes
     *
     * @effects omitted_managed_rate_keeps_saved_value
     */
    it('저장돼 있는 관리 통화(USD)의 환율은 빼고 다른 칸은 그대로 보낸다', () => {
        const body = evaluateSaveBody(ctx);
        const usd = body.language_currency.currencies.find((c: any) => c.code === 'USD');
        expect(usd).not.toHaveProperty('exchange_rate');
        expect(usd.decimal_places).toBe(2);
        expect(body._tab).toBe('language_currency');
    });

    /**
     * @scenario managed_rate_param=omitted, provider_refreshed=yes
     *
     * @effects omitted_managed_rate_keeps_saved_value
     */
    it('비관리 통화·새로 추가하는 통화의 환율은 그대로 보낸다', () => {
        const body = evaluateSaveBody(ctx);
        const byCode = Object.fromEntries(body.language_currency.currencies.map((c: any) => [c.code, c]));
        expect(byCode.JPY.exchange_rate).toBe(0.11);
        expect(byCode.AUD.exchange_rate).toBe(0.0011);
        expect(byCode.KRW.exchange_rate).toBe(1);
    });

    /**
     * @scenario managed_rate_param=omitted, provider_refreshed=yes
     *
     * @effects omitted_managed_rate_keeps_saved_value
     */
    it('다른 탭 저장은 종전 형태(활성 탭 몫만)를 유지한다', () => {
        const body = evaluateSaveBody({ ...ctx, _global: { activeEcommerceSettingsTab: 'shipping' }, _local: { form: { shipping: { a: 1 }, inquiry: {} } } });
        expect(body).toEqual({ _tab: 'shipping', shipping: { a: 1 }, inquiry: {} });
    });
});
