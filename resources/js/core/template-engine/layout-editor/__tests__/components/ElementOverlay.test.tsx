/**
 * ElementOverlay 컴포넌트 테스트
 *
 * - hoverBox 만 있으면 점선 박스만 렌더
 * - selectedBox 가 있으면 실선 박스 + ⓘ + 8방향 핸들 자리 (none 일 때만)
 * - lockKind=base → '공통 레이아웃 편집' 어포던스 + 8방향 핸들 미렌더
 * - lockKind=extension → '확장 편집' 어포던스
 * - lockKind=data_bound → 안내 라벨 (pointer-events: none)
 * - navAffordance=route_in_tree → '→ 이 화면 편집' 버튼
 * - navAffordance=external_url / route_not_in_tree / dynamic_path → 비활성 안내
 * - onSelectParent 제공 → 타입 칩이 클릭 가능한 부모 선택(↑) 버튼 / 미제공 → 클릭 불가 라벨
 *
 * @effects overlapping_child_selected_type_chip_escalates_to_parent, root_node_type_chip_degrades_to_non_clickable_label
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, expect, it, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ElementOverlay } from '../../components/ElementOverlay';
import { OVERLAY_AFFORDANCE } from '../../utils/overlayZIndex';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';

function withTranslation(node: React.ReactElement): React.ReactElement {
  const engine = new TranslationEngine();
  return (
    <TranslationProvider
      translationEngine={engine}
      translationContext={{ templateId: 'test', locale: 'ko' }}
    >
      {node}
    </TranslationProvider>
  );
}

const box = (overrides = {}) => ({
  left: 10,
  top: 20,
  width: 100,
  height: 50,
  scale: 1,
  ...overrides,
});

describe('ElementOverlay — 박스 렌더', () => {
  it('hoverBox 만 있으면 hover 점선만 렌더', () => {
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={box()}
          selectedBox={null}
          lockKind="none"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
        />
      )
    );
    expect(screen.getByTestId('g7le-overlay-hover')).toBeTruthy();
    expect(screen.queryByTestId('g7le-overlay-selected')).toBeNull();
  });

  // 선택된 경우에 한해 요소 위에 컴포넌트 타입(Div/Table 등)을 흐린 오버레이로 표시.
  it('selectedName 제공 → 큰 박스는 안쪽 좌상단 타입 라벨 표시', () => {
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box({ width: 200, height: 120 })} // inside placement
          lockKind="none"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
          selectedName="Table"
        />
      )
    );
    const label = screen.getByTestId('g7le-overlay-type-label');
    expect(label.textContent).toBe('Table');
    expect(label.getAttribute('data-placement')).toBe('inside');
    // pointerEvents none — 클릭/리사이즈 비방해
    expect(getComputedStyle(label).pointerEvents).toBe('none');
  });

  // 너무 작은 엘리먼트는 라벨이 콘텐츠를 가리므로 박스 바깥 좌상단에 표시.
  it('작은 박스(outside placement) → 타입 라벨을 박스 바깥에 표시', () => {
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box({ width: 30, height: 18 })} // 작은 박스 → outside
          lockKind="none"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
          selectedName="Span"
        />
      )
    );
    const label = screen.getByTestId('g7le-overlay-type-label');
    expect(label.textContent).toBe('Span');
    expect(label.getAttribute('data-placement')).toBe('outside');
  });

  // 겹친 부모 선택. onSelectParent 제공 시 타입 칩이 클릭 가능한
  // "부모 선택"(↑) 버튼이 된다(부모/자식 크기 같아 자식만 잡히는 경우 상위 escalation).
  it('onSelectParent 제공 → 타입 칩이 클릭 가능한 부모 선택 버튼(↑ + 클릭→콜백)', () => {
    const onSelectParent = vi.fn();
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box({ width: 200, height: 120 })}
          lockKind="none"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
          selectedName="Li"
          onSelectParent={onSelectParent}
        />
      )
    );
    const chip = screen.getByTestId('g7le-overlay-type-label');
    expect(chip.tagName).toBe('BUTTON');
    expect(chip.textContent).toContain('↑');
    expect(chip.textContent).toContain('Li');
    // 클릭 가능(pointerEvents auto) — 부모 선택 콜백 발동.
    expect(getComputedStyle(chip).pointerEvents).toBe('auto');
    fireEvent.click(chip);
    expect(onSelectParent).toHaveBeenCalledTimes(1);
  });

  it('onSelectParent 미제공(루트 등) → 타입 칩은 클릭 불가 라벨(span, pointerEvents none)', () => {
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box({ width: 200, height: 120 })}
          lockKind="none"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
          selectedName="Div"
        />
      )
    );
    const chip = screen.getByTestId('g7le-overlay-type-label');
    expect(chip.tagName).toBe('SPAN');
    expect(chip.textContent).toBe('Div');
    expect(getComputedStyle(chip).pointerEvents).toBe('none');
  });

  it('hover 만(선택 없음) → 타입 라벨 미표시 (선택된 경우에 한함)', () => {
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={box()}
          selectedBox={null}
          lockKind="none"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
          selectedName="표"
        />
      )
    );
    expect(screen.queryByTestId('g7le-overlay-type-label')).toBeNull();
  });

  it('selectedName 미제공 → 타입 라벨 미렌더', () => {
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box()}
          lockKind="none"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
        />
      )
    );
    expect(screen.queryByTestId('g7le-overlay-type-label')).toBeNull();
  });

  it('selectedBox + lockKind=none + 양축 활성 → 실선 박스 + ⓘ + 8방향 핸들 ', () => {
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box()}
          lockKind="none"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
          resizeEnabledAxes={{ width: true, height: true }}
          onResizeHandlePointerDown={vi.fn()}
        />
      )
    );
    expect(screen.getByTestId('g7le-overlay-selected')).toBeTruthy();
    expect(screen.getByTestId('g7le-overlay-info-button')).toBeTruthy();
    // 양축 활성 → 8방향 핸들 모두 표시 + 활성(pointer-events)
    for (const key of ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w']) {
      const handle = screen.getByTestId(`g7le-resize-handle-${key}`);
      expect(handle).toBeTruthy();
      expect(handle.getAttribute('data-resize-handle-active')).toBe('true');
    }
  });

  it('ⓘ 버튼 + 리사이즈 핸들은 드래그 핸들 위 어포던스 밴드(z-index) — 클릭 가로채기 회귀 차단', () => {
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box()}
          lockKind="none"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
          resizeEnabledAxes={{ width: true, height: true }}
          onResizeHandlePointerDown={vi.fn()}
        />
      )
    );
    // S5b 드래그 핸들이 z-index 미지정(≈0) ⓘ/리사이즈 핸들 위로 올라와 클릭이 이동
    // 포인터에 가로채이던 회귀. 모두 OVERLAY_AFFORDANCE z-index 로 핸들 위에 와야 한다.
    expect(screen.getByTestId('g7le-overlay-info-button').style.zIndex).toBe(String(OVERLAY_AFFORDANCE));
    for (const key of ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w']) {
      expect(screen.getByTestId(`g7le-resize-handle-${key}`).style.zIndex).toBe(
        String(OVERLAY_AFFORDANCE)
      );
    }
  });

  it('큰 박스(inside)에서 ⓘ 버튼이 코너/ne 핸들 바깥 여백으로 밀려난다', () => {
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box({ width: 200, height: 120 })} // inside placement
          lockKind="none"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
          resizeEnabledAxes={{ width: true, height: true }}
          onResizeHandlePointerDown={vi.fn()}
        />
      )
    );
    const info = screen.getByTestId('g7le-overlay-info-button');
    expect(info.dataset.placement).toBe('inside');
    // 종전 -20 은 ne 핸들(-4)·코너와 겹쳤다. -30 으로 박스 밖 대각 여백 확보.
    expect(info.style.right).toBe('-30px');
    expect(info.style.top).toBe('-30px');
  });

  it('스펙이 width/height 컨트롤 미선언(axes 미제공) → 핸들 미표시 ', () => {
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box()}
          lockKind="none"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
        />
      )
    );
    expect(screen.getByTestId('g7le-overlay-selected')).toBeTruthy();
    // 활성 축 없음 → 8방향 핸들 모두 미표시
    for (const key of ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w']) {
      expect(screen.queryByTestId(`g7le-resize-handle-${key}`)).toBeNull();
    }
  });

  it('가로만 선언(width 축) → 좌우 변 핸들만, 모서리/상하 변 핸들 미표시 ', () => {
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box()}
          lockKind="none"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
          resizeEnabledAxes={{ width: true, height: false }}
          onResizeHandlePointerDown={vi.fn()}
        />
      )
    );
    // 가로 변(e/w)만 표시 — 모서리(양축)·세로 변(n/s)은 height 미선언이라 미표시
    expect(screen.getByTestId('g7le-resize-handle-e')).toBeTruthy();
    expect(screen.getByTestId('g7le-resize-handle-w')).toBeTruthy();
    for (const key of ['nw', 'n', 'ne', 'se', 's', 'sw']) {
      expect(screen.queryByTestId(`g7le-resize-handle-${key}`)).toBeNull();
    }
  });
});

describe('ElementOverlay — 잠금 어포던스', () => {
  it('lockKind=base → 공통 레이아웃 편집 어포던스 + 핸들 미렌더', () => {
    const onEditBase = vi.fn();
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box()}
          lockKind="base"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
          onEditBase={onEditBase}
        />
      )
    );
    const editBase = screen.getByTestId('g7le-overlay-edit-base');
    fireEvent.click(editBase);
    expect(onEditBase).toHaveBeenCalledTimes(1);
    // base 잠금 시 8방향 핸들 자리 미렌더 (lockKind=none 일 때만 렌더)
    expect(screen.queryByTestId('g7le-resize-handle-nw')).toBeNull();
    // base 잠금 시 ⓘ 속성 메뉴 미표시
    expect(screen.queryByTestId('g7le-overlay-info-button')).toBeNull();
  });

  it('lockKind=extension → 확장 편집 어포던스', () => {
    const onEditExtension = vi.fn();
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box()}
          lockKind="extension"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
          onEditExtension={onEditExtension}
        />
      )
    );
    fireEvent.click(screen.getByTestId('g7le-overlay-edit-extension'));
    expect(onEditExtension).toHaveBeenCalledTimes(1);
    // 확장 잠금 시 ⓘ 속성 메뉴 미표시 — 속성 편집/복사/삭제 차단, 확장 편집 어포던스만
    expect(screen.queryByTestId('g7le-overlay-info-button')).toBeNull();
    expect(screen.queryByTestId('g7le-overlay-edit-props')).toBeNull();
    // extensionLabel 미제공 시 식별자 칩은 렌더되지 않는다.
    expect(screen.queryByTestId('g7le-overlay-extension-label')).toBeNull();
  });

  it('lockKind=extension + extensionLabel → 확장 편집 버튼에 어느 확장인지 식별자 칩 표시', () => {
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box()}
          lockKind="extension"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
          onEditExtension={vi.fn()}
          extensionLabel="sirsoft-board"
        />
      )
    );
    const chip = screen.getByTestId('g7le-overlay-extension-label');
    expect(chip).toHaveTextContent('sirsoft-board');
    // 칩은 확장 편집 어포던스 버튼 내부에 위치(어느 확장인지를 그 버튼에 명시).
    expect(screen.getByTestId('g7le-overlay-edit-extension')).toContainElement(chip);
  });

  it('lockKind=base + baseLayoutLabel → 공통 레이아웃 편집 버튼에 레이아웃 파일명 칩 표시', () => {
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box()}
          lockKind="base"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
          onEditBase={vi.fn()}
          baseLayoutLabel="_user_base"
        />
      )
    );
    const chip = screen.getByTestId('g7le-overlay-base-layout-label');
    expect(chip).toHaveTextContent('_user_base');
    expect(screen.getByTestId('g7le-overlay-edit-base')).toContainElement(chip);
  });

  it('lockKind=data_bound → ⓘ 속성 메뉴는 허용 (텍스트만 편집 불가)', () => {
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box()}
          lockKind="data_bound"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
        />
      )
    );
    // 데이터 결정 노드는 스타일/배치 편집이 허용되므로 ⓘ 표시 
    expect(screen.getByTestId('g7le-overlay-info-button')).toBeTruthy();
    // 안내 라벨도 함께 표시
    expect(screen.getByTestId('g7le-overlay-data-bound-notice')).toBeTruthy();
    // dataSourceLabel 미제공 시 데이터소스 칩은 렌더되지 않는다.
    expect(screen.queryByTestId('g7le-overlay-data-source-label')).toBeNull();
  });

  it('lockKind=data_bound + dataSourceLabel → 안내에 어느 데이터소스인지 칩 표시', () => {
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box()}
          lockKind="data_bound"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
          dataSourceLabel="recent_posts"
        />
      )
    );
    const chip = screen.getByTestId('g7le-overlay-data-source-label');
    expect(chip).toHaveTextContent('recent_posts');
    expect(screen.getByTestId('g7le-overlay-data-bound-notice')).toContainElement(chip);
    // onEditIteration 미제공(비-반복 data_bound) 시 반복 항목 편집 어포던스 미표시
    expect(screen.queryByTestId('g7le-overlay-edit-iteration')).toBeNull();
  });

  it('data_bound + onEditIteration(반복) → "↳ 반복 항목 편집" 어포던스 표시 + 클릭 시 콜백', () => {
    const onEditIteration = vi.fn();
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box()}
          lockKind="data_bound"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
          dataSourceLabel="recent_posts"
          onEditIteration={onEditIteration}
        />
      )
    );
    const btn = screen.getByTestId('g7le-overlay-edit-iteration');
    fireEvent.click(btn);
    expect(onEditIteration).toHaveBeenCalledTimes(1);
  });

  it('data_bound + 작은 박스(outside) + 타입 라벨 → 안내 라벨을 타입 표식 위로 올려 겹침 회피', () => {
    // 작은 박스(width/height < 44)면 타입 표식이 박스 바깥 좌상단(bottom:100%)에 떠
    // 안내 라벨(top:-28)과 겹친다. 이때 안내 라벨 top 을 더 올린다.
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box({ width: 30, height: 18 })}
          lockKind="data_bound"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
          selectedName="Input"
          dataSourceLabel="profile"
        />
      )
    );
    const notice = screen.getByTestId('g7le-overlay-data-bound-notice');
    // 타입 표식 위로 올라간 위치(-50). 기본(-28)이 아니어야 한다.
    expect(notice.style.top).toBe('-50px');
    // 타입 표식도 outside 로 렌더(겹침 전제 성립 확인)
    expect(screen.getByTestId('g7le-overlay-type-label').getAttribute('data-placement')).toBe('outside');
  });

  it('data_bound + 큰 박스(inside) → 안내 라벨은 기본 위치(-28) 유지', () => {
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box({ width: 200, height: 80 })}
          lockKind="data_bound"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
          selectedName="Input"
          dataSourceLabel="profile"
        />
      )
    );
    expect(screen.getByTestId('g7le-overlay-data-bound-notice').style.top).toBe('-28px');
  });

  it('lockKind=extension → ⓘ 클릭으로도 컨텍스트 메뉴가 열리지 않음(메뉴 자체 미마운트)', () => {
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box()}
          lockKind="extension"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
          onEditExtension={vi.fn()}
        />
      )
    );
    // ⓘ 버튼 자체가 없으므로 컨텍스트 메뉴(속성 설정/복사/삭제) 진입 경로가 차단됨
    expect(screen.queryByTestId('g7le-overlay-info-button')).toBeNull();
  });

  it('lockKind=data_bound → 안내 라벨', () => {
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box()}
          lockKind="data_bound"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
        />
      )
    );
    expect(screen.getByTestId('g7le-overlay-data-bound-notice')).toBeTruthy();
  });
});

describe('ElementOverlay — 네비게이션 어포던스', () => {
  it('navAffordance=route_in_tree → 활성 버튼, 클릭 시 콜백', () => {
    const onLink = vi.fn();
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box()}
          lockKind="none"
          navAffordance="route_in_tree"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={onLink}
        />
      )
    );
    fireEvent.click(screen.getByTestId('g7le-overlay-link-edit-destination'));
    expect(onLink).toHaveBeenCalledTimes(1);
  });

  it.each(['external_url', 'route_not_in_tree', 'dynamic_path'] as const)(
    'navAffordance=%s → 어포던스 미표시',
    (kind) => {
      render(
        withTranslation(
          <ElementOverlay
            hoverBox={null}
            selectedBox={box()}
            lockKind="none"
            navAffordance={kind}
            onEditProps={vi.fn()}
            onDuplicate={vi.fn()}
            onDelete={vi.fn()}
            onLinkEditDestination={vi.fn()}
          />
        )
      );
      // "⊘ 이동할 수 없습니다" 안내도, "이 화면 편집" 버튼도 모두 미표시.
      expect(screen.queryByTestId('g7le-overlay-destination-unreachable')).toBeNull();
      expect(screen.queryByTestId('g7le-overlay-link-edit-destination')).toBeNull();
      // 선택 박스 자체는 정상 — 일반 노드처럼 선택·편집 가능.
      expect(screen.getByTestId('g7le-overlay-selected')).toBeTruthy();
    }
  );
});

describe('ElementOverlay — ⓘ 메뉴 토글 → ContextMenu 노출', () => {
  it('초기 메뉴 미표시, ⓘ 클릭 시 메뉴 표시, 메뉴 항목 3개', () => {
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box()}
          lockKind="none"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
        />
      )
    );
    expect(screen.queryByTestId('g7le-context-menu')).toBeNull();
    fireEvent.click(screen.getByTestId('g7le-overlay-info-button'));
    expect(screen.getByTestId('g7le-context-menu')).toBeTruthy();
    expect(screen.getByTestId('g7le-context-menu-edit-props')).toBeTruthy();
    expect(screen.getByTestId('g7le-context-menu-duplicate')).toBeTruthy();
    expect(screen.getByTestId('g7le-context-menu-delete')).toBeTruthy();
  });
});

describe('ElementOverlay — 디바이스 분리 버튼(안 A ③④)', () => {
  it('onSeparateBranch 제공 → 하단 별도 분리 버튼 표시 + 클릭 콜백', () => {
    const onSeparateBranch = vi.fn();
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box({ width: 200, height: 120 })}
          lockKind="none"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
          onSeparateBranch={onSeparateBranch}
          separateBranchLabel="이 영역을 모바일 전용으로 분리"
        />
      )
    );
    const btn = screen.getByTestId('g7le-overlay-separate-branch');
    expect(btn.textContent).toContain('모바일');
    expect(btn.style.pointerEvents).toBe('auto');
    fireEvent.click(btn);
    expect(onSeparateBranch).toHaveBeenCalledTimes(1);
  });

  it('onMergeBranch 제공 → 분리 해제 버튼 표시 + 클릭 콜백', () => {
    const onMergeBranch = vi.fn();
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box({ width: 200, height: 120 })}
          lockKind="none"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
          onMergeBranch={onMergeBranch}
          mergeBranchLabel="모바일 전용 분리 해제"
        />
      )
    );
    expect(screen.queryByTestId('g7le-overlay-separate-branch')).toBeNull();
    const btn = screen.getByTestId('g7le-overlay-merge-branch');
    fireEvent.click(btn);
    expect(onMergeBranch).toHaveBeenCalledTimes(1);
  });

  it('둘 다 미제공이면 분리 버튼 부재(회귀 가드 — 일반 노드 영향 0)', () => {
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box({ width: 200, height: 120 })}
          lockKind="none"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
        />
      )
    );
    expect(screen.queryByTestId('g7le-overlay-separate-branch')).toBeNull();
    expect(screen.queryByTestId('g7le-overlay-merge-branch')).toBeNull();
  });
});

describe('ElementOverlay — 정의된 디바이스 구성 점프 버튼군', () => {
  it('definedDeviceBranches 제공 → 각 디바이스 점프 버튼 표시 + 클릭 콜백(키 전달)', () => {
    const onJumpToDevice = vi.fn();
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box({ width: 200, height: 120 })}
          lockKind="none"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
          definedDeviceBranches={[
            { key: 'portable', label: '모바일' },
            { key: '600-900', label: '600-900' },
          ]}
          onJumpToDevice={onJumpToDevice}
        />
      )
    );
    // 다중 디바이스 구성(커스텀 포함)마다 키별 고유 testid 버튼이 렌더된다.
    const b1 = screen.getByTestId('g7le-overlay-jump-device-portable');
    const b2 = screen.getByTestId('g7le-overlay-jump-device-600-900');
    expect(b1.style.pointerEvents).toBe('auto');
    expect(b2.style.pointerEvents).toBe('auto');
    // 클릭 시 해당 디바이스 키가 콜백으로 전달된다.
    fireEvent.click(b1);
    expect(onJumpToDevice).toHaveBeenCalledWith('portable');
    fireEvent.click(b2);
    expect(onJumpToDevice).toHaveBeenCalledWith('600-900');
  });

  it('definedDeviceBranches 미제공 → 점프 버튼 부재(회귀 가드)', () => {
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box({ width: 200, height: 120 })}
          lockKind="none"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
        />
      )
    );
    expect(screen.queryByTestId('g7le-overlay-jump-device-portable')).toBeNull();
  });

  // 이동+분리 버튼이 동시에 뜰 때 가로 겹침 금지 → 세로 스택 컨테이너에
  // 함께 담겨 여러 줄로 쌓인다(개별 절대위치 금지). 컨테이너 1개 + 자식 버튼들 구조 가드.
  it('점프 + 분리 버튼 공존 시 한 세로 스택 컨테이너에 담긴다(겹침 방지)', () => {
    render(
      withTranslation(
        <ElementOverlay
          hoverBox={null}
          selectedBox={box({ width: 200, height: 120 })}
          lockKind="none"
          navAffordance="none"
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onLinkEditDestination={vi.fn()}
          definedDeviceBranches={[{ key: 'portable', label: '모바일+태블릿' }]}
          onJumpToDevice={vi.fn()}
          onSeparateBranch={vi.fn()}
          separateBranchLabel="이 영역을 모바일 전용으로 분리"
        />
      )
    );
    const container = screen.getByTestId('g7le-overlay-branch-affordances');
    expect(container).toBeInTheDocument();
    // column flow — 자식 버튼은 개별 absolute 가 아니라 컨테이너 흐름에 쌓인다.
    expect(container.style.flexDirection).toBe('column');
    const jump = screen.getByTestId('g7le-overlay-jump-device-portable');
    const sep = screen.getByTestId('g7le-overlay-separate-branch');
    // 둘 다 같은 컨테이너의 자식이어야 한다(겹침 방지 — 별도 absolute 레인 금지).
    expect(container.contains(jump)).toBe(true);
    expect(container.contains(sep)).toBe(true);
    // 자식 버튼은 자체 absolute 위치를 갖지 않는다(컨테이너가 위치 담당).
    expect(jump.style.position).not.toBe('absolute');
    expect(sep.style.position).not.toBe('absolute');
  });
});
