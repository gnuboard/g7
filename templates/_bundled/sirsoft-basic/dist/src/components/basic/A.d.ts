import { default as React } from 'react';
export interface AProps extends React.AnchorHTMLAttributes<HTMLAnchorElement> {
    /**
     * 비활성 링크 — href 를 빼고 `aria-disabled="true"`·`tabindex="-1"`, 클릭은 아무 일도 하지 않는다.
     * 단추의 `disabled` 와 같은 뜻이다(`<a>` 에는 `:disabled` 가상 클래스가 없으므로 꾸밈은 `aria-disabled:` 변형으로).
     */
    disabled?: boolean;
    /** 현재 주소의 쿼리를 href 에 합친다(목록 문맥 유지) — 보통 클릭과 새 탭이 같은 주소로 간다 */
    mergeQuery?: boolean;
    /**
     * 대체 주소 — href 가 비지 않았는데 이 사이트 주소가 아니면(실행 주소·다른 사이트·`//host`) 대신 이 주소를 href 로 단다.
     * 알림 이동 주소처럼 바깥에서 정해지는 href 가 가운데 클릭·「새 탭에서 열기」로 그대로 열리지 않게 한다 — 보통 클릭의
     * `navigate fallbackPath` 와 같은 착지점을 적는다(7.1.1). 비운 href 는 그대로 둔다(링크 아님).
     */
    fallbackHref?: string;
}
/**
 * 기본 a(링크) 컴포넌트
 *
 * 화면 이동을 링크로 그려 가운데 클릭·Ctrl+클릭·우클릭 「새 탭에서 열기」가 되게 한다(#757).
 * - 레이아웃 액션(`<on type="click">`)이 있으면 그 액션이 클릭을 맡는다 — 수정키 클릭을 막지 않는 판정은 엔진이 한다.
 * - 액션이 없는 내부 링크는 보통 클릭만 SPA 로 옮기고(전체 다시 부르기 방지), 나머지는 브라우저 기본 동작이다.
 * - `target="_blank"` 면 rel 에 `noopener noreferrer` 를 합친다(넘겨받은 rel 토큰은 남긴다).
 */
export declare const A: React.FC<AProps>;
