// e2e:allow 프레임 브리지의 부모-자식 배선 단위. 실제 KCP 결제창(외부 SDK)은 브라우저 실측(이력 §8 T8 PG 행)이 덮는다.
/**
 * NHN KCP PC 결제창 — 인라인 스크립트 없는 같은 출처 프레임 (#158 strict CSP)
 *
 * 종전에는 빈 iframe 에 `document.write` 로 SDK 와 인라인 `<script>` 를 써 넣었다. 사이트가 `script-src 'self'` 를
 * 걸면 그 인라인 코드가 실행되지 않아 PC 결제가 15초 뒤 시간 초과로 끝났다. 이제 iframe 은 같은 출처 프레임
 * 페이지를 열고, 브리지(`assets/bridge/kcp-frame.js`)가 부모의 `window.__g7KcpFrame` 으로 값을 주고받는다.
 *
 * 잠그는 것: 프레임 주소 모양 · 핸들러 소스에 document.write/인라인 스크립트 없음 · 브리지가 폼을 채우고
 * KCP_Pay_Execute 후 ready · SDK 없음은 fail · m_Completepayment 가 complete 로 이어짐 · 부모 객체가 없으면 무동작.
 */
import { describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { buildKcpFrameUrl, KCP_FRAME_BRIDGE, KCP_FRAME_PATH } from '../../handlers/requestPayment';

const bridgeSource = readFileSync(resolve(__dirname, '../../../../assets/bridge/kcp-frame.js'), 'utf-8');
const handlerSource = readFileSync(resolve(__dirname, '../../handlers/requestPayment.ts'), 'utf-8');

/**
 * 프레임 문서 하나를 만들고 브리지를 그 문서·창 대역으로 실행한다.
 *
 * @param parentBridge 부모 창의 브리지 객체 (없으면 undefined)
 * @param sdk KCP_Pay_Execute 대역 (없으면 SDK 미로드)
 * @returns 프레임 창 대역과 폼
 */
function runBridge(parentBridge: unknown, sdk?: (form: HTMLFormElement) => void) {
    const frameDoc = document.implementation.createHTMLDocument('kcp');
    frameDoc.body.innerHTML = '<form name="order_info"></form>';
    const parent = { [KCP_FRAME_BRIDGE]: parentBridge };
    const frameWin: any = { parent, KCP_Pay_Execute: sdk };

    // eslint-disable-next-line no-new-func
    new Function('window', 'document', bridgeSource)(frameWin, frameDoc);

    return { frameWin, form: frameDoc.forms.namedItem('order_info') as HTMLFormElement };
}

describe('KCP PC 결제창 프레임 (#158)', () => {
    /**
     * @scenario surface=kcp_pc_frame
     * @effects kcp_pc_frame_without_inline_script
     */
    it('프레임 주소는 같은 출처 경로 + 인코딩된 SDK 주소다', () => {
        expect(buildKcpFrameUrl('https://testpay.kcp.co.kr/plugin/payplus_web.jsp')).toBe(
            `${KCP_FRAME_PATH}?sdk=https%3A%2F%2Ftestpay.kcp.co.kr%2Fplugin%2Fpayplus_web.jsp`,
        );
        expect(KCP_FRAME_PATH.startsWith('/plugins/sirsoft-pay_nhnkcp/')).toBe(true);
    });

    /**
     * @scenario surface=kcp_pc_frame
     * @effects kcp_pc_frame_without_inline_script
     */
    it('핸들러는 iframe 에 document.write 로 스크립트를 써 넣지 않는다', () => {
        const code = handlerSource.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '');
        expect(code).not.toMatch(/document\.write\s*\(/);
        expect(code).not.toMatch(/<script>/);
    });

    /**
     * @scenario surface=kcp_pc_frame
     * @effects kcp_pc_frame_bridge_relays_fields_and_result
     */
    it('브리지가 부모 값으로 폼을 채우고 KCP_Pay_Execute 후 ready 를 부른다', () => {
        const bridge = { fields: { site_cd: 'T0000', good_name: '상품 "A"' }, complete: vi.fn(), ready: vi.fn(), fail: vi.fn() };
        const execute = vi.fn();

        const { frameWin, form } = runBridge(bridge, execute);

        expect((form.elements.namedItem('site_cd') as HTMLInputElement).value).toBe('T0000');
        expect((form.elements.namedItem('good_name') as HTMLInputElement).value).toBe('상품 "A"');
        expect(execute).toHaveBeenCalledWith(form);
        expect(bridge.ready).toHaveBeenCalledTimes(1);
        expect(bridge.fail).not.toHaveBeenCalled();

        // KCP 가 결과 폼으로 m_Completepayment 를 부르면 부모 complete 로 이어진다
        const result = form.ownerDocument.createElement('form');
        frameWin.m_Completepayment(result);
        expect(bridge.complete).toHaveBeenCalledWith(result, frameWin);
    });

    /**
     * @scenario surface=kcp_pc_frame
     * @effects kcp_pc_frame_bridge_relays_fields_and_result
     */
    it('SDK 가 없으면 fail, 부모 객체가 없으면 아무 것도 하지 않는다', () => {
        const bridge = { fields: {}, complete: vi.fn(), ready: vi.fn(), fail: vi.fn() };
        runBridge(bridge, undefined);
        expect(bridge.fail).toHaveBeenCalledWith('KCP_Pay_Execute not defined');
        expect(bridge.ready).not.toHaveBeenCalled();

        const execute = vi.fn();
        const { frameWin } = runBridge(undefined, execute);
        expect(execute).not.toHaveBeenCalled();
        expect(frameWin.m_Completepayment).toBeUndefined();
    });
});
