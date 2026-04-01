import { default as React } from 'react';
export interface InputProps extends React.InputHTMLAttributes<HTMLInputElement> {
    label?: string;
    error?: string;
}
/**
 * 기본 입력 필드 컴포넌트
 *
 * IME(한글 등) 조합 중에는 외부 onChange 이벤트를 발생시키지 않습니다.
 * 내부 로컬 상태를 사용하여 입력을 처리하고, 조합이 완료되면 외부에 알립니다.
 *
 * forwardRef를 사용하여 외부에서 ref로 input 요소에 접근할 수 있습니다.
 * 예: indeterminate 상태 설정 시 ref.current.indeterminate = true
 */
export declare const Input: React.ForwardRefExoticComponent<InputProps & React.RefAttributes<HTMLInputElement>>;
