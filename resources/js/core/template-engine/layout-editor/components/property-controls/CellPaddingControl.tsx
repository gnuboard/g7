// e2e:allow 레이아웃 편집기 캔버스 오버레이/속성패널 UI — dnd-kit/합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스(T1~T8) 실측 + 단위/레이아웃 렌더링 테스트로 검증
/**
 * CellPaddingControl.tsx — 셀 내부 여백 시각 피커
 *
 * 표 셀의 **내부 여백(padding)** 을 raw className 타이핑 없이 시각 UI 로 지정한다(계획서
 *  raw 편집 금지 · 기술용어 배제). 프리셋 단계(없음/좁게/보통/넓게) 칩 +
 * 자유 px 입력을 고르면 셀 인라인 `style.padding` 에 적용된다.
 *
 * **인라인 style SSoT**: 셀 배경색/테두리 색과 동일하게 여백은 인라인
 * `style.padding`(예 `'12px'`)으로 적용한다 — 자유 px 도 라이브러리 빌드 비종속(편집기·
 * 사용자 페이지 동일 렌더), 다크/임의값 빌드 누락 위험 없음.
 *
 * **라이브러리 중립**(메모리 feedback_layout_editor_no_css_lib_dependency): 코어는 여백
 * 어휘를 모른다 — 프리셋 단계와 px 값은 템플릿 editor-spec 의 cellPadding 카탈로그
 * (`nodeEditor.params.cellPadding`)가 공급하고, 코어는 그 px 를 인라인 style 에 바를 뿐.
 *
 * 속성 패널 TableEditor 와 캔버스 인플레이스 TableInplaceOverlay 가 공유한다.
 *
 * @since engine-v1.50.0
 */

import React, { useEffect, useState } from 'react';

/** cellPadding 카탈로그(템플릿 editor-spec params.cellPadding). */
export interface CellPaddingCatalog {
  /** 프리셋 단계 — px 가 적용값(빈/undefined = 여백 제거). */
  steps?: Array<{ value: string; label?: string; px?: number }>;
}

export interface CellPaddingControlProps {
  /** 현재 셀 인라인 여백(style.padding, 예 '12px'). 없으면 빈 문자열. */
  paddingStyle?: string;
  /** 카탈로그(템플릿 공급). 없으면 자유 px 입력만. */
  catalog?: CellPaddingCatalog | null;
  /** 다국어 해석. */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 여백 변경 콜백 — CSS 값(예 '12px'), 빈 문자열=제거. */
  onChange: (padding: string) => void;
  /** 비활성. */
  disabled?: boolean;
}

/** style.padding('12px') → 숫자 px(매칭 안 되면 null). */
function parsePx(padding: string): number | null {
  const m = /^(\d+)px$/.exec(padding.trim());
  return m ? parseInt(m[1]!, 10) : null;
}

export function CellPaddingControl({
  paddingStyle,
  catalog,
  t,
  onChange,
  disabled,
}: CellPaddingControlProps): React.ReactElement {
  const steps = catalog?.steps ?? [];
  const cur = paddingStyle ?? '';
  const curPx = parsePx(cur);

  const lbl = (key: string | undefined, fallback: string): string =>
    key && key.startsWith('$t:') ? t(key.slice(3)) : key || fallback;

  // 자유 px 입력 — 현재 값이 프리셋 단계와 일치하지 않을 때 직접 입력.
  const matchesStep = steps.some((s) => (s.px ?? 0) === (curPx ?? -1));
  const derivedFree = curPx !== null && !matchesStep ? String(curPx) : '';
  const [free, setFree] = useState<string>(derivedFree);
  // 선택 셀이 바뀌면(paddingStyle prop 변화) free 입력값을 그 셀의 비-프리셋 값으로 재동기화.
  // 컴포넌트가 셀마다 리마운트되지 않아 useState 초기값이 stale 하게 남던 결함 방지(다른 셀
  // 선택 후에도 이전 셀의 자유 px 가 입력칸에 남던 문제,  브라우저 실측). 편집기
  // 패치 본체는 노드 파생이어야 한다(feedback_editor_modal_remounts_content_stateful_tab...).
  useEffect(() => {
    setFree(derivedFree);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [paddingStyle]);

  const applyStep = (px: number | undefined): void => {
    onChange(px && px > 0 ? `${px}px` : '');
    setFree('');
  };
  const applyFree = (raw: string): void => {
    const n = parseInt(raw, 10);
    if (Number.isFinite(n) && n >= 0) onChange(n > 0 ? `${n}px` : '');
  };

  return (
    <div className="g7le-cell-padding" data-testid="g7le-cell-padding" style={wrap}>
      <div style={sectionLabel}>{t('layout_editor.table_editor.cell_padding')}</div>
      <div style={row} data-testid="g7le-cell-padding-steps">
        {steps.map((s) => {
          const active = (s.px ?? 0) === (curPx ?? -1) || (!s.px && cur.trim() === '');
          return (
            <button
              key={s.value}
              type="button"
              disabled={disabled}
              data-testid={`g7le-cell-padding-step-${s.value}`}
              aria-pressed={active}
              onClick={() => applyStep(s.px)}
              style={active ? chipActive : chip}
            >
              {lbl(s.label, s.value)}
            </button>
          );
        })}
        {/* 자유 px 입력 */}
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3 }}>
          <input
            type="number"
            min={0}
            disabled={disabled}
            data-testid="g7le-cell-padding-free"
            value={free}
            placeholder={t('layout_editor.table_editor.cell_padding_custom')}
            onChange={(e) => setFree(e.target.value)}
            onBlur={() => free.trim() !== '' && applyFree(free)}
            onKeyDown={(e) => { if (e.key === 'Enter' && free.trim() !== '') applyFree(free); }}
            style={freeInput}
          />
          <span style={{ fontSize: 11, color: '#94a3b8' }}>px</span>
        </span>
      </div>
    </div>
  );
}

const wrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6 };
const sectionLabel: React.CSSProperties = { fontSize: 12, fontWeight: 600, color: '#475569' };
const row: React.CSSProperties = { display: 'flex', gap: 4, flexWrap: 'wrap', alignItems: 'center' };
const chip: React.CSSProperties = { padding: '3px 8px', fontSize: 11, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer' };
const chipActive: React.CSSProperties = { ...chip, border: '1px solid #2563eb', background: '#eff6ff', color: '#1d4ed8', fontWeight: 600 };
const freeInput: React.CSSProperties = { width: 48, padding: '3px 5px', fontSize: 11, border: '1px solid #cbd5e1', borderRadius: 6, boxSizing: 'border-box' };
