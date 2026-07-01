/**
 * EditorToolbar.tsx
 *
 * 레이아웃 편집기 상단 툴바 — Phase 1 골격.
 *
 * 모든 문자열은 `$t:layout_editor.chrome.toolbar.*` 키 — 코어 TranslationEngine
 * 이 자동 해석. 후속 Phase 가 골격을 채운다.
 *
 * @since engine-v1.50.0
 */

import React, { useEffect, useRef, useState } from 'react';
import { useTranslation } from '../../TranslationContext';
import { useLayoutEditor } from '../LayoutEditorContext';

/**
 * hover 전이를 인라인 style 로 표현하는 툴바 버튼.
 *
 * 인라인 `React.CSSProperties` 는 `:hover` pseudo-class 를 표현할 수 없으므로
 * (편집기 코어는 CSS 라이브러리 비종속 — 별도 CSS 파일 없음), onMouseEnter/Leave
 * 로 hover 상태를 관리해 `hoverStyle` 을 base style 위에 병합한다. disabled 시엔
 * hover 를 무시한다.
 */
interface ToolbarButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  baseStyle: React.CSSProperties;
  hoverStyle: React.CSSProperties;
}

function ToolbarButton({
  baseStyle,
  hoverStyle,
  disabled,
  style,
  children,
  ...rest
}: ToolbarButtonProps): React.ReactElement {
  const [hovered, setHovered] = useState(false);
  const merged: React.CSSProperties = {
    // 브라우저 기본 focus outline 제거 — 클릭으로 focus 가 남으면 검정 테두리가
    // 잔류하던 결함 방지. 인라인 style 은:focus-visible 을 표현할
    // 수 없으므로 default outline 을 끄고, 시각 affordance 는 hover/active 색으로 대체.
    outline: 'none',
    ...baseStyle,
    ...style,
    ...(hovered && !disabled ? hoverStyle : {}),
  };
  return (
    <button
      type="button"
      disabled={disabled}
      onMouseEnter={() => setHovered(true)}
      onMouseLeave={() => setHovered(false)}
      style={merged}
      {...rest}
    >
      {children}
    </button>
  );
}

/** 템플릿 전환 드롭다운 항목 — EditorToolbar 가 받아 표시. */
export interface EditorTemplateOption {
  identifier: string;
  name: string;
  version: string;
  type: 'admin' | 'user';
}

/**
 * 상단 툴바 템플릿 식별자 영역 — 이름·버전 표시 + 전환 드롭다운.
 *
 * 종전엔 식별자 문자열만 노출했다. 이제 표시 이름(다국어 해석)과 버전을 함께 보이고,
 * 전환 가능한 다른 템플릿 목록을 드롭다운으로 제공한다. 항목 선택 시 onSwitch 가
 * 호출되며, 미저장 변경(dirty) 확인은 상위(LayoutEditorChrome)가 담당한다.
 *
 * 전환 목록이 없거나(또는 자기 자신만 있으면) 드롭다운 토글을 비활성화하고 정적
 * 라벨로 디그레이드한다.
 *
 * @since engine-v1.50.0
 */
