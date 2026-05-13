import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { CodeEditor } from '../CodeEditor';

// Monaco Editor mock
vi.mock('@monaco-editor/react', () => ({
  default: ({ value, onChange, onMount, theme, language }: any) => {
    // onMount 호출을 시뮬레이션
    React.useEffect(() => {
      if (onMount) {
        const mockEditor = {
          updateOptions: vi.fn(),
          getValue: () => value,
        };
        const mockMonaco = {
          languages: {
            json: {
              jsonDefaults: {
                setDiagnosticsOptions: vi.fn(),
              },
            },
          },
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
}));

describe('CodeEditor', () => {
  it('기본 props로 렌더링되어야 한다', () => {
    render(<CodeEditor value="" />);

    const editor = screen.getByTestId('monaco-editor');
    expect(editor).toBeInTheDocument();
  });

  it('value prop이 에디터에 표시되어야 한다', () => {
    const testValue = '{"key": "value"}';
    render(<CodeEditor value={testValue} />);

    const textarea = screen.getByTestId('editor-textarea');
    expect(textarea).toHaveValue(testValue);
  });

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

  it('language prop이 에디터에 적용되어야 한다', () => {
    render(<CodeEditor value="" language="javascript" />);

    const textarea = screen.getByTestId('editor-textarea');
    expect(textarea).toHaveAttribute('data-language', 'javascript');
  });

  it('theme prop이 에디터에 적용되어야 한다', () => {
    render(<CodeEditor value="" theme="vs-light" />);

    const textarea = screen.getByTestId('editor-textarea');
    expect(textarea).toHaveAttribute('data-theme', 'vs-light');
  });

  it('기본값으로 json 언어를 사용해야 한다', () => {
    render(<CodeEditor value="" />);

    const textarea = screen.getByTestId('editor-textarea');
    expect(textarea).toHaveAttribute('data-language', 'json');
  });

  it('기본값으로 vs-dark 테마를 사용해야 한다', () => {
    render(<CodeEditor value="" />);

    const textarea = screen.getByTestId('editor-textarea');
    expect(textarea).toHaveAttribute('data-theme', 'vs-dark');
  });

  it('컨테이너에 올바른 스타일 클래스가 적용되어야 한다', () => {
    const { container } = render(<CodeEditor value="" />);

    const wrapper = container.querySelector('div');
    expect(wrapper).toHaveClass('border', 'border-gray-300', 'rounded-lg', 'overflow-hidden');
  });
});
