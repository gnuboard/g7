/**
 * InlineTextEditor.tsx — 더블클릭 인라인 텍스트 편집 오버레이
 *
 * 선택된 텍스트 노드 박스 위에 `contentEditable` 오버레이를 띄워 그 자리에서 텍스트를
 * 편집한다. Enter/blur 로 확정(onCommit), Escape 로 취소(onCancel). 캔버스 frame-local
 * 좌표(OverlayBox)에 절대 배치되어 원래 텍스트와 같은 위치에 겹쳐 보인다.
 *
 * 본 컴포넌트는 "편집 UI" 만 담당한다 — 확정 시 키 생성/수정 로직은 useInlineEdit 가
 * 수행하며, 본 컴포넌트는 onCommit(newValue) 콜백만 호출한다(책임 분리).
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만(CSS 라이브러리 비종속).
 * 모든 사용자 대면 문자열은 `$t:layout_editor.*` 키.
 *
 * @since engine-v1.50.0
 */

import React, { useEffect, useRef, useState } from 'react';
import type { OverlayBox } from '../utils/overlayGeometry';

export interface InlineTextEditorProps {
  /** 편집 대상 박스 (frame-local 좌표) — 오버레이 배치 */
  box: OverlayBox;
  /** 편집 시작값 (평문 또는 커스텀 키의 현재 로케일 값) */
  initialValue: string;
  /** 다국어 키 노드인지 — 배지 표시 + 확정 시 안내 분기 */
  isCustomKey: boolean;
  /** 다국어 해석 t */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 확정(Enter/blur) — 변경된 값 전달 */
  onCommit: (value: string) => void;
  /** 취소(Escape) */
  onCancel: () => void;
  /**
   * 편집 중 노드의 className — 서식 툴바로 바뀐 스타일(굵기/정렬/색 등)을 편집 오버레이에
   * 그대로 미러링해, 사용자가 서식 변경을 **편집 중에도 즉시 시각 확인**하게 한다. 오버레이가
   * 노드를 덮어 가리므로 미러링이 없으면 서식이 적용돼도 안 보여 "적용 안 됨"으로 오인된다
   * 토큰형 className 은 캔버스
   * 프레임에 주입된 템플릿 CSS 가 해석한다(편집 오버레이도 같은 프레임 안).
   */
  mirrorClassName?: string;
  /** 편집 중 노드의 인라인 style — className 과 동일 목적(styleProp 컨트롤 미러링). */
  mirrorStyle?: React.CSSProperties;
  /**
   * 편집 대상 노드의 캔버스 내 실제 글자색(computed, 다크 컨텍스트 반영) — 대비 배경/글자색
   * 판정의 결정적 소스. 오버레이는 다크 컨텍스트(`.g7le-preview-dark`) 밖에 마운트되어
   * 자체 computed 로는 `dark:` 토큰 미발동·oklch 등으로 색이 어긋난다. 대상 노드(다크 컨텍스트 안)의
   * 색을 EditorCanvasOverlay 가 측정해 넘기면 브라우저가 정규화한 값을 받아 ① 밝기로 대비 배경을
   * 정확히 정하고 ② 캔버스와 같은 색으로 글자를 표시한다.
   */
  nodeEffectiveColor?: string;
  /**
   * 힌트 배지 클릭 핸들러 — 사용자가 "텍스트만 고치면 끝"으로 오해하지 않도록,
   * 편집 힌트 배지를 눌러 이 노드의 [번역] 탭(전체 로케일 일괄 편집)으로 바로 진입하게 한다.
   * 전달되면 배지가 버튼처럼 클릭 가능해진다(미전달 시 종전대로 정적 안내).
   */
  onOpenTranslations?: () => void;
}

