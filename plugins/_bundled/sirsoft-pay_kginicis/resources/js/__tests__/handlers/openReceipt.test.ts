/**
 * @file openReceipt.test.ts
 * @description 영수증 창 핸들러(sirsoft-pay_kginicis.openReceipt)
 *
 * 주문 화면 조각은 마운트 때 영수증 조회 API 응답을 화면 상태에 담아 두고, 버튼을 누르면 그 응답을 이 핸들러로
 * 넘긴다. 예전에는 주입 스크립트가 클릭마다 로그인 토큰을 저장소에서 읽어 직접 다시 조회했다(#639). 핸들러는
 * 조회하지 않고 창만 연다 — 외부 영수증 주소는 https 만, CBT 결제확인서는 응답 항목을 이스케이프해 그린다.
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_kginicis/tests/scenarios/receipt-popup-handler.yaml
 */

import { afterEach, describe, expect, it, vi } from 'vitest';
import { handlerMap } from '../../handlers';
import { openReceiptHandler } from '../../handlers/openReceipt';

afterEach(() => {
    vi.restoreAllMocks();
    delete (window as any).G7Core;
});

describe('openReceipt 핸들러', () => {
    // @scenario receipt=inicis_url
    // @effects opens_https_receipt_without_opener, no_fetch_on_click
    it('외부 영수증 주소는 opener 를 끊은 크기 지정 창으로 열고 다시 조회하지 않는다', () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch');
        const popup = { opener: window as unknown, location: { href: '' } };
        const open = vi.spyOn(window, 'open').mockReturnValue(popup as unknown as Window);

        expect(openReceiptHandler({ params: { receipt: { receipt_type: 'inicis_receipt', receipt_url: 'https://iniweb.example/receipt?noTid=T1' } } })).toBe(true);

        expect(open).toHaveBeenCalledWith('', 'kginicis_receipt', expect.stringContaining('width=800'));
        expect(popup.opener).toBeNull();
        expect(popup.location.href).toBe('https://iniweb.example/receipt?noTid=T1');
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    // @scenario receipt=non_https_url
    // @effects opens_https_receipt_without_opener
    it('https 가 아닌 주소는 열지 않는다', () => {
        const open = vi.spyOn(window, 'open');

        openReceiptHandler({ params: { receipt: { receipt_type: 'inicis_receipt', receipt_url: 'javascript:alert(1)' } } });
        openReceiptHandler({ params: { receipt: { receipt_type: 'inicis_receipt', receipt_url: 'http://iniweb.example/receipt' } } });

        expect(open).not.toHaveBeenCalled();
    });

    // @scenario receipt=cbt_confirmation
    // @effects cbt_confirmation_document_escapes_fields
    it('CBT 결제확인서는 응답 항목으로 인쇄용 문서를 그리고 값은 이스케이프한다', () => {
        let html = '';
        const popup = { document: { open: vi.fn(), write: (s: string) => { html += s; }, close: vi.fn() } };
        vi.spyOn(window, 'open').mockReturnValue(popup as unknown as Window);

        expect(openReceiptHandler({
            params: {
                receipt: {
                    receipt_type: 'cbt_confirmation',
                    receipt_title: 'KG 이니시스 CBT 결제확인서',
                    receipt_fields: [{ key: 'order_number', label: '주문번호', value: '<img src=x onerror=alert(1)>' }],
                },
            },
        })).toBe(true);

        expect(html).toContain('KG 이니시스 CBT 결제확인서');
        expect(html).toContain('&lt;img src=x onerror=alert(1)&gt;');
        expect(html).not.toContain('<img src=x');
    });

    // @scenario receipt=empty
    // @effects nothing_opened_without_receipt
    it('열 수 있는 것이 없으면(응답 없음·주소 없음·확인서 항목 없음) 아무 창도 열지 않는다', () => {
        const open = vi.spyOn(window, 'open');

        expect(openReceiptHandler({ params: {} })).toBe(false);
        expect(openReceiptHandler({ params: { receipt: { receipt_type: 'inicis_receipt', receipt_url: null } } })).toBe(false);
        expect(openReceiptHandler({ params: { receipt: { receipt_type: 'cbt_confirmation', receipt_fields: [] } } })).toBe(false);
        expect(open).not.toHaveBeenCalled();
    });

    // @scenario receipt=inicis_url
    // @effects handler_registered_with_plugin_prefix
    it('핸들러 맵에 openReceipt 로 등록된다 (등록 시 sirsoft-pay_kginicis. 접두)', () => {
        expect(handlerMap.openReceipt).toBe(openReceiptHandler);
    });
});
