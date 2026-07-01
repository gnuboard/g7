/**
 * ElementOverlay.tsx — hover/선택 오버레이
 *
 * - hover: 점선 박스 (대상 DOM 의 getBoundingClientRect).
 * - 선택: 실선 박스 + 우상단 ⓘ + 8방향 핸들 자리(시각 골격만 — Phase 4 에서 활성)
 *         + base/확장 잠금 어포던스 + 네비게이션 어포던스.
 *
 * 호출자가 컨텍스트 + 버튼(InsertionAffordances)을 별도로 마운트한다 — 본
 * 컴포넌트는 박스 시각 + ⓘ + 어포던스 + 8방향 핸들 자리만 담당.
 *
 * @since engine-v1.50.0
 */

import React, { useState } from 'react';
import { useTranslation } from '../../TranslationContext';
import type { OverlayBox } from '../utils/overlayGeometry';
import { resolveAffordancePlacement, resolveInsertionCrossOffsets } from '../utils/overlayGeometry';
import { isContextMenuAllowed, type SelectionLockKind, type NavAffordanceKind } from '../hooks/useElementSelection';
import type { ResizeHandleKey } from '../hooks/useResizeHandles';
import { OVERLAY_AFFORDANCE } from '../utils/overlayZIndex';
import { ComponentContextMenu } from './ComponentContextMenu';

