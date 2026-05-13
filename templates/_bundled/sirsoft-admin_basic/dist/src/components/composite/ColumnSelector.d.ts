import { default as React } from 'react';
import { IconName } from '../basic/IconTypes';
/**
 * 전역 window 타입 확장
 * 그누보드7 Core의 AuthManager에 접근하기 위한 타입 선언
 */
declare global {
    interface Window {
        G7Core?: {
            AuthManager?: {
                getInstance: () => {
                    getUser: () => {
                        uuid: string;
                        [key: string]: any;
                    } | null;
                };
            };
        };
    }
}
export interface ColumnItem {
    field: string;
    header: string;
    required?: boolean;
    visible?: boolean;
}
export interface ColumnSelectorProps {
    /** 컴포넌트 고유 ID (레이아웃 JSON의 id 값, localStorage 키 생성에 사용) */
    id?: string;
    columns: ColumnItem[];
    visibleColumns?: string[];
    onColumnVisibilityChange?: (visibleFields: string[]) => void;
    triggerLabel?: string;
    triggerIconName?: IconName;
    position?: 'left' | 'right';
    className?: string;
    style?: React.CSSProperties;
    requiredText?: string;
    /** 드롭다운 메뉴 상단 제목 */
    menuTitle?: string;
    /** 드롭다운 메뉴 상단 설명 */
    menuDescription?: string;
    /** 기본 표시 컬럼 (localStorage에 값이 없을 때 사용) */
    defaultColumns?: string[];
    /** 트리거 버튼에 적용할 추가 className (기존 스타일과 병합됨) */
    triggerClassName?: string;
    /** 라벨 Span에 적용할 추가 className (기존 스타일과 병합됨) */
    labelClassName?: string;
    /**
     * 동적 컬럼 배열 (v1.4.0+)
     *
     * 기존 columns 배열에 동적으로 병합될 컬럼들입니다.
     * dynamicColumnsInsertAfter가 지정된 경우 해당 필드 뒤에 삽입되고,
     * 그렇지 않으면 columns 배열 끝에 추가됩니다.
     */
    dynamicColumns?: ColumnItem[];
    /**
     * 동적 컬럼 삽입 위치를 지정하는 필드명
     * 해당 필드 뒤에 dynamicColumns가 삽입됩니다.
     * 지정하지 않으면 columns 끝에 추가됩니다.
     */
    dynamicColumnsInsertAfter?: string;
}
/**
 * ColumnSelector 집합 컴포넌트
 *
 * 테이블 컬럼의 표시/숨김을 선택할 수 있는 드롭다운 컴포넌트입니다.
 * required 속성이 있는 컬럼은 항상 표시되며 비활성화됩니다.
 *
 * 기본 컴포넌트 조합: Button + Div + Span + Icon + Checkbox
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "ColumnSelector",
 *   "props": {
 *     "columns": [
 *       {"field": "id", "header": "$t:admin.users.columns.id", "required": true},
 *       {"field": "name", "header": "$t:admin.users.columns.name", "required": true},
 *       {"field": "email", "header": "$t:admin.users.columns.email"},
 *       {"field": "status", "header": "$t:admin.users.columns.status"}
 *     ],
 *     "visibleColumns": "{{_local.visibleColumns}}",
 *     "triggerLabel": "$t:common.column_selector",
 *     "requiredText": "$t:common.required"
 *   },
 *   "actions": [
 *     {
 *       "event": "onColumnVisibilityChange",
 *       "type": "change",
 *       "handler": "setState",
 *       "params": {
 *         "target": "local",
 *         "visibleColumns": "{{$args[0]}}"
 *       }
 *     }
 *   ]
 * }
 */
export declare const ColumnSelector: React.FC<ColumnSelectorProps>;
