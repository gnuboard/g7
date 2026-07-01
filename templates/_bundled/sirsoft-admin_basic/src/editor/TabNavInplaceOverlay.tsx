// e2e:allow 레이아웃 편집기 캔버스 인플레이스 오버레이 UI — 합성 마우스/측정 박스 의존으로 Playwright 자동화 부적합. Chrome MCP 매트릭스(T1~T8) 실측 + 단위 테스트로 검증.
/**
 * TabNavInplaceOverlay.tsx — `tabnav` 캔버스 인플레이스 오버레이
 *
 *
 * `registerCanvasOverlay` 확장점이 **문서상 확장점이 아니라 실제 동작하는 경로**임을
 * 실증하기 위한 템플릿 제공 오버레이. 코어 빌트인(TableInplaceOverlay = `table` kind)과
 * 달리, 본 오버레이는 **템플릿이 직접** `initTemplate` 에서 `G7Core.layoutEditor.
 * registerCanvasOverlay('tabnav', TabNavInplaceOverlay)` 로 등록한다.
 *
 * 대상: TabNavigation 의 `tabs` 배열(1D 항목 구조 — 표의 2D grid 와 다른 모델이라
 * 확장점의 범용성을 실증). 캔버스에 렌더된 탭 헤더 위에 시중 탭 편집 UX —
 *  - 각 탭 헤더 우상단 ✕(삭제)
 *  - 탭 사이/끝 +(추가) — 새 탭을 `node.props.tabs` 에 push
 *  - 각 탭 ◀ ▶(좌/우 이동)
 *
 * **동일 패치 경로 SSoT**: 모든 구조 변형은 `onPatchNode({...node, props:{...props, tabs}})`
 * 로 노드를 통째 교체한다. 속성 패널 `ArrayItemsEditor`(tabs 배열, 정적값 한정 — 단계 4-a)와
 * **동일한 `node.props.tabs` 패치 경로**를 공유한다(인플레이스 ↔ 속성패널 일관). 라벨 다국어
 * 편집은 속성 패널 ArrayItemsEditor(i18n-text 위젯)가 담당하고, 인플레이스는 add/remove/move
 * 구조 변형을 담당한다.
 *
 * **측정 박스 주입**: 코어 `EditorCanvasOverlay` 가 각 탭 헤더 DOM(`data-editor-item-path=
 * "<node>.props.tabs.<i>"` 마커 — TabNavigation 이 편집 모드에서 부여)을 측정해 `cellBoxes`
 * (path=`props.tabs.<i>`)로 넘긴다. 본 오버레이는 그 path 로 탭 인덱스를 역매핑해 어포던스를
 * 그 좌표에 배치한다(코어는 측정만 — 어포던스 시각/상호작용은 템플릿 오버레이가 정의).
 *
 * 어포던스는 코어 위 z-index(TABLE_INPLACE 밴드와 동급 130)로 두어 드래그 핸들/코어 선택
 * 오버레이가 버튼 클릭을 가로채지 못하게 한다(메모리
 * `feedback_editor_canvas_affordance_dedicated_lane_and_zindex`). 편집기 코어 UI 관용대로
 * `g7le-*` 류 BEM 클래스 + 인라인 스타일만 사용하되, 본 파일은 템플릿 소유라 자체 스타일도
 * 허용된다(라이브러리 토큰 비종속 — 인라인 style SSoT).
 */

import React, { useCallback, useMemo } from 'react';

/** 코어가 주입하는 오버레이 박스(프레임 기준 좌표). */
interface OverlayBox {
  top: number;
  left: number;
  width: number;
  height: number;
}

/** 코어 `CanvasOverlayProps` 의 본 오버레이 사용 부분집합(코어 타입과 구조 호환). */
interface TabNavOverlayProps {
  node: { props?: Record<string, unknown> } & Record<string, unknown>;
  params?: Record<string, unknown>;
  nodeBox: OverlayBox;
  cellBoxes?: Array<OverlayBox & { path: string }>;
  colorScheme?: 'light' | 'dark';
  t: (key: string, params?: Record<string, string | number>) => string;
  onPatchNode: (patched: TabNavOverlayProps['node']) => void;
}