export interface ElementOverlayProps {
  /** hover 박스 (frame 기준) */
  hoverBox: OverlayBox | null;
  /** 선택 박스 (frame 기준) */
  selectedBox: OverlayBox | null;
  /** 선택 노드의 잠금 종류 */
  lockKind: SelectionLockKind;
  /** 선택 노드의 네비게이션 어포던스 */
  navAffordance: NavAffordanceKind;
  /** ⓘ 메뉴 — 속성 설정 클릭 (Phase 4 위임) */
  onEditProps: () => void;
  /** ⓘ 메뉴 — 컴포넌트 복사 */
  onDuplicate: () => void;
  /** ⓘ 메뉴 — 삭제 */
  onDelete: () => void;
  /**
   * ⓘ 메뉴 — 디바이스 전용 '분리 생성'. 현재 디바이스 보기에서
   * base(맨바탕)를 보는 컨테이너 노드일 때만 제공. 미제공 시 메뉴 항목 미표시.
   */
  onSeparateBranch?: () => void;
  /** '분리 생성' 메뉴 라벨(현재 디바이스명 포함, 예: "모바일 전용으로 분리"). */
  separateBranchLabel?: string;
  /**
   * ⓘ 메뉴 — 디바이스 전용 '분리 해제'. 현재 디바이스 전용 분기가
   * 이미 존재할 때만 제공. onSeparateBranch 와 상호배타. 미제공 시 항목 미표시.
   */
  onMergeBranch?: () => void;
  /** '분리 해제' 메뉴 라벨. */
  mergeBranchLabel?: string;
  /**
   * 이 영역에 정의된 **다른 디바이스 전용 구성** 목록.
   * 선택 노드가 `responsive.{key}.children` 교체 구성을 여러 디바이스에 가질 때, 현재 보고 있는
   * 디바이스를 제외한 나머지 구성 키+라벨. 비어 있으면 미표시. 버튼 클릭 시 `onJumpToDevice(key)`.
   */
  definedDeviceBranches?: Array<{ key: string; label: string }>;
  /** 디바이스 구성 점프 — 캔버스를 그 디바이스 보기로 전환. definedDeviceBranches 동반 시 필수. */
  onJumpToDevice?: (key: string) => void;
  /** "→ 이 화면 편집" 클릭 (navAffordance = route_in_tree 일 때만 활성) */
  onLinkEditDestination: () => void;
  /** "공통 레이아웃 편집" 클릭 (lockKind = base) — base 편집 모드 진입 */
  onEditBase?: () => void;
  /** "확장 편집" 클릭 (lockKind = extension) — 확장 편집 모드 진입 */
  onEditExtension?: () => void;
  /**
   * 선택 노드가 어느 확장에서 주입되었는지 식별하는 라벨(예: `sirsoft-board`).
   * lockKind = extension 일 때 "확장 편집" 버튼에 함께 표시해 어느 확장인지 알린다.
   * 미제공 시 라벨 없이 기존 문구만 표시.
   */
  extensionLabel?: string | null;
  /**
   * lockKind = base 일 때 그 공통 레이아웃 파일명(예: `_user_base`).
   * "공통 레이아웃 편집" 버튼에 함께 표시해 어느 파일인지 알린다.
   */
  baseLayoutLabel?: string | null;
  /**
   * lockKind = data_bound 일 때 그 반복 영역의 데이터 출처 식별자(예: `recent_posts`).
   * "데이터 영역" 안내에 함께 표시해 어느 데이터소스인지 알린다.
   */
  dataSourceLabel?: string | null;
  /**
   * 선택 노드가 디바이스 분기(responsive 자식 교체) 안이면 그 분기 라벨
   * (예: "모바일 구성"). 선택 박스 우상단에 배지로 표시해 사용자가 어느 벌(PC/모바일)을
   * 고치는지 인지하게 한다. base 노드면 null(배지 미표시).
   */
  branchLabel?: string | null;
  /**
   * "↳ 반복 항목 편집" 클릭 — 반복 항목 편집 모드 진입. 선택 영역이
   * 반복(iteration)일 때만 제공된다. 미제공(undefined)이면 어포던스 미표시
   * (폼 필드 등 비-반복 data_bound).
   */
  onEditIteration?: () => void;
  /**
   * 리사이즈 활성 축 — 선택 노드의 width/height 컨트롤 선언 여부.
   * 미제공/false 면 그 축 핸들을 미표시(스펙이 크기 편집을 허용하지 않음).
   */
  resizeEnabledAxes?: { width: boolean; height: boolean };
  /** 핸들 pointerdown — useResizeHandles 의 핸들러. 미제공 시 핸들은 시각 골격(비활성) */
  onResizeHandlePointerDown?: (handle: ResizeHandleKey, e: { clientX: number; clientY: number }) => void;
  /**
   * 선택 노드의 컴포넌트 타입 이름(예: `Div`/`Table`/`P`/`H1`) — **선택된 경우에 한해**
   * 박스 위에 흐린 오버레이 라벨로 표시. hover 에는
   * 미표시. 작은 박스(placement outside)는 콘텐츠를 가리지 않도록 박스 바깥에 띄운다.
   * @since engine-v1.50.0
   */
  selectedName?: string | null;
  /**
   * 부모 노드 선택 — 타입 칩 클릭 시 호출(겹친 부모 escalation). 제공되면 타입 칩이
   * 클릭 가능한 "부모 선택"(↑) 버튼이 된다. 미제공(루트 등 부모 없음)이면 기존 흐린
   * 라벨(클릭 불가). 캔버스 클릭은 항상 가장 깊은 자식을 잡으므로, 부모/자식 크기가
   * 같아 자식만 잡히는 경우 이 칩으로 한 단계씩 상위로 올라간다(상용 편집기 공통 패턴 —
   * 부모는 별도 어포던스). 키보드 `↑`(ArrowUp)도 같은 동작을 한다(`Esc` 는 선택 해제 —
   * EditorCanvasOverlay).
   * @since engine-v1.50.0
   */
  onSelectParent?: () => void;
}

