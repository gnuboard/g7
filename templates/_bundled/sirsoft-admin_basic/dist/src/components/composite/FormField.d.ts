import { default as React } from 'react';
import { EditorAttrs } from '../../types';
export interface FormFieldProps {
    /** 필드 레이블 */
    label?: string;
    /** 필수 필드 표시 여부 */
    required?: boolean;
    /** 에러 메시지 */
    error?: string;
    /** 도움말 텍스트 */
    helperText?: string;
    /** 추가 className */
    className?: string;
    /** 레이블 className */
    labelClassName?: string;
    /** 자식 요소 (필드 컨텐츠) */
    children?: React.ReactNode;
    /** 수평 레이아웃 여부 */
    horizontal?: boolean;
    /** 레이블 너비 (수평 레이아웃 시) */
    labelWidth?: string;
    /**
     * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
     */
    id?: string;
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
    editorAttrs?: EditorAttrs;
}
/**
 * FormField 컴포넌트
 *
 * 폼 필드에 레이블, 에러, 도움말을 제공하는 래퍼 컴포넌트입니다.
 * 수직(기본) 및 수평 레이아웃을 지원합니다.
 */
export declare const FormField: React.FC<FormFieldProps>;
