/**
 * InlineTextToolbar.tsx — 인라인 편집 서식 툴바
 *
 * 텍스트를 인라인 편집할 때 텍스트 박스 근처에 떠 있는 서식 툴바. G7 은 HTML 이 아니라
 * **컴포넌트 단위**이므로 툴바 버튼은 그 컴포넌트의 props/style 로 표현 가능한 서식만
 * (굵기/기울임/밑줄/정렬/크기/색상) 제공한다.
 *
 * 노출 규칙:
 *  - 어떤 버튼이 보일지는 그 컴포넌트의 `componentCapabilities[name].styleControls` 에
 *    선언된 텍스트 서식 컨트롤만으로 결정된다(스펙 기반). 미선언 컨트롤은 부재.
 *  - 목록·표·이미지·인용 등(= 요소 추가의 영역)은 노출하지 않는다.
 *  - 서식은 텍스트 컴포넌트 **전체**에 적용된다(substring 부분 서식 미지원).
 *  - 속성 모달 스타일 탭과 **같은 컨트롤·같은 레시피 엔진**(getControl/applyRecipe/
 *    reverseResolve)을 호출한다 — 툴바는 빠른 접근 표면일 뿐(컨트롤 동일성).
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만. 모든 문자열은 `$t:layout_editor.*`.
 *
 * @since engine-v1.50.0
 */

import React, { useState } from 'react';
import type { EditorSpec } from '../spec/specTypes';
import type { EditorNode } from '../utils/layoutTreeUtils';
import type { OverlayBox } from '../utils/overlayGeometry';
import { getComponentCapability, getControl } from '../spec/editorSpecLoader';
import { applyRecipe, reverseResolve } from '../spec/recipeEngine';
import { BASE_SCOPE, type StyleScope } from '../spec/styleScope';

/**
 * 서식 툴바에 노출 가능한 텍스트 서식 컨트롤 화이트리스트(6종: 굵기/기울임/밑줄/
 * 정렬/크기/색상). 이 목록에 있고 + 컴포넌트 styleControls 에도 선언된 컨트롤만 버튼으로 노출.
 *
 * 컨트롤 **키 명칭**은 템플릿 editor-spec 이 정한다 — 계획서 가 예시로 든 명칭
 * (`fontStyle`/`textDecoration`)과 실제 번들 스펙 키(`fontItalic`/`textUnderline`)가 다를 수
 * 있으므로 동의어를 모두 화이트리스트에 둔다(실제 스펙은 fontItalic/textUnderline
 * 사용 → fontStyle/textDecoration 으로 찾으면 기울임·밑줄 버튼이 영영 안 보였다). 굵기는
 * fontWeight 외에 별도 fontBold 토글을 쓰는 템플릿도 지원.
 */
export const INLINE_TOOLBAR_CONTROL_KEYS = [
  'fontWeight',
  'fontBold',
  'fontStyle',
  'fontItalic',
  'textDecoration',
  'textUnderline',
  'textAlign',
  'fontSize',
  'textColor',
] as const;

export type InlineToolbarControlKey = (typeof INLINE_TOOLBAR_CONTROL_KEYS)[number];

/** 컨트롤별 짧은 버튼 라벨/아이콘 — 인라인 스타일만이므로 텍스트 글리프 사용. */
const CONTROL_GLYPH: Record<InlineToolbarControlKey, string> = {
  fontWeight: 'B',
  fontBold: 'B',
  fontStyle: 'I',
  fontItalic: 'I',
  textDecoration: 'U',
  textUnderline: 'U',
  textAlign: '≡',
  fontSize: 'T',
  textColor: 'A',
};

/**
 * 같은 서식의 동의어 컨트롤이 둘 다 styleControls 에 있으면 하나만 노출(중복 버튼 방지).
 * 우선순위: weight 는 fontWeight > fontBold, italic 은 fontItalic > fontStyle,
 * underline 은 textUnderline > textDecoration. (실제 번들 스펙 키 우선.)
 */
const SYNONYM_GROUPS: InlineToolbarControlKey[][] = [
  ['fontWeight', 'fontBold'],
  ['fontItalic', 'fontStyle'],
  ['textUnderline', 'textDecoration'],
];

export interface InlineTextToolbarProps {
  /** 편집 중 노드 */
  node: EditorNode;
  /** 병합 editor-spec — 컨트롤/역량 조회 */
  spec: EditorSpec | null;
  /** 다국어 해석 t */
  t: (key: string, params?: Record<string, string | number>) => string;
  /**
   * 컨트롤 적용 — 호출자가 **patch 시점의 최신 노드**에 applyFn 을 적용해 PATCH_LAYOUT 한다.
   * 툴바가 미리 `applyRecipe(node,...)` 해 넘기면 그 `node` 가 직전 patch 를 반영 못한
   * stale 사본이라 연속 토글이 깨진다. applyFn 으로
   * 넘겨 호출자가 fresh 노드에 적용하게 한다.
   */
  onApplyControl: (controlKey: string, applyFn: (freshNode: EditorNode) => EditorNode) => void;
  /** 떠 있을 박스(frame-local) — 텍스트 위쪽에 배치 */
  box: OverlayBox;
  /** 활성 StyleScope (색 모드 × 디바이스) — 속성 모달과 동일 scope 사용 */
  scope?: StyleScope;
}

