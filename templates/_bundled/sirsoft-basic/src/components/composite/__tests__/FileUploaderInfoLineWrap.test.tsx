/**
 * 사용자 템플릿 FileUploader — 첨부 목록 아래 안내 줄의 줄바꿈.
 *
 * 7.1.0 전수 실측 M3(390px 게시판 글쓰기, 영어): 첨부 목록 아래 「Max 10MB (.jpg,.jpeg,…,.zip)」 안내가
 * 쉼표로만 이어진 긴 허용 형식 목록이라 줄바꿈할 자리가 없어, 한 줄 `justify-between` 안에서 칸 밖으로
 * 넘쳐 화면에 가로 스크롤(scrollWidth 496 / 폭 375)이 생겼다. 안내 줄을 줄바꿈 가능한 flex 로 두고
 * 형식 목록은 어디서든 끊어 접히게 한다.
 *
 * @scenario-manifest tests/scenarios/file-uploader-info-line-wrap.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, vi } from 'vitest';
import React from 'react';
import { render, screen } from '@testing-library/react';

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

const LONG_ACCEPT = '.jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.hwp,.txt,.zip';

describe('[case:file-uploader-info-line-wrap] FileUploader (sirsoft-basic) 안내 줄 줄바꿈', () => {
  /**
   * @scenario accept=long_list
   * @effects info_line_wraps_within_container
   */
  it('긴 허용 형식 목록이 있으면 안내 줄이 줄바꿈되고 형식 목록은 어디서든 끊긴다', () => {
    render(
      <FileList
        allItems={[{ id: 1, hash: 'h1', original_filename: 'a.txt', mime_type: 'text/plain', size: 1, is_image: false, download_url: null } as any]}
        canAddMore
        totalCount={1}
        maxFiles={5}
        maxSize={10}
        accept={LONG_ACCEPT}
        onRemove={vi.fn()}
        onRetry={vi.fn()}
        onImageClick={vi.fn()}
        onDownload={vi.fn()}
        onDragEnd={vi.fn()}
        onAddClick={vi.fn()}
      />
    );

    const limit = screen.getByText((content) => content.includes(LONG_ACCEPT));
    const line = limit.parentElement as HTMLElement;
    expect(line.className).toContain('flex-wrap');
    expect(limit.className).toContain('min-w-0');
    expect(limit.className).toContain('wrap-anywhere');
  });

  /**
   * @scenario accept=long_list
   * @effects dropzone_hint_wraps_within_container
   */
  it('첨부가 없을 때 드롭존 안내의 긴 허용 형식 목록도 어디서든 끊긴다', () => {
    render(
      <FileDropZone
        isDragOver={false}
        canAddMore
        onFiles={vi.fn()}
        setIsDragOver={vi.fn()}
        inputRef={React.createRef<HTMLInputElement>()}
        accept={LONG_ACCEPT}
        maxFiles={5}
        maxSize={10}
      />
    );

    const hint = screen.getByText((content) => content.includes(LONG_ACCEPT));
    expect(hint.className).toContain('wrap-anywhere');
  });
});
