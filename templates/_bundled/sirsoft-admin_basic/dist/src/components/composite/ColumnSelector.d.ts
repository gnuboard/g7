import { default as React } from 'react';
import { IconName } from '../basic/IconTypes';
import { EditorAttrs } from '../../types';
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
    /**
     * 표시 컬럼이 정해질 때 호출됩니다.
     *
     * 두 번째 인자 `meta.source` 가 **이 호출이 무엇 때문에 일어났는지**를 알려줍니다.
     * 이 콜백은 사람이 체크박스를 누른 순간에만 오는 것이 아니라, 마운트 시 저장값·기본값으로
     * 부모 상태를 맞출 때(`init`)와 동적 컬럼이 새로 생겨 자동 포함될 때(`dynamic`)도 옵니다.
     * 그 셋을 구분하지 못하면 "복원"을 "사용자 조작"으로 오해해, 예컨대 주소를 다시 쓰는
     * 소비처에서 화면에 들어올 때마다 주소가 바뀌는 문제가 생깁니다.
     *
     * 두 번째 인자는 **덧붙인 것**이라 기존 소비처(첫 인자만 읽는 곳)는 영향을 받지 않습니다.
     *
     * 소비 현황: **현재 `meta.source` 를 읽는 레이아웃은 하나도 없습니다.** 유일하게 구분이
     * 필요했던 주문 목록은 이 콜백에서 주소를 다시 쓰지 않게 바뀌어(서버가 클레임 요약을 항상
     * 싣도록 되돌린 결과) 세 출처의 처리가 같아졌기 때문입니다. 그래도 이 인자를 없애지 않는
     * 이유는, 복원(`init`)·자동 포함(`dynamic`)과 사람의 토글(`user`)을 다르게 다뤄야 하는
     * 소비처 — 주소·서버 요청·저장을 콜백에서 수행하는 화면 — 가 다시 생길 때 그 구분을
     * 컴포넌트 밖에서는 복원할 수 없기 때문입니다. 레이아웃에서 읽으려면 `$args[1]` 입니다
     * (예: `{{$args[1].source === 'user'}}`).
     *
     * @param visibleFields 표시할 컬럼 field 목록
     * @param meta 발화 출처 — `user`(사람이 토글) / `init`(마운트 동기화) / `dynamic`(동적 컬럼 자동 포함)
     */
    onColumnVisibilityChange?: (visibleFields: string[], meta?: {
        source: 'user' | 'init' | 'dynamic';
    }) => void;
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
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
    editorAttrs?: EditorAttrs;
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
