/**
 * FileUploader 개수 상한 초과 안내 테스트
 *
 * maxFiles 를 초과해 파일을 선택하면 초과분을 조용히 버리지 않고
 * onUploadError 콜백으로 안내한다. (admin_basic 과 동일 동작 — 공통 문구)
 *
 * @vitest-environment jsdom
 * @module composite/__tests__/FileUploaderLimit.test
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import { useFileUploader } from '../FileUploader/useFileUploader';
import type { Attachment } from '../FileUploader/types';

// G7Core 모킹 (useFileUploader 가 모듈 스코프에서 window.G7Core 를 캐싱하므로
// 새 객체로 교체하지 않고 기존 객체에 프로퍼티를 직접 추가)
beforeEach(() => {
  const listeners = new Map<string, Set<Function>>();

  if (!(window as any).G7Core) {
    (window as any).G7Core = {};
  }
  const g7 = (window as any).G7Core;

  g7.componentEvent = {
    on: vi.fn((event: string, callback: Function) => {
      if (!listeners.has(event)) {
        listeners.set(event, new Set());
      }
      listeners.get(event)!.add(callback);
      return () => {
        listeners.get(event)?.delete(callback);
      };
    }),
    emit: vi.fn(),
  };
  g7.createLogger = vi.fn(() => ({
    log: vi.fn(),
    warn: vi.fn(),
    error: vi.fn(),
  }));
});

vi.mock('browser-image-compression', () => ({
  default: vi.fn((file: File) => Promise.resolve(file)),
}));

URL.createObjectURL = vi.fn(() => 'blob:mock-url');
URL.revokeObjectURL = vi.fn();

describe('FileUploader - 개수 상한 초과 안내 (useFileUploader 훅)', () => {
  const defaultEndpoints = {
    upload: '/api/attachments',
    delete: '/api/attachments/:id',
    reorder: '/api/attachments/reorder',
  };

  const STABLE_EMPTY_FILES: Attachment[] = [];
  const STABLE_EMPTY_ROLE_IDS: number[] = [];

  function makeFileList(files: File[]): FileList {
    return {
      ...files,
      length: files.length,
      item: (i: number) => files[i] ?? null,
    } as unknown as FileList;
  }

  it('maxFiles 초과 시 onUploadError 로 안내하고 상한까지만 추가한다', async () => {
    const onUploadError = vi.fn();

    const { result } = renderHook(() =>
      useFileUploader({
        collection: 'test',
        maxFiles: 2,
        maxSize: 10,
        maxConcurrentUploads: 3,
        roleIds: STABLE_EMPTY_ROLE_IDS,
        autoUpload: false,
        initialFiles: STABLE_EMPTY_FILES,
        confirmBeforeRemove: false,
        onUploadError,
        endpoints: defaultEndpoints,
      })
    );

    // 상한(2)보다 많은 3개 선택
    const files = [
      new File(['1'], 'a.png', { type: 'image/png' }),
      new File(['2'], 'b.png', { type: 'image/png' }),
      new File(['3'], 'c.png', { type: 'image/png' }),
    ];

    await act(async () => {
      await result.current.handleFiles(makeFileList(files));
    });

    // 초과 안내 콜백 1회 (조용히 버리지 않음)
    expect(onUploadError).toHaveBeenCalledTimes(1);
    // 상한까지만 추가 (2개)
    expect(result.current.pendingFiles).toHaveLength(2);
  });

  it('상한 이내로 선택하면 onUploadError 를 발화하지 않는다 (오발화 없음)', async () => {
    const onUploadError = vi.fn();

    const { result } = renderHook(() =>
      useFileUploader({
        collection: 'test',
        maxFiles: 5,
        maxSize: 10,
        maxConcurrentUploads: 3,
        roleIds: STABLE_EMPTY_ROLE_IDS,
        autoUpload: false,
        initialFiles: STABLE_EMPTY_FILES,
        confirmBeforeRemove: false,
        onUploadError,
        endpoints: defaultEndpoints,
      })
    );

    const files = [
      new File(['1'], 'a.png', { type: 'image/png' }),
      new File(['2'], 'b.png', { type: 'image/png' }),
    ];

    await act(async () => {
      await result.current.handleFiles(makeFileList(files));
    });

    expect(onUploadError).not.toHaveBeenCalled();
    expect(result.current.pendingFiles).toHaveLength(2);
  });
});
