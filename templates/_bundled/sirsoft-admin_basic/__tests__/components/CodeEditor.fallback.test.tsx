/**
 * CodeEditor — Monaco 확보 실패 시의 평문 폴백
 *
 * 편집기가 없다고 화면을 비워 두면 레이아웃을 고칠 방법이 사라진다. 저장 계약
 * (값·onChange)이 같은 textarea 로 대체하고, 사용자에게 사실을 알린다.
 *
 * @scenario asset_class=vendored, outcome=failed
 * @effects failed_asset_falls_back_to_plain_input, failed_asset_shows_retry_notice
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen, fireEvent, waitFor, cleanup } from '@testing-library/react';

/** loader.init() 결과를 테스트마다 갈아끼우기 위한 제어점 */
const loaderState: { init: () => Promise<unknown>; config: ReturnType<typeof vi.fn> } = {
    init: () => Promise.resolve({}),
    config: vi.fn(),
};

vi.mock('@monaco-editor/react', () => ({
    __esModule: true,
    default: ({ value }: any) => <div data-testid="monaco-editor">{value}</div>,
    loader: {
        init: () => loaderState.init(),
        config: (...args: unknown[]) => loaderState.config(...args),
    },
}));

import { CodeEditor } from '../../src/components/composite/CodeEditor';

describe('CodeEditor Monaco 폴백', () => {
    let notifyFailure: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        notifyFailure = vi.fn();
        loaderState.config = vi.fn();
        loaderState.init = () => Promise.resolve({});
        (window as any).G7Core = {
            asset: { templateDir: vi.fn((id: string, path: string) => `/api/templates/assets/${id}/${path}`) },
            assets: { notifyFailure, clearFailure: vi.fn() },
        };
    });

    afterEach(() => {
        cleanup();
        delete (window as any).G7Core;
        vi.restoreAllMocks();
    });

    it('Monaco 로더를 동봉본 디렉토리로 설정한다 (외부 CDN 미사용)', () => {
        render(<CodeEditor value="{}" />);

        expect(loaderState.config).toHaveBeenCalled();

        const [{ paths }] = loaderState.config.mock.calls[0] as any[];

        expect(paths.vs).toContain('vendor/monaco-editor/0.56.0/vs');
        expect(paths.vs).not.toMatch(/^https?:/);
    });

    it('확보에 성공하면 편집기를 렌더한다', async () => {
        render(<CodeEditor value="{ }" />);

        await waitFor(() => {
            expect(screen.getByTestId('monaco-editor')).toBeInTheDocument();
        });

        expect(screen.queryByTestId('code-editor-fallback')).toBeNull();
    });

    it('확보에 실패하면 textarea 로 대체하고 안내한다', async () => {
        loaderState.init = () => Promise.reject(new Error('load failed'));

        render(<CodeEditor value='{"a":1}' />);

        const textarea = await screen.findByTestId('code-editor-fallback');

        expect(textarea).toBeInTheDocument();
        expect((textarea as HTMLTextAreaElement).value).toBe('{"a":1}');
        expect(screen.queryByTestId('monaco-editor')).toBeNull();
        expect(notifyFailure).toHaveBeenCalledTimes(1);
        expect(notifyFailure.mock.calls[0][0].id).toBe('monaco-editor');
    });

    it('폴백에서도 저장 계약(onChange 이벤트 모양)이 같다', async () => {
        loaderState.init = () => Promise.reject(new Error('load failed'));
        const onChange = vi.fn();

        render(<CodeEditor value="{}" onChange={onChange} />);

        const textarea = await screen.findByTestId('code-editor-fallback');
        fireEvent.change(textarea, { target: { value: '{"changed":true}' } });

        expect(onChange).toHaveBeenCalledWith({ target: { value: '{"changed":true}' } });
    });

    it('readOnly 설정은 폴백에도 적용된다', async () => {
        loaderState.init = () => Promise.reject(new Error('load failed'));

        render(<CodeEditor value="{}" readOnly />);

        const textarea = await screen.findByTestId('code-editor-fallback');

        expect((textarea as HTMLTextAreaElement).readOnly).toBe(true);
    });

    it('폴백에서 탭은 들여쓰기로 들어간다 (포커스 이동 금지)', async () => {
        loaderState.init = () => Promise.reject(new Error('load failed'));
        const onChange = vi.fn();

        render(<CodeEditor value="ab" onChange={onChange} />);

        const textarea = (await screen.findByTestId('code-editor-fallback')) as HTMLTextAreaElement;
        textarea.selectionStart = 1;
        textarea.selectionEnd = 1;
        fireEvent.keyDown(textarea, { key: 'Tab' });

        expect(onChange).toHaveBeenCalledWith({ target: { value: 'a  b' } });
    });
});
