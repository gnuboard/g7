/**
 * VersionDiffView.tsx — 버전 간 Unified diff 뷰
 *
 * 두 레이아웃 버전의 content 를 받아 GitHub Unified diff 스타일로 렌더한다. 좌측에
 * 구/신 라인 번호 거터, 본문에 +추가(녹색)/-삭제(빨강)/context(중립) 라인을
 * 표시하고, hunk 경계마다 `@@ ... @@` 헤더를 둔다. 외부 diff 라이브러리 없이
 * 자체 lineDiff 엔진(computeLineDiff)을 사용한다.
 *
 * 편집기 코어 위젯이므로 `g7le-*` + 인라인 스타일만(CSS 라이브러리 비종속).
 *
 * @since engine-v1.50.0
 */

import React, { useMemo } from 'react';
import {
  computeLineDiff,
  stableStringify,
  type DiffLine,
} from '../utils/lineDiff';

export interface VersionDiffViewProps {
  /** 구버전 번호 (좌/old) */
  oldVersion: number;
  /** 신버전 번호 (우/new) */
  newVersion: number;
  /** 구버전 content (직렬화 전 객체) */
  oldContent: unknown;
  /** 신버전 content (직렬화 전 객체) */
  newContent: unknown;
  /** 다국어 해석 함수 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 뒤로(목록으로) */
  onBack: () => void;
  /** 모달 닫기 */
  onClose: () => void;
}

const wrap: React.CSSProperties = {
  display: 'flex',
  flexDirection: 'column',
  height: '100%',
  minHeight: 0,
};

const header: React.CSSProperties = {
  display: 'flex',
  alignItems: 'center',
  gap: 8,
  padding: '12px 16px',
  borderBottom: '1px solid #e2e8f0',
  background: '#f8fafc',
};

const backBtn: React.CSSProperties = {
  display: 'inline-flex',
  alignItems: 'center',
  gap: 4,
  padding: '4px 10px',
  borderRadius: 6,
  border: '1px solid #cbd5e1',
  background: '#ffffff',
  color: '#0f172a',
  fontSize: 12,
  fontWeight: 600,
  cursor: 'pointer',
  outline: 'none',
};

const closeBtn: React.CSSProperties = {
  marginLeft: 'auto',
  border: 'none',
  background: 'transparent',
  fontSize: 16,
  color: '#64748b',
  cursor: 'pointer',
  outline: 'none',
};

const summaryBar: React.CSSProperties = {
  display: 'flex',
  alignItems: 'center',
  gap: 12,
  padding: '8px 16px',
  borderBottom: '1px solid #e2e8f0',
  background: '#ffffff',
  fontSize: 12,
  fontWeight: 600,
};

const body: React.CSSProperties = {
  flex: 1,
  minHeight: 0,
  overflow: 'auto',
  background: '#ffffff',
  fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace',
  fontSize: 12,
  lineHeight: 1.5,
};

const hint: React.CSSProperties = {
  padding: '24px 12px',
  textAlign: 'center',
  color: '#94a3b8',
  fontSize: 13,
};

const hunkHeaderStyle: React.CSSProperties = {
  padding: '2px 12px',
  background: '#f1f5f9',
  color: '#64748b',
  borderTop: '1px solid #e2e8f0',
  borderBottom: '1px solid #e2e8f0',
  userSelect: 'none',
};

/** 라인 종류별 배경/거터/기호 색. */
function lineStyles(kind: DiffLine['kind']): {
  row: React.CSSProperties;
  gutter: React.CSSProperties;
  sign: string;
  signColor: string;
} {
  if (kind === 'add') {
    return {
      row: { background: '#e6ffed' },
      gutter: { background: '#cdffd8', color: '#22863a' },
      sign: '+',
      signColor: '#22863a',
    };
  }
  if (kind === 'remove') {
    return {
      row: { background: '#ffeef0' },
      gutter: { background: '#ffdce0', color: '#b31d28' },
      sign: '-',
      signColor: '#b31d28',
    };
  }
  return {
    row: { background: '#ffffff' },
    gutter: { background: '#f8fafc', color: '#94a3b8' },
    sign: ' ',
    signColor: '#94a3b8',
  };
}