/** 탭 항목(TabNavigation `tabs` 배열 요소). */
interface TabItem {
  id: string | number;
  label: string;
  [k: string]: unknown;
}

/** 어포던스 z-index — 코어 어포던스 밴드 위(드래그 핸들/선택 오버레이가 클릭 가로채기 방지). */
const Z_AFFORDANCE = 130;

/** 어포던스 버튼 한 변(px). */
const BTN = 20;

/**
 * `props` 의 배열 prop 키 — capability `canvasOverlay.params.arrayProp` 가 공급(중립).
 * 미지정이면 `tabs`(TabNavigation 기본).
 */
function resolveArrayProp(params: Record<string, unknown> | undefined): string {
  const ap = params?.arrayProp;
  return typeof ap === 'string' && ap ? ap : 'tabs';
}

/** 새 탭 항목 id 생성 — 기존 id 와 충돌하지 않는 최소 정수(문자 id 와도 안전). */
function nextTabId(tabs: TabItem[]): number {
  let max = 0;
  for (const t of tabs) {
    const n = typeof t.id === 'number' ? t.id : Number(t.id);
    if (Number.isFinite(n) && n > max) max = n;
  }
  return max + 1;
}

export function TabNavInplaceOverlay({
  node,
  params,
  cellBoxes,
  t,
  onPatchNode,
}: TabNavOverlayProps): React.ReactElement | null {
  const arrayProp = resolveArrayProp(params);

  // 현재 탭 배열. `{{...}}` 바인딩/비-배열이면 인플레이스 비대상(정적값만 — 단계 4 가드).
  const tabs = useMemo<TabItem[] | null>(() => {
    const raw = (node.props ?? {})[arrayProp];
    if (typeof raw === 'string') return null; // 바인딩 표현식 → 비대상
    if (!Array.isArray(raw)) return null;
    return raw as TabItem[];
  }, [node, arrayProp]);

  // path(`props.tabs.<i>`) → 측정 박스. 코어가 탭 헤더 DOM 을 측정해 넘긴 것.
  const boxByIndex = useMemo<Map<number, OverlayBox>>(() => {
    const m = new Map<number, OverlayBox>();
    const prefix = `props.${arrayProp}.`;
    for (const b of cellBoxes ?? []) {
      if (!b.path.startsWith(prefix)) continue;
      const idx = Number(b.path.slice(prefix.length));
      if (Number.isInteger(idx)) m.set(idx, { top: b.top, left: b.left, width: b.width, height: b.height });
    }
    return m;
  }, [cellBoxes, arrayProp]);

  // ── 구조 연산(속성 패널 ArrayItemsEditor 와 동일 `node.props[arrayProp]` 패치 경로) ──
  const patchTabs = useCallback(
    (next: TabItem[]): void => {
      const prevProps = (node.props ?? {}) as Record<string, unknown>;
      onPatchNode({ ...node, props: { ...prevProps, [arrayProp]: next } });
    },
    [node, arrayProp, onPatchNode],
  );

  const addAt = useCallback(
    (index: number): void => {
      if (!tabs) return;
      const id = nextTabId(tabs);
      // 기본 라벨은 평문(현재 로케일) — 라벨 다국어화는 속성 패널 ArrayItemsEditor(i18n-text)
      // 가 담당(동일 `node.props.tabs` SSoT). 인플레이스는 구조 변형(추가/삭제/이동)만.
      const newTab: TabItem = { id, label: t('editor.tabnav_inplace.new_tab') };
      const next = tabs.slice();
      next.splice(index, 0, newTab);
      patchTabs(next);
    },
    [tabs, patchTabs, t],
  );

  const removeAt = useCallback(
    (index: number): void => {
      if (!tabs) return;
      const next = tabs.slice();
      next.splice(index, 1);
      patchTabs(next);
    },
    [tabs, patchTabs],
  );

  const moveBy = useCallback(
    (index: number, dir: -1 | 1): void => {
      if (!tabs) return;
      const target = index + dir;
      if (target < 0 || target >= tabs.length) return;
      const next = tabs.slice();
      const [moved] = next.splice(index, 1);
      next.splice(target, 0, moved as TabItem);
      patchTabs(next);
    },
    [tabs, patchTabs],
  );

  if (!tabs || boxByIndex.size === 0) {
    // 바인딩/비배열이거나 아직 측정 전(또는 모바일 Select 분기라 탭 헤더 DOM 없음) → 미표시.
    return null;
  }

  const btnStyle: React.CSSProperties = {
    position: 'absolute',
    width: BTN,
    height: BTN,
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    fontSize: 12,
    lineHeight: 1,
    border: '1px solid rgba(0,0,0,0.15)',
    borderRadius: 4,
    background: '#ffffff',
    color: '#1f2937',
    cursor: 'pointer',
    boxShadow: '0 1px 2px rgba(0,0,0,0.18)',
    pointerEvents: 'auto',
    padding: 0,
    zIndex: Z_AFFORDANCE,
  };

  return (
    <div
      className="g7le-tabnav-inplace"
      data-testid="g7le-tabnav-inplace"
      style={{ position: 'absolute', inset: 0, pointerEvents: 'none', zIndex: Z_AFFORDANCE }}
    >
      {tabs.map((tab, i) => {
        const box = boxByIndex.get(i);
        if (!box) return null;
        const right = box.left + box.width;
        return (
          <div key={`tab-aff-${tab.id}-${i}`} data-testid={`g7le-tabnav-aff-${i}`}>
            {/* ✕ 삭제 — 탭 헤더 우상단 바깥 */}
            <button
              type="button"
              data-testid={`g7le-tabnav-remove-${i}`}
              title={t('editor.tabnav_inplace.remove')}
              aria-label={t('editor.tabnav_inplace.remove')}
              style={{ ...btnStyle, left: right - BTN / 2, top: box.top - BTN / 2, color: '#b91c1c' }}
              onClick={(e) => {
                e.preventDefault();
                e.stopPropagation();
                removeAt(i);
              }}
            >
              ✕
            </button>
            {/* ◀ 좌 이동 — 탭 헤더 좌하단 */}
            {i > 0 && (
              <button
                type="button"
                data-testid={`g7le-tabnav-move-left-${i}`}
                title={t('editor.tabnav_inplace.move_left')}
                aria-label={t('editor.tabnav_inplace.move_left')}
                style={{ ...btnStyle, left: box.left, top: box.top + box.height - BTN / 2 }}
                onClick={(e) => {
                  e.preventDefault();
                  e.stopPropagation();
                  moveBy(i, -1);
                }}
              >
                ◀
              </button>
            )}
            {/* ▶ 우 이동 — 탭 헤더 우하단 */}
            {i < tabs.length - 1 && (
              <button
                type="button"
                data-testid={`g7le-tabnav-move-right-${i}`}
                title={t('editor.tabnav_inplace.move_right')}
                aria-label={t('editor.tabnav_inplace.move_right')}
                style={{ ...btnStyle, left: right - BTN, top: box.top + box.height - BTN / 2 }}
                onClick={(e) => {
                  e.preventDefault();
                  e.stopPropagation();
                  moveBy(i, 1);
                }}
              >
                ▶
              </button>
            )}
            {/* + 추가 — 이 탭 앞(첫 탭) / 각 탭 다음 사이·끝 */}
            {i === 0 && (
              <button
                type="button"
                data-testid="g7le-tabnav-add-0"
                title={t('editor.tabnav_inplace.add')}
                aria-label={t('editor.tabnav_inplace.add')}
                style={{ ...btnStyle, left: box.left - BTN, top: box.top + box.height / 2 - BTN / 2, color: '#15803d' }}
                onClick={(e) => {
                  e.preventDefault();
                  e.stopPropagation();
                  addAt(0);
                }}
              >
                +
              </button>
            )}
            <button
              type="button"
              data-testid={`g7le-tabnav-add-${i + 1}`}
              title={t('editor.tabnav_inplace.add')}
              aria-label={t('editor.tabnav_inplace.add')}
              style={{ ...btnStyle, left: right - BTN / 2, top: box.top + box.height / 2 - BTN / 2, color: '#15803d' }}
              onClick={(e) => {
                e.preventDefault();
                e.stopPropagation();
                addAt(i + 1);
              }}
            >
              +
            </button>
          </div>
        );
      })}
    </div>
  );
}
