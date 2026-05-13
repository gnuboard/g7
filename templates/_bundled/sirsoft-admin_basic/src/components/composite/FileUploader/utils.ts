/**
 * FileUploader 유틸리티 함수
 *
 * @module composite/FileUploader/utils
 */

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const G7Core = (window as any).G7Core;

/**
 * 파일 크기를 읽기 쉬운 형식으로 변환합니다.
 */
export const formatFileSize = (bytes: number): string => {
  const units = ['B', 'KB', 'MB', 'GB'];
  let i = 0;
  let size = bytes;
  while (size >= 1024 && i < units.length - 1) {
    size /= 1024;
    i++;
  }
  return `${size.toFixed(2)} ${units[i]}`;
};

/**
 * API 에러 응답에서 상세 메시지를 추출합니다.
 */
export const extractErrorMessage = (error: unknown): string => {
  // Axios 에러 응답 구조 처리
  if (error && typeof error === 'object') {
    const axiosError = error as {
      response?: {
        data?: {
          message?: string;
          errors?: Record<string, string[]>;
        };
      };
      message?: string;
    };

    // API 응답의 message 필드
    if (axiosError.response?.data?.message) {
      return axiosError.response.data.message;
    }

    // Laravel validation 에러 (errors 객체의 첫 번째 메시지)
    if (axiosError.response?.data?.errors) {
      const firstError = Object.values(axiosError.response.data.errors)[0];
      if (Array.isArray(firstError) && firstError[0]) {
        return firstError[0];
      }
    }

    // 일반 에러 메시지
    if (axiosError.message) {
      return axiosError.message;
    }
  }

  // Error 인스턴스
  if (error instanceof Error) {
    return error.message;
  }

  return '업로드 실패';
};

/**
 * MIME 타입에 따른 Font Awesome 아이콘 클래스를 반환합니다.
 */
export const getFileIcon = (mimeType: string): string => {
  if (!mimeType) return 'fa-file';
  if (mimeType.startsWith('image/')) return 'fa-file-image';
  if (mimeType.startsWith('video/')) return 'fa-file-video';
  if (mimeType.startsWith('audio/')) return 'fa-file-audio';
  if (mimeType === 'application/pdf') return 'fa-file-pdf';
  if (mimeType.includes('word') || mimeType.includes('document')) return 'fa-file-word';
  if (mimeType.includes('excel') || mimeType.includes('spreadsheet')) return 'fa-file-excel';
  if (mimeType.includes('zip') || mimeType.includes('compressed')) return 'fa-file-zipper';
  return 'fa-file';
};

/**
 * G7Core.t() 번역 함수 참조
 */
export const t = (key: string, params?: Record<string, string | number>) =>
  G7Core?.t?.(key, params) ?? key;