export function InlineTextToolbar({
  node,
  spec,
  t,
  onApplyControl,
  box,
  scope = BASE_SCOPE,
}: InlineTextToolbarProps): React.ReactElement | null {
  const componentName = typeof node.name === 'string' ? node.name : '';
  const capability = getComponentCapability(spec, componentName);
  const styleControls: string[] = Array.isArray(capability?.styleControls)
    ? (capability!.styleControls as string[])
    : [];

  // 화이트리스트 ∩ 컴포넌트 styleControls — 둘 다 만족하는 컨트롤만 노출(스펙 기반).
  let visibleKeys = INLINE_TOOLBAR_CONTROL_KEYS.filter(
    (key) => styleControls.includes(key) && getControl(spec, key) !== null,
  );
  // 동의어 중복 제거 — 같은 서식(굵기/기울임/밑줄)에 두 키가 다 선언되면 우선 키만 남긴다.
  for (const group of SYNONYM_GROUPS) {
    const present = group.filter((k) => visibleKeys.includes(k));
    if (present.length > 1) {
      const keep = present[0]; // 그룹 첫 원소가 우선
      visibleKeys = visibleKeys.filter((k) => !group.includes(k) || k === keep);
    }
  }

  // 노출할 서식 컨트롤이 없으면 툴바 자체 미렌더(부재).
  if (visibleKeys.length === 0) return null;

  return (
    <div
      className="g7le-inline-toolbar"
      data-testid="g7le-inline-toolbar"
      role="toolbar"
      aria-label={t('layout_editor.inline_edit.toolbar_label')}
      style={{
        position: 'absolute',
        left: box.left,
        // 텍스트 박스 위쪽에 띄운다(겹침 회피). 공간이 없으면 음수 top 으로 위로.
        top: box.top - 40,
        display: 'inline-flex',
        gap: 2,
        padding: 4,
        background: '#0f172a',
        borderRadius: 8,
        boxShadow: '0 4px 12px rgba(15, 23, 42, 0.35)',
        pointerEvents: 'auto',
        zIndex: 10001,
      }}
    >
      {visibleKeys.map((key) => (
        <ToolbarFormatButton
          key={key}
          controlKey={key}
          node={node}
          spec={spec}
          t={t}
          scope={scope}
          onApplyControl={onApplyControl}
        />
      ))}
    </div>
  );
}

interface ToolbarFormatButtonProps {
  controlKey: InlineToolbarControlKey;
  node: EditorNode;
  spec: EditorSpec | null;
  t: (key: string, params?: Record<string, string | number>) => string;
  scope: StyleScope;
  onApplyControl: (controlKey: string, applyFn: (freshNode: EditorNode) => EditorNode) => void;
}

/**
 * 서식 버튼 1개. 택1(toggle/segmented) 컨트롤은 현재값을 reverseResolve 로 읽어
 * 토글한다. 자유값 컨트롤(fontSize/textColor)은 클릭 시 프롬프트로 값을 받는다 —
 * 정밀 편집은 속성 모달 스타일 탭으로 위임(툴바는 빠른 토글 표면).
 */
/**
 * 버튼별 토글 의미. 서식 버튼은 "특정 서식 켜기/끄기" 가 직관이다.
 * 그러나 editor-spec 컨트롤(`fontWeight`)은 normal/semibold/bold **3지 세그먼트**라, 단순
 * 옵션 순환은 "B 클릭 → normal" 처럼 엉뚱하게 동작하고 해제도 안 됐다. 각 버튼이 가리키는
 * **목표 옵션**을 토큰 키워드로 식별해(bold/italic/underline) 그 값만 켜고/끄도록 한다.
 * 정렬은 본질적으로 다지(좌/중/우)라 순환을 유지한다.
 */
const BUTTON_TOGGLE_KEYWORD: Partial<Record<InlineToolbarControlKey, string>> = {
  fontWeight: 'bold',
  fontBold: 'bold',
  fontStyle: 'italic',
  fontItalic: 'italic',
  textDecoration: 'underline',
  textUnderline: 'underline',
};

