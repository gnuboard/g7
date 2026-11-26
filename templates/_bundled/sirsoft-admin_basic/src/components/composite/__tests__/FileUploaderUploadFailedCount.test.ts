/**
 * FileUploader 업로드 신호 결과에 실패 수를 싣는다
 *
 * 저장 단추는 `emit-event upload:*` 로 업로더를 돌린 뒤 저장 요청을 보낸다. 업로드가 모두 실패해도 리스너 결과에 실패를
 * 알리는 값이 없어 저장이 그대로 이어졌다(올리려던 파일 없이 설정이 저장됨). 결과에 `failedCount` 를 싣고, 저장 흐름이
 * 그 값으로 멈춘다(레이아웃 시험이 잰다).
 *
 * @vitest-environment jsdom
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import { useFileUploader } from '../FileUploader/useFileUploader';
import type { Attachment } from '../FileUploader/types';

beforeEach(() => {
  const listeners = new Map<string, Set<Function>>();
  if (!(window as any).G7Core) (window as any).G7Core = {};
  const g7 = (window as any).G7Core;
  g7.componentEvent = {
    on: vi.fn((event: string, cb: Function) => {
      if (!listeners.has(event)) listeners.set(event, new Set());
      listeners.get(event)!.add(cb);
      return () => listeners.get(event)?.delete(cb);
    }),
    emit: vi.fn(async (event: string) => {
      const out = [];
      for (const l of listeners.get(event) ?? []) out.push(await l());
      return out;
    }),
  };
  g7.createLogger = vi.fn(() => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }));
  g7.t = vi.fn((key: string) => key);
  URL.createObjectURL = vi.fn(() => 'blob:x');
  URL.revokeObjectURL = vi.fn();
});

afterEach(() => {
  delete (window as any).G7Core.api;
});

vi.mock('browser-image-compression', () => ({ default: vi.fn((f: File) => Promise.resolve(f)) }));

const EMPTY: Attachment[] = [];
const ROLES: number[] = [];
const fileList = (files: File[]): FileList => ({ ...files, length: files.length, item: (i: number) => files[i] ?? null }) as unknown as FileList;

function setup(event: string, onUploadError = vi.fn()) {
  return renderHook(() =>
    useFileUploader({
      collection: 'site_logo',
      maxFiles: 5,
      maxSize: 10,
      maxConcurrentUploads: 2,
      roleIds: ROLES,
      autoUpload: false,
      initialFiles: EMPTY,
      confirmBeforeRemove: false,
      onUploadError,
      uploadTriggerEvent: event,
      endpoints: { upload: '/api/attachments', delete: '/api/attachments/:id', reorder: '' },
    })
  );
}

describe('업로드 신호 결과의 failedCount', () => {
  /**
   * @scenario upload_result=all_failed
   * @effects upload_signal_reports_failed_count
   */
  it('업로드가 모두 실패하면 failedCount 가 올리려던 수와 같고, 업로더가 실패를 알린다', async () => {
    (window as any).G7Core.api = { post: vi.fn(() => Promise.reject(new Error('network'))) };
    const onUploadError = vi.fn();
    const { result } = setup('upload:fail_all', onUploadError);
    await act(async () => {
      await result.current.handleFiles(fileList([new File(['a'], 'a.pdf', { type: 'application/pdf' }), new File(['b'], 'b.pdf', { type: 'application/pdf' })]));
    });

    let results: any[] = [];
    await act(async () => {
      results = await (window as any).G7Core.componentEvent.emit('upload:fail_all');
    });

    expect(results[0].failedCount, '업로드 실패가 결과에 실리지 않았습니다').toBe(2);
    expect(results[0].uploadedAttachments).toEqual([]);
    expect(onUploadError).toHaveBeenCalledTimes(2);
  });

  /**
   * @scenario upload_result=partial
   * @effects upload_signal_reports_failed_count
   */
  it('일부만 실패하면 실패한 수만 싣는다', async () => {
    let n = 0;
    (window as any).G7Core.api = {
      post: vi.fn((_u: string, form: FormData) => {
        const f = form.get('file') as File;
        n++;
        return f.name === 'bad.pdf'
          ? Promise.reject(new Error('network'))
          : Promise.resolve({ success: true, data: { id: n, hash: `h${n}`, original_filename: f.name, mime_type: 'application/pdf', size: 1, size_formatted: '1 B', download_url: '', order: n, is_image: false } });
      }),
    };
    const { result } = setup('upload:partial');
    await act(async () => {
      await result.current.handleFiles(fileList([new File(['a'], 'ok.pdf', { type: 'application/pdf' }), new File(['b'], 'bad.pdf', { type: 'application/pdf' })]));
    });
    let results: any[] = [];
    await act(async () => {
      results = await (window as any).G7Core.componentEvent.emit('upload:partial');
    });

    expect(results[0].failedCount).toBe(1);
    expect(results[0].uploadedAttachments.map((a: Attachment) => a.original_filename)).toEqual(['ok.pdf']);
  });

  /**
   * @scenario upload_result=nothing_to_upload
   * @effects upload_signal_reports_failed_count
   */
  it('올릴 파일이 없으면 failedCount 는 0 이다', async () => {
    const { result } = setup('upload:none');
    let results: any[] = [];
    await act(async () => {
      results = await (window as any).G7Core.componentEvent.emit('upload:none');
    });
    expect(result.current.pendingFiles).toEqual([]);
    expect(results[0].failedCount).toBe(0);
  });
});
