/**
 * StyleControlWidgets.tsx — 기본 스타일 컨트롤 위젯
 *
 * `segmented` / `slider` / `select` / `toggle` 위젯. 각 위젯은 컨트롤의 옵션/
 * 스케일을 받아 값 입력 UI 를 렌더하고, 변경 시 `onChange(value)` 를 호출한다.
 * 코드 생성(apply 레시피)은 ControlRenderer 가 recipeEngine 으로 처리하므로
 * 본 위젯들은 "값" 만 다룬다.
 *
 * 편집기 코어 위젯은 CSS 라이브러리(Tailwind/Bootstrap) 토큰을 쓰지 않는다 —
 * `g7le-*` BEM + 인라인 스타일만 사용한다(메모리 feedback_layout_editor_no_css_lib_dependency).
 *
 * "기본 / 직접 지정" 토글: 각 위젯은 값 비우기(`기본`) 를 지원한다.
 * 기본 상태는 onChange(undefined) — recipeEngine 이 group 토큰/스타일을 제거한다.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import type { WidgetProps } from '../../spec/widgetRegistry';

/** 컨트롤 옵션 1건 (위젯 표시용 — recipeEngine 의 RecipeOptionSpec 와 호환) */
interface OptionLike {
  value: unknown;
  label?: string;
}

/** 옵션 라벨 해석 — `$t:` 키면 t() 로, 아니면 평문/값 */
function optionLabel(
  opt: OptionLike,
  t: WidgetProps['t'],
): string {
  if (typeof opt.label === 'string') {
    return opt.label.startsWith('$t:') ? t(opt.label.slice(3)) : opt.label;
  }
  return String(opt.value);
}

function readOptions(control: WidgetProps['control']): OptionLike[] {
  return Array.isArray(control.options) ? (control.options as OptionLike[]) : [];
}

/**
 * Segmented — 소수 옵션 택1 (정렬/굵게 등). 버튼 그룹.
 */
export function SegmentedWidget({ control, value, onChange, t }: WidgetProps): React.ReactElement {
  const options = readOptions(control);
  return (
    <div className="g7le-widget g7le-widget--segmented" data-testid="g7le-widget-segmented" style={segmentedWrap}>
      {options.map((opt) => {
        const active = opt.value === value;
        return (
          <button
            key={String(opt.value)}
            type="button"
            data-testid={`g7le-segment-${String(opt.value)}`}
            data-active={active ? 'true' : 'false'}
            onClick={() => onChange(active ? undefined : opt.value)}
            style={{
              ...segmentBtn,
              background: active ? '#2563eb' : '#fff',
              color: active ? '#fff' : '#0f172a',
              borderColor: active ? '#2563eb' : '#cbd5e1',
            }}
          >
            {optionLabel(opt, t)}
          </button>
        );
      })}
    </div>
  );
}

/**
 * Slider — 단계 스케일(크기/여백 등). `control.scale` 의 인덱스를 단계로 쓴다.
 * 값은 scale[index]. `기본`(미적용) 은 슬라이더 좌측 끝 밖의 "사용 안 함" 체크.
 */
export function SliderWidget({ control, value, onChange, t }: WidgetProps): React.ReactElement {
  const scale = Array.isArray((control as { scale?: unknown[] }).scale)
    ? ((control as { scale?: unknown[] }).scale as unknown[])
    : [];
  const currentIndex = scale.findIndex((s) => s === value);
  const enabled = value !== undefined && value !== null;

  return (
    <div className="g7le-widget g7le-widget--slider" data-testid="g7le-widget-slider" style={sliderWrap}>
      <label style={toggleInline}>
        <input
          type="checkbox"
          data-testid="g7le-slider-enabled"
          checked={enabled}
          onChange={(e) => {
            if (e.target.checked) {
              onChange(scale.length > 0 ? scale[Math.max(0, currentIndex === -1 ? 0 : currentIndex)] : undefined);
            } else {
              onChange(undefined);
            }
          }}
        />
        <span style={{ fontSize: 12, color: '#64748b' }}>{t('layout_editor.control.use')}</span>
      </label>
      <input
        type="range"
        data-testid="g7le-slider-range"
        min={0}
        max={Math.max(0, scale.length - 1)}
        step={1}
        disabled={!enabled || scale.length === 0}
        value={currentIndex === -1 ? 0 : currentIndex}
        onChange={(e) => {
          const idx = Number(e.target.value);
          onChange(scale[idx]);
        }}
        style={{ flex: 1 }}
      />
      <span data-testid="g7le-slider-value" style={{ fontSize: 12, color: '#0f172a', minWidth: 48, textAlign: 'right' }}>
        {enabled ? String(value) : t('layout_editor.control.default')}
      </span>
    </div>
  );
}

