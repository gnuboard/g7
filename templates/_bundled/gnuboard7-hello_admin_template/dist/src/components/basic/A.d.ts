import { default as React } from 'react';
export interface AProps extends React.AnchorHTMLAttributes<HTMLAnchorElement> {
    /** 참이면 링크를 끈다 — href 를 빼고 aria-disabled="true"·tabIndex -1 을 달며 클릭을 막는다 */
    disabled?: boolean;
    /** 참이면 현재 주소의 쿼리를 href 에 합친다 — 목록 문맥(페이지·검색어)을 새 탭에서도 유지 */
    mergeQuery?: boolean;
}
/**
 * 기본 a(링크) 컴포넌트
 *
 * - 레이아웃이 클릭 액션(`<on type="click">`)을 달았으면 클릭은 엔진이 맡는다. 엔진이 Ctrl·Cmd·Shift·가운데
 *   클릭을 브라우저에 넘겨 새 탭·새 창으로 열린다.
 * - 액션 없는 내부 링크를 보통 클릭하면 SPA 로 옮긴다(전체 다시 부르기 방지). 보통 클릭인지는
 *   `G7Core.navigation.isPlainLeftClick` 하나로만 판정한다 — 수정키·target·download·외부 주소는 브라우저 기본 동작.
 * - 그리는 href 에는 사이트 마운트 접두사를 붙인다(`sitePath()`, 멱등). 새 탭은 이 값을 그대로 여는데, 접두사가 없으면
 *   하위 폴더 배치(`https://host/g7/`)에서 링크가 사이트 밖으로 나가고 오류가 아니라 「엉뚱한 페이지가 떴다」로만 나타난다.
 *   SPA 이동에는 접두사 없는 경로를 넘긴다 — 라우터가 주소창에 쓸 때 붙인다.
 */
export declare const A: React.FC<AProps>;