const gutterCell: React.CSSProperties = {
  display: 'inline-block',
  width: 44,
  textAlign: 'right',
  padding: '0 6px',
  color: '#94a3b8',
  userSelect: 'none',
  borderRight: '1px solid #e2e8f0',
  whiteSpace: 'nowrap',
};

export function VersionDiffView({
  oldVersion,
  newVersion,
  oldContent,
  newContent,
  t,
  onBack,
  onClose,
}: VersionDiffViewProps): React.ReactElement {
  const diff = useMemo(() => {
    const oldText = stableStringify(oldContent);
    const newText = stableStringify(newContent);
    return computeLineDiff(oldText, newText);
  }, [oldContent, newContent]);

  const tooLarge = diff.addedCount < 0;

  return (
    <div className="g7le-version-diff" data-testid="g7le-version-diff" style={wrap}>
      <div data-modal-drag-handle style={header}>
        <button
          type="button"
          onClick={onBack}
          style={backBtn}
          data-testid="g7le-version-diff-back"
        >
          ← {t('layout_editor.version_history.diff_back')}
        </button>
        <span style={{ fontSize: 14, fontWeight: 700 }} data-testid="g7le-version-diff-title">
          {t('layout_editor.version_history.diff_title', { old: oldVersion, new: newVersion })}
        </span>
        <button
          type="button"
          aria-label={t('layout_editor.version_history.close')}
          onClick={onClose}
          style={closeBtn}
          data-testid="g7le-version-diff-close"
        >
          ✕
        </button>
      </div>

      {!tooLarge && (
        <div style={summaryBar} data-testid="g7le-version-diff-summary">
          <span style={{ color: '#16a34a' }} data-testid="g7le-version-diff-added">
            {t('layout_editor.version_history.added', { count: Math.max(0, diff.addedCount) })}
          </span>
          <span style={{ color: '#dc2626' }} data-testid="g7le-version-diff-removed">
            {t('layout_editor.version_history.removed', { count: Math.max(0, diff.removedCount) })}
          </span>
        </div>
      )}

      <div style={body}>
        {tooLarge ? (
          <div style={hint} data-testid="g7le-version-diff-too-large">
            {t('layout_editor.version_history.diff_too_large')}
          </div>
        ) : diff.identical ? (
          <div style={hint} data-testid="g7le-version-diff-identical">
            {t('layout_editor.version_history.diff_identical')}
          </div>
        ) : (
          diff.hunks.map((hunk, hi) => (
            <div key={hi} data-testid={`g7le-version-diff-hunk-${hi}`}>
              <div style={hunkHeaderStyle} data-testid={`g7le-version-diff-hunk-header-${hi}`}>
                {hunk.header}
              </div>
              {hunk.lines.map((line, li) => {
                const s = lineStyles(line.kind);
                return (
                  <div
                    key={li}
                    style={{ display: 'flex', alignItems: 'stretch', ...s.row }}
                    data-kind={line.kind}
                    data-testid={`g7le-version-diff-line-${hi}-${li}`}
                  >
                    <span style={{ ...gutterCell, ...s.gutter }} aria-hidden="true">
                      {line.oldLine ?? ''}
                    </span>
                    <span style={{ ...gutterCell, ...s.gutter }} aria-hidden="true">
                      {line.newLine ?? ''}
                    </span>
                    <span
                      style={{
                        display: 'inline-block',
                        width: 16,
                        textAlign: 'center',
                        color: s.signColor,
                        userSelect: 'none',
                        fontWeight: 700,
                      }}
                      aria-hidden="true"
                    >
                      {s.sign}
                    </span>
                    <span style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word', flex: 1, padding: '0 4px' }}>
                      {line.content}
                    </span>
                  </div>
                );
              })}
            </div>
          ))
        )}
      </div>
    </div>
  );
}
