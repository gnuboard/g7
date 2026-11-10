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

import React from 'react';
import { useTranslation } from '../../TranslationContext';
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
const FAB_CLASS = 'g7le-edit-entry-fab';

/**
 * 인라인 style 로 줄 수 없는 규칙(인쇄, 다크, 포커스 표시, 눌림). 코어 번들에는 CSS 파일이 없어 컴포넌트가 함께 싣는다.
 * 다크 선택자는 ColorSchemeSignal 규격(다크 안의 라이트 제외)을 따른다.
 */
const FAB_STYLE = `
.${FAB_CLASS}{position:fixed;z-index:39;display:inline-flex;align-items:center;justify-content:center;gap:6px;
  border:1px solid transparent;border-radius:999px;background:#0f172a;color:#ffffff;
  box-shadow:0 2px 12px rgba(15,23,42,.25);cursor:pointer;font-size:13px;font-weight:600;line-height:1;
  font-family:inherit;transition:transform .12s ease,box-shadow .12s ease}
.${FAB_CLASS}:hover{box-shadow:0 4px 16px rgba(15,23,42,.35)}
.${FAB_CLASS}:active{transform:translateY(1px)}
.${FAB_CLASS}:focus{outline:none}
.${FAB_CLASS}:focus-visible{outline:3px solid #60a5fa;outline-offset:2px}
.${FAB_CLASS}:is([data-g7-color-scheme="dark"] *):not(:where([data-g7-color-scheme="dark"] [data-g7-color-scheme="light"] *)){
  background:#f8fafc;color:#0f172a;border-color:#cbd5e1;box-shadow:0 2px 12px rgba(0,0,0,.5)}
@media print{.${FAB_CLASS}{display:none !important}}
`;

export function EditEntryFab(props: EditEntryFabProps): React.ReactElement | null {
  const { t } = useTranslation();

  const shouldShow = shouldRenderEditEntryFab({
    pathname: props.pathname,
    isAuthenticated: props.isAuthenticated,
    hasLayoutEditPermission: props.hasLayoutEditPermission,
  });

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
      <style data-g7le-edit-entry-fab-style="">{FAB_STYLE}</style>
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
