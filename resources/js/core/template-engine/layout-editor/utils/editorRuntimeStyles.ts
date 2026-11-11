/**
 * editorRuntimeStyles.ts — 편집기 전용 동적 CSS 규칙 (#163)
 *
 * 편집기 코어는 별도 CSS 파일을 두지 않는다. 인라인 style 로 표현할 수 없는 규칙(@keyframes,
 * 의사 요소, 자손 선택자)은 번들 안 상수로 두고 `RuntimeStyleSheet` 로 문서에 붙인다 —
 * `<style>` 요소를 만들지 않으므로 엄격한 스타일 정책(`style-src 'self'`)에서도 적용된다.
 *
 * 저장 content·운영 렌더에는 영향이 없다(`g7le-*` 네임스페이스, 편집기 생존 동안만 존재).
 *
 * @since engine-v1.91.0
 */

import { has, remove, setRules } from '../../../style/RuntimeStyleSheet';

/** 툴바 규칙 묶음 키 */
export const TOOLBAR_STYLE_KEY = 'g7le-toolbar-style';

/**
 * 툴바 규칙 — 저장 스피너 회전 keyframe + 툴바 압착 차단.
 *
 * 툴바는 flex row 라 기본값(flex-shrink:1)이면 창이 좁을 때 모든 항목의 폭이 깎이고 라벨이 글자
 * 단위로 줄바꿈된다("요 소 추 가"). 직접 자식 **전체**에 적용해야 한다 — 버튼마다 인라인 style 로
 * 붙이면 자체 style 을 가진 하위 컴포넌트(TemplateSwitcher/LocaleSwitcher)와 이후 추가될 항목이
 * 빠져 같은 결함이 재발한다.
 */
export const TOOLBAR_STYLE_CSS =
  '@keyframes g7le-spin { to { transform: rotate(360deg); } }' +
  '.g7le-toolbar > * { flex-shrink: 0; white-space: nowrap; }';

/** 공통 레이아웃 슬롯 가시화 규칙 묶음 키 */
export const BASE_SLOT_STYLE_KEY = 'g7le-base-slot-style';

/** 슬롯 라벨 문구를 담는 CSS 변수 — 값은 따옴표로 감싼 CSS 문자열 */
export const BASE_SLOT_LABEL_VAR = '--g7le-slot-label';

/**
 * 슬롯 점선 박스 + 라벨 규칙. 번역 문구는 규칙 본문에 넣지 않고 CSS 변수로 받는다
 * (규칙을 정적 상수로 유지 — 문구가 바뀌어도 시트를 다시 만들지 않는다).
 */
export const BASE_SLOT_STYLE_CSS =
  '.g7le-preview-frame [data-editor-slot]{position:relative;min-height:96px;outline:2px dashed #94a3b8;outline-offset:-2px;}' +
  `.g7le-preview-frame [data-editor-slot]::after{content:var(${BASE_SLOT_LABEL_VAR}, "") " · " attr(data-editor-slot);` +
  'position:absolute;top:8px;left:50%;transform:translateX(-50%);font-size:12px;line-height:1;color:#64748b;' +
  'background:rgba(241,245,249,0.92);border:1px solid #cbd5e1;border-radius:9999px;padding:4px 12px;pointer-events:none;white-space:nowrap;}';

/** 빈 표 셀 최소 크기 규칙 묶음 키 */
export const EMPTY_CELL_STYLE_KEY = 'g7le-table-inplace-empty-cell-style';

/** 빈 표 셀 최소 크기 규칙 — `.g7le-preview-frame` 안의 셀에만 적용된다 */
export const EMPTY_CELL_STYLE_CSS =
  '.g7le-preview-frame td[data-editor-name],.g7le-preview-frame th[data-editor-name]{min-width:56px;height:38px;}' +
  '.g7le-preview-frame table[data-editor-name]{min-width:120px;}';

/**
 * 문구를 CSS 문자열 토큰으로 바꿉니다(`content` 에 쓸 수 있는 따옴표 문자열).
 *
 * @param text 원문
 * @returns 큰따옴표로 감싼 CSS 문자열
 */
export function toCssString(text: string): string {
  const escaped = text
    .replace(/\\/g, '\\\\')
    .replace(/"/g, '\\"')
    // 줄바꿈·제어 문자는 CSS 문자열을 끊으므로 16진 이스케이프로 바꾼다
    // eslint-disable-next-line no-control-regex
    .replace(/[\u0000-\u001f\u007f]/g, (ch) => `\\${ch.charCodeAt(0).toString(16)} `);

  return `"${escaped}"`;
}

/**
 * 툴바 규칙을 붙입니다.
 *
 * @returns 반환값 없음
 */
export function applyToolbarStyle(): void {
  if (typeof document === 'undefined') return;
  setRules(TOOLBAR_STYLE_KEY, TOOLBAR_STYLE_CSS);
}

/**
 * 툴바 규칙을 뗍니다.
 *
 * @returns 반환값 없음
 */
export function removeToolbarStyle(): void {
  if (typeof document === 'undefined') return;
  remove(TOOLBAR_STYLE_KEY);
}

/**
 * 슬롯 가시화 규칙을 붙이고 라벨 문구를 CSS 변수로 넣습니다. 이미 붙어 있으면 문구만 갱신합니다.
 *
 * @param label 번역된 슬롯 라벨 문구
 * @returns 반환값 없음
 */
export function applyBaseSlotStyle(label: string): void {
  if (typeof document === 'undefined') return;
  document.documentElement.style.setProperty(BASE_SLOT_LABEL_VAR, toCssString(label));
  if (!has(BASE_SLOT_STYLE_KEY)) setRules(BASE_SLOT_STYLE_KEY, BASE_SLOT_STYLE_CSS);
}

/**
 * 슬롯 가시화 규칙과 라벨 변수를 지웁니다.
 *
 * @returns 반환값 없음
 */
export function removeBaseSlotStyle(): void {
  if (typeof document === 'undefined') return;
  remove(BASE_SLOT_STYLE_KEY);
  document.documentElement.style.removeProperty(BASE_SLOT_LABEL_VAR);
}

/**
 * 빈 표 셀 최소 크기 규칙을 한 번만 붙입니다.
 *
 * @returns 반환값 없음
 */
export function ensureEmptyCellStyle(): void {
  if (typeof document === 'undefined') return;
  if (!has(EMPTY_CELL_STYLE_KEY)) setRules(EMPTY_CELL_STYLE_KEY, EMPTY_CELL_STYLE_CSS);
}
