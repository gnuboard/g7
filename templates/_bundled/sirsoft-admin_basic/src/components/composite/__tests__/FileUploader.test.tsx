/**
 * FileUploader 컴포넌트 테스트
 *
 * initialFiles 동기화 로직과 업로드 후 상태 유지를 검증합니다.
 *
 * @module composite/__tests__/FileUploader.test
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, act, renderHook } from '@testing-library/react';
import { useState } from 'react';
import { FileUploader } from '../FileUploader';
import { useFileUploader } from '../FileUploader/useFileUploader';
import type { Attachment } from '../FileUploader/types';

// G7Core 모킹 확장
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
  // api는 전역 설정하지 않음 — 기존 테스트는 api=undefined 상태로 동작
  // (인증 이미지 로딩 시 TypeError 발생 → catch에서 동기적으로 처리 → 빠르게 완료)
  // 업로드 테스트에서만 개별 설정
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

describe('FileUploader - initialFiles 동기화', () => {
  it('initialFiles 참조만 변경되고 내용이 같으면 existingFiles를 리셋하지 않는다', () => {
    // 부모 컴포넌트가 re-render될 때 새 [] 참조를 전달하는 시나리오
    const TestWrapper = () => {
      const [renderCount, setRenderCount] = useState(0);
      // 매 렌더마다 새 빈 배열 생성 (부모 state 변경으로 인한 re-render 시뮬레이션)
      const initialFiles: Attachment[] = [];

      return (
        <div>
          <button data-testid="rerender" onClick={() => setRenderCount((c) => c + 1)}>
            Re-render ({renderCount})
          </button>
          <FileUploader
            initialFiles={initialFiles}
            collection="products"
            maxFiles={20}
            maxSize={10}
            autoUpload={false}
          />
        </div>
      );
    };

    const { getByTestId } = render(<TestWrapper />);

    // 초기 렌더링 후 드롭존이 보여야 한다
    const dropzone = document.querySelector('[class*="border-dashed"]');
    expect(dropzone).toBeTruthy();

    // 부모 re-render 트리거 (새 [] 참조 전달됨)
    act(() => {
      getByTestId('rerender').click();
    });

    // 드롭존이 여전히 보여야 한다 (컴포넌트가 파괴되지 않음)
    const dropzoneAfter = document.querySelector('[class*="border-dashed"]');
    expect(dropzoneAfter).toBeTruthy();
  });

  it('initialFiles 내용이 실제로 변경되면 existingFiles를 업데이트한다', () => {
    const attachment1 = createAttachment({ id: 1 });
    const attachment2 = createAttachment({ id: 2 });

    const TestWrapper = () => {
      const [files, setFiles] = useState<Attachment[]>([attachment1]);

      return (
        <div>
          <button
            data-testid="change-files"
            onClick={() => setFiles([attachment1, attachment2])}
          >
            Change files
          </button>
          <FileUploader
            initialFiles={files}
            collection="products"
            maxFiles={20}
            maxSize={10}
            autoUpload={false}
          />
        </div>
      );
    };

    const { getByTestId } = render(<TestWrapper />);

    // 초기: attachment1만 있음
    expect(screen.getByText(attachment1.original_filename)).toBeTruthy();

    // initialFiles 내용 변경
    act(() => {
      getByTestId('change-files').click();
    });

    // attachment2도 표시되어야 한다
    expect(screen.getByText(attachment2.original_filename)).toBeTruthy();
  });
});

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

  it('ResponseHelper 이중 래핑 응답(data.data)도 올바르게 파싱한다', async () => {
    const uploadedAttachment = createAttachment({ id: 200, original_filename: 'double-wrap.jpg' });

    // ProductController가 ResponseHelper::moduleSuccess(data: { data: {...} }) 형식으로 반환
    // ApiClient가 Axios response.data를 반환하므로 결과 = { success, message, data: { data: {...} } }
    (window as any).G7Core.api.post = vi.fn().mockResolvedValue({
      success: true,
      message: 'Upload success',
      data: { data: uploadedAttachment },
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

    // 파일 추가
    const testFile = new File(['test content'], 'test-double-wrap.png', { type: 'image/png' });
    await act(async () => {
      const fileList = { 0: testFile, length: 1, item: () => testFile } as unknown as FileList;
      await result.current.handleFiles(fileList);
    });

    expect(result.current.pendingFiles).toHaveLength(1);

    // 업로드 실행
    await act(async () => {
      await result.current.handleUploadAll();
    });

    // 이중 래핑 응답에서도 올바르게 Attachment로 파싱됨
    expect(result.current.pendingFiles).toHaveLength(0);
    expect(result.current.existingFiles).toHaveLength(1);
    expect(result.current.existingFiles[0].id).toBe(200);
    expect(result.current.existingFiles[0].original_filename).toBe('double-wrap.jpg');
    expect(result.current.existingFiles[0].hash).toBe('hash_200');
  });

  it('단일 래핑 응답(data: Attachment)도 기존대로 정상 파싱한다', async () => {
    const uploadedAttachment = createAttachment({ id: 300, original_filename: 'single-wrap.jpg' });

    // 이중 래핑 없는 일반 응답
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

    const testFile = new File(['test'], 'test-single-wrap.png', { type: 'image/png' });
    await act(async () => {
      const fileList = { 0: testFile, length: 1, item: () => testFile } as unknown as FileList;
      await result.current.handleFiles(fileList);
    });

    await act(async () => {
      await result.current.handleUploadAll();
    });

    expect(result.current.existingFiles).toHaveLength(1);
    expect(result.current.existingFiles[0].id).toBe(300);
    expect(result.current.existingFiles[0].original_filename).toBe('single-wrap.jpg');
  });
});
