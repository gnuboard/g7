/**
 * editorRuntimeStyles.ts — 편집기 전용 동적 CSS 규칙 (#163)
 *
 * 편집기 코어는 별도 CSS 파일을 두지 않는다. 인라인 style 로 표현할 수 없는 규칙(@keyframes,
 * 의사 요소, 자손 선택자)은 번들 안 상수로 두고 `RuntimeStyleSheet` 로 문서에 붙인다 —
 * `<style>` 요소를 만들지 않으므로 엄격한 스타일 정책(`style-src 'self'`)에서도 적용된다.
 *
 * 저장 content·운영 렌더에는 영향이 없다(`g7le-*` 네임스페이스, 편집기 생존 동안만 존재). 예외는 운영 화면에
 * 붙는 편집 진입 단추 규칙(`EDIT_ENTRY_FAB_STYLE_CSS`)으로, 그 단추가 보이는 동안만 존재한다.
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

/** 기기 줄(기기 툴바·페이지 상태 전환기를 담는 셸 줄) 규칙 묶음 키 */
export const DEVICE_ROW_STYLE_KEY = 'g7le-device-row-style';

/** 기기 줄 항목도 툴바처럼 줄 단위로 감고 줄이지 않는다(설계 확정 2026-11-08 「두 줄로 감기」) */
export const DEVICE_ROW_STYLE_CSS = '.g7le-chrome__device-toolbar > * { flex-shrink: 0; }';

/** 기기 툴바 규칙 묶음 키 */
export const DEVICE_TOOLBAR_STYLE_KEY = 'g7le-device-toolbar-style';

/** 기기 툴바 묶음(기기 단추·색 테마·줌·언어)은 줄지 않고 글자 단위로 줄바꿈되지 않는다 */
export const DEVICE_TOOLBAR_STYLE_CSS = '.g7le-device-toolbar > * { flex-shrink: 0; white-space: nowrap; }';

/** 편집 진입 단추(운영 화면 플로팅 단추) 규칙 묶음 키 */
export const EDIT_ENTRY_FAB_STYLE_KEY = 'g7le-edit-entry-fab-style';

/** 편집 진입 단추 클래스 — 인쇄·다크·포커스 규칙이 이 이름을 쓴다 */
export const EDIT_ENTRY_FAB_CLASS = 'g7le-edit-entry-fab';

/**
 * 편집 진입 단추 규칙 — 인라인 style 로 줄 수 없는 규칙(인쇄, 다크, 포커스 표시, 눌림).
 * 이 단추만은 편집기 밖(운영 화면)에 붙는다 — 단추가 보이는 동안만 붙고 사라지면 뗀다.
 * 다크 선택자는 ColorSchemeSignal 규격(다크 안의 라이트 제외)을 따른다.
 */
export const EDIT_ENTRY_FAB_STYLE_CSS =
  `.${EDIT_ENTRY_FAB_CLASS}{position:fixed;z-index:39;display:inline-flex;align-items:center;justify-content:center;gap:6px;` +
  'border:1px solid transparent;border-radius:999px;background:#0f172a;color:#ffffff;' +
  'box-shadow:0 2px 12px rgba(15,23,42,.25);cursor:pointer;font-size:13px;font-weight:600;line-height:1;' +
  'font-family:inherit;transition:transform .12s ease,box-shadow .12s ease}' +
  `.${EDIT_ENTRY_FAB_CLASS}:hover{box-shadow:0 4px 16px rgba(15,23,42,.35)}` +
  `.${EDIT_ENTRY_FAB_CLASS}:active{transform:translateY(1px)}` +
  `.${EDIT_ENTRY_FAB_CLASS}:focus{outline:none}` +
  `.${EDIT_ENTRY_FAB_CLASS}:focus-visible{outline:3px solid #60a5fa;outline-offset:2px}` +
  `.${EDIT_ENTRY_FAB_CLASS}:is([data-g7-color-scheme="dark"] *):not(:where([data-g7-color-scheme="dark"] [data-g7-color-scheme="light"] *)){` +
  'background:#f8fafc;color:#0f172a;border-color:#cbd5e1;box-shadow:0 2px 12px rgba(0,0,0,.5)}' +
  `@media print{.${EDIT_ENTRY_FAB_CLASS}{display:none !important}}`;

/**
 * 키 하나의 정적 규칙 묶음을 붙입니다(같은 키는 교체). 컴포넌트가 마운트 효과에서 부르고 정리 함수에서
 * `removeEditorStyle` 로 뗀다 — 종전 JSX `<style>` 자식과 같은 생존 범위다.
 *
 * @param key 규칙 묶음 키 (`g7le-*`)
 * @param css 규칙 본문
 * @returns 반환값 없음
 */
export function applyEditorStyle(key: string, css: string): void {
  if (typeof document === 'undefined') return;
  setRules(key, css);
}

/**
 * 키 하나의 규칙 묶음을 뗍니다.
 *
 * @param key 규칙 묶음 키
 * @returns 반환값 없음
 */
export function removeEditorStyle(key: string): void {
  if (typeof document === 'undefined') return;
  remove(key);
}

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
