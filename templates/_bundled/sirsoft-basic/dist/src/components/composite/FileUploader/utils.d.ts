import { ApiEndpoints, FileUploaderProps } from './types';
/**
 * 레이아웃이 넘긴 주소 묶음을 정리합니다 — 사용자 템플릿에는 **기본 주소가 없다**.
 *
 * 종전 기본값 `/api/attachments*` 는 존재하지 않는 경로라, 주소를 빠뜨린 화면이 404 를 받고도 어디가 틀렸는지 드러나지
 * 않았다. 이제 업로드 주소가 없으면 빈 문자열로 두고, 업로드 훅이 요청 없이 그 파일을 실패로 돌려 `onUploadError` 를 부르며
 * 개발 경고를 남긴다. 삭제 주소가 없으면 서버 삭제 없이 화면에서만 뺀다. 정렬 주소는 `null`·빈 문자열·키 없음 모두 정렬 없음.
 *
 * @param apiEndpoints 레이아웃이 넘긴 주소 묶음
 * @returns 업로드·삭제·정렬 주소(정렬하지 않으면 reorder 가 null)
 */
export declare const resolveApiEndpoints: (apiEndpoints: FileUploaderProps["apiEndpoints"]) => ApiEndpoints;
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
/**
 * 움직이는 이미지 형식을 파일 바이트로 가린다 — MIME·확장자는 믿지 않는다.
 *
 * 판정 규칙은 서버 썸네일 생성기(`ThumbnailGenerator::animatedFormat`)와 같다: GIF 는 프레임 2개 이상,
 * WebP 는 `ANMF` 프레임 청크 2개 이상(`ANIM` 표시만 있고 프레임이 하나면 정지 그림), PNG 는 `IDAT` 앞 `acTL` 의
 * 프레임 수 2 이상. 압축기는 첫 프레임만 다시 인코딩하므로 움직이는 이미지는 압축을 건너뛴다(#869 P4).
 *
 * @param file 검사할 파일
 * @returns 움직이면 'gif' | 'webp' | 'png', 아니면(정지 그림·다른 형식·읽기 실패) null
 */
export declare const detectAnimatedImageFormat: (file: Blob) => Promise<"gif" | "webp" | "png" | null>;
/**
 * 파일이 accept 목록(확장자, 정확한 MIME, MIME 와일드카드)에 맞는지 판정합니다.
 *
 * @param file 판정할 파일
 * @param accept 쉼표로 구분한 허용 목록 (예: ".jpg,.png", "image/*", "application/pdf")
 * @returns 허용 목록 중 하나라도 맞으면 true
 */
export declare const isFileAccepted: (file: File, accept: string) => boolean;
