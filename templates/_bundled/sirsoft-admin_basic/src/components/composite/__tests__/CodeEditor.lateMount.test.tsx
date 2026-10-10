import React from 'react';
import { act, render } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { CodeEditor } from '../CodeEditor';

/**
 * 옵션은 **편집기가 생긴 뒤에도** 한 번 더 적용돼야 한다.
 *
 * Monaco 는 비동기로 실린다. 그 사이 `readOnly` 가 바뀌면 반영 effect 는 아직 없는 편집기에 대고
 * 돌아 아무 일도 하지 않고, 뒤늦게 끝난 마운트가 **그때 캡처한 옵션**을 덮어쓴다 —
 * 실측 2026-09-12: 권한 없는 계정에서 `readOnly` prop 은 true 인데 Monaco 는 false 라
 * 편집기에 그대로 타이핑이 됐다(11,467 → 11,471자). 저장은 서버가 403 으로 막지만, 저장할 수
 * 없는 편집을 화면이 권하는 셈이라 그 자체가 결함이다.
 *
 * 이 파일의 대역은 **첫 렌더의 `onMount` 를 붙들었다가 나중에 부른다** — 라이브러리가 콜백을
 * 마운트 시점까지 들고 있는 모습 그대로다. 최신 `onMount` 를 부르는 대역으로는 이 결함이
 * 재현되지 않는다(마운트 핸들러가 그때의 옵션을 스스로 넣어 주기 때문).
 */

/** 편집기 대역이 받은 `updateOptions` 호출 기록 */
const updateOptions = vi.fn();

/** 시험이 원하는 시점에 마운트를 일으키는 손잡이 */
let triggerMount: (() => void) | null = null;

/** 첫 렌더의 onMount 만 붙든다 — 라이브러리가 콜백을 마운트 시점까지 들고 있는 모습 그대로다. */
let capturedOnMount: ((editor: unknown, monacoInstance: unknown) => void) | null = null;

vi.mock('@monaco-editor/react', () => ({
  default: ({ onMount }: any) => {
    if (!capturedOnMount) {
      capturedOnMount = onMount;
    }

    triggerMount = () => {
      capturedOnMount?.(
        {
          updateOptions,
          getModel: () => null,
          getValue: () => '',
        },
        {
          languages: { json: { jsonDefaults: { setDiagnosticsOptions: vi.fn() } } },
          editor: { setModelMarkers: vi.fn() },
          MarkerSeverity: { Error: 8, Warning: 4 },
        },
      );
    };

    return <div data-testid="monaco-stub" />;
  },
  loader: { init: () => Promise.resolve({}), config: vi.fn() },
}));

describe('CodeEditor — 늦은 마운트와 옵션 반영', () => {
  /** @effects code_editor_reapplies_options_after_late_mount */
  it('마운트가 옵션 변경보다 늦어도 현재 옵션(readOnly)이 적용된다', () => {
    updateOptions.mockClear();
    capturedOnMount = null;

    const { rerender } = render(<CodeEditor value="x" readOnly={false} />);

    // 권한 정보가 늦게 도착해 readOnly 가 뒤집힌다 — 아직 Monaco 는 실리지 않았다.
    rerender(<CodeEditor value="x" readOnly />);

    updateOptions.mockClear();

    // 이제서야 Monaco 가 실린다. (마운트가 일으키는 상태 변경까지 흘려보낸다)
    act(() => {
      triggerMount?.();
    });

    const seen = updateOptions.mock.calls.map(([options]) => options?.readOnly);

    // 붙들려 있던 마운트가 옛 옵션(false)을 먼저 넣는 것 자체는 막을 수 없다.
    // 요구는 **마지막에 남는 값**이 현재 prop 과 같아야 한다는 것이다.
    expect(seen.length).toBeGreaterThan(0);
    expect(seen[seen.length - 1]).toBe(true);
  });
});