/**
 * Select — 다수 옵션 택1 (너비/높이 등). 드롭다운. 첫 항목은 `기본`(빈 값).
 */
export function SelectWidget({ control, value, onChange, t }: WidgetProps): React.ReactElement {
  const options = readOptions(control);
  return (
    <select
      className="g7le-widget g7le-widget--select"
      data-testid="g7le-widget-select"
      value={value === undefined || value === null ? '' : String(value)}
      onChange={(e) => {
        const raw = e.target.value;
        if (raw === '') {
          onChange(undefined);
          return;
        }
        const opt = options.find((o) => String(o.value) === raw);
        onChange(opt ? opt.value : raw);
      }}
      style={selectStyle}
    >
      <option value="">{t('layout_editor.control.default')}</option>
      {options.map((opt) => (
        <option key={String(opt.value)} value={String(opt.value)}>
          {optionLabel(opt, t)}
        </option>
      ))}
    </select>
  );
}

/**
 * Toggle — on/off. `control.options` 의 [off, on] 또는 boolean.
 * value 가 off 옵션 value(또는 undefined) 면 off, on 옵션 value 면 on.
 */
export function ToggleWidget({ control, value, onChange }: WidgetProps): React.ReactElement {
  const options = readOptions(control);
  const onValue = options[1]?.value ?? true;
  const offValue = options[0]?.value ?? undefined;
  const isOn = value === onValue;
  return (
    <button
      type="button"
      className="g7le-widget g7le-widget--toggle"
      data-testid="g7le-widget-toggle"
      role="switch"
      aria-checked={isOn}
      onClick={() => onChange(isOn ? offValue : onValue)}
      style={{
        ...toggleTrack,
        background: isOn ? '#2563eb' : '#cbd5e1',
      }}
    >
      <span
        style={{
          ...toggleKnob,
          transform: isOn ? 'translateX(18px)' : 'translateX(0)',
        }}
      />
    </button>
  );
}

/**
 * Dimension — 가로/세로 크기 자유 입력.
 *
 * `select` 위젯은 editor-spec `options` 의 고정 값만 선택 가능해 `320px` 같은 임의
 * 픽셀 입력이 막혔다. 또한 `select` + `options`(per-option apply 없음) + control-level
 * `apply: styleProp` 조합은 recipeEngine 이 옵션 apply 만 보고 control-level apply 를
 * 무시해 **아무 것도 적용되지 않던** 결함이 있었다(항목7 동반 원인).
 *
 * Dimension 위젯은 `options` 를 apply 경로에서 쓰지 않는다 — 값은 자유 문자열
 * (`320px`/`50%`/`24rem`/`auto`)이고, recipeEngine 이 control-level `apply: styleProp`
 * 으로 그 문자열을 그대로 `style.width/height` 에 set 한다. editor-spec 의 `options`
 * 는 **빠른 프리셋 칩**으로만 재사용(클릭 시 입력칸을 그 값으로 채움) — 자유 입력을
 * 막지 않는 단축 수단.
 *
 * "기본" — 입력칸 비우면 `onChange(undefined)` → recipeEngine 이 group 스타일 제거.
 * 잘못된 형식은 무손실 보존(백엔드 최후 방어, dev 경고만 — 본 위젯은 캐스팅 안 함).
 */
