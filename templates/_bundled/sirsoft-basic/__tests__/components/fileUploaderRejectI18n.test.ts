/**
 * @file fileUploaderRejectI18n.test.ts
 * @description 첨부 컴포지트의 거절 문구 다국어화 (#599 validate 2026-09-23)
 *
 * 용량·형식 거절 문구가 한국어 리터럴이라, 신청서 화면이 그 문구를 토스트로 그대로 띄우면(onUploadError)
 * 영어·일본어 화면에도 한국어가 떴다. 문구는 `attachment.*` 키로 만든다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, vi } from 'vitest';

// utils.ts 는 모듈 로드 시점에 window.G7Core 를 잡아 두므로, import 전에 번역 함수를 세운다.
vi.hoisted(() => {
  (window as any).G7Core = {
    ...((window as any).G7Core ?? {}),
    t: (key: string, params?: Record<string, unknown>) =>
      `T(${key}${params ? ':' + Object.keys(params).sort().map((k) => `${k}=${params[k]}`).join(',') : ''})`,
    componentEvent: { on: () => () => {}, emit: () => {} },
    createLogger: () => ({ log: () => {}, warn: () => {}, error: () => {} }),
  };
});

vi.mock('browser-image-compression', () => ({
  default: vi.fn((file: File) => Promise.resolve(file)),
}));

import { act, renderHook } from '@testing-library/react';
import { useFileUploader } from '../../src/components/composite/FileUploader/useFileUploader';
import type { Attachment } from '../../src/components/composite/FileUploader/types';

URL.createObjectURL = vi.fn(() => 'blob:mock-url');
URL.revokeObjectURL = vi.fn();

const EMPTY_FILES: Attachment[] = [];
const EMPTY_ROLES: number[] = [];

function fileList(files: File[]): FileList {
  return { ...files, length: files.length, item: (i: number) => files[i] ?? null } as unknown as FileList;
}

function renderUploader(onUploadError: ReturnType<typeof vi.fn>, overrides: Record<string, any> = {}) {
  return renderHook(() =>
    useFileUploader({
      collection: 'test',
      maxFiles: 5,
      maxSize: 1,
      maxConcurrentUploads: 3,
      roleIds: EMPTY_ROLES,
      autoUpload: false,
      initialFiles: EMPTY_FILES,
      confirmBeforeRemove: false,
      onUploadError,
      endpoints: { upload: '/api/a', delete: '/api/a/:id', reorder: '/api/a/reorder' },
      ...overrides,
    })
  );
}

describe('FileUploader 거절 문구 — attachment.* 키', () => {
  /**
   * @scenario screen=claim_apply, fault=uploader_reject_text
   * @effects file_uploader_reject_messages_are_translated
   */
  it('용량 초과 문구는 attachment.file_too_large 키로 만든다', async () => {
    const onUploadError = vi.fn();
    const { result } = renderUploader(onUploadError);
    const big = new File([new Uint8Array(2 * 1024 * 1024)], 'big.jpg', { type: 'image/jpeg' });

    await act(async () => {
      await result.current.handleFiles(fileList([big]));
    });

    const message = String(onUploadError.mock.calls[0]?.[0] ?? '');
    expect(message).toContain('T(attachment.file_too_large');
    expect(message).toContain('name=big.jpg');
    expect(message).not.toMatch(/[가-힣]/);
  });

  /**
   * @scenario screen=claim_apply, fault=uploader_reject_text
   * @effects file_uploader_reject_messages_are_translated
   */
  it('형식 거절 문구는 attachment.file_type_not_allowed 키로 만든다', async () => {
    const onUploadError = vi.fn();
    const { result } = renderUploader(onUploadError, { accept: '.png' });
    const doc = new File(['x'], 'memo.txt', { type: 'text/plain' });

    await act(async () => {
      await result.current.handleFiles(fileList([doc]));
    });

    const message = String(onUploadError.mock.calls[0]?.[0] ?? '');
    expect(message).toContain('T(attachment.file_type_not_allowed');
    expect(message).toContain('accept=.png');
    expect(message).not.toMatch(/[가-힣]/);
  });
});
