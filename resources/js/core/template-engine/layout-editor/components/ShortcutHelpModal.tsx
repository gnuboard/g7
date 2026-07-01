/**
 * ShortcutHelpModal.tsx — 레이아웃 편집기 단축키 맵 모달
 *
 * `editorShortcuts.ts`(SSoT)를 읽어 그룹별 단축키 목록을 표(액션 라벨 ↔ 키 표기)로
 * 보여 준다. 키맵에 단축키를 추가/변경하면 본 모달이 자동 반영된다(별도 동기 불요).
 * 키 표기는 플랫폼(Mac ⌘ / Win·Linux Ctrl)에 맞춘다.
 *
 * 편집기 코어 컴포넌트 — `g7le-*` BEM + 인라인 스타일만(CSS 라이브러리 비종속).
 *
 * @since engine-v1.50.0
 */

import React, { useMemo } from 'react';
import {
  EDITOR_SHORTCUTS,
  SHORTCUT_GROUP_ORDER,
  SHORTCUT_GROUP_LABEL,
  formatCombo,
  type ShortcutGroup,
} from '../spec/editorShortcuts';

export interface ShortcutHelpModalProps {
  t: (key: string, params?: Record<string, string | number>) => string;
  onClose: () => void;
  /** 플랫폼 판정 주입(테스트 결정성). 미공급 시 navigator 로 감지. */
  isMac?: boolean;
}

function detectMac(): boolean {
  if (typeof navigator === 'undefined') return false;
  return /mac|iphone|ipad|ipod/i.test(navigator.platform || navigator.userAgent || '');
}

export function ShortcutHelpModal({ t, onClose, isMac }: ShortcutHelpModalProps): React.ReactElement {
  const mac = isMac ?? detectMac();

  const byGroup = useMemo(() => {
    const m = new Map<ShortcutGroup, typeof EDITOR_SHORTCUTS>();
    for (const s of EDITOR_SHORTCUTS) {
      const arr = m.get(s.group) ?? [];
      arr.push(s);
      m.set(s.group, arr);
    }
    return m;
  }, []);

  return (
    <div className="g7le-shortcut-help" data-testid="g7le-shortcut-help" style={wrap}>
      <div style={header}>
        <span style={title}>{t('layout_editor.shortcuts.title')}</span>
        <button type="button" data-testid="g7le-shortcut-help-close" onClick={onClose} aria-label={t('layout_editor.shortcuts.close')} style={closeBtn}>
          ✕
        </button>
      </div>
      <div style={body}>
        {SHORTCUT_GROUP_ORDER.map((g) => {
          const items = byGroup.get(g);
          if (!items || items.length === 0) return null;
          return (
            <div key={g} style={groupBox} data-testid={`g7le-shortcut-group-${g}`}>
              <div style={groupLabel}>{t(SHORTCUT_GROUP_LABEL[g])}</div>
              <table style={tableStyle}>
                <tbody>
                  {items.map((s) => (
                    <tr key={s.id} data-testid={`g7le-shortcut-row-${s.id}`}>
                      <td style={actionCell}>{t(s.labelKey)}</td>
                      <td style={keyCell}>
                        {s.combos.map((c, i) => (
                          <React.Fragment key={i}>
                            {i > 0 && <span style={orSep}>{t('layout_editor.shortcuts.or')}</span>}
                            <kbd style={kbd}>{formatCombo(c, mac)}</kbd>
                          </React.Fragment>
                        ))}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          );
        })}
      </div>
      <div style={footnote}>{t('layout_editor.shortcuts.footnote')}</div>
    </div>
  );
}

// 일관 여백 — 모달 프레임(g7le-modal)은 padding 0 이므로 콘텐츠가 사방 동일 padding(20)을
// 제공한다. 헤더/본문/푸터는 같은 좌우 정렬선 안에서 세로 간격만 둔다.
const PAD = 20;
// width:100% 로 모달 프레임을 꽉 채워 좌우 여백을 대칭으로 만든다(모달 width 와 콘텐츠
// maxWidth 불일치로 우측 빈 공간이 생기던 "여백 불일치" 수정).
const wrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', padding: PAD, gap: 16, width: '100%', boxSizing: 'border-box' };
const header: React.CSSProperties = { display: 'flex', alignItems: 'center', justifyContent: 'space-between', paddingBottom: 12, borderBottom: '1px solid #e2e8f0' };
const title: React.CSSProperties = { fontSize: 15, fontWeight: 700, color: '#0f172a' };
const closeBtn: React.CSSProperties = { width: 28, height: 28, border: 'none', background: 'transparent', color: '#64748b', cursor: 'pointer', fontSize: 14, borderRadius: 6 };
// 2열 그리드 — 행/열 간격을 동일(20)하게, 각 그룹 카드 내부도 균일.
const body: React.CSSProperties = { display: 'grid', gridTemplateColumns: '1fr 1fr', columnGap: 28, rowGap: 18, alignItems: 'start' };
const groupBox: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6 };
const groupLabel: React.CSSProperties = { fontSize: 12, fontWeight: 700, color: '#2563eb' };
const tableStyle: React.CSSProperties = { width: '100%', borderCollapse: 'collapse' };
const actionCell: React.CSSProperties = { padding: '4px 0', fontSize: 12.5, color: '#334155', textAlign: 'left', whiteSpace: 'nowrap', verticalAlign: 'middle' };
const keyCell: React.CSSProperties = { padding: '4px 0', paddingLeft: 12, textAlign: 'right', verticalAlign: 'middle' };
const kbd: React.CSSProperties = { display: 'inline-block', padding: '1px 6px', fontSize: 11, fontFamily: 'ui-monospace, monospace', color: '#0f172a', background: '#f1f5f9', border: '1px solid #cbd5e1', borderBottomWidth: 2, borderRadius: 5, whiteSpace: 'nowrap' };
const orSep: React.CSSProperties = { fontSize: 11, color: '#94a3b8', margin: '0 4px' };
const footnote: React.CSSProperties = { fontSize: 11, color: '#94a3b8', paddingTop: 12, borderTop: '1px solid #f1f5f9' };
