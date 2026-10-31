/**
 * KCP 본인확인 — 표준창 제출 주소 허용 목록과 결과 수신 검증.
 *
 * 검증 범위:
 *  - 거래등록 응답의 call_url 이 https + KCP 인증 도메인일 때만 제출한다(fail-closed)
 *  - 결과 메시지는 열린 팝업·사이트 origin·진행 중 challenge 의 것만 받는다
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { startAuthHandler, isTrustedCallUrl } from '../index';

const SITE_ORIGIN = 'https://shop.example.test';
const CALL_URL = 'https://testcert.kcp.co.kr/certGateway.do';

describe('KCP 본인확인 — 제출 주소·결과 수신 검증', () => {
    let dispatched: Array<Record<string, any>>;
    let globalState: Record<string, any>;
    let submitted: Array<{ action: string }>;
    let popup: { closed: boolean; close: ReturnType<typeof vi.fn> };

    beforeEach(() => {
        dispatched = [];
        submitted = [];
        popup = { closed: false, close: vi.fn() };
        globalState = {
            identityChallenge: {
                provider_id: 'nhnkcp',
                purpose: 'signup',
                challenge_id: 'chal-1',
                public_payload: { call_url: CALL_URL, reg_cert_key: 'CERTKEY000000001', siteOrigin: SITE_ORIGIN },
            },
        };
        (window as any).G7Core = {
            state: {
                get: () => globalState,
                getGlobal: () => globalState,
                set: (updates: Record<string, any>) => {
                    globalState = {
                        ...globalState,
                        identityChallenge: { ...(globalState.identityChallenge ?? {}), ...(updates.identityChallenge ?? {}) },
                    };
                },
            },
            dispatch: vi.fn(async (action: Record<string, any>) => {
                dispatched.push(action);
            }),
            identity: { markDomainNoticeShown: vi.fn() },
        };
        vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(function (this: HTMLFormElement) {
            submitted.push({ action: this.getAttribute('action') ?? '' });
        });
        vi.stubGlobal('open', vi.fn(() => popup));
        Object.defineProperty(window, 'innerWidth', { value: 1280, configurable: true });
        Object.defineProperty(window.navigator, 'userAgent', {
            value: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
            configurable: true,
        });
    });

    afterEach(() => {
        delete (window as any).G7Core;
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    /**
     * 결과 메시지를 흉내 낸다.
     *
     * @param data 메시지 본문
     * @param origin 발신 출처
     * @param source 발신 창
     */
    function post(data: Record<string, unknown>, origin: string, source: unknown): void {
        const ev = new MessageEvent('message', { data: { type: 'identity_result', ...data }, origin });
        Object.defineProperty(ev, 'source', { value: source });
        window.dispatchEvent(ev);
    }

    // @scenario host_header=canonical, sink=front_form_action
    // @effects front_submits_only_to_kcp_cert_host
    it('call_url 허용 판정 — https + KCP 인증 도메인만', () => {
        expect(isTrustedCallUrl(CALL_URL)).toBe(true);
        expect(isTrustedCallUrl('https://cert.kcp.co.kr/certGateway.do')).toBe(true);
        expect(isTrustedCallUrl('http://testcert.kcp.co.kr/certGateway.do')).toBe(false);
        expect(isTrustedCallUrl('https://kcp.co.kr.evil.example/x')).toBe(false);
        expect(isTrustedCallUrl('https://evil.example/kcp.co.kr')).toBe(false);
        expect(isTrustedCallUrl('/relative')).toBe(false);
        expect(isTrustedCallUrl(undefined)).toBe(false);
    });

    // @scenario host_header=forged, sink=front_form_action
    // @effects front_submits_only_to_kcp_cert_host
    it('허용 밖 call_url 이면 팝업을 열지 않고 제출하지 않는다', async () => {
        globalState.identityChallenge.public_payload.call_url = 'https://evil.example/collect';

        await startAuthHandler();

        expect(window.open).not.toHaveBeenCalled();
        expect(submitted).toHaveLength(0);
        expect(dispatched.find((a) => a.handler === 'toast')?.params.message).toContain('modal.payload_not_ready');
    });

    // @scenario host_header=forged, sink=front_message
    // @effects front_accepts_only_popup_site_origin_current_challenge
    it('다른 출처·다른 창·다른 challenge 의 결과는 무시하고, 열린 팝업의 사이트 origin 결과만 받는다', async () => {
        await startAuthHandler();
        expect(submitted[0]?.action).toBe(CALL_URL);

        post({ verification_token: 'evil', challenge_id: 'chal-1' }, window.location.origin, popup);
        post({ verification_token: 'evil', challenge_id: 'chal-1' }, SITE_ORIGIN, window);
        post({ verification_token: 'evil', challenge_id: 'chal-OTHER' }, SITE_ORIGIN, popup);
        expect(dispatched.find((a) => a.handler === 'sequence')).toBeUndefined();

        post({ verification_token: 'tok-ok', challenge_id: 'chal-1' }, SITE_ORIGIN, popup);
        const seq = dispatched.find((a) => a.handler === 'sequence');
        expect(seq?.params.actions[0]).toEqual({ handler: 'resolveIdentityChallenge', params: { result: 'verified', token: 'tok-ok' } });
    });

    // @scenario host_header=canonical, sink=front_message
    // @effects front_accepts_only_popup_site_origin_current_challenge
    it('siteOrigin 이 없으면 시작하지 않는다', async () => {
        delete globalState.identityChallenge.public_payload.siteOrigin;

        await startAuthHandler();

        expect(window.open).not.toHaveBeenCalled();
    });
});
