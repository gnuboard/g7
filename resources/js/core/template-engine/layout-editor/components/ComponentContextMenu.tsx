/**
 * ComponentContextMenu.tsx — 선택 컴포넌트 우상단 ⓘ 드롭다운
 *
 * 메뉴 항목은 정확히 3가지:
 *  - 속성 설정 (속성 편집 모달 — Phase 4 위임)
 *  - 컴포넌트 복사 (layoutTreeUtils.duplicateNode + 형제로 삽입)
 *  - 삭제 (layoutTreeUtils.removeNode)
 *
 * 목록·표·이미지 삽입 등은 요소 추가 팔레트 + 컨텍스트 + 버튼으로만 처리.
 *
 * pointer-events 주의: 본 메뉴는 `pointer-events: none` 으로 설정된
 * EditorCanvasOverlay/ElementOverlay 의 자손이라 컨테이너와 항목 button 에
 * 명시적으로 `pointer-events: auto` 를 지정하지 않으면 hit-test 에서 투명해져
 * 마우스가 통과한다(클릭/hover 모두 미반응). hover 강조는 inline style :hover
 * 의사클래스를 표현할 수 없으므로 onMouseEnter/Leave 기반 state 토글로 처리.
 *
 * @since engine-v1.50.0
 */

import React, { useState } from 'react';
import { useTranslation } from '../../TranslationContext';

export interface ComponentContextMenuProps {
  /** 메뉴 위치 (frame 기준 absolute) */
  anchor: { left: number; top: number } | null;
  /** 메뉴 표시 여부 */
  open: boolean;
  /** 메뉴 닫기 */
  onClose: () => void;
  /** 속성 설정 클릭 */
  onEditProps: () => void;
  /** 컴포넌트 복사 클릭 */
  onDuplicate: () => void;
  /** 삭제 클릭 */
  onDelete: () => void;
  /**
   * 디바이스 전용 '분리 생성' 클릭. 현재 디바이스 보기에서
   * base(맨바탕) 구성을 보는 컨테이너 노드일 때만 제공된다. 라벨은 현재 디바이스
   * 명을 포함(예: "모바일 전용으로 분리"). 미제공(undefined) 시 항목 미표시.
   */
  onSeparateBranch?: () => void;
  /** '분리 생성' 메뉴 라벨(현재 디바이스명 포함). onSeparateBranch 동반 시 필수. */
  separateBranchLabel?: string;
  /**
   * 디바이스 전용 '분리 해제' 클릭. 현재 디바이스 전용 분기가
   * 이미 존재할 때만 제공. 미제공 시 항목 미표시. onSeparateBranch 와 상호배타.
   */
  onMergeBranch?: () => void;
  /** '분리 해제' 메뉴 라벨. onMergeBranch 동반 시 필수. */
  mergeBranchLabel?: string;
}

export function ComponentContextMenu(props: ComponentContextMenuProps): React.ReactElement | null {
  const {
    anchor,
    open,
    onClose,
    onEditProps,
    onDuplicate,
    onDelete,
    onSeparateBranch,
    separateBranchLabel,
    onMergeBranch,
    mergeBranchLabel,
  } = props;
  const { t } = useTranslation();
  const [hoverIndex, setHoverIndex] = useState<number | null>(null);

  if (!open || !anchor) {
    return null;
  }

  const items: Array<{
    index: number;
    testid: string;
    label: string;
    onSelect: () => void;
    danger?: boolean;
    /** 항목 hover 툴팁(title) — props vs children 분리 차이 안내 등. */
    hint?: string;
  }> = [
    {
      index: 0,
      testid: 'g7le-context-menu-edit-props',
      label: t('layout_editor.context_menu.edit_props'),
      onSelect: onEditProps,
    },
    {
      index: 1,
      testid: 'g7le-context-menu-duplicate',
      label: t('layout_editor.context_menu.duplicate'),
      onSelect: onDuplicate,
    },
  ];

  // 디바이스 전용 분리 생성/해제 — 상태에 따라 둘 중 하나만.
  if (onSeparateBranch) {
    items.push({
      index: items.length,
      testid: 'g7le-context-menu-separate-branch',
      label: separateBranchLabel ?? t('layout_editor.context_menu.separate_branch'),
      onSelect: onSeparateBranch,
      hint: t('layout_editor.context_menu.separate_branch_hint'),
    });
  } else if (onMergeBranch) {
    items.push({
      index: items.length,
      testid: 'g7le-context-menu-merge-branch',
      label: mergeBranchLabel ?? t('layout_editor.context_menu.merge_branch'),
      onSelect: onMergeBranch,
      hint: t('layout_editor.context_menu.merge_branch_hint'),
    });
  }

  items.push({
    index: items.length,
    testid: 'g7le-context-menu-delete',
    label: t('layout_editor.context_menu.delete'),
    onSelect: onDelete,
    danger: true,
  });

  return (
    <div
      className="g7le-context-menu"
      data-testid="g7le-context-menu"
      role="menu"
      style={{
        position: 'absolute',
        left: anchor.left,
        top: anchor.top,
        background: '#fff',
        border: '1px solid #cbd5e1',
        borderRadius: 6,
        boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
        zIndex: 1200,
        minWidth: 160,
        padding: 4,
        pointerEvents: 'auto',
      }}
      onMouseLeave={() => {
        setHoverIndex(null);
        onClose();
      }}
    >
      {items.map((item) => {
        const isHover = hoverIndex === item.index;
        const baseColor = item.danger ? '#dc2626' : '#0f172a';
        const hoverBg = item.danger ? '#fee2e2' : '#e0e7ff';
        const hoverColor = item.danger ? '#b91c1c' : '#1d4ed8';
        return (
          <button
            key={item.testid}
            type="button"
            role="menuitem"
            data-testid={item.testid}
            title={item.hint}
            onClick={(e) => {
              e.stopPropagation();
              item.onSelect();
              setHoverIndex(null);
              onClose();
            }}
            onMouseEnter={() => setHoverIndex(item.index)}
            onFocus={() => setHoverIndex(item.index)}
            style={{
              ...menuItemStyle,
              color: isHover ? hoverColor : baseColor,
              background: isHover ? hoverBg : 'transparent',
            }}
          >
            {item.label}
          </button>
        );
      })}
    </div>
  );
}

const menuItemStyle: React.CSSProperties = {
  display: 'block',
  width: '100%',
  textAlign: 'left',
  padding: '6px 12px',
  border: 'none',
  fontSize: 13,
  cursor: 'pointer',
  borderRadius: 4,
  pointerEvents: 'auto',
  transition: 'background-color 120ms ease, color 120ms ease',
};
