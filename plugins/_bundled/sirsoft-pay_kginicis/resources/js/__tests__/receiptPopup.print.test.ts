// e2e:allow 팝업 문서의 버튼 배선 단위. 실제 결제확인서 팝업(PG 결제 필요)은 브라우저 실측(이력 §8 T8 브리지 행)이 덮는다.
/**
 * KG 이니시스 CBT 결제확인서 팝업 — 인쇄 버튼에 인라인 onclick 이 없다 (#158 strict CSP)
 *
 * 빈 팝업(`window.open('')`)은 여는 문서의 CSP 를 물려받는다. 사이트가 `script-src 'self'` 를 걸면
 * 팝업 HTML 문자열 속 `onclick="window.print()"` 가 실행되지 않아 인쇄 버튼이 무반응이었다.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { openKginicisReceipt } from '../receiptPopup';

describe('CBT 결제확인서 인쇄 버튼 (#158)', () => {
    afterEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    /**
     * @scenario surface=receipt_popup
     * @effects receipt_print_button_without_inline_handler
     */
    it('팝업 HTML 에 on* 속성이 없고 인쇄 버튼 click 이 팝업 print 를 부른다', () => {
        // 같은 출처 iframe 을 팝업 대역으로 쓴다 — 실제 문서 객체라 open/write/close 가 동작한다
        const frame = document.createElement('iframe');
        document.body.appendChild(frame);
        const popup = frame.contentWindow as Window;
        const print = vi.fn();
        Object.defineProperty(popup, 'print', { configurable: true, value: print });
        vi.spyOn(window, 'open').mockReturnValue(popup);

        openKginicisReceipt({
            receipt_type: 'cbt_confirmation',
            receipt_title: '결제확인서',
            receipt_fields: [{ label: '주문번호', value: 'ORD-1' }],
        } as any);

        const html = popup.document.documentElement.outerHTML;
        expect(html).toContain('ORD-1');
        expect(html).not.toMatch(/\son[a-z]+\s*=/i);

        const button = popup.document.querySelector('[data-print]') as HTMLButtonElement;
        expect(button).not.toBeNull();
        button.click();
        expect(print).toHaveBeenCalledTimes(1);
    });
});
