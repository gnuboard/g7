import React from 'react';
import { act, render } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { CodeEditor } from '../CodeEditor';

/**
 * 검색 결과 줄을 누르면 **그 파일의 그 줄**로 가야 한다.
 *
 * 결과 줄 클릭은 파일 전환(`modelKey`)과 좌표(`revealAt`)를 같은 순간에 보낸다. 그런데 본문은
 * 서버에서 뒤늦게 온다 — 그 사이에 `setPosition(19)` 을 부르면 Monaco 가 자기가 가진 줄 수
 * (빈 모델이면 1)로 잘라 버리고, 뒤늦게 선 본문은 커서를 되돌려 주지 않는다.
 *
 * 브라우저 실측(2026-09-12, `code-editor-search.spec.ts:104`): 기대 19 / 실제 1.
 *
 * 여기서 잠그는 것은 두 가지다 — ⓐ 본문이 그 줄에 닿기 전에는 옮기지 않는다,
 * ⓑ 본문이 서면 그때 옮긴다. 그리고 ⓒ 한 번 옮긴 좌표로 되끌지 않는다(사용자가 커서를
 * 옮긴 뒤 타이핑하면 effect 가 다시 도는데, 그때마다 결과 줄로 튕기면 편집을 할 수 없다).
 */

/** 편집기 대역이 받은 `setPosition` 호출 기록 */
const setPosition = vi.fn();
const revealPositionInCenter = vi.fn();

/** 대역 모델이 들고 있다고 답할 줄 수 — 본문 도착을 흉내 낸다 */
let lineCount = 1;

vi.mock('@monaco-editor/react', () => ({
  default: ({ onMount }: any) => {
    // 라이브러리는 마운트 때 편집기를 한 번 건네준다.
    React.useEffect(() => {
      onMount?.(
        {
          updateOptions: vi.fn(),
          setPosition,
          revealPositionInCenter,
          focus: vi.fn(),
          getValue: () => '',
          getModel: () => ({
            getLineCount: () => lineCount,
            getEOL: () => '\n',
          }),
        },
        {
          languages: { json: { jsonDefaults: { setDiagnosticsOptions: vi.fn() } } },
          editor: { setModelMarkers: vi.fn() },
          MarkerSeverity: { Error: 8, Warning: 4 },
        },
      );
      // 대역은 마운트를 한 번만 일으킨다 — 실제 라이브러리와 같다.
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return <div data-testid="monaco-stub" />;
  },
  loader: { init: () => Promise.resolve({}), config: vi.fn() },
}));

describe('CodeEditor — 검색 결과 좌표 이동', () => {
  beforeEach(() => {
    setPosition.mockClear();
    revealPositionInCenter.mockClear();
    lineCount = 1;
  });

  /** @effects code_editor_defers_reveal_until_the_target_file_content_arrives */
  it('본문이 오기 전에는 커서를 옮기지 않고, 본문이 서면 그 줄로 옮긴다', () => {
    // 다른 파일의 19번째 줄을 눌렀다 — 본문은 아직 없다(줄 수 1).
    const { rerender } = render(
      <CodeEditor value="" modelKey="layout:other" revealAt={{ line: 19, column: 3 }} />,
    );

    expect(
      setPosition.mock.calls.map(([p]) => p?.lineNumber),
      '본문이 없는데 커서를 옮기면 19줄이 1줄로 잘려 굳는다',
    ).not.toContain(19);

    // 서버 응답이 도착했다.
    lineCount = 40;
    act(() => {
      rerender(<CodeEditor value={'x\n'.repeat(40)} modelKey="layout:other" revealAt={{ line: 19, column: 3 }} />);
    });

    expect(setPosition).toHaveBeenCalledWith({ lineNumber: 19, column: 3 });
    expect(revealPositionInCenter).toHaveBeenCalledWith({ lineNumber: 19, column: 3 });
  });

  /** @effects code_editor_does_not_re_reveal_the_same_coordinate_while_typing */
  it('같은 좌표로는 다시 옮기지 않는다 (타이핑이 결과 줄로 되끌리지 않는다)', () => {
    lineCount = 40;

    const { rerender } = render(
      <CodeEditor value={'x\n'.repeat(40)} modelKey="layout:other" revealAt={{ line: 19, column: 3 }} />,
    );

    expect(setPosition).toHaveBeenCalledTimes(1);

    // 사용자가 커서를 다른 곳에 두고 타이핑한다 — value 만 바뀐다.
    act(() => {
      rerender(
        <CodeEditor value={'x\n'.repeat(40) + 'y'} modelKey="layout:other" revealAt={{ line: 19, column: 3 }} />,
      );
    });

    expect(setPosition, '같은 좌표로 되끌면 편집을 할 수 없다').toHaveBeenCalledTimes(1);
  });
});