export function ElementOverlay(props: ElementOverlayProps): React.ReactElement {
  const {
    hoverBox,
    selectedBox,
    lockKind,
    navAffordance,
    onEditProps,
    onDuplicate,
    onDelete,
    onSeparateBranch,
    separateBranchLabel,
    onMergeBranch,
    mergeBranchLabel,
    definedDeviceBranches,
    onJumpToDevice,
    onLinkEditDestination,
    onEditBase,
    onEditExtension,
    extensionLabel,
    baseLayoutLabel,
    dataSourceLabel,
    branchLabel,
    onEditIteration,
    resizeEnabledAxes,
    onResizeHandlePointerDown,
    selectedName,
    onSelectParent,
  } = props;
  const { t } = useTranslation();
  const [menuOpen, setMenuOpen] = useState(false);

  // ⓘ 컨텍스트 메뉴(속성 설정 / 복사 / 삭제) 허용 여부 — 잠금 출처 노드
  // (base / partial / extension / extension_point)는 차단하고 진입 어포던스만 노출한다
  // data_bound·none 만 허용. (확장 주입 노드가
  // 일반 컴포넌트처럼 속성 편집되던 결함: ElementOverlay 가 잠금 무관하게 ⓘ 를 항상
  // 렌더했음.)
  const contextMenuAllowed = isContextMenuAllowed(lockKind);
  // 잠금 노드에서는 메뉴가 차단되므로, 열림 상태였더라도 렌더에서 닫힌 것으로 취급한다.
  // (lockKind 가 none→extension 으로 바뀌어도 잔존 메뉴가 뜨지 않게)
  const menuVisible = menuOpen && contextMenuAllowed;

  // 작은 박스에서 버튼이 겹치지 않도록 배치 전략 결정.
  const placement = resolveAffordancePlacement(selectedBox);
  const isOutside = placement === 'outside';

  // - 작은 박스(outside): ⓘ 를 삽입 right 버튼의 가로 라인(left) + above 버튼의 세로
  //   라인(top)이 만나는 우상단 코너에 정확히 일치시킨다. 십자 4버튼 사이 빈 대각이라
  //   어느 것과도 겹치지 않고, 박스 크기와 무관하게 십자와 정렬된다.
  // - 큰 박스(inside): ⓘ(20px) 가 우상단 리사이즈 핸들(right:-4 top:-4)·코너와
  //  겹쳐 크기 핸들을 가린다. 박스 바깥 대각으로 충분히 밀어내(여백)
  //   ne 핸들과 코너에서 떨어뜨린다 — InsertionAffordances의 INSERTION_GAP 과 정합.
  const cross = isOutside && selectedBox ? resolveInsertionCrossOffsets(selectedBox) : null;
  const infoButtonPosition: React.CSSProperties = cross
    ? { left: cross.right.left, top: cross.above.top }
    : { right: -30, top: -30 };

  // lock 어포던스 — 박스 위 가장자리 고정(변경 없음).
  const lockPlacementStyle: React.CSSProperties = { top: -28, left: 0 };

  // data_bound 안내 라벨 — 작은 박스(outside)에서는 컴포넌트 타입 표식이 박스 **바깥
  // 좌상단**(bottom:100%)에 떠 안내 라벨(top:-28)과 겹친다. 타입
  // 표식이 바깥일 때는 안내 라벨을 그 위로 한 칸 더 올려(약 22px) 겹침을 피한다.
  // 큰 박스(inside)는 타입 표식이 박스 안쪽이라 겹치지 않으므로 기존 위치 유지.
  const dataBoundOverlapsTypeLabel = isOutside && !!selectedName;
  const dataBoundPlacementStyle: React.CSSProperties = dataBoundOverlapsTypeLabel
    ? { top: -50, left: 0 }
    : lockPlacementStyle;

  // nav 어포던스 — 작은 박스(cross)에서는 삽입 below 버튼이 박스 아래를 점유하므로
  // below 버튼 아래로 내려 겹침을 피한다. 큰 박스(inside)는 기존 박스 아래(-28) 유지.
  const navPlacementStyle: React.CSSProperties = cross
    ? { top: cross.below.top + 28, left: 0 }
    : { top: 'auto', bottom: -28 };

  // 디바이스 전용 분리 버튼(안 A — ③④) — 선택 박스 **하단 전용 줄**.
  // nav/iteration 어포던스(navPlacementStyle)보다 한 줄(약 28px) 더 내려 어떤 경우에도
  // 겹치지 않게 한다.
  // 분리 버튼은 lockKind === 'none' 컨테이너에서만 노출되므로 lock/확장 어포던스와는
  // 애초에 상호배타지만, 작은 박스에서 cross 십자 below 버튼과의 간섭을 막기 위해 한 줄 더 둔다.
  const branchSeparatePlacementStyle: React.CSSProperties = cross
    ? { top: cross.below.top + 56, left: 0 }
    : { top: 'auto', bottom: -56 };

  return (
    <div className="g7le-element-overlay" data-testid="g7le-element-overlay" style={{ position: 'absolute', inset: 0, pointerEvents: 'none' }}>
      {hoverBox && (
        <div
          data-testid="g7le-overlay-hover"
          style={{
            position: 'absolute',
            left: hoverBox.left,
            top: hoverBox.top,
            width: hoverBox.width,
            height: hoverBox.height,
            border: '1px dashed #2563eb',
            pointerEvents: 'none',
          }}
        />
      )}

      {selectedBox && (
        <div
          data-testid="g7le-overlay-selected"
          data-lock-kind={lockKind}
          style={{
            position: 'absolute',
            left: selectedBox.left,
            top: selectedBox.top,
            width: selectedBox.width,
            height: selectedBox.height,
            border: lockKind === 'none' ? '2px solid #2563eb' : '2px dashed #f59e0b',
            pointerEvents: 'none',
          }}
        >
          {/* 디바이스 분기 배지 — 선택 노드가 responsive 분기(모바일 구성 등)
              안이면 박스 우상단에 표시해 어느 벌을 편집 중인지 인지시킨다. base 노드면
              branchLabel=null → 미표시. */}
          {branchLabel ? (
            <span
              data-testid="g7le-overlay-branch-badge"
              style={branchBadgeStyle}
            >
              📱 {branchLabel}
            </span>
          ) : null}
          {/* 컴포넌트 타입 식별자 라벨 — **선택된 경우에 한해** 박스 좌상단에 흐린
 오버레이로 표시. 박스가 작으면(placement
              outside) 라벨이 콘텐츠를 가리므로 박스 **바깥 좌상단**(위쪽)에 띄운다. 큰
              박스는 안쪽 좌상단. pointerEvents none 으로 클릭/리사이즈 비방해. */}
          {selectedName && (
            onSelectParent ? (
              // 부모가 있으면 타입 칩을 "부모 선택"(↑) 버튼으로 — 캔버스 클릭은 늘 가장 깊은
              // 자식을 잡으므로(closest), 부모/자식 크기가 같아 자식만 잡히는 경우 이 칩으로
              // 한 단계씩 상위 선택(상용 편집기 공통 — 부모는 별도 어포던스). 키보드 ↑/Esc 병행.
              <button
                type="button"
                data-testid="g7le-overlay-type-label"
                data-placement={isOutside ? 'outside' : 'inside'}
                title={t('layout_editor.overlay.select_parent')}
                aria-label={t('layout_editor.overlay.select_parent')}
                onClick={(e) => {
                  e.preventDefault();
                  e.stopPropagation();
                  onSelectParent();
                }}
                style={isOutside ? typeLabelButtonOutsideStyle : typeLabelButtonStyle}
              >
                <span aria-hidden style={{ marginRight: 3, opacity: 0.85 }}>↑</span>
                {selectedName}
              </button>
            ) : (
              <span
                data-testid="g7le-overlay-type-label"
                data-placement={isOutside ? 'outside' : 'inside'}
                style={isOutside ? typeLabelOutsideStyle : typeLabelStyle}
              >
                {selectedName}
              </span>
            )
          )}

          {/* ⓘ 아이콘 — 컨텍스트 메뉴 트리거. 잠금 출처 노드(base/partial/extension/
              extension_point)에는 미표시 — 그 노드는 속성/구조 편집 대신 진입 어포던스만
 제공한다. data_bound·none 만 표시. */}
          {contextMenuAllowed && (
          <button
            type="button"
            data-testid="g7le-overlay-info-button"
            aria-label="info"
            onClick={(e) => {
              e.stopPropagation();
              setMenuOpen((v) => !v);
            }}
            data-placement={placement}
            style={{
              position: 'absolute',
              ...infoButtonPosition,
              width: 20,
              height: 20,
              borderRadius: 10,
              border: '1px solid #2563eb',
              background: '#fff',
              color: '#2563eb',
              fontSize: 12,
              fontWeight: 700,
              cursor: 'pointer',
              pointerEvents: 'auto',
              padding: 0,
              lineHeight: '18px',
              // S5b 드래그 핸들(20+depth) 위 어포던스 밴드 — 미지정 시 핸들이 ⓘ 를 덮어
              // 클릭이 이동 포인터에 가로채임.
              zIndex: OVERLAY_AFFORDANCE,
            }}
          >
            ⓘ
          </button>
          )}

          {/* 8방향 리사이즈 핸들 — Phase 4 활성. 스펙이 width/height 컨트롤을
              선언한 축만 표시·드래그 가능. onResizeHandlePointerDown 미제공 시 시각 골격(비활성). */}
          {lockKind === 'none' && (
            <ResizeHandles
              enabledAxes={resizeEnabledAxes}
              onHandlePointerDown={onResizeHandlePointerDown}
            />
          )}

          {/* base/확장 잠금 어포던스 */}
          {lockKind === 'base' && onEditBase && (
            <button
              type="button"
              data-testid="g7le-overlay-edit-base"
              onClick={(e) => {
                e.stopPropagation();
                onEditBase();
              }}
              style={{ ...lockAffordanceStyle, ...lockPlacementStyle }}
            >
              🔒 {t('layout_editor.overlay.edit_common_layout')}
              {baseLayoutLabel ? (
                <span data-testid="g7le-overlay-base-layout-label" style={extensionLabelStyle}>
                  {baseLayoutLabel}
                </span>
              ) : null}
            </button>
          )}
          {lockKind === 'extension' && onEditExtension && (
            <button
              type="button"
              data-testid="g7le-overlay-edit-extension"
              onClick={(e) => {
                e.stopPropagation();
                onEditExtension();
              }}
              style={{ ...lockAffordanceStyle, ...lockPlacementStyle }}
            >
              🔒 {t('layout_editor.overlay.edit_extension')}
              {extensionLabel ? (
                <span data-testid="g7le-overlay-extension-label" style={extensionLabelStyle}>
                  {extensionLabel}
                </span>
              ) : null}
            </button>
          )}
          {lockKind === 'data_bound' && (
            <span
              data-testid="g7le-overlay-data-bound-notice"
              style={{ ...lockAffordanceStyle, ...dataBoundPlacementStyle, pointerEvents: 'none', cursor: 'default' }}
            >
              {t('layout_editor.overlay.locked_data_bound')}
              {dataSourceLabel ? (
                <span data-testid="g7le-overlay-data-source-label" style={extensionLabelStyle}>
                  {dataSourceLabel}
                </span>
              ) : null}
            </span>
          )}

          {/* 반복(iteration) 영역 — "↳ 반복 항목 편집" 진입 어포던스. 안내 라벨은
              pointerEvents:none 이라 클릭 불가하므로, 진입 버튼은 별도 클릭 가능 요소로
              박스 아래에 둔다. onEditIteration 미제공(폼 필드 등 비-반복 data_bound)이면 미표시. */}
          {lockKind === 'data_bound' && onEditIteration && (
            <button
              type="button"
              data-testid="g7le-overlay-edit-iteration"
              onClick={(e) => {
                e.stopPropagation();
                onEditIteration();
              }}
              style={{ ...lockAffordanceStyle, ...navPlacementStyle }}
            >
              ↳ {t('layout_editor.overlay.edit_iteration_item')}
            </button>
          )}

          {/* 네비게이션 어포던스 — 목적지가 라우트 트리에 있을 때만 "이 화면 편집" 표시.
              external_url / route_not_in_tree / dynamic_path 는 편집기에서 이동 불가이므로
 어포던스를 **표시하지 않는다**((4) — 종전 "이동할 수 없습니다" 안내는
              네비게이션을 드래그 재배치로 오해시켜 제거). 그 노드는 일반 노드와 동일하게
              선택·드래그·속성 편집되며 네비 액션 값 자체는 무손실 보존된다. */}
          {navAffordance === 'route_in_tree' && (
            <button
              type="button"
              data-testid="g7le-overlay-link-edit-destination"
              onClick={(e) => {
                e.stopPropagation();
                onLinkEditDestination();
              }}
              style={{ ...lockAffordanceStyle, ...navPlacementStyle }}
            >
              {t('layout_editor.overlay.edit_destination_route')}
            </button>
          )}

          {/* 디바이스 분기 버튼군. 선택 박스 하단
              전용 영역에 **세로로 쌓아**(여러 줄) 노출 — 이동(점프)·분리·해제 버튼이 동시에
 뜰 수 있으므로 가로 겹침을 막기 위해 한 컨테이너의 column flow 로 배치
              ("두 줄에 걸쳐, 겹치지 않게"). 컨테이너만 절대위치
              (branchSeparatePlacementStyle), 자식 버튼은 정상 흐름이라 겹치지 않는다.
              lockKind === 'none' 컨테이너 대상이라 잠금/확장 어포던스와 상호배타. */}
          {(onSeparateBranch || onMergeBranch || (onJumpToDevice && (definedDeviceBranches ?? []).length > 0)) && (
            <div
              data-testid="g7le-overlay-branch-affordances"
              style={{
                position: 'absolute',
                ...branchSeparatePlacementStyle,
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'flex-start',
                gap: 4,
                pointerEvents: 'none',
              }}
            >
              {/* 점프(이동) 버튼군 먼저 — 다른 디바이스에 별도 구성이 있음을 알리고 그 보기로 전환.
                  다중 커스텀 범위도 각각 별도 버튼(키별 고유 testid). */}
              {onJumpToDevice &&
                (definedDeviceBranches ?? []).map((b) => (
                  <button
                    key={b.key}
                    type="button"
                    data-testid={`g7le-overlay-jump-device-${b.key}`}
                    title={t('layout_editor.overlay.jump_device_hint', { device: b.label })}
                    onClick={(e) => {
                      e.stopPropagation();
                      onJumpToDevice(b.key);
                    }}
                    style={branchAffordanceButtonStyle}
                  >
                    ↗ {t('layout_editor.overlay.jump_device', { device: b.label })}
                  </button>
                ))}
              {/* 분리 생성 / 해제 — 둘 중 하나만 제공된다(EditorCanvasOverlay 가 mode 로 결정). */}
              {onSeparateBranch && (
                <button
                  type="button"
                  data-testid="g7le-overlay-separate-branch"
                  title={t('layout_editor.context_menu.separate_branch_hint')}
                  onClick={(e) => {
                    e.stopPropagation();
                    onSeparateBranch();
                  }}
                  style={branchAffordanceButtonStyle}
                >
                  📱 {separateBranchLabel ?? t('layout_editor.context_menu.separate_branch')}
                </button>
              )}
              {onMergeBranch && (
                <button
                  type="button"
                  data-testid="g7le-overlay-merge-branch"
                  title={t('layout_editor.context_menu.merge_branch_hint')}
                  onClick={(e) => {
                    e.stopPropagation();
                    onMergeBranch();
                  }}
                  style={branchAffordanceButtonStyle}
                >
                  ↩️ {mergeBranchLabel ?? t('layout_editor.context_menu.merge_branch')}
                </button>
              )}
            </div>
          )}
        </div>
      )}

      <ComponentContextMenu
        anchor={
          selectedBox && menuVisible
            ? { left: selectedBox.left + selectedBox.width + 8, top: selectedBox.top - 4 }
            : null
        }
        open={menuVisible}
        onClose={() => setMenuOpen(false)}
        onEditProps={onEditProps}
        onDuplicate={onDuplicate}
        onDelete={onDelete}
        onSeparateBranch={onSeparateBranch}
        separateBranchLabel={separateBranchLabel}
        onMergeBranch={onMergeBranch}
        mergeBranchLabel={mergeBranchLabel}
      />
    </div>
  );
}

