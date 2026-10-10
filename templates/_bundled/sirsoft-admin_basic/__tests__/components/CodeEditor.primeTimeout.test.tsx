/**
 * CodeEditor — Monaco 선심기가 제한 시간 안에 끝나지 않았을 때 AMD 로더를 두 번 넣지 않는다
 *
 * 선심기(primeMonaco)는 동봉본 `loader.js` 를 직접 넣고 `editor.main` 을 받아 `window.monaco` 에
 * 심는다. 네트워크가 느려 3초 안에 끝나지 않으면 게이트를 풀고 `loader.init()` 을 부르는데,
 * 라이브러리 `init()` 은 `window.monaco.editor` 가 아직 없으면 **자기 `loader.js` 를 또 넣는다** —
 * 그러면 AMD 로더가 두 번 실행돼 `_amdLoaderGlobal` 재선언으로 그 판의 require 가 통째로 죽는다.
 *
 * 적재가 진행 중일 때는 3초에 끊지 않는다 — 트러블슈팅 `[case:components-misc-codeeditor-prime-timeout]`.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen, act, cleanup } from '@testing-library/react';

const loaderInit = vi.fn(() => Promise.resolve({}));

vi.mock('@monaco-editor/react', () => ({
    __esModule: true,
    default: ({ value }: any) => <div data-testid="monaco-editor">{value}</div>,
    loader: {
        init: () => loaderInit(),
        config: vi.fn(),
    },
}));

import { CodeEditor } from '../../src/components/composite/CodeEditor';

/** 문서에 들어간 동봉 AMD 로더 스크립트 수 */
function loaderScripts(): HTMLScriptElement[] {
    return Array.from(document.querySelectorAll<HTMLScriptElement>('script[src*="/vs/loader.js"]'));
}

