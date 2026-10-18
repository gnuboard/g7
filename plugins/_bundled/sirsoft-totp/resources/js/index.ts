import { renderQrSvg } from './qr';
import { handlerMap } from './handlers';

/**
 * 인증 앱(TOTP) 플러그인 프론트 엔트리.
 *
 * 브라우저 API 가 있어야만 되는 두 가지를 맡는다 — 레이아웃 문법으로는 표현할 수 없는
 * 것들이다.
 *
 * 1. **QR 그리기** — 등록 화면의 `data-totp-qr` 요소에 QR 코드를 그린다. otpauth URI 는
 *    그 속성에 실려 오므로 **시크릿이 네트워크를 다시 타지 않는다**(외부 QR 서비스에
 *    넘기면 그 순간 제3자가 인증 수단을 갖게 된다). 레이아웃은 상태가 바뀔 때마다 이
 *    요소를 다시 그리므로, 속성값이 달라졌을 때만 다시 렌더하고 같은 값이면 건너뛴다.
 * 2. **백업 코드 복사** — `sirsoft-totp.copyToClipboard` named action 을 등록한다. 엔진에는
 *    클립보드 액션이 없어 플러그인이 내준다(형제 플러그인 sirsoft-pay_nhnkcp 와 같은 결).
 */

/** QR 을 그릴 요소를 가리키는 속성 — 레이아웃(admin/plugin_settings.g7.html)이 선언한다. */
const QR_ATTRIBUTE = 'data-totp-qr';

/** named action 앞에 붙는 식별자 — 레이아웃은 `sirsoft-totp.copyToClipboard` 로 부른다. */
const PLUGIN_IDENTIFIER = 'sirsoft-totp';

/** 마지막으로 그린 URI 를 기억해 두는 속성 — 같은 값이면 다시 그리지 않는다. */
const RENDERED_ATTRIBUTE = 'data-totp-qr-rendered';

/**
 * 문서 안의 QR 자리를 모두 채운다.
 *
 * @returns 반환값 없음
 */
function renderAll(): void {
    const targets = document.querySelectorAll<HTMLElement>(`[${QR_ATTRIBUTE}]`);

    targets.forEach((element) => {
        const uri = element.getAttribute(QR_ATTRIBUTE) ?? '';

        // 값이 아직 안 실렸거나(등록 시작 전) 이미 같은 값으로 그렸으면 둔다.
        if (uri === '' || uri === element.getAttribute(RENDERED_ATTRIBUTE)) {
            return;
        }

        try {
            element.innerHTML = renderQrSvg(uri, resolveLabel());
            element.setAttribute(RENDERED_ATTRIBUTE, uri);
        } catch {
            // QR 을 그리지 못해도 등록은 막히지 않는다 — 화면에 수동 입력 키가 함께 있다.
            element.removeAttribute(RENDERED_ATTRIBUTE);
        }
    });
}

/**
 * QR 이미지의 대체 텍스트를 고른다.
 *
 * 코어 번역기가 있으면 그것을, 없으면 영문 기본값을 쓴다 — 대체 텍스트가 비면
 * 스크린리더 사용자에게 이 자리가 무엇인지 알릴 방법이 없다.
 *
 * @returns 대체 텍스트
 */
function resolveLabel(): string {
    const core = (window as { G7Core?: { t?: (key: string) => string } }).G7Core;
    const translated = core?.t?.('sirsoft-totp.admin.security.enroll.qr_hint');

    // 번역기가 키를 찾지 못하면 키 문자열을 그대로 돌려준다 — 그 경우는 기본값을 쓴다.
    if (translated && !translated.startsWith('sirsoft-totp.')) {
        return translated;
    }

    return 'Authenticator app QR code';
}

/**
 * 이 플러그인의 named action 을 엔진 디스패처에 꽂는다.
 *
 * @returns 등록한 핸들러 수 — 디스패처가 아직 없으면 0
 */