/**
 * 8방향 리사이즈 핸들. 스펙이 width/height 컨트롤을 선언한 축의 핸들만
 * 활성(pointer-events:auto + onPointerDown). 미선언 축 핸들은 표시하지 않는다
 * onHandlePointerDown 미제공 시 전부 시각 골격(비활성 — 디그레이드).
 *
 * 핸들별 축: 변 중앙(n/s=세로, e/w=가로), 모서리(nw/ne/se/sw=양축).
 */
function ResizeHandles(props: {
  enabledAxes?: { width: boolean; height: boolean };
  onHandlePointerDown?: (
    handle: ResizeHandleKey,
    e: { clientX: number; clientY: number },
  ) => void;
}): React.ReactElement {
  const { enabledAxes, onHandlePointerDown } = props;
  const widthOn = enabledAxes?.width ?? false;
  const heightOn = enabledAxes?.height ?? false;

  const positions: Array<{
    key: ResizeHandleKey;
    style: React.CSSProperties;
    cursor: string;
    needsWidth: boolean;
    needsHeight: boolean;
  }> = [
    { key: 'nw', style: { left: -4, top: -4 }, cursor: 'nwse-resize', needsWidth: true, needsHeight: true },
    { key: 'n', style: { left: '50%', top: -4, transform: 'translateX(-50%)' }, cursor: 'ns-resize', needsWidth: false, needsHeight: true },
    { key: 'ne', style: { right: -4, top: -4 }, cursor: 'nesw-resize', needsWidth: true, needsHeight: true },
    { key: 'e', style: { right: -4, top: '50%', transform: 'translateY(-50%)' }, cursor: 'ew-resize', needsWidth: true, needsHeight: false },
    { key: 'se', style: { right: -4, bottom: -4 }, cursor: 'nwse-resize', needsWidth: true, needsHeight: true },
    { key: 's', style: { left: '50%', bottom: -4, transform: 'translateX(-50%)' }, cursor: 'ns-resize', needsWidth: false, needsHeight: true },
    { key: 'sw', style: { left: -4, bottom: -4 }, cursor: 'nesw-resize', needsWidth: true, needsHeight: true },
    { key: 'w', style: { left: -4, top: '50%', transform: 'translateY(-50%)' }, cursor: 'ew-resize', needsWidth: true, needsHeight: false },
  ];

  return (
    <>
      {positions.map((p) => {
        // 핸들이 요구하는 모든 축이 활성이어야 표시. 모서리는 양축, 변은 한 축.
        const widthSatisfied = !p.needsWidth || widthOn;
        const heightSatisfied = !p.needsHeight || heightOn;
        const active = widthSatisfied && heightSatisfied && !!onHandlePointerDown;
        if (!widthSatisfied || !heightSatisfied) return null; // 미선언 축 핸들 미표시
        return (
          <span
            key={p.key}
            data-testid={`g7le-resize-handle-${p.key}`}
            data-resize-handle-active={active ? 'true' : 'false'}
            onPointerDown={
              active
                ? (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    onHandlePointerDown?.(p.key, { clientX: e.clientX, clientY: e.clientY });
                  }
                : undefined
            }
            style={{
              position: 'absolute',
              ...p.style,
              width: 8,
              height: 8,
              background: '#fff',
              border: '1px solid #2563eb',
              cursor: p.cursor,
              pointerEvents: active ? 'auto' : 'none',
              // S5b 드래그 핸들(20+depth) 위 어포던스 밴드 — 미지정 시 핸들이 리사이즈
              // 핸들을 덮어 드래그 리사이즈가 이동에 가로채임.
              zIndex: OVERLAY_AFFORDANCE,
            }}
          />
        );
      })}
    </>
  );
}

