import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { LoginForm } from '../LoginForm';
import type { LoginFormProps } from '../LoginForm';

// 한국어 라벨 props (컴포넌트 기본값은 영어)
const koreanLabels = {
  emailLabel: '이메일',
  passwordLabel: '비밀번호',
  submitButtonText: '로그인',
  forgotPasswordText: '비밀번호를 잊으셨나요?',
  emailRequiredError: '이메일을 입력해주세요.',
  emailInvalidError: '올바른 이메일 형식이 아닙니다.',
  passwordRequiredError: '비밀번호를 입력해주세요.',
};

describe('LoginForm', () => {
  let onSubmitMock: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    onSubmitMock = vi.fn();
    // localStorage 모킹
    const localStorageMock = {
      getItem: vi.fn(),
      setItem: vi.fn(),
      removeItem: vi.fn(),
      clear: vi.fn(),
    };
    Object.defineProperty(window, 'localStorage', {
      value: localStorageMock,
      writable: true,
    });

    // location.href 모킹
    delete (window as any).location;
    (window as any).location = { href: '' };

    // fetch 모킹 초기화
    global.fetch = vi.fn();
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  describe('렌더링 테스트', () => {
    it('이메일 input이 렌더링되어야 함', () => {
      render(<LoginForm {...koreanLabels} />);
      const emailInput = screen.getByLabelText('이메일');
      expect(emailInput).toBeInTheDocument();
      expect(emailInput).toHaveAttribute('type', 'email');
    });

    it('비밀번호 input이 렌더링되어야 함', () => {
      render(<LoginForm {...koreanLabels} />);
      const passwordInput = screen.getByLabelText('비밀번호');
      expect(passwordInput).toBeInTheDocument();
      expect(passwordInput).toHaveAttribute('type', 'password');
    });

    it('제출 버튼이 렌더링되어야 함', () => {
      render(<LoginForm {...koreanLabels} />);
      const submitButton = screen.getByRole('button', { name: /로그인/i });
      expect(submitButton).toBeInTheDocument();
    });

    it('비밀번호 찾기 링크가 렌더링되어야 함', () => {
      render(<LoginForm {...koreanLabels} />);
      const forgotPasswordLink = screen.getByText('비밀번호를 잊으셨나요?');
      expect(forgotPasswordLink).toBeInTheDocument();
      expect(forgotPasswordLink).toHaveAttribute('href', '/admin/password/reset');
    });

    it('커스텀 props가 올바르게 적용되어야 함', () => {
      const props: LoginFormProps = {
        submitButtonText: '로그인하기',
        emailPlaceholder: '이메일 주소',
        passwordPlaceholder: '비밀번호 입력',
        forgotPasswordText: '비밀번호 재설정',
        forgotPasswordUrl: '/reset-password',
      };

      render(<LoginForm {...props} />);

      expect(screen.getByRole('button', { name: '로그인하기' })).toBeInTheDocument();
      expect(screen.getByPlaceholderText('이메일 주소')).toBeInTheDocument();
      expect(screen.getByPlaceholderText('비밀번호 입력')).toBeInTheDocument();
      expect(screen.getByText('비밀번호 재설정')).toHaveAttribute('href', '/reset-password');
    });
  });

  describe('유효성 검증 테스트', () => {
    it('빈 이메일 제출 시 에러 메시지가 표시되어야 함', async () => {
      render(<LoginForm {...koreanLabels} onSubmit={onSubmitMock} />);

      const submitButton = screen.getByRole('button', { name: /로그인/i });
      fireEvent.click(submitButton);

      await waitFor(() => {
        expect(screen.getByText('이메일을 입력해주세요.')).toBeInTheDocument();
      });

      expect(onSubmitMock).not.toHaveBeenCalled();
    });

    it('잘못된 이메일 형식 제출 시 에러 메시지가 표시되어야 함', async () => {
      render(<LoginForm {...koreanLabels} onSubmit={onSubmitMock} />);

      const emailInput = screen.getByLabelText('이메일');
      // 명확하게 유효하지 않은 이메일 형식들 테스트
      const invalidEmails = ['test', 'test@', '@example.com', 'test@example'];

      for (const invalidEmail of invalidEmails) {
        fireEvent.change(emailInput, { target: { value: invalidEmail } });

        const submitButton = screen.getByRole('button', { name: /로그인/i });
        fireEvent.click(submitButton);

        await waitFor(() => {
          expect(screen.getByText('올바른 이메일 형식이 아닙니다.')).toBeInTheDocument();
        });

        expect(onSubmitMock).not.toHaveBeenCalled();

        // 다음 테스트를 위해 에러 상태 초기화
        fireEvent.change(emailInput, { target: { value: '' } });
      }
    });

    it('빈 비밀번호 제출 시 에러 메시지가 표시되어야 함', async () => {
      render(<LoginForm {...koreanLabels} onSubmit={onSubmitMock} />);

      const emailInput = screen.getByLabelText('이메일');
      fireEvent.change(emailInput, { target: { value: 'test@example.com' } });

      const submitButton = screen.getByRole('button', { name: /로그인/i });
      fireEvent.click(submitButton);

      await waitFor(() => {
        expect(screen.getByText('비밀번호를 입력해주세요.')).toBeInTheDocument();
      });

      expect(onSubmitMock).not.toHaveBeenCalled();
    });

    it('올바른 입력 시 에러 메시지가 표시되지 않아야 함', async () => {
      render(<LoginForm {...koreanLabels} onSubmit={onSubmitMock} />);

      const emailInput = screen.getByLabelText('이메일');
      const passwordInput = screen.getByLabelText('비밀번호');

      fireEvent.change(emailInput, { target: { value: 'test@example.com' } });
      fireEvent.change(passwordInput, { target: { value: 'password123' } });

      const submitButton = screen.getByRole('button', { name: /로그인/i });
      fireEvent.click(submitButton);

      await waitFor(() => {
        expect(screen.queryByText('이메일을 입력해주세요.')).not.toBeInTheDocument();
        expect(screen.queryByText('올바른 이메일 형식이 아닙니다.')).not.toBeInTheDocument();
        expect(screen.queryByText('비밀번호를 입력해주세요.')).not.toBeInTheDocument();
      });
    });
  });

  describe('상태 관리 테스트', () => {
    it('이메일 입력 시 상태가 업데이트되어야 함', () => {
      render(<LoginForm {...koreanLabels} />);

      const emailInput = screen.getByLabelText('이메일') as HTMLInputElement;
      fireEvent.change(emailInput, { target: { value: 'test@example.com' } });

      expect(emailInput.value).toBe('test@example.com');
    });

    it('비밀번호 입력 시 상태가 업데이트되어야 함', () => {
      render(<LoginForm {...koreanLabels} />);

      const passwordInput = screen.getByLabelText('비밀번호') as HTMLInputElement;
      fireEvent.change(passwordInput, { target: { value: 'password123' } });

      expect(passwordInput.value).toBe('password123');
    });
  });

  describe('이벤트 핸들러 테스트', () => {
    it('올바른 입력 시 onSubmit 콜백이 호출되어야 함', async () => {
      render(<LoginForm {...koreanLabels} onSubmit={onSubmitMock} />);

      const emailInput = screen.getByLabelText('이메일');
      const passwordInput = screen.getByLabelText('비밀번호');

      fireEvent.change(emailInput, { target: { value: 'test@example.com' } });
      fireEvent.change(passwordInput, { target: { value: 'password123' } });

      const submitButton = screen.getByRole('button', { name: /로그인/i });
      fireEvent.click(submitButton);

      await waitFor(() => {
        // LoginForm은 FormEvent를 onSubmit에 전달함
        expect(onSubmitMock).toHaveBeenCalled();
      });
    });

    it('유효성 검증 실패 시 preventDefault가 호출되어야 함', async () => {
      // LoginForm은 유효성 검증 실패 시에만 자체적으로 preventDefault를 호출함
      // onSubmit이 있는 경우, 검증 통과 후에는 핸들러에게 제어를 넘김
      render(<LoginForm {...koreanLabels} onSubmit={onSubmitMock} />);

      // 빈 입력 상태에서 제출 시도 (유효성 검증 실패)
      const form = screen.getByRole('button', { name: /로그인/i }).closest('form')!;
      const submitEvent = new Event('submit', { bubbles: true, cancelable: true });
      const preventDefaultSpy = vi.spyOn(submitEvent, 'preventDefault');

      fireEvent(form, submitEvent);

      // 유효성 검증 실패로 preventDefault가 호출되어야 함
      expect(preventDefaultSpy).toHaveBeenCalled();
    });
  });

  describe('접근성 테스트', () => {
    it('이메일 input에 올바른 label이 연결되어야 함', () => {
      render(<LoginForm {...koreanLabels} />);

      const emailInput = screen.getByLabelText('이메일');
      expect(emailInput).toHaveAttribute('id', 'email');
    });

    it('비밀번호 input에 올바른 label이 연결되어야 함', () => {
      render(<LoginForm {...koreanLabels} />);

      const passwordInput = screen.getByLabelText('비밀번호');
      expect(passwordInput).toHaveAttribute('id', 'password');
    });

    it('이메일과 비밀번호 input에 name 속성이 있어야 함', () => {
      render(<LoginForm {...koreanLabels} />);

      const emailInput = screen.getByLabelText('이메일');
      const passwordInput = screen.getByLabelText('비밀번호');

      expect(emailInput).toHaveAttribute('name', 'email');
      expect(passwordInput).toHaveAttribute('name', 'password');
    });
  });

  describe('스타일 테스트', () => {
    it('에러 상태에서 input에 에러 스타일이 적용되어야 함', async () => {
      render(<LoginForm {...koreanLabels} onSubmit={onSubmitMock} />);

      const submitButton = screen.getByRole('button', { name: /로그인/i });
      fireEvent.click(submitButton);

      await waitFor(() => {
        const emailInput = screen.getByLabelText('이메일');
        expect(emailInput).toHaveClass('border-red-500');
      });
    });

    it('커스텀 className이 적용되어야 함', () => {
      const { container } = render(<LoginForm className="custom-class" />);
      const formContainer = container.firstChild;
      expect(formContainer).toHaveClass('custom-class');
    });

    it('커스텀 style이 적용되어야 함', () => {
      const customStyle = { backgroundColor: 'red' };
      const { container } = render(<LoginForm style={customStyle} />);
      // style은 최상위 Div 컴포넌트 (w-full 클래스가 있는 첫 번째 요소)에 적용됨
      const wrapper = container.firstChild as HTMLElement;
      expect(wrapper).toHaveAttribute('style', 'background-color: red;');
    });
  });

  describe('템플릿 엔진 통합 테스트 (ActionDispatcher와 함께)', () => {
    it('ActionDispatcher를 통한 API 호출이 성공하면 onSubmit이 호출되어야 함', async () => {
      render(<LoginForm {...koreanLabels} onSubmit={onSubmitMock} />);

      const emailInput = screen.getByLabelText('이메일');
      const passwordInput = screen.getByLabelText('비밀번호');

      fireEvent.change(emailInput, { target: { value: 'admin@example.com' } });
      fireEvent.change(passwordInput, { target: { value: 'password123' } });

      const submitButton = screen.getByRole('button', { name: /로그인/i });
      fireEvent.click(submitButton);

      // LoginForm은 FormEvent를 onSubmit에 전달함
      await waitFor(() => {
        expect(onSubmitMock).toHaveBeenCalled();
      });
    });

    it('CSRF 토큰 요청이 올바른 옵션으로 호출되는지 확인 (ActionDispatcher 통합)', async () => {
      const csrfFetchMock = vi.fn().mockResolvedValue({
        ok: true,
      });

      const loginFetchMock = vi.fn().mockResolvedValue({
        ok: true,
        json: async () => ({ success: true }),
      });

      global.fetch = vi.fn((url, options) => {
        if (url === '/sanctum/csrf-cookie') {
          return csrfFetchMock(url, options);
        }
        if (url === '/api/auth/admin/login') {
          return loginFetchMock(url, options);
        }
        return Promise.reject(new Error('Unknown URL'));
      }) as any;

      // ActionDispatcher를 시뮬레이션하는 핸들러 생성
      // LoginForm은 FormEvent를 전달하므로, 이벤트에서 FormData를 추출해야 함
      const actionDispatcherHandler = vi.fn(async (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        const formData = new FormData(e.currentTarget);
        const data = {
          email: formData.get('email') as string,
          password: formData.get('password') as string,
        };

        // CSRF 토큰 가져오기
        await fetch('/sanctum/csrf-cookie', {
          credentials: 'include',
        });

        // API 호출
        await fetch('/api/auth/admin/login', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
          },
          credentials: 'include',
          body: JSON.stringify(data),
        });
      });

      render(<LoginForm {...koreanLabels} onSubmit={actionDispatcherHandler} />);

      const emailInput = screen.getByLabelText('이메일');
      const passwordInput = screen.getByLabelText('비밀번호');

      fireEvent.change(emailInput, { target: { value: 'admin@example.com' } });
      fireEvent.change(passwordInput, { target: { value: 'password123' } });

      const submitButton = screen.getByRole('button', { name: /로그인/i });
      fireEvent.click(submitButton);

      await waitFor(() => {
        expect(csrfFetchMock).toHaveBeenCalledWith(
          '/sanctum/csrf-cookie',
          expect.objectContaining({
            credentials: 'include',
          })
        );
      });
    });

    it('로그인 API 요청이 올바른 body와 headers로 호출되는지 확인 (ActionDispatcher 통합)', async () => {
      const csrfFetchMock = vi.fn().mockResolvedValue({
        ok: true,
      });

      const loginFetchMock = vi.fn().mockResolvedValue({
        ok: true,
        json: async () => ({ success: true }),
      });

      global.fetch = vi.fn((url, options) => {
        if (url === '/sanctum/csrf-cookie') {
          return csrfFetchMock(url, options);
        }
        if (url === '/api/auth/admin/login') {
          return loginFetchMock(url, options);
        }
        return Promise.reject(new Error('Unknown URL'));
      }) as any;

      // ActionDispatcher를 시뮬레이션하는 핸들러 생성
      // LoginForm은 FormEvent를 전달하므로, 이벤트에서 FormData를 추출해야 함
      const actionDispatcherHandler = vi.fn(async (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        const formData = new FormData(e.currentTarget);
        const data = {
          email: formData.get('email') as string,
          password: formData.get('password') as string,
        };

        // CSRF 토큰 가져오기
        await fetch('/sanctum/csrf-cookie', {
          credentials: 'include',
        });

        // API 호출
        await fetch('/api/auth/admin/login', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
          },
          credentials: 'include',
          body: JSON.stringify(data),
        });
      });

      render(<LoginForm {...koreanLabels} onSubmit={actionDispatcherHandler} />);

      const emailInput = screen.getByLabelText('이메일');
      const passwordInput = screen.getByLabelText('비밀번호');

      fireEvent.change(emailInput, { target: { value: 'admin@example.com' } });
      fireEvent.change(passwordInput, { target: { value: 'password123' } });

      const submitButton = screen.getByRole('button', { name: /로그인/i });
      fireEvent.click(submitButton);

      await waitFor(() => {
        expect(loginFetchMock).toHaveBeenCalledWith(
          '/api/auth/admin/login',
          expect.objectContaining({
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              Accept: 'application/json',
            },
            credentials: 'include',
            body: JSON.stringify({
              email: 'admin@example.com',
              password: 'password123',
            }),
          })
        );
      });
    });
  });
});
