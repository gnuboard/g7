import React, { useState, useCallback, forwardRef, useImperativeHandle, useRef, useEffect, useMemo } from 'react';
import { Icon } from './Icon';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as unknown as { G7Core?: { t?: (key: string, params?: Record<string, string | number>) => string } })
    .G7Core?.t?.(key, params) ?? key;

/**
 * 비밀번호 검증 규칙 정의
 */
export interface PasswordRule {
  /** 규칙 식별자 */
  key: string;
  /** 규칙 설명 (다국어 키) */
  labelKey: string;
  /** 검증 함수 (param은 규칙별 설정값) */
  validate: (value: string, param?: number) => boolean;
  /** 기본 파라미터 값 */
  defaultParam?: number;
}

/**
 * 사용 가능한 모든 비밀번호 검증 규칙
 *
 * JSON 레이아웃에서 enableRules prop으로 키를 지정하여 선택적으로 활성화
 * 파라미터가 있는 규칙은 "규칙명:값" 형식으로 지정 (예: "minLength:12")
 */
export const availablePasswordRules: Record<string, PasswordRule> = {
  minLength: {
    key: 'minLength',
    labelKey: 'auth.password_input.rules.minLength',
    defaultParam: 6,
    validate: (value: string, param = 6) => value.length >= param,
  },
  maxLength: {
    key: 'maxLength',
    labelKey: 'auth.password_input.rules.maxLength',
    defaultParam: 20,
    validate: (value: string, param = 20) => value.length <= param,
  },
  hasUppercase: {
    key: 'hasUppercase',
    labelKey: 'auth.password_input.rules.hasUppercase',
    validate: (value: string) => /[A-Z]/.test(value),
  },
  hasLowercase: {
    key: 'hasLowercase',
    labelKey: 'auth.password_input.rules.hasLowercase',
    validate: (value: string) => /[a-z]/.test(value),
  },
  hasNumber: {
    key: 'hasNumber',
    labelKey: 'auth.password_input.rules.hasNumber',
    validate: (value: string) => /[0-9]/.test(value),
  },
  hasSpecial: {
    key: 'hasSpecial',
    labelKey: 'auth.password_input.rules.hasSpecial',
    validate: (value: string) => /[!@#$%^&*(),.?":{}|<>]/.test(value),
  },
  noSpaces: {
    key: 'noSpaces',
    labelKey: 'auth.password_input.rules.noSpaces',
    validate: (value: string) => !/\s/.test(value),
  },
  minTypes: {
    key: 'minTypes',
    labelKey: 'auth.password_input.rules.minTypes',
    defaultParam: 3,
    validate: (value: string, param = 3) => {
      let types = 0;
      if (/[A-Z]/.test(value)) types++;
      if (/[a-z]/.test(value)) types++;
      if (/[0-9]/.test(value)) types++;
      if (/[!@#$%^&*(),.?":{}|<>]/.test(value)) types++;
      return types >= param;
    },
  },
};

/**
 * 기본 비밀번호 검증 규칙
 *
 * 백엔드 검증 규칙(RegisterRequest.php)과 동일:
 * - password: required|string|min:6|confirmed
 */
export const defaultPasswordRules: PasswordRule[] = [
  availablePasswordRules.minLength,
];

/**
 * 파싱된 규칙 (규칙 + 파라미터)
 */
interface ParsedRule {
  rule: PasswordRule;
  param?: number;
}

/**
 * enableRules 문자열을 파싱하여 규칙과 파라미터를 추출
 * 예: "minLength:12" → { rule: minLengthRule, param: 12 }
 * 예: "hasUppercase" → { rule: hasUppercaseRule, param: undefined }
 *
 * @param ruleString 규칙 문자열 (예: "minLength:12" 또는 "hasUppercase")
 * @returns 파싱된 규칙 또는 null (규칙이 존재하지 않는 경우)
 */
function parseRuleString(ruleString: string): ParsedRule | null {
  const [key, paramStr] = ruleString.split(':');
  const rule = availablePasswordRules[key];
  if (!rule) return null;

  const param = paramStr ? parseInt(paramStr, 10) : rule.defaultParam;
  return { rule, param: isNaN(param as number) ? rule.defaultParam : param };
}

/**
 * PasswordInput에서 사용하지 않는 HTML 속성 목록
 * (그누보드7 템플릿 엔진에서 전달되는 커스텀 속성)
 */
interface NonStandardProps {
  loadingactions?: unknown;
  formdata?: unknown;
}

export interface PasswordInputProps extends Omit<React.InputHTMLAttributes<HTMLInputElement>, 'type'> {
  /** 레이블 텍스트 */
  label?: string;
  /** 에러 메시지 */
  error?: string;
  /** 비밀번호 보기/숨기기 버튼 표시 */
  showToggle?: boolean;
  /** 비밀번호 조건 검증 표시 */
  showValidation?: boolean;
  /** 비밀번호 확인 모드 (확인 필드로 사용) */
  isConfirmField?: boolean;
  /** 비밀번호 확인 대상 값 (isConfirmField가 true일 때 필요) */
  confirmTarget?: string;
  /** 비밀번호 확인 일치 여부 콜백 */
  onMatchChange?: (isMatch: boolean) => void;
  /** 비밀번호 유효성 변경 콜백 */
  onValidityChange?: (isValid: boolean, failedRules: string[]) => void;
  /** 커스텀 비밀번호 규칙 (기본 규칙 대체, 코드에서만 사용) */
  rules?: PasswordRule[];
  /**
   * 활성화할 규칙 키 목록 (JSON 레이아웃에서 사용)
   * 사용 가능: minLength, maxLength, hasUppercase, hasLowercase, hasNumber, hasSpecial, noSpaces, minTypes
   * 파라미터 지정: "규칙명:값" 형식 (예: "minLength:12", "minTypes:3")
   * 예: ["minLength:12", "hasUppercase", "hasSpecial"]
   */
  enableRules?: string[];
  /** 표시할 규칙 키 목록 (지정하지 않으면 전체 표시) */
  showRules?: string[];
  /** 입력 필드 wrapper 클래스 */
  wrapperClassName?: string;
  /** 검증 결과 표시 영역 클래스 */
  validationClassName?: string;
}

/**
 * 비밀번호 입력 컴포넌트
 *
 * 비밀번호 보기/숨기기 토글, 실시간 조건 검증, 비밀번호 확인 기능을 제공합니다.
 *
 * @example
 * // 기본 사용 (보기/숨기기만)
 * <PasswordInput name="password" showToggle />
 *
 * @example
 * // 조건 검증 표시
 * <PasswordInput
 *   name="password"
 *   showToggle
 *   showValidation
 *   onValidityChange={(isValid, failed) => console.log(isValid, failed)}
 * />
 *
 * @example
 * // 비밀번호 확인 필드
 * <PasswordInput
 *   name="password_confirmation"
 *   isConfirmField
 *   confirmTarget={password}
 *   onMatchChange={(isMatch) => console.log(isMatch)}
 * />
 */
export const PasswordInput = forwardRef<HTMLInputElement, PasswordInputProps>(
  (
    {
      label,
      error,
      showToggle = true,
      showValidation = false,
      isConfirmField = false,
      confirmTarget,
      onMatchChange,
      onValidityChange,
      rules,
      enableRules,
      showRules,
      wrapperClassName = '',
      validationClassName = '',
      className = '',
      onChange,
      value,
      defaultValue,
      disabled,
      ...props
    },
    ref
  ) => {
    const internalRef = useRef<HTMLInputElement>(null);
    useImperativeHandle(ref, () => internalRef.current as HTMLInputElement);

    // 비밀번호 보기/숨기기 상태
    const [showPassword, setShowPassword] = useState(false);

    // CapsLock 활성화 상태
    const [capsLockOn, setCapsLockOn] = useState(false);

    // 내부 값 상태 (controlled/uncontrolled 지원)
    const [internalValue, setInternalValue] = useState<string>(
      (value as string) ?? (defaultValue as string) ?? ''
    );

    // 외부 value가 변경되면 동기화
    useEffect(() => {
      if (value !== undefined) {
        setInternalValue(value as string);
      }
    }, [value]);

    // 사용할 규칙 결정: rules > enableRules > defaultPasswordRules
    const parsedRules = useMemo((): ParsedRule[] => {
      // 1. rules prop이 있으면 그대로 사용 (코드에서 직접 전달, 기본 파라미터 적용)
      if (rules && rules.length > 0) {
        return rules.map((rule) => ({ rule, param: rule.defaultParam }));
      }

      // 2. enableRules가 있으면 파싱하여 규칙과 파라미터 추출
      if (enableRules && enableRules.length > 0) {
        return enableRules
          .map((ruleStr) => parseRuleString(ruleStr))
          .filter((parsed): parsed is ParsedRule => parsed !== null);
      }

      // 3. 기본 규칙 사용
      return defaultPasswordRules.map((rule) => ({ rule, param: rule.defaultParam }));
    }, [rules, enableRules]);

    // 표시할 규칙 필터링
    const displayRules = useMemo(() => {
      if (!showRules || showRules.length === 0) return parsedRules;
      return parsedRules.filter((parsed) => showRules.includes(parsed.rule.key));
    }, [parsedRules, showRules]);

    // 비밀번호 규칙 검증 결과
    const validationResults = useMemo(() => {
      if (!showValidation || isConfirmField) return {};
      return displayRules.reduce((acc, { rule, param }) => {
        acc[rule.key] = rule.validate(internalValue, param);
        return acc;
      }, {} as Record<string, boolean>);
    }, [internalValue, displayRules, showValidation, isConfirmField]);

    // 비밀번호 확인 일치 여부
    const isMatch = useMemo(() => {
      if (!isConfirmField || confirmTarget === undefined) return true;
      return internalValue === confirmTarget && internalValue.length > 0;
    }, [isConfirmField, internalValue, confirmTarget]);

    // 전체 유효성 (모든 규칙 통과)
    const isAllValid = useMemo(() => {
      if (!showValidation) return true;
      return Object.values(validationResults).every((v) => v);
    }, [validationResults, showValidation]);

    // 유효성 변경 콜백
    useEffect(() => {
      if (onValidityChange && showValidation && !isConfirmField) {
        const failedRules = Object.entries(validationResults)
          .filter(([, passed]) => !passed)
          .map(([key]) => key);
        onValidityChange(isAllValid, failedRules);
      }
    }, [isAllValid, validationResults, onValidityChange, showValidation, isConfirmField]);

    // 비밀번호 확인 일치 콜백
    useEffect(() => {
      if (onMatchChange && isConfirmField) {
        onMatchChange(isMatch);
      }
    }, [isMatch, onMatchChange, isConfirmField]);

    // 비밀번호 보기/숨기기 토글
    const handleToggle = useCallback(() => {
      setShowPassword((prev) => !prev);
    }, []);

    // 입력값 변경 처리
    const handleChange = useCallback(
      (e: React.ChangeEvent<HTMLInputElement>) => {
        const newValue = e.target.value;
        setInternalValue(newValue);
        if (onChange) {
          onChange(e);
        }
      },
      [onChange]
    );

    // CapsLock 상태 감지
    const handleKeyEvent = useCallback(
      (e: React.KeyboardEvent<HTMLInputElement>) => {
        setCapsLockOn(e.getModifierState('CapsLock'));
      },
      []
    );

    // HTML 표준 속성이 아닌 것들을 필터링 (타입 안전성 개선)
    const { loadingactions, formdata, ...validProps } = props as typeof props & NonStandardProps;

    return (
      <div className={`relative ${wrapperClassName}`}>
        {/* 입력 필드 wrapper */}
        <div className="relative">
          <input
            ref={internalRef}
            type={showPassword ? 'text' : 'password'}
            className={`${className} ${showToggle ? 'pr-10' : ''}`}
            value={value !== undefined ? (value as string) : undefined}
            defaultValue={value === undefined ? defaultValue : undefined}
            onChange={handleChange}
            onKeyDown={handleKeyEvent}
            onKeyUp={handleKeyEvent}
            disabled={disabled}
            {...validProps}
          />

          {/* 비밀번호 보기/숨기기 버튼 */}
          {showToggle && (
            <button
              type="button"
              onClick={handleToggle}
              disabled={disabled}
              className="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 disabled:opacity-50 disabled:cursor-not-allowed dark:disabled:opacity-50"
              aria-label={showPassword ? t('auth.password_input.hide') : t('auth.password_input.show')}
            >
              <Icon
                name={showPassword ? 'eye-slash' : 'eye'}
                className="w-5 h-5"
              />
            </button>
          )}
        </div>

        {/* CapsLock 경고 */}
        {capsLockOn && !showPassword && (
          <div className="mt-2 flex items-center gap-1.5 text-sm text-yellow-600 dark:text-yellow-400">
            <Icon name="triangle-exclamation" className="w-4 h-4" />
            <span>{t('auth.password_input.caps_lock_on')}</span>
          </div>
        )}

        {/* 비밀번호 확인 일치/불일치 표시 */}
        {isConfirmField && confirmTarget !== undefined && internalValue.length > 0 && (
          <div
            className={`mt-2 flex items-center gap-1.5 text-sm ${
              isMatch
                ? 'text-green-600 dark:text-green-400'
                : 'text-red-600 dark:text-red-400'
            }`}
          >
            <Icon
              name={isMatch ? 'check-circle' : 'xmark-circle'}
              className="w-4 h-4"
            />
            <span>{isMatch ? t('auth.password_input.match') : t('auth.password_input.mismatch')}</span>
          </div>
        )}

        {/* 비밀번호 조건 검증 표시 */}
        {showValidation && !isConfirmField && internalValue.length > 0 && (
          <div className={`mt-2 space-y-1 ${validationClassName}`}>
            {displayRules.map(({ rule, param }) => {
              const passed = validationResults[rule.key];
              // 파라미터가 있는 규칙은 라벨에 파라미터 값 전달
              const labelParams = param !== undefined ? { count: param } : undefined;
              return (
                <div
                  key={rule.key}
                  className={`flex items-center gap-1.5 text-sm ${
                    passed
                      ? 'text-green-600 dark:text-green-400'
                      : 'text-gray-500 dark:text-gray-400'
                  }`}
                >
                  <Icon
                    name={passed ? 'check-circle' : 'circle'}
                    className="w-4 h-4"
                  />
                  <span>{t(rule.labelKey, labelParams)}</span>
                </div>
              );
            })}
          </div>
        )}

        {/* 에러 메시지 */}
        {error && (
          <p className="mt-1 text-sm text-red-600 dark:text-red-400">{error}</p>
        )}
      </div>
    );
  }
);

PasswordInput.displayName = 'PasswordInput';
