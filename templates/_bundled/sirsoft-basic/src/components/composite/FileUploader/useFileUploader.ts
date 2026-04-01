/**
 * useFileUploader Hook
 *
 * FileUploader의 핵심 로직을 담당하는 훅입니다.
 * - 파일 추가/삭제/업로드
 * - 이미지 압축
 * - 병렬 업로드 큐
 * - 순서 변경
 *
 * @module composite/FileUploader/useFileUploader
 */

import { useState, useCallback, useRef, useEffect, useMemo } from 'react';
import imageCompression from 'browser-image-compression';

import type { Attachment, PendingFile, FileUploaderProps, ApiEndpoints } from './types';
import { formatFileSize, extractErrorMessage, t } from './utils';

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const G7Core = (window as any).G7Core;

export interface UseFileUploaderOptions {
  attachmentableType?: string;
  attachmentableId?: number;
  collection: string;
  maxFiles: number;
  maxSize: number;
  maxConcurrentUploads: number;
  accept?: string;
  imageCompression?: FileUploaderProps['imageCompression'];
  roleIds: number[];
  autoUpload: boolean;
  initialFiles: Attachment[];
  endpoints: ApiEndpoints;
  onFilesChange?: (files: PendingFile[]) => void;
  onUploadComplete?: (attachments: Attachment[]) => void;
  onUploadError?: (error: string, file: File) => void;
  onRemove?: (id: number | string) => void;
  onReorder?: (files: Attachment[]) => void;
  uploadTriggerEvent?: string;
  confirmBeforeRemove: boolean;
  uploadParams?: Record<string, string>;
}

export interface UseFileUploaderReturn {
  // 상태
  existingFiles: Attachment[];
  pendingFiles: PendingFile[];
  isDragOver: boolean;
  setIsDragOver: (value: boolean) => void;
  isDeleting: boolean;

  // 계산된 값
  totalCount: number;
  hasFiles: boolean;
  canAddMore: boolean;
  allItems: (Attachment | PendingFile)[];
  imageFiles: (Attachment | PendingFile)[];

  // 액션
  handleFiles: (files: FileList) => Promise<void>;
  handleRemove: (item: PendingFile | Attachment) => Promise<void>;
  handleRetry: (pendingFile: PendingFile) => void;
  handleUploadAll: () => Promise<Attachment[]>;
  handleOpenGallery: (item: PendingFile | Attachment) => void;
  handleDownload: (item: Attachment) => Promise<void>;
  handleDragEnd: (event: import('@dnd-kit/core').DragEndEvent) => Promise<void>;

  // Input ref
  inputRef: React.RefObject<HTMLInputElement | null>;

  // 갤러리 상태
  galleryOpen: boolean;
  setGalleryOpen: (value: boolean) => void;
  galleryStartIndex: number;
  galleryKeyRef: React.MutableRefObject<number>;

  // 삭제 확인 모달 상태
  confirmDialogOpen: boolean;
  setConfirmDialogOpen: (value: boolean) => void;
  itemToDelete: Attachment | null;
  executeRemoveAttachment: (item: Attachment) => Promise<void>;

  // 인증된 이미지 URL 캐시
  authenticatedImageUrls: Map<number, string>;

  // 초기화
  clear: () => void;
  getPendingFiles: () => PendingFile[];
}

