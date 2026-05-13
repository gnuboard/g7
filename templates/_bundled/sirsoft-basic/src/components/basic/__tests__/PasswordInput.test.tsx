/**
 * PasswordInput 컴포넌트 테스트
 *
 * @description 비밀번호 입력 컴포넌트의 동작을 테스트합니다.
 */

import { render, screen, fireEvent, act } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { PasswordInput, defaultPasswordRules, availablePasswordRules } from '../PasswordInput';

// Icon 컴포넌트 Mock
vi.mock('../Icon', () => ({
  Icon: ({ name, className }: { name: string; className?: string }) => (
    <i data-testid={`icon-${name}`} className={className} />
  ),
}));

// 다국어 키 -> 텍스트 매핑 (테스트용)
const translations: Record<string, string> = {
  'auth.password_input.show': '비밀번호 보기',
  'auth.password_input.hide': '비밀번호 숨기기',
  'auth.password_input.match': '비밀번호가 일치합니다',
  'auth.password_input.mismatch': '비밀번호가 일치하지 않습니다',
  'auth.password_input.caps_lock_on': 'CapsLock이 켜져 있습니다',
  'auth.password_input.rules.minLength': '최소 8자 이상',
  'auth.password_input.rules.hasUppercase': '대문자 포함',
  'auth.password_input.rules.hasLowercase': '소문자 포함',
  'auth.password_input.rules.hasNumber': '숫자 포함',
  'auth.password_input.rules.hasSpecial': '특수문자 포함',
};

// G7Core.t() Mock 설정
const mockT = vi.fn((key: string) => translations[key] ?? key);

