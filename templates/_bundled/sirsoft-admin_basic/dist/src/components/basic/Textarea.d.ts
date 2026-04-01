import { default as React } from 'react';
export interface TextareaProps extends React.TextareaHTMLAttributes<HTMLTextAreaElement> {
    label?: string;
    error?: string;
}
/**
 * 기본 텍스트 영역 컴포넌트
 *
 * IME(한글 등) 조합 중에는 외부 onChange 이벤트를 발생시키지 않습니다.
 * 내부 로컬 상태를 사용하여 입력을 처리하고, 조합이 완료되면 외부에 알립니다.
 */
export declare const Textarea: React.FC<TextareaProps>;
