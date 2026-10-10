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
 * 호출 시점의 `window.G7Core` 를 읽는다 — 모듈을 불러온 시점에 잡아 두면 그 뒤에 준비된 번역 함수를 쓰지 못한다.
 */
export declare const t: (key: string, params?: Record<string, string | number>) => any;
/**
 * 파일이 업로더의 `accept` 값에 맞는지 판정합니다.
 *
 * `accept` 는 HTML 파일 입력과 같은 표기를 받는다 — 확장자(`.png`), MIME(`text/plain`), MIME 계열
 * 와일드카드(`image/*`). 파일 선택창이 세 표기를 모두 따르므로 사전 검사도 같아야 한다. 확장자만 보면
 * 운영자가 허용 형식을 MIME 으로 둔 경우 서버가 받아 주는 파일을 화면이 먼저 거절한다.
 *
 * @param file 검사할 파일
 * @param accept 쉼표로 구분한 허용 표기 목록 (비면 모두 허용)
 * @returns 허용되면 true
 */
export declare const isFileAccepted: (file: File, accept?: string) => boolean;
