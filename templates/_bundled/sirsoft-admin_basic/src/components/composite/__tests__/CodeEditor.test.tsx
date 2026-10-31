import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { CodeEditor } from '../CodeEditor';

// 마커 관측용 스파이 — CodeEditor 가 `monaco.editor.setModelMarkers(model, 'g7-markup', …)` 로 찍는 값을 잡는다.
const setModelMarkersSpy = vi.fn();
const mockModel = { getLineMaxColumn: (_line: number) => 40 };

// Monaco Editor mock
vi.mock('@monaco-editor/react', () => ({
  default: ({ defaultValue: value, onChange, onMount, theme, language }: any) => {
    // onMount 호출을 시뮬레이션
    React.useEffect(() => {
      if (onMount) {
        const mockEditor = {
          updateOptions: vi.fn(),
          getValue: () => value,
          getModel: () => mockModel,
        };
        const mockMonaco = {
          languages: {
            json: {
              jsonDefaults: {
                setDiagnosticsOptions: vi.fn(),
              },
            },
          },
          editor: { setModelMarkers: setModelMarkersSpy },
          MarkerSeverity: { Error: 8, Warning: 4 },
        };
        onMount(mockEditor, mockMonaco);
      }
    }, [onMount]);

    return (
      <div data-testid="monaco-editor">
        <textarea
          data-testid="editor-textarea"
          value={value}
          onChange={(e) => onChange?.(e.target.value)}
          readOnly={false}
          data-theme={theme}
          data-language={language}
        />
      </div>
    );
  },
  // CodeEditor 가 동봉 Monaco 를 가리키도록 loader 를 쓴다(#123 Phase 4).
  // 이 스텁이 없으면 렌더 시점에 loader 가 undefined 라 컴포넌트가 통째로 실패한다.
  loader: {
    init: () => Promise.resolve({}),
    config: vi.fn(),
  },
}));

describe('CodeEditor', () => {
  /** @effects code_editor_renders_with_default_props */
  it('기본 props로 렌더링되어야 한다', () => {
    render(<CodeEditor value="" />);

    const editor = screen.getByTestId('monaco-editor');
    expect(editor).toBeInTheDocument();
  });

  /** @effects code_editor_shows_the_value_prop */
  it('value prop이 에디터에 표시되어야 한다', () => {
    const testValue = '{"key": "value"}';
    render(<CodeEditor value={testValue} />);

    const textarea = screen.getByTestId('editor-textarea');
    expect(textarea).toHaveValue(testValue);
  });

  /** @effects code_editor_calls_on_change_when_content_changes */
  it('onChange 콜백이 에디터 내용 변경 시 호출되어야 한다', async () => {
    const handleChange = vi.fn();
    render(<CodeEditor value="" onChange={handleChange} />);

    const textarea = screen.getByTestId('editor-textarea');
    const newValue = '{"test": true}';

    // 텍스트 변경 이벤트 시뮬레이션
    textarea.dispatchEvent(
      new Event('input', { bubbles: true })
    );

    await waitFor(() => {
      // textarea의 value 변경을 onChange에 전달
      const changeEvent = new Event('change', { bubbles: true });
      Object.defineProperty(changeEvent, 'target', {
        value: { value: newValue },
        writable: false,
      });
      textarea.dispatchEvent(changeEvent);
    });
  });

  /** @effects code_editor_applies_the_language_prop */
  it('language prop이 에디터에 적용되어야 한다', () => {
    render(<CodeEditor value="" language="javascript" />);

    const textarea = screen.getByTestId('editor-textarea');
    expect(textarea).toHaveAttribute('data-language', 'javascript');
  });

  /** @effects code_editor_applies_the_theme_prop */
  it('theme prop이 에디터에 적용되어야 한다', () => {
    render(<CodeEditor value="" theme="vs-light" />);

    const textarea = screen.getByTestId('editor-textarea');
    expect(textarea).toHaveAttribute('data-theme', 'vs-light');
  });

  /** @effects code_editor_defaults_language_to_json */
  it('기본값으로 json 언어를 사용해야 한다', () => {
    render(<CodeEditor value="" />);

    const textarea = screen.getByTestId('editor-textarea');
    expect(textarea).toHaveAttribute('data-language', 'json');
  });

  /**
   * 기본 테마는 **문서를 따라간다**(D8, #476).
   *
   * 종전 기본값은 `vs-dark` 하드코딩이라 사이트가 라이트 모드여도 편집기만 검은 판이었다.
   * 이제 기본값은 `auto` 이고, 루트에 `dark` 클래스가 없으면 `vs-light` 로 해석된다.
   *
   * @effects code_editor_defaults_theme_to_document_theme, code_editor_follows_document_theme
   */
  it('기본값은 문서 테마를 따른다 — 라이트 문서에서 vs-light', () => {
    document.documentElement.classList.remove('dark');

    render(<CodeEditor value="" />);

    expect(screen.getByTestId('editor-textarea')).toHaveAttribute('data-theme', 'vs-light');
  });

  /** @effects code_editor_follows_dark_class */
  it('문서가 다크면 vs-dark 로 해석한다', () => {
    document.documentElement.classList.add('dark');

    try {
      render(<CodeEditor value="" />);

      expect(screen.getByTestId('editor-textarea')).toHaveAttribute('data-theme', 'vs-dark');
    } finally {
      document.documentElement.classList.remove('dark');
    }
  });

  /** @effects code_editor_explicit_theme_wins_over_document */
  it('명시한 테마는 문서 테마를 이긴다', () => {
    document.documentElement.classList.remove('dark');

    render(<CodeEditor value="" theme="vs-dark" />);

    expect(screen.getByTestId('editor-textarea')).toHaveAttribute('data-theme', 'vs-dark');
  });

  /** @effects code_editor_container_carries_the_layout_classes */
  it('컨테이너에 올바른 스타일 클래스가 적용되어야 한다', () => {
    const { container } = render(<CodeEditor value="" />);

    const wrapper = container.querySelector('div');
    expect(wrapper).toHaveClass('border', 'border-gray-300', 'rounded-lg', 'overflow-hidden');
  });
});