describe('CodeEditor Monaco 선심기 제한 시간', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        loaderInit.mockClear();
        // 선심기는 스크립트가 실제로 도는 환경에서만 걸린다 — jsdom 판정을 비켜 간다.
        vi.spyOn(window.navigator, 'userAgent', 'get').mockReturnValue('Mozilla/5.0 (Windows NT 10.0) Chrome/128');
        (window as any).G7Core = {
            asset: { templateDir: (id: string, p: string) => `/api/templates/assets/${id}/${p}` },
            assets: { notifyFailure: vi.fn(), clearFailure: vi.fn() },
        };
        delete (window as any).monaco;
        delete (window as any).require;
        delete (window as any).__g7MonacoPriming;
        loaderScripts().forEach((s) => s.remove());
    });

    afterEach(() => {
        cleanup();
        vi.useRealTimers();
        vi.restoreAllMocks();
        delete (window as any).G7Core;
        delete (window as any).monaco;
        delete (window as any).require;
        delete (window as any).__g7MonacoPriming;
        loaderScripts().forEach((s) => s.remove());
    });

    /**
     * 우리 loader.js 가 아직 Monaco 를 심지 못한 채 제한 시간이 지나면 `loader.init()` 을 부르지 않고
     * 평문 폴백으로 넘어간다 — 부르면 라이브러리가 두 번째 AMD 로더를 넣는다.
     *
     * @scenario case=prime_timeout
     * @effects vendored_amd_loader_is_not_injected_twice_after_prime_timeout
     */
    it('선심기 제한 시간이 지나도 두 번째 로더 주입 경로(loader.init)를 타지 않는다', async () => {
        render(<CodeEditor value="{}" />);

        expect(loaderScripts()).toHaveLength(1);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(3_100);
        });

        expect(loaderInit).not.toHaveBeenCalled();
        expect(loaderScripts()).toHaveLength(1);
        expect(screen.getByTestId('code-editor-fallback')).toBeInTheDocument();
    });

    /**
     * 선심기가 정상으로 끝나면(로더 → editor.main → window.monaco) 그때 `loader.init()` 을 한 번 부른다.
     *
     * @scenario case=primed
     * @effects vendored_amd_loader_runs_exactly_once
     */
    it('선심기가 끝나면 loader.init 을 부르고 편집기를 그린다', async () => {
        render(<CodeEditor value="{ }" />);

        const [script] = loaderScripts();
        const amdRequire: any = vi.fn((_deps: string[], cb: (main: unknown) => void) => cb({ editor: { create: vi.fn() } }));
        amdRequire.config = vi.fn();
        (window as any).require = amdRequire;

        await act(async () => {
            script.dispatchEvent(new Event('load'));
            await vi.advanceTimersByTimeAsync(0);
        });

        expect((window as any).monaco?.editor).toBeTruthy();
        expect(loaderInit).toHaveBeenCalledTimes(1);
        expect(loaderScripts()).toHaveLength(1);
        expect(screen.getByTestId('monaco-editor')).toBeInTheDocument();
    });

    /**
     * 로더 스크립트가 받아져 `editor.main` 을 요청한 뒤에는 3초가 지나도 끊지 않고 끝까지 기다린다.
     *
     * 확장자를 가로채는 서버에서 정적 게시본이 없으면 Monaco 청크가 PHP 를 거쳐 개당 약 0.7초씩 와서
     * 전부 받는 데 3초를 넘긴다(2026-10-01 실측). 3초 제한은 이벤트가 아예 오지 않는 환경을 위한 것이라,
     * 적재가 진행 중일 때 끊으면 다 받은 Monaco 를 버리고 평문 폴백으로 떨어진다.
     *
     * @scenario case=prime_slow_after_loader
     * @effects prime_waits_for_editor_main_once_loader_script_loaded
     */
    it('로더가 받아진 뒤에는 3초가 지나도 editor.main 을 기다려 편집기를 그린다', async () => {
        render(<CodeEditor value="{ }" />);

        const [script] = loaderScripts();
        let finish: ((main: unknown) => void) | null = null;
        const amdRequire: any = vi.fn((_deps: string[], cb: (main: unknown) => void) => {
            finish = cb;
        });
        amdRequire.config = vi.fn();
        (window as any).require = amdRequire;

        await act(async () => {
            script.dispatchEvent(new Event('load'));
            await vi.advanceTimersByTimeAsync(8_000);
        });

        expect(screen.queryByTestId('code-editor-fallback'), '적재 진행 중에 폴백으로 떨어졌다').toBeNull();
        expect(loaderInit).not.toHaveBeenCalled();

        await act(async () => {
            finish?.({ editor: { create: vi.fn() } });
            await vi.advanceTimersByTimeAsync(0);
        });

        expect(loaderInit).toHaveBeenCalledTimes(1);
        expect(loaderScripts()).toHaveLength(1);
        expect(screen.getByTestId('monaco-editor')).toBeInTheDocument();
    });

    /**
     * `editor.main` 적재가 실패하면(AMD errback) 긴 안전 제한을 기다리지 않고 바로 평문 폴백으로 넘어간다.
     *
     * @scenario case=prime_slow_after_loader
     * @effects prime_waits_for_editor_main_once_loader_script_loaded
     */
    it('editor.main 적재가 실패하면 바로 평문 폴백으로 넘어간다', async () => {
        render(<CodeEditor value="{ }" />);

        const [script] = loaderScripts();
        let fail: ((error: unknown) => void) | null = null;
        const amdRequire: any = vi.fn((_deps: string[], _cb: unknown, errback: (error: unknown) => void) => {
            fail = errback;
        });
        amdRequire.config = vi.fn();
        (window as any).require = amdRequire;

        await act(async () => {
            script.dispatchEvent(new Event('load'));
            await vi.advanceTimersByTimeAsync(100);
        });

        await act(async () => {
            fail?.(new Error('Script error for "vs/editor/editor.main"'));
            await vi.advanceTimersByTimeAsync(0);
        });

        expect(loaderInit).not.toHaveBeenCalled();
        expect(loaderScripts()).toHaveLength(1);
        expect(screen.getByTestId('code-editor-fallback')).toBeInTheDocument();
        // 끝난 심기의 60초 안전 제한 타이머는 남지 않는다.
        expect(vi.getTimerCount()).toBe(0);
    });
});
