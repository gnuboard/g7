/**
 * FileUploader 마운트 시 「파일 바뀜」 미발화 테스트
 *
 * 업로더가 처음 그려질 때 빈 대기 목록으로 `onFilesChange([])` 를 부르면, 레이아웃이 그 이벤트를
 * 「변경 있음」(`hasChanges: true`)으로 받는다. 업로더가 폼 데이터 초기화(`initLocal`) 뒤에 마운트되면
 * 이 값이 남아, 게시글 수정 화면을 열기만 해도 「저장하지 않은 변경」 안내와 저장 버튼 활성이 뜬다.
 * 사용자가 파일을 더하거나 뺐을 때만 알린다.
 *
 * @scenario-manifest tests/scenarios/file-uploader-mount-no-change.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import { useFileUploader } from '../FileUploader/useFileUploader';
import type { Attachment } from '../FileUploader/types';

// useFileUploader.ts 가 모듈 스코프에서 G7Core 를 캐싱하므로 기존 객체에 프로퍼티를 붙인다
beforeEach(() => {
  if (!(window as any).G7Core) {
    (window as any).G7Core = {};
  }
  const g7 = (window as any).G7Core;
  g7.componentEvent = { on: vi.fn(() => () => {}), emit: vi.fn(async () => []) };
  g7.createLogger = vi.fn(() => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }));
});

vi.mock('browser-image-compression', () => ({
  default: vi.fn((file: File) => Promise.resolve(file)),
}));

URL.createObjectURL = vi.fn(() => 'blob:mock-url');
URL.revokeObjectURL = vi.fn();

const STABLE_EMPTY_FILES: Attachment[] = [];
const STABLE_EMPTY_ROLE_IDS: number[] = [];

/**
 * 공통 옵션으로 훅을 렌더한다.
 *
 * @param onFilesChange 대기 파일 변경 콜백
 * @returns renderHook 결과
 */
function renderUploader(onFilesChange: (files: unknown[]) => void) {
  return renderHook(() =>
    useFileUploader({
      collection: 'test',
      maxFiles: 5,
      maxSize: 10,
      maxConcurrentUploads: 3,
      roleIds: STABLE_EMPTY_ROLE_IDS,
      autoUpload: false,
      initialFiles: STABLE_EMPTY_FILES,
      confirmBeforeRemove: false,
      onFilesChange,
      endpoints: { upload: '/api/attachments', delete: '/api/attachments/:id', reorder: '/api/attachments/reorder' },
    })
  );
}

describe('[case:state-global-file-uploader-mount-has-changes] FileUploader - 마운트 시 onFilesChange', () => {
  /**
   * @scenario trigger=mount
   *
   * @effects no_files_change_on_mount
   */
  it('처음 그려질 때는 부르지 않는다 (빈 대기 목록은 사용자가 바꾼 것이 아니다)', () => {
    const onFilesChange = vi.fn();

    const { rerender } = renderUploader(onFilesChange);
    rerender();

    expect(onFilesChange).not.toHaveBeenCalled();
  });

  /**
   * @scenario trigger=user_change
   *
   * @effects files_change_on_add_and_remove
   */
  it('파일을 고르면 부르고, 고른 파일을 빼도 부른다', async () => {
    const onFilesChange = vi.fn();
    const { result } = renderUploader(onFilesChange);

    const file = new File(['x'], 'a.png', { type: 'image/png' });
    await act(async () => {
      await result.current.handleFiles({ 0: file, length: 1, item: () => file } as unknown as FileList);
    });

    expect(onFilesChange).toHaveBeenCalledTimes(1);
    expect(onFilesChange.mock.calls[0][0]).toHaveLength(1);

    await act(async () => {
      await result.current.handleRemove(result.current.pendingFiles[0]);
    });

    expect(onFilesChange).toHaveBeenCalledTimes(2);
    expect(onFilesChange.mock.calls[1][0]).toHaveLength(0);
  });
});
