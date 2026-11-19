import { default as React } from 'react';
import { EditorAttrs } from '../../types';
export interface ProductRailProps {
    /** 줄 제목 (레이아웃이 `$t:` 로 번역해 넘긴다) */
    title?: string;
    /** 제목 앞 아이콘 이름 */
    icon?: string;
    /** 아이콘 클래스 (색) */
    iconClassName?: string;
    /** 이전 단추 이름 (없으면 영문 기본값) */
    prevLabel?: string;
    /** 다음 단추 이름 (없으면 영문 기본값) */
    nextLabel?: string;
    /** 스크린리더가 읽는 영역 종류 (예: 「상품 줄」) */
    roleDescription?: string;
    /** 추가 CSS 클래스 */
    className?: string;
    /** DOM id 속성 (레이아웃 편집기 코어 일괄 ID) */
    id?: string;
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
    editorAttrs?: EditorAttrs;
    /** 카드들 — 레이아웃이 넘긴 자식을 그대로 그린다 */
    children?: React.ReactNode;
}
/**
 * ProductRail 컴포넌트
 *
 * 상품 카드를 가로 한 줄로 늘어놓고 손가락 넘김(브라우저 스크롤 + 스냅)과 이전·다음 단추로
 * 넘기는 컨테이너. 상품 배열을 받지 않고 **레이아웃이 넘긴 자식 카드**를 감싼다 — 검색 로봇용
 * HTML 에서도 안의 상품 링크가 각각 그려진다. 한 화면에 보이는 카드 수는 2(모바일)·3·4(데스크톱)이며,
 * 엔진 폭(편집기 미리보기에서는 기기 폭)으로 고른다(`productRailColumnClass`).
 *
 * @example
 * // 레이아웃 마크업 사용 예시
 * <product-rail data-type="composite" title="$t:shop.popular_products" icon="flame"
 *               prevLabel="$t:common.prev" nextLabel="$t:common.next">
 *   <div data-each="product in {{popularProducts.data ?? []}}">
 *     <product-card data-type="composite" product="{{product}}"/>
 *   </div>
 * </product-rail>
 */
export declare const ProductRail: React.FC<ProductRailProps>;
export default ProductRail;
