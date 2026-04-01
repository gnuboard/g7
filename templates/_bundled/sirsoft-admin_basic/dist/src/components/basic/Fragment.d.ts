import { default as React } from 'react';
export interface FragmentProps {
    children?: React.ReactNode;
    className?: string;
    style?: React.CSSProperties;
}
/**
 * Fragment 기본 컴포넌트
 *
 * React.Fragment와 유사하게 DOM 요소를 추가하지 않고 children만 렌더링합니다.
 * iterator와 함께 사용하여 불필요한 wrapper div 없이 반복 렌더링할 때 유용합니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "type": "basic",
 *   "name": "Fragment",
 *   "iterator": {
 *     "source": "{{items}}",
 *     "item": "item"
 *   },
 *   "children": [
 *     {
 *       "type": "basic",
 *       "name": "Button",
 *       "text": "{{item.label}}"
 *     }
 *   ]
 * }
 */
export declare const Fragment: React.FC<FragmentProps>;
