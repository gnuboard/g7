/**
 * @file handlerReregistration.test.ts
 * @description 코어 재초기화 진입점은 남기되 등록할 핸들러가 없다 (#639, 결정 D22)
 *
 * TemplateApp 은 로케일 전환 등으로 ActionDispatcher 를 새로 만든 뒤 `window.__[Plugin].initPlugin()` 을
 * 부른다. 이 플러그인은 더는 액션 핸들러를 등록하지 않는다 — 동의 저장 뒤 동기화는 코어 내장
 * `refreshConsent` 가 맡는다. 전역 이름은 외부에서 이 플러그인 존재를 확인하던 코드를 깨지 않으려고 남긴다.
 *
 * @scenario case=frontend_refresh
 *
 * @effects layouts_refresh_through_core_handler
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import { initPlugin, PLUGIN_IDENTIFIER } from '../index';

describe('코어 재초기화 진입점', () => {
    afterEach(() => {
        delete (window as any).G7Core;
    });

    // @scenario case=frontend_refresh
    // @effects layouts_refresh_through_core_handler
    it('window 전역에 식별자와 initPlugin 을 노출한다', () => {
        const exposed = (window as any).__SirsoftGdpr;

        expect(exposed).toBeDefined();
        expect(exposed.identifier).toBe(PLUGIN_IDENTIFIER);
        expect(typeof exposed.initPlugin).toBe('function');
    });

    // @scenario case=frontend_refresh
    // @effects layouts_refresh_through_core_handler
    it('initPlugin 은 핸들러를 등록하지 않는다 — 옛 syncConsent 가 되살아나지 않는다', () => {
        const registerHandler = vi.fn();
        (window as any).G7Core = { getActionDispatcher: () => ({ registerHandler }) };

        initPlugin();

        expect(registerHandler).not.toHaveBeenCalled();
    });
});