const lockAffordanceStyle: React.CSSProperties = {
  position: 'absolute',
  top: -28,
  left: 0,
  background: '#fff',
  border: '1px solid #2563eb',
  color: '#2563eb',
  padding: '2px 6px',
  fontSize: 11,
  borderRadius: 4,
  cursor: 'pointer',
  pointerEvents: 'auto',
  // 긴 안내 문구가 좁은 박스 폭에 갇혀 세로로 줄바꿈되지 않도록 한 줄 유지(폭 초과 허용).
  whiteSpace: 'nowrap',
  // S5b 드래그 핸들(20+depth) 위 어포던스 밴드 — 잠금/네비 어포던스 클릭이 핸들에
  // 가로채이지 않도록. data_bound 안내(span)는 pointerEvents:none 으로
  // 클릭 대상이 아니나 시각 일관성 위해 동일 밴드 유지.
  zIndex: OVERLAY_AFFORDANCE,
};

// 디바이스 분기 버튼(이동/분리/해제) — 세로 스택 컨테이너(g7le-overlay-branch-affordances)
// 안의 정상 흐름 버튼. lockAffordanceStyle 의 시각은 같되 절대위치는 컨테이너가 담당하므로
// position 을 빼고(static), 클릭 가능하도록 pointerEvents 를 켠다(컨테이너는 none).
const branchAffordanceButtonStyle: React.CSSProperties = {
  background: '#fff',
  border: '1px solid #2563eb',
  color: '#2563eb',
  padding: '2px 6px',
  fontSize: 11,
  borderRadius: 4,
  cursor: 'pointer',
  pointerEvents: 'auto',
  whiteSpace: 'nowrap',
  zIndex: OVERLAY_AFFORDANCE,
};

