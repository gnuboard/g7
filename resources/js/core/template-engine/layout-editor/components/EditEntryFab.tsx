/**
 * EditEntryFab.tsx
 *
 * 운영 화면 우측 하단 편집 진입 플로팅 액션 버튼 — 8.2.2.
 *
 * 본 컴포넌트는 다른 layout-editor/ 자산과 달리 **일반 렌더 모드** 에 마운트
 * 된다 (편집기 셸 외부). 진입 권한 보유자에게만 노출. 마운트와 입력 수집은
 * `EditEntryFabHost` 가 맡는다(#869 — 그전까지 어느 화면에도 붙지 않았다).
 *
 * 클릭 시 `/admin/layout-editor/{현재템플릿}?route={매칭된 라우트 정의 path}` 로 **같은 탭** 에서
 * 이동해 보던 화면이 트리에서 선택된 채 편집기 진입 (새 탭 진입 금지,
 * 로그인 풀려 재로그인 후 진입할 때도 같은 탭 유지).
 *
 * 모양: 글자 없이 레이아웃 편집 아이콘(화면 구획 + 연필)만 둔 원형 단추다(7.2.0 — 그전에는 넓은 폭에서 「✎ 레이아웃 편집」
 * 글자 단추, 좁은 폭에서 원형이었다). 보이는 이름은 `aria-label`, 설명은 `title` 로 준다.
 * 다크 모드는 코어 중립 표식 `data-g7-color-scheme`, 인쇄에서는 숨는다.
 * 층(z-index)은 39 다 — 템플릿 오버레이(모바일 덮개 z-40, 모달·드로어·토스트 z-50)보다 아래라 단추가 오버레이를 가리지 않는다.
 *
 * `translate` prop 받지 않음 — 코어 TranslationEngine 자동 해석.
 *
 * @since engine-v1.50.0
 */

import React, { useLayoutEffect } from 'react';
import { useTranslation } from '../../TranslationContext';
import {
  applyEditorStyle,
  removeEditorStyle,
  EDIT_ENTRY_FAB_CLASS,
  EDIT_ENTRY_FAB_STYLE_CSS,
  EDIT_ENTRY_FAB_STYLE_KEY,
} from '../utils/editorRuntimeStyles';
import {
  buildEditEntryUrl,
  shouldRenderEditEntryFab,
  EDIT_ENTRY_FAB_GAP,
  type FabOffset,
} from '../hooks/useEditEntryFab';

export interface EditEntryFabProps {
  /** 현재 운영 사이트가 부팅된 템플릿 식별자 */
  templateIdentifier: string;
  /** 현재 URL pathname */
  pathname: string;
  /** 인증 사용자 여부 */
  isAuthenticated: boolean;
  /** core.templates.layouts.edit 권한 보유 여부 */
  hasLayoutEditPermission: boolean;
  /** 현재 보고 있는 라우트의 정의 path (편집기 진입 시 ?route= 로 전달) */
  currentRoutePath: string;
  /** 환경설정 「레이아웃 편집 단추」 — false 면 그리지 않는다(생략하면 켜짐, 7.2.0) */
  enabled?: boolean;
  /**
   * 진입 함수 — 기본값은 같은 탭 이동 (`window.location.assign`). 테스트 주입 가능.
   *
   */
  openWindow?: (url: string) => void;
  /** 고정 요소를 피한 위치 — 없으면 오른쪽·아래 16px */
  offset?: FabOffset;
  /** 단추 요소 ref — 호스트가 자리 계산에서 자기 자신을 빼는 데 쓴다 */
  buttonRef?: React.Ref<HTMLButtonElement>;
}

/** 단추 클래스 — 인쇄·다크·포커스 규칙이 이 이름을 쓴다 */
const FAB_CLASS = EDIT_ENTRY_FAB_CLASS;

/**
 * 레이아웃 편집 아이콘 — 화면 구획(머리글·옆 패널·본문)과 오른쪽 아래 연필.
 *
 * 코어 엔진은 템플릿의 아이콘 글꼴에 기대지 않으므로 SVG 를 직접 둔다. 색은 `currentColor` 라 다크 규칙을 그대로 따른다.
 */
function LayoutEditIcon(): React.ReactElement {
  return (
    <svg
      data-testid="g7le-edit-entry-fab-icon"
      width={20}
      height={20}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.8}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      <path d="M11 20H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6" />
      <path d="M3 8.5h18" />
      <path d="M8.5 8.5V20" />
      <path d="M19.4 13.6l2 2-5.6 5.6H13.8v-2z" />
    </svg>
  );
}

export function EditEntryFab(props: EditEntryFabProps): React.ReactElement | null {
  const { t } = useTranslation();

  const shouldShow = shouldRenderEditEntryFab({
    pathname: props.pathname,
    isAuthenticated: props.isAuthenticated,
    hasLayoutEditPermission: props.hasLayoutEditPermission,
    enabled: props.enabled,
  });

  // 인라인 style 로 줄 수 없는 규칙(인쇄, 다크, 포커스 표시, 눌림)은 단추가 보이는 동안만 런타임 시트로 붙인다.
  // 종전 JSX <style> 자식은 엄격한 스타일 정책(style-src 'self')에서 막혀 단추가 고정 위치를 잃고 문서 흐름에 그려졌다(#163).
  // 그리기 전에 붙여야 첫 화면에 무스타일 단추가 비치지 않는다(useLayoutEffect).
  useLayoutEffect(() => {
    if (!shouldShow) return undefined;
    applyEditorStyle(EDIT_ENTRY_FAB_STYLE_KEY, EDIT_ENTRY_FAB_STYLE_CSS);
    return () => removeEditorStyle(EDIT_ENTRY_FAB_STYLE_KEY);
  }, [shouldShow]);

  if (!shouldShow) return null;

  const url = buildEditEntryUrl(props.templateIdentifier, props.currentRoutePath);
  const onClick = (): void => {
    if (props.openWindow) {
      props.openWindow(url);
    } else if (typeof window !== 'undefined') {
      // 같은 탭 이동 — 새 탭/창 금지
      // base-path:allow buildEditEntryUrl() 이 withBasePrefix() 로 조립한 값이다.
      window.location.assign(url);
    }
  };

  const label = t('layout_editor.chrome.fab.label');
  const offset = props.offset ?? { right: EDIT_ENTRY_FAB_GAP, bottom: EDIT_ENTRY_FAB_GAP };

  return (
    <button
      ref={props.buttonRef}
      type="button"
      className={FAB_CLASS}
      data-testid="g7le-edit-entry-fab"
      // 진입 주소 — 실측·시험이 클릭 없이 읽는다(이동은 클릭 때만)
      data-entry-url={url}
      onClick={onClick}
      title={t('layout_editor.chrome.fab.tooltip')}
      // 글자가 없으므로 보이는 이름은 aria-label 로 준다
      aria-label={label}
      style={{ right: offset.right, bottom: offset.bottom }}
    >
      <LayoutEditIcon />
    </button>
  );
}
