import { default as React } from 'react';
export interface ExpandableContentProps {
    /**
     * 접힌 상태의 최대 높이 (px)
     * @default 500
     */
    maxHeight?: number;
    /**
     * 펼치기 버튼 텍스트
     */
    expandText?: string;
    /**
     * 접기 버튼 텍스트
     */
    collapseText?: string;
    /**
     * 사용자 정의 클래스
     */
    className?: string;
    /**
     * children 렌더링
     */
    children?: React.ReactNode;
}
/**
 * ExpandableContent 컴포넌트
 *
 * 콘텐츠가 maxHeight를 초과하면 그라데이션 오버레이와 펼치기/접기 버튼을 표시합니다.
 * 콘텐츠가 짧으면 버튼/그라데이션 없이 전체 콘텐츠를 그대로 표시합니다.
 *
 * @example
 * // 레이아웃 JSON에서 사용
 * {
 *   "type": "composite",
 *   "name": "ExpandableContent",
 *   "props": { "maxHeight": 500 },
 *   "children": [
 *     {
 *       "type": "composite",
 *       "name": "HtmlContent",
 *       "props": { "content": "{{product.data?.description_localized ?? ''}}" }
 *     }
 *   ]
 * }
 */
export declare const ExpandableContent: React.FC<ExpandableContentProps>;
export default ExpandableContent;
