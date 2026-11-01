// e2e:allow 셸 부트스트랩의 순수 DOM 로직 단위. 브라우저 계층(strict CSP 실제 차단)은 strict-csp.spec.ts 가 커버한다.
/**
 * 부트스트랩 셸 `public/assets/core/js/g7-shell-boot.js` (#158)
 *
 * 종전 인라인 부트스트랩·G7Config 대입·번들 `<script onload/onerror>` 를 정적 파일로 옮겼다.
 * strict CSP(`script-src 'self'`)에서 인라인 코드가 막혀 전 화면이 백지가 되던 결함의 회귀 가드다.
 *
 * 잠그는 것:
 *  - JSON 데이터 블록 → window.G7Config / G7Error 대입, assetUrlMode 우선순위(`__g7AssetUrlMode` 우선)
 *  - document 캡처 load → pending 감소 · initTemplateApp 정확히 1회
 *  - document 캡처 error → 지수 백오프 재시도(300ms → 600ms) → 3회 소진 시 폴백
 *  - 폴백 새로고침 버튼은 인라인 onclick 없이 click 리스너로 reload
 *  - `<html>` 에 `g7-shell-ok` 클래스 (셸 미도착 CSS 안내문 해제)
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const shellSource = readFileSync(
    resolve(__dirname, '../../../../public/assets/core/js/g7-shell-boot.js'),
    'utf-8',
);

interface Fixture {
    config?: Record<string, unknown>;
    error?: Record<string, unknown> | null;
    initConfig?: Record<string, unknown>;
}

/**
 * 셸이 읽는 데이터 블록과 정적 번들 태그를 심고 셸을 실행한다.
 *
 * @param fixture 데이터 블록 값
 * @returns 정적 번들 태그 두 개
 */
function bootShell(fixture: Fixture = {}): { core: HTMLScriptElement; components: HTMLScriptElement } {
    const config = fixture.config ?? { cache_version: 3, assetUrlMode: 'extension', settings: {} };
    const boot = {
        label: '[User]',
        coreEngineSrc: '/build/core/template-engine.min.js?v=1',
        componentsSrc: '/api/templates/assets/sirsoft-basic/js/components.iife.js?v=3',
        i18n: {
            title: '화면을 불러오지 못했습니다',
            message: '네트워크 <연결> 확인',
            reload: '새로고침',
            incompatible_title: '비호환',
            incompatible_message: '비호환 문구',
            blocked_title: '차단',
            blocked_message: '차단 문구',
        },
        initConfig: fixture.initConfig ?? { templateId: 'sirsoft-basic', templateType: 'user' },
    };

    document.body.innerHTML = `
        <div id="app"></div>
        <script type="application/json" id="g7-config">${JSON.stringify(config)}</script>
        ${fixture.error ? `<script type="application/json" id="g7-error">${JSON.stringify(fixture.error)}</script>` : ''}
        <script type="application/json" id="g7-boot-data">${JSON.stringify(boot)}</script>
    `;

    // eslint-disable-next-line no-new-func
    new Function(shellSource)();

    // 정적 번들 태그 — 실제 셸 HTML 과 같은 순서·표식 (jsdom 은 src 를 받지 않으므로 이벤트를 직접 쏜다)
    const core = document.createElement('script');
    core.setAttribute('data-g7-boot-bundle', 'core');
    core.setAttribute('src', boot.coreEngineSrc);
    const components = document.createElement('script');
    components.setAttribute('data-g7-boot-bundle', 'components');
    components.setAttribute('src', boot.componentsSrc);
    document.body.appendChild(core);
    document.body.appendChild(components);

    return { core, components };
}

const w = window as any;

/** 셸이 등록한 document·window 리스너 — 테스트마다 해제한다(jsdom 문서가 파일 안에서 공유됨). */
const registered: Array<[EventTarget, string, EventListenerOrEventListenerObject, boolean | AddEventListenerOptions | undefined]> = [];

function trackListeners(target: EventTarget): void {
    const original = target.addEventListener.bind(target);
    vi.spyOn(target, 'addEventListener').mockImplementation((type: string, listener: any, options?: any) => {
        registered.push([target, type, listener, options]);
        original(type, listener, options);
    });
}

