import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { FileTreePicker } from '../FileTreePicker';
import type { FileTreeNode } from '../FileTree';

/**
 * FileTreePicker — 기기 폭에 따라 트리를 **인라인 · 서랍 · 시트**에 담는 그릇.
 *
 * 여기서 잠그는 것은 오버레이의 규격이다(계획서 §7.4·§7.5) — 서랍 폭 380px·`z-40`·200ms 슬라이드,
 * 시트는 전체 화면·아래에서 올라옴, 둘 다 Esc 로 닫힘. 경계(1023/1024)와 포커스 트랩은 브라우저
 * 실측(C21·C22)이 재고, 단위는 형태별 계약만 본다.
 */

const NODES: FileTreeNode[] = [
  { key: 'layout:admin_dashboard', kind: 'layout', path: 'admin_dashboard', label: 'admin_dashboard' },
  { key: 'layout:errors/404', kind: 'layout', path: 'errors/404', label: '404' },
];

describe('FileTreePicker — 오버레이 규격', () => {
  /** @effects file_tree_picker_drawer_slides_in_from_the_left */
  it('서랍은 380px 폭·z-40 으로 왼쪽에서 200ms 슬라이드해 들어온다', () => {
    render(<FileTreePicker mode="drawer" nodes={NODES} selectedKey="layout:admin_dashboard" />);

    expect(screen.queryByTestId('file-tree-overlay')).toBeNull();

    fireEvent.click(screen.getByTestId('file-tree-picker-open'));

    const overlay = screen.getByTestId('file-tree-overlay');

    expect(overlay.className).toContain('w-[380px]');
    expect(overlay.className).toContain('max-w-[85vw]');
    expect(overlay.className).toContain('z-40');
    // 진입 애니메이션은 `main.css` 의 키프레임(200ms)과 이 클래스명으로 짝을 이룬다.
    expect(overlay.className).toContain('g7le-drawer-enter');
    expect(overlay.className).not.toContain('g7le-sheet-enter');
    // 관리자 모바일 헤더(h-16, z-50) 아래에서 시작한다 — top:0 이면 서랍 머리(제목·검색칸)가 헤더에 가려
    // 검색칸 가운데를 눌러도 헤더가 받는다(실측 2026-10-06, 7.1.0 전수 실측 I1: 900px 에서 elementFromPoint=헤더).
    expect(overlay.className).toContain('top-16');
    expect(overlay.className).not.toContain('inset-y-0');
  });

  /** @effects file_tree_picker_sheet_covers_the_viewport */
  it('시트는 헤더 아래 화면 전체를 덮고 아래에서 올라온다', () => {
    render(<FileTreePicker mode="sheet" nodes={NODES} selectedKey="layout:admin_dashboard" />);

    fireEvent.click(screen.getByTestId('file-tree-picker-open'));

    const overlay = screen.getByTestId('file-tree-overlay');

    // 헤더 아래부터 화면 끝까지 덮는다 — `inset-0` 이면 시트 머리(검색칸)가 모바일 헤더에 가린다(위 서랍과 같은 이유).
    expect(overlay.className).toContain('inset-x-0');
    expect(overlay.className).toContain('top-16');
    expect(overlay.className).toContain('bottom-0');
    expect(overlay.className).not.toMatch(/(^|\s)inset-0(\s|$)/);
    expect(overlay.className).toContain('z-40');
    expect(overlay.className).toContain('g7le-sheet-enter');
  });

  /**
   * 단축키 «파일 빠른 이동»(mod+k)·«내용 검색»(mod+shift+f)은 검색창에 포커스를 준다 — 레이아웃이
   * 카운터를 올려 넘기고, 인라인이면 바로 포커스, 서랍·시트면 열고 포커스한다.
   *
   * @effects file_tree_picker_focuses_search_on_nonce
   */
  it('focusSearchNonce 가 오르면 검색창에 포커스를 준다 — 서랍이면 먼저 연다', () => {
    const inline = render(
      <FileTreePicker mode="inline" nodes={NODES} selectedKey="layout:admin_dashboard" focusSearchNonce={0} />,
    );

    expect(document.activeElement).not.toBe(screen.getByTestId('file-tree-search'));

    inline.rerender(
      <FileTreePicker mode="inline" nodes={NODES} selectedKey="layout:admin_dashboard" focusSearchNonce={1} />,
    );

    expect(document.activeElement).toBe(screen.getByTestId('file-tree-search'));
    inline.unmount();

    const drawer = render(
      <FileTreePicker mode="drawer" nodes={NODES} selectedKey="layout:admin_dashboard" focusSearchNonce={0} />,
    );

    expect(screen.queryByTestId('file-tree-overlay')).toBeNull();

    drawer.rerender(
      <FileTreePicker mode="drawer" nodes={NODES} selectedKey="layout:admin_dashboard" focusSearchNonce={1} />,
    );

    expect(screen.getByTestId('file-tree-overlay')).toBeInTheDocument();
    expect(document.activeElement).toBe(screen.getByTestId('file-tree-search'));
  });

  /** @effects file_tree_picker_overlay_closes_on_escape */
  it('Esc 로 오버레이가 닫히고 스크림도 사라진다', () => {
    render(<FileTreePicker mode="drawer" nodes={NODES} selectedKey="layout:admin_dashboard" />);

    fireEvent.click(screen.getByTestId('file-tree-picker-open'));
    expect(screen.getByTestId('file-tree-scrim')).toBeInTheDocument();

    fireEvent.keyDown(document, { key: 'Escape' });

    expect(screen.queryByTestId('file-tree-overlay')).toBeNull();
    expect(screen.queryByTestId('file-tree-scrim')).toBeNull();
  });
});
