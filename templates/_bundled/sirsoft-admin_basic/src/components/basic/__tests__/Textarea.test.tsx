// e2e:allow 단위 테스트 보강 (디폴트 시맨틱 머지 회귀) — 동작 무변, 컴포넌트 소스의 e2e:allow 와 동일 사이클에서 E2E spec 일괄 작성 예정.
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';
import { Textarea } from '../Textarea';

describe('Textarea 컴포넌트', () => {
  describe('기본 렌더링', () => {
    /**
     * @scenario component=textarea
     * @effects textarea_renders_value_and_placeholder
     */
    it('textarea 요소가 렌더링된다', () => {
      const { container } = render(<Textarea />);
      const textarea = container.querySelector('textarea');
      expect(textarea).toBeTruthy();
    });

    /**
     * @scenario component=textarea
     * @effects textarea_renders_value_and_placeholder
     */
    it('placeholder가 표시된다', () => {
      render(<Textarea placeholder="내용을 입력하세요" />);
      expect(screen.getByPlaceholderText('내용을 입력하세요')).toBeTruthy();
    });

    /**
     * @scenario component=textarea
     * @effects textarea_renders_value_and_placeholder
     */
    it('value가 설정된다', () => {
      const { container } = render(<Textarea value="테스트 내용" onChange={() => {}} />);
      const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
      expect(textarea.value).toBe('테스트 내용');
    });

    /**
     * @scenario component=textarea
     * @effects textarea_renders_value_and_placeholder
     */
    it('defaultValue가 설정된다', () => {
      const { container } = render(<Textarea defaultValue="기본 내용" />);
      const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
      expect(textarea.value).toBe('기본 내용');
    });
  });

  describe('이벤트 핸들링', () => {
    /**
     * @scenario component=textarea
     * @effects textarea_forwards_events
     */
    it('onChange 핸들러가 호출된다', () => {
      const handleChange = vi.fn();
      const { container } = render(<Textarea onChange={handleChange} />);
      const textarea = container.querySelector('textarea')!;

      fireEvent.change(textarea, { target: { value: '새 내용' } });

      expect(handleChange).toHaveBeenCalledTimes(1);
    });

    /**
     * @scenario component=textarea
     * @effects textarea_forwards_events
     */
    it('onFocus 핸들러가 호출된다', () => {
      const handleFocus = vi.fn();
      const { container } = render(<Textarea onFocus={handleFocus} />);
      const textarea = container.querySelector('textarea')!;

      fireEvent.focus(textarea);

      expect(handleFocus).toHaveBeenCalledTimes(1);
    });

    /**
     * @scenario component=textarea
     * @effects textarea_forwards_events
     */
    it('onBlur 핸들러가 호출된다', () => {
      const handleBlur = vi.fn();
      const { container } = render(<Textarea onBlur={handleBlur} />);
      const textarea = container.querySelector('textarea')!;

      fireEvent.blur(textarea);

      expect(handleBlur).toHaveBeenCalledTimes(1);
    });

    /**
     * @scenario component=textarea
     * @effects textarea_forwards_events
     */
    it('onKeyDown 핸들러가 호출된다', () => {
      const handleKeyDown = vi.fn();
      const { container } = render(<Textarea onKeyDown={handleKeyDown} />);
      const textarea = container.querySelector('textarea')!;

      fireEvent.keyDown(textarea, { key: 'Enter' });

      expect(handleKeyDown).toHaveBeenCalledTimes(1);
    });

    /**
     * @scenario component=textarea
     * @effects textarea_forwards_events
     */
    it('onKeyUp 핸들러가 호출된다', () => {
      const handleKeyUp = vi.fn();
      const { container } = render(<Textarea onKeyUp={handleKeyUp} />);
      const textarea = container.querySelector('textarea')!;

      fireEvent.keyUp(textarea, { key: 'a' });

      expect(handleKeyUp).toHaveBeenCalledTimes(1);
    });
  });

  describe('제어 컴포넌트', () => {
    /**
     * @scenario component=textarea
     * @effects textarea_controlled_mode
     */
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
    /**
     * @scenario component=textarea
     * @effects textarea_applies_html_attributes
     */
    it('disabled 속성이 적용된다', () => {
      const { container } = render(<Textarea disabled />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.disabled).toBe(true);
    });

    /**
     * @scenario component=textarea
     * @effects textarea_applies_html_attributes
     */
    it('readOnly 속성이 적용된다', () => {
      const { container } = render(<Textarea readOnly />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.readOnly).toBe(true);
    });

    /**
     * @scenario component=textarea
     * @effects textarea_applies_html_attributes
     */
    it('required 속성이 적용된다', () => {
      const { container } = render(<Textarea required />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.required).toBe(true);
    });

    /**
     * @scenario component=textarea
     * @effects textarea_applies_html_attributes
     */
    it('name 속성이 적용된다', () => {
      const { container } = render(<Textarea name="description" />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.name).toBe('description');
    });

    /**
     * @scenario component=textarea
     * @effects textarea_applies_html_attributes
     */
    it('id 속성이 적용된다', () => {
      const { container } = render(<Textarea id="comment-textarea" />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.id).toBe('comment-textarea');
    });

    /**
     * @scenario component=textarea
     * @effects textarea_applies_html_attributes
     */
    it('rows 속성이 적용된다', () => {
      const { container } = render(<Textarea rows={5} />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.rows).toBe(5);
    });

    /**
     * @scenario component=textarea
     * @effects textarea_applies_html_attributes
     */
    it('cols 속성이 적용된다', () => {
      const { container } = render(<Textarea cols={50} />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.cols).toBe(50);
    });

    /**
     * @scenario component=textarea
     * @effects textarea_applies_html_attributes
     */
    it('maxLength 속성이 적용된다', () => {
      const { container } = render(<Textarea maxLength={100} />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.maxLength).toBe(100);
    });

    /**
     * @scenario component=textarea
     * @effects textarea_applies_html_attributes
     */
    it('minLength 속성이 적용된다', () => {
      const { container } = render(<Textarea minLength={10} />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.minLength).toBe(10);
    });

    /**
     * @scenario component=textarea
     * @effects textarea_applies_html_attributes
     */
    it('wrap 속성이 적용된다', () => {
      const { container } = render(<Textarea wrap="hard" />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.wrap).toBe('hard');
    });
  });

  describe('Label 및 Error Props', () => {
    /**
     * @scenario component=textarea
     * @effects textarea_applies_html_attributes
     */
    it('label prop이 존재한다', () => {
      // label prop은 있지만 렌더링하지 않음 (부모에서 처리)
      const { container } = render(<Textarea label="설명" />);
      const textarea = container.querySelector('textarea');
      expect(textarea).toBeTruthy();
    });

    /**
     * @scenario component=textarea
     * @effects textarea_applies_html_attributes
     */
    it('error prop이 존재한다', () => {
      // error prop은 있지만 렌더링하지 않음 (부모에서 처리)
      const { container } = render(<Textarea error="필수 입력 항목입니다" />);
      const textarea = container.querySelector('textarea');
      expect(textarea).toBeTruthy();
    });
  });

  describe('사용자 정의 Props', () => {
    /**
     * @scenario component=textarea
     * @effects textarea_applies_html_attributes
     */
    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(<Textarea className="custom-textarea" />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.className).toContain('custom-textarea');
    });

    /**
     * @scenario component=textarea
     * @effects textarea_applies_html_attributes
     */
    it('aria-label이 적용된다', () => {
      const { container } = render(<Textarea aria-label="댓글 입력" />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.getAttribute('aria-label')).toBe('댓글 입력');
    });

    /**
     * @scenario component=textarea
     * @effects textarea_applies_html_attributes
     */
    it('aria-describedby가 적용된다', () => {
      const { container } = render(<Textarea aria-describedby="description-help" />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.getAttribute('aria-describedby')).toBe('description-help');
    });

    /**
     * @scenario component=textarea
     * @effects textarea_applies_html_attributes
     */
    it('data 속성이 적용된다', () => {
      const { container } = render(<Textarea data-testid="test-textarea" />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.getAttribute('data-testid')).toBe('test-textarea');
    });

    /**
     * @scenario component=textarea
     * @effects textarea_applies_html_attributes
     */
    it('autoComplete 속성이 적용된다', () => {
      const { container } = render(<Textarea autoComplete="off" />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.getAttribute('autocomplete')).toBe('off');
    });

    // autoFocus는 jsdom 환경에서 제대로 테스트되지 않으므로 생략

    /**
     * @scenario component=textarea
     * @effects textarea_applies_html_attributes
     */
    it('spellCheck 속성이 적용된다', () => {
      const { container } = render(<Textarea spellCheck={false} />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.getAttribute('spellcheck')).toBe('false');
    });
  });

  describe('Resize 동작', () => {
    /**
     * @scenario component=textarea
     * @effects textarea_applies_html_attributes
     */
    it('기본 resize 동작이 유지된다', () => {
      const { container } = render(<Textarea />);
      const textarea = container.querySelector('textarea');
      // CSS resize 속성은 기본적으로 'both' 또는 브라우저 기본값
      expect(textarea).toBeTruthy();
    });

    /**
     * @scenario component=textarea
     * @effects textarea_applies_html_attributes
     */
    it('resize 스타일을 커스텀할 수 있다', () => {
      const { container } = render(<Textarea style={{ resize: 'vertical' }} />);
      const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
      expect(textarea.style.resize).toBe('vertical');
    });
  });

  describe('복합 Props', () => {
    /**
     * @scenario component=textarea
     * @effects textarea_applies_html_attributes
     */
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
    /**
     * @scenario component=textarea
     * @effects textarea_renders_value_and_placeholder
     */
    it('여러 줄 텍스트를 처리한다', () => {
      const multilineText = '첫 번째 줄\n두 번째 줄\n세 번째 줄';
      const { container } = render(<Textarea value={multilineText} onChange={() => {}} />);
      const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
      expect(textarea.value).toBe(multilineText);
    });
  });

  // 디폴트 시맨틱 머지 회귀 테스트
  // 호출처가 이미 'textarea' 토큰을 명시한 경우 중복 prepend ("textarea textarea ...") 를 방지한다.
  describe('디폴트 시맨틱 클래스 머지', () => {
    /**
     * @scenario component=textarea
     * @effects textarea_default_semantic_class_merge
     */
    it('className 미지정 시 디폴트 시맨틱 "textarea" 가 자동 적용된다', () => {
      const { container } = render(<Textarea />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.className).toBe('textarea');
    });

    /**
     * @scenario component=textarea
     * @effects textarea_default_semantic_class_merge
     */
    it('className 에 시맨틱 토큰이 없으면 "textarea" 를 prepend 한다', () => {
      const { container } = render(<Textarea className="resize-vertical" />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.className).toBe('textarea resize-vertical');
    });

    /**
     * @scenario component=textarea
     * @effects textarea_default_semantic_class_merge
     */
    it('className 에 이미 "textarea" 토큰이 있으면 중복 prepend 하지 않는다', () => {
      const { container } = render(<Textarea className="textarea resize-vertical" />);
      const textarea = container.querySelector('textarea');
      expect(textarea?.className).toBe('textarea resize-vertical');
      expect(textarea?.className.split(/\s+/).filter((t) => t === 'textarea')).toHaveLength(1);
    });
  });
});