function registerHandlers(): number {
    const g7Core = (window as Record<string, unknown>).G7Core as Record<string, unknown> | undefined;
    const getDispatcher = g7Core?.getActionDispatcher as (() => Record<string, unknown>) | undefined;

    if (typeof getDispatcher !== 'function') {
        return 0;
    }

    const dispatcher = getDispatcher();

    if (!dispatcher || typeof dispatcher.registerHandler !== 'function') {
        return 0;
    }

    // 메서드를 변수로 빼내 부르지 않는다 — 디스패처의 `registerHandler` 는 내부에서
    // `this.customHandlers` 를 쓰므로, 떼어내 호출하면 `this` 가 undefined 가 되어
    // "Cannot read properties of undefined (reading 'customHandlers')" 로 터진다.
    // 그 예외는 이 함수를 부르는 start() 를 통째로 중단시켜 QR 관측자까지 죽였다.
    // 형제 플러그인(sirsoft-verification_nhnkcp)과 같이 수신자를 붙여 호출한다.
    const register = dispatcher.registerHandler as (
        name: string,
        handler: unknown,
        meta: Record<string, string>,
    ) => void;

    let count = 0;
    for (const [name, handler] of Object.entries(handlerMap)) {
        register.call(dispatcher, `${PLUGIN_IDENTIFIER}.${name}`, handler, {
            category: 'plugin',
            source: PLUGIN_IDENTIFIER,
        });
        count++;
    }

    return count;
}

/**
 * 디스패처가 준비될 때까지 기다렸다가 핸들러를 등록한다.
 *
 * 플러그인 번들과 엔진의 적재 순서가 보장되지 않아 첫 시도가 빌 수 있다. 형제 플러그인과
 * 같은 간격·횟수(100ms × 50 = 5초)로 재시도하고, 끝내 못 찾으면 경고만 남긴다 — 복사
 * 버튼이 안 될 뿐 등록 절차 자체는 막히지 않아야 한다.
 *
 * @returns 반환값 없음
 */
function startHandlerRegistration(): void {
    if (registerHandlers() > 0) {
        return;
    }

    let retries = 0;
    const interval = setInterval(() => {
        retries++;

        if (registerHandlers() > 0 || retries >= 50) {
            clearInterval(interval);

            if (retries >= 50) {
                console.warn(`[${PLUGIN_IDENTIFIER}] ActionDispatcher not available after timeout`);
            }
        }
    }, 100);
}

/**
 * 코어 재초기화(로케일 전환) 때 불리는 진입점 — 새 디스패처에 핸들러를 다시 꽂는다.
 *
 * 로케일을 바꾸면 코어가 ActionDispatcher 를 새로 만들고 `window.__{Name}.initPlugin()` 을 부른다. 이 전역이
 * 없어 언어를 바꾼 뒤 `sirsoft-totp.copyToClipboard` 가 디스패처에서 빠졌다(#639 ④, 실측 2026-10-05). QR 관측자 같은
 * 1회성 부팅 작업은 넣지 않는다.
 *
 * @returns 반환값 없음
 */
function initPlugin(): void {
    registerHandlers();
}

if (typeof window !== 'undefined') {
    // 재등록 진입점 — 이름 고정 (TemplateApp.reinitializePluginHandlers). 문서 생성기가 이 모양으로 찾는다.
    (window as unknown as Record<string, unknown>).__SirsoftTotp = { identifier: PLUGIN_IDENTIFIER, initPlugin };
}

// 최초 렌더 + 이후 DOM 변화 추적. 레이아웃 엔진이 화면을 다시 그려도 QR 이 따라온다.
if (typeof document !== 'undefined') {
    const start = (): void => {
        // QR 관측자를 **먼저** 건다. 핸들러 등록이 실패해도 QR 은 그려져야 한다 —
        // 등록이 먼저였을 때 그 예외가 start() 를 중단시켜 관측자가 설치되지 않았고,
        // 등록 화면의 QR 자리가 빈 사각형으로 남았다(실측).
        renderAll();

        new MutationObserver(() => renderAll()).observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: [QR_ATTRIBUTE],
        });

        // 등록이 터져도 QR·관측자는 이미 살아 있다.
        try {
            startHandlerRegistration();
        } catch (error) {
            console.warn(`[${PLUGIN_IDENTIFIER}] handler registration failed`, error);
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
}

export { initPlugin, renderQrSvg };