describe('PasswordInput 컴포넌트', () => {
  beforeEach(() => {
    // G7Core Mock 설정
    (window as unknown as { G7Core: { t: typeof mockT } }).G7Core = {
      t: mockT,
    };
  });

  afterEach(() => {
    vi.clearAllMocks();
    delete (window as unknown as { G7Core?: unknown }).G7Core;
  });

  describe('렌더링', () => {
    it('기본 렌더링이 되어야 함', () => {
      render(<PasswordInput name="password" data-testid="password-input" />);
      const input = screen.getByTestId('password-input');
      expect(input).toBeInTheDocument();
      expect(input).toHaveAttribute('type', 'password');
    });

    it('showToggle=true일 때 토글 버튼이 렌더링되어야 함', () => {
      render(<PasswordInput name="password" showToggle={true} />);
      const toggleButton = screen.getByRole('button', { name: /비밀번호 보기/i });
      expect(toggleButton).toBeInTheDocument();
    });

    it('showToggle=false일 때 토글 버튼이 렌더링되지 않아야 함', () => {
      render(<PasswordInput name="password" showToggle={false} />);
      const toggleButton = screen.queryByRole('button');
      expect(toggleButton).not.toBeInTheDocument();
    });

    it('에러 메시지가 표시되어야 함', () => {
      render(<PasswordInput name="password" error="비밀번호 오류" />);
      expect(screen.getByText('비밀번호 오류')).toBeInTheDocument();
    });
  });

  describe('비밀번호 보기/숨기기', () => {
    it('토글 버튼 클릭 시 비밀번호가 표시되어야 함', () => {
      render(<PasswordInput name="password" data-testid="password-input" />);
      const input = screen.getByTestId('password-input');
      const toggleButton = screen.getByRole('button', { name: /비밀번호 보기/i });

      // 초기 상태: password 타입
      expect(input).toHaveAttribute('type', 'password');

      // 토글 클릭
      fireEvent.click(toggleButton);

      // 변경 상태: text 타입
      expect(input).toHaveAttribute('type', 'text');
    });

    it('토글 버튼 두 번 클릭 시 비밀번호가 다시 숨겨져야 함', () => {
      render(<PasswordInput name="password" data-testid="password-input" />);
      const input = screen.getByTestId('password-input');
      const toggleButton = screen.getByRole('button', { name: /비밀번호 보기/i });

      // 두 번 클릭
      fireEvent.click(toggleButton);
      fireEvent.click(toggleButton);

      expect(input).toHaveAttribute('type', 'password');
    });

    it('disabled 상태에서 토글 버튼도 비활성화되어야 함', () => {
      render(<PasswordInput name="password" disabled />);
      const toggleButton = screen.getByRole('button');
      expect(toggleButton).toBeDisabled();
    });
  });

  describe('비밀번호 검증', () => {
    it('showValidation=true일 때 검증 규칙이 표시되어야 함', () => {
      render(
        <PasswordInput
          name="password"
          showValidation={true}
          enableRules={['minLength', 'hasUppercase', 'hasLowercase', 'hasNumber', 'hasSpecial']}
          defaultValue="test"
          data-testid="password-input"
        />
      );

      // 기본 규칙들이 표시되어야 함
      expect(screen.getByText('최소 8자 이상')).toBeInTheDocument();
      expect(screen.getByText('대문자 포함')).toBeInTheDocument();
      expect(screen.getByText('소문자 포함')).toBeInTheDocument();
      expect(screen.getByText('숫자 포함')).toBeInTheDocument();
      expect(screen.getByText('특수문자 포함')).toBeInTheDocument();
    });

    it('조건 충족 시 체크 아이콘이 표시되어야 함', () => {
      render(
        <PasswordInput
          name="password"
          showValidation={true}
          enableRules={['minLength', 'hasLowercase', 'hasNumber']}
          defaultValue="test1234"
          data-testid="password-input"
        />
      );

      // 8자 이상, 소문자, 숫자 조건 충족
      // check-circle 아이콘이 표시되어야 함
      const checkIcons = screen.getAllByTestId('icon-check-circle');
      expect(checkIcons.length).toBeGreaterThan(0);
    });

    it('showRules로 표시할 규칙을 제한할 수 있어야 함', () => {
      render(
        <PasswordInput
          name="password"
          showValidation={true}
          enableRules={['minLength', 'hasUppercase', 'hasNumber', 'hasSpecial']}
          showRules={['minLength', 'hasNumber']}
          defaultValue="test"
          data-testid="password-input"
        />
      );

      // 지정한 규칙만 표시
      expect(screen.getByText('최소 8자 이상')).toBeInTheDocument();
      expect(screen.getByText('숫자 포함')).toBeInTheDocument();

      // 지정하지 않은 규칙은 표시되지 않음
      expect(screen.queryByText('대문자 포함')).not.toBeInTheDocument();
      expect(screen.queryByText('특수문자 포함')).not.toBeInTheDocument();
    });

    it('onValidityChange 콜백이 호출되어야 함', () => {
      const onValidityChange = vi.fn();
      render(
        <PasswordInput
          name="password"
          showValidation={true}
          onValidityChange={onValidityChange}
          defaultValue="Test1234!"
          data-testid="password-input"
        />
      );

      // 기본 규칙(minLength)만 적용되므로 모든 조건 충족 시 isValid=true
      expect(onValidityChange).toHaveBeenCalledWith(true, []);
    });

    it('빈 값일 때 검증 결과가 표시되지 않아야 함', () => {
      render(
        <PasswordInput
          name="password"
          showValidation={true}
          data-testid="password-input"
        />
      );

      // 검증 규칙 텍스트가 표시되지 않아야 함
      expect(screen.queryByText('최소 8자 이상')).not.toBeInTheDocument();
    });
  });

  describe('비밀번호 확인', () => {
    it('isConfirmField=true일 때 일치 여부가 표시되어야 함', () => {
      render(
        <PasswordInput
          name="password_confirmation"
          isConfirmField={true}
          confirmTarget="password123"
          defaultValue="password123"
          data-testid="confirm-input"
        />
      );

      expect(screen.getByText('비밀번호가 일치합니다')).toBeInTheDocument();
    });

    it('비밀번호가 일치하지 않을 때 불일치 메시지가 표시되어야 함', () => {
      render(
        <PasswordInput
          name="password_confirmation"
          isConfirmField={true}
          confirmTarget="password123"
          defaultValue="different"
          data-testid="confirm-input"
        />
      );

      expect(screen.getByText('비밀번호가 일치하지 않습니다')).toBeInTheDocument();
    });

    it('onMatchChange 콜백이 호출되어야 함', () => {
      const onMatchChange = vi.fn();
      render(
        <PasswordInput
          name="password_confirmation"
          isConfirmField={true}
          confirmTarget="password123"
          onMatchChange={onMatchChange}
          defaultValue="password123"
          data-testid="confirm-input"
        />
      );

      expect(onMatchChange).toHaveBeenCalledWith(true);
    });

    it('빈 값일 때 일치 여부가 표시되지 않아야 함', () => {
      render(
        <PasswordInput
          name="password_confirmation"
          isConfirmField={true}
          confirmTarget="password123"
          data-testid="confirm-input"
        />
      );

      expect(screen.queryByText('비밀번호가 일치합니다')).not.toBeInTheDocument();
      expect(screen.queryByText('비밀번호가 일치하지 않습니다')).not.toBeInTheDocument();
    });
  });

  describe('입력 처리', () => {
    it('onChange가 호출되어야 함', () => {
      const onChange = vi.fn();
      render(
        <PasswordInput
          name="password"
          onChange={onChange}
          data-testid="password-input"
        />
      );

      const input = screen.getByTestId('password-input');
      fireEvent.change(input, { target: { value: 'newpassword' } });

      expect(onChange).toHaveBeenCalled();
    });

    it('controlled 모드에서 value prop이 적용되어야 함', () => {
      render(
        <PasswordInput
          name="password"
          value="controlled-value"
          onChange={vi.fn()}
          data-testid="password-input"
        />
      );

      const input = screen.getByTestId('password-input');
      expect(input).toHaveValue('controlled-value');
    });
  });

  describe('availablePasswordRules', () => {
    it('minLength 규칙이 올바르게 동작해야 함', () => {
      const rule = availablePasswordRules.minLength;
      // defaultParam=6 이므로 6자 미만이면 false, 6자 이상이면 true
      expect(rule.validate('12345')).toBe(false); // 5자 - 미달
      expect(rule.validate('123456')).toBe(true); // 6자 - 통과 (defaultParam=6)
    });

    it('hasUppercase 규칙이 올바르게 동작해야 함', () => {
      const rule = availablePasswordRules.hasUppercase;
      expect(rule.validate('lowercase')).toBe(false);
      expect(rule.validate('Uppercase')).toBe(true);
    });

    it('hasLowercase 규칙이 올바르게 동작해야 함', () => {
      const rule = availablePasswordRules.hasLowercase;
      expect(rule.validate('UPPERCASE')).toBe(false);
      expect(rule.validate('lowerCASE')).toBe(true);
    });

    it('hasNumber 규칙이 올바르게 동작해야 함', () => {
      const rule = availablePasswordRules.hasNumber;
      expect(rule.validate('noNumbers')).toBe(false);
      expect(rule.validate('has1Number')).toBe(true);
    });

    it('hasSpecial 규칙이 올바르게 동작해야 함', () => {
      const rule = availablePasswordRules.hasSpecial;
      expect(rule.validate('noSpecial')).toBe(false);
      expect(rule.validate('has@Special')).toBe(true);
    });
  });

  describe('다국어 지원', () => {
    it('G7Core.t()가 호출되어야 함', () => {
      render(
        <PasswordInput
          name="password"
          showValidation={true}
          defaultValue="test"
          data-testid="password-input"
        />
      );

      // t() 함수가 규칙 라벨 번역을 위해 호출되었는지 확인
      // 참고: showToggle=true(기본값)이면 'auth.password_input.show'가 먼저 호출됨
      // 규칙 번역 시 count 파라미터도 함께 전달됨 (defaultParam=6)
      expect(mockT).toHaveBeenCalledWith('auth.password_input.rules.minLength', { count: 6 });
    });

    it('비밀번호 확인 시 다국어 메시지가 표시되어야 함', () => {
      render(
        <PasswordInput
          name="password_confirmation"
          isConfirmField={true}
          confirmTarget="password123"
          defaultValue="password123"
          data-testid="confirm-input"
        />
      );

      // 일치 메시지 번역이 호출되었는지 확인 (호출 순서는 showToggle 버튼 aria-label이 먼저)
      expect(mockT).toHaveBeenCalledWith('auth.password_input.match', undefined);
    });
  });

  describe('defaultPasswordRules', () => {
    it('기본 규칙에 minLength만 포함되어야 함', () => {
      expect(defaultPasswordRules).toHaveLength(1);
      expect(defaultPasswordRules[0].key).toBe('minLength');
    });
  });

  describe('CapsLock 감지', () => {
    /**
     * jsdom에서 fireEvent로 getModifierState를 전달해도 React 이벤트에 반영되지 않음
     * KeyboardEvent를 직접 생성하고 getModifierState를 프로토타입에 추가해야 함
     * 또한 dispatchEvent는 React 상태 업데이트를 일으키므로 act()로 래핑 필요
     */
    const createKeyboardEvent = (type: 'keydown' | 'keyup', capsLockOn: boolean) => {
      const event = new KeyboardEvent(type, { bubbles: true, key: 'A' });
      Object.defineProperty(event, 'getModifierState', {
        value: (key: string) => key === 'CapsLock' && capsLockOn,
      });
      return event;
    };

    it('CapsLock이 켜져 있을 때 경고 메시지가 표시되어야 함', () => {
      render(<PasswordInput name="password" data-testid="password-input" />);
      const input = screen.getByTestId('password-input');

      // CapsLock이 켜진 상태로 keyDown 이벤트 발생
      act(() => {
        input.dispatchEvent(createKeyboardEvent('keydown', true));
      });

      expect(screen.getByText('CapsLock이 켜져 있습니다')).toBeInTheDocument();
    });

    it('CapsLock이 꺼지면 경고 메시지가 사라져야 함', () => {
      render(<PasswordInput name="password" data-testid="password-input" />);
      const input = screen.getByTestId('password-input');

      // CapsLock 켜짐
      act(() => {
        input.dispatchEvent(createKeyboardEvent('keydown', true));
      });
      expect(screen.getByText('CapsLock이 켜져 있습니다')).toBeInTheDocument();

      // CapsLock 꺼짐
      act(() => {
        input.dispatchEvent(createKeyboardEvent('keyup', false));
      });
      expect(screen.queryByText('CapsLock이 켜져 있습니다')).not.toBeInTheDocument();
    });

    it('비밀번호가 보이는 상태에서는 CapsLock 경고가 표시되지 않아야 함', () => {
      render(<PasswordInput name="password" data-testid="password-input" />);
      const input = screen.getByTestId('password-input');
      const toggleButton = screen.getByRole('button', { name: /비밀번호 보기/i });

      // CapsLock 켜짐
      act(() => {
        input.dispatchEvent(createKeyboardEvent('keydown', true));
      });

      // 비밀번호 보기 활성화
      fireEvent.click(toggleButton);

      // CapsLock 경고가 표시되지 않아야 함 (showPassword=true면 경고 숨김)
      expect(screen.queryByText('CapsLock이 켜져 있습니다')).not.toBeInTheDocument();
    });

    it('CapsLock 경고 아이콘이 올바르게 표시되어야 함', () => {
      render(<PasswordInput name="password" data-testid="password-input" />);
      const input = screen.getByTestId('password-input');

      act(() => {
        input.dispatchEvent(createKeyboardEvent('keydown', true));
      });

      // triangle-exclamation 아이콘이 표시되어야 함
      expect(screen.getByTestId('icon-triangle-exclamation')).toBeInTheDocument();
    });
  });
});