// "확장 편집" 버튼 안에서 어느 확장인지 식별하는 칩 — 문구 뒤에 배경칩으로 덧붙인다.
const extensionLabelStyle: React.CSSProperties = {
  marginLeft: 6,
  padding: '0 5px',
  borderRadius: 3,
  background: '#eef2ff',
  border: '1px solid #c7d2fe',
  fontSize: 10,
  fontWeight: 600,
  color: '#3730a3',
};

// 디바이스 분기 배지 — 박스 우상단, 분기(모바일 구성 등) 식별. pointerEvents none.
const branchBadgeStyle: React.CSSProperties = {
  position: 'absolute',
  top: 0,
  right: 0,
  transform: 'translateY(-100%)',
  background: 'rgba(124,58,237,0.92)',
  color: '#fff',
  padding: '1px 6px',
  borderRadius: '3px 3px 0 0',
  fontSize: 10,
  fontWeight: 600,
  whiteSpace: 'nowrap',
  pointerEvents: 'none',
};

// 컴포넌트 타입 라벨 — 박스 좌상단 안쪽, 흐리게(반투명). 클릭/리사이즈 비방해.
const typeLabelStyle: React.CSSProperties = {
  position: 'absolute',
  top: 0,
  left: 0,
  background: 'rgba(37,99,235,0.55)',
  color: '#fff',
  padding: '1px 6px',
  fontSize: 10,
  fontWeight: 600,
  lineHeight: '14px',
  borderBottomRightRadius: 4,
  letterSpacing: '0.02em',
  whiteSpace: 'nowrap',
  pointerEvents: 'none',
  userSelect: 'none',
  maxWidth: '100%',
  overflow: 'hidden',
  textOverflow: 'ellipsis',
};

