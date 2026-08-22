import React, { useState, type FormEvent } from 'react';
import { Div } from '../basic/Div';
import { Form } from '../basic/Form';
import { Label } from '../basic/Label';
import { Input } from '../basic/Input';
import { Button } from '../basic/Button';
import { A } from '../basic/A';
import { P } from '../basic/P';
import { Svg } from '../basic/Svg';

/**
 * LoginForm props 인터페이스
 */
export interface LoginFormProps {
  /** 이메일 라벨 텍스트 */
  emailLabel?: string;
  /** 비밀번호 라벨 텍스트 */
  passwordLabel?: string;
  /** 로그인 버튼 텍스트 */
  submitButtonText?: string;
  /** 처리 중 텍스트 */
  processingText?: string;
  /** 이메일 입력 필드 placeholder */
  emailPlaceholder?: string;
  /** 비밀번호 입력 필드 placeholder */
  passwordPlaceholder?: string;
  /** 비밀번호 찾기 텍스트 */
  forgotPasswordText?: string;
  /** 비밀번호 찾기 URL */
  forgotPasswordUrl?: string;
  /** 이메일 필수 입력 에러 메시지 */
  emailRequiredError?: string;
  /** 이메일 형식 에러 메시지 */
  emailInvalidError?: string;
  /** 비밀번호 필수 입력 에러 메시지 */
  passwordRequiredError?: string;
  /** 폼 제출 핸들러 */
  onSubmit?: (data: { email: string; password: string }) => void;
  /** 로딩 액션 맵 (ActionDispatcher에서 전달) */
  loadingActions?: Record<string, boolean>;
  /** 외부에서 직접 로딩 상태 제어 */
  isLoading?: boolean;
  /** 외부에서 직접 비활성화 상태 제어 */
  disabled?: boolean;
  /** API 에러 메시지 (ActionDispatcher에서 전달) */
  apiError?: string;
  /** 커스텀 className */
  className?: string;
  /** 커스텀 스타일 */
  style?: React.CSSProperties;
}

/**
 * 에러 상태 인터페이스
 */
interface FormErrors {
  email?: string;
  password?: string;
}

/**
 * LoginForm 집합 컴포넌트
 *
 * 관리자 로그인 폼의 UI 및 상태 관리를 담당합니다.
 * - 이메일/비밀번호 입력 폼
 * - 유효성 검증
 * - 로딩 상태 표시
 * - 다크 모드 지원
 * - Laravel Sanctum API 통신
 */
