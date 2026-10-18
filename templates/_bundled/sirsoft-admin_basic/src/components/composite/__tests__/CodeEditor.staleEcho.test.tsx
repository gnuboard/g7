import React from 'react';
import { act, render } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { CodeEditor } from '../CodeEditor';

/**
 * 빠른 타이핑 중 **뒤늦게 돌아온 내 값**이 편집기를 덮어쓰지 않는다.
 *
 * 편집기는 제어 컴포넌트다 — 친 글자는 `onChange` 로 위로 올라가고, 부모가 상태를 바꿔 다시 그리면
 * `value` 로 되돌아온다. 그 왕복이 끝나기 전에 다음 글자가 들어오면 돌아온 `value` 는 **한두 글자 전의
 * 내 값**이다. 라이브러리(`@monaco-editor/react`)는 모델과 다르다는 이유로 그 옛 값을 통째로 덮어써
 * (전체 범위 교체) 마지막 글자가 사라지고 커서가 문서 끝으로 튄다.
 *
 * 실측 2026-10-06(7.1.0 전수 실측 I1): 3줄 `<span>qa736 probe</span>` 안에 「9876」 을 연달아 치자
 * 모델 변경 기록에 `1:1-6:1` 전체 교체가 끼어들어 「7」 이 사라지고 「6」 이 문서 끝(6:1)에 찍혔다.
 * 579줄 파일에서 「abcdefgh」 를 치면 「a」 만 제자리에 남고 커서가 579줄로 갔다.
 *
 * 이 대역은 Monaco 편집기·모델을 흉내 낸다 — 시험이 모델 값을 직접 바꾸고(사람이 친 것) `onChange` 를
 * 부르며, 바깥 값 반영은 `executeEdits`·`setValue` 로 모델에 들어온다. 판정은 **모델에 남은 값**이다.
 */

/** 대역 모델의 현재 값 */
let modelValue = '';

/** 모델을 통째로 바꾼 호출 기록 */
const replacements: string[] = [];

/** 라이브러리에 넘어간 `value` prop — 넘기면 라이브러리가 effect 에서 그대로 덮어쓴다 */
const libraryValueProps: Array<string | undefined> = [];

/** 마지막 렌더의 onChange — 시험이 「사람이 친 글자」 를 올릴 때 쓴다 */
let emit: ((next: string) => void) | null = null;

const fakeEditor = {
  updateOptions: vi.fn(),
  addCommand: vi.fn(),
  onDidChangeModelContent: vi.fn(),
  pushUndoStop: vi.fn(),
  getOption: vi.fn(() => false),
  setValue: (next: string) => {
    modelValue = next;
    replacements.push(next);
  },
  executeEdits: (_source: string, edits: Array<{ text: string }>) => {
    modelValue = edits[0].text;
    replacements.push(edits[0].text);
    // 실제 모델처럼 변경 알림이 위로 올라간다 — 이 값은 사람이 친 것이 아니다.
    emit?.(modelValue);

    return true;
  },
  getModel: () => ({
    getValue: () => modelValue,
    getLineCount: () => 1,
    getFullModelRange: () => ({}),
    uri: {},
  }),
};

vi.mock('@monaco-editor/react', () => ({
  default: ({ value, defaultValue, onChange, onMount }: any) => {
    libraryValueProps.push(value);
    emit = onChange;

    // 라이브러리(4.7)의 value effect 그대로 — 받은 값이 모델과 다르면 전체 범위를 덮어쓴다.
    const mounted = React.useRef(false);
    React.useEffect(() => {
      if (!mounted.current) {
        mounted.current = true;

        return;
      }
      if (value !== undefined && value !== modelValue) {
        modelValue = value;
        replacements.push(value);
      }
    }, [value]);

    React.useEffect(() => {
      if (modelValue === '') {
        modelValue = defaultValue ?? '';
      }
      onMount?.(fakeEditor, {
        languages: { json: { jsonDefaults: { setDiagnosticsOptions: vi.fn() } } },
        editor: { setModelMarkers: vi.fn(), EditorOption: { readOnly: 1 } },
        MarkerSeverity: { Error: 8, Warning: 4 },
        KeyCode: {},
        KeyMod: {},
      });
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return <div data-testid="monaco-stub" />;
  },
  loader: { init: () => Promise.resolve({}), config: vi.fn() },
}));

/**
 * 사람이 글자를 친다 — 모델이 먼저 바뀌고 편집기가 change 를 올린다.
 *
 * @param next 친 뒤의 모델 값
 */
function type(next: string): void {
  modelValue = next;
  act(() => {
    emit?.(next);
  });
}

beforeEach(() => {
  modelValue = '';
  replacements.length = 0;
  libraryValueProps.length = 0;
  emit = null;
});

describe('CodeEditor — 늦게 돌아온 내 값', () => {
  /** @effects code_editor_ignores_stale_echo_of_own_typing */
  it('모델이 앞서 있는데 한 글자 전 내 값이 돌아오면 그 값으로 덮지 않는다', () => {
    const onChange = vi.fn();
    const { rerender } = render(<CodeEditor value="A" onChange={onChange} />);

    type('AB');
    type('ABC');

    // 부모가 「AB」 를 반영해 다시 그렸다 — 그 사이 모델은 이미 「ABC」 다.
    rerender(<CodeEditor value="AB" onChange={onChange} />);
    expect(modelValue).toBe('ABC');

    // 부모가 따라잡으면 그대로 「ABC」.
    rerender(<CodeEditor value="ABC" onChange={onChange} />);
    expect(modelValue).toBe('ABC');
    expect(replacements).toEqual([]);

    // 라이브러리에는 제어 값을 넘기지 않는다 — 넘기면 라이브러리 effect 가 메아리를 그대로 밀어 넣는다.
    expect(libraryValueProps.every((v) => v === undefined)).toBe(true);
    expect(onChange.mock.calls.map(([event]) => event.target.value)).toEqual(['AB', 'ABC']);
  });

  /** @effects code_editor_applies_external_value */
  it('내가 친 적 없는 값(서버 본문·초안 복원)은 그대로 편집기에 들어가고 위로 다시 올리지 않는다', () => {
    const onChange = vi.fn();
    const { rerender } = render(<CodeEditor value="A" onChange={onChange} />);

    type('AB');
    rerender(<CodeEditor value="AB" onChange={onChange} />);

    rerender(<CodeEditor value="SERVER" onChange={onChange} />);

    expect(modelValue).toBe('SERVER');
    expect(replacements).toEqual(['SERVER']);
    expect(onChange.mock.calls.map(([event]) => event.target.value)).toEqual(['AB']);
  });

  /** @effects code_editor_applies_external_value */
  it('따라잡은 뒤에는 예전에 친 값이라도 바깥에서 오면 적용한다(되돌리기·서버 것으로)', () => {
    const { rerender } = render(<CodeEditor value="A" />);

    type('AB');
    rerender(<CodeEditor value="AB" />);
    type('ABC');
    rerender(<CodeEditor value="ABC" />);

    // 부모가 내 예전 값 「AB」 로 되돌린다(예: 충돌에서 「서버 것으로」 를 골랐는데 서버 본문이 그 값).
    rerender(<CodeEditor value="AB" />);

    expect(modelValue).toBe('AB');
  });

  /** @effects code_editor_applies_external_value */
  it('읽기 전용 편집기(JSON 탭)는 setValue 로 바깥 값을 받는다', () => {
    const { rerender } = render(<CodeEditor value="{}" readOnly />);

    rerender(<CodeEditor value='{"a":1}' readOnly />);

    expect(modelValue).toBe('{"a":1}');
  });
});
