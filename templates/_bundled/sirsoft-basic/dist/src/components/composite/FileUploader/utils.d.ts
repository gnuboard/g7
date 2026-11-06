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
 *
 * 호출 시점의 `window.G7Core` 를 읽고, 템플릿 설정 화면 묶음 안에서는 이 템플릿 문구(`sirsoft-basic.키`)를 먼저 찾는다 —
 * 그 묶음은 이 템플릿 문구가 감싸여 오는 관리자 화면에 실린다(#434).
 */
export declare const t: (key: string, params?: Record<string, string | number>) => string;
