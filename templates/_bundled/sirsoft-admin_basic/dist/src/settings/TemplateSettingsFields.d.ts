import { default as React } from 'react';
import { EditorAttrs } from '../types';
/** 선택지 서술 */
export interface TemplateSettingOption {
    value: string;
    label: string;
}
/** 칸 서술 — 조회 응답 `sections[].fields[]` */
export interface TemplateSettingField {
    key: string;
    type: string;
    label: string;
    hint?: string | null;
    placeholder?: string | null;
    required?: boolean;
    sensitive?: boolean;
    min?: number;
    max?: number;
    step?: number;
    max_length?: number;
    max_items?: number;
    options?: TemplateSettingOption[];
    collection?: string;
    max_files?: number;
    max_size_kb?: number | null;
    accept?: string;
    visible_if?: {
        field: string;
        equals?: unknown;
        in?: unknown[];
    };
    item_fields?: TemplateSettingField[];
}
/** 섹션 서술 */
export interface TemplateSettingSection {
    key: string;
    label: string;
    hint?: string | null;
    fields: TemplateSettingField[];
}
export type TemplateSettingsValue = Record<string, Record<string, any>>;
export interface TemplateSettingsFieldsProps {
    /** 섹션·칸 서술 (조회 응답 `data.sections`) */
    sections?: TemplateSettingSection[];
    /** 현재 값 (섹션 중첩) */
    value?: TemplateSettingsValue | null;
    /** 서버 검증 오류 (`{경로: [문구]}`) */
    errors?: Record<string, string[] | string> | null;
    /** 읽기 전용 */
    disabled?: boolean;
    /** 첨부 칸 업로드 신호 이름 */
    uploadTriggerEvent?: string;
    /** 값이 바뀌면 폼 전체 */
    onChange?: (next: TemplateSettingsValue) => void;
    /** 파일만 고르고 아직 올리지 않은 상태 등 — 저장 버튼을 풀어 주는 표시용 */
    onDirty?: () => void;
    /** 업로드 실패 문구 */
    onUploadError?: (message: string) => void;
    /** DOM id (레이아웃 편집기 코어 일괄 ID) */
    id?: string;
    /** 레이아웃 편집기 주입 속성 */
    editorAttrs?: EditorAttrs;
}
/**
 * 템플릿 설정 칸 묶음
 */
export declare const TemplateSettingsFields: React.FC<TemplateSettingsFieldsProps>;
export default TemplateSettingsFields;
