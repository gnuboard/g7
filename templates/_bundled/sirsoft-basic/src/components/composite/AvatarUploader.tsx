/**
 * AvatarUploader 컴포넌트
 *
 * 프로필 아바타 이미지 업로드를 위한 컴포넌트입니다.
 * - 원형 아바타 UI
 * - 파일 선택 시 즉시 업로드
 * - 업로드/삭제 API 지원
 * - 업로드 오류 표시
 * - 삭제 확인 다이얼로그
 *
 * @module composite/AvatarUploader
 */

import { useRef, useState, useCallback, useEffect } from 'react';

import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { Label } from '../basic/Label';
import { Input } from '../basic/Input';
import { Img } from '../basic/Img';
import { Avatar } from './Avatar';

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const G7Core = (window as any).G7Core;

export interface AvatarUploaderProps {
  /** 현재 아바타 URL */
  src?: string;
  /** 이미지 없을 때 표시할 텍스트 (이니셜) */
  fallbackText?: string;
  /** 아바타 크기 */
  size?: 'sm' | 'md' | 'lg' | 'xl' | '2xl';
  /** 업로드 API 엔드포인트 (기본: /api/me/avatar) */
  uploadEndpoint?: string;
  /** 삭제 API 엔드포인트 (기본: /api/me/avatar) */
  deleteEndpoint?: string;
  /** 업로드 성공 콜백 */
  onUploadSuccess?: (data: { avatar: string; attachment_id: number }) => void;
  /** 업로드 에러 콜백 */
  onUploadError?: (error: Error) => void;
  /** 삭제 성공 콜백 */
  onDeleteSuccess?: () => void;
  /** 삭제 에러 콜백 */
  onDeleteError?: (error: Error) => void;
  /** 삭제 버튼 표시 여부 */
  showDeleteButton?: boolean;
  /** 허용 파일 타입 (기본: image/*) */
  accept?: string;
  /** 최대 파일 크기 (MB, 기본: 5) */
  maxSize?: number;
  /** 추가 CSS 클래스 */
  className?: string;
  /** 업로드 버튼 텍스트 */
  uploadButtonText?: string;
  /** 삭제 버튼 텍스트 */
  deleteButtonText?: string;
  /** 읽기 전용 모드 */
  readOnly?: boolean;
  /** 삭제 확인 다이얼로그 표시 여부 (기본: true) */
  confirmDelete?: boolean;
  /** 삭제 확인 메시지 */
  deleteConfirmMessage?: string;
  /** 업로드 확인 다이얼로그 표시 여부 (기본: true) */
  confirmUpload?: boolean;
  /** 업로드 확인 메시지 */
  uploadConfirmMessage?: string;
  /** 업로드 성공 시 실행할 액션 배열 (G7Core.dispatch) */
  uploadSuccessActions?: Array<Record<string, any>>;
  /** 업로드 실패 시 실행할 액션 배열 */
  uploadErrorActions?: Array<Record<string, any>>;
  /** 삭제 성공 시 실행할 액션 배열 */
  deleteSuccessActions?: Array<Record<string, any>>;
  /** 삭제 실패 시 실행할 액션 배열 */
  deleteErrorActions?: Array<Record<string, any>>;
}

// AvatarUploader size를 Avatar size로 매핑
// AvatarUploader는 프로필 편집용으로 더 큰 사이즈 사용
const avatarSizeMap: Record<string, 'xs' | 'sm' | 'md' | 'lg' | 'xl' | '2xl' | '3xl' | '4xl' | '5xl'> = {
  sm: 'md',     // AvatarUploader sm → Avatar md (w-10, 40px)
  md: 'lg',     // AvatarUploader md → Avatar lg (w-12, 48px)
  lg: 'xl',     // AvatarUploader lg → Avatar xl (w-16, 64px)
  xl: '2xl',    // AvatarUploader xl → Avatar 2xl (w-24, 96px)
  '2xl': '3xl', // AvatarUploader 2xl → Avatar 3xl (w-32, 128px)
};