export function useFileUploader(options: UseFileUploaderOptions): UseFileUploaderReturn {
  const {
    attachmentableType,
    attachmentableId,
    collection,
    maxFiles,
    maxSize,
    maxConcurrentUploads,
    accept,
    imageCompression: compressionOptions,
    roleIds,
    autoUpload,
    initialFiles,
    endpoints,
    onFilesChange,
    onUploadComplete,
    onUploadError,
    onRemove,
    onReorder,
    uploadTriggerEvent,
    confirmBeforeRemove,
    uploadParams,
  } = options;

  // 상태
  const [existingFiles, setExistingFiles] = useState<Attachment[]>(initialFiles);
  const [pendingFiles, setPendingFiles] = useState<PendingFile[]>([]);
  const [isDragOver, setIsDragOver] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);
  const activeUploadsRef = useRef(0);
  // 삭제된 파일 ID 또는 hash를 추적하여 initialFiles 동기화 시 제외
  const deletedIdsRef = useRef<Set<number | string>>(new Set());
  // 세션 중 업로드된 파일을 추적하여 initialFiles 동기화 시 유지
  const uploadedFilesRef = useRef<Map<number, Attachment>>(new Map());

  // 삭제 확인 모달 상태
  const [confirmDialogOpen, setConfirmDialogOpen] = useState(false);
  const [itemToDelete, setItemToDelete] = useState<Attachment | null>(null);
  const [isDeleting, setIsDeleting] = useState(false);
  // 순서가 변경되었는지 추적 (변경 후에는 initialFiles 동기화 무시)
  const hasReorderedRef = useRef(false);
  // 드래그 순서 유지: 기존/신규 파일 간 섞인 순서를 보존하기 위한 ID 배열
  const [customOrder, setCustomOrder] = useState<(string | number)[]>([]);
  // initialFiles 참조 안정성 — 외부에서 매 렌더마다 새 배열이 전달되는 경우 방어
  const prevInitialFilesRef = useRef<Attachment[]>(initialFiles);

  // 이미지 갤러리 상태
  const [galleryOpen, setGalleryOpen] = useState(false);
  const [galleryStartIndex, setGalleryStartIndex] = useState(0);
  // 갤러리 리마운트용 key (열릴 때마다 증가)
  const galleryKeyRef = useRef(0);
  // 인증된 이미지 URL 캐시 (기존 첨부파일용)
  const [authenticatedImageUrls, setAuthenticatedImageUrls] = useState<Map<number, string>>(new Map());

  // 대기 파일 변경 시 콜백
  useEffect(() => {
    onFilesChange?.(pendingFiles);
  }, [pendingFiles, onFilesChange]);

  // initialFiles 참조 안정화 — 매 렌더마다 새 배열이 전달되어도 내용이 같으면 무시
  const stableInitialFiles = useMemo(() => {
    const prev = prevInitialFilesRef.current;
    // 동일 참조면 즉시 반환
    if (prev === initialFiles) return prev;
    // 길이가 다르면 실제 변경
    if (prev.length !== initialFiles.length) {
      prevInitialFilesRef.current = initialFiles;
      return initialFiles;
    }
    // 빈 배열끼리는 항상 동일 (가장 흔한 케이스 — 무한 루프 방지)
    if (prev.length === 0 && initialFiles.length === 0) return prev;
    // ID 기반 얕은 비교
    const changed = initialFiles.some((f, i) => f.id !== prev[i]?.id);
    if (changed) {
      prevInitialFilesRef.current = initialFiles;
      return initialFiles;
    }
    return prev;
  }, [initialFiles]);

  // initialFiles prop 변경 시 existingFiles 동기화 (삭제/순서변경된 경우 제외)
  useEffect(() => {
    // 순서가 변경된 후에는 initialFiles 동기화를 무시
    if (hasReorderedRef.current) {
      return;
    }
    const filteredFiles = stableInitialFiles.filter(
      (file) => !deletedIdsRef.current.has(file.id) && (!file.hash || !deletedIdsRef.current.has(file.hash))
    );

    // 세션 중 업로드된 파일 병합 (initialFiles에 없는 것만 추가)
    const initialIds = new Set(filteredFiles.map((f) => f.id));
    const sessionUploaded = Array.from(uploadedFilesRef.current.values()).filter(
      (f) => !initialIds.has(f.id) && !deletedIdsRef.current.has(f.id)
    );

    setExistingFiles([...filteredFiles, ...sessionUploaded]);
  }, [stableInitialFiles]);

  // 이미지 압축
  const compressImage = useCallback(
    async (file: File): Promise<Blob> => {
      const compressionOpts = {
        maxSizeMB: compressionOptions?.maxSizeMB ?? 1,
        maxWidthOrHeight: compressionOptions?.maxWidthOrHeight ?? 1920,
        useWebWorker: true,
      };
      return await imageCompression(file, compressionOpts);
    },
    [compressionOptions]
  );

  // 파일 추가 처리
  const handleFiles = useCallback(
    async (selectedFiles: FileList) => {
      const totalCount = existingFiles.length + pendingFiles.length;
      const remainingSlots = maxFiles - totalCount;
      const filesToAdd = Array.from(selectedFiles).slice(0, remainingSlots);

      for (const file of filesToAdd) {
        // 크기 검증
        if (file.size > maxSize * 1024 * 1024) {
          onUploadError?.(
            `[${file.name}] 파일 크기(${formatFileSize(file.size)})가 최대 허용 크기(${maxSize}MB)를 초과합니다.`,
            file
          );
          continue;
        }

        // 확장자/MIME 타입 검증
        if (accept) {
          const ext = '.' + file.name.split('.').pop()?.toLowerCase();
          const acceptedTypes = accept.split(',').map((e) => e.trim().toLowerCase());
          const isAccepted = acceptedTypes.some((accepted) => {
            // MIME 타입 와일드카드 (예: image/*, video/*, audio/*)
            if (accepted.includes('/')) {
              if (accepted.endsWith('/*')) {
                const mimePrefix = accepted.slice(0, -2);
                return file.type.toLowerCase().startsWith(mimePrefix + '/');
              }
              // 정확한 MIME 타입 매칭 (예: image/jpeg)
              return file.type.toLowerCase() === accepted;
            }
            // 확장자 매칭 (예: .jpg, .png)
            return ext === accepted;
          });
          if (!isAccepted) {
            onUploadError?.(`[${file.name}] 허용되지 않는 파일 형식입니다. (허용: ${accept})`, file);
            continue;
          }
        }

        const pendingFile: PendingFile = {
          id: `pending-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`,
          file,
          original_filename: file.name,
          mime_type: file.type,
          size: file.size,
          size_formatted: formatFileSize(file.size),
          status: 'pending',
          progress: 0,
        };

        // 이미지 미리보기 생성
        if (file.type.startsWith('image/')) {
          pendingFile.preview = URL.createObjectURL(file);
        }

        setPendingFiles((prev) => [...prev, pendingFile]);

        // 이미지인 경우 압축 처리
        if (file.type.startsWith('image/') && compressionOptions) {
          setPendingFiles((prev) =>
            prev.map((f) => (f.id === pendingFile.id ? { ...f, status: 'compressing' } : f))
          );

          try {
            const compressedBlob = await compressImage(file);
            setPendingFiles((prev) =>
              prev.map((f) =>
                f.id === pendingFile.id
                  ? {
                      ...f,
                      compressedBlob,
                      size: compressedBlob.size,
                      size_formatted: formatFileSize(compressedBlob.size),
                      status: 'pending',
                      progress: 0,
                    }
                  : f
              )
            );
          } catch {
            setPendingFiles((prev) =>
              prev.map((f) =>
                f.id === pendingFile.id
                  ? { ...f, status: 'error', error: '이미지 압축 실패' }
                  : f
              )
            );
          }
        }
      }
    },
    [existingFiles.length, pendingFiles.length, maxFiles, maxSize, accept, compressionOptions, compressImage, onUploadError]
  );

  // 단일 파일 업로드
  const uploadSingleFile = useCallback(
    async (pendingFile: PendingFile): Promise<Attachment | null> => {
      const formData = new FormData();

      // 압축된 Blob 또는 원본 파일
      const fileToUpload = pendingFile.compressedBlob
        ? new File([pendingFile.compressedBlob], pendingFile.original_filename, {
            type: pendingFile.mime_type,
          })
        : pendingFile.file;

      formData.append('file', fileToUpload);

      if (attachmentableType) {
        formData.append('attachmentable_type', attachmentableType);
      }
      if (attachmentableId) {
        formData.append('attachmentable_id', String(attachmentableId));
      }
      formData.append('collection', collection);

      roleIds.forEach((roleId) => {
        formData.append('role_ids[]', String(roleId));
      });

      // 추가 업로드 파라미터 (예: temp_key)
      if (uploadParams) {
        Object.entries(uploadParams).forEach(([key, value]) => {
          formData.append(key, value);
        });
      }

      try {
        const response = await G7Core.api.post(endpoints.upload, formData, {
          headers: { 'Content-Type': 'multipart/form-data' },
          onUploadProgress: (progressEvent: { loaded: number; total?: number }) => {
            const progress = Math.round((progressEvent.loaded * 100) / (progressEvent.total ?? 1));
            setPendingFiles((prev) =>
              prev.map((f) => (f.id === pendingFile.id ? { ...f, progress } : f))
            );
          },
        });

        // ResponseHelper 이중 래핑 대응: response.data.data (래핑) 또는 response.data (비래핑)
        const attachment = response?.data?.data ?? response?.data;
        return attachment as Attachment;
      } catch (error: unknown) {
        // API 에러 응답에서 상세 메시지 추출
        const errorMessage = extractErrorMessage(error);
        throw new Error(errorMessage);
      }
    },
    [attachmentableType, attachmentableId, collection, roleIds, endpoints.upload]
  );

  // 모든 파일 업로드
  const handleUploadAll = useCallback(async (): Promise<Attachment[]> => {
    const filesToUpload = pendingFiles.filter(
      (f) => f.status === 'pending' || f.status === 'error'
    );

    if (filesToUpload.length === 0) {
      return [];
    }

    const uploadedAttachments: Attachment[] = [];
    // 드래그 순서 복원용: pending ID → 업로드된 hash 매핑
    const pendingToHashMap = new Map<string, string>();
    const uploadQueue = [...filesToUpload];

    const processQueue = async () => {
      while (uploadQueue.length > 0 && activeUploadsRef.current < maxConcurrentUploads) {
        const pendingFile = uploadQueue.shift()!;
        activeUploadsRef.current++;

        setPendingFiles((prev) =>
          prev.map((f) => (f.id === pendingFile.id ? { ...f, status: 'uploading', progress: 0 } : f))
        );

        try {
          const attachment = await uploadSingleFile(pendingFile);
          if (attachment) {
            uploadedAttachments.push(attachment);
            // 드래그 순서 복원용: pending ID → 업로드된 hash 매핑
            pendingToHashMap.set(String(pendingFile.id), String(attachment.hash));
            // 세션 중 업로드된 파일 추적 (initialFiles 동기화 시 유지)
            uploadedFilesRef.current.set(attachment.id, attachment);
            setPendingFiles((prev) => prev.filter((f) => f.id !== pendingFile.id));
            setExistingFiles((prev) => [...prev, attachment]);

            // customOrder에 pending ID가 있으면 즉시 hash로 교체
            // → allItems useMemo가 올바른 순서로 E를 표시 (UI 플래시 방지)
            setCustomOrder((prev) => {
              if (prev.length === 0) return prev;
              const pendingId = String(pendingFile.id);
              const hasMatch = prev.some((id) => String(id) === pendingId);
              if (!hasMatch) return prev;
              return prev.map((id) => String(id) === pendingId ? String(attachment.hash) : id);
            });

            // 미리보기 URL 해제
            if (pendingFile.preview) {
              URL.revokeObjectURL(pendingFile.preview);
            }
          } else {
            // 업로드 API 성공이지만 응답 파싱 실패 → 에러 상태로 전환 ('uploading' 영구 잔류 방지)
            const invalidMsg = t('attachment.upload_response_invalid');
            setPendingFiles((prev) =>
              prev.map((f) =>
                f.id === pendingFile.id
                  ? { ...f, status: 'error', error: invalidMsg }
                  : f
              )
            );
            onUploadError?.(
              `[${pendingFile.original_filename}] ${invalidMsg}`,
              pendingFile.file
            );
          }
        } catch (error) {
          const errorMessage = error instanceof Error ? error.message : '업로드 실패';
          setPendingFiles((prev) =>
            prev.map((f) =>
              f.id === pendingFile.id ? { ...f, status: 'error', error: errorMessage } : f
            )
          );
          onUploadError?.(`[${pendingFile.original_filename}] ${errorMessage}`, pendingFile.file);
        } finally {
          activeUploadsRef.current--;
        }
      }
    };

    // 병렬 업로드 시작
    const workers = Array(Math.min(maxConcurrentUploads, filesToUpload.length))
      .fill(null)
      .map(() => processQueue());

    await Promise.all(workers);

    if (uploadedAttachments.length > 0) {
      onUploadComplete?.(uploadedAttachments);

      // 편집 모드(reorder 엔드포인트 존재)에서 드래그 순서가 있는 경우에만 onReorder 호출
      // → form.images를 올바른 순서로 덮어쓰기 (onUploadComplete의 append 순서를 교정)
      // 복사/생성 모드에서는 호출하지 않음 (linkTempImages가 별도 처리하므로 중복 방지)
      // NOTE: customOrder의 pending ID→hash 교체는 processQueue 내에서 즉시 처리됨
      if (endpoints.reorder && customOrder.length > 0 && onReorder) {
        const allExisting = [...existingFiles, ...uploadedAttachments];

        // pending ID를 업로드된 hash로 교체 (이미 setCustomOrder로 갱신되었지만, 클로저 값 기준 재계산)
        const updatedOrder = customOrder.map((id) => {
          const hash = pendingToHashMap.get(String(id));
          return hash ?? id;
        });

        const orderMap = new Map(updatedOrder.map((id, idx) => [String(id), idx]));
        const ordered = [...allExisting]
          .sort((a, b) => {
            const aOrder = orderMap.get(String(a.hash)) ?? allExisting.length;
            const bOrder = orderMap.get(String(b.hash)) ?? allExisting.length;
            return aOrder - bOrder;
          })
          .map((item, idx) => ({ ...item, sort_order: idx, order: idx + 1 }));

        onReorder(ordered);
      }
    }

    return uploadedAttachments;
  }, [pendingFiles, maxConcurrentUploads, uploadSingleFile, onUploadComplete, onUploadError, customOrder, existingFiles, onReorder, endpoints.reorder]);

  // 업로드 트리거 이벤트 구독 (uploadTriggerEvent가 있는 경우)
  useEffect(() => {
    if (!uploadTriggerEvent) return;

    const unsubscribe = G7Core.componentEvent.on(uploadTriggerEvent, async () => {
      // 업로드할 파일이 있으면 업로드, 없으면 기존 파일 ID 반환
      const uploadedAttachments = await handleUploadAll();
      return {
        uploadedAttachments,
        existingFiles,
        allFiles: [...existingFiles, ...uploadedAttachments],
      };
    });

    return () => {
      unsubscribe();
    };
  }, [uploadTriggerEvent, handleUploadAll, existingFiles]);

  // autoUpload가 true이고 pendingFiles가 추가되면 자동 업로드
  useEffect(() => {
    if (autoUpload && pendingFiles.some((f) => f.status === 'pending')) {
      handleUploadAll();
    }
  }, [autoUpload, pendingFiles, handleUploadAll]);

  // 실제 삭제 실행 (기존 첨부파일)
  const executeRemoveAttachment = useCallback(
    async (item: Attachment) => {
      setIsDeleting(true);
      try {
        // id가 있는 경우만 서버 API 삭제 호출 (복사 모드 등 id 없는 이미지는 로컬 제거만)
        if (item.id && endpoints.delete) {
          const deleteUrl = endpoints.delete.replace(':id', String(item.id));
          await G7Core.api.delete(deleteUrl);
        }
        // 삭제 추적 — id와 hash 모두 기록 (복사 모드 이미지는 id 없이 hash만 존재)
        const itemKey = item.hash || item.id;
        if (item.id) {
          deletedIdsRef.current.add(item.id);
          uploadedFilesRef.current.delete(item.id);
        }
        if (item.hash) {
          deletedIdsRef.current.add(item.hash);
        }
        setExistingFiles((prev) => prev.filter((f) => (f.hash || f.id) !== itemKey));
        onRemove?.(item.hash || item.id);
        setConfirmDialogOpen(false);
        setItemToDelete(null);
      } catch {
        console.error('첨부파일 삭제 실패');
      } finally {
        setIsDeleting(false);
      }
    },
    [onRemove, endpoints.delete]
  );

  // 파일 삭제 핸들러 (확인 모달 또는 즉시 삭제)
  const handleRemove = useCallback(
    async (item: PendingFile | Attachment) => {
      if ('hash' in item) {
        // 기존 첨부파일
        if (confirmBeforeRemove) {
          // 확인 모달 표시
          setItemToDelete(item);
          setConfirmDialogOpen(true);
        } else {
          // 즉시 삭제
          await executeRemoveAttachment(item);
        }
      } else {
        // 대기 파일 삭제 (확인 없이 즉시)
        if (item.preview) {
          URL.revokeObjectURL(item.preview);
        }
        setPendingFiles((prev) => prev.filter((f) => f.id !== item.id));
      }
    },
    [confirmBeforeRemove, executeRemoveAttachment]
  );

  // 재시도 핸들러
  const handleRetry = useCallback((pendingFile: PendingFile) => {
    setPendingFiles((prev) =>
      prev.map((f) =>
        f.id === pendingFile.id ? { ...f, status: 'pending', error: undefined } : f
      )
    );
  }, []);

  // 순서 변경
  const handleDragEnd = useCallback(
    async (event: import('@dnd-kit/core').DragEndEvent) => {
      const { arrayMove } = await import('@dnd-kit/sortable');
      const { active, over } = event;
      if (!over || active.id === over.id) return;

      // customOrder 기반으로 시각 순서와 일치하는 allItems 계산
      // (raw [...existingFiles, ...pendingFiles] 는 시각 순서와 다를 수 있음)
      const combined = [...existingFiles, ...pendingFiles];
      let orderedItems: (Attachment | PendingFile)[];
      if (customOrder.length === 0) {
        orderedItems = combined;
      } else {
        const orderMap = new Map(customOrder.map((id, idx) => [String(id), idx]));
        orderedItems = [...combined].sort((a, b) => {
          const aKey = 'hash' in a ? String(a.hash) : String(a.id);
          const bKey = 'hash' in b ? String(b.hash) : String(b.id);
          return (orderMap.get(aKey) ?? combined.length) - (orderMap.get(bKey) ?? combined.length);
        });
      }

      const oldIndex = orderedItems.findIndex((item) =>
        'hash' in item ? item.hash === active.id : item.id === active.id
      );
      const newIndex = orderedItems.findIndex((item) =>
        'hash' in item ? item.hash === over.id : item.id === over.id
      );

      const reordered = arrayMove(orderedItems, oldIndex, newIndex);

      // 드래그 순서 보존 (기존/신규 파일 간 섞인 순서 유지)
      setCustomOrder(reordered.map((item) => 'hash' in item ? item.hash : item.id));

      // 분리
      const newExisting = reordered.filter((item): item is Attachment => 'hash' in item);
      const newPending = reordered.filter((item): item is PendingFile => !('hash' in item));

      // 순서 변경 플래그 설정 (initialFiles 동기화 방지)
      hasReorderedRef.current = true;

      setExistingFiles(newExisting);
      setPendingFiles(newPending);

      // sort_order/order 필드를 새 인덱스로 갱신 (form.images 동기화용)
      const reorderedFiles = newExisting.map((item, idx) => ({
        ...item,
        sort_order: idx,
        order: idx + 1,
      }));

      // 서버에 순서 변경 요청 (기존 파일 + reorder 엔드포인트가 설정된 경우만)
      if (newExisting.length > 0 && endpoints.reorder) {
        const orderData = newExisting.map((item, index) => ({
          id: item.id,
          order: index + 1,
        }));

        try {
          const response = await G7Core.api.patch(endpoints.reorder, { order: orderData });
          G7Core.toast.success(response.message);
        } catch (error: unknown) {
          const message = error instanceof Error ? error.message : t('attachment.reorder_failed');
          G7Core.toast.error(message);
        }
      }

      // API 호출 성공/스킵 무관하게 항상 콜백 호출
      onReorder?.(reorderedFiles);
    },
    [existingFiles, pendingFiles, customOrder, endpoints.reorder, onReorder]
  );

  // 이미지 파일만 필터링 (갤러리용)
  const imageFiles = useMemo(
    () =>
      [...existingFiles, ...pendingFiles].filter((item) => {
        if ('is_image' in item) {
          // Attachment (기존 첨부파일) - is_image 필드 우선 사용
          return (item as Attachment).is_image;
        }
        // PendingFile (대기 파일) - mime_type으로 확인
        return item.mime_type?.startsWith('image/') ?? false;
      }),
    [existingFiles, pendingFiles]
  );

  // 갤러리 열기 (이미지 인덱스로)
  const handleOpenGallery = useCallback(
    (item: PendingFile | Attachment) => {
      // 클릭된 아이템의 인덱스를 imageFiles에서 찾기
      const imageIndex = imageFiles.findIndex((img) => {
        // 둘 다 Attachment인 경우 (hash로 비교)
        if ('hash' in item && 'hash' in img) {
          return img.hash === item.hash;
        }
        // 둘 다 PendingFile인 경우 (id로 비교)
        if ('id' in item && 'id' in img && !('hash' in item) && !('hash' in img)) {
          return img.id === item.id;
        }
        return false;
      });
      if (imageIndex !== -1) {
        // 갤러리를 열 때마다 key 증가 (강제 리마운트)
        galleryKeyRef.current += 1;
        setGalleryStartIndex(imageIndex);
        setGalleryOpen(true);
      }
    },
    [imageFiles]
  );

  // 기존 첨부파일 인증된 이미지 URL 로드 (갤러리용)
  useEffect(() => {
    const loadAuthenticatedUrls = async () => {
      const existingImageFiles = existingFiles.filter((f) => f.mime_type?.startsWith('image/') ?? false);

      for (const file of existingImageFiles) {
        if (!authenticatedImageUrls.has(file.id)) {
          try {
            const blob = await G7Core.api.get(file.download_url, {
              responseType: 'blob',
            });
            if (blob) {
              const objectUrl = URL.createObjectURL(blob);
              setAuthenticatedImageUrls((prev) => new Map(prev).set(file.id, objectUrl));
            }
          } catch (error) {
            console.error('Failed to load authenticated image:', error);
          }
        }
      }
    };

    loadAuthenticatedUrls();

    // 클린업: 컴포넌트 언마운트 시 URL 해제
    return () => {
      authenticatedImageUrls.forEach((url) => URL.revokeObjectURL(url));
    };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [existingFiles]);

  // 파일 다운로드 실행
  const handleDownload = useCallback(async (item: Attachment) => {
    const { executeImageDownload } = await import('../ImageGallery');
    await executeImageDownload({
      src: item.download_url,
      downloadUrl: item.download_url,
      filename: item.original_filename,
      downloadRequiresAuth: true,
    });
  }, []);

  // 계산된 값 (customOrder가 있으면 드래그 순서 유지, 없으면 기본 순서)
  const allItems = useMemo(() => {
    const combined = [...existingFiles, ...pendingFiles];
    if (customOrder.length === 0) return combined;

    const orderMap = new Map(customOrder.map((id, idx) => [String(id), idx]));
    return [...combined].sort((a, b) => {
      const aKey = 'hash' in a ? String(a.hash) : String(a.id);
      const bKey = 'hash' in b ? String(b.hash) : String(b.id);
      const aOrder = orderMap.get(aKey) ?? combined.length;
      const bOrder = orderMap.get(bKey) ?? combined.length;
      return aOrder - bOrder;
    });
  }, [existingFiles, pendingFiles, customOrder]);
  const totalCount = allItems.length;
  const hasFiles = totalCount > 0;
  const canAddMore = totalCount < maxFiles;

  // 초기화 함수들
  const clear = useCallback(() => setPendingFiles([]), []);
  const getPendingFiles = useCallback(() => pendingFiles, [pendingFiles]);

  return {
    // 상태
    existingFiles,
    pendingFiles,
    isDragOver,
    setIsDragOver,
    isDeleting,

    // 계산된 값
    totalCount,
    hasFiles,
    canAddMore,
    allItems,
    imageFiles,

    // 액션
    handleFiles,
    handleRemove,
    handleRetry,
    handleUploadAll,
    handleOpenGallery,
    handleDownload,
    handleDragEnd,

    // Input ref
    inputRef,

    // 갤러리 상태
    galleryOpen,
    setGalleryOpen,
    galleryStartIndex,
    galleryKeyRef,

    // 삭제 확인 모달 상태
    confirmDialogOpen,
    setConfirmDialogOpen,
    itemToDelete,
    executeRemoveAttachment,

    // 인증된 이미지 URL 캐시
    authenticatedImageUrls,

    // 초기화
    clear,
    getPendingFiles,
  };
}

export default useFileUploader;