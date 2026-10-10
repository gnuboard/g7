/**
 * @file ComponentDisabledContract.test.tsx
 * @description 컴포넌트 disabled 계약 (7.1.0 abilities 게이트 G09·G11·#6·#7·#16·N8)
 *
 * 레이아웃은 읽기 전용 화면에서 입력 컴포넌트에 `disabled` 를 넘긴다. 컴포넌트가 그 값을 무시하면
 * 권한 없는 운영자에게 편집·추가·삭제·대표 지정이 살아 있다(저장만 403). 여기서는 컴포넌트가
 * `disabled` 를 받으면 쓰기 동작을 모두 막는지를 잠근다.
 */
import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { HtmlEditor } from '../HtmlEditor';
import { ChipCheckbox } from '../ChipCheckbox';
import { DynamicFieldList } from '../DynamicFieldList/DynamicFieldList';
import { SortableThumbnailItem } from '../FileUploader/SortableThumbnailItem';
import { SortableMenuItem } from '../SortableMenuItem';
import { TabNavigationScroll } from '../TabNavigationScroll';

vi.mock('@dnd-kit/sortable', async (importOriginal) => {
  const actual = await importOriginal<any>();
  return {
    ...actual,
    useSortable: () => ({
      attributes: {},
      listeners: {},
      setNodeRef: () => {},
      transform: null,
      transition: null,
      isDragging: false,
    }),
  };
});

describe('컴포넌트 disabled 계약', () => {
  // @scenario component=HtmlEditor,disabled=true
  // @effects html_editor_disabled_blocks_editing
  it('HtmlEditor 는 disabled 를 readOnly 와 같이 다룬다 — 본문을 편집할 수 없다', () => {
    const onChange = vi.fn();
    const { container } = render(<HtmlEditor content="본문" disabled onChange={onChange} />);

    const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
    expect(textarea).not.toBeNull();
    expect(textarea.readOnly || textarea.disabled).toBe(true);
  });

  // @scenario component=ChipCheckbox,disabled=true
  // @effects chip_checkbox_disabled_blocks_toggle
  it('ChipCheckbox 는 disabled 면 체크 입력을 막고 onChange 를 부르지 않는다', () => {
    const onChange = vi.fn();
    const { container } = render(<ChipCheckbox value="a" label="라벨" disabled onChange={onChange} />);

    const input = container.querySelector('input[type="checkbox"]') as HTMLInputElement;
    expect(input.disabled).toBe(true);
    fireEvent.click(screen.getByText('라벨'));
    expect(onChange).not.toHaveBeenCalled();
  });

  // @scenario component=DynamicFieldList,disabled=true
  // @effects dynamic_field_list_disabled_blocks_add_remove
  it('DynamicFieldList 는 disabled 를 readOnly 와 같이 다룬다 — 추가 버튼이 없다', () => {
    render(
      <DynamicFieldList
        items={[{ _id: '1', name: '항목' }]}
        columns={[{ key: 'name', label: '이름', type: 'input' }] as any}
        addLabel="항목 추가"
        disabled
      />
    );

    expect(screen.queryByText('항목 추가')).toBeNull();
  });

  // @scenario component=SortableThumbnailItem,disabled=true
  // @effects thumbnail_primary_button_disabled
  it('대표 이미지(★) 버튼은 disabled 면 눌리지 않는다', () => {
    const onPrimaryClick = vi.fn();
    const { container } = render(
      <SortableThumbnailItem
        file={{ id: 1, hash: 'h1', original_filename: 'a.png', mime_type: 'image/png', size: 1, download_url: '/a.png', is_image: true } as any}
        onRemove={vi.fn()}
        isPrimary
        onPrimaryClick={onPrimaryClick}
        disabled
      />
    );

    const star = container.querySelector('[data-uploader-action="primary"]') as HTMLButtonElement;
    expect(star).not.toBeNull();
    expect(star.disabled).toBe(true);
    fireEvent.click(star);
    expect(onPrimaryClick).not.toHaveBeenCalled();
  });

  // @scenario component=SortableMenuItem,enableDrag=false,has_children=true
  // @effects sortable_menu_item_expands_without_drag
  it('SortableMenuItem 은 끌기를 끈(읽기 전용) 상태에서도 하위 항목 펼침 버튼을 보인다', () => {
    const onExpandToggle = vi.fn();
    render(
      <SortableMenuItem
        item={{ id: 1, name: '부모', hasChildren: true } as any}
        enableDrag={false}
        isExpanded={false}
        onExpandToggle={onExpandToggle}
      />
    );

    const toggle = screen.getByRole('button', { expanded: false });
    fireEvent.click(toggle);
    expect(onExpandToggle).toHaveBeenCalledTimes(1);
  });

  // @scenario component=TabNavigationScroll,tabs=contains_null
  // @effects tab_navigation_scroll_ignores_null_tabs
  it('TabNavigationScroll 은 탭 목록에 null 이 섞여도 오류 없이 나머지를 그린다', () => {
    render(<TabNavigationScroll tabs={[null as any, { id: 'a', label: '탭 A' }]} activeTabId="a" />);

    expect(screen.getAllByText('탭 A').length).toBeGreaterThan(0);
  });
});
