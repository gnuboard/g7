/**
 * FileUploader — 이 화면에서 지운 첨부를 다른 업로더 인스턴스가 다시 그리지 않는다
 *
 * 같은 배열을 넓은 화면 표와 좁은 화면 카드가 함께 편집하는 화면(게시판 환경설정 반응 유형 아이콘)에서,
 * 한 업로더가 첨부를 서버에서 지운 직후 다른 인스턴스(폭을 바꿔 새로 마운트된 표·카드, 다시 그려진 반복 행)가
 * 지우기 전 값을 initialFiles 로 한 번 받으면, 그 인스턴스는 지운 첨부를 목록에 올리고 썸네일을 내려받다가
 * 404 를 콘솔에 남겼다(7.1.0 실측 O-K1-6). 지운 기록이 인스턴스마다 따로라 새 인스턴스는 그 사실을 몰랐다.
 *
 * @module composite/__tests__/FileUploaderSessionDeleted.test
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import { useFileUploader } from '../FileUploader/useFileUploader';
import type { Attachment } from '../FileUploader/types';

vi.mock('browser-image-compression', () => ({
  default: vi.fn((file: File) => Promise.resolve(file)),
}));

URL.createObjectURL = vi.fn(() => 'blob:mock-url');
URL.revokeObjectURL = vi.fn();

const endpoints = {
  upload: '/api/attachments',
  delete: '/api/attachments/:id',
  reorder: '/api/attachments/reorder',
};

const STABLE_EMPTY_ROLE_IDS: number[] = [];

/**
 * 테스트용 이미지 첨부를 만듭니다.
 *
 * @param id 첨부 ID
 * @returns 첨부
 */
function createAttachment(id: number): Attachment {
  return {
    id,
    hash: `session_deleted_${id}`,
    original_filename: `icon_${id}.png`,
    mime_type: 'image/png',
    size: 176,
    size_formatted: '176 B',
    download_url: `/api/attachment/session_deleted_${id}`,
    order: 1,
    is_image: true,
  };
}

/**
 * 업로더 훅 옵션을 만듭니다.
 *
 * @param initialFiles 초기 첨부
 * @returns 훅 옵션
 */
function options(initialFiles: Attachment[]) {
  return {
    collection: 'reaction_type_icon',
    maxFiles: 1,
    maxSize: 1,
    maxConcurrentUploads: 1,
    roleIds: STABLE_EMPTY_ROLE_IDS,
    autoUpload: true,
    initialFiles,
    confirmBeforeRemove: false,
    endpoints,
  };
}

describe('[case:components-misc-file-uploader-session-deleted-404] FileUploader - 이 화면에서 지운 첨부', () => {
  beforeEach(() => {
    if (!(window as any).G7Core) {
      (window as any).G7Core = {};
    }
    const g7 = (window as any).G7Core;
    g7.createLogger = vi.fn(() => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }));
    g7.componentEvent = { on: vi.fn(() => () => {}), emit: vi.fn(async () => []) };
    g7.api = {
      delete: vi.fn().mockResolvedValue({}),
      post: vi.fn().mockResolvedValue({ data: {} }),
      get: vi.fn().mockRejectedValue(new Error("test: skip auth image")),
    };
  });

  afterEach(() => {
    delete (window as any).G7Core.api;
  });

  // @scenario case=new_instance_with_stale_initial
  // @effects file_uploader_deleted_attachment_not_redrawn_by_other_instance
  it('다른 인스턴스가 지운 첨부를 지우기 전 initialFiles 로 받아도 목록에 올리지 않고 내려받지 않는다', async () => {
    const icon = createAttachment(9101);
    const staleInitial = [icon];

    const first = renderHook(() => useFileUploader(options(staleInitial)));
    await act(async () => {
      await first.result.current.handleRemove(icon);
    });
    expect((window as any).G7Core.api.delete).toHaveBeenCalledWith('/api/attachments/9101');
    expect(first.result.current.existingFiles).toHaveLength(0);

    (window as any).G7Core.api.get.mockClear();

    // 폭을 바꿔 새로 마운트된 형제 인스턴스 — 지우기 전 값을 한 번 받는다
    const second = renderHook(() => useFileUploader(options(staleInitial)));
    await act(async () => {
      await Promise.resolve();
    });

    expect(second.result.current.existingFiles).toHaveLength(0);
    expect((window as any).G7Core.api.get).not.toHaveBeenCalledWith(icon.download_url, expect.anything());
  });

  // @scenario case=mounted_sibling_stale_sync
  // @effects file_uploader_deleted_attachment_not_redrawn_by_other_instance
  it('이미 마운트된 인스턴스도 낡은 initialFiles 동기화에서 지운 첨부를 되살리지 않는다', async () => {
    const icon = createAttachment(9102);
    let initial: Attachment[] = [];

    const sibling = renderHook(({ files }) => useFileUploader(options(files)), {
      initialProps: { files: initial },
    });
    const ownerFiles = [icon];
    const owner = renderHook(() => useFileUploader(options(ownerFiles)));
    await act(async () => {
      await owner.result.current.handleRemove(icon);
    });

    initial = [icon];
    sibling.rerender({ files: initial });
    await act(async () => {
      await Promise.resolve();
    });

    expect(sibling.result.current.existingFiles).toHaveLength(0);
  });

  // @scenario case=new_instance_during_pending_delete
  // @effects file_uploader_deleted_attachment_not_redrawn_by_other_instance
  it('삭제 요청이 끝나기 전에 마운트된 인스턴스도 그 첨부를 목록에 올리지 않는다', async () => {
    const icon = createAttachment(9104);
    const files = [icon];
    let resolveDelete: () => void = () => {};
    (window as any).G7Core.api.delete = vi.fn(() => new Promise<void>((resolve) => { resolveDelete = resolve; }));

    const first = renderHook(() => useFileUploader(options(files)));
    let pending: Promise<void> = Promise.resolve();
    act(() => {
      pending = first.result.current.handleRemove(icon);
    });

    const second = renderHook(() => useFileUploader(options(files)));
    expect(second.result.current.existingFiles).toHaveLength(0);

    await act(async () => {
      resolveDelete();
      await pending;
    });
    expect(first.result.current.existingFiles).toHaveLength(0);
  });

  // @scenario case=server_delete_failed
  // @effects file_uploader_failed_delete_still_shown_elsewhere
  it('서버 삭제가 실패한 첨부는 다른 인스턴스에서 그대로 보인다', async () => {
    const icon = createAttachment(9103);
    (window as any).G7Core.api.delete = vi.fn().mockRejectedValue(new Error('500'));

    const files = [icon];
    const first = renderHook(() => useFileUploader(options(files)));
    await act(async () => {
      await first.result.current.handleRemove(icon);
    });

    const second = renderHook(() => useFileUploader(options(files)));
    await act(async () => {
      await Promise.resolve();
    });

    expect(second.result.current.existingFiles.map((f) => f.id)).toEqual([9103]);
  });
});
