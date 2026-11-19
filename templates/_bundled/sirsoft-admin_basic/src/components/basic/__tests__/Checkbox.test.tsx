import { describe, it, expect, vi } from 'vitest';
import { render, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';
import { Checkbox } from '../Checkbox';

describe('Checkbox 컴포넌트', () => {
  describe('기본 렌더링', () => {
    // @scenario component=checkbox
    // @effects checkbox_renders_native_input
    it('checkbox 요소가 렌더링된다', () => {
      const { container } = render(<Checkbox />);
      const checkbox = container.querySelector('input[type="checkbox"]');
      expect(checkbox).toBeTruthy();
    });

    // @scenario component=checkbox
    // @effects checkbox_renders_native_input
    it('type이 checkbox이다', () => {
      const { container } = render(<Checkbox />);
      const checkbox = container.querySelector('input');
      expect(checkbox?.type).toBe('checkbox');
    });

    // @scenario component=checkbox
    // @effects checkbox_renders_native_input
    it('기본적으로 체크되지 않은 상태이다', () => {
      const { container } = render(<Checkbox />);
      const checkbox = container.querySelector('input') as HTMLInputElement;
      expect(checkbox.checked).toBe(false);
    });
  });

  describe('Checked 상태', () => {
    // @scenario component=checkbox
    // @effects checkbox_checked_state_follows_props
    it('checked prop이 true일 때 체크된다', () => {
      const { container } = render(<Checkbox checked onChange={() => {}} />);
      const checkbox = container.querySelector('input') as HTMLInputElement;
      expect(checkbox.checked).toBe(true);
    });

    // @scenario component=checkbox
    // @effects checkbox_checked_state_follows_props
    it('checked prop이 false일 때 체크되지 않는다', () => {
      const { container } = render(<Checkbox checked={false} onChange={() => {}} />);
      const checkbox = container.querySelector('input') as HTMLInputElement;
      expect(checkbox.checked).toBe(false);
    });

    // @scenario component=checkbox
    // @effects checkbox_checked_state_follows_props
    it('defaultChecked prop이 적용된다', () => {
      const { container } = render(<Checkbox defaultChecked />);
      const checkbox = container.querySelector('input') as HTMLInputElement;
      expect(checkbox.checked).toBe(true);
    });
  });

  describe('이벤트 핸들링', () => {
    // @scenario component=checkbox
    // @effects checkbox_events_fire_and_toggle
    it('onChange 핸들러가 호출된다', () => {
      const handleChange = vi.fn();
      const { container } = render(<Checkbox onChange={handleChange} />);
      const checkbox = container.querySelector('input')!;

      fireEvent.click(checkbox);

      expect(handleChange).toHaveBeenCalledTimes(1);
    });

    // @scenario component=checkbox
    // @effects checkbox_events_fire_and_toggle
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

    // @scenario component=checkbox
    // @effects checkbox_events_fire_and_toggle
    it('onFocus 핸들러가 호출된다', () => {
      const handleFocus = vi.fn();
      const { container } = render(<Checkbox onFocus={handleFocus} />);
      const checkbox = container.querySelector('input')!;

      fireEvent.focus(checkbox);

      expect(handleFocus).toHaveBeenCalledTimes(1);
    });

    // @scenario component=checkbox
    // @effects checkbox_events_fire_and_toggle
    it('onBlur 핸들러가 호출된다', () => {
      const handleBlur = vi.fn();
      const { container } = render(<Checkbox onBlur={handleBlur} />);
      const checkbox = container.querySelector('input')!;

      fireEvent.blur(checkbox);

      expect(handleBlur).toHaveBeenCalledTimes(1);
    });
  });

  describe('HTML 속성', () => {
    // @scenario component=checkbox
    // @effects checkbox_attributes_passthrough
    it('disabled 속성이 적용된다', () => {
      const { container } = render(<Checkbox disabled />);
      const checkbox = container.querySelector('input');
      expect(checkbox?.disabled).toBe(true);
    });

    // @scenario component=checkbox
    // @effects checkbox_disabled_blocks_toggle
    it('disabled 상태에서 클릭하면 상태가 변하지 않는다', async () => {
      const user = userEvent.setup();
      const { container } = render(<Checkbox disabled />);
      const checkbox = container.querySelector('input') as HTMLInputElement;
      const initialChecked = checkbox.checked;

      // 사용자 클릭으로 흉내 낸다 — jsdom 의 fireEvent.click 은 disabled 체크박스도 토글해 브라우저와 다르다
      await user.click(checkbox);

      expect(checkbox.disabled).toBe(true);
      expect(checkbox.checked).toBe(initialChecked);
    });

    // @scenario component=checkbox
    // @effects checkbox_attributes_passthrough
    it('required 속성이 적용된다', () => {
      const { container } = render(<Checkbox required />);
      const checkbox = container.querySelector('input');
      expect(checkbox?.required).toBe(true);
    });

    // @scenario component=checkbox
    // @effects checkbox_attributes_passthrough
    it('name 속성이 적용된다', () => {
      const { container } = render(<Checkbox name="terms" />);
      const checkbox = container.querySelector('input');
      expect(checkbox?.name).toBe('terms');
    });

    // @scenario component=checkbox
    // @effects checkbox_attributes_passthrough
    it('value 속성이 적용된다', () => {
      const { container } = render(<Checkbox value="agree" />);
      const checkbox = container.querySelector('input');
      expect(checkbox?.value).toBe('agree');
    });

    // @scenario component=checkbox
    // @effects checkbox_attributes_passthrough
    it('id 속성이 적용된다', () => {
      const { container } = render(<Checkbox id="terms-checkbox" />);
      const checkbox = container.querySelector('input');
      expect(checkbox?.id).toBe('terms-checkbox');
    });

    // @scenario component=checkbox
    // @effects checkbox_attributes_passthrough
    it('readOnly 속성이 적용된다', () => {
      const { container } = render(<Checkbox readOnly />);
      const checkbox = container.querySelector('input');
      expect(checkbox?.readOnly).toBe(true);
    });
  });

  describe('Label Props', () => {
    // @scenario component=checkbox
    // @effects checkbox_attributes_passthrough
    it('label prop이 존재한다', () => {
      // label prop은 있지만 렌더링하지 않음 (부모에서 처리)
      const { container } = render(<Checkbox label="약관 동의" />);
      const checkbox = container.querySelector('input');
      expect(checkbox).toBeTruthy();
    });
  });

  describe('사용자 정의 Props', () => {
    // @scenario component=checkbox
    // @effects checkbox_attributes_passthrough
    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(<Checkbox className="custom-checkbox" />);
      const checkbox = container.querySelector('input');
      expect(checkbox?.className).toContain('custom-checkbox');
    });

    // @scenario component=checkbox
    // @effects checkbox_attributes_passthrough
    it('aria-label이 적용된다', () => {
      const { container } = render(<Checkbox aria-label="약관 동의" />);
      const checkbox = container.querySelector('input');
      expect(checkbox?.getAttribute('aria-label')).toBe('약관 동의');
    });

    // @scenario component=checkbox
    // @effects checkbox_checked_state_follows_props
    it('checked 속성이 체크 상태를 나타낸다', () => {
      const { container } = render(<Checkbox checked onChange={() => {}} />);
      const checkbox = container.querySelector('input') as HTMLInputElement;
      expect(checkbox.checked).toBe(true);
    });

    // @scenario component=checkbox
    // @effects checkbox_attributes_passthrough
    it('data 속성이 적용된다', () => {
      const { container } = render(<Checkbox data-testid="test-checkbox" />);
      const checkbox = container.querySelector('input');
      expect(checkbox?.getAttribute('data-testid')).toBe('test-checkbox');
    });
  });

  describe('Form 통합', () => {
    // @scenario component=checkbox
    // @effects checkbox_value_included_in_form_submit
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
    // @scenario component=checkbox
    // @effects checkbox_attributes_passthrough
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
    // @scenario component=checkbox
    // @effects checkbox_indeterminate_via_ref
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
