import { default as React } from 'react';
import { IconName } from '../basic/IconTypes';
export interface ChipCheckboxProps {
    value: string;
    checked?: boolean;
    icon?: IconName | string;
    label: string;
    variant?: 'green' | 'orange' | 'yellow' | 'gray' | 'blue';
    customColor?: string;
    className?: string;
    style?: React.CSSProperties;
    onChange?: (event: React.ChangeEvent<HTMLInputElement>) => void;
}
/**
 * ChipCheckbox 집합 컴포넌트
 *
 * 체크박스 + 아이콘 + 라벨을 조합한 칩 형태의 체크박스 컴포넌트입니다.
 * 필터 UI에서 주로 사용되며, 선택 여부에 따라 스타일이 변경됩니다.
 *
 * 기본 컴포넌트 조합: Div + Label + Input(checkbox) + Icon
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "ChipCheckbox",
 *   "props": {
 *     "value": "pending_order",
 *     "checked": "{{(_local.filter.orderStatus || []).includes('pending_order')}}",
 *     "icon": "clock",
 *     "label": "$t:sirsoft-ecommerce.admin.order.status.waiting",
 *     "variant": "gray"
 *   },
 *   "actions": [
 *     {
 *       "type": "change",
 *       "handler": "sirsoft-ecommerce.toggleArrayValue",
 *       "params": {
 *         "target": "_local",
 *         "path": "filter.orderStatus",
 *         "value": "pending_order"
 *       }
 *     }
 *   ]
 * }
 *
 * @example
 * // customColor 사용 예시 (임의의 hex 색상)
 * {
 *   "name": "ChipCheckbox",
 *   "props": {
 *     "value": "{{String(label.id)}}",
 *     "checked": "{{(assignments ?? []).some(a => a.label_id === label.id)}}",
 *     "label": "{{$localized(label.name)}}",
 *     "customColor": "{{label.color ?? '#3B82F6'}}"
 *   }
 * }
 *
 * @remarks
 * - variant: green (연두색, 긍정 상태), orange (주황색, 진행 중), yellow (노란색, 회수), gray (회색, 대기/보류), blue (기본값)
 * - customColor: 임의의 hex 색상 코드 지정 시 variant 무시, 다크모드/호버 자동 처리
 */
export declare const ChipCheckbox: React.FC<ChipCheckboxProps>;