export function AvatarUploader({
  src,
  fallbackText = 'U',
  size = 'lg',
  uploadEndpoint = '/api/me/avatar',
  deleteEndpoint = '/api/me/avatar',
  onUploadSuccess,
  onUploadError,
  onDeleteSuccess,
  onDeleteError,
  showDeleteButton = true,
  accept = 'image/*',
  maxSize = 5,
  className = '',
  uploadButtonText,
  deleteButtonText,
  readOnly = false,
  confirmDelete = true,
  deleteConfirmMessage,
  confirmUpload = true,
  uploadConfirmMessage,
  uploadSuccessActions,
  uploadErrorActions,
  deleteSuccessActions,
  deleteErrorActions,
}: AvatarUploaderProps) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [isUploading, setIsUploading] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  // undefined: 초기 상태(props 사용), null: 삭제됨, string: 새 이미지 URL
  const [localAvatarUrl, setLocalAvatarUrl] = useState<string | null | undefined>(undefined);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
  const [showUploadConfirm, setShowUploadConfirm] = useState(false);
  const [pendingFile, setPendingFile] = useState<File | null>(null);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const inputId = useRef(`avatar-upload-${Math.random().toString(36).slice(2, 11)}`).current;

  // 파일 선택 시 미리보기 URL 생성
  useEffect(() => {
    if (pendingFile) {
      const url = URL.createObjectURL(pendingFile);
      setPreviewUrl(url);
      return () => URL.revokeObjectURL(url);
    } else {
      setPreviewUrl(null);
    }
  }, [pendingFile]);

  // 다국어 텍스트 가져오기
  const t = useCallback((key: string): string => {
    return G7Core?.t?.(key) ?? key;
  }, []);

  // CSRF 토큰 가져오기
  const getCsrfToken = useCallback((): string | null => {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    if (match) {
      return decodeURIComponent(match[1]);
    }
    return null;
  }, []);

  // 인증 토큰 가져오기 (ApiClient에서 토큰 조회)
  const getAuthToken = useCallback((): string | null => {
    return G7Core?.api?.getToken?.() ?? null;
  }, []);

  // 실제 업로드 실행 함수
  const executeUpload = useCallback(async (file: File) => {
    setShowUploadConfirm(false);
    setPendingFile(null);
    setIsUploading(true);
    setErrorMessage(null);

    try {
      // FormData 생성
      const formData = new FormData();
      formData.append('avatar', file);

      // API 호출
      const csrfToken = getCsrfToken();
      const authToken = getAuthToken();
      const response = await fetch(uploadEndpoint, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          ...(csrfToken && { 'X-XSRF-TOKEN': csrfToken }),
          ...(authToken && { Authorization: `Bearer ${authToken}` }),
        },
        credentials: 'include',
        body: formData,
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        throw new Error(errorData.message || t('attachment.upload_failed'));
      }

      const data = await response.json();
      const result = data.data || data;

      // 로컬 상태 즉시 업데이트 (빠른 피드백)
      if (result.avatar) {
        setLocalAvatarUrl(result.avatar);
      }

      onUploadSuccess?.(result);
      G7Core?.toast?.success?.(t('mypage.profile.avatar_uploaded'));

      // 액션 props가 있으면 dispatch, 없으면 기본 동작(user 리패치)
      if (uploadSuccessActions?.length) {
        uploadSuccessActions.forEach(action => G7Core?.dispatch?.(action));
      } else {
        G7Core?.refetchDataSource?.('user');
      }
    } catch (error) {
      const err = error instanceof Error ? error : new Error(String(error));
      setErrorMessage(err.message);
      onUploadError?.(err);
      G7Core?.toast?.error?.(err.message);

      if (uploadErrorActions?.length) {
        uploadErrorActions.forEach(action => G7Core?.dispatch?.(action));
      }
    } finally {
      setIsUploading(false);
      // 입력 초기화 (같은 파일 재선택 가능)
      if (inputRef.current) {
        inputRef.current.value = '';
      }
    }
  }, [uploadEndpoint, onUploadSuccess, onUploadError, getCsrfToken, getAuthToken, t]);

  // 파일 선택 핸들러
  const handleFileChange = useCallback(
    async (event: React.ChangeEvent<HTMLInputElement>) => {
      const file = event.target.files?.[0];
      if (!file) return;

      // 오류 상태 초기화
      setErrorMessage(null);

      // 파일 크기 검증
      const maxBytes = maxSize * 1024 * 1024;
      if (file.size > maxBytes) {
        const error = new Error(t('attachment.file_too_large').replace(':max', `${maxSize}MB`));
        setErrorMessage(error.message);
        onUploadError?.(error);
        G7Core?.toast?.error?.(error.message);
        return;
      }

      // 파일 타입 검증
      if (accept && !file.type.match(accept.replace('*', '.*'))) {
        const error = new Error(t('attachment.invalid_file_type'));
        setErrorMessage(error.message);
        onUploadError?.(error);
        G7Core?.toast?.error?.(error.message);
        return;
      }

      // 확인 다이얼로그 표시 또는 즉시 업로드
      if (confirmUpload) {
        setPendingFile(file);
        setShowUploadConfirm(true);
      } else {
        executeUpload(file);
      }
    },
    [maxSize, accept, onUploadError, confirmUpload, executeUpload, t]
  );

  // 삭제 실행 함수
  const executeDelete = useCallback(async () => {
    setShowDeleteConfirm(false);
    setIsDeleting(true);
    setErrorMessage(null);

    try {
      const csrfToken = getCsrfToken();
      const authToken = getAuthToken();
      const response = await fetch(deleteEndpoint, {
        method: 'DELETE',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          ...(csrfToken && { 'X-XSRF-TOKEN': csrfToken }),
          ...(authToken && { Authorization: `Bearer ${authToken}` }),
        },
        credentials: 'include',
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        throw new Error(errorData.message || t('attachment.delete_failed'));
      }

      // 로컬 상태 즉시 업데이트 (빠른 피드백)
      setLocalAvatarUrl(null);

      onDeleteSuccess?.();
      G7Core?.toast?.success?.(t('mypage.profile.avatar_deleted'));

      // 액션 props가 있으면 dispatch, 없으면 기본 동작(user 리패치)
      if (deleteSuccessActions?.length) {
        deleteSuccessActions.forEach(action => G7Core?.dispatch?.(action));
      } else {
        G7Core?.refetchDataSource?.('user');
      }
    } catch (error) {
      const err = error instanceof Error ? error : new Error(String(error));
      setErrorMessage(err.message);
      onDeleteError?.(err);
      G7Core?.toast?.error?.(err.message);

      if (deleteErrorActions?.length) {
        deleteErrorActions.forEach(action => G7Core?.dispatch?.(action));
      }
    } finally {
      setIsDeleting(false);
    }
  }, [deleteEndpoint, onDeleteSuccess, onDeleteError, getCsrfToken, getAuthToken, t]);

  // 삭제 버튼 클릭 핸들러 (확인 다이얼로그 표시 또는 즉시 삭제)
  const handleDeleteClick = useCallback(() => {
    if (confirmDelete) {
      setShowDeleteConfirm(true);
    } else {
      executeDelete();
    }
  }, [confirmDelete, executeDelete]);

  // 삭제 취소
  const handleDeleteCancel = useCallback(() => {
    setShowDeleteConfirm(false);
  }, []);

  // 업로드 취소
  const handleUploadCancel = useCallback(() => {
    setShowUploadConfirm(false);
    setPendingFile(null);
    // 입력 초기화 (같은 파일 재선택 가능)
    if (inputRef.current) {
      inputRef.current.value = '';
    }
  }, []);

  // 업로드 확인
  const handleUploadConfirm = useCallback(() => {
    if (pendingFile) {
      executeUpload(pendingFile);
    }
  }, [pendingFile, executeUpload]);

  // 로컬 상태 우선 사용: undefined면 props 사용, null이면 삭제됨, string이면 새 이미지
  const currentAvatarUrl = localAvatarUrl === undefined ? src : localAvatarUrl;
  const hasAvatar = !!currentAvatarUrl;

  // Avatar 컴포넌트에 전달할 사이즈
  const avatarSize = avatarSizeMap[size] || 'lg';

  // 현재 표시 모드 결정
  const isConfirmMode = showDeleteConfirm || showUploadConfirm;

  // 버튼 영역 렌더링
  const renderButtons = () => (
    <Div className="flex items-center justify-center gap-2">
      {/* 숨겨진 파일 입력 */}
      <Input
        ref={inputRef}
        id={inputId}
        type="file"
        accept={accept}
        className="hidden"
        onChange={handleFileChange}
        disabled={isUploading}
      />

      {/* 이미지 없을 때: 이미지 등록 버튼 */}
      {!hasAvatar && (
        <Label
          htmlFor={inputId}
          className={`inline-flex items-center gap-2 px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors cursor-pointer ${
            isUploading ? 'opacity-50 cursor-not-allowed' : ''
          }`}
        >
          {isUploading ? (
            <Icon name="spinner" size="sm" className="animate-spin" />
          ) : (
            <Icon name="image" size="sm" />
          )}
          <Span>{uploadButtonText || t('mypage.profile.register_avatar')}</Span>
        </Label>
      )}

      {/* 이미지 있을 때: 변경 + 삭제 버튼 */}
      {hasAvatar && (
        <>
          <Label
            htmlFor={inputId}
            className={`inline-flex items-center gap-2 px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors cursor-pointer ${
              isUploading ? 'opacity-50 cursor-not-allowed' : ''
            }`}
          >
            {isUploading ? (
              <Icon name="spinner" size="sm" className="animate-spin" />
            ) : (
              <Icon name="upload" size="sm" />
            )}
            <Span>{uploadButtonText || t('common.change')}</Span>
          </Label>

          {showDeleteButton && (
            <Button
              type="button"
              className="inline-flex items-center gap-2 px-4 py-2 border border-red-300 dark:border-red-600 text-red-600 dark:text-red-400 rounded-lg text-sm font-medium hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              onClick={handleDeleteClick}
              disabled={isDeleting}
            >
              {isDeleting ? (
                <Icon name="spinner" size="sm" className="animate-spin" />
              ) : (
                <Icon name="trash-2" size="sm" />
              )}
              <Span>{deleteButtonText || t('common.delete')}</Span>
            </Button>
          )}
        </>
      )}
    </Div>
  );

  return (
    <Div className={`flex flex-col items-center gap-3 ${className}`}>
      {/* 아바타 이미지 - 업로드 확인 시 미리보기 표시 */}
      {showUploadConfirm && previewUrl ? (
        <Div className="relative">
          <Img
            src={previewUrl}
            alt="Preview"
            className={`rounded-full object-cover ${
              size === 'sm' ? 'w-10 h-10' :
              size === 'md' ? 'w-12 h-12' :
              size === 'lg' ? 'w-16 h-16' :
              size === 'xl' ? 'w-24 h-24' :
              'w-32 h-32'
            }`}
          />
          <Div className="absolute inset-0 rounded-full bg-blue-500/20 border-2 border-blue-500 border-dashed" />
        </Div>
      ) : (
        <Avatar
          avatar={currentAvatarUrl || undefined}
          name={fallbackText}
          size={avatarSize}
        />
      )}

      {/* 버튼/확인 영역 */}
      {!readOnly && (
        <Div className="w-[220px] flex flex-col items-center gap-2">
          {/* 업로드 확인 박스 */}
          {showUploadConfirm && pendingFile && (
            <Div className="w-full p-3 bg-blue-50 dark:bg-blue-950/50 border border-blue-200 dark:border-blue-800 rounded-lg">
              <Div className="flex items-center gap-2 mb-2">
                <Icon name="upload" size="sm" className="text-blue-600 dark:text-blue-400 shrink-0" />
                <Span className="text-sm font-medium text-blue-800 dark:text-blue-300">
                  {uploadConfirmMessage || t('mypage.profile.upload_avatar_confirm')}
                </Span>
              </Div>
              <Div className="text-xs text-blue-600 dark:text-blue-400 truncate mb-3" title={pendingFile.name}>
                {pendingFile.name} ({(pendingFile.size / 1024 / 1024).toFixed(2)}MB)
              </Div>
              <Div className="grid grid-cols-2 gap-2">
                <Button
                  type="button"
                  className="inline-flex items-center justify-center gap-1.5 px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition-colors disabled:opacity-50"
                  onClick={handleUploadConfirm}
                  disabled={isUploading}
                >
                  {isUploading ? (
                    <Icon name="spinner" size="sm" className="animate-spin" />
                  ) : (
                    <Icon name="check" size="sm" />
                  )}
                  <Span>{t('common.confirm')}</Span>
                </Button>
                <Button
                  type="button"
                  className="inline-flex items-center justify-center gap-1.5 px-3 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-md text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                  onClick={handleUploadCancel}
                >
                  <Span>{t('common.cancel')}</Span>
                </Button>
              </Div>
            </Div>
          )}

          {/* 삭제 확인 박스 */}
          {showDeleteConfirm && (
            <Div className="w-full p-3 bg-red-50 dark:bg-red-950/50 border border-red-200 dark:border-red-800 rounded-lg">
              <Div className="flex items-center gap-2 mb-2">
                <Icon name="alert-triangle" size="sm" className="text-red-600 dark:text-red-400 shrink-0" />
                <Span className="text-sm font-medium text-red-800 dark:text-red-300">
                  {deleteConfirmMessage || t('mypage.profile.delete_avatar_confirm')}
                </Span>
              </Div>
              <Div className="grid grid-cols-2 gap-2">
                <Button
                  type="button"
                  className="inline-flex items-center justify-center gap-1.5 px-3 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md text-sm font-medium transition-colors disabled:opacity-50"
                  onClick={executeDelete}
                  disabled={isDeleting}
                >
                  {isDeleting ? (
                    <Icon name="spinner" size="sm" className="animate-spin" />
                  ) : (
                    <Icon name="trash-2" size="sm" />
                  )}
                  <Span>{t('common.delete')}</Span>
                </Button>
                <Button
                  type="button"
                  className="inline-flex items-center justify-center gap-1.5 px-3 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-md text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                  onClick={handleDeleteCancel}
                >
                  <Span>{t('common.cancel')}</Span>
                </Button>
              </Div>
            </Div>
          )}

          {/* 기본 모드 - 버튼들 */}
          {!isConfirmMode && renderButtons()}
        </Div>
      )}

      {/* 오류 메시지 표시 */}
      {errorMessage && !isConfirmMode && (
        <Div className="flex items-center gap-2 text-sm text-red-600 dark:text-red-400">
          <Icon name="alert-circle" size="sm" />
          <Span>{errorMessage}</Span>
        </Div>
      )}
    </Div>
  );
}

export default AvatarUploader;
