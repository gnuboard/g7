// e2e:allow 팝업 문서 HTML 문자열의 계약(인라인 스타일 없음·CSS 링크)을 잠근다 — 정책을 강제한 브라우저에서 팝업 모양(본문 720px·테두리)과 인쇄는 출시 전 화면 재실측(엄격한 스타일 정책 강제 + CBT 결제확인서)이 덮는다. 실제 팝업은 결제 실거래가 필요해 E2E 로 재현할 수 없다
/**
 * KG 이니시스 CBT 결제확인서 팝업은 인라인 스타일을 쓰지 않는다 — 스타일 보안 정책 호환
 *
 * 회귀 배경: 빈 팝업(`window.open('')`)은 여는 문서의 CSP 를 물려받는다. 사이트가 `style-src 'self'` 를 걸면
 * 팝업 HTML 문자열 속 `<style>` 블록이 막혀 결제확인서가 무스타일(여백·테두리·2단 배치 없음)로 떴다. 규칙은
 * 같은 출처 정적 파일(`assets/bridge/receipt-popup.css`)로 옮기고, 팝업 문서에는 `<link>` 만 둔다. 주소는 코어의
 * `G7Core.asset.plugin()` 으로 만든다 — 그 API 가 없는 코어에서는 링크 없이 그린다(내용·인쇄는 된다).
 *
 * @scenario-manifest plugins/_bundled/sirsoft-pay_kginicis/tests/scenarios/standalone-pages-no-inline-style.yaml
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { openKginicisReceipt } from '../receiptPopup';

const RECEIPT = {
    receipt_type: 'cbt_confirmation',
    receipt_title: '결제확인서',
    receipt_notice: '안내 문구',
    receipt_fields: [{ key: 'order_number', label: '주문번호', value: 'ORD-1' }],
} as any;

/**
 * 같은 출처 iframe 을 팝업 대역으로 세웁니다 — 실제 문서 객체라 open/write/close 가 동작한다.
 *
 * @return 팝업 창과 인쇄 감시자
 */
function openFakePopup(): { popup: Window; print: ReturnType<typeof vi.fn> } {
    const frame = document.createElement('iframe');
    document.body.appendChild(frame);
    const popup = frame.contentWindow as Window;
    const print = vi.fn();
    Object.defineProperty(popup, 'print', { configurable: true, value: print });
    vi.spyOn(window, 'open').mockReturnValue(popup);

    return { popup, print };
}

afterEach(() => {
    vi.restoreAllMocks();
    delete (window as any).G7Core;
    document.body.innerHTML = '';
});

describe('CBT 결제확인서 팝업 — 인라인 스타일 없음', () => {
    /**
     * @scenario page=receipt_popup
     * @effects standalone_page_has_no_inline_style, standalone_page_links_stylesheet_in_head
     */
    it('팝업 HTML 에 <style>·style 속성이 없고 <head> 에 receipt-popup.css 링크가 있다', () => {
        const plugin = vi.fn((id: string, path: string) => `/api/plugins/assets/${id}/${path}?v=1.2.4`);
        (window as any).G7Core = { asset: { plugin } };
        const { popup } = openFakePopup();

        openKginicisReceipt(RECEIPT);

        const html = popup.document.documentElement.outerHTML;
        expect(html).toContain('ORD-1');
        expect(popup.document.querySelectorAll('style')).toHaveLength(0);
        expect(html).not.toMatch(/\sstyle\s*=/i);

        expect(plugin).toHaveBeenCalledWith('sirsoft-pay_kginicis', 'assets/bridge/receipt-popup.css');
        const links = popup.document.head.querySelectorAll('link[rel="stylesheet"]');
        expect(links).toHaveLength(1);
        // 팝업은 about:blank 라 상대 주소가 풀리지 않는다 — 여는 창 출처의 절대 주소로 싣는다
        expect(links[0].getAttribute('href')).toBe(
            `${window.location.origin}/api/plugins/assets/sirsoft-pay_kginicis/assets/bridge/receipt-popup.css?v=1.2.4`
        );
    });

    /**
     * @scenario page=receipt_popup
     * @effects stylesheet_href_is_escaped
     */
    it('CSS 주소는 속성값으로 이스케이프해 싣는다', () => {
        (window as any).G7Core = { asset: { plugin: () => '/assets?file=a&b="c"' } };
        const { popup } = openFakePopup();

        openKginicisReceipt(RECEIPT);

        const link = popup.document.head.querySelector('link[rel="stylesheet"]') as HTMLLinkElement;
        expect(link).not.toBeNull();
        expect(link.getAttribute('href')).toBe(`${window.location.origin}/assets?file=a&b=%22c%22`);
        expect(popup.document.querySelectorAll('link')).toHaveLength(1);
    });

    /**
     * @scenario page=receipt_popup
     * @effects popup_renders_without_asset_api
     */
    it('자산 API 가 없는 코어에서는 링크 없이 그리고 인쇄 버튼은 그대로 동작한다', () => {
        const { popup, print } = openFakePopup();

        openKginicisReceipt(RECEIPT);

        const html = popup.document.documentElement.outerHTML;
        expect(html).toContain('ORD-1');
        expect(popup.document.querySelectorAll('link')).toHaveLength(0);
        expect(popup.document.querySelectorAll('style')).toHaveLength(0);
        expect(html).not.toMatch(/\sstyle\s*=/i);

        (popup.document.querySelector('[data-print]') as HTMLButtonElement).click();
        expect(print).toHaveBeenCalledTimes(1);
    });

    /**
     * @scenario page=receipt_popup
     * @effects popup_renders_without_asset_api
     */
    it('자산 API 가 주소를 만들지 못하면(예외·빈 값) 링크 없이 그린다', () => {
        (window as any).G7Core = { asset: { plugin: () => { throw new Error('boom'); } } };
        const first = openFakePopup();
        openKginicisReceipt(RECEIPT);
        expect(first.popup.document.querySelectorAll('link')).toHaveLength(0);
        expect(first.popup.document.documentElement.outerHTML).toContain('ORD-1');

        vi.restoreAllMocks();
        (window as any).G7Core = { asset: { plugin: () => '' } };
        const second = openFakePopup();
        openKginicisReceipt(RECEIPT);
        expect(second.popup.document.querySelectorAll('link')).toHaveLength(0);
    });
});
