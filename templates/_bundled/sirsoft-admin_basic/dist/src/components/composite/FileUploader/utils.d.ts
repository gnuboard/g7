import { ApiEndpoints, FileUploaderProps } from './types';
/**
 * FileUploader 유틸리티 함수
 *
 * @module composite/FileUploader/utils
 */
/**
 * 레이아웃이 넘긴 주소 묶음에 기본값을 채웁니다.
 *
 * 정렬 주소는 `reorder` 키가 **있으면** 그 값을 쓰고(`null`·빈 문자열이면 정렬 요청을 보내지 않는다), 키가 없을 때만
 * 코어 첨부 정렬 주소로 떨어진다. 종전 `??` 는 `null` 도 기본값으로 바꿔, 정렬 API 가 없는 화면(카테고리·반응 아이콘·
 * 배너·팝업)에서 끌어 옮기면 코어 정렬 API 로 요청이 나갔다.
 *
 * @param apiEndpoints 레이아웃이 넘긴 주소 묶음
 * @returns 업로드·삭제·정렬 주소(정렬하지 않으면 reorder 가 null)
 */
export declare const resolveApiEndpoints: (apiEndpoints: FileUploaderProps["apiEndpoints"]) => ApiEndpoints;
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
export declare const t: (key: string, params?: Record<string, string | number>) => string;
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
/**
 * 움직이는 이미지 형식을 파일 바이트로 가린다 — MIME·확장자는 믿지 않는다.
 *
 * 판정 규칙은 서버 썸네일 생성기(`ThumbnailGenerator::animatedFormat`)와 같다: GIF 는 프레임 2개 이상,
 * WebP 는 `ANMF` 프레임 청크 2개 이상(`ANIM` 표시만 있고 프레임이 하나면 정지 그림), PNG 는 `IDAT` 앞 `acTL` 의
 * 프레임 수 2 이상. 압축기는 첫 프레임만 다시 인코딩하므로 움직이는 이미지는 압축을 건너뛴다.
 *
 * @param file 검사할 파일
 * @returns 움직이면 'gif' | 'webp' | 'png', 아니면(정지 그림·다른 형식·읽기 실패) null
 */
export declare const detectAnimatedImageFormat: (file: Blob) => Promise<"gif" | "webp" | "png" | null>;