/**
 * options 중 keyword(예: 'bold')에 해당하는 옵션의 value 를 찾는다.
 *
 * 토큰 경계 매칭 — `bold` 가 `font-semibold` 에 부분 일치하지 않도록 토큰을 `-`/공백으로
 * 쪼개 마지막 세그먼트(또는 토큰 전체)가 keyword 와 정확히 일치하는 옵션만 고른다
 *
 */
function findTargetOptionValue(
  options: Array<{ value?: unknown; apply?: { tokens?: string[] } }>,
  keyword: string,
): unknown {
  const matchesToken = (tok: string): boolean => {
    const seg = tok.split(/[-\s]/).pop() ?? tok;
    return seg === keyword || tok === keyword;
  };
  const opt = options.find((o) => {
    const v = typeof o.value === 'string' ? o.value : '';
    const tokens = Array.isArray(o.apply?.tokens) ? o.apply!.tokens! : [];
    return matchesToken(v) || tokens.some(matchesToken);
  });
  return opt?.value;
}

function ToolbarFormatButton({
  controlKey,
  node,
  spec,
  t,
  scope,
  onApplyControl,
}: ToolbarFormatButtonProps): React.ReactElement | null {
  const [open, setOpen] = useState(false);
  const control = getControl(spec, controlKey);
  if (!control) return null;

  const options = (control as {
    options?: Array<{ value?: unknown; apply?: { tokens?: string[] }; label?: string; swatch?: string }>;
  }).options;
  const isToggleish = Array.isArray(options);
  const resolution = reverseResolve(node, control, scope);

  // 이 버튼이 토글하는 목표 값(bold/italic/underline). 그 외 옵션 컨트롤은 선택(popover).
  const toggleKeyword = BUTTON_TOGGLE_KEYWORD[controlKey];
  const targetValue =
    toggleKeyword && Array.isArray(options) ? findTargetOptionValue(options, toggleKeyword) : undefined;

  // 선택형(popover) 버튼 — 토글 키워드가 없고 옵션이 2개 초과(정렬/크기/색 등) 또는 자유값 컨트롤.
  // 크기·색은 단순 토글이 아니라 목록에서 **선택**한다.
  const isSelect = !toggleKeyword;

  // active 판정 — 토글 버튼은 현재값이 목표값과 같으면 on, 선택형은 비-기본값이면 on.
  const active =
    targetValue !== undefined
      ? resolution.value === targetValue
      : isToggleish && resolution.matched && resolution.value !== undefined && resolution.value !== '';

  const label = t(`layout_editor.inline_edit.format.${controlKey}`);

  // 옵션 라벨 해석 — `$t:` 키면 t(), 평문이면 그대로, 없으면 value 표기.
  const optLabel = (opt: { value?: unknown; label?: string }): string => {
    if (typeof opt.label === 'string') return opt.label.startsWith('$t:') ? t(opt.label.slice(3)) : opt.label;
    return String(opt.value ?? '');
  };

  // 토글 적용 — 굵게/기울임/밑줄. 현재 목표값이면 해제, 아니면 켜기.
  const applyToggle = (): void => {
    onApplyControl(controlKey, (freshNode) => {
      const cur = reverseResolve(freshNode, control, scope);
      const tv = Array.isArray(options) ? findTargetOptionValue(options, toggleKeyword!) : undefined;
      const nextValue = tv !== undefined && cur.value === tv ? undefined : tv;
      return applyRecipe(freshNode, control, nextValue, scope);
    });
  };

  // 선택 적용 — popover 에서 옵션 클릭. 현재값과 같으면 해제(toggle off), 아니면 그 값 적용.
  const applySelect = (value: unknown): void => {
    onApplyControl(controlKey, (freshNode) => {
      const cur = reverseResolve(freshNode, control, scope);
      const nextValue = cur.value === value ? undefined : value;
      return applyRecipe(freshNode, control, nextValue, scope);
    });
    setOpen(false);
  };

  const handleClick = (e: React.MouseEvent): void => {
    e.preventDefault();
    e.stopPropagation();
    if (!isSelect && Array.isArray(options)) {
      applyToggle();
      return;
    }
    // 선택형 — popover 토글. 옵션이 없는 자유값 컨트롤(색 HEX 등)도 popover 안에서 처리.
    setOpen((v) => !v);
  };

  return (
    <div style={{ position: 'relative', display: 'inline-flex' }}>
    <button
      type="button"
      data-testid={`g7le-inline-format-${controlKey}`}
      data-active={active ? 'true' : 'false'}
      aria-label={label}
      aria-pressed={isToggleish ? active : undefined}
      title={label}
      // mousedown 에서 preventDefault — contentEditable blur 로 편집이 닫히기 전에 적용.
      onMouseDown={(e) => e.preventDefault()}
      onClick={handleClick}
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 5,
        height: 28,
        padding: '0 9px',
        fontSize: 12,
        border: 'none',
        borderRadius: 5,
        cursor: 'pointer',
        background: active ? '#2563eb' : 'transparent',
        color: '#ffffff',
        whiteSpace: 'nowrap',
      }}
    >
      {/* 글리프는 해당 서식 모양으로(B=굵게, I=기울임, U=밑줄), 라벨은 항상 평문으로 —
 버튼만 봐서 역할을 알도록 글리프 + 라벨 함께 표시. */}
      <span
        aria-hidden="true"
        style={{
          fontWeight: controlKey === 'fontWeight' || controlKey === 'fontBold' ? 700 : 600,
          fontStyle: controlKey === 'fontStyle' || controlKey === 'fontItalic' ? 'italic' : 'normal',
          textDecoration:
            controlKey === 'textDecoration' || controlKey === 'textUnderline' ? 'underline' : 'none',
        }}
      >
        {CONTROL_GLYPH[controlKey]}
      </span>
      <span>{label}</span>
      {/* 선택형(정렬/크기/색)은 드롭다운임을 ▾ 로 표시. */}
      {isSelect && <span aria-hidden="true" style={{ fontSize: 9, opacity: 0.8 }}>▾</span>}
    </button>

      {/* 선택 popover — 정렬/크기/색 옵션을 목록으로 띄워 고른다(단순 토글/프롬프트 아님). */}
      {isSelect && open && (
        <div
          data-testid={`g7le-inline-popover-${controlKey}`}
          role="listbox"
          // mousedown preventDefault — contentEditable blur 방지(편집 유지).
          onMouseDown={(e) => e.preventDefault()}
          style={{
            position: 'absolute',
            top: 32,
            left: 0,
            minWidth: 140,
            maxHeight: 240,
            overflowY: 'auto',
            background: '#0f172a',
            border: '1px solid #334155',
            borderRadius: 8,
            boxShadow: '0 8px 24px rgba(15,23,42,0.45)',
            padding: 4,
            zIndex: 10002,
          }}
        >
          {Array.isArray(options) && options.length > 0 ? (
            options.map((opt, i) => {
              const selected = resolution.value === opt.value;
              return (
                <button
                  key={String(opt.value ?? i)}
                  type="button"
                  role="option"
                  aria-selected={selected}
                  data-testid={`g7le-inline-option-${controlKey}-${String(opt.value ?? i)}`}
                  onMouseDown={(e) => e.preventDefault()}
                  onClick={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    applySelect(opt.value);
                  }}
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 8,
                    width: '100%',
                    padding: '6px 8px',
                    fontSize: 12,
                    textAlign: 'left',
                    border: 'none',
                    borderRadius: 5,
                    cursor: 'pointer',
                    background: selected ? '#2563eb' : 'transparent',
                    color: '#ffffff',
                  }}
                >
                  {/* 색 옵션이면 swatch 미리보기 */}
                  {typeof opt.swatch === 'string' && (
                    <span aria-hidden="true" style={{ width: 14, height: 14, borderRadius: 3, background: opt.swatch, border: '1px solid rgba(255,255,255,0.3)' }} />
                  )}
                  <span>{optLabel(opt)}</span>
                  {selected && <span aria-hidden="true" style={{ marginLeft: 'auto' }}>✓</span>}
                </button>
              );
            })
          ) : (
            // 옵션 없는 자유값 컨트롤(예: 색 HEX) — 직접 입력칸.
            <FreeValueInput
              testid={`g7le-inline-freeinput-${controlKey}`}
              placeholder={t(`layout_editor.inline_edit.prompt.${controlKey}`)}
              initial={typeof resolution.value === 'string' ? resolution.value : ''}
              onSubmit={(v) => {
                onApplyControl(controlKey, (freshNode) => applyRecipe(freshNode, control, v, scope));
                setOpen(false);
              }}
            />
          )}
        </div>
      )}
    </div>
  );
}

/** 옵션 없는 선택형 컨트롤(색 HEX 등)을 위한 작은 입력칸. Enter 로 적용. */
function FreeValueInput({
  testid,
  placeholder,
  initial,
  onSubmit,
}: {
  testid: string;
  placeholder: string;
  initial: string;
  onSubmit: (value: string) => void;
}): React.ReactElement {
  const [val, setVal] = useState(initial);
  return (
    <input
      type="text"
      data-testid={testid}
      value={val}
      placeholder={placeholder}
      autoFocus
      onMouseDown={(e) => e.stopPropagation()}
      onChange={(e) => setVal(e.currentTarget.value)}
      onKeyDown={(e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          onSubmit(val.trim());
        }
      }}
      style={{
        width: 130,
        padding: '6px 8px',
        fontSize: 12,
        border: '1px solid #334155',
        borderRadius: 5,
        background: '#1e293b',
        color: '#ffffff',
      }}
    />
  );
}