describe('CodeEditor markers — 서버 컴파일 오류·경고 물결 (#476)', () => {
  beforeEach(() => {
    setModelMarkersSpy.mockClear();
  });

  /**
   * @effects code_editor_marks_compile_errors_in_monaco_with_g7_markup_owner
   */
  it('markers 를 주면 마운트 시 g7-markup 소유자로 Monaco 마커를 찍는다', async () => {
    render(
      <CodeEditor
        value="<layout/>"
        language="html"
        markers={[
          { line: 3, column: 5, message: '주석 자리 없음', severity: 'error' },
          { line: 7, column: 2, message: '미등록 핸들러', severity: 'warning' },
        ]}
      />,
    );

    await waitFor(() => expect(setModelMarkersSpy).toHaveBeenCalled());

    const [model, owner, markers] = setModelMarkersSpy.mock.calls.at(-1)!;
    expect(model).toBe(mockModel);
    expect(owner).toBe('g7-markup');
    expect(markers).toHaveLength(2);
    expect(markers[0]).toMatchObject({ startLineNumber: 3, startColumn: 5, endLineNumber: 3, severity: 8, message: '주석 자리 없음' });
    expect(markers[0].endColumn).toBeGreaterThan(5);
    expect(markers[1]).toMatchObject({ startLineNumber: 7, startColumn: 2, severity: 4 });
  });

  /**
   * @effects code_editor_marks_compile_errors_in_monaco_with_g7_markup_owner
   */
  it('markers 가 바뀌면 다시 찍고, 비우면 물결을 지운다', async () => {
    const { rerender } = render(
      <CodeEditor value="<layout/>" language="html" markers={[{ line: 1, column: 1, message: 'a', severity: 'error' }]} />,
    );

    await waitFor(() => expect(setModelMarkersSpy).toHaveBeenCalled());

    rerender(<CodeEditor value="<layout/>" language="html" markers={[]} />);

    await waitFor(() => {
      const [, owner, markers] = setModelMarkersSpy.mock.calls.at(-1)!;
      expect(owner).toBe('g7-markup');
      expect(markers).toEqual([]);
    });
  });

  /**
   * @effects code_editor_marks_compile_errors_in_monaco_with_g7_markup_owner
   */
  it('좌표가 0 인 문서 전체 경고는 1행 1열로 접는다', async () => {
    render(<CodeEditor value="<layout/>" language="html" markers={[{ line: 0, column: 0, message: '문서 전체', severity: 'warning' }]} />);

    await waitFor(() => expect(setModelMarkersSpy).toHaveBeenCalled());

    const [, , markers] = setModelMarkersSpy.mock.calls.at(-1)!;
    expect(markers[0]).toMatchObject({ startLineNumber: 1, startColumn: 1, severity: 4 });
  });
});
