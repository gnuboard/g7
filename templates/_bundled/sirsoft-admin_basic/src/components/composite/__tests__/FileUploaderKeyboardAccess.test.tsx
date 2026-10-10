/**
 * 관리자 템플릿 FileUploader — 키보드로 다루는 첨부 목록.
 *
 * 7.1.0 전수 실측(원 세션 관측 O-M2r-1·O-M2r-2):
 * - 썸네일의 대표·순서·다운로드·다시 시도·삭제 단추를 담은 덮개가 `opacity-0 group-hover:opacity-100` 이라,
 *   Tab 으로 단추에 초점이 가도 덮개가 투명한 채로 남아 어느 단추에 초점이 있는지 보이지 않았다.
 *   마우스를 올린 것과 같이 썸네일 안에 초점이 있으면 덮개를 보인다(`group-focus-within`).
 * - 목록 끝 「추가」 타일이 onClick 만 있는 Div 라 Tab 이 닿지 않았다. 단추로 그려 Tab 으로 닿고
 *   Enter·Space 로 파일 선택 창을 연다.
 * - 첨부가 없을 때의 드롭존(「파일을 드래그하거나 클릭하여 업로드」)도 onClick 만 있는 Div 라, 키보드로는
 *   첫 파일을 고를 방법이 아예 없었다(M3 재실측). 단추 구실(role=button, tabIndex 0)로 Tab 이 닿고
 *   Enter·Space 로 파일 선택 창을 연다.
 *
 * @scenario-manifest tests/scenarios/file-uploader-keyboard-access.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, vi } from 'vitest';
import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// SortableThumbnailItem·utils 가 모듈 스코프에서 G7Core 를 잡으므로 import 전에 둔다
vi.hoisted(() => {
  (window as any).G7Core = {
    ...((window as any).G7Core ?? {}),
    t: (key: string) => `T(${key})`,
    api: { get: () => Promise.resolve(null) },
    createLogger: () => ({ log: () => {}, warn: () => {}, error: () => {} }),
  };
});

import { FileList } from '../FileUploader/FileList';
import { FileDropZone } from '../FileUploader/FileDropZone';

URL.createObjectURL = vi.fn(() => 'blob:mock-url');
URL.revokeObjectURL = vi.fn();

const existing = {
  id: 11,
  hash: 'abc123def456',
  original_filename: 'a.txt',
  mime_type: 'text/plain',
  size: 8,
  is_image: false,
  download_url: null,
} as any;

/**
 * 첨부 목록을 그린다.
 *
 * @param props 목록 속성(기본: 기존 첨부 1개, 더 추가 가능, 대표 이미지 선택 켬)
 * @returns render 결과와 추가 단추 처리기
 */
function renderList(props: Partial<React.ComponentProps<typeof FileList>> = {}) {
  const onAddClick = vi.fn();
  const result = render(
    <FileList
      allItems={[existing]}
      canAddMore
      totalCount={1}
      maxFiles={5}
      maxSize={10}
      onRemove={vi.fn()}
      onRetry={vi.fn()}
      onImageClick={vi.fn()}
      onDownload={vi.fn()}
      onDragEnd={vi.fn()}
      onAddClick={onAddClick}
      enablePrimarySelection
      primaryFileId={null}
      onPrimaryChange={vi.fn()}
      {...props}
    />
  );
  return { ...result, onAddClick };
}

