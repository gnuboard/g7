/**
 * FileUploader — 언어 전환 뒤에도 저장 전에 고른 대기 파일이 남는다
 *
 * 언어 전환은 엔진을 다시 띄우고 같은 화면을 새로 마운트한다. 대기 파일(autoUpload=false 로 고른 뒤 저장 때
 * 올리는 파일)은 업로더 안에만 있어, 관리자 페이지 작성 화면에서 첨부를 고른 뒤 언어를 바꾸면 사라졌다 — 사용자 템플릿의 글쓰기 업로더도 같은 구조
 * (7.1.0 실측 O-G3-3). 업로더는 언어 전환으로 언마운트될 때 엔진의 인계 저장소(G7Core.locale.handoff)에
 * 대기 파일을 맡기고, 새 인스턴스가 마운트될 때 되찾는다.
 *
 * @module composite/__tests__/FileUploaderLocaleHandoff.test
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import { useFileUploader } from '../FileUploader/useFileUploader';

vi.mock('browser-image-compression', () => ({
  default: vi.fn((file: File) => Promise.resolve(file)),
}));

URL.createObjectURL = vi.fn(() => 'blob:mock-url');
URL.revokeObjectURL = vi.fn();

const endpoints = { upload: '/api/attachments', delete: '/api/attachments/:id', reorder: '' };
const STABLE_EMPTY: never[] = [];

/**
 * 업로더 훅 옵션 (페이지 작성 화면과 같은 저장 때 올리기 방식)
 *
 * @returns 훅 옵션
 */
function options() {
  return {
    collection: 'page_attachments',
    maxFiles: 5,
    maxSize: 10,
    maxConcurrentUploads: 1,
    roleIds: STABLE_EMPTY,
    autoUpload: false,
    initialFiles: STABLE_EMPTY,
    confirmBeforeRemove: false,
    uploadTriggerEvent: 'upload:page_attachments',
    endpoints,
  };
}

/**
 * 엔진 인계 저장소 계약(engine-v1.79.0 localeSwitchHandoff)을 그대로 흉내 낸 스텁
 *
 * @returns 스텁과 전환 상태 조작 함수
 */
function installHandoff() {
  const entries = new Map<string, unknown>();
  let switching = false;
  (window as any).G7Core.locale = {
    handoff: {
      isSwitching: () => switching,
      stash: (key: string, value: unknown) => {
        if (!switching) return false;
        entries.set(key, value);
        return true;
      },
      take: (key: string) => {
        const value = entries.get(key);
        entries.delete(key);
        return value;
      },
    },
  };
  return { setSwitching: (v: boolean) => { switching = v; }, entries };
}

/**
 * 파일 하나를 고른 FileList 를 만듭니다.
 *
 * @param name 파일 이름
 * @returns FileList 흉내
 */
function fileList(name: string): FileList {
  const file = new File(['qa736 pg'], name, { type: 'text/plain' });
  return { 0: file, length: 1, item: () => file, [Symbol.iterator]: function* () { yield file; } } as unknown as FileList;
}

describe('FileUploader - 언어 전환 인계', () => {
  beforeEach(() => {
    window.history.replaceState(null, '', '/board/qa736-pg-a/write');
    (window as any).G7Core = (window as any).G7Core || {};
    const g7 = (window as any).G7Core;
    g7.createLogger = vi.fn(() => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }));
    g7.componentEvent = { on: vi.fn(() => () => {}), emit: vi.fn(async () => []) };
    g7.toast = { error: vi.fn(), warning: vi.fn(), success: vi.fn() };
  });

  afterEach(() => {
    delete (window as any).G7Core.locale;
  });

  // @scenario case=locale_switch_remount
  // @effects file_uploader_pending_files_survive_locale_switch
  it('언어 전환으로 다시 마운트되면 저장 전에 고른 대기 파일을 되찾는다', async () => {
    const { setSwitching } = installHandoff();
    const first = renderHook(() => useFileUploader(options()));
    await act(async () => {
      await first.result.current.handleFiles(fileList('pg_note.txt'));
    });
    expect(first.result.current.pendingFiles.map((f) => f.original_filename)).toEqual(['pg_note.txt']);

    setSwitching(true);
    first.unmount();
    setSwitching(false);

    const second = renderHook(() => useFileUploader(options()));
    expect(second.result.current.pendingFiles.map((f) => f.original_filename)).toEqual(['pg_note.txt']);
    expect(second.result.current.pendingFiles[0].file).toBeInstanceOf(File);
  });

  // @scenario case=normal_unmount
  // @effects file_uploader_pending_files_not_carried_outside_locale_switch
  it('언어 전환이 아닌 언마운트(다른 화면으로 이동)는 대기 파일을 넘기지 않는다', async () => {
    const { entries } = installHandoff();
    const first = renderHook(() => useFileUploader(options()));
    await act(async () => {
      await first.result.current.handleFiles(fileList('pg_note.txt'));
    });
    first.unmount();

    expect(entries.size).toBe(0);
    const second = renderHook(() => useFileUploader(options()));
    expect(second.result.current.pendingFiles).toEqual([]);
  });

  // @scenario case=locale_switch_remount_reordered
  // @effects file_uploader_custom_order_survives_locale_switch
  it('기존 첨부와 대기 파일 사이에 드래그로 정한 순서도 함께 넘긴다', async () => {
    const { setSwitching } = installHandoff();
    const existing = [{ id: 7001, hash: 'e7001', original_filename: 'old.pdf', mime_type: 'application/pdf', size: 10, size_formatted: '10 B', download_url: '/api/attachment/e7001', order: 1, is_image: false }];
    const withExisting = () => ({ ...options(), initialFiles: existing as any });
    const first = renderHook(() => useFileUploader(withExisting()));
    await act(async () => {
      await first.result.current.handleFiles(fileList('new.txt'));
    });
    const pendingId = first.result.current.pendingFiles[0].id;
    await act(async () => {
      await first.result.current.handleDragEnd({ active: { id: pendingId }, over: { id: 'e7001' } } as any);
    });
    const orderBefore = first.result.current.allItems.map((i: any) => i.original_filename);
    expect(orderBefore).toEqual(['new.txt', 'old.pdf']);

    setSwitching(true);
    first.unmount();
    setSwitching(false);

    const second = renderHook(() => useFileUploader(withExisting()));
    expect(second.result.current.allItems.map((i: any) => i.original_filename)).toEqual(orderBefore);
  });

  // @scenario case=locale_switch_while_uploading
  // @effects file_uploader_in_flight_items_back_to_pending
  it('올리던 중(업로드·압축) 넘어온 항목은 대기로 되돌려 저장 때 다시 올린다', () => {
    const { setSwitching } = installHandoff();
    const file = new File(['x'], 'up.txt', { type: 'text/plain' });
    setSwitching(true);
    (window as any).G7Core.locale.handoff.stash(`FileUploader:${window.location.pathname}:page_attachments:upload:page_attachments`, {
      pending: [{ id: 'p1', file, original_filename: 'up.txt', mime_type: 'text/plain', size: 1, size_formatted: '1 B', status: 'uploading', progress: 40 }],
      order: [],
      reordered: false,
    });
    setSwitching(false);
    const second = renderHook(() => useFileUploader(options()));
    expect(second.result.current.pendingFiles[0].status).toBe('pending');
    expect(second.result.current.pendingFiles[0].progress).toBe(0);
  });
});
