import { default as React } from 'react';
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
export declare const availablePasswordRules: Record<string, PasswordRule>;
/**
 * 기본 비밀번호 검증 규칙
 *
 * 백엔드 검증 규칙(RegisterRequest.php)과 동일:
 * - password: required|string|min:6|confirmed
 */
export declare const defaultPasswordRules: PasswordRule[];
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
export declare const PasswordInput: React.ForwardRefExoticComponent<PasswordInputProps & React.RefAttributes<HTMLInputElement>>;
