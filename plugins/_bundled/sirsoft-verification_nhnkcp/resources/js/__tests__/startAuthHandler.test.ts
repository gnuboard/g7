/**
 * @file startAuthHandler.test.ts
 * @description 본인확인 시작 핸들러 — PC 팝업 / 모바일 페이지 전환 분기 검증
 *
 * 표준창 form 이 KCP 규격대로 조립되는지, 팝업 차단·페이로드 부재 같은 실패 상황에서
 * 진행 중 상태로 잘못 진입하지 않는지, 모바일에서는 복귀 정보와 입력값이 보관되는지 확인한다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
    startAuthHandler,
    resolveFailureMessageKey,
    isMobileEnvironment,
    FORM_STASH_KEY,
    REDIRECT_STASH_KEY,
} from '../index';

const CALL_URL = 'https://testcert.kcp.co.kr/certGateway.do';
const REG_CERT_KEY = 'CERTKEY000000001';

interface SubmittedForm {
    action: string;
    target: string;
    fields: Record<string, string>;
}

let submitted: SubmittedForm[] = [];
let dispatched: Array<Record<string, unknown>> = [];
let globalState: Record<string, any> = {};

function setupG7Core(): void {
    dispatched = [];
    globalState = {};

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
        dispatch: vi.fn(async (action: Record<string, unknown>) => {
            dispatched.push(action);
            return true;
        }),
        identity: { markDomainNoticeShown: vi.fn() },
    };
}

function setChallenge(payload: Record<string, unknown> | undefined): void {
    globalState.identityChallenge = {
        provider_id: 'nhnkcp',
        purpose: 'signup',
        public_payload: payload,
    };
}

/** form.submit 을 가로채 전송 내용을 기록한다 (jsdom 은 실제 전송을 지원하지 않는다). */
function captureFormSubmits(): void {
    submitted = [];
    HTMLFormElement.prototype.submit = function (this: HTMLFormElement) {
        const fields: Record<string, string> = {};
        this.querySelectorAll('input').forEach((input) => {
            fields[input.name] = input.value;
        });
        submitted.push({
            action: this.getAttribute('action') ?? '',
            target: this.getAttribute('target') ?? '',
            fields,
        });
    };
}

