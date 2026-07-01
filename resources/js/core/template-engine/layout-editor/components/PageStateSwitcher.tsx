/**
 * PageStateSwitcher.tsx — 캔버스 툴바 페이지 상태 토글
 *
 * 편집기 디바이스/줌 토글 옆에 두는 상태 드롭다운. `editorContext.availableStates`
 * (현재 편집 대상 scope 에 매칭된 상태 목록)를 항목으로 노출하고, 선택 변경 시
 * `SET_ACTIVE_STATE` 를 디스패치해 캔버스를 즉시 재시뮬레이션한다.
 *
 * 디그레이드: availableStates 가 1개 이하면 토글 자체를 미표시
 * (states 미선언 / scope 미매칭 / 기본 상태만 — 불필요한 UI 노이즈 제거).
 *
 * 라벨/설명은 `$t:` 다국어 키 또는 평문. `$t:` 접두면 전역 `G7Core.t`
 * (PreviewCanvas 가 편집 대상 사전 fallback 체인을 설치) → 코어 `t` 순으로 해석한다.
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useEffect, useState } from 'react';
import { useTranslation } from '../../TranslationContext';
import { useLayoutEditor } from '../LayoutEditorContext';
import { trackPageState } from '../devtools/editorTrackers';
import { EDITOR_T_READY_EVENT } from './PreviewCanvas';
import type { EditorStateItemSpec } from '../spec/specTypes';

export function PageStateSwitcher(): React.ReactElement | null {
  const { t } = useTranslation();
  const { state, dispatch } = useLayoutEditor();
  const { availableStates, activeStateId } = state;

  // PreviewCanvas 가 전역 `G7Core.t` 를 편집 대상 사전 fallback 으로 교체하는 시점은
  // 본 컴포넌트 첫 렌더 직후(PreviewCanvas effect)다. 그래서 첫 렌더 때 `$t:` 라벨이
  // swap 전 admin t 로 해석돼 raw 키가 보일 수 있다. swap 완료 이벤트를 구독해 1회
  // 재렌더(라벨 재해석)한다. 이벤트 누락 대비로 마운트 시 RAF 1회도 예약한다.
  const [, forceTick] = useState(0);
  useEffect(() => {
    if (typeof window === 'undefined') return;
    const rerender = (): void => forceTick((n) => n + 1);
    const raf = window.requestAnimationFrame(rerender);
    window.addEventListener(EDITOR_T_READY_EVENT, rerender);
    return () => {
      window.cancelAnimationFrame(raf);
      window.removeEventListener(EDITOR_T_READY_EVENT, rerender);
    };
  }, []);

  // `$t:` 라벨/설명 해석 — 편집 대상 사전 fallback(전역 G7Core.t) 우선, 미해석 시 코어 t.
  // 평문(`$t:` 미접두)은 그대로 표시. EditorCanvasOverlay.editorAwareT 와 동형.
  const resolveLabel = useCallback(
    (value: string | undefined, fallback: string): string => {
      if (!value) return fallback;
      if (!value.startsWith('$t:')) return value;
      const key = value.slice(3);
      const g7 = (window as { G7Core?: { t?: (k: string) => string } }).G7Core;
      if (g7 && typeof g7.t === 'function') {
        const resolved = g7.t(key);
        if (resolved && resolved !== key && !resolved.startsWith('$t:')) return resolved;
      }
      const coreResolved = t(key);
      return coreResolved && coreResolved !== key ? coreResolved : fallback;
    },
    [t],
  );

  // 항목 1개 이하 → 토글 미표시(디그레이드).
  if (!Array.isArray(availableStates) || availableStates.length <= 1) {
    return null;
  }

  const onChange = (e: React.ChangeEvent<HTMLSelectElement>): void => {
    const nextId = e.target.value;
    dispatch({ type: 'SET_ACTIVE_STATE', activeStateId: nextId });
    trackPageState({
      kind: 'change',
      activeStateId: nextId,
      routePath: state.selectedRoute?.path ?? null,
      availableCount: availableStates.length,
    });
  };

  const active: EditorStateItemSpec | undefined =
    availableStates.find((s) => s.id === activeStateId) ?? availableStates[0];
  const activeDescription = active?.description
    ? resolveLabel(active.description, '')
    : '';

  return (
    <div
      className="g7le-state-switcher"
      data-testid="g7le-state-switcher"
      data-active-state={activeStateId ?? ''}
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 6,
        padding: '4px 8px',
        background: '#fff',
        border: '1px solid #cbd5e1',
        borderRadius: 6,
      }}
    >
      <span
        style={{ fontSize: 12, fontWeight: 600, color: '#475569', whiteSpace: 'nowrap' }}
      >
        {t('layout_editor.toolbar.state')}
      </span>
      <select
        data-testid="g7le-state-switcher-select"
        aria-label={t('layout_editor.toolbar.state')}
        value={activeStateId ?? availableStates[0].id}
        onChange={onChange}
        style={{
          fontSize: 12,
          padding: '2px 6px',
          border: '1px solid #cbd5e1',
          borderRadius: 4,
          background: '#fff',
          color: '#0f172a',
          cursor: 'pointer',
          outline: 'none',
        }}
      >
        {availableStates.map((item) => (
          <option key={item.id} value={item.id}>
            {resolveLabel(item.label, item.id)}
          </option>
        ))}
      </select>
      {activeDescription && (
        <span
          data-testid="g7le-state-switcher-description"
          style={{ fontSize: 11, color: '#94a3b8', whiteSpace: 'nowrap' }}
        >
          {activeDescription}
        </span>
      )}
    </div>
  );
}