export function DimensionWidget({ control, value, onChange, t, freeValueDisabled }: WidgetProps): React.ReactElement {
  const options = readOptions(control);
  const currentStr = value === undefined || value === null ? '' : String(value);
  const [draft, setDraft] = React.useState<string>(currentStr);

  // 외부(리사이즈/역해석)에서 값이 바뀌면 입력칸 동기 — 양방향 동기.
  React.useEffect(() => {
    setDraft(currentStr);
  }, [currentStr]);

  const commit = (raw: string): void => {
    const trimmed = raw.trim();
    if (trimmed === '') {
      onChange(undefined);
      return;
    }
    onChange(trimmed);
  };

  return (
    <div className="g7le-widget g7le-widget--dimension" data-testid="g7le-widget-dimension" style={dimensionWrap}>
      {freeValueDisabled ? (
        // 다크 scope — 자유 px/% 입력은 라이트 전용(`dark:w-[137px]` 빌드 불가). 프리셋 칩만 노출.
        <div data-testid="g7le-dimension-free-disabled" style={dimensionFreeDisabled}>
          {t('layout_editor.property_modal.dark_preset_only')}
        </div>
      ) : (
        <div style={dimensionRow}>
          <input
            type="text"
            data-testid="g7le-dimension-input"
            value={draft}
            placeholder={t('layout_editor.control.dimension.placeholder')}
            onChange={(e) => setDraft(e.target.value)}
            onBlur={(e) => commit(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') {
                e.preventDefault();
                commit((e.target as HTMLInputElement).value);
              }
            }}
            style={dimensionInput}
          />
          <button
            type="button"
            data-testid="g7le-dimension-clear"
            title={t('layout_editor.control.default')}
            onClick={() => {
              setDraft('');
              onChange(undefined);
            }}
            style={dimensionClear}
          >
            {t('layout_editor.control.default')}
          </button>
        </div>
      )}
      {options.length > 0 && (
        <div style={dimensionChips} data-testid="g7le-dimension-chips">
          {options.map((opt) => {
            const v = String(opt.value);
            const active = v === currentStr;
            return (
              <button
                key={v}
                type="button"
                data-testid={`g7le-dimension-chip-${v}`}
                data-active={active ? 'true' : 'false'}
                onClick={() => {
                  setDraft(v);
                  onChange(v);
                }}
                style={{
                  ...dimensionChip,
                  background: active ? '#2563eb' : '#fff',
                  color: active ? '#fff' : '#0f172a',
                  borderColor: active ? '#2563eb' : '#cbd5e1',
                }}
              >
                {optionLabel(opt, t)}
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}

/**
 * Spacing — 여백(안쪽/바깥쪽)의 **측별 독립 크기** 편집 (§항목B 재설계).
 *
 * 종전 `paddingAll`/`marginAll` 은 단일 슬라이더(전 방향 `p-N`)만 지원했고, 1차
 * 수정(방향 세그먼트 택1)도 한 번에 한 방향만 줄 수 있어 상/우/하/좌를 **각각 다르게**
 * 줄 수 없었다.
 *
 * 본 위젯은 두 모드를 제공한다:
 *  - **일괄(link)**: 한 슬라이더가 전 방향 `p-N` 토큰 1개 적용.
 *  - **개별**: 상/우/하/좌 4개 슬라이더가 각각 독립적으로 `pt-N`/`pr-M`/`pb-K`/`pl-J`
 *    토큰을 **공존** 적용. 측별로 다른 값을 줄 수 있다.
 *
 * 값(onChange 대상)은 활성 측 토큰들을 **공백으로 결합한 문자열**이다(예: `"pt-4 pl-8"`).
 * recipeEngine 은 `apply.tokenTemplate:"{value}"` 의 다중 토큰을 펼쳐 적용하고,
 * `groupPrefixes`(p-/px-/py-/pt-/pr-/pb-/pl-)로 이전 여백 토큰을 일괄 제거 후 새 집합을
 * 넣는다. 따라서 측별 토큰이 서로 덮어쓰지 않고 공존한다. CSS 라이브러리 비종속 —
 * prefix 형식은 editor-spec 이 공급.
 */
interface SpacingSide {
  key: 't' | 'r' | 'b' | 'l';
  /** 측별 토큰 prefix (예: padding 상='pt-') */
  infix: string;
  labelKey: string;
}

/** 측 정의 — control.spacingPrefix('p'|'m') 로 prefix 합성 */
function spacingSides(prefix: string): SpacingSide[] {
  return [
    { key: 't', infix: `${prefix}t-`, labelKey: 'layout_editor.control.spacing.top' },
    { key: 'r', infix: `${prefix}r-`, labelKey: 'layout_editor.control.spacing.right' },
    { key: 'b', infix: `${prefix}b-`, labelKey: 'layout_editor.control.spacing.bottom' },
    { key: 'l', infix: `${prefix}l-`, labelKey: 'layout_editor.control.spacing.left' },
  ];
}

/** value(공백 결합 토큰들) → 측별 step index 맵 + 일괄(all) step. */
function parseSpacingValue(
  value: unknown,
  prefix: string,
  sides: SpacingSide[],
  scale: string[],
): { all: number; sides: Record<string, number> } {
  const result: { all: number; sides: Record<string, number> } = { all: -1, sides: {} };
  if (typeof value !== 'string' || value.trim() === '') return result;
  const tokens = value.split(/\s+/).filter((p) => p.length > 0);
  // 측별 토큰 먼저(긴 infix), 그 다음 일괄(p-). px-/py- 는 측별 슬라이더로 분해해 표시:
  // px- → 좌/우 동일, py- → 상/하 동일 (역해석 시 양측에 반영).
  for (const tok of tokens) {
    const side = sides.find((s) => tok.startsWith(s.infix));
    if (side) {
      result.sides[side.key] = scale.indexOf(tok.slice(side.infix.length));
      continue;
    }
    if (tok.startsWith(`${prefix}x-`)) {
      const idx = scale.indexOf(tok.slice(`${prefix}x-`.length));
      result.sides.l = idx;
      result.sides.r = idx;
      continue;
    }
    if (tok.startsWith(`${prefix}y-`)) {
      const idx = scale.indexOf(tok.slice(`${prefix}y-`.length));
      result.sides.t = idx;
      result.sides.b = idx;
      continue;
    }
    if (tok.startsWith(`${prefix}-`)) {
      result.all = scale.indexOf(tok.slice(`${prefix}-`.length));
    }
  }
  return result;
}

export function SpacingWidget({ control, value, onChange, t }: WidgetProps): React.ReactElement {
  const prefix = typeof (control as { spacingPrefix?: unknown }).spacingPrefix === 'string'
    ? ((control as { spacingPrefix?: string }).spacingPrefix as string)
    : 'p';
  const scale = (Array.isArray((control as { scale?: unknown[] }).scale)
    ? ((control as { scale?: unknown[] }).scale as unknown[])
    : []
  ).map((s) => String(s));
  const sides = spacingSides(prefix);
  const parsed = parseSpacingValue(value, prefix, sides, scale);
  const hasSideValues = Object.values(parsed.sides).some((i) => i >= 0);
  const enabled = parsed.all >= 0 || hasSideValues;
  // 모드: 측별 값이 있으면 개별, 아니면 일괄(link). 사용자가 토글로 전환.
  const [perSide, setPerSide] = React.useState<boolean>(hasSideValues);
  React.useEffect(() => {
    // 외부 값이 측별로 바뀌면 개별 모드로 동기(역해석 일관).
    if (hasSideValues) setPerSide(true);
  }, [hasSideValues]);

  const defaultStep = Math.min(4, Math.max(0, scale.length - 1));

  /** 측별 step 맵으로부터 value(공백 결합 토큰) 합성 후 emit. 빈 맵이면 undefined. */
  const emitSides = (sideSteps: Record<string, number>): void => {
    const parts: string[] = [];
    for (const s of sides) {
      const idx = sideSteps[s.key];
      if (idx !== undefined && idx >= 0) parts.push(`${s.infix}${scale[idx]}`);
    }
    onChange(parts.length > 0 ? parts.join(' ') : undefined);
  };

  const emitAll = (idx: number): void => {
    onChange(idx >= 0 ? `${prefix}-${scale[Math.max(0, Math.min(idx, scale.length - 1))]}` : undefined);
  };

  const currentSideSteps = (): Record<string, number> => ({ ...parsed.sides });

  const sliderRow = (
    testid: string,
    labelKey: string,
    idx: number,
    onIdx: (i: number) => void,
    onToggle: (on: boolean) => void,
  ): React.ReactElement => {
    const on = idx >= 0;
    return (
      <div key={testid} style={spacingSideRow} data-testid={testid}>
        <label style={{ ...toggleInline, minWidth: 64 }}>
          <input
            type="checkbox"
            data-testid={`${testid}-enabled`}
            checked={on}
            onChange={(e) => onToggle(e.target.checked)}
          />
          <span style={{ fontSize: 12, color: '#0f172a' }}>{t(labelKey)}</span>
        </label>
        <input
          type="range"
          data-testid={`${testid}-range`}
          min={0}
          max={Math.max(0, scale.length - 1)}
          step={1}
          disabled={!on || scale.length === 0}
          value={idx < 0 ? 0 : idx}
          onChange={(e) => onIdx(Number(e.target.value))}
          style={{ flex: 1 }}
        />
        <span data-testid={`${testid}-value`} style={{ fontSize: 12, color: on ? '#0f172a' : '#94a3b8', minWidth: 28, textAlign: 'right' }}>
          {on ? scale[idx] : '–'}
        </span>
      </div>
    );
  };

  return (
    <div className="g7le-widget g7le-widget--spacing" data-testid="g7le-widget-spacing" style={spacingWrap}>
      {/* 모드 토글 — 일괄 / 개별(측별) */}
      <div style={spacingTopRow}>
        <div style={spacingModeWrap} data-testid="g7le-spacing-mode">
          <button
            type="button"
            data-testid="g7le-spacing-mode-all"
            data-active={!perSide ? 'true' : 'false'}
            onClick={() => {
              setPerSide(false);
              // 개별→일괄 전환 시 기존 측값 폐기, 일괄 미설정으로 둠(사용자가 슬라이더로 설정).
              if (hasSideValues) onChange(undefined);
            }}
            style={{ ...spacingModeBtn, background: !perSide ? '#2563eb' : '#fff', color: !perSide ? '#fff' : '#0f172a', borderColor: !perSide ? '#2563eb' : '#cbd5e1' }}
          >
            {t('layout_editor.control.spacing.mode_all')}
          </button>
          <button
            type="button"
            data-testid="g7le-spacing-mode-sides"
            data-active={perSide ? 'true' : 'false'}
            onClick={() => {
              setPerSide(true);
              // 일괄→개별 전환 시, 기존 일괄값을 4측에 펼쳐 시작점으로 둔다.
              if (parsed.all >= 0) {
                const seeded: Record<string, number> = {};
                for (const s of sides) seeded[s.key] = parsed.all;
                emitSides(seeded);
              }
            }}
            style={{ ...spacingModeBtn, background: perSide ? '#2563eb' : '#fff', color: perSide ? '#fff' : '#0f172a', borderColor: perSide ? '#2563eb' : '#cbd5e1' }}
          >
            {t('layout_editor.control.spacing.mode_sides')}
          </button>
        </div>
        <span data-testid="g7le-spacing-value" style={{ fontSize: 11, color: '#64748b', minWidth: 80, textAlign: 'right' }}>
          {enabled ? String(value) : t('layout_editor.control.default')}
        </span>
      </div>

      {!perSide ? (
        // 일괄 모드 — 단일 슬라이더(p-N)
        <div style={sliderWrap}>
          <label style={{ ...toggleInline, minWidth: 64 }}>
            <input
              type="checkbox"
              data-testid="g7le-spacing-all-enabled"
              checked={parsed.all >= 0}
              onChange={(e) => emitAll(e.target.checked ? defaultStep : -1)}
            />
            <span style={{ fontSize: 12, color: '#64748b' }}>{t('layout_editor.control.spacing.all')}</span>
          </label>
          <input
            type="range"
            data-testid="g7le-spacing-all-range"
            min={0}
            max={Math.max(0, scale.length - 1)}
            step={1}
            disabled={parsed.all < 0 || scale.length === 0}
            value={parsed.all < 0 ? 0 : parsed.all}
            onChange={(e) => emitAll(Number(e.target.value))}
            style={{ flex: 1 }}
          />
          <span data-testid="g7le-spacing-all-value" style={{ fontSize: 12, color: parsed.all >= 0 ? '#0f172a' : '#94a3b8', minWidth: 28, textAlign: 'right' }}>
            {parsed.all >= 0 ? scale[parsed.all] : '–'}
          </span>
        </div>
      ) : (
        // 개별 모드 — 상/우/하/좌 독립 슬라이더
        <div data-testid="g7le-spacing-sides" style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
          {sides.map((s) =>
            sliderRow(
              `g7le-spacing-side-${s.key}`,
              s.labelKey,
              parsed.sides[s.key] ?? -1,
              (i) => {
                const next = currentSideSteps();
                next[s.key] = i;
                emitSides(next);
              },
              (on) => {
                const next = currentSideSteps();
                if (on) next[s.key] = parsed.sides[s.key] >= 0 ? parsed.sides[s.key] : defaultStep;
                else delete next[s.key];
                emitSides(next);
              },
            ),
          )}
        </div>
      )}
    </div>
  );
}

const spacingWrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6, width: '100%' };
const spacingTopRow: React.CSSProperties = { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8 };
const spacingModeWrap: React.CSSProperties = { display: 'inline-flex', gap: 0, border: '1px solid #cbd5e1', borderRadius: 6, overflow: 'hidden' };
const spacingModeBtn: React.CSSProperties = { padding: '3px 10px', fontSize: 11, border: 'none', borderRight: '1px solid #e2e8f0', cursor: 'pointer' };
const spacingSideRow: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 8, width: '100%' };
const segmentedWrap: React.CSSProperties = { display: 'inline-flex', gap: 0, border: '1px solid #cbd5e1', borderRadius: 6, overflow: 'hidden' };
const dimensionWrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6, width: '100%' };
const dimensionRow: React.CSSProperties = { display: 'flex', gap: 6, alignItems: 'center' };
const dimensionInput: React.CSSProperties = { flex: 1, padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, minWidth: 80 };
const dimensionClear: React.CSSProperties = { padding: '4px 8px', fontSize: 11, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#64748b', cursor: 'pointer', whiteSpace: 'nowrap' };
const dimensionChips: React.CSSProperties = { display: 'flex', flexWrap: 'wrap', gap: 4 };
const dimensionFreeDisabled: React.CSSProperties = { fontSize: 11, color: '#94a3b8', fontStyle: 'italic', padding: '2px 0' };
const dimensionChip: React.CSSProperties = { padding: '3px 9px', fontSize: 11, border: '1px solid #cbd5e1', borderRadius: 999, background: '#fff', cursor: 'pointer' };
const segmentBtn: React.CSSProperties = { padding: '4px 10px', fontSize: 12, border: 'none', borderRight: '1px solid #e2e8f0', cursor: 'pointer' };
const sliderWrap: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 8, width: '100%' };
const toggleInline: React.CSSProperties = { display: 'inline-flex', alignItems: 'center', gap: 4 };
const selectStyle: React.CSSProperties = { padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', minWidth: 120 };
const toggleTrack: React.CSSProperties = { position: 'relative', width: 38, height: 20, borderRadius: 10, border: 'none', cursor: 'pointer', padding: 2, display: 'inline-flex', alignItems: 'center', transition: 'background 120ms ease' };
const toggleKnob: React.CSSProperties = { display: 'block', width: 16, height: 16, borderRadius: 8, background: '#fff', transition: 'transform 120ms ease' };
