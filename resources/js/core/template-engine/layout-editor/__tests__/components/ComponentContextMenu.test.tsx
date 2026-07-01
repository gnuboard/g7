/**
 * ComponentContextMenu 컴포넌트 테스트
 *
 * 메뉴 항목은 정확히 3가지: 속성 설정 / 컴포넌트 복사 / 삭제.
 * 목록·표·이미지 삽입 등 다른 항목은 메뉴에 없어야 함 (회귀 가드).
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, expect, it, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ComponentContextMenu } from '../../components/ComponentContextMenu';
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

describe('ComponentContextMenu — 항목 구성', () => {
  it('open=true 이면 정확히 3 항목만 렌더', () => {
    render(
      withTranslation(
        <ComponentContextMenu
          anchor={{ left: 0, top: 0 }}
          open={true}
          onClose={vi.fn()}
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
        />
      )
    );
    const menu = screen.getByTestId('g7le-context-menu');
    const items = menu.querySelectorAll('[role="menuitem"]');
    expect(items.length).toBe(3);
    expect(screen.getByTestId('g7le-context-menu-edit-props')).toBeTruthy();
    expect(screen.getByTestId('g7le-context-menu-duplicate')).toBeTruthy();
    expect(screen.getByTestId('g7le-context-menu-delete')).toBeTruthy();
  });

  it('open=false 또는 anchor=null 이면 렌더 자체를 안 함', () => {
    const { rerender, container } = render(
      withTranslation(
        <ComponentContextMenu
          anchor={null}
          open={true}
          onClose={vi.fn()}
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
        />
      )
    );
    expect(container.querySelector('[data-testid="g7le-context-menu"]')).toBeNull();

    rerender(
      withTranslation(
        <ComponentContextMenu
          anchor={{ left: 0, top: 0 }}
          open={false}
          onClose={vi.fn()}
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
        />
      )
    );
    expect(container.querySelector('[data-testid="g7le-context-menu"]')).toBeNull();
  });
});

describe('ComponentContextMenu — 디바이스 분리 항목', () => {
  it('onSeparateBranch 제공 시 분리 생성 항목 추가(삭제 앞)', () => {
    const onSeparateBranch = vi.fn();
    render(
      withTranslation(
        <ComponentContextMenu
          anchor={{ left: 0, top: 0 }}
          open={true}
          onClose={vi.fn()}
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onSeparateBranch={onSeparateBranch}
          separateBranchLabel="이 영역을 모바일 전용으로 분리"
        />
      )
    );
    const menu = screen.getByTestId('g7le-context-menu');
    expect(menu.querySelectorAll('[role="menuitem"]').length).toBe(4);
    const sep = screen.getByTestId('g7le-context-menu-separate-branch');
    expect(sep.textContent).toContain('모바일');
    // 삭제 항목이 마지막(분리 항목 뒤)
    const items = Array.from(menu.querySelectorAll('[role="menuitem"]'));
    expect(items[items.length - 1]?.getAttribute('data-testid')).toBe('g7le-context-menu-delete');
    fireEvent.click(sep);
    expect(onSeparateBranch).toHaveBeenCalledTimes(1);
  });

  it('onMergeBranch 제공 시 분리 해제 항목 추가', () => {
    const onMergeBranch = vi.fn();
    render(
      withTranslation(
        <ComponentContextMenu
          anchor={{ left: 0, top: 0 }}
          open={true}
          onClose={vi.fn()}
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
          onMergeBranch={onMergeBranch}
          mergeBranchLabel="모바일 전용 분리 해제"
        />
      )
    );
    expect(screen.getByTestId('g7le-context-menu-merge-branch')).toBeTruthy();
    expect(screen.queryByTestId('g7le-context-menu-separate-branch')).toBeNull();
    fireEvent.click(screen.getByTestId('g7le-context-menu-merge-branch'));
    expect(onMergeBranch).toHaveBeenCalledTimes(1);
  });

  it('둘 다 미제공이면 분리 항목 없음(기본 3항목 — 회귀 가드)', () => {
    render(
      withTranslation(
        <ComponentContextMenu
          anchor={{ left: 0, top: 0 }}
          open={true}
          onClose={vi.fn()}
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
        />
      )
    );
    expect(screen.queryByTestId('g7le-context-menu-separate-branch')).toBeNull();
    expect(screen.queryByTestId('g7le-context-menu-merge-branch')).toBeNull();
  });
});

describe('ComponentContextMenu — 클릭 콜백', () => {
  it('각 항목 클릭 → 콜백 + onClose 동시 호출', () => {
    const onClose = vi.fn();
    const onEditProps = vi.fn();
    const onDuplicate = vi.fn();
    const onDelete = vi.fn();
    render(
      withTranslation(
        <ComponentContextMenu
          anchor={{ left: 0, top: 0 }}
          open={true}
          onClose={onClose}
          onEditProps={onEditProps}
          onDuplicate={onDuplicate}
          onDelete={onDelete}
        />
      )
    );
    fireEvent.click(screen.getByTestId('g7le-context-menu-edit-props'));
    expect(onEditProps).toHaveBeenCalledTimes(1);
    expect(onClose).toHaveBeenCalledTimes(1);

    fireEvent.click(screen.getByTestId('g7le-context-menu-duplicate'));
    expect(onDuplicate).toHaveBeenCalledTimes(1);

    fireEvent.click(screen.getByTestId('g7le-context-menu-delete'));
    expect(onDelete).toHaveBeenCalledTimes(1);
  });
});

describe('ComponentContextMenu — 금지 항목 부재 (회귀 가드)', () => {
  it('목록/표/이미지 삽입 등 별도 항목이 메뉴에 없음', () => {
    render(
      withTranslation(
        <ComponentContextMenu
          anchor={{ left: 0, top: 0 }}
          open={true}
          onClose={vi.fn()}
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
        />
      )
    );
    // 미지정 testid 패턴이 발생하지 않아야 함
    expect(screen.queryByTestId('g7le-context-menu-insert-list')).toBeNull();
    expect(screen.queryByTestId('g7le-context-menu-insert-table')).toBeNull();
    expect(screen.queryByTestId('g7le-context-menu-insert-image')).toBeNull();
  });
});

describe('ComponentContextMenu — pointer-events 회귀 가드 (engine-v1.50.0)', () => {
  /**
   * 증상: 메뉴 컨테이너/항목이 EditorCanvasOverlay/ElementOverlay 의
   * `pointer-events: none` 상속으로 hit-test 에서 투명해져 마우스가 통과 → 클릭/hover 미반응.
   * 해결: 컨테이너와 각 항목 button 에 inline `pointer-events: auto` 명시.
   */
  it('메뉴 컨테이너 inline style 에 pointer-events: auto 가 명시되어 있음', () => {
    render(
      withTranslation(
        <ComponentContextMenu
          anchor={{ left: 0, top: 0 }}
          open={true}
          onClose={vi.fn()}
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
        />
      )
    );
    const menu = screen.getByTestId('g7le-context-menu') as HTMLElement;
    expect(menu.style.pointerEvents).toBe('auto');
  });

  it('각 메뉴 항목 button inline style 에 pointer-events: auto 가 명시되어 있음', () => {
    render(
      withTranslation(
        <ComponentContextMenu
          anchor={{ left: 0, top: 0 }}
          open={true}
          onClose={vi.fn()}
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
        />
      )
    );
    for (const tid of [
      'g7le-context-menu-edit-props',
      'g7le-context-menu-duplicate',
      'g7le-context-menu-delete',
    ]) {
      const btn = screen.getByTestId(tid) as HTMLElement;
      expect(btn.style.pointerEvents).toBe('auto');
    }
  });
});