describe('startAuth 핸들러', () => {
    beforeEach(() => {
        setupG7Core();
        captureFormSubmits();
        window.sessionStorage.clear();
        document.body.innerHTML = '';
        vi.stubGlobal('open', vi.fn(() => ({ closed: false, close: vi.fn() })));
        // 진행 방식 판정 입력(폭·UA)은 테스트마다 새로 정한다 — defineProperty 는
        // unstubAllGlobals 로 되돌지 않아 앞 테스트의 값이 그대로 새면 조합이 뒤섞인다.
        Object.defineProperty(window, 'innerWidth', { value: 1280, configurable: true });
        Object.defineProperty(window.navigator, 'userAgent', {
            value: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
            configurable: true,
        });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    /**
     * @scenario environment=desktop,outcome=verified
     * @effects desktop_opens_popup_and_submits_standard_window_form
     */
    it('데스크톱에서는 팝업을 열고 표준창 form 을 그 팝업으로 전송한다', async () => {
        setChallenge({ call_url: CALL_URL, reg_cert_key: REG_CERT_KEY, is_test_mode: true });

        await startAuthHandler();

        expect(window.open).toHaveBeenCalledTimes(1);
        expect(submitted).toHaveLength(1);
        expect(submitted[0].action).toBe(CALL_URL);
        expect(submitted[0].target).toBe('kcp_cert_popup');
        expect(submitted[0].fields.reg_cert_key).toBe(REG_CERT_KEY);
        expect(submitted[0].fields.kcp_page_submit_yn).toBe('Y');
        expect(globalState.identityChallenge.providerInProgress).toBe(true);
    });

    /**
     * @scenario environment=desktop,outcome=failed
     * @effects popup_blocked_shows_notice_without_entering_progress_state
     */
    it('팝업이 차단되면 안내만 하고 진행 중 상태로 들어가지 않는다', async () => {
        vi.stubGlobal('open', vi.fn(() => null));
        setChallenge({ call_url: CALL_URL, reg_cert_key: REG_CERT_KEY });

        await startAuthHandler();

        expect(submitted).toHaveLength(0);
        expect(globalState.identityChallenge.providerInProgress).toBeUndefined();

        const toast = dispatched.find((action) => action.handler === 'toast');
        expect(toast).toBeDefined();
        expect((toast?.params as any).message).toContain('modal.popup_blocked');
    });

    /**
     * @scenario environment=desktop,outcome=failed
     * @effects popup_blocked_shows_notice_without_entering_progress_state
     */
    it('표준창 호출 정보가 없으면 시작하지 않고 안내한다', async () => {
        setChallenge({ call_url: '', reg_cert_key: '' });

        await startAuthHandler();

        expect(window.open).not.toHaveBeenCalled();
        expect(submitted).toHaveLength(0);

        const toast = dispatched.find((action) => action.handler === 'toast');
        expect((toast?.params as any).message).toContain('modal.payload_not_ready');
    });

    /**
     * @scenario environment=mobile,outcome=verified
     * @effects mobile_stashes_return_url_and_form_values_before_transition
     */
    it('모바일에서는 복귀 정보와 입력값을 보관하고 현재 페이지를 전환한다', async () => {
        Object.defineProperty(window, 'innerWidth', { value: 375, configurable: true });
        document.body.innerHTML = `
            <form>
                <input name="email" value="guest@example.test" />
                <input name="password" type="password" value="secret" />
                <input name="nickname" value="길동" />
            </form>
        `;
        setChallenge({ call_url: CALL_URL, reg_cert_key: REG_CERT_KEY });

        await startAuthHandler();

        expect(window.open).not.toHaveBeenCalled();
        expect(submitted).toHaveLength(1);
        expect(submitted[0].target).toBe('_self');

        const redirectStash = JSON.parse(window.sessionStorage.getItem(REDIRECT_STASH_KEY) ?? '{}');
        expect(redirectStash.return_url).toBe(window.location.href);

        const formStash = JSON.parse(window.sessionStorage.getItem(FORM_STASH_KEY) ?? '{}');
        expect(formStash.fields.email).toBe('guest@example.test');
        expect(formStash.fields.nickname).toBe('길동');
        expect(formStash.fields.password).toBeUndefined();
    });

    /**
     * 안내 창은 엔진의 `portable` breakpoint(0~1023px)로 "인증 화면으로 이동합니다" 문구를 띄운다.
     * 진행 방식 판정이 다른 기준을 보면, 그 사이 폭에서 페이지 전환을 안내해 놓고 실제로는
     * 팝업을 연다.
     *
     * 엔진(`ResponsiveManager`)은 `window.innerWidth` 로 breakpoint 를 정한다. 판정이
     * `matchMedia` 를 쓰면 devicePixelRatio 가 정수가 아닌 환경에서 경계 1px 이 어긋난다 —
     * 실측(Chrome, dPR 1.0000000447)에서 `innerWidth=1023` 인데
     * `matchMedia('(max-width: 1023px)')` 가 false 였다. 같은 값을 같은 방식으로 읽는지 잠근다.
     *
     * @scenario environment=mobile,outcome=verified
     * @effects transition_decision_matches_portable_breakpoint_of_the_notice_panel
     */
    it.each([
        [1023, true, '경계 폭에서는 페이지 전환'],
        [1024, false, '경계 바로 위에서는 팝업'],
    ])('innerWidth=%i 이면 엔진 portable 판정과 같은 경로를 탄다 (%s)', async (innerWidth, expectTransition) => {
        // matchMedia 는 항상 false 를 돌려주도록 둔다 — 판정이 이 값을 보면 경계에서 갈라진다.
        vi.stubGlobal('matchMedia', vi.fn(() => ({ matches: false })));
        Object.defineProperty(window, 'innerWidth', { value: innerWidth, configurable: true });
        // 데스크톱 UA — 폭 판정만으로 갈리는 경로를 태우기 위해 UA 분기를 비켜 간다.
        Object.defineProperty(window.navigator, 'userAgent', {
            value: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
            configurable: true,
        });
        setChallenge({ call_url: CALL_URL, reg_cert_key: REG_CERT_KEY });

        await startAuthHandler();

        if (expectTransition) {
            expect(window.open).not.toHaveBeenCalled();
            expect(submitted[0]?.target).toBe('_self');
        } else {
            expect(window.open).toHaveBeenCalled();
            expect(submitted[0]?.target).toBe('kcp_cert_popup');
        }
    });

    /**
     * 사용자가 인증을 마치지 않고 인증창을 그냥 닫는 경우. 브리지 결과가 오지 않으므로
     * 팝업 닫힘을 주기적으로 살펴 진행 중 상태를 풀어 주지 않으면, 안내 창이 "인증창에서
     * 진행해 주세요" 상태로 굳어 [본인확인 시작] 버튼이 돌아오지 않는다.
     *
     * @scenario environment=desktop,outcome=cancelled
     * @effects popup_closed_without_result_restores_start_button
     */
    it('인증창을 그냥 닫으면 진행 중 상태를 풀어 다시 시작할 수 있게 한다', async () => {
        vi.useFakeTimers();
        const popup = { closed: false, close: vi.fn() };
        vi.stubGlobal('open', vi.fn(() => popup));
        setChallenge({ call_url: CALL_URL, reg_cert_key: REG_CERT_KEY });

        await startAuthHandler();
        expect(globalState.identityChallenge.providerInProgress).toBe(true);

        // 아직 열려 있는 동안은 진행 중 상태를 유지한다.
        vi.advanceTimersByTime(2000);
        expect(globalState.identityChallenge.providerInProgress).toBe(true);

        popup.closed = true;
        vi.advanceTimersByTime(500);

        expect(globalState.identityChallenge.providerInProgress).toBe(false);
        // 안내 창은 닫지 않는다 — 하던 작업을 잃지 않고 다시 시도할 수 있어야 한다.
        expect(dispatched.some((a) => a.handler === 'closeModal' || a.handler === 'sequence')).toBe(false);

        vi.useRealTimers();
    });

    /**
     * 인증창을 그냥 닫으면 서버에도 그 거래가 끝났음을 알려야 한다.
     *
     * 브라우저만 알고 있으면 인증 이력이 "보낸 뒤 소식 없는" 상태로, 거래 행은 소비 시각 없이
     * 남는다. 만료 시간이 지나면 정리되지만 그때까지 그 거래키는 유효한 상태로 남는다.
     *
     * @scenario environment=desktop,outcome=cancelled
     * @effects abandoned_challenge_is_cancelled_on_server
     */
    it('인증창을 그냥 닫으면 서버에 취소를 통보한다', async () => {
        vi.useFakeTimers();
        const popup = { closed: false, close: vi.fn() };
        vi.stubGlobal('open', vi.fn(() => popup));
        const fetchMock = vi.fn(async () => ({ ok: true, json: async () => ({ success: true }) }));
        vi.stubGlobal('fetch', fetchMock as unknown as typeof fetch);
        globalState.identityChallenge = {
            provider_id: 'nhnkcp',
            purpose: 'signup',
            challenge_id: 'challenge-abandoned',
            public_payload: { call_url: CALL_URL, reg_cert_key: REG_CERT_KEY },
        };

        await startAuthHandler();
        popup.closed = true;
        vi.advanceTimersByTime(500);
        await vi.runAllTicks?.();

        const calls = fetchMock.mock.calls as unknown as Array<[string, RequestInit]>;
        const cancelCall = calls.find(([url]) => String(url).includes('/cancel'));
        expect(cancelCall, '취소 엔드포인트 호출이 있어야 한다').toBeTruthy();
        expect(cancelCall![0]).toBe('/api/identity/challenges/challenge-abandoned/cancel');
        expect(cancelCall![1].method).toBe('POST');

        vi.useRealTimers();
    });

    /**
     * 닫힘 감지 후에도 감시 타이머가 계속 돌면 다음 시도의 상태를 덮어써 버린다.
     *
     * @scenario environment=desktop,outcome=cancelled
     * @effects popup_closed_without_result_restores_start_button
     */
    it('닫힘을 감지한 뒤에는 감시를 멈추고 다시 시작할 수 있다', async () => {
        vi.useFakeTimers();
        const first = { closed: false, close: vi.fn() };
        vi.stubGlobal('open', vi.fn(() => first));
        setChallenge({ call_url: CALL_URL, reg_cert_key: REG_CERT_KEY });

        await startAuthHandler();
        first.closed = true;
        vi.advanceTimersByTime(500);

        const second = { closed: false, close: vi.fn() };
        vi.stubGlobal('open', vi.fn(() => second));

        await startAuthHandler();

        expect(submitted).toHaveLength(2);
        expect(globalState.identityChallenge.providerInProgress).toBe(true);

        // 이전 감시가 살아 있었다면 여기서 진행 중 상태가 다시 false 로 뒤집힌다.
        vi.advanceTimersByTime(2000);
        expect(globalState.identityChallenge.providerInProgress).toBe(true);

        vi.useRealTimers();
    });

    /**
     * @scenario environment=mobile,outcome=verified
     * @effects mobile_stash_excludes_password_fields
     */
    it('모바일 판정은 사용자 에이전트 또는 화면 폭으로 결정된다', () => {
        const setWidth = (value: number) =>
            Object.defineProperty(window, 'innerWidth', { value, configurable: true });

        setWidth(1280);
        expect(isMobileEnvironment()).toBe(false);

        setWidth(1024);
        expect(isMobileEnvironment()).toBe(false);

        // 엔진 portable 상한 — 이 값까지는 페이지 전환이다.
        setWidth(1023);
        expect(isMobileEnvironment()).toBe(true);

        setWidth(375);
        expect(isMobileEnvironment()).toBe(true);

        // 폭이 넓어도 모바일 UA 면 페이지 전환 (실기기 태블릿·대화면 폰)
        setWidth(1280);
        Object.defineProperty(window.navigator, 'userAgent', {
            value: 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Safari/604.1',
            configurable: true,
        });
        expect(isMobileEnvironment()).toBe(true);
    });
});

describe('실패 코드 안내 매핑', () => {
    /**
     * @scenario environment=desktop,outcome=failed
     * @effects mobile_return_shows_failure_notice_and_strips_error_param
     */
    it('주요 실패 사유는 전용 다국어 키로 매핑된다', () => {
        expect(resolveFailureMessageKey('9999')).toContain('errors.cancelled');
        expect(resolveFailureMessageKey('NOT_ADULT')).toContain('errors.not_adult');
        expect(resolveFailureMessageKey('DUPLICATE')).toContain('errors.duplicate');
        expect(resolveFailureMessageKey('REMOTE_CALL_FAILED')).toContain('errors.remote_call_failed');
    });

    /**
     * @scenario environment=desktop,outcome=failed
     * @effects mobile_return_shows_failure_notice_and_strips_error_param
     */
    it('매핑되지 않은 인증기관 응답 코드는 일반 안내로 처리된다', () => {
        expect(resolveFailureMessageKey('CS16')).toContain('errors.verify_failed');
        expect(resolveFailureMessageKey('')).toContain('errors.verify_failed');
    });
});
