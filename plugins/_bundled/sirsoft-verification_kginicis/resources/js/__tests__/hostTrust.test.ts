/**
 * 본인확인 결과 전달 주소·결과 수신 검증 — 요청 Host·브라우저 현재 주소 비신뢰.
 *
 * 검증 범위:
 *  - 이니시스로 보내는 successUrl·failUrl 은 서버가 내려준 사이트 주소(public_payload.callbackUrl)만 쓴다
 *  - callbackUrl 이 없거나 http(s) 가 아니면 인증을 시작하지 않는다
 *  - 결과 메시지는 열린 팝업·사이트 origin·진행 중 challenge 의 것만 받는다
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { startAuthHandler, buildSuccessUrl } from '../index';

const SITE_ORIGIN = 'https://shop.example.test';
const CALLBACK_URL = `${SITE_ORIGIN}/plugins/sirsoft-verification_kginicis/plugin/inicis/callback`;

describe('이니시스 본인확인 — 사이트 주소 고정', () => {
    let dispatchMock: ReturnType<typeof vi.fn>;
    let mockPopup: { close: ReturnType<typeof vi.fn>; closed: boolean };
    let submitted: Array<Record<string, string>>;
    let originalOpen: typeof window.open;

    const challenge = {
        provider_id: 'inicis',
        challenge_id: 'chal-1',
        public_payload: {
            callbackUrl: CALLBACK_URL,
            siteOrigin: SITE_ORIGIN,
            mid: 'INIiasTest',
            mtxid: 'mtx-1',
            authHash: 'hash-1',
            reqSvcCd: '03',
            flgFixedUser: 'N',
            reservedMsg: 'isUseToken=Y',
        },
    };

    beforeEach(() => {
        dispatchMock = vi.fn().mockResolvedValue(undefined);
        mockPopup = { close: vi.fn(), closed: false };
        submitted = [];

        (window as any).G7Core = {
            dispatch: dispatchMock,
            state: {
                set: vi.fn(),
                getGlobal: () => ({ identityChallenge: challenge }),
                get: () => ({ identityChallenge: challenge }),
            },
            identity: { markDomainNoticeShown: vi.fn() },
        };

        originalOpen = window.open;
        (window as any).open = vi.fn(() => mockPopup);
        vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(function (this: HTMLFormElement) {
            const fields: Record<string, string> = {};
            for (const el of Array.from(this.elements) as HTMLInputElement[]) {
                fields[el.name] = el.value;
            }
            submitted.push(fields);
        });
        vi.useFakeTimers();
    });

    afterEach(() => {
        delete (window as any).G7Core;
        (window as any).open = originalOpen;
        vi.useRealTimers();
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

    function sequenceDispatch(): any {
        return dispatchMock.mock.calls.find((c) => c[0]?.handler === 'sequence')?.[0];
    }

    // @scenario host_header=forged, sink=front_result_url
    // @effects front_sends_site_callback_url
    it('successUrl·failUrl 은 public_payload.callbackUrl 이고 브라우저 현재 origin 을 따르지 않는다', async () => {
        await startAuthHandler();

        expect(window.location.origin).not.toBe(SITE_ORIGIN);
        expect(submitted).toHaveLength(1);
        expect(submitted[0].successUrl).toBe(CALLBACK_URL);
        expect(submitted[0].failUrl).toBe(CALLBACK_URL);
    });

    // @scenario host_header=canonical, sink=front_result_url
    // @effects front_stops_without_site_callback_url
    it('callbackUrl 이 없거나 http(s) 가 아니면 인증을 시작하지 않고 안내한다', async () => {
        expect(buildSuccessUrl({ ...challenge.public_payload, callbackUrl: '/relative' } as any)).toBeNull();
        expect(buildSuccessUrl({ ...challenge.public_payload, callbackUrl: 'javascript:alert(1)' } as any)).toBeNull();
        expect(buildSuccessUrl(challenge.public_payload as any)).toBe(CALLBACK_URL);

        (window as any).G7Core.state.getGlobal = () => ({
            identityChallenge: { ...challenge, public_payload: { ...challenge.public_payload, callbackUrl: undefined } },
        });

        await startAuthHandler();

        expect(window.open).not.toHaveBeenCalled();
        expect(dispatchMock).toHaveBeenCalledWith(expect.objectContaining({ handler: 'toast' }));
    });

    // @scenario host_header=forged, sink=front_message
    // @effects front_accepts_only_popup_site_origin_current_challenge
    it('다른 출처·다른 창·다른 challenge 의 결과는 무시하고, 열린 팝업의 사이트 origin 결과만 받는다', async () => {
        await startAuthHandler();

        post({ verification_token: 'evil' }, 'https://evil.example.com', mockPopup);
        post({ verification_token: 'evil' }, window.location.origin, mockPopup);
        post({ verification_token: 'evil', challenge_id: 'chal-1' }, SITE_ORIGIN, window);
        post({ verification_token: 'evil', challenge_id: 'chal-OTHER' }, SITE_ORIGIN, mockPopup);
        expect(sequenceDispatch()).toBeUndefined();

        post({ verification_token: 'tok-ok', challenge_id: 'chal-1' }, SITE_ORIGIN, mockPopup);
        expect(sequenceDispatch()?.params.actions[0]).toEqual({
            handler: 'resolveIdentityChallenge',
            params: { result: 'verified', token: 'tok-ok' },
        });
    });

    // @scenario host_header=canonical, sink=front_message
    // @effects front_accepts_only_popup_site_origin_current_challenge
    it('토큰 없는 실패 결과는 challenge_id 가 없어도 열린 팝업의 사이트 origin 이면 받는다', async () => {
        await startAuthHandler();

        post({ identity_error: 'PROVIDER_ERROR' }, SITE_ORIGIN, mockPopup);

        expect(sequenceDispatch()?.params.actions[0]).toEqual({
            handler: 'resolveIdentityChallenge',
            params: { result: 'failed', failureCode: 'PROVIDER_ERROR' },
        });
    });
});