describe('[case:file-uploader-keyboard-access] FileUploader (sirsoft-admin_basic) 키보드 사용', () => {
  /**
   * @scenario target=item_overlay
   * @effects overlay_visible_on_focus
   */
  it('썸네일 안에 초점이 있으면 단추 덮개가 보인다', () => {
    renderList();

    const deleteButton = screen.getByRole('button', { name: 'T(common.delete)' });
    const overlay = deleteButton.parentElement as HTMLElement;
    expect(overlay.className).toContain('opacity-0');
    expect(overlay.className).toContain('group-hover:opacity-100');
    expect(overlay.className).toContain('group-focus-within:opacity-100');
    expect(overlay.className).toContain('group-focus-within:bg-black/40');
  });

  /**
   * @scenario target=primary_button
   * @effects overlay_visible_on_focus
   */
  it('대표 이미지가 아닌 썸네일의 대표 지정 단추도 초점이 있으면 보인다', () => {
    renderList();

    const primaryButton = screen.getByRole('button', { name: 'T(attachment.set_as_primary)' });
    expect(primaryButton.className).toContain('opacity-0');
    expect(primaryButton.className).toContain('group-focus-within:opacity-100');
  });

  /**
   * @scenario target=add_tile
   * @effects add_tile_reachable_by_tab
   */
  it('추가 타일은 이름 있는 단추라 Tab 으로 닿는다', async () => {
    const user = userEvent.setup();
    renderList();

    const addButton = screen.getByRole('button', { name: 'T(common.add)' });
    expect(addButton.tagName).toBe('BUTTON');
    expect(addButton.getAttribute('type')).toBe('button');
    // 아이콘은 꾸밈 — 이름에 끼어들지 않는다
    expect(addButton.querySelector('i')?.getAttribute('aria-hidden')).toBe('true');

    // 썸네일 단추(대표·순서·다운로드·삭제) 다음 Tab 이 추가 단추에 닿는다
    for (let i = 0; i < 5; i++) {
      await user.tab();
      if (document.activeElement === addButton) break;
    }
    expect(document.activeElement).toBe(addButton);
  });

  /**
   * @scenario target=add_tile
   * @effects add_tile_opens_picker_by_key
   */
  it('추가 단추에서 Enter·Space 를 누르면 파일 선택을 연다', async () => {
    const user = userEvent.setup();
    const { onAddClick } = renderList();

    const addButton = screen.getByRole('button', { name: 'T(common.add)' });
    addButton.focus();
    await user.keyboard('{Enter}');
    expect(onAddClick).toHaveBeenCalledTimes(1);
    await user.keyboard(' ');
    expect(onAddClick).toHaveBeenCalledTimes(2);
  });

  /**
   * @scenario target=add_tile
   * @effects add_tile_hidden_when_disabled
   */
  it('비활성 업로더와 한도에 찬 업로더에는 추가 단추가 없다', () => {
    const disabled = renderList({ disabled: true });
    expect(screen.queryByRole('button', { name: 'T(common.add)' })).toBeNull();
    disabled.unmount();

    renderList({ canAddMore: false });
    expect(screen.queryByRole('button', { name: 'T(common.add)' })).toBeNull();
  });

  /**
   * @scenario target=empty_dropzone
   * @effects empty_dropzone_opens_picker_by_key
   */
  it('첨부가 없을 때 드롭존은 Tab 으로 닿고 Enter·Space 로 파일 선택을 연다', async () => {
    const user = userEvent.setup();
    const inputRef = React.createRef<HTMLInputElement>();
    render(
      <FileDropZone
        isDragOver={false}
        canAddMore
        onFiles={vi.fn()}
        setIsDragOver={vi.fn()}
        inputRef={inputRef}
        accept=".txt"
        maxFiles={5}
        maxSize={10}
      />
    );

    const zone = screen.getByRole('button', { name: /T\(attachment\.drop_or_click\)/ });
    expect(zone.getAttribute('tabindex')).toBe('0');
    expect(zone.querySelector('i')?.getAttribute('aria-hidden')).toBe('true');

    const pick = vi.spyOn(inputRef.current as HTMLInputElement, 'click').mockImplementation(() => {});
    // jsdom 은 CSS(hidden)를 적용하지 않아 숨은 파일 입력도 Tab 순서에 든다 — 브라우저에서는 display:none 이라 건너뛴다
    for (let i = 0; i < 3 && document.activeElement !== zone; i++) {
      await user.tab();
    }
    expect(document.activeElement).toBe(zone);
    await user.keyboard('{Enter}');
    expect(pick).toHaveBeenCalledTimes(1);
    await user.keyboard(' ');
    expect(pick).toHaveBeenCalledTimes(2);
  });

  /**
   * @scenario target=empty_dropzone
   * @effects add_tile_hidden_when_disabled
   */
  it('비활성 업로더의 드롭존은 Tab 이 닿지 않고 키로도 열리지 않는다', async () => {
    const user = userEvent.setup();
    const inputRef = React.createRef<HTMLInputElement>();
    render(
      <FileDropZone
        isDragOver={false}
        canAddMore
        onFiles={vi.fn()}
        setIsDragOver={vi.fn()}
        inputRef={inputRef}
        maxFiles={5}
        maxSize={10}
        disabled
      />
    );

    const zone = screen.getByRole('button', { name: /T\(attachment\.drop_or_click\)/ });
    expect(zone.getAttribute('tabindex')).toBe('-1');
    expect(zone.getAttribute('aria-disabled')).toBe('true');
    const pick = vi.spyOn(inputRef.current as HTMLInputElement, 'click').mockImplementation(() => {});
    zone.focus();
    await user.keyboard('{Enter}');
    expect(pick).not.toHaveBeenCalled();
  });
});