/**
 * 편집 오버레이의 글자색을 결정한다.
 *
 * 색 출처 우선순위:
 *  - `nodeEffectiveColor`(EditorCanvasOverlay 가 측정한 대상 노드의 캔버스 내 computed 색) 가
 *    있으면 → 그 값을 명시 글자색으로 적용한다. 오버레이가 다크 컨텍스트(`.g7le-preview-dark`)
 *  밖이라 className 의 `dark:` 토큰이 발동하지 않아 색이 어긋나던 결함(다크 모드
 *    흰 글자 노드)을 막고, 캔버스와 동일한 색으로 보이게 한다.
 *  - `mirrorStyle.color`(styleProp 색 컨트롤) 가 있으면 → 그 값(이미 mirrorStyle 스프레드로 적용됨)을
 *    유지하기 위해 폴백을 반환하지 않는다.
 *  - `mirrorClassName` 이 있으면 → 그 className 의 색 토큰을 프레임 템플릿 CSS 가 해석하도록 인라인
 *    color 를 비운다. (라이브러리 중립 — 특정 클래스 토큰 어휘를 코어가 알지 못하므로, className 이
 *    존재하면 색 결정을 template CSS 에 위임한다. 인라인 color 를 깔면 className 색이 인라인 우선순위에
 *    가려 편집 중 색 변경이 안 보인다.)
 *  - 모두 없을 때만 가독성 폴백색(`#0f172a`)을 적용한다.
 *
 * @param nodeEffectiveColor 대상 노드의 캔버스 내 실제 글자색(computed)
 * @param mirrorClassName 편집 대상 노드의 className 미러
 * @param mirrorStyle 편집 대상 노드의 인라인 style 미러
 * @return color 를 포함/미포함하는 부분 스타일 객체
 */
function colorFallbackStyle(
  nodeEffectiveColor: string | undefined,
  mirrorClassName?: string,
  mirrorStyle?: React.CSSProperties,
): React.CSSProperties {
  if (typeof nodeEffectiveColor === 'string' && nodeEffectiveColor.trim().length > 0) {
    return { color: nodeEffectiveColor };
  }
  const hasStyleColor = typeof mirrorStyle?.color === 'string' && mirrorStyle.color.length > 0;
  const hasClassName = typeof mirrorClassName === 'string' && mirrorClassName.trim().length > 0;
  if (hasStyleColor || hasClassName) return {};
  return { color: '#0f172a' };
}

/** 오버레이 base 배경(밝은 글자색이 아닐 때) */
const OVERLAY_BG_LIGHT = '#ffffff';
/** 오버레이 대비 배경(밝은 글자색일 때 — 흰 글자 묻힘 방지) */
const OVERLAY_BG_DARK = '#0f172a';

/**
 * `rgb()`/`rgba()`/`#rrggbb`/`#rgb` 색 문자열을 [r,g,b] 로 파싱한다. 파싱 불가 시 null.
 *
 * @param color CSS 색 문자열
 * @return [r,g,b] (0~255) 또는 null
 */
function parseRgb(color: string): [number, number, number] | null {
  const c = color.trim();
  const rgbMatch = c.match(/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)/i);
  if (rgbMatch) {
    return [Number(rgbMatch[1]), Number(rgbMatch[2]), Number(rgbMatch[3])];
  }
  const hex = c.replace(/^#/, '');
  if (/^[0-9a-f]{3}$/i.test(hex)) {
    return [
      parseInt(hex[0] + hex[0], 16),
      parseInt(hex[1] + hex[1], 16),
      parseInt(hex[2] + hex[2], 16),
    ];
  }
  if (/^[0-9a-f]{6}$/i.test(hex)) {
    return [
      parseInt(hex.slice(0, 2), 16),
      parseInt(hex.slice(2, 4), 16),
      parseInt(hex.slice(4, 6), 16),
    ];
  }
  return null;
}

/**
 * 글자색이 "밝은 색"(흰 배경 위에서 묻히는 색)인지 판정한다.
 *
 * ITU-R BT.601 휘도 근사식으로 밝기를 계산해 임계값(180)보다 밝으면 true. 파싱 불가/투명/
 * 빈값은 false(밝지 않음 — 흰 배경 유지).
 *
 * @param color CSS 색 문자열
 * @return 밝은 색이면 true
 */