describe('ComponentContextMenu — hover 강조 (engine-v1.50.0)', () => {
  /**
   * 증상: inline style 에는 :hover 의사클래스를 표현할 수 없어 마우스를 올려도
   * 시각 강조가 없음.
   * 해결: 내부 state `hoverIndex` + onMouseEnter/Leave/Focus 로 background/color 토글.
   */
  it('onMouseEnter 시 background 가 transparent 에서 색상으로 변함', () => {
    render(
      withTranslation(
        <ComponentContextMenu
          anchor={{ left: 0, top: 0 }}
          open={true}
          onClose={vi.fn()}
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
        />
      )
    );
    const item = screen.getByTestId('g7le-context-menu-duplicate') as HTMLElement;
    expect(item.style.background).toBe('transparent');
    fireEvent.mouseEnter(item);
    expect(item.style.background).not.toBe('transparent');
  });

  it('hoverIndex 가 상호 배타 — 한 번에 한 항목만 강조', () => {
    render(
      withTranslation(
        <ComponentContextMenu
          anchor={{ left: 0, top: 0 }}
          open={true}
          onClose={vi.fn()}
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
        />
      )
    );
    const edit = screen.getByTestId('g7le-context-menu-edit-props') as HTMLElement;
    const dup = screen.getByTestId('g7le-context-menu-duplicate') as HTMLElement;
    fireEvent.mouseEnter(dup);
    expect(dup.style.background).not.toBe('transparent');
    expect(edit.style.background).toBe('transparent');
    fireEvent.mouseEnter(edit);
    expect(edit.style.background).not.toBe('transparent');
    expect(dup.style.background).toBe('transparent');
  });

  it('삭제 항목은 hover 시 별도 색상 계열 사용 (danger)', () => {
    render(
      withTranslation(
        <ComponentContextMenu
          anchor={{ left: 0, top: 0 }}
          open={true}
          onClose={vi.fn()}
          onEditProps={vi.fn()}
          onDuplicate={vi.fn()}
          onDelete={vi.fn()}
        />
      )
    );
    const del = screen.getByTestId('g7le-context-menu-delete') as HTMLElement;
    fireEvent.mouseEnter(del);
    // danger 계열 색상 — 본 테스트는 hover bg 가 일반 항목과 달라야 함을 검증
    const dup = screen.getByTestId('g7le-context-menu-duplicate') as HTMLElement;
    fireEvent.mouseEnter(dup);
    fireEvent.mouseEnter(del); // 다시 delete 로 — 색상 검증
    expect(del.style.background).not.toBe(dup.style.background);
  });
});
