import { default as React } from 'react';
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
    onSubmit?: (data: {
        email: string;
        password: string;
    }) => void;
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
 * LoginForm 집합 컴포넌트
 *
 * 관리자 로그인 폼의 UI 및 상태 관리를 담당합니다.
 * - 이메일/비밀번호 입력 폼
 * - 유효성 검증
 * - 로딩 상태 표시
 * - 다크 모드 지원
 * - Laravel Sanctum API 통신
 */
export declare const LoginForm: React.FC<LoginFormProps>;