function isLightColor(color: string | null | undefined): boolean {
  if (!color) return false;
  if (/transparent/i.test(color)) return false;
  // oklch(L C H ...) — Tailwind v4 색 함수. L(첫 인자)은 0~1(또는 %) 의 지각 밝기. 0.62 이상이면
  // 밝은 색으로 본다(흰색=1, 검정=0). getComputedStyle 이 이 형태를 그대로 반환하는 환경(Tailwind
  // v4 템플릿)에서 rgb 파싱이 실패하던 결함 대응.
  const oklch = color.trim().match(/^oklch\(\s*([\d.]+)(%?)/i);
  if (oklch) {
    const l = oklch[2] === '%' ? Number(oklch[1]) / 100 : Number(oklch[1]);
    return l >= 0.62;
  }
  const rgb = parseRgb(color);
  if (!rgb) return false;
  const [r, g, b] = rgb;
  const luminance = 0.299 * r + 0.587 * g + 0.114 * b;
  return luminance >= 180;
}

/**
 * 편집 오버레이의 배경색을 결정한다.
 *
 * 오버레이는 자체 배경을 깔아 원본을 덮으므로, 글자색이 밝은 색(흰색 등)이면 흰 배경 위에서
 * 텍스트가 묻혀 안 보인다(원본 캔버스에선 어두운 부모 배경 위라 보였던 노드). 글자색 밝기를
 * 측정해 밝으면 어두운 배경으로 전환해 대비를 보장한다.
 *
 * 글자색 출처(신뢰 순):
 *  - `nodeEffectiveColor` — EditorCanvasOverlay 가 측정한 대상 노드의 캔버스 내 computed 색
 *    (다크 컨텍스트·oklch 반영). 가장 정확하므로 있으면 우선.
 *  - `mirrorStyle.color`(styleProp 명시값) — 문자열 파싱으로 판정.
 *  - className 색 토큰 — 오버레이 자체 computed(effectiveColor, 다크 컨텍스트 밖이라 부정확) 폴백.
 *
 * @param nodeEffectiveColor 대상 노드의 캔버스 내 실제 글자색(computed)
 * @param effectiveColor 오버레이 자체 computed 글자색(폴백)
 * @param mirrorStyle 편집 대상 노드의 인라인 style 미러(명시 색 케이스)
 * @return 대비를 보장하는 오버레이 배경색
 */
function overlayBackground(
  nodeEffectiveColor: string | undefined,
  effectiveColor: string | null,
  mirrorStyle?: React.CSSProperties,
): string {
  const explicit = typeof mirrorStyle?.color === 'string' ? mirrorStyle.color : null;
  const colorToJudge = nodeEffectiveColor ?? explicit ?? effectiveColor;
  return isLightColor(colorToJudge) ? OVERLAY_BG_DARK : OVERLAY_BG_LIGHT;
}

export function InlineTextEditor({
  box,
  initialValue,
  isCustomKey,
  t,
  onCommit,
  onCancel,
  mirrorClassName,
  mirrorStyle,
  nodeEffectiveColor,
  onOpenTranslations,
}: InlineTextEditorProps): React.ReactElement {
  const ref = useRef<HTMLDivElement | null>(null);
  // 확정/취소가 중복 발화하지 않도록(blur 와 Enter 동시) 1회 가드.
  const committedRef = useRef(false);
  // contentEditable 은 비제어 — 초기값만 주입하고 이후 DOM 이 진실. React state 는
  // "현재 텍스트" 미러(빈값 판정용)만.
  const [hasContent, setHasContent] = useState(initialValue.trim().length > 0);
  // className 색 토큰의 실제 글자색(computed) — 흰 글자 묻힘 방지용 대비 배경 판정에 사용.
  // mirrorStyle.color(명시값)는 파싱으로 즉시 판정되지만, className 토큰은 프레임 CSS 가
  // 해석하므로 마운트 후 getComputedStyle 로만 읽을 수 있다.
  const [effectiveColor, setEffectiveColor] = useState<string | null>(null);

  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    el.textContent = initialValue;
    // 포커스 + 전체 선택 — 더블클릭 직후 바로 타이핑/교체 가능하게.
    el.focus();
    try {
      const range = window.document.createRange();
      range.selectNodeContents(el);
      const sel = window.getSelection();
      sel?.removeAllRanges();
      sel?.addRange(range);
    } catch {
      // 선택 실패는 무시 — 포커스만으로도 편집 가능.
    }
    // initialValue 변경(다른 노드 진입) 시 재초기화.
    committedRef.current = false;
  }, [initialValue]);

  // className 색 토큰의 실제 글자색을 computed 로 읽어 대비 배경 판정에 쓴다.
  // 명시 색(mirrorStyle.color)이 없고 className 만 있을 때 의미가 있으며, 서식 툴바로
  // 색 토큰이 바뀔 때마다(mirrorClassName 변경) 재측정한다.
  useEffect(() => {
    const el = ref.current;
    if (!el || typeof window.getComputedStyle !== 'function') {
      setEffectiveColor(null);
      return;
    }
    try {
      setEffectiveColor(window.getComputedStyle(el).color || null);
    } catch {
      setEffectiveColor(null);
    }
  }, [mirrorClassName, mirrorStyle]);

  const readValue = (): string => (ref.current?.textContent ?? '').trim();

  const commit = (): void => {
    if (committedRef.current) return;
    committedRef.current = true;
    onCommit(readValue());
  };

  const cancel = (): void => {
    if (committedRef.current) return;
    committedRef.current = true;
    onCancel();
  };

  const onKeyDown = (e: React.KeyboardEvent<HTMLDivElement>): void => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      commit();
    } else if (e.key === 'Escape') {
      e.preventDefault();
      cancel();
    }
  };

  return (
    <div
      className="g7le-inline-text-editor"
      data-testid="g7le-inline-text-editor"
      data-custom-key={isCustomKey ? 'true' : 'false'}
      style={{
        position: 'absolute',
        left: box.left,
        top: box.top,
        minWidth: Math.max(box.width, 60),
        minHeight: box.height,
        pointerEvents: 'auto',
        zIndex: 10000,
      }}
    >
      <div
        ref={ref}
        role="textbox"
        aria-label={t('layout_editor.inline_edit.aria_label')}
        aria-multiline="false"
        data-testid="g7le-inline-text-editable"
        // 서식 미러 — 편집 중 노드의 className 을 React-controlled 로 그대로 입혀 서식 툴바 변경을
        // 즉시 시각 반영. 캔버스 프레임 템플릿 CSS 가 토큰 className 을 해석.
        className={mirrorClassName}
        contentEditable
        suppressContentEditableWarning
        spellCheck={false}
        onInput={() => setHasContent(readValue().length > 0)}
        onKeyDown={onKeyDown}
        onBlur={commit}
        style={{
          // 편집 식별용 base — outline/배경/그림자는 항상 유지. font 관련은 className·mirrorStyle 이
          // 결정하도록 `font:'inherit'`(shorthand 가 font-weight 등을 reset) 를 두지 않는다.
          outline: '2px solid #2563eb',
          outlineOffset: 1,
          borderRadius: 3,
          // 대비 배경 — 글자색이 밝은 색(흰색 등)이면 어두운 배경으로 전환해 묻힘을 막는다.
          // 그 외(어두운 글자/색 출처 없음)는 흰 배경 유지.
          // 판정 소스는 nodeEffectiveColor(캔버스 다크 컨텍스트·oklch 반영) 우선.
          background: overlayBackground(nodeEffectiveColor, effectiveColor, mirrorStyle),
          padding: '2px 4px',
          minHeight: box.height,
          whiteSpace: 'pre-wrap',
          wordBreak: 'break-word',
          boxShadow: '0 2px 8px rgba(37, 99, 235, 0.25)',
          // 노드 인라인 style(styleProp 컨트롤 — fontSize/color 등) 미러링. base 뒤에 펼쳐 우선.
          ...(mirrorStyle ?? {}),
          // 가독성 폴백(흰 배경 위 진한 글자색)은 **색 출처가 전혀 없을 때만** 적용한다.
          //   - mirrorStyle.color 가 있으면 그 값을 그대로 둔다(styleProp 색 컨트롤).
          //   - mirrorClassName 이 있으면 그 className 의 색 토큰(예 text-blue-600)을 프레임 템플릿 CSS 가
          //     해석하도록 인라인 color 를 두지 않는다. 인라인 color 를 항상 깔면 classToken 색이
          //  인라인 우선순위에 가려 편집 중 색 변경이 안 보이던 결함(textColor 실시간
          //     미반영, 빠져나와야 반영). 색 출처가 아무것도 없을 때만 폴백색을 깐다.
          ...colorFallbackStyle(nodeEffectiveColor, mirrorClassName, mirrorStyle),
        }}
      />
      <div
        className="g7le-inline-text-editor__hint"
        data-testid="g7le-inline-text-editor-hint"
        style={{
          marginTop: 2,
          display: 'inline-flex',
          alignItems: 'center',
          gap: 4,
          whiteSpace: 'nowrap',
        }}
      >
        {/* 안내 + (onOpenTranslations 제공 시) 번역 모달 진입 버튼.
            "텍스트만 고치면 끝"으로 오해하지 않도록, 다른 로케일 일괄 편집(번역 탭)으로 쉽게 진입. */}
        {onOpenTranslations ? (
          <button
            type="button"
            data-testid="g7le-inline-text-editor-open-translations"
            // mousedown preventDefault — contentEditable blur(편집 자동 확정)보다 먼저 잡아
            // onClick 이 확실히 실행되게 한다(편집 중 클릭).
            onMouseDown={(e) => e.preventDefault()}
            onClick={(e) => {
              e.preventDefault();
              e.stopPropagation();
              onOpenTranslations();
            }}
            title={t('layout_editor.inline_edit.open_translations')}
            style={{
              padding: '2px 8px',
              fontSize: 11,
              background: isCustomKey ? '#eff6ff' : '#f0fdf4',
              color: isCustomKey ? '#1d4ed8' : '#15803d',
              border: `1px solid ${isCustomKey ? '#bfdbfe' : '#bbf7d0'}`,
              borderRadius: 4,
              display: 'inline-flex',
              alignItems: 'center',
              gap: 4,
              cursor: 'pointer',
              whiteSpace: 'nowrap',
            }}
          >
            <span aria-hidden="true">🌐</span>
            <span>
              {isCustomKey
                ? t('layout_editor.inline_edit.existing_key_hint')
                : t('layout_editor.inline_edit.new_key_hint')}
            </span>
            <span aria-hidden="true" style={{ opacity: 0.7 }}>
              · {t('layout_editor.inline_edit.open_translations')} ›
            </span>
          </button>
        ) : (
          <span
            style={{
              padding: '2px 6px',
              fontSize: 11,
              background: isCustomKey ? '#eff6ff' : '#f0fdf4',
              color: isCustomKey ? '#1d4ed8' : '#15803d',
              border: `1px solid ${isCustomKey ? '#bfdbfe' : '#bbf7d0'}`,
              borderRadius: 4,
              display: 'inline-flex',
              alignItems: 'center',
              gap: 4,
              whiteSpace: 'nowrap',
            }}
          >
            {isCustomKey ? (
              <>⌐ {t('layout_editor.inline_edit.existing_key_hint')}</>
            ) : (
              <>✎ {t('layout_editor.inline_edit.new_key_hint')}</>
            )}
          </span>
        )}
        {!hasContent && (
          <span
            data-testid="g7le-inline-text-editor-empty-warn"
            style={{ color: '#b91c1c', marginLeft: 4 }}
          >
            {t('layout_editor.inline_edit.empty_warning')}
          </span>
        )}
      </div>
    </div>
  );
}