export const LoginForm: React.FC<LoginFormProps> = ({
  emailLabel = 'Email',
  passwordLabel = 'Password',
  submitButtonText = 'Sign In',
  processingText = 'Processing...',
  emailPlaceholder = 'Email',
  passwordPlaceholder = 'Password',
  forgotPasswordText = 'Forgot your password?',
  forgotPasswordUrl = '/admin/password/reset',
  emailRequiredError = 'Please enter your email.',
  emailInvalidError = 'Please enter a valid email address.',
  passwordRequiredError = 'Please enter your password.',
  onSubmit,
  loadingActions = {},
  isLoading: isLoadingProp = false,
  disabled: disabledProp = false,
  apiError: apiErrorProp = '',
  className = '',
  style,
}) => {
  // 폼 상태 관리
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [errors, setErrors] = useState<FormErrors>({});

  // ActionDispatcher에서 전달된 apiError를 사용 (prop이 우선)
  const apiError = apiErrorProp;

  // loadingActions로부터 isLoading 계산
  // 외부 isLoading prop 또는 loadingActions 중 하나라도 true이면 로딩 중
  const isLoadingFromActions = Object.values(loadingActions).some((loading) => loading === true);
  const isLoading = isLoadingProp || isLoadingFromActions;
  const isDisabled = disabledProp || isLoading;

  /**
   * 유효성 검증
   */
  const validate = (): boolean => {
    const newErrors: FormErrors = {};

    // 이메일 검증 (RFC 5322 기반 더 엄격한 정규식)
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    if (!email || email.trim() === '') {
      newErrors.email = emailRequiredError;
    } else if (!emailRegex.test(email)) {
      newErrors.email = emailInvalidError;
    }

    // 비밀번호 검증
    if (!password || password.trim() === '') {
      newErrors.password = passwordRequiredError;
    }

    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  /**
   * 폼 제출 핸들러 래퍼
   *
   * ActionDispatcher의 핸들러를 실행하기 전에 클라이언트 검증을 수행합니다.
   */
  const handleSubmit = async (e: FormEvent<HTMLFormElement>): Promise<void> => {
    // 유효성 검증
    if (!validate()) {
      e.preventDefault();
      return;
    }

    // 에러 초기화 (클라이언트 검증 에러만)
    setErrors({});

    // ActionDispatcher의 핸들러 실행 (onSubmit prop으로 전달됨)
    if (onSubmit) {
      // onSubmit은 ActionDispatcher.createHandler가 생성한 핸들러
      // 내부적으로 event.preventDefault(), FormData 추출, API 호출을 처리함
      // onError 핸들러에서 setError를 사용하면 apiError prop이 업데이트됨
      await (onSubmit as any)(e);
    } else {
      // onSubmit이 없으면 기본 동작 방지
      e.preventDefault();
    }
  };

  return (
    <Div className={`w-full ${className}`} style={style}>
      <Form onSubmit={handleSubmit} className="space-y-6" noValidate>
        {/* API 에러 메시지 */}
        {apiError && (
          <Div className="p-4 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800">
            <P className="text-sm text-red-600 dark:text-red-400">{apiError}</P>
          </Div>
        )}

        {/* 이메일 입력 필드 */}
        <Div>
          <Label
            htmlFor="email"
            className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2"
          >
            {emailLabel}
          </Label>
          <Input
            id="email"
            name="email"
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            placeholder={emailPlaceholder}
            disabled={isDisabled}
            className={`
              w-full px-4 py-3 rounded-lg border
              ${
                errors.email
                  ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                  : 'border-gray-300 dark:border-gray-600 focus:border-blue-500 focus:ring-blue-500'
              }
              bg-white dark:bg-gray-800
              text-gray-900 dark:text-white
              placeholder-gray-400 dark:placeholder-gray-500
              focus:outline-none focus:ring-2
              disabled:opacity-50 disabled:cursor-not-allowed
              transition-colors duration-200
            `}
          />
          {errors.email && (
            <P className="mt-2 text-sm text-red-600 dark:text-red-400">
              {errors.email}
            </P>
          )}
        </Div>

        {/* 비밀번호 입력 필드 */}
        <Div>
          <Label
            htmlFor="password"
            className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2"
          >
            {passwordLabel}
          </Label>
          <Input
            id="password"
            name="password"
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            placeholder={passwordPlaceholder}
            disabled={isDisabled}
            className={`
              w-full px-4 py-3 rounded-lg border
              ${
                errors.password
                  ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                  : 'border-gray-300 dark:border-gray-600 focus:border-blue-500 focus:ring-blue-500'
              }
              bg-white dark:bg-gray-800
              text-gray-900 dark:text-white
              placeholder-gray-400 dark:placeholder-gray-500
              focus:outline-none focus:ring-2
              disabled:opacity-50 disabled:cursor-not-allowed
              transition-colors duration-200
            `}
          />
          {errors.password && (
            <P className="mt-2 text-sm text-red-600 dark:text-red-400">
              {errors.password}
            </P>
          )}
        </Div>

        {/* 비밀번호 찾기 링크 */}
        {forgotPasswordUrl && (
          <Div className="flex items-center justify-end">
            <A
              href={forgotPasswordUrl}
              className="text-sm text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300 transition-colors duration-200"
            >
              {forgotPasswordText}
            </A>
          </Div>
        )}

        {/* 제출 버튼 */}
        <Button
          type="submit"
          disabled={isDisabled}
          className={`
            w-full px-4 py-3 rounded-lg font-medium
            bg-blue-600 hover:bg-blue-700
            dark:bg-blue-500 dark:hover:bg-blue-600
            text-white
            focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2
            dark:focus:ring-offset-gray-900
            disabled:opacity-50 disabled:cursor-not-allowed
            transition-colors duration-200
            flex items-center justify-center
          `}
        >
          {isLoading ? (
            <>
              <Svg
                className="animate-spin -ml-1 mr-3 h-5 w-5 text-white"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
              >
                <circle
                  className="opacity-25"
                  cx="12"
                  cy="12"
                  r="10"
                  stroke="currentColor"
                  strokeWidth="4"
                ></circle>
                <path
                  className="opacity-75"
                  fill="currentColor"
                  d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                ></path>
              </Svg>
              {processingText}
            </>
          ) : (
            submitButtonText
          )}
        </Button>
      </Form>
    </Div>
  );
};
