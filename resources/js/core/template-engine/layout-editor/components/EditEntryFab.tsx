/**
 * EditEntryFab.tsx
 *
 * 운영 화면 좌측 하단 편집 진입 플로팅 액션 버튼 — 8.2.2.
 *
 * 본 컴포넌트는 다른 layout-editor/ 자산과 달리 **일반 렌더 모드** 에 마운트
 * 된다 (편집기 셸 외부). 진입 권한 보유자에게만 노출. 마운트와 입력 수집은
 * `EditEntryFabHost` 가 맡는다(#869 — 그전까지 어느 화면에도 붙지 않았다).
 *
 * 클릭 시 `/admin/layout-editor/{현재템플릿}?route={매칭된 라우트 정의 path}` 로 **같은 탭** 에서
 * 이동해 보던 화면이 트리에서 선택된 채 편집기 진입 (새 탭 진입 금지,
 * 로그인 풀려 재로그인 후 진입할 때도 같은 탭 유지).
 *
 * 모양: 넓은 폭은 아이콘 + 글자, 좁은 폭(`compact`, 엔진 responsive 판정)은 아이콘만 둔 원형 단추이며
 * 보이는 이름은 `aria-label` 로 준다. 다크 모드는 코어 중립 표식 `data-g7-color-scheme`, 인쇄에서는 숨는다.
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
  /**
   * 진입 함수 — 기본값은 같은 탭 이동 (`window.location.assign`). 테스트 주입 가능.
   *
   */
  openWindow?: (url: string) => void;
  /** 좁은 폭 — 아이콘만 둔 원형 단추 (엔진 responsive 판정을 호스트가 넘긴다) */
  compact?: boolean;
  /** 고정 요소를 피한 위치 — 없으면 왼쪽·아래 16px */
  offset?: FabOffset;
  /** 단추 요소 ref — 호스트가 자리 계산에서 자기 자신을 빼는 데 쓴다 */
  buttonRef?: React.Ref<HTMLButtonElement>;
}

/** 단추 클래스 — 인쇄·다크·포커스 규칙이 이 이름을 쓴다 */
const FAB_CLASS = EDIT_ENTRY_FAB_CLASS;

export function EditEntryFab(props: EditEntryFabProps): React.ReactElement | null {
  const { t } = useTranslation();

  const shouldShow = shouldRenderEditEntryFab({
    pathname: props.pathname,
    isAuthenticated: props.isAuthenticated,
    hasLayoutEditPermission: props.hasLayoutEditPermission,
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
  const compact = props.compact === true;
  const offset = props.offset ?? { left: EDIT_ENTRY_FAB_GAP, bottom: EDIT_ENTRY_FAB_GAP };

  return (
    <>
      <button
        ref={props.buttonRef}
        type="button"
        className={FAB_CLASS}
        data-testid="g7le-edit-entry-fab"
        data-compact={compact ? 'true' : 'false'}
        // 진입 주소 — 실측·시험이 클릭 없이 읽는다(이동은 클릭 때만)
        data-entry-url={url}
        onClick={onClick}
        title={t('layout_editor.chrome.fab.tooltip')}
        // 아이콘만 보일 때도 보이는 이름이 있어야 한다 — 글자가 보이면 그 글자가 이름이다
        aria-label={compact ? label : undefined}
        style={{
          left: offset.left,
          bottom: offset.bottom,
          ...(compact ? { width: 44, height: 44, padding: 0, fontSize: 18 } : { padding: '9px 14px', minHeight: 36 }),
        }}
      >
        <span aria-hidden="true">✎</span>
        {compact ? null : <span>{label}</span>}
      </button>
    </>
  );
}
