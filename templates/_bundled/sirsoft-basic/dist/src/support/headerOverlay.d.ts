/**
 * 메뉴 겹침 — 화면 맨 위 배너(히어로섹션 등)가 사이트 상단 메뉴를 자기 위에 겹쳐 달라고 알리면 그에 맞춘다.
 *
 * 계약(템플릿 ↔ 배너 확장):
 * - 배너가 문서 뿌리에 `data-header-overlay="dark|light"` 를 단다(값: 지금 보이는 배너 바탕 밝기). 지우면 겹침이 끝난다.
 * - 이 템플릿은 그동안 보이는 헤더 높이를 `--g7-header-height`(px)로 문서 뿌리에 알리고, 본문 앞 전폭 자리(`.g7-page-top`)를
 *   그만큼 위로 당겨 배너가 메뉴 밑으로 들어가게 한다(main.css). 배너는 이 값만큼 문구를 아래로 내린다.
 * - 화면 맨 위(스크롤 10px 이하)에서만 `data-header-overlay-active` 를 같은 값으로 달아 메뉴를 투명하게, 글자를 바탕에 맞게 바꾼다.
 *   내리면 속성을 지워 원래 배경으로 돌아온다.
 *
 * 데스크톱 헤더(Header)가 이 훅을 부른다 — 휴대폰에서는 데스크톱 헤더가 감춰져 있어도 그려져 있으므로 모바일 툴바도 함께 잰다.
 */
/** 배너가 다는 신호 속성 */
export declare const OVERLAY_ATTRIBUTE = "data-header-overlay";
/** 이 템플릿이 화면 맨 위에서만 다는 속성 — CSS 가 메뉴를 투명하게 한다 */
export declare const OVERLAY_ACTIVE_ATTRIBUTE = "data-header-overlay-active";
/** 보이는 헤더 높이 CSS 변수 */
export declare const HEADER_HEIGHT_VARIABLE = "--g7-header-height";
/**
 * 배너의 메뉴 겹침 신호를 따라 헤더 높이·투명 상태를 문서 뿌리에 반영합니다. 신호가 없으면 아무것도 바꾸지 않는다(종전 화면).
 */
export declare function useHeaderOverlayController(): void;
