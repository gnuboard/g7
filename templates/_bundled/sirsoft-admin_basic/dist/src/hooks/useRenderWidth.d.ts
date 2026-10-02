/**
 * 렌더 폭 — 컴포넌트가 폭으로 형태·기본값을 고를 때 쓰는 값.
 *
 * `G7Core.useResponsive()` 의 Provider 폭이 우선이다. 레이아웃 편집기 미리보기는 iframe 이 아니라
 * 같은 문서의 div 라서 `window.innerWidth` 는 미리보기 기기 폭과 무관하다 — 창 폭으로 고르면 모바일
 * 미리보기에서 데스크톱 형태가 그려진다(#141). 훅이 없는 환경(코어 미초기화·단위 테스트)에서만
 * 창 폭으로 폴백하고 `resize` 로 갱신한다.
 *
 * @returns 렌더 폭(px)
 */
export declare function useRenderWidth(): number;
