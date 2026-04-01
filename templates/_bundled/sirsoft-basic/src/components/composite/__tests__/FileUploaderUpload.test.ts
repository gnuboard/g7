/**
 * FileUploader 업로드 응답 파싱 테스트
 *
 * uploadSingleFile의 응답 파싱 로직과 null attachment 방어를 검증합니다.
 * - 정상 응답: pendingFile 제거 + existingFile 추가
 * - data 누락 응답: pendingFile을 에러 상태로 전환
 *
 * @vitest-environment jsdom
 * @module composite/__tests__/FileUploaderUpload.test
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import { useFileUploader } from '../FileUploader/useFileUploader';
import type { Attachment } from '../FileUploader/types';

// G7Core 모킹
// 주의: useFileUploader.ts가 모듈 스코프에서 G7Core = (window as any).G7Core를 캐싱하므로
// window.G7Core를 새 객체로 교체하면 참조가 끊어짐 → 기존 객체에 프로퍼티를 직접 추가해야 함
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
    emit: vi.fn(async (event: string, data?: any) => {
      const eventListeners = listeners.get(event);
      if (!eventListeners) return [];
      const results = [];
      for (const listener of eventListeners) {
        results.push(await listener(data));
      }
      return results;
    }),
  };
  g7.createLogger = vi.fn(() => ({
    log: vi.fn(),
    warn: vi.fn(),
    error: vi.fn(),
  }));
});

// browser-image-compression 모킹
vi.mock('browser-image-compression', () => ({
  default: vi.fn((file: File) => Promise.resolve(file)),
}));

// Node.js의 URL.createObjectURL은 jsdom 환경에서 Blob 인스턴스 불일치로 실패 → mock 필수
URL.createObjectURL = vi.fn(() => 'blob:mock-url');
URL.revokeObjectURL = vi.fn();

// 테스트 헬퍼: Attachment 생성
function createAttachment(overrides: Partial<Attachment> = {}): Attachment {
  const id = overrides.id ?? Math.floor(Math.random() * 10000);
  return {
    id,
    hash: `hash_${id}`,
    original_filename: `image_${id}.jpg`,
    mime_type: 'image/jpeg',
    size: 1024,
    size_formatted: '1 KB',
    download_url: `/api/admin/attachments/${id}/download`,
    order: 1,
    is_image: true,
    ...overrides,
  };
}

describe('FileUploader - 업로드 응답 파싱 (useFileUploader 훅 직접 테스트)', () => {
  // 업로드 테스트에서만 api mock 설정
  // 전체 컴포넌트 대신 훅만 테스트하여 DndContext/SortableThumbnailItem 등의
  // 컴포넌트 트리 오버헤드 없이 업로드 로직(응답 파싱, 에러 처리)을 검증
  const defaultEndpoints = {
    upload: '/api/attachments',
    delete: '/api/attachments/:id',
    reorder: '/api/attachments/reorder',
  };

  // 안정적인 참조 — renderHook 콜백 내에서 매 렌더마다 새 [] 생성하면
  // initialFiles 변경 감지 → setExistingFiles → 재렌더 → 무한 루프 발생
  const STABLE_EMPTY_FILES: Attachment[] = [];
  const STABLE_EMPTY_ROLE_IDS: number[] = [];

  beforeEach(() => {
    const g7 = (window as any).G7Core;
    g7.api = {
      delete: vi.fn().mockResolvedValue({}),
      post: vi.fn().mockResolvedValue({ data: {} }),
      // 인증 이미지 로딩은 테스트 대상이 아님 → 즉시 실패시켜 async 체인 방지
      get: vi.fn(() => { throw new Error('test: skip auth image'); }),
    };
  });

  afterEach(() => {
    // api mock 정리 — 다른 테스트에 영향 방지
    delete (window as any).G7Core.api;
  });

  it('uploadSingleFile이 올바른 응답을 파싱하여 pendingFile을 제거하고 existingFile에 추가한다', async () => {
    const uploadedAttachment = createAttachment({ id: 100, original_filename: 'uploaded.jpg' });

    // G7Core.api.post가 ResponseHelper 형식 응답 반환 (ApiClient가 Axios response.data를 반환)
    (window as any).G7Core.api.post = vi.fn().mockResolvedValue({
      success: true,
      message: 'Upload success',
      data: uploadedAttachment,
    });

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
        endpoints: defaultEndpoints,
      })
    );

    // 파일 추가 (FileList mock)
    const testFile = new File(['test content'], 'test-image.png', { type: 'image/png' });
    await act(async () => {
      const fileList = { 0: testFile, length: 1, item: () => testFile } as unknown as FileList;
      await result.current.handleFiles(fileList);
    });

    // pendingFile이 추가되었는지 확인
    expect(result.current.pendingFiles).toHaveLength(1);
    expect(result.current.pendingFiles[0].original_filename).toBe('test-image.png');
    expect(result.current.pendingFiles[0].status).toBe('pending');

    // 업로드 실행
    await act(async () => {
      await result.current.handleUploadAll();
    });

    // api.post가 호출되었는지 확인
    expect((window as any).G7Core.api.post).toHaveBeenCalled();

    // 업로드 완료 후: pendingFile 제거, existingFile에 추가
    expect(result.current.pendingFiles).toHaveLength(0);
    expect(result.current.existingFiles).toHaveLength(1);
    expect(result.current.existingFiles[0].original_filename).toBe('uploaded.jpg');
    expect(result.current.existingFiles[0].id).toBe(100);
  });

  it('uploadSingleFile 응답에 data가 없으면 pendingFile을 에러 상태로 전환한다', async () => {
    // API 응답에 data 없음 → response?.data = undefined → attachment null
    (window as any).G7Core.api.post = vi.fn().mockResolvedValue({
      success: true,
      message: 'Upload success',
      // data 필드 누락
    });

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

    // 파일 추가
    const testFile = new File(['test'], 'invalid-response.png', { type: 'image/png' });
    await act(async () => {
      const fileList = { 0: testFile, length: 1, item: () => testFile } as unknown as FileList;
      await result.current.handleFiles(fileList);
    });

    // 파일 추가 확인
    expect(result.current.pendingFiles).toHaveLength(1);

    // 업로드 실행
    await act(async () => {
      await result.current.handleUploadAll();
    });

    // 에러 콜백이 호출되어야 함 (업로드중 영구 잔류 대신 에러 상태)
    expect(onUploadError).toHaveBeenCalled();
    expect(onUploadError.mock.calls[0][0]).toContain('invalid-response.png');

    // pendingFile이 에러 상태로 전환됨 (uploading 상태로 영구 잔류하지 않음)
    expect(result.current.pendingFiles).toHaveLength(1);
    expect(result.current.pendingFiles[0].status).toBe('error');
  });
});
