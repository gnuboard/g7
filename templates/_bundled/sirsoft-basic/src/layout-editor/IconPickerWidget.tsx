/**
 * IconPickerWidget.tsx — sirsoft-basic 템플릿 소유 아이콘 피커 위젯
 *
 * 레이아웃 편집기 [속성] 탭에서 아이콘명 prop(`Icon.name`/`IconButton.iconName` 등)을
 * **검색 가능한 그리드 드롭다운**으로 고른다. 값은 아이콘명 문자열(propValue 로
 * `node.props[propKey]` 기록).
 *
 * **소유 경계**: 아이콘 검색 그리드 UI 는 라이브러리(Font Awesome) 종속
 * 지식이므로 **템플릿이 소유**한다. 코어는 icon-picker 위젯을 제공하지 않으며, 본 위젯을
 * `G7Core.layoutEditor.registerWidget('icon-picker', ...)` 로 등록해 사용한다(코어 예약
 * 접수함이 편집기 로드 전 등록도 큐로 보존 → 편집기 로드 시 flush). 카탈로그(`control.icons`)
 * 도 템플릿 editor-spec 이 공급한다. 본 위젯은 Font Awesome 클래스(`fas fa-{value}`)로
 * 프리뷰를 직접 렌더한다(템플릿이 자기 라이브러리를 알기 때문).
 *
 * 카탈로그 미공급 시 자유입력(text) 폴백.
 */

import React from 'react';

/** 코어 WidgetProps 와 호환되는 최소 형태 — 코어 타입 import 회피(IIFE 번들 분리 안전) */
interface IconCatalogEntry {
  value: string;
  label?: string;
  keywords?: string[];
  group?: string;
  preview?: { html?: string; className?: string };
}
interface IconPickerWidgetProps {
  control: { icons?: unknown; iconColumns?: number; iconSearchPlaceholder?: string } & Record<
    string,
    unknown
  >;
  value: unknown;
  onChange: (value: unknown) => void;
  t: (key: string, params?: Record<string, string | number>) => string;
}

function readCatalog(control: IconPickerWidgetProps['control']): IconCatalogEntry[] {
  const icons = control.icons;
  if (!Array.isArray(icons)) return [];
  return icons.filter(
    (e): e is IconCatalogEntry =>
      !!e && typeof e === 'object' && typeof (e as IconCatalogEntry).value === 'string',
  );
}

function entryLabel(entry: IconCatalogEntry, t: IconPickerWidgetProps['t']): string {
  const label = entry.label;
  if (typeof label === 'string') return label.startsWith('$t:') ? t(label.slice(3)) : label;
  return entry.value;
}

function matchesQuery(entry: IconCatalogEntry, label: string, q: string): boolean {
  if (q === '') return true;
  const needle = q.toLowerCase();
  if (entry.value.toLowerCase().includes(needle)) return true;
  if (label.toLowerCase().includes(needle)) return true;
  return (entry.keywords ?? []).some((k) => k.toLowerCase().includes(needle));
}

/** FA 프리뷰 — preview.html → preview.className → `fas fa-{value}` (FA 직접 렌더) → 텍스트 */
function IconPreview({ entry }: { entry: IconCatalogEntry }): React.ReactElement {
  const preview = entry.preview;
  if (preview?.html) {
    return (
      <span
        className="g7le-icon-preview"
        dangerouslySetInnerHTML={{ __html: preview.html }}
        style={previewBox}
      />
    );
  }
  if (preview?.className) {
    return <span className={`g7le-icon-preview ${preview.className}`} style={previewBox} />;
  }
  // 템플릿은 자기 라이브러리(Font Awesome)를 알므로 클래스 직접 합성.
  return <i className={`fas fa-${entry.value}`} style={previewBox} aria-hidden="true" />;
}

export function IconPickerWidget({
  control,
  value,
  onChange,
  t,
}: IconPickerWidgetProps): React.ReactElement {
  const catalog = readCatalog(control);
  const current = value === undefined || value === null ? '' : String(value);
  const columns = typeof control.iconColumns === 'number' ? control.iconColumns : 6;
  const searchPlaceholderKey = control.iconSearchPlaceholder;
  const searchPlaceholder =
    typeof searchPlaceholderKey === 'string' && searchPlaceholderKey.startsWith('$t:')
      ? t(searchPlaceholderKey.slice(3))
      : t('layout_editor.icon_picker.search_placeholder');

  const [query, setQuery] = React.useState('');

  // 카탈로그 미공급 → 자유입력 폴백.
  if (catalog.length === 0) {
    return (
      <div
        className="g7le-widget g7le-widget--icon-picker"
        data-testid="g7le-widget-icon-picker"
        style={wrap}
      >
        <input
          type="text"
          className="g7le-widget g7le-widget--icon-free"
          data-testid="g7le-widget-icon-picker-free"
          value={current}
          placeholder={t('layout_editor.icon_picker.free_input_hint')}
          onChange={(e) => onChange(e.target.value === '' ? undefined : e.target.value)}
          style={inputStyle}
        />
      </div>
    );
  }

  const labelOf = (e: IconCatalogEntry): string => entryLabel(e, t);
  const filtered = catalog.filter((e) => matchesQuery(e, labelOf(e), query));

  return (
    <div
      className="g7le-widget g7le-widget--icon-picker"
      data-testid="g7le-widget-icon-picker"
      style={wrap}
    >
      <input
        type="text"
        className="g7le-widget g7le-widget--icon-search"
        data-testid="g7le-widget-icon-picker-search"
        value={query}
        placeholder={searchPlaceholder}
        onChange={(e) => setQuery(e.target.value)}
        style={inputStyle}
      />
      <div
        className="g7le-icon-grid"
        data-testid="g7le-widget-icon-picker-grid"
        style={{ ...grid, gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))` }}
      >
        <button
          type="button"
          data-testid="g7le-icon-cell-none"
          title={t('layout_editor.icon_picker.none')}
          onClick={() => onChange(undefined)}
          aria-pressed={current === ''}
          style={current === '' ? cellSelected : cell}
        >
          <span style={previewText}>—</span>
        </button>
        {filtered.map((entry) => {
          const selected = entry.value === current;
          return (
            <button
              key={entry.value}
              type="button"
              data-testid={`g7le-icon-cell-${entry.value}`}
              title={labelOf(entry)}
              aria-pressed={selected}
              onClick={() => onChange(entry.value)}
              style={selected ? cellSelected : cell}
            >
              <IconPreview entry={entry} />
            </button>
          );
        })}
      </div>
      {filtered.length === 0 && (
        <div data-testid="g7le-widget-icon-picker-empty" style={emptyHint}>
          {t('layout_editor.icon_picker.no_results')}
        </div>
      )}
    </div>
  );
}

const wrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6, width: '100%' };
const inputStyle: React.CSSProperties = { padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, width: '100%' };
const grid: React.CSSProperties = { display: 'grid', gap: 4, maxHeight: 220, overflowY: 'auto', padding: 2 };
const cell: React.CSSProperties = { display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: 34, padding: 4, border: '1px solid #e2e8f0', borderRadius: 6, background: '#fff', cursor: 'pointer' };
const cellSelected: React.CSSProperties = { ...cell, border: '2px solid #2563eb', background: '#eff6ff' };
const previewBox: React.CSSProperties = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', fontSize: 16, lineHeight: 1 };
const previewText: React.CSSProperties = { fontSize: 10, color: '#475569', fontFamily: 'monospace', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: '100%' };
const emptyHint: React.CSSProperties = { fontSize: 11, color: '#94a3b8', fontStyle: 'italic', padding: '4px 2px' };