function TemplateSwitcher({
  templateIdentifier,
  templateName,
  templateVersion,
  templateList,
  onSwitch,
}: {
  templateIdentifier: string;
  templateName?: string | null;
  templateVersion?: string | null;
  templateList?: EditorTemplateOption[];
  onSwitch?: (identifier: string) => void;
}): React.ReactElement {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const [hovered, setHovered] = useState(false);
  const rootRef = useRef<HTMLDivElement | null>(null);

  // 외부 클릭 / Escape 로 닫기
  useEffect(() => {
    if (!open) return;
    const onDocClick = (e: MouseEvent): void => {
      if (rootRef.current && !rootRef.current.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent): void => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onDocClick);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDocClick);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  // 드롭다운에는 현재 선택된 템플릿도 함께 노출한다 — 현재 항목은 선택 표시(✓)
  // 와 함께 비활성(클릭해도 이동 없음)으로 둔다. 토글 활성화 여부는 "전환 가능한 다른
  // 템플릿이 하나라도 있는가"로 판정한다(자기 자신만 설치 시 정적 라벨 디그레이드).
  const allOptions = templateList ?? [];
  const hasOther = allOptions.some((opt) => opt.identifier !== templateIdentifier);
  const canSwitch = !!onSwitch && hasOther;
  const displayName = templateName || templateIdentifier;

  const handleSelect = (identifier: string): void => {
    // 현재 선택된 항목은 이동 없이 닫기만 (재진입 confirm 회피)
    if (identifier === templateIdentifier) {
      setOpen(false);
      return;
    }
    setOpen(false);
    onSwitch?.(identifier);
  };

  return (
    <div
      ref={rootRef}
      className="g7le-toolbar__template-switcher"
      style={{ position: 'relative', display: 'inline-flex' }}
    >
      <button
        type="button"
        className="g7le-toolbar__template"
        data-testid="g7le-toolbar-template-label"
        data-can-switch={canSwitch ? 'true' : 'false'}
        aria-haspopup={canSwitch ? 'listbox' : undefined}
        aria-expanded={canSwitch ? open : undefined}
        disabled={!canSwitch}
        onClick={() => canSwitch && setOpen((v) => !v)}
        onMouseEnter={() => setHovered(true)}
        onMouseLeave={() => setHovered(false)}
        title={canSwitch ? t('layout_editor.chrome.toolbar.switch_template') : displayName}
        style={{
          display: 'inline-flex',
          alignItems: 'center',
          gap: 6,
          padding: '4px 8px',
          fontSize: 13,
          fontWeight: 600,
          color: '#0f172a',
          letterSpacing: 0.1,
          borderRadius: 6,
          borderWidth: 1,
          borderStyle: 'solid',
          borderColor: canSwitch && (hovered || open) ? '#cbd5e1' : 'transparent',
          backgroundColor: canSwitch && (hovered || open) ? '#f1f5f9' : 'transparent',
          cursor: canSwitch ? 'pointer' : 'default',
          outline: 'none',
          transition: 'background-color 120ms, border-color 120ms',
        }}
      >
        <span className="g7le-toolbar__template-name" data-testid="g7le-toolbar-template-name">
          {displayName}
        </span>
        {templateVersion && (
          <span
            className="g7le-toolbar__template-version"
            data-testid="g7le-toolbar-template-version"
            style={{
              fontSize: 11,
              fontWeight: 500,
              color: '#64748b',
              padding: '1px 6px',
              borderRadius: 999,
              background: '#f1f5f9',
              border: '1px solid #e2e8f0',
            }}
          >
            v{templateVersion}
          </span>
        )}
        {canSwitch && (
          <span
            className="g7le-toolbar__template-caret"
            aria-hidden="true"
            style={{ fontSize: 9, color: '#94a3b8', marginLeft: 1 }}
          >
            ▼
          </span>
        )}
      </button>

      {canSwitch && open && (
        <div
          className="g7le-toolbar__template-menu"
          data-testid="g7le-toolbar-template-menu"
          role="listbox"
          style={{
            position: 'absolute',
            top: 'calc(100% + 4px)',
            left: 0,
            minWidth: 240,
            maxWidth: 360,
            maxHeight: 360,
            overflowY: 'auto',
            background: '#ffffff',
            border: '1px solid #e2e8f0',
            borderRadius: 8,
            boxShadow: '0 8px 24px rgba(15, 23, 42, 0.14)',
            zIndex: 30,
            padding: 4,
          }}
        >
          {allOptions.map((opt) => {
            const isCurrent = opt.identifier === templateIdentifier;
            return (
              <button
                key={opt.identifier}
                type="button"
                role="option"
                aria-selected={isCurrent}
                aria-current={isCurrent ? 'true' : undefined}
                className="g7le-toolbar__template-menu-item"
                data-testid="g7le-toolbar-template-menu-item"
                data-identifier={opt.identifier}
                data-current={isCurrent ? 'true' : 'false'}
                onClick={() => handleSelect(opt.identifier)}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'space-between',
                  gap: 8,
                  width: '100%',
                  padding: '8px 10px',
                  fontSize: 13,
                  color: '#1e293b',
                  background: isCurrent ? '#eff6ff' : 'transparent',
                  border: 'none',
                  borderRadius: 6,
                  cursor: isCurrent ? 'default' : 'pointer',
                  textAlign: 'left',
                  outline: 'none',
                }}
                onMouseEnter={(e) => {
                  if (!isCurrent) (e.currentTarget as HTMLButtonElement).style.background = '#f1f5f9';
                }}
                onMouseLeave={(e) => {
                  (e.currentTarget as HTMLButtonElement).style.background = isCurrent ? '#eff6ff' : 'transparent';
                }}
              >
                <span style={{ display: 'flex', alignItems: 'center', gap: 8, minWidth: 0 }}>
                  {/* 현재 선택 표시 — 체크 글리프(자리 고정으로 정렬 흔들림 방지) */}
                  <span
                    aria-hidden="true"
                    style={{
                      width: 12,
                      flex: '0 0 auto',
                      color: '#2563eb',
                      fontSize: 12,
                      textAlign: 'center',
                    }}
                  >
                    {isCurrent ? '✓' : ''}
                  </span>
                  <span style={{ display: 'flex', flexDirection: 'column', gap: 2, minWidth: 0 }}>
                    <span
                      style={{
                        fontWeight: 600,
                        color: isCurrent ? '#1d4ed8' : '#1e293b',
                        overflow: 'hidden',
                        textOverflow: 'ellipsis',
                        whiteSpace: 'nowrap',
                      }}
                    >
                      {opt.name || opt.identifier}
                    </span>
                    <span
                      style={{
                        fontSize: 11,
                        color: '#94a3b8',
                        overflow: 'hidden',
                        textOverflow: 'ellipsis',
                        whiteSpace: 'nowrap',
                      }}
                    >
                      {opt.identifier}
                    </span>
                  </span>
                </span>
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, flex: '0 0 auto' }}>
                  <span
                    style={{
                      fontSize: 10,
                      fontWeight: 600,
                      color: opt.type === 'admin' ? '#7c3aed' : '#0891b2',
                      textTransform: 'uppercase',
                      letterSpacing: 0.4,
                    }}
                  >
                    {opt.type}
                  </span>
                  {opt.version && (
                    <span style={{ fontSize: 11, color: '#64748b' }}>v{opt.version}</span>
                  )}
                </span>
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}

export interface EditorToolbarProps {
  /** 저장 클릭 — LayoutEditorChrome 이 useLayoutDocument.save 와 연결 */
  onSave?: () => void | Promise<void>;
  /** dirty 표시 (저장 버튼 활성/스타일 분기) */
  isDirty?: boolean;
  /** 편집 대상 템플릿 표시 이름 (다국어 해석됨) — 미수신 시 식별자로 폴백 */
  templateName?: string | null;
  /** 편집 대상 템플릿 버전 */
  templateVersion?: string | null;
  /** 전환 가능한 템플릿 목록 — 드롭다운 채움 */
  templateList?: EditorTemplateOption[];
  /** 템플릿 전환 클릭 — LayoutEditorChrome 이 dirty 가드 후 페이지 이동 */
  onSwitchTemplate?: (identifier: string) => void;
  /** 나가기 클릭 — LayoutEditorChrome 이 편집기 종료/복귀 navigate 와 연결 */
  onExit?: () => void;
  /** 코드편집 클릭 — 기존 텍스트 레이아웃 편집기 화면으로 navigate */
  onEditCode?: () => void;
  /** 이미지 관리 클릭 — 현재 레이아웃 첨부 이미지 관리 모달 */
  onManageImages?: () => void;
  /** 다국어 관리 클릭 — 현재 레이아웃 커스텀 다국어 키 관리 모달 */
  onManageTranslations?: () => void;
  /**
   * 페이지 설정 클릭 — 현재 레이아웃 8탭 페이지 설정 모달.
   * 데이터 소스 관리는 이 모달의 [데이터] 탭으로 흡수됐다(종전 ⚙데이터 버튼 제거 — 진입점
   * 단일화). route/base/modal & selectedRoute 존재 시 활성, extension/iteration_item 비활성.
   */
  onPageSettings?: () => void;
  /** 초기화 클릭 — 현재 레이아웃을 마지막 저장 상태로 되돌림 */
  onReset?: () => void;
  /** 단축키 맵 모달 열기 (⌨ 버튼 / `?` 단축키) */
  onShowShortcuts?: () => void;
  /**
   * 미리보기 클릭 — 현재 편집 중(미저장 포함) 문서를 실데이터로 새 창 미리보기.
   * Promise 반환 시 완료까지 버튼 스피너 + disabled.
   */
  onPreview?: () => void | Promise<void>;
  /** 버전 히스토리 클릭 — 현재 레이아웃 저장 버전 목록·복원 모달 */
  onShowVersions?: () => void;
}

export function EditorToolbar(props: EditorToolbarProps = {}): React.ReactElement {
  const {
    onSave,
    isDirty = false,
    onExit,
    onEditCode,
    onManageImages,
    onManageTranslations,
    onPageSettings,
    onReset,
    onShowShortcuts,
    onPreview,
    onShowVersions,
    templateName,
    templateVersion,
    templateList,
    onSwitchTemplate,
  } = props;

  // 저장 진행 상태 — onSave 가 Promise 면 완료까지 스피너 + disabled.
  const [isSaving, setIsSaving] = useState(false);
  // 미리보기 진행 상태 — onPreview 가 Promise 면 완료까지 스피너 + disabled.
  const [isPreviewing, setIsPreviewing] = useState(false);
  const { t } = useTranslation();
  const { state, dispatch } = useLayoutEditor();

  // EditorCanvasOverlay 가 window.__g7LayoutEditorHistory 로 노출하는 undo/redo
  // 핸들러를 polling 으로 가져온다 — 단일 캔버스 셋업에서만 사용.
  // canUndo/canRedo 가 변경되면 useEffect 가 다시 읽도록 history 객체 리렌더 트리거.
  const [history, setHistory] = useState<{
    undo: () => void;
    redo: () => void;
    canUndo: boolean;
    canRedo: boolean;
  } | null>(null);
  useEffect(() => {
    const tick = (): void => {
      const h = (window as any).__g7LayoutEditorHistory;
      if (h) {
        setHistory({
          undo: h.undo,
          redo: h.redo,
          canUndo: !!h.canUndo,
          canRedo: !!h.canRedo,
        });
      } else {
        setHistory(null);
      }
    };
    tick();
    const id = window.setInterval(tick, 250);
    return () => window.clearInterval(id);
  }, []);

  const onToggleRouteTree = (): void => {
    dispatch({ type: 'TOGGLE_ROUTE_TREE' });
  };

  const modeBadge = (): string | null => {
    if (state.editMode === 'base') return t('layout_editor.chrome.toolbar.mode_badge.base');
    if (state.editMode === 'modal') return t('layout_editor.chrome.toolbar.mode_badge.modal');
    if (state.editMode === 'extension') return t('layout_editor.chrome.toolbar.mode_badge.extension');
    if (state.editMode === 'iteration_item') return t('layout_editor.chrome.toolbar.mode_badge.iteration_item');
    return null;
  };

  const onExitMode = (): void => {
    if (state.editMode === 'base') dispatch({ type: 'EXIT_BASE_EDIT' });
    else if (state.editMode === 'modal') dispatch({ type: 'EXIT_MODAL_EDIT' });
    else if (state.editMode === 'extension') dispatch({ type: 'EXIT_EXTENSION_EDIT' });
    else if (state.editMode === 'iteration_item') dispatch({ type: 'EXIT_ITERATION_ITEM_EDIT' });
  };

  const badge = modeBadge();
  const isAltMode = state.editMode !== 'route';

  // border / background 는 longhand(borderWidth/Style/Color, backgroundColor)로만 표기한다.
  // shorthand(`border`/`background`)와 longhand(`borderColor`)를 섞으면 hover 해제 시 React 가
  // borderColor 만 제거하고 border-width/style 은 남겨 border 가 color(텍스트색)로 폴백되며
  // 검정 테두리가 잔류한다. 양쪽 모두 longhand 로 통일.
  const buttonBase: React.CSSProperties = {
    display: 'inline-flex',
    alignItems: 'center',
    gap: 6,
    padding: '6px 12px',
    fontSize: 13,
    fontWeight: 500,
    borderRadius: 6,
    borderWidth: 1,
    borderStyle: 'solid',
    borderColor: 'transparent',
    backgroundColor: 'transparent',
    color: '#475569',
    cursor: 'pointer',
    transition: 'background-color 120ms, color 120ms, border-color 120ms',
  };

  const primaryButton: React.CSSProperties = {
    ...buttonBase,
    backgroundColor: '#2563eb',
    color: '#ffffff',
    borderColor: '#2563eb',
    fontWeight: 600,
  };

  const ghostButton: React.CSSProperties = {
    ...buttonBase,
    borderColor: '#e2e8f0',
    backgroundColor: '#ffffff',
    color: '#0f172a',
  };

  // hover 전이 — :not(:disabled) 에서만 적용 (ToolbarButton 이 disabled 시 무시).
  // base 와 동일하게 longhand 만 사용 → hover 해제 시 React 가 borderColor/backgroundColor 를
  // base 값으로 정확히 되돌린다(잔류 없음).
  const buttonHover: React.CSSProperties = {
    backgroundColor: '#f1f5f9',
    color: '#0f172a',
    borderColor: '#cbd5e1',
  };
  const primaryHover: React.CSSProperties = {
    backgroundColor: '#1d4ed8',
    borderColor: '#1d4ed8',
  };
  const ghostHover: React.CSSProperties = {
    backgroundColor: '#f8fafc',
    borderColor: '#cbd5e1',
  };

  return (
    <div
      className="g7le-toolbar"
      data-testid="g7le-toolbar"
      role="toolbar"
      style={{
        display: 'flex',
        alignItems: 'center',
        gap: 6,
        padding: '8px 12px',
        borderBottom: '1px solid #e2e8f0',
        background: '#ffffff',
        boxShadow: '0 1px 2px rgba(15, 23, 42, 0.04)',
      }}
    >
      {/* 저장 스피너 회전 keyframe — 인라인 style 로는 @keyframes 표현 불가. */}
      <style>{'@keyframes g7le-spin { to { transform: rotate(360deg); } }'}</style>
      <button
        type="button"
        className="g7le-toolbar__route-tree-toggle"
        data-testid="g7le-toolbar-toggle-route-tree"
        aria-label={t('layout_editor.chrome.toolbar.toggle_route_tree')}
        title={t('layout_editor.chrome.toolbar.toggle_route_tree')}
        onClick={onToggleRouteTree}
        style={{
          ...buttonBase,
          outline: 'none',
          padding: '6px 10px',
          fontSize: 16,
          lineHeight: 1,
          color: '#64748b',
        }}
      >
        ☰
      </button>

      <TemplateSwitcher
        templateIdentifier={state.templateIdentifier}
        templateName={templateName}
        templateVersion={templateVersion}
        templateList={templateList}
        onSwitch={onSwitchTemplate}
      />

      {badge && (
        <span
          className="g7le-toolbar__mode-badge"
          data-testid="g7le-toolbar-mode-badge"
          data-mode={state.editMode}
          style={{
            padding: '2px 8px',
            borderRadius: 999,
            background: '#fef3c7',
            color: '#92400e',
            fontSize: 11,
            fontWeight: 600,
            border: '1px solid #fde68a',
          }}
        >
          {badge}
        </span>
      )}

      <div style={{ flex: 1 }} />

      {/* 툴바 본체 — route 모드뿐 아니라 **별도 편집 모드(확장/반복/공통레이아웃/모달)에서도
          항상 표시**한다(D-29). 모든 핸들러(onSave/onReset/onEditCode/onShowVersions/…)는
          LayoutEditorChrome 이 activeDocument(편집 모드별 문서) 기준으로 연결하므로, 각 버튼이
          현재 편집 대상(확장 조각/항목 템플릿/base/모달)에 맞게 동작한다. 종전엔 `!isAltMode`
          게이트로 별도 모드에서 본체를 통째로 숨겨 "← 종료" 버튼만 남았다. */}
      <>
          {/* Undo / Redo — EditorCanvasOverlay 가 window.__g7LayoutEditorHistory 로 공급 */}
          <ToolbarButton
            data-testid="g7le-toolbar-undo"
            aria-label={t('layout_editor.chrome.toolbar.undo')}
            title={t('layout_editor.chrome.toolbar.undo')}
            disabled={!history?.canUndo}
            onClick={() => history?.undo?.()}
            baseStyle={buttonBase}
            hoverStyle={buttonHover}
            style={{ opacity: history?.canUndo ? 1 : 0.4 }}
          >
            ↶
          </ToolbarButton>
          <ToolbarButton
            data-testid="g7le-toolbar-redo"
            aria-label={t('layout_editor.chrome.toolbar.redo')}
            title={t('layout_editor.chrome.toolbar.redo')}
            disabled={!history?.canRedo}
            onClick={() => history?.redo?.()}
            baseStyle={buttonBase}
            hoverStyle={buttonHover}
            style={{ opacity: history?.canRedo ? 1 : 0.4 }}
          >
            ↷
          </ToolbarButton>
          <ToolbarButton
            data-testid="g7le-toolbar-add-element"
            data-open={state.isPaletteOpen ? 'true' : 'false'}
            disabled={!state.selectedRoute}
            onClick={() => dispatch({ type: 'TOGGLE_PALETTE' })}
            aria-pressed={state.isPaletteOpen}
            baseStyle={buttonBase}
            hoverStyle={buttonHover}
            style={
              state.isPaletteOpen
                ? { backgroundColor: '#eff6ff', color: '#1d4ed8', borderColor: '#93c5fd' }
                : undefined
            }
          >
            ＋ {t('layout_editor.chrome.toolbar.add_element')}
          </ToolbarButton>
          <ToolbarButton
            data-testid="g7le-toolbar-preview"
            data-previewing={isPreviewing ? 'true' : 'false'}
            disabled={!onPreview || !state.selectedRoute?.layoutName || isPreviewing}
            onClick={() => {
              const result = onPreview?.();
              if (result && typeof (result as Promise<void>).then === 'function') {
                setIsPreviewing(true);
                (result as Promise<void>).finally(() => setIsPreviewing(false));
              }
            }}
            baseStyle={buttonBase}
            hoverStyle={buttonHover}
            style={{ opacity: isPreviewing ? 0.7 : 1 }}
          >
            {isPreviewing ? (
              <>
                <span
                  data-testid="g7le-toolbar-preview-spinner"
                  aria-hidden="true"
                  style={{
                    display: 'inline-block',
                    width: 12,
                    height: 12,
                    border: '2px solid rgba(71,85,105,0.35)',
                    borderTopColor: '#475569',
                    borderRadius: '50%',
                    animation: 'g7le-spin 600ms linear infinite',
                  }}
                />
                {t('layout_editor.chrome.toolbar.previewing')}
              </>
            ) : (
              <>👁 {t('layout_editor.chrome.toolbar.preview')}</>
            )}
          </ToolbarButton>
          <ToolbarButton
            data-testid="g7le-toolbar-versions"
            disabled={
              // 레이아웃 본체(layoutName) 또는 확장 편집(`__extension__/` 가상 path —
              // ) 선택 시 활성. 둘 다 아니면(라우트 미선택) 비활성.
              !onShowVersions ||
              !(
                state.selectedRoute?.layoutName ||
                state.selectedRoute?.path?.startsWith('__extension__/')
              )
            }
            onClick={() => onShowVersions?.()}
            aria-label={t('layout_editor.chrome.toolbar.version_history')}
            title={t('layout_editor.chrome.toolbar.version_history')}
            baseStyle={buttonBase}
            hoverStyle={buttonHover}
          >
            🕘 {t('layout_editor.chrome.toolbar.version_history')}
          </ToolbarButton>
          <ToolbarButton
            data-testid="g7le-toolbar-edit-code"
            disabled={!onEditCode}
            onClick={() => onEditCode?.()}
            baseStyle={buttonBase}
            hoverStyle={buttonHover}
          >
            ⟨/⟩ {t('layout_editor.chrome.toolbar.edit_code')}
          </ToolbarButton>
          <ToolbarButton
            data-testid="g7le-toolbar-images"
            disabled={!onManageImages || !state.selectedRoute}
            onClick={() => onManageImages?.()}
            baseStyle={buttonBase}
            hoverStyle={buttonHover}
          >
            🖼 {t('layout_editor.chrome.toolbar.images')}
          </ToolbarButton>
          <ToolbarButton
            data-testid="g7le-toolbar-translations"
            disabled={!onManageTranslations || !state.selectedRoute}
            onClick={() => onManageTranslations?.()}
            baseStyle={buttonBase}
            hoverStyle={buttonHover}
          >
            🌐 {t('layout_editor.chrome.toolbar.translations')}
          </ToolbarButton>
          <ToolbarButton
            data-testid="g7le-toolbar-page-settings"
            disabled={
              !onPageSettings ||
              !state.selectedRoute ||
              state.editMode === 'extension' ||
              state.editMode === 'iteration_item'
            }
            onClick={() => onPageSettings?.()}
            baseStyle={buttonBase}
            hoverStyle={buttonHover}
          >
            ⚙ {t('layout_editor.chrome.toolbar.page_settings')}
          </ToolbarButton>
          <ToolbarButton
            data-testid="g7le-toolbar-reset"
            disabled={!onReset || !isDirty}
            onClick={() => onReset?.()}
            aria-label={t('layout_editor.chrome.toolbar.reset')}
            title={t('layout_editor.chrome.toolbar.reset')}
            baseStyle={buttonBase}
            hoverStyle={buttonHover}
            style={{ opacity: isDirty ? 1 : 0.4 }}
          >
            ↺ {t('layout_editor.chrome.toolbar.reset')}
          </ToolbarButton>
          <ToolbarButton
            data-testid="g7le-toolbar-save"
            data-dirty={isDirty ? 'true' : 'false'}
            data-saving={isSaving ? 'true' : 'false'}
            disabled={!onSave || isSaving}
            onClick={() => {
              const result = onSave?.();
              if (result && typeof (result as Promise<void>).then === 'function') {
                setIsSaving(true);
                (result as Promise<void>).finally(() => setIsSaving(false));
              }
            }}
            baseStyle={primaryButton}
            hoverStyle={primaryHover}
            style={{ opacity: isSaving ? 0.7 : isDirty ? 1 : 0.7 }}
          >
            {isSaving ? (
              <>
                <span
                  data-testid="g7le-toolbar-save-spinner"
                  aria-hidden="true"
                  className="g7le-toolbar__spinner"
                  style={{
                    display: 'inline-block',
                    width: 12,
                    height: 12,
                    border: '2px solid rgba(255,255,255,0.4)',
                    borderTopColor: '#ffffff',
                    borderRadius: '50%',
                    animation: 'g7le-spin 600ms linear infinite',
                  }}
                />
                {t('layout_editor.chrome.toolbar.saving')}
              </>
            ) : (
              <>💾 {t('layout_editor.chrome.toolbar.save')}</>
            )}
          </ToolbarButton>
          <ToolbarButton
            data-testid="g7le-toolbar-shortcuts"
            disabled={!onShowShortcuts}
            onClick={() => onShowShortcuts?.()}
            aria-label={t('layout_editor.shortcuts.title')}
            title={t('layout_editor.shortcuts.title')}
            baseStyle={buttonBase}
            hoverStyle={buttonHover}
          >
            ⌨
          </ToolbarButton>
          <ToolbarButton
            data-testid="g7le-toolbar-exit"
            disabled={!onExit}
            onClick={() => onExit?.()}
            baseStyle={ghostButton}
            hoverStyle={ghostHover}
          >
            ✕ {t('layout_editor.chrome.toolbar.exit')}
          </ToolbarButton>
        </>

      {isAltMode && (
        <ToolbarButton
          data-testid="g7le-toolbar-exit-alt-mode"
          onClick={onExitMode}
          baseStyle={ghostButton}
          hoverStyle={ghostHover}
        >
          ← {state.editMode === 'base' && t('layout_editor.chrome.toolbar.exit_base_edit')}
          {state.editMode === 'modal' && t('layout_editor.chrome.toolbar.exit_modal_edit')}
          {state.editMode === 'extension' && t('layout_editor.chrome.toolbar.exit_extension_edit')}
          {state.editMode === 'iteration_item' && t('layout_editor.chrome.toolbar.exit_iteration_item_edit')}
        </ToolbarButton>
      )}
    </div>
  );
}
