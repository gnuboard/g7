/**
 * CodeEditor — Monaco 선심기가 제한 시간 안에 끝나지 않았을 때 AMD 로더를 두 번 넣지 않는다
 *
 * 선심기(primeMonaco)는 동봉본 `loader.js` 를 직접 넣고 `editor.main` 을 받아 `window.monaco` 에
 * 심는다. 네트워크가 느려 3초 안에 끝나지 않으면 게이트를 풀고 `loader.init()` 을 부르는데,
 * 라이브러리 `init()` 은 `window.monaco.editor` 가 아직 없으면 **자기 `loader.js` 를 또 넣는다** —
 * 그러면 AMD 로더가 두 번 실행돼 `_amdLoaderGlobal` 재선언으로 그 판의 require 가 통째로 죽는다.
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
});