describe('[case:network-strict-csp-inline-shell-blank] 부트스트랩 셸 g7-shell-boot.js (#158)', () => {
    let reload: ReturnType<typeof vi.fn>;
    const originalLocation = window.location;

    beforeEach(() => {
        reload = vi.fn();
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { ...originalLocation, reload, protocol: 'http:', origin: originalLocation.origin, href: originalLocation.href },
        });
        document.documentElement.className = '';
        delete w.G7Config;
        delete w.G7Error;
        delete w.G7Core;
        delete w.__g7Bootstrap;
        delete w.__g7AssetUrl;
        delete w.__g7AssetUrlMode;
        vi.spyOn(console, 'error').mockImplementation(() => {});
        vi.spyOn(console, 'warn').mockImplementation(() => {});
        trackListeners(document);
        trackListeners(window);
    });

    afterEach(() => {
        for (const [target, type, listener, options] of registered.splice(0)) {
            target.removeEventListener(type, listener, options);
        }
        document.head.querySelectorAll('script').forEach((el) => el.remove());
        Object.defineProperty(window, 'location', { configurable: true, value: originalLocation });
        vi.useRealTimers();
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    /**
     * @scenario surface=user_shell, csp=none, failure=none
     * @effects shell_assigns_config_from_data_block
     */
    it('데이터 블록을 G7Config·G7Error 로 대입하고 g7-shell-ok 를 붙인다', () => {
        bootShell({ error: { code: 503, layout: 'errors/503', data: [] } });

        expect(w.G7Config.cache_version).toBe(3);
        expect(w.G7Config.assetUrlMode).toBe('extension');
        expect(w.G7Error).toEqual({ code: 503, layout: 'errors/503', data: [] });
        expect(document.documentElement.classList.contains('g7-shell-ok')).toBe(true);
    });

    /** @effects shell_asset_url_mode_precedence */
    it('head 자가 복구가 확정한 __g7AssetUrlMode 를 서버 기본값보다 우선한다', () => {
        w.__g7AssetUrlMode = 'extensionless';
        bootShell();

        expect(w.G7Config.assetUrlMode).toBe('extensionless');
    });

    /** @effects shell_init_on_captured_load */
    it('두 번들의 load 를 document 캡처로 받아 initTemplateApp 을 정확히 1회 호출한다', () => {
        const initTemplateApp = vi.fn();
        w.G7Core = { initTemplateApp };
        const { core, components } = bootShell({ initConfig: { templateId: 'sirsoft-basic', templateType: 'user', locale: 'ko' } });

        core.dispatchEvent(new Event('load'));
        expect(w.__g7Bootstrap.pending).toBe(1);
        expect(initTemplateApp).not.toHaveBeenCalled();

        components.dispatchEvent(new Event('load'));
        expect(initTemplateApp).toHaveBeenCalledTimes(1);
        expect(initTemplateApp).toHaveBeenCalledWith({ templateId: 'sirsoft-basic', templateType: 'user', locale: 'ko' });

        // 늦게 도착한 중복 load 가 있어도 다시 초기화하지 않는다
        components.dispatchEvent(new Event('load'));
        expect(initTemplateApp).toHaveBeenCalledTimes(1);
    });

    /** @effects shell_ignores_unmarked_scripts */
    it('표식 없는 스크립트의 load 는 무시한다', () => {
        w.G7Core = { initTemplateApp: vi.fn() };
        bootShell();

        const other = document.createElement('script');
        document.body.appendChild(other);
        other.dispatchEvent(new Event('load'));

        expect(w.__g7Bootstrap.pending).toBe(2);
    });

    /**
     * @scenario surface=bootstrap_fallback, csp=none, failure=components_bundle_fail
     * @effects shell_retry_backoff_then_fallback
     */
    it('번들 error 를 받아 지수 백오프로 재시도하고 소진되면 폴백 + 리스너 버튼을 그린다', () => {
        vi.useFakeTimers();
        const { components } = bootShell();
        const appended = () => Array.from(document.head.querySelectorAll('script')) as HTMLScriptElement[];

        components.dispatchEvent(new Event('error'));

        // 1회차 재시도: 300ms 뒤 동적 스크립트 삽입 (컴포넌트 URL 은 모드 전환 대상이 아님 — head 헬퍼 미로드)
        vi.advanceTimersByTime(299);
        expect(appended()).toHaveLength(0);
        vi.advanceTimersByTime(1);
        expect(appended()).toHaveLength(1);
        expect(appended()[0].src).toContain('components.iife.js');

        // 동적 스크립트는 프로퍼티 핸들러로 이어진다 (인라인 속성 아님)
        expect(appended()[0].getAttribute('onerror')).toBeNull();
        (appended()[0].onerror as () => void)();

        // 2회차: 600ms 뒤
        vi.advanceTimersByTime(600);
        expect(appended()).toHaveLength(1);
        (appended()[0].onerror as () => void)();

        // 3회 소진 → 폴백
        const fallback = document.querySelector('[data-g7-bootstrap-fallback]');
        expect(fallback?.getAttribute('data-g7-bootstrap-fallback')).toBe('network');
        expect(document.getElementById('app')!.innerHTML).not.toMatch(/\son[a-z]+=/i);
        // 문구는 이스케이프되어 들어간다
        expect(document.getElementById('app')!.innerHTML).toContain('네트워크 &lt;연결&gt; 확인');

        const button = document.querySelector('[data-g7-bootstrap-reload]') as HTMLButtonElement;
        expect(button.textContent).toBe('새로고침');
        button.click();
        expect(reload).toHaveBeenCalledTimes(1);
    });

    /** @effects shell_corrupt_bundle_fallback */
    it('번들은 받았으나 전역이 없으면 corrupt 폴백을 그린다', () => {
        const { core, components } = bootShell();

        core.dispatchEvent(new Event('load'));
        components.dispatchEvent(new Event('load'));

        expect(document.querySelector('[data-g7-bootstrap-fallback]')).not.toBeNull();
        expect(w.__g7Bootstrap.initialized).toBe(true);
    });
});
