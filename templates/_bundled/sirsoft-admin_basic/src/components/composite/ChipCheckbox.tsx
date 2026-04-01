import React from 'react';
import { Div } from '../basic/Div';
import { Label } from '../basic/Label';
import { Input } from '../basic/Input';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';

/**
 * hex 색상 문자열을 RGB 객체로 변환
 *
 * @param hex hex 색상 문자열 (예: '#FF5733' 또는 'FF5733')
 * @returns RGB 객체 또는 변환 실패 시 null
 */
function hexToRgb(hex: string): { r: number; g: number; b: number } | null {
  const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
  return result ? {
    r: parseInt(result[1], 16),
    g: parseInt(result[2], 16),
    b: parseInt(result[3], 16),
  } : null;
}

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
export const ChipCheckbox: React.FC<ChipCheckboxProps> = ({
  value,
  checked = false,
  icon,
  label,
  variant = 'blue',
  customColor,
  className = '',
  style,
  onChange,
}) => {
  const [isHovered, setIsHovered] = React.useState(false);

  const baseStyles = 'inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm font-medium border cursor-pointer transition-all';

  // customColor가 있으면 variant 무시, 직접 스타일 계산
  if (customColor) {
    const rgb = hexToRgb(customColor);
    const isDark = document.documentElement.classList.contains('dark');

    let computedStyle: React.CSSProperties;
    if (checked) {
      computedStyle = {
        backgroundColor: customColor,
        color: '#ffffff',
        borderColor: customColor,
        opacity: isHovered ? 0.85 : 1,
      };
    } else {
      computedStyle = rgb ? {
        backgroundColor: `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${isHovered ? (isDark ? 0.25 : 0.12) : (isDark ? 0.15 : 0.06)})`,
        color: customColor,
        borderColor: `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${isDark ? 0.3 : 0.2})`,
      } : {};
    }

    return (
      <Label
        className={`${baseStyles} ${className}`}
        style={{ ...computedStyle, ...style }}
        onMouseEnter={() => setIsHovered(true)}
        onMouseLeave={() => setIsHovered(false)}
      >
        <Input
          type="checkbox"
          value={value}
          checked={checked}
          onChange={(e) => { onChange?.(e); }}
          className="hidden"
        />
        {icon && (
          <Icon
            name={icon}
            className="w-4 h-4"
          />
        )}
        <Div>{label}</Div>
      </Label>
    );
  }

  // variant별 unchecked 스타일 (연한 톤)
  const variantUncheckedStyles: Record<string, string> = {
    green: 'bg-green-50 dark:bg-green-900/20 text-green-600 dark:text-green-400 border-green-200 dark:border-green-800 hover:bg-green-100 dark:hover:bg-green-900/30',
    orange: 'bg-orange-50 dark:bg-orange-900/20 text-orange-600 dark:text-orange-400 border-orange-200 dark:border-orange-800 hover:bg-orange-100 dark:hover:bg-orange-900/30',
    yellow: 'bg-yellow-50 dark:bg-yellow-900/20 text-yellow-600 dark:text-yellow-400 border-yellow-200 dark:border-yellow-800 hover:bg-yellow-100 dark:hover:bg-yellow-900/30',
    gray: 'bg-gray-50 dark:bg-gray-700 text-gray-600 dark:text-gray-300 border-gray-200 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-600',
    blue: 'bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 border-blue-200 dark:border-blue-800 hover:bg-blue-100 dark:hover:bg-blue-900/30',
  };

  // variant별 checked 스타일 (진한 톤, 흰색 텍스트)
  const variantCheckedStyles: Record<string, string> = {
    green: 'bg-green-600 dark:bg-green-600 text-white dark:text-white border-green-700 dark:border-green-500 hover:bg-green-700 dark:hover:bg-green-500',
    orange: 'bg-orange-600 dark:bg-orange-600 text-white dark:text-white border-orange-700 dark:border-orange-500 hover:bg-orange-700 dark:hover:bg-orange-500',
    yellow: 'bg-yellow-600 dark:bg-yellow-600 text-white dark:text-white border-yellow-700 dark:border-yellow-500 hover:bg-yellow-700 dark:hover:bg-yellow-500',
    gray: 'bg-gray-600 dark:bg-gray-500 text-white dark:text-white border-gray-700 dark:border-gray-400 hover:bg-gray-700 dark:hover:bg-gray-400',
    blue: 'bg-blue-600 dark:bg-blue-600 text-white dark:text-white border-blue-700 dark:border-blue-500 hover:bg-blue-700 dark:hover:bg-blue-500',
  };

  const currentStyles = checked ? variantCheckedStyles[variant] : variantUncheckedStyles[variant];

  return (
    <Label
      className={`${baseStyles} ${currentStyles} ${className}`}
      style={style}
    >
      <Input
        type="checkbox"
        value={value}
        checked={checked}
        onChange={(e) => { onChange?.(e); }}
        className="hidden"
      />
      {icon && (
        <Icon
          name={icon}
          className="w-4 h-4"
        />
      )}
      <Div>{label}</Div>
    </Label>
  );
};
