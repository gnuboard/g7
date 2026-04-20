import { default as React } from 'react';
export interface FileInputProps extends Omit<React.InputHTMLAttributes<HTMLInputElement>, 'type' | 'onChange' | 'onError'> {
    /** 허용되는 파일 확장자 (예: ".zip,.pdf") */
    accept?: string;
    /** 최대 파일 크기 (MB) */
    maxSize?: number;
    /** 파일 선택 시 콜백 ({ target: { value } } 패턴) */
    onChange?: (event: {
        target: {
            value: File | null;
            name: string;
        };
    }) => void;
    /** 에러 발생 시 콜백 */
    onError?: (error: string) => void;
    /** 버튼 텍스트 */
    buttonText?: string;
    /** 빈 상태 텍스트 */
    placeholder?: string;
}
/**
 * 파일 업로드 입력 컴포넌트
 *
 * ZIP 파일 등의 파일 업로드를 처리하며, 파일 크기 검증을 지원합니다.
 */
export declare const FileInput: React.ForwardRefExoticComponent<FileInputProps & React.RefAttributes<HTMLInputElement>>;
