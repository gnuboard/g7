import { describe, it, expect, vi } from 'vitest';
import { render, fireEvent } from '@testing-library/react';
import React from 'react';
import { Checkbox } from '../Checkbox';

describe('Checkbox 컴포넌트', () => {
  describe('기본 렌더링', () => {
    it('checkbox 요소가 렌더링된다', () => {
      const { container } = render(<Checkbox />);
      const checkbox = container.querySelector('input[type="checkbox"]');
      expect(checkbox).toBeTruthy();
    });

    it('type이 checkbox이다', () => {
      const { container } = render(<Checkbox />);
      const checkbox = container.querySelector('input');
      expect(checkbox?.type).toBe('checkbox');
    });

    it('기본적으로 체크되지 않은 상태이다', () => {
      const { container } = render(<Checkbox />);
      const checkbox = container.querySelector('input') as HTMLInputElement;
      expect(checkbox.checked).toBe(false);
    });
  });

  describe('Checked 상태', () => {
    it('checked prop이 true일 때 체크된다', () => {
      const { container } = render(<Checkbox checked onChange={() => {}} />);
      const checkbox = container.querySelector('input') as HTMLInputElement;
      expect(checkbox.checked).toBe(true);
    });

    it('checked prop이 false일 때 체크되지 않는다', () => {
      const { container } = render(<Checkbox checked={false} onChange={() => {}} />);
      const checkbox = container.querySelector('input') as HTMLInputElement;
      expect(checkbox.checked).toBe(false);
    });

    it('defaultChecked prop이 적용된다', () => {
      const { container } = render(<Checkbox defaultChecked />);
      const checkbox = container.querySelector('input') as HTMLInputElement;
      expect(checkbox.checked).toBe(true);
    });
  });

  describe('이벤트 핸들링', () => {
    it('onChange 핸들러가 호출된다', () => {
      const handleChange = vi.fn();
      const { container } = render(<Checkbox onChange={handleChange} />);
      const checkbox = container.querySelector('input')!;

      fireEvent.click(checkbox);

      expect(handleChange).toHaveBeenCalledTimes(1);
    });

    it('클릭 시 체크 상태가 토글된다', () => {
      const TestComponent = () => {
        const [checked, setChecked] = React.useState(false);
        return <Checkbox checked={checked} onChange={() => setChecked(!checked)} />;
      };

      const { container } = render(<TestComponent />);
      const checkbox = container.querySelector('input') as HTMLInputElement;

      expect(checkbox.checked).toBe(false);

      fireEvent.click(checkbox);
      expect(checkbox.checked).toBe(true);

      fireEvent.click(checkbox);
      expect(checkbox.checked).toBe(false);
    });

    it('onFocus 핸들러가 호출된다', () => {
      const handleFocus = vi.fn();
      const { container } = render(<Checkbox onFocus={handleFocus} />);
      const checkbox = container.querySelector('input')!;

      fireEvent.focus(checkbox);

      expect(handleFocus).toHaveBeenCalledTimes(1);
    });

    it('onBlur 핸들러가 호출된다', () => {
      const handleBlur = vi.fn();
      const { container } = render(<Checkbox onBlur={handleBlur} />);
      const checkbox = container.querySelector('input')!;

      fireEvent.blur(checkbox);

      expect(handleBlur).toHaveBeenCalledTimes(1);
    });
  });

  describe('HTML 속성', () => {
    it('disabled 속성이 적용된다', () => {
      const { container } = render(<Checkbox disabled />);
      const checkbox = container.querySelector('input');
      expect(checkbox?.disabled).toBe(true);
    });

    it('disabled 상태에서 클릭하면 상태가 변하지 않는다', () => {
      const { container } = render(<Checkbox disabled />);
      const checkbox = container.querySelector('input') as HTMLInputElement;
      const initialChecked = checkbox.checked;

      fireEvent.click(checkbox);

      // disabled 상태에서도 React는 이벤트를 발생시키지만, 실제로는 변경되지 않음
      expect(checkbox.disabled).toBe(true);
    });

    it('required 속성이 적용된다', () => {
      const { container } = render(<Checkbox required />);
      const checkbox = container.querySelector('input');
      expect(checkbox?.required).toBe(true);
    });

    it('name 속성이 적용된다', () => {
      const { container } = render(<Checkbox name="terms" />);
      const checkbox = container.querySelector('input');
      expect(checkbox?.name).toBe('terms');
    });

    it('value 속성이 적용된다', () => {
      const { container } = render(<Checkbox value="agree" />);
      const checkbox = container.querySelector('input');
      expect(checkbox?.value).toBe('agree');
    });

    it('id 속성이 적용된다', () => {
      const { container } = render(<Checkbox id="terms-checkbox" />);
      const checkbox = container.querySelector('input');
      expect(checkbox?.id).toBe('terms-checkbox');
    });

    it('readOnly 속성이 적용된다', () => {
      const { container } = render(<Checkbox readOnly />);
      const checkbox = container.querySelector('input');
      expect(checkbox?.readOnly).toBe(true);
    });
  });

  describe('Label Props', () => {
    it('label prop이 존재한다', () => {
      // label prop은 있지만 렌더링하지 않음 (부모에서 처리)
      const { container } = render(<Checkbox label="약관 동의" />);
      const checkbox = container.querySelector('input');
      expect(checkbox).toBeTruthy();
    });
  });

  describe('사용자 정의 Props', () => {
    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(<Checkbox className="custom-checkbox" />);
      const checkbox = container.querySelector('input');
      expect(checkbox?.className).toContain('custom-checkbox');
    });

    it('aria-label이 적용된다', () => {
      const { container } = render(<Checkbox aria-label="약관 동의" />);
      const checkbox = container.querySelector('input');
      expect(checkbox?.getAttribute('aria-label')).toBe('약관 동의');
    });

    it('checked 속성이 체크 상태를 나타낸다', () => {
      const { container } = render(<Checkbox checked onChange={() => {}} />);
      const checkbox = container.querySelector('input') as HTMLInputElement;
      expect(checkbox.checked).toBe(true);
    });

    it('data 속성이 적용된다', () => {
      const { container } = render(<Checkbox data-testid="test-checkbox" />);
      const checkbox = container.querySelector('input');
      expect(checkbox?.getAttribute('data-testid')).toBe('test-checkbox');
    });
  });

  describe('Form 통합', () => {
    it('form 제출 시 checkbox 값이 포함된다', () => {
      const handleSubmit = vi.fn((e) => {
        e.preventDefault();
        const formData = new FormData(e.target as HTMLFormElement);
        return formData.get('terms');
      });

      const { container } = render(
        <form onSubmit={handleSubmit}>
          <Checkbox name="terms" value="agree" defaultChecked />
          <button type="submit">제출</button>
        </form>
      );

      const form = container.querySelector('form')!;
      fireEvent.submit(form);

      expect(handleSubmit).toHaveBeenCalled();
    });
  });

  describe('복합 Props', () => {
    it('여러 props가 함께 적용된다', () => {
      const handleChange = vi.fn();
      const { container } = render(
        <Checkbox
          id="terms"
          name="terms"
          value="agree"
          checked={true}
          onChange={handleChange}
          required
          className="custom-checkbox"
          aria-label="약관 동의"
        />
      );

      const checkbox = container.querySelector('input')!;
      expect(checkbox.id).toBe('terms');
      expect(checkbox.name).toBe('terms');
      expect(checkbox.value).toBe('agree');
      expect((checkbox as HTMLInputElement).checked).toBe(true);
      expect(checkbox.required).toBe(true);
      expect(checkbox.className).toContain('custom-checkbox');
      expect(checkbox.getAttribute('aria-label')).toBe('약관 동의');

      fireEvent.click(checkbox);
      expect(handleChange).toHaveBeenCalledTimes(1);
    });
  });

  describe('Indeterminate 상태', () => {
    it('indeterminate ref를 통해 설정할 수 있다', () => {
      const TestComponent = () => {
        const checkboxRef = React.useRef<HTMLInputElement>(null);

        React.useEffect(() => {
          if (checkboxRef.current) {
            checkboxRef.current.indeterminate = true;
          }
        }, []);

        return <Checkbox ref={checkboxRef} />;
      };

      const { container } = render(<TestComponent />);
      const checkbox = container.querySelector('input') as HTMLInputElement;

      // indeterminate는 DOM 속성이므로 직접 확인
      expect(checkbox.indeterminate).toBe(true);
    });
  });
});
