import { default as React } from 'react';
interface QuantitySelectorProps {
    /** 현재 수량 */
    value: number;
    /** 최소 수량 */
    min?: number;
    /** 최대 수량 */
    max?: number;
    /** 수량 변경 콜백 */
    onChange: (value: number) => void;
    /** 컴포넌트 크기 */
    size?: 'sm' | 'md' | 'lg';
    /** 비활성화 여부 */
    disabled?: boolean;
    /** 추가 CSS 클래스 */
    className?: string;
}
/**
 * 수량 선택 컴포넌트
 *
 * @example
 * ```tsx
 * const [quantity, setQuantity] = useState(1);
 *
 * <QuantitySelector
 *   value={quantity}
 *   min={1}
 *   max={10}
 *   onChange={setQuantity}
 * />
 * ```
 *
 * @example
 * ```json
 * // 레이아웃 JSON에서 사용
 * {
 *   "type": "composite",
 *   "name": "QuantitySelector",
 *   "props": {
 *     "value": "{{item.quantity}}",
 *     "min": 1,
 *     "max": "{{item.stock}}",
 *     "size": "sm"
 *   },
 *   "actions": [{
 *     "event": "onChange",
 *     "type": "change",
 *     "handler": "updateQuantity",
 *     "params": { "itemId": "{{item.id}}", "quantity": "{{$args[0]}}" }
 *   }]
 * }
 * ```
 */
declare const QuantitySelector: React.FC<QuantitySelectorProps>;
export default QuantitySelector;
