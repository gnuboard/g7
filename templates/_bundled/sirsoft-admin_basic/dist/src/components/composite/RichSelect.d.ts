import { default as React } from 'react';
export interface RichSelectOption {
    /** 옵션 고유 값 */
    value: string | number;
    /** 기본 라벨 (selectedTemplate이 없을 때 트리거에 표시) */
    label: string;
    /** 비활성화 여부 */
    disabled?: boolean;
    /** 옵션에 포함된 추가 데이터 (itemTemplate에서 {{item.xxx}}로 접근) */
    [key: string]: unknown;
}
export interface RichSelectProps {
    /** 옵션 목록 */
    options?: RichSelectOption[];
    /** 선택된 값 */
    value?: string | number;
    /** 값 변경 핸들러 */
    onChange?: (e: {
        target: {
            value: string | number;
        };
    }) => void;
    /** 트리거 버튼 placeholder */
    placeholder?: string;
    /** 비활성화 */
    disabled?: boolean;
    /** 추가 클래스 */
    className?: string;
    /** 드롭다운 최대 높이 */
    maxHeight?: string;
    /**
     * 각 옵션 항목 렌더링용 children
     * 템플릿 엔진에서 itemContext로 item, index, isSelected를 주입받음
     */
    children?: React.ReactNode;
    /**
     * 선택된 항목 표시용 children (트리거 버튼에 표시)
     * 없으면 option.label 사용
     */
    selectedChildren?: React.ReactNode;
    /**
     * DynamicRenderer에서 전달되는 component_layout 정의
     * @internal 템플릿 엔진 내부 사용
     */
    __componentLayoutDefs?: {
        /** 각 옵션 항목 렌더링용 레이아웃 정의 */
        item?: any[];
        /** 선택된 항목 표시용 레이아웃 정의 */
        selected?: any[];
    };
}
/**
 * 리치 셀렉트 컴포넌트
 *
 * component_layout을 통해 각 항목을 커스텀 렌더링할 수 있습니다.
 * 파일 선택, 사용자 선택 등 복잡한 정보를 표시해야 하는 드롭다운에 적합합니다.
 *
 * @example JSON 레이아웃에서 사용
 * ```json
 * {
 *   "type": "composite",
 *   "name": "RichSelect",
 *   "props": {
 *     "value": "{{_global.selectedFile}}",
 *     "options": "{{files?.data ?? []}}",
 *     "placeholder": "파일을 선택하세요"
 *   },
 *   "component_layout": {
 *     "item": [
 *       {
 *         "type": "basic",
 *         "name": "Div",
 *         "props": { "className": "flex flex-col" },
 *         "children": [
 *           { "type": "basic", "name": "Span", "text": "{{item.label}}" },
 *           { "type": "basic", "name": "Span", "text": "{{item.size}} • {{item.updated_at}}" }
 *         ]
 *       }
 *     ],
 *     "selected": [
 *       { "type": "basic", "name": "Span", "text": "{{item.label}}" }
 *     ]
 *   },
 *   "actions": [{ "type": "change", "handler": "setState", "params": { "target": "global", "selectedFile": "{{$event.target.value}}" } }]
 * }
 * ```
 */
export declare const RichSelect: React.FC<RichSelectProps>;
