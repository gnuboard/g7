/**
 * FileUploader(사용자) — 업로드 주소를 넘기지 않으면 존재하지 않는 기본 주소로 보내지 않는다
 *
 * 종전 기본값 `/api/attachments`·`/api/attachments/:id`·`/api/attachments/reorder` 는 사용자 화면에 없는 경로였다.
 * 주소를 빠뜨린 화면은 404 만 받고 원인이 드러나지 않았다. 이제 요청을 보내지 않고 그 파일을 실패로 두며,
 * `onUploadError` 를 부르고 개발 경고를 남긴다. 저장 흐름은 `failedCount` 로 저장을 멈춘다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { act, renderHook } from '@testing-library/react';

vi.mock('browser-image-compression', () => ({
  default: vi.fn((file: File) => Promise.resolve(file)),
}));

import type { Attachment } from '../FileUploader/types';
import { useFileUploader } from '../FileUploader/useFileUploader';
import { resolveApiEndpoints } from '../FileUploader/utils';

const STABLE_EMPTY_FILES: Attachment[] = [];
const STABLE_EMPTY_ROLE_IDS: number[] = [];

const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

beforeEach(() => {
  if (!(window as any).G7Core) {
    (window as any).G7Core = {};
  }
  const g7 = (window as any).G7Core;
  const listeners = new Map<string, Set<Function>>();
  g7.componentEvent = {
    on: vi.fn((event: string, cb: Function) => {
      if (!listeners.has(event)) listeners.set(event, new Set());
      listeners.get(event)!.add(cb);
      return () => listeners.get(event)?.delete(cb);
    }),
    emit: vi.fn(async (event: string) => {
      const results = [];
      for (const cb of listeners.get(event) ?? []) results.push(await cb());
      return results;
    }),
  };
  g7.t = vi.fn((key: string) => key);
  g7.toast = { success: vi.fn(), error: vi.fn() };
  g7.api = {
    post: vi.fn().mockResolvedValue({ data: { id: 1 } }),
    patch: vi.fn(),
    delete: vi.fn(),
    get: vi.fn(() => { throw new Error('test: skip auth image'); }),
  };
  warnSpy.mockClear();
  URL.createObjectURL = vi.fn(() => 'blob:x');
  URL.revokeObjectURL = vi.fn();
});

afterEach(() => {
  delete (window as any).G7Core.api;
});

describe('FileUploader(사용자) — 기본 업로드 주소 없음', () => {
  // @scenario case=user_endpoints_without_defaults
  // @effects user_uploader_has_no_default_endpoint
  it('주소를 넘기지 않으면 업로드·삭제 주소가 비고 정렬 주소가 없다', () => {
    expect(resolveApiEndpoints(undefined)).toEqual({ upload: '', delete: '', reorder: null });
  });

  // @scenario case=user_upload_without_endpoint
  // @effects user_uploader_missing_endpoint_reports_error_without_request
  it('업로드 주소 없이 올리면 요청 없이 실패로 두고 onUploadError 와 개발 경고를 낸다', async () => {
    const onUploadError = vi.fn();
    const onUploadComplete = vi.fn();
    const { result } = renderHook(() =>
      useFileUploader({
        collection: 'post_attachments',
        maxFiles: 5,
        maxConcurrentUploads: 1,
        roleIds: STABLE_EMPTY_ROLE_IDS,
        autoUpload: false,
        initialFiles: STABLE_EMPTY_FILES,
        endpoints: resolveApiEndpoints(undefined),
        confirmBeforeRemove: false,
        uploadTriggerEvent: 'upload:test',
        onUploadError,
        onUploadComplete,
      })
    );

    const file = new File(['abc'], 'a.pdf', { type: 'application/pdf' });
    await act(async () => {
      await result.current.handleFiles({ 0: file, length: 1, item: () => file } as unknown as FileList);
    });

    let eventResults: any[] = [];
    await act(async () => {
      eventResults = await (window as any).G7Core.componentEvent.emit('upload:test');
    });

    const g7 = (window as any).G7Core;
    expect(g7.api.post).not.toHaveBeenCalled();
    expect(g7.api.post.mock.calls.some((c: unknown[]) => String(c[0]).startsWith('/api/attachments'))).toBe(false);
    expect(onUploadError).toHaveBeenCalledTimes(1);
    expect(String(onUploadError.mock.calls[0][0])).toContain('a.pdf');
    expect(onUploadComplete).not.toHaveBeenCalled();
    expect(result.current.pendingFiles[0].status).toBe('error');
    // 저장 흐름이 읽는 결과 — 실패 1건이라 저장이 멈춘다
    expect(eventResults[0].failedCount).toBe(1);
    expect(warnSpy.mock.calls.some((c) => String(c.join(' ')).includes('apiEndpoints.upload'))).toBe(true);
  });

  // @scenario case=user_upload_with_endpoint
  // @effects user_uploader_posts_to_given_endpoint
  it('업로드 주소를 넘기면 그 주소로 보낸다(대조군)', async () => {
    const { result } = renderHook(() =>
      useFileUploader({
        collection: 'post_attachments',
        maxFiles: 5,
        maxConcurrentUploads: 1,
        roleIds: STABLE_EMPTY_ROLE_IDS,
        autoUpload: false,
        initialFiles: STABLE_EMPTY_FILES,
        endpoints: resolveApiEndpoints({ upload: '/api/modules/x/attachments' }),
        confirmBeforeRemove: false,
      })
    );

    const file = new File(['abc'], 'a.pdf', { type: 'application/pdf' });
    await act(async () => {
      await result.current.handleFiles({ 0: file, length: 1, item: () => file } as unknown as FileList);
    });
    await act(async () => {
      await result.current.handleUploadAll();
    });

    expect((window as any).G7Core.api.post).toHaveBeenCalledTimes(1);
    expect((window as any).G7Core.api.post.mock.calls[0][0]).toBe('/api/modules/x/attachments');
  });
});
