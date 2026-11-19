import { default as React } from 'react';
export interface FilePickerProps {
    /** 단추 글자 */
    label: string;
    /** 접근 이름 (칸 라벨) */
    ariaLabel: string;
    /** 허용 형식 (파일 입력칸 accept) */
    accept?: string;
    /** 단추 클래스 */
    className?: string;
    /** 입력칸 클래스 (숨김) */
    inputClassName?: string;
    /** 파일을 고르면 첫 파일 (취소하면 null) */
    onPick: (file: File | null) => void;
}
/**
 * 파일 고르기
 */
export declare const FilePicker: React.FC<FilePickerProps>;
export default FilePicker;
