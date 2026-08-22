/**
 * FileUploader 유틸리티 함수
 *
 * @module composite/FileUploader/utils
 */
/**
 * 파일 크기를 읽기 쉬운 형식으로 변환합니다.
 */
export declare const formatFileSize: (bytes: number) => string;
/**
 * API 에러 응답에서 상세 메시지를 추출합니다.
 */
export declare const extractErrorMessage: (error: unknown) => string;
/**
 * MIME 타입에 따른 Font Awesome 아이콘 클래스를 반환합니다.
 */
export declare const getFileIcon: (mimeType: string) => string;
/**
 * G7Core.t() 번역 함수 참조
 */
export declare const t: (key: string, params?: Record<string, string | number>) => any;
