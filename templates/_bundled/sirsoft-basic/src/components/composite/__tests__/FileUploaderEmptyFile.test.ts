/**
 * FileUploader 빈 파일(0바이트) 선검사 테스트 (7.1.0 N O-N7)
 *
 * 0바이트 파일을 고르면 목록에 「0.00 B」 로 올라가 제출 때에야 서버가 거부했다. 크기 검사처럼 고르는 자리에서
 * 걸러 내고 onUploadError 로 안내한다(서버 거부 문구 「빈 파일(0바이트)은 올릴 수 없습니다」 와 같은 뜻).
 *
 * @vitest-environment jsdom
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

describe('FileUploader - 빈 파일(0바이트) 선검사 (useFileUploader 훅)', () => {
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

  /**
   * @scenario template=user_basic
   * @effects empty_file_rejected_before_submit
   */
  it('0바이트 파일은 목록에 넣지 않고 그 자리에서 안내한다(제출 때 서버 422 를 기다리지 않음)', async () => {
    const onUploadError = vi.fn();
    // 번역기는 키와 파일 이름을 이어 돌려준다 — 안내 문구가 번역 키를 거치고 파일 이름을 싣는지 본다.
    (window as any).G7Core.t = vi.fn((key: string, params?: Record<string, unknown>) =>
      params?.name ? `${key}|${String(params.name)}` : key
    );

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
      new File([], 'empty.png', { type: 'image/png' }),
      new File(['1'], 'ok.png', { type: 'image/png' }),
    ];

    await act(async () => {
      await result.current.handleFiles(makeFileList(files));
    });

    expect(onUploadError).toHaveBeenCalledTimes(1);
    expect(String(onUploadError.mock.calls[0][0])).toBe('attachment.empty_file|empty.png');
    expect(onUploadError.mock.calls[0][1]?.name).toBe('empty.png');
    expect(result.current.pendingFiles.map((p: any) => p.original_filename)).toEqual(['ok.png']);
  });
});
