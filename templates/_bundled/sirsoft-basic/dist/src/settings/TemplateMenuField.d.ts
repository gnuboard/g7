import { default as React } from 'react';
import { TemplateMenuNode } from './templateMenuTree';
/** 자동 항목 서술 — 조회 응답 `fields[].auto_items[]` */
export interface TemplateMenuAutoItem {
    key: string;
    label: string;
    hint?: string | null;
}
/** 역할 선택지 — `/api/admin/roles/active` 의 행 */
export interface TemplateMenuRoleOption {
    identifier: string;
    name?: string | Record<string, string>;
}
export interface TemplateMenuFieldProps {
    /** 칸 값 (항목 트리) */
    value?: TemplateMenuNode[] | null;
    /** 바뀌면 트리 전체 */
    onChange?: (next: TemplateMenuNode[]) => void;
    /** 칸 경로 (`섹션.키`) — 오류 위치를 찾는 데 쓴다 */
    path: string;
    /** 칸 이름 (접근성 이름) */
    label?: string;
    /** 전체 항목 수 상한 */
    maxItems?: number;
    /** 허용 깊이 (1 또는 2) */
    maxDepth?: number;
    /** 자동 항목 */
    autoItems?: TemplateMenuAutoItem[];
    /** 역할 선택지 */
    roleOptions?: TemplateMenuRoleOption[];
    /** 서버 검증 오류 */
    errors?: Record<string, string[] | string> | null;
    /** 읽기 전용 */
    disabled?: boolean;
}
/**
 * 메뉴 칸 편집기
 */
export declare const TemplateMenuField: React.FC<TemplateMenuFieldProps>;
export default TemplateMenuField;