// 작은 박스용 — 박스 바깥 좌상단(위쪽)에 띄워 콘텐츠를 가리지 않음. 모서리 둥글게.
const typeLabelOutsideStyle: React.CSSProperties = {
  position: 'absolute',
  bottom: '100%',
  left: 0,
  marginBottom: 2,
  background: 'rgba(37,99,235,0.55)',
  color: '#fff',
  padding: '1px 6px',
  fontSize: 10,
  fontWeight: 600,
  lineHeight: '14px',
  borderRadius: 4,
  letterSpacing: '0.02em',
  whiteSpace: 'nowrap',
  pointerEvents: 'none',
  userSelect: 'none',
};

// 부모 선택 버튼 변형 — 타입 칩이 클릭 가능할 때(부모 존재). pointerEvents:auto +
// cursor:pointer + 약간 진한 배경(hover 가능성 시그널). button 기본값(border/font) 리셋.
const typeLabelButtonStyle: React.CSSProperties = {
  ...typeLabelStyle,
  pointerEvents: 'auto',
  cursor: 'pointer',
  background: 'rgba(37,99,235,0.82)',
  border: 'none',
  display: 'inline-flex',
  alignItems: 'center',
  font: 'inherit',
  fontSize: 10,
  fontWeight: 600,
  // S5b 드래그 핸들(20+depth) 위 어포던스 밴드 — 미지정 시 인접 노드의 핸들이 칩을 덮어
  // 부모 선택 클릭이 이동 포인터에 가로채임(ⓘ 버튼과 동일 회귀 — E2E 실클릭으로 검출).
  zIndex: OVERLAY_AFFORDANCE,
};

const typeLabelButtonOutsideStyle: React.CSSProperties = {
  ...typeLabelOutsideStyle,
  pointerEvents: 'auto',
  cursor: 'pointer',
  background: 'rgba(37,99,235,0.82)',
  border: 'none',
  display: 'inline-flex',
  alignItems: 'center',
  font: 'inherit',
  fontSize: 10,
  fontWeight: 600,
  // 박스 바깥(위) 배치 칩은 위 형제 노드의 드래그 핸들과 겹친다 — 어포던스 밴드 필수.
  zIndex: OVERLAY_AFFORDANCE,
};
