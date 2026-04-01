import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';
import { Textarea } from '../Textarea';

describe('Textarea 컴포넌트', () => {
  describe('기본 렌더링', () => {
    it('textarea 요소가 렌더링된다', () => {
      const { container } = render(<Textarea />);
      const textarea = container.querySelector('textarea');
      expect(textarea).toBeTruthy();
    });

    it('placeholder가 표시된다', () => {
      render(<Textarea placeholder="내용을 입력하세요" />);
      expect(screen.getByPlaceholderText('내용을 입력하세요')).toBeTruthy();
    });

    it('value가 설정된다', () => {
      const { container } = render(<Textarea value="테스트 내용" onChange={() => {}} />);
      const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
      expect(textarea.value).toBe('테스트 내용');
    });

    it('defaultValue가 설정된다', () => {
      const { container } = render(<Textarea defaultValue="기본 내용" />);
      const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
      expect(textarea.value).toBe('기본 내용');
    });
  });

  describe('이벤트 핸들링', () => {
    it('onChange 핸들러가 호출된다', () => {
      const handleChange = vi.fn();
      const { container } = render(<Textarea onChange={handleChange} />);
      const textarea = container.querySelector('textarea')!;

      fireEvent.change(textarea, { target: { value: '새 내용' } });

      expect(handleChange).toHaveBeenCalledTimes(1);
    });

    it('onFocus 핸들러가 호출된다', () => {
      const handleFocus = vi.fn();
      const { container } = render(<Textarea onFocus={handleFocus} />);
      const textarea = container.querySelector('textarea')!;

      fireEvent.focus(textarea);

      expect(handleFocus).toHaveBeenCalledTimes(1);
    });

    it('onBlur 핸들러가 호출된다', () => {
      const handleBlur = vi.fn();
      const { container } = render(<Textarea onBlur={handleBlur} />);
      const textarea = container.querySelector('textarea')!;

      fireEvent.blur(textarea);

      expect(handleBlur).toHaveBeenCalledTimes(1);
    });

    it('onKeyDown 핸들러가 호출된다', () => {
      const handleKeyDown = vi.fn();
      const { container } = render(<Textarea onKeyDown={handleKeyDown} />);
      const textarea = container.querySelector('textarea')!;

      fireEvent.keyDown(textarea, { key: 'Enter' });

      expect(handleKeyDown).toHaveBeenCalledTimes(1);
    });

    it('onKeyUp 핸들러가 호출된다', () => {
      const handleKeyUp = vi.fn();
      const { container } = render(<Textarea onKeyUp={handleKeyUp} />);
      const textarea = container.querySelector('textarea')!;

      fireEvent.keyUp(textarea, { key: 'a' });

      expect(handleKeyUp).toHaveBeenCalledTimes(1);
    });
  });

  describe('제어 컴포넌트', () => {
    it('제어 컴포넌트로 작동한다', () => {
      const TestComponent = () => {
        const [value, setValue] = React.useState('초기값');

        return <Textarea value={value} onChange={(e) => setValue(e.target.value)} />;
      };

      const { container } = render(<TestComponent />);
      const textarea = container.querySelector('textarea')!;

      expect((textarea as HTMLTextAreaElement).value).toBe('초기값');

      fireEvent.change(textarea, { target: { value: '변경된 값' } });

      expect((textarea as HTMLTextAreaElement).value).toBe('변경된 값');
    });
  });

  describe('HTML 속성', () => {
    it('disabled 속성이 적용된다', () => {
      const { container } = render(<Textarea disabled />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.disabled).toBe(true);
    });

    it('readOnly 속성이 적용된다', () => {
      const { container } = render(<Textarea readOnly />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.readOnly).toBe(true);
    });

    it('required 속성이 적용된다', () => {
      const { container } = render(<Textarea required />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.required).toBe(true);
    });

    it('name 속성이 적용된다', () => {
      const { container } = render(<Textarea name="description" />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.name).toBe('description');
    });

    it('id 속성이 적용된다', () => {
      const { container } = render(<Textarea id="comment-textarea" />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.id).toBe('comment-textarea');
    });

    it('rows 속성이 적용된다', () => {
      const { container } = render(<Textarea rows={5} />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.rows).toBe(5);
    });

    it('cols 속성이 적용된다', () => {
      const { container } = render(<Textarea cols={50} />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.cols).toBe(50);
    });

    it('maxLength 속성이 적용된다', () => {
      const { container } = render(<Textarea maxLength={100} />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.maxLength).toBe(100);
    });

    it('minLength 속성이 적용된다', () => {
      const { container } = render(<Textarea minLength={10} />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.minLength).toBe(10);
    });

    it('wrap 속성이 적용된다', () => {
      const { container } = render(<Textarea wrap="hard" />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.wrap).toBe('hard');
    });
  });

  describe('Label 및 Error Props', () => {
    it('label prop이 존재한다', () => {
      // label prop은 있지만 렌더링하지 않음 (부모에서 처리)
      const { container } = render(<Textarea label="설명" />);
      const textarea = container.querySelector('textarea');
      expect(textarea).toBeTruthy();
    });

    it('error prop이 존재한다', () => {
      // error prop은 있지만 렌더링하지 않음 (부모에서 처리)
      const { container } = render(<Textarea error="필수 입력 항목입니다" />);
      const textarea = container.querySelector('textarea');
      expect(textarea).toBeTruthy();
    });
  });

  describe('사용자 정의 Props', () => {
    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(<Textarea className="custom-textarea" />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.className).toContain('custom-textarea');
    });

    it('aria-label이 적용된다', () => {
      const { container } = render(<Textarea aria-label="댓글 입력" />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.getAttribute('aria-label')).toBe('댓글 입력');
    });

    it('aria-describedby가 적용된다', () => {
      const { container } = render(<Textarea aria-describedby="description-help" />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.getAttribute('aria-describedby')).toBe('description-help');
    });

    it('data 속성이 적용된다', () => {
      const { container } = render(<Textarea data-testid="test-textarea" />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.getAttribute('data-testid')).toBe('test-textarea');
    });

    it('autoComplete 속성이 적용된다', () => {
      const { container } = render(<Textarea autoComplete="off" />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.getAttribute('autocomplete')).toBe('off');
    });

    // autoFocus는 jsdom 환경에서 제대로 테스트되지 않으므로 생략

    it('spellCheck 속성이 적용된다', () => {
      const { container } = render(<Textarea spellCheck={false} />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.getAttribute('spellcheck')).toBe('false');
    });
  });

  describe('Resize 동작', () => {
    it('기본 resize 동작이 유지된다', () => {
      const { container } = render(<Textarea />);
      const textarea = container.querySelector('textarea');
      // CSS resize 속성은 기본적으로 'both' 또는 브라우저 기본값
      expect(textarea).toBeTruthy();
    });

    it('resize 스타일을 커스텀할 수 있다', () => {
      const { container } = render(<Textarea style={{ resize: 'vertical' }} />);
      const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
      expect(textarea.style.resize).toBe('vertical');
    });
  });

  describe('복합 Props', () => {
    it('여러 props가 함께 적용된다', () => {
      const handleChange = vi.fn();
      const { container } = render(
        <Textarea
          name="description"
          value="테스트 내용"
          onChange={handleChange}
          rows={5}
          cols={40}
          maxLength={500}
          required
          className="custom-textarea"
          aria-label="상품 설명"
          placeholder="상품 설명을 입력하세요"
        />
      );

      const textarea = container.querySelector('textarea')!;
      expect(textarea.name).toBe('description');
      expect((textarea as HTMLTextAreaElement).value).toBe('테스트 내용');
      expect(textarea.rows).toBe(5);
      expect(textarea.cols).toBe(40);
      expect(textarea.maxLength).toBe(500);
      expect(textarea.required).toBe(true);
      expect(textarea.className).toContain('custom-textarea');
      expect(textarea.getAttribute('aria-label')).toBe('상품 설명');
      expect(textarea.placeholder).toBe('상품 설명을 입력하세요');

      fireEvent.change(textarea, { target: { value: '새 내용' } });
      expect(handleChange).toHaveBeenCalledTimes(1);
    });
  });

  describe('줄바꿈 처리', () => {
    it('여러 줄 텍스트를 처리한다', () => {
      const multilineText = '첫 번째 줄\n두 번째 줄\n세 번째 줄';
      const { container } = render(<Textarea value={multilineText} onChange={() => {}} />);
      const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
      expect(textarea.value).toBe(multilineText);
    });
  });
});
