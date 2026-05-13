import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Input } from '../Input';

describe('Input 컴포넌트', () => {
  describe('기본 렌더링', () => {
    it('input 요소가 렌더링된다', () => {
      const { container } = render(<Input />);
      const input = container.querySelector('input');
      expect(input).toBeTruthy();
    });

    it('placeholder가 표시된다', () => {
      render(<Input placeholder="이름을 입력하세요" />);
      expect(screen.getByPlaceholderText('이름을 입력하세요')).toBeTruthy();
    });

    it('value가 설정된다', () => {
      const { container } = render(<Input value="테스트 값" onChange={() => {}} />);
      const input = container.querySelector('input') as HTMLInputElement;
      expect(input.value).toBe('테스트 값');
    });
  });

  describe('Input Type', () => {
    it('text type이 적용된다', () => {
      const { container } = render(<Input type="text" />);
      const input = container.querySelector('input');
      expect(input?.type).toBe('text');
    });

    it('password type이 적용된다', () => {
      const { container } = render(<Input type="password" />);
      const input = container.querySelector('input');
      expect(input?.type).toBe('password');
    });

    it('email type이 적용된다', () => {
      const { container } = render(<Input type="email" />);
      const input = container.querySelector('input');
      expect(input?.type).toBe('email');
    });

    it('number type이 적용된다', () => {
      const { container } = render(<Input type="number" />);
      const input = container.querySelector('input');
      expect(input?.type).toBe('number');
    });

    it('tel type이 적용된다', () => {
      const { container } = render(<Input type="tel" />);
      const input = container.querySelector('input');
      expect(input?.type).toBe('tel');
    });

    it('url type이 적용된다', () => {
      const { container } = render(<Input type="url" />);
      const input = container.querySelector('input');
      expect(input?.type).toBe('url');
    });

    it('date type이 적용된다', () => {
      const { container } = render(<Input type="date" />);
      const input = container.querySelector('input');
      expect(input?.type).toBe('date');
    });
  });

  describe('이벤트 핸들링', () => {
    it('onChange 핸들러가 호출된다', () => {
      const handleChange = vi.fn();
      const { container } = render(<Input onChange={handleChange} />);
      const input = container.querySelector('input')!;

      fireEvent.change(input, { target: { value: '새 값' } });

      expect(handleChange).toHaveBeenCalledTimes(1);
    });

    it('onFocus 핸들러가 호출된다', () => {
      const handleFocus = vi.fn();
      const { container } = render(<Input onFocus={handleFocus} />);
      const input = container.querySelector('input')!;

      fireEvent.focus(input);

      expect(handleFocus).toHaveBeenCalledTimes(1);
    });

    it('onBlur 핸들러가 호출된다', () => {
      const handleBlur = vi.fn();
      const { container } = render(<Input onBlur={handleBlur} />);
      const input = container.querySelector('input')!;

      fireEvent.blur(input);

      expect(handleBlur).toHaveBeenCalledTimes(1);
    });

    it('onKeyDown 핸들러가 호출된다', () => {
      const handleKeyDown = vi.fn();
      const { container } = render(<Input onKeyDown={handleKeyDown} />);
      const input = container.querySelector('input')!;

      fireEvent.keyDown(input, { key: 'Enter' });

      expect(handleKeyDown).toHaveBeenCalledTimes(1);
    });
  });

  describe('HTML 속성', () => {
    it('disabled 속성이 적용된다', () => {
      const { container } = render(<Input disabled />);
      const input = container.querySelector('input');
      expect(input?.disabled).toBe(true);
    });

    it('readonly 속성이 적용된다', () => {
      const { container } = render(<Input readOnly />);
      const input = container.querySelector('input');
      expect(input?.readOnly).toBe(true);
    });

    it('required 속성이 적용된다', () => {
      const { container } = render(<Input required />);
      const input = container.querySelector('input');
      expect(input?.required).toBe(true);
    });

    it('name 속성이 적용된다', () => {
      const { container } = render(<Input name="username" />);
      const input = container.querySelector('input');
      expect(input?.name).toBe('username');
    });

    it('id 속성이 적용된다', () => {
      const { container } = render(<Input id="email-input" />);
      const input = container.querySelector('input');
      expect(input?.id).toBe('email-input');
    });

    it('maxLength 속성이 적용된다', () => {
      const { container } = render(<Input maxLength={10} />);
      const input = container.querySelector('input');
      expect(input?.maxLength).toBe(10);
    });

    it('minLength 속성이 적용된다', () => {
      const { container } = render(<Input minLength={3} />);
      const input = container.querySelector('input');
      expect(input?.minLength).toBe(3);
    });

    it('pattern 속성이 적용된다', () => {
      const { container } = render(<Input pattern="[0-9]{3}-[0-9]{4}" />);
      const input = container.querySelector('input');
      expect(input?.pattern).toBe('[0-9]{3}-[0-9]{4}');
    });
  });

  describe('Label 및 Error Props', () => {
    it('label prop이 존재한다', () => {
      // label prop은 있지만 렌더링하지 않음 (부모에서 처리)
      const { container } = render(<Input label="사용자 이름" />);
      const input = container.querySelector('input');
      expect(input).toBeTruthy();
    });

    it('error prop이 존재한다', () => {
      // error prop은 있지만 렌더링하지 않음 (부모에서 처리)
      const { container } = render(<Input error="필수 입력 항목입니다" />);
      const input = container.querySelector('input');
      expect(input).toBeTruthy();
    });
  });

  describe('사용자 정의 Props', () => {
    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(<Input className="custom-input" />);
      const input = container.querySelector('input');
      expect(input?.className).toContain('custom-input');
    });

    it('aria-label이 적용된다', () => {
      const { container } = render(<Input aria-label="이메일 입력" />);
      const input = container.querySelector('input');
      expect(input?.getAttribute('aria-label')).toBe('이메일 입력');
    });

    it('data 속성이 적용된다', () => {
      const { container } = render(<Input data-testid="test-input" />);
      const input = container.querySelector('input');
      expect(input?.getAttribute('data-testid')).toBe('test-input');
    });

    it('autoComplete 속성이 적용된다', () => {
      const { container } = render(<Input autoComplete="off" />);
      const input = container.querySelector('input');
      expect(input?.getAttribute('autocomplete')).toBe('off');
    });

    // autoFocus는 jsdom 환경에서 제대로 테스트되지 않으므로 생략
  });

  describe('복합 Props', () => {
    it('여러 props가 함께 적용된다', () => {
      const handleChange = vi.fn();
      const { container } = render(
        <Input
          type="email"
          name="email"
          placeholder="이메일을 입력하세요"
          required
          className="custom-input"
          onChange={handleChange}
        />
      );

      const input = container.querySelector('input')!;
      expect(input.type).toBe('email');
      expect(input.name).toBe('email');
      expect(input.placeholder).toBe('이메일을 입력하세요');
      expect(input.required).toBe(true);
      expect(input.className).toContain('custom-input');

      fireEvent.change(input, { target: { value: 'test@example.com' } });
      expect(handleChange).toHaveBeenCalledTimes(1);
    });
  });

  describe('IME 조합 처리 (한글 입력)', () => {
    it('IME 조합 중에는 onChange가 호출되지 않는다', () => {
      const handleChange = vi.fn();
      const { container } = render(<Input value="" onChange={handleChange} />);
      const input = container.querySelector('input')!;

      // IME 조합 시작
      fireEvent.compositionStart(input);

      // 조합 중 입력 (한글 'ㄱ' -> '가' -> '간')
      fireEvent.change(input, { target: { value: 'ㄱ' } });
      fireEvent.change(input, { target: { value: '가' } });
      fireEvent.change(input, { target: { value: '간' } });

      // IME 조합 중에는 onChange가 호출되지 않아야 함
      expect(handleChange).not.toHaveBeenCalled();
    });

    it('IME 조합 완료 후 onChange가 호출된다', () => {
      const handleChange = vi.fn();
      const { container } = render(<Input value="" onChange={handleChange} />);
      const input = container.querySelector('input')! as HTMLInputElement;

      // IME 조합 시작
      fireEvent.compositionStart(input);

      // 조합 중 입력 - input의 실제 value를 설정
      Object.defineProperty(input, 'value', { value: '한글', writable: true });
      fireEvent.change(input, { target: { value: '한글' } });

      // IME 조합 완료
      fireEvent.compositionEnd(input);

      // 조합 완료 후 onChange가 호출되어야 함
      expect(handleChange).toHaveBeenCalledTimes(1);
      // onChange에 전달된 이벤트의 target.value가 '한글'이어야 함
      expect(handleChange.mock.calls[0][0].target.value).toBe('한글');
    });

    it('IME 조합 중에도 화면에 입력이 표시된다', () => {
      const handleChange = vi.fn();
      const { container } = render(<Input value="" onChange={handleChange} />);
      const input = container.querySelector('input')! as HTMLInputElement;

      // IME 조합 시작
      fireEvent.compositionStart(input);

      // 조합 중 입력
      fireEvent.change(input, { target: { value: '테스트' } });

      // 화면에는 입력이 표시되어야 함 (로컬 상태)
      expect(input.value).toBe('테스트');
    });

    it('영문 입력 시 onChange가 즉시 호출된다', () => {
      const handleChange = vi.fn();
      const { container } = render(<Input value="" onChange={handleChange} />);
      const input = container.querySelector('input')!;

      // 영문 입력 (IME 조합 없음)
      fireEvent.change(input, { target: { value: 'a' } });

      // 즉시 onChange가 호출되어야 함
      expect(handleChange).toHaveBeenCalledTimes(1);
    });

    // TODO: IME 테스트는 happy-dom 환경에서 composition 이벤트가 실제 브라우저처럼 동작하지 않아 건너뜀
    it.skip('IME 조합 후 영문 입력 시 onChange가 호출된다', () => {
      const handleChange = vi.fn();
      const { container } = render(<Input value="" onChange={handleChange} />);
      const input = container.querySelector('input')! as HTMLInputElement;

      // IME 조합 시작 및 완료
      fireEvent.compositionStart(input);
      Object.defineProperty(input, 'value', { value: '한', writable: true });
      fireEvent.change(input, { target: { value: '한' } });
      fireEvent.compositionEnd(input);

      // 한글 조합 완료로 1회
      expect(handleChange).toHaveBeenCalledTimes(1);

      // 영문 입력
      fireEvent.change(input, { target: { value: '한a' } });

      // 영문 입력으로 추가 1회
      expect(handleChange).toHaveBeenCalledTimes(2);
    });

    it('외부 value prop이 변경되면 로컬 값도 동기화된다', async () => {
      const handleChange = vi.fn();
      const { container, rerender } = render(
        <Input value="초기값" onChange={handleChange} />
      );
      const input = container.querySelector('input')! as HTMLInputElement;

      expect(input.value).toBe('초기값');

      // 외부에서 value 변경
      rerender(<Input value="변경된값" onChange={handleChange} />);

      await waitFor(() => {
        expect(input.value).toBe('변경된값');
      });
    });

    it('포커스 상태에서 외부 value prop 변경 시 로컬 값이 동기화되지 않는다 (플리커 방지)', async () => {
      const handleChange = vi.fn();
      const { container, rerender } = render(
        <Input value="2000" onChange={handleChange} />
      );
      const input = container.querySelector('input')! as HTMLInputElement;

      expect(input.value).toBe('2000');

      // 사용자가 Input에 포커스 (입력 중 상태)
      input.focus();
      expect(document.activeElement).toBe(input);

      // 사용자 입력으로 로컬 값 변경
      fireEvent.change(input, { target: { value: '30000' } });
      expect(input.value).toBe('30000');

      // 부모 리렌더링으로 stale value prop 전달 (debounce 대기 중 발생하는 시나리오)
      rerender(<Input value="2000" onChange={handleChange} />);

      // 포커스 상태이므로 stale value로 동기화되지 않아야 함
      await waitFor(() => {
        expect(input.value).toBe('30000');
      });
    });

    it('포커스 해제 후 외부 value prop 변경 시 로컬 값이 정상 동기화된다', async () => {
      const handleChange = vi.fn();
      const { container, rerender } = render(
        <Input value="초기값" onChange={handleChange} />
      );
      const input = container.querySelector('input')! as HTMLInputElement;

      // 포커스 후 해제
      input.focus();
      input.blur();
      expect(document.activeElement).not.toBe(input);

      // 외부에서 value 변경
      rerender(<Input value="새값" onChange={handleChange} />);

      // 포커스 해제 상태이므로 정상 동기화
      await waitFor(() => {
        expect(input.value).toBe('새값');
      });
    });

    it('포커스 상태에서 연속 입력 시 마지막 입력값이 유지된다', async () => {
      const handleChange = vi.fn();
      const { container, rerender } = render(
        <Input value="100" onChange={handleChange} />
      );
      const input = container.querySelector('input')! as HTMLInputElement;

      input.focus();

      // 연속 입력 (100 → 1000 → 10000 → 100000)
      fireEvent.change(input, { target: { value: '1000' } });
      fireEvent.change(input, { target: { value: '10000' } });
      fireEvent.change(input, { target: { value: '100000' } });

      // 중간에 stale value로 리렌더링
      rerender(<Input value="100" onChange={handleChange} />);

      // 마지막 입력값 유지
      await waitFor(() => {
        expect(input.value).toBe('100000');
      });
    });

    it('defaultValue로 초기값이 설정된다', () => {
      const { container } = render(<Input defaultValue="기본값" />);
      const input = container.querySelector('input')! as HTMLInputElement;

      expect(input.value).toBe('기본값');
    });

    it('value 없이 defaultValue만 있으면 uncontrolled로 동작한다', () => {
      const handleChange = vi.fn();
      const { container } = render(
        <Input defaultValue="기본값" onChange={handleChange} />
      );
      const input = container.querySelector('input')! as HTMLInputElement;

      // 직접 입력
      fireEvent.change(input, { target: { value: '새값' } });

      // onChange가 호출되어야 함
      expect(handleChange).toHaveBeenCalledTimes(1);
    });

    // TODO: IME 테스트는 happy-dom 환경에서 composition 이벤트가 실제 브라우저처럼 동작하지 않아 건너뜀
    // 실제 브라우저 환경에서 E2E 테스트로 검증 필요
    it.skip('IME 조합 중에는 onKeyPress가 호출되지 않는다', () => {
      const handleKeyPress = vi.fn();
      const { container } = render(<Input value="" onChange={() => {}} onKeyPress={handleKeyPress} />);
      const input = container.querySelector('input')!;

      // IME 조합 시작
      fireEvent.compositionStart(input);

      // 조합 중 Enter 키
      fireEvent.keyPress(input, { key: 'Enter' });

      // IME 조합 중에는 onKeyPress가 호출되지 않아야 함
      expect(handleKeyPress).not.toHaveBeenCalled();
    });

    // TODO: IME 테스트는 happy-dom 환경에서 composition 이벤트가 실제 브라우저처럼 동작하지 않아 건너뜀
    it.skip('IME 조합 완료 후 onKeyPress가 호출된다', () => {
      const handleKeyPress = vi.fn();
      const { container } = render(<Input value="" onChange={() => {}} onKeyPress={handleKeyPress} />);
      const input = container.querySelector('input')!;

      // IME 조합 시작 및 완료
      fireEvent.compositionStart(input);
      fireEvent.compositionEnd(input);

      // Enter 키
      fireEvent.keyPress(input, { key: 'Enter' });

      // 조합 완료 후 onKeyPress가 호출되어야 함
      expect(handleKeyPress).toHaveBeenCalledTimes(1);
    });
  });
});
