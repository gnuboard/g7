/**
 * CodeEditor — 모델 줄 수를 넘는 서버 마커가 편집 영역을 죽이지 않는다
 *
 * 서버 컴파일 마커(`markup_warnings`·`compile_error`)는 새 문서의 줄 좌표다. 마커가 모델에 내용이
 * 들어오기 전에 도착하면(빈 모델·직전 문서), `getLineMaxColumn(13)` 이 Monaco 의
 * 「Illegal value for lineNumber」 예외를 던지고 그 예외가 렌더 경계까지 올라가 코드 작업 영역
 * 전체가 그려지지 않았다(2026-10-01 실측: branroom 조각 행 열기 → `CodeWorkspace` 렌더 오류).
 *
 * 범위 밖 마커는 찍지 않고 보류했다가, 모델 내용이 바뀌어 그 줄이 생기면 다시 찍는다. 모델이 이미 지금 받은 값을
 * 담고 있는데도 넘는 마커(파일 끝 다음 줄을 가리키는 오류 등)는 마지막 줄로 접어 찍는다 — 보류하면 끝내 보이지
 * 않고 타이핑마다 다시 찍혀 편집을 따라 옮긴 마커 위치까지 서버 좌표로 되돌아간다.
 *
 * 트러블슈팅 `[case:components-misc-codeeditor-marker-line-range]` 의 회귀 그물이다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup, act } from '@testing-library/react';

/** 테스트가 조작하는 가짜 모델·편집기·Monaco */
const fake = vi.hoisted(() => {
    const state = {
        lineCount: 1,
        content: 'OLD',
        contentListeners: [] as Array<() => void>,
        setModelMarkers: null as unknown as ReturnType<typeof import('vitest').vi.fn>,
    };

    return state;
});

vi.mock('@monaco-editor/react', () => {
    const model = {
        getLineCount: () => fake.lineCount,
        getValue: () => fake.content,
        getLineMaxColumn: (line: number) => {
            if (line < 1 || line > fake.lineCount) {
                throw new Error('Illegal value for lineNumber');
            }

            return 40;
        },
        setEOL: () => {},
    };

    const editor = {
        getModel: () => model,
        updateOptions: () => {},
        addCommand: () => {},
        onDidChangeModelContent: (listener: () => void) => {
            fake.contentListeners.push(listener);

            return { dispose: () => {} };
        },
    };

    const monaco = {
        editor: { setModelMarkers: (...args: unknown[]) => fake.setModelMarkers(...args) },
        MarkerSeverity: { Error: 8, Warning: 4 },
        KeyMod: { CtrlCmd: 2048, Shift: 1024, Alt: 512 },
        KeyCode: {},
    };

    const Editor = ({ onMount }: any) => {
        React.useEffect(() => {
            onMount?.(editor, monaco);
        }, []);

        return <div data-testid="monaco-editor" />;
    };

    return {
        __esModule: true,
        default: Editor,
        loader: { init: () => Promise.resolve(monaco), config: vi.fn() },
    };
});

import { CodeEditor } from '../../src/components/composite/CodeEditor';

/** 마지막 setModelMarkers 호출이 찍은 시작 줄 목록 */
function lastMarkedLines(): number[] {
    const calls = fake.setModelMarkers.mock.calls;
    const markers = (calls[calls.length - 1]?.[2] ?? []) as Array<{ startLineNumber: number }>;

    return markers.map((m) => m.startLineNumber);
}

describe('CodeEditor 서버 마커 줄 범위', () => {
    beforeEach(() => {
        fake.lineCount = 1;
        fake.content = 'OLD';
        fake.contentListeners = [];
        fake.setModelMarkers = vi.fn();
        (window as any).G7Core = {
            asset: { templateDir: (id: string, p: string) => `/api/templates/assets/${id}/${p}` },
            assets: { notifyFailure: vi.fn(), clearFailure: vi.fn() },
        };
        (window as any).monaco = { editor: { create: vi.fn() } };
    });

    afterEach(() => {
        cleanup();
        delete (window as any).G7Core;
        delete (window as any).monaco;
        vi.restoreAllMocks();
    });

    /**
     * 모델이 아직 1줄인데 13·14행 마커가 오면 예외 없이 보류하고, 내용이 들어오면 그 줄에 찍는다.
     *
     * @scenario case=marker_beyond_model_lines
     * @effects marker_beyond_model_lines_is_deferred_not_thrown
     */
    it('모델 줄 수를 넘는 마커는 예외 없이 보류했다가 내용이 들어오면 찍는다', async () => {
        const markers = [
            { line: 13, column: 11, message: 'Label', severity: 'warning' as const },
            { line: 14, column: 13, message: 'Checkbox', severity: 'warning' as const },
        ];

        await act(async () => {
            render(<CodeEditor value="" markers={markers} />);
        });

        expect(lastMarkedLines()).toEqual([]);

        fake.lineCount = 66;
        await act(async () => {
            fake.contentListeners.forEach((listener) => listener());
        });

        expect(lastMarkedLines()).toEqual([13, 14]);
    });

    /**
     * 모델이 이미 받은 값을 담고 있는데 마지막 줄 다음을 가리키는 마커는 마지막 줄로 접어 바로 찍고, 타이핑마다 다시
     * 찍지 않는다.
     *
     * @scenario case=marker_beyond_model_lines
     * @effects marker_beyond_model_lines_is_deferred_not_thrown
     */
    it('받은 값이 이미 모델에 있으면 넘는 마커는 마지막 줄로 접는다', async () => {
        fake.lineCount = 5;
        fake.content = ['a', 'b', 'c', 'd', 'e'].join('\n');

        await act(async () => {
            render(<CodeEditor value={fake.content} markers={[{ line: 6, column: 1, message: 'EOF', severity: 'error' }]} />);
        });

        expect(lastMarkedLines()).toEqual([5]);

        const callsAfterMount = fake.setModelMarkers.mock.calls.length;
        await act(async () => {
            fake.contentListeners.forEach((listener) => listener());
        });

        expect(fake.setModelMarkers.mock.calls.length).toBe(callsAfterMount);
    });

    /**
     * 범위 안의 마커는 종전대로 바로 찍고, 내용이 바뀌어도 다시 찍지 않는다(편집을 따라 움직인 위치를 되돌리지 않는다).
     *
     * @scenario case=marker_beyond_model_lines
     * @effects marker_beyond_model_lines_is_deferred_not_thrown
     */
    it('범위 안의 마커는 바로 찍고 타이핑마다 다시 찍지 않는다', async () => {
        fake.lineCount = 66;

        await act(async () => {
            render(<CodeEditor value="x" markers={[{ line: 13, column: 1, message: 'm', severity: 'warning' }]} />);
        });

        const callsAfterMount = fake.setModelMarkers.mock.calls.length;
        expect(lastMarkedLines()).toEqual([13]);

        await act(async () => {
            fake.contentListeners.forEach((listener) => listener());
        });

        expect(fake.setModelMarkers.mock.calls.length).toBe(callsAfterMount);
    });
});
