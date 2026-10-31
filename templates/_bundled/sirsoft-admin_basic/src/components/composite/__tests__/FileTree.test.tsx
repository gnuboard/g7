import React from 'react';
import { render, screen, fireEvent, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { FileTree, type FileTreeNode } from '../FileTree';

/**
 * FileTree — 평면 목록을 폴더 트리로 세우는 좌측 패널의 계약.
 *
 * 종전 좌측 목록은 144개 파일이 한 줄씩 늘어선 평면 리스트였고 `<div>` + click 이라 키보드로는
 * 닿을 수 없었다. 여기서 잠그는 것은 그 두 가지다 — **경로가 폴더가 되는 규칙**과
 * **`role="tree"` 규약**.
 */

const NODES: FileTreeNode[] = [
  { key: 'layout:admin_dashboard', kind: 'layout', path: 'admin_dashboard' },
  { key: 'layout:admin_user_list', kind: 'layout', path: 'admin_user_list' },
  { key: 'layout:partials/admin_user_list/_modal_form', kind: 'layout', path: 'partials/admin_user_list/_modal_form' },
  { key: 'layout:partials/admin_user_list/_partial_filters', kind: 'layout', path: 'partials/admin_user_list/_partial_filters' },
  { key: 'layout:errors/404', kind: 'layout', path: 'errors/404' },
  {
    key: 'ext:12',
    kind: 'extension',
    path: 'user_form',
    label: 'user_form',
    group: 'shop',
    groupKind: 'module',
    badges: [{ text: '덮어쓰기', tone: 'indigo' }],
  },
];

beforeEach(() => {
  window.localStorage.clear();
});

/** 지금 보이는 파일 행의 이름 목록 (폴더·구역 제외). */
function visibleFileLabels(): string[] {
  return screen
    .getAllByRole('treeitem')
    .filter((el) => !el.hasAttribute('aria-expanded'))
    .map((el) => el.textContent?.trim() ?? '');
}

describe('FileTree — 트리 변환', () => {
  /** @effects file_tree_builds_folders_from_slashes */
  // @scenario depth=two_levels, input=mouse
  it('이름의 슬래시로 폴더를 만든다 — 깊이 3까지', () => {
    render(<FileTree nodes={NODES} />);

    // 최상위에 폴더가 서고, 그 안의 파일은 접혀 있어 보이지 않는다.
    expect(screen.getByText('partials')).toBeInTheDocument();
    expect(screen.getByText('errors')).toBeInTheDocument();
    expect(visibleFileLabels().some((label) => label.includes('_modal_form'))).toBe(false);

    fireEvent.click(screen.getByText('partials'));

    // 같은 이름의 파일과 폴더가 함께 있다 — 폴더 행은 aria-expanded 를 갖는 쪽이다.
    const folderRow = screen
      .getAllByRole('treeitem')
      .find((el) => el.hasAttribute('aria-expanded') && el.textContent?.includes('admin_user_list')) as HTMLElement;
    fireEvent.click(folderRow);

    expect(visibleFileLabels().some((label) => label.includes('_modal_form'))).toBe(true);
  });

  /** @effects file_tree_shows_last_segment_only */
  // @scenario depth=root, input=mouse
  it('파일 행에는 마지막 세그먼트만 보인다 — 전체 경로는 title 로 남긴다', () => {
    render(<FileTree nodes={NODES} searchKeyword="_modal_form" />);

    const row = screen.getByTitle('partials/admin_user_list/_modal_form');

    expect(row.textContent).toContain('_modal_form');
    expect(row.textContent).not.toContain('partials/');
  });

  /** @effects file_tree_indents_16px_per_depth */
  // @scenario depth=two_levels, input=mouse
  it('깊이마다 16px 씩 들여쓴다 (§7.6)', () => {
    render(<FileTree nodes={NODES} />);

    fireEvent.click(screen.getByText('partials'));
    const folderRow = screen
      .getAllByRole('treeitem')
      .find((el) => el.hasAttribute('aria-expanded') && el.textContent?.includes('admin_user_list')) as HTMLElement;
    fireEvent.click(folderRow);

    const px = (el: HTMLElement): number => parseFloat(el.style.paddingLeft);
    const partialsRow = screen.getByText('partials').closest('[role="treeitem"]') as HTMLElement;
    const fileRow = screen.getByTitle('partials/admin_user_list/_modal_form').closest('[role="treeitem"]') as HTMLElement;

    // partials(폴더) → admin_user_list(폴더) → _modal_form(파일): 두 단계 = 32px
    expect(px(fileRow) - px(partialsRow)).toBe(32);
    expect(px(folderRow) - px(partialsRow)).toBe(16);
  });

  /** @effects file_tree_groups_extensions_by_source */
  // @scenario depth=one_level, input=mouse
  it('확장 조각은 준 확장(모듈·플러그인)으로 묶는다', () => {
    render(<FileTree nodes={NODES} />);

    expect(screen.getByText('shop')).toBeInTheDocument();
  });

  /** @effects file_tree_shows_pending_folders */
  it('파일이 아직 없는 가상 폴더도 트리에 보인다', () => {
    render(<FileTree nodes={NODES} pendingFolders={['partials/brand_new']} />);

    fireEvent.click(screen.getByText('partials'));

    expect(screen.getByText('brand_new')).toBeInTheDocument();
  });
});

describe('FileTree — 미저장 표시', () => {
  /**
   * 미저장 표시는 색 하나에 기대지 않는다 — 점 모양 · 굵기 · `aria-label` 셋이 함께 선다.
   *
   * @effects file_tree_marks_dirty_files_three_ways
   */
  it('미저장 파일에 점과 접근성 라벨이 함께 붙는다', () => {
    render(<FileTree nodes={NODES} dirtyKeys={['layout:admin_user_list']} />);

    const dot = screen.getByLabelText('admin.templates.layout_edit.tree.unsaved');

    expect(dot).toBeInTheDocument();

    const row = screen.getByTitle('admin_user_list');
    expect(row.className).toContain('font-semibold');
  });

  /** @effects file_tree_counts_dirty_descendants_on_folders */
  it('상위 폴더에는 자손의 미저장 개수가 배지로 붙는다', () => {
    render(
      <FileTree
        nodes={NODES}
        dirtyKeys={[
          'layout:partials/admin_user_list/_modal_form',
          'layout:partials/admin_user_list/_partial_filters',
        ]}
      />,
    );

    const badge = screen.getByLabelText('admin.templates.layout_edit.tree.unsaved_count');

    expect(badge.textContent).toBe('2');
  });
});

describe('FileTree — 검색', () => {
  /** @effects file_tree_filters_by_name_and_keeps_parents */
  it('이름으로 거르되 부모 폴더는 남긴다', () => {
    render(<FileTree nodes={NODES} searchKeyword="_modal_form" />);

    // 걸린 파일과 그 부모 폴더만 남는다.
    expect(screen.getByText('partials')).toBeInTheDocument();
    expect(screen.getByTitle('partials/admin_user_list/_modal_form')).toBeInTheDocument();
    expect(screen.queryByTitle('admin_dashboard')).not.toBeInTheDocument();
  });

  /** @effects file_tree_highlights_name_matches */
  it('걸린 조각을 강조한다', () => {
    render(<FileTree nodes={NODES} searchKeyword="modal" />);

    const row = screen.getByTitle('partials/admin_user_list/_modal_form');
    const marked = within(row).getByText('modal');

    expect(marked.className).toContain('bg-yellow-200');
  });

  /** @effects file_tree_lists_content_matches_as_children */
  it('내용 매치를 파일 아래 줄로 펼친다 — 클릭하면 좌표를 함께 넘긴다', () => {
    const onSelect = vi.fn();

    render(
      <FileTree
        nodes={NODES}
        searchKeyword="data-source"
        contentMatches={[
          {
            key: 'layout:admin_user_list',
            count: 2,
            lines: [{ line: 42, column: 5, preview: '<data-source id="users">' }],
          },
        ]}
        onSelect={onSelect}
      />,
    );

    const hit = screen.getByLabelText('admin.templates.layout_edit.search.goto_line');
    fireEvent.click(hit);

    expect(onSelect).toHaveBeenCalledWith('layout:admin_user_list', { line: 42, column: 5 });
  });

  /** @effects file_tree_shows_empty_state_for_no_match */
  it('결과가 없으면 빈 상태와 [검색 지우기] 를 보여 준다', () => {
    const onClearSearch = vi.fn();

    render(<FileTree nodes={NODES} searchKeyword="zzz-no-such-file" onClearSearch={onClearSearch} />);

    fireEvent.click(screen.getByText('admin.templates.layout_edit.search.clear'));

    expect(onClearSearch).toHaveBeenCalled();
  });
});

describe('FileTree — 접근성', () => {
  /** @effects file_tree_uses_tree_role_contract */
  it('role=tree / treeitem 규약을 지킨다', () => {
    render(<FileTree nodes={NODES} selectedKey="layout:admin_dashboard" />);

    expect(screen.getAllByRole('tree')).toHaveLength(1);

    // 폴더·구역은 aria-expanded 를 갖고, 선택된 파일 하나만 aria-selected 다.
    const expandables = screen.getAllByRole('treeitem').filter((el) => el.hasAttribute('aria-expanded'));
    expect(expandables.length).toBeGreaterThan(0);

    const selected = screen.getAllByRole('treeitem').filter((el) => el.getAttribute('aria-selected') === 'true');
    expect(selected).toHaveLength(1);
  });

  /**
   * tabindex 는 roving 이다 — 활성 노드 하나만 `0`.
   *
   * 144개 파일이 전부 `0` 이면 Tab 으로 트리를 빠져나가는 데만 144번을 눌러야 한다.
   *
   * @effects file_tree_uses_roving_tabindex
   */
  // @scenario depth=root, input=keyboard
  it('활성 노드 하나만 tabindex=0 이다', () => {
    render(<FileTree nodes={NODES} selectedKey="layout:admin_dashboard" />);

    const focusable = screen.getAllByRole('treeitem').filter((el) => el.getAttribute('tabindex') === '0');

    expect(focusable).toHaveLength(1);
  });

  /** @effects file_tree_arrow_keys_move_between_visible_rows */
  // @scenario depth=two_levels, input=keyboard
  it('↓ 는 다음 **보이는** 행으로 간다 (접힌 폴더의 자식은 건너뛴다)', () => {
    render(<FileTree nodes={NODES} selectedKey="layout:admin_dashboard" />);

    const tree = screen.getByRole('tree');
    const before = screen.getAllByRole('treeitem').findIndex((el) => el.getAttribute('tabindex') === '0');

    fireEvent.keyDown(tree, { key: 'ArrowDown' });

    const after = screen.getAllByRole('treeitem').findIndex((el) => el.getAttribute('tabindex') === '0');

    expect(after).toBe(before + 1);
  });

  /** @effects file_tree_right_arrow_expands_collapsed_folder */
  // @scenario depth=one_level, input=keyboard
  it('→ 는 접힌 폴더를 펼친다', () => {
    render(<FileTree nodes={NODES} />);

    const tree = screen.getByRole('tree');
    const folder = screen.getByText('errors').closest('[role="treeitem"]') as HTMLElement;

    fireEvent.click(folder);
    expect(folder.getAttribute('aria-expanded')).toBe('true');

    fireEvent.keyDown(tree, { key: 'ArrowLeft' });
    expect(folder.getAttribute('aria-expanded')).toBe('false');

    fireEvent.keyDown(tree, { key: 'ArrowRight' });
    expect(folder.getAttribute('aria-expanded')).toBe('true');
  });

  /** @effects file_tree_enter_opens_a_file */
  it('Enter 로 파일을 연다', () => {
    const onSelect = vi.fn();

    render(<FileTree nodes={NODES} selectedKey="layout:admin_dashboard" onSelect={onSelect} />);

    fireEvent.keyDown(screen.getByRole('tree'), { key: 'Enter' });

    expect(onSelect).toHaveBeenCalledWith('layout:admin_dashboard');
  });
});

describe('FileTree — 상태', () => {
  /** @effects file_tree_remembers_expansion_in_local_storage */
  it('펼침 상태를 localStorage 에 적어 둔다', () => {
    const { unmount } = render(<FileTree nodes={NODES} storageKey="g7.test.expanded" />);

    fireEvent.click(screen.getByText('errors'));
    unmount();

    render(<FileTree nodes={NODES} storageKey="g7.test.expanded" />);

    expect(screen.getByText('404')).toBeInTheDocument();
  });

  /** @effects file_tree_hides_file_actions_without_permission */
  it('권한이 없으면 이름변경·삭제 버튼을 내보내지 않는다', () => {
    render(<FileTree nodes={NODES} canManage={false} />);

    expect(screen.queryByLabelText('admin.templates.layout_edit.file.delete')).not.toBeInTheDocument();
  });

  /** @effects file_tree_shows_skeleton_while_loading */
  it('목록을 불러오는 동안 스켈레톤을 세운다', () => {
    const { container } = render(<FileTree nodes={[]} isLoading />);

    expect(container.querySelector('[aria-busy="true"]')).toBeInTheDocument();
  });
});
