/**
 * 관리자 템플릿 FileUploader — 첨부 썸네일의 아이콘 단추 이름.
 *
 * 7.1.0 전수 실측(원 세션 관측 O-RF-1): 페이지 작성·게시판 관리자 글쓰기 화면의 첨부 업로더에서
 * 「순서 바꾸기」·「다운로드」·「삭제」 단추의 접근 이름이 빈 문자열이었다. 단추에 `title` 만 있고 안의
 * Font Awesome 아이콘(`<i>`)이 CSS 로 그린 글리프를 내용으로 내놓아, 브라우저가 그 글리프를 이름으로 잡았다
 * (화면 낭독기·자동화 도구가 단추를 이름으로 찾을 수 없다). 사용자 템플릿(sirsoft-basic)과 같은 방식으로
 * 단추에 사람이 읽는 이름(aria-label)을 달고 아이콘은 꾸밈으로 숨긴다.
 *
 * @scenario-manifest tests/scenarios/file-uploader-item-button-names.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, vi } from 'vitest';
import React from 'react';
import { render, screen } from '@testing-library/react';

// SortableThumbnailItem·utils 가 모듈 스코프에서 G7Core 를 잡으므로 import 전에 둔다
vi.hoisted(() => {
  (window as any).G7Core = {
    ...((window as any).G7Core ?? {}),
    t: (key: string) => `T(${key})`,
    api: { get: () => Promise.resolve(null) },
    createLogger: () => ({ log: () => {}, warn: () => {}, error: () => {} }),
  };
});

import { DndContext } from '@dnd-kit/core';
import { SortableContext } from '@dnd-kit/sortable';
import { SortableThumbnailItem } from '../FileUploader/SortableThumbnailItem';

URL.createObjectURL = vi.fn(() => 'blob:mock-url');
URL.revokeObjectURL = vi.fn();

/**
 * 정렬 문맥 안에 썸네일 하나를 그린다.
 *
 * @param props 썸네일 속성(file 을 넘기면 기본 첨부 대신 쓴다)
 * @returns render 결과
 */
function renderItem(props: Partial<React.ComponentProps<typeof SortableThumbnailItem>> = {}) {
  const file = {
    id: 11,
    hash: 'abc123def456',
    original_filename: 'a.txt',
    mime_type: 'text/plain',
    size: 8,
    is_image: false,
    download_url: null,
  } as any;

  return render(
    <DndContext>
      <SortableContext items={['abc123def456', 'pending-1']}>
        <SortableThumbnailItem file={file} onRemove={vi.fn()} onDownload={vi.fn()} {...props} />
      </SortableContext>
    </DndContext>
  );
}

describe('[case:file-uploader-item-button-names] FileUploader (sirsoft-admin_basic) 썸네일 단추 이름', () => {
  /**
   * @scenario file=existing_attachment
   * @effects icon_buttons_have_readable_names
   */
  it('기존 첨부의 순서·다운로드·삭제 단추는 번역된 이름을 접근 이름으로 가진다', () => {
    const { container } = renderItem();

    // jsdom 은 CSS 글리프(::before)를 그리지 않아 title 만으로도 이름이 잡힌다 — 실제 브라우저는 글리프가
    // title 보다 앞서므로, 이름은 aria-label 로 직접 달려 있어야 한다.
    const labels = [...container.querySelectorAll('button')].map((b) => b.getAttribute('aria-label'));
    expect(labels).toEqual(['T(attachment.drag_to_reorder)', 'T(common.download)', 'T(common.delete)']);
    expect(screen.getByRole('button', { name: 'T(common.delete)' })).toBeTruthy();
  });

  /**
   * @scenario file=existing_attachment
   * @effects icons_are_decorative
   */
  it('단추 안의 아이콘은 꾸밈으로 숨겨 이름에 끼어들지 않는다', () => {
    const { container } = renderItem({ onPrimaryClick: vi.fn(), isPrimary: true });

    const iconsInButtons = [...container.querySelectorAll('button i')];
    expect(iconsInButtons.length).toBe(4);
    for (const icon of iconsInButtons) {
      expect(icon.getAttribute('aria-hidden')).toBe('true');
    }
  });

  /**
   * @scenario file=image_with_primary
   * @effects icon_buttons_have_readable_names
   */
  it('대표 이미지 단추도 상태에 맞는 이름을 가진다', () => {
    const notPrimary = renderItem({ onPrimaryClick: vi.fn(), isPrimary: false });
    expect([...notPrimary.container.querySelectorAll('button')].map((b) => b.getAttribute('aria-label'))).toContain(
      'T(attachment.set_as_primary)'
    );
    notPrimary.unmount();

    const primary = renderItem({ onPrimaryClick: vi.fn(), isPrimary: true });
    expect([...primary.container.querySelectorAll('button')].map((b) => b.getAttribute('aria-label'))).toContain(
      'T(attachment.primary_image)'
    );
  });

  /**
   * @scenario file=failed_upload
   * @effects icon_buttons_have_readable_names
   */
  it('업로드에 실패한 파일의 다시 시도 단추도 이름을 가진다', () => {
    const pending = {
      id: 'pending-1',
      file: new File(['x'], 'b.txt', { type: 'text/plain' }),
      original_filename: 'b.txt',
      mime_type: 'text/plain',
      size: 1,
      status: 'error',
      progress: 0,
    } as any;
    const { container } = renderItem({ file: pending, onRetry: vi.fn() });

    const labels = [...container.querySelectorAll('button')].map((b) => b.getAttribute('aria-label'));
    expect(labels).toEqual(['T(attachment.drag_to_reorder)', 'T(common.retry)', 'T(common.delete)']);
  });
});
