/**
 * 본문 미디어 업로드 계약 타입 (engine-v1.89.0, #883)
 *
 * 확장(게시판·페이지·이커머스)이 편집기에 「본문에 넣을 미디어를 어디로·어떤 규칙으로 올리는지」를 알려 주는 계약이다.
 * 확장점 `html_editor` 의 prop `mediaUpload` 로 전달되고, 편집기 플러그인은 이 계약만 읽는다 — 확장의 API 경로를 모른다.
 * 서버 쪽 짝은 `App\Support\ContentMedia\ContentMediaUploadTarget::toArray()` 다.
 */

/** 미디어 종류 */
export type ContentMediaKind = 'image' | 'video' | 'audio';

/** 종류별 정책 */
export interface KindPolicy {
  /** 이 종류를 받는지 (확장 정책 + 코어 「본문 콘텐츠」 설정) */
  enabled: boolean;
  /** 파일당 MB (PHP 업로드 상한과 비교한 실효값) */
  maxSizeMb: number;
  /** 허용 확장자 (소문자) */
  extensions: string[];
  /** 허용 MIME */
  mimeTypes: string[];
}

/** 정책 묶음 */
export interface ContentMediaPolicyPayload {
  image: KindPolicy;
  video: KindPolicy;
  audio: KindPolicy;
  /** 문서당 최대 개수 */
  maxPerDocument: number;
}

/** 업로드 계약 */
export interface ContentMediaUploadTarget {
  version: 1;
  /** 업로드 주소 — 같은 출처 상대 경로만 (`/api/...`) */
  endpoint: string;
  /** 파일 필드 이름 (기본 'file') */
  fileField?: string;
  /** 함께 보낼 폼 필드 (temp_key·post_id 등) */
  fields?: Record<string, string | number>;
  /** 함께 보낼 헤더 — 허용 목록만 */
  headers?: Record<string, string>;
  /** 응답 본문에서 값을 꺼낼 점 경로 */
  response: { urlPath: string; idPath?: string; kindPath?: string };
  /** 서빙 주소 정규식 본문 — `(?<hash>…)` 그룹 포함, 문서당 개수 셀 때 쓴다 */
  mediaUrlPattern: string;
  policy: ContentMediaPolicyPayload;
}

/** 파일 검사 결과 */
export interface ContentMediaValidation {
  ok: boolean;
  /** 판정한 종류 (판정 불가면 null) */
  kind: ContentMediaKind | null;
  /** 실패 문구 키 (`core.content_media.client.*`) */
  messageKey?: string;
  /** 문구 매개변수 */
  params?: Record<string, string | number>;
}

/** 업로드 결과 */
export interface ContentMediaUploadResult {
  url: string;
  id?: string | number;
  kind: ContentMediaKind;
}

/** 업로드 진행 문맥 */
export interface ContentMediaUploadContext {
  signal?: AbortSignal;
  onProgress?: (loaded: number, total: number) => void;
}

/** 업로드 어댑터 — 확장이 저장소 직접 업로드(예: 외부 저장소 서명 주소) 등을 끼울 때 */
export interface ContentMediaAdapter {
  id: string;
  /** 높을수록 먼저 (기본 어댑터는 0) */
  priority?: number;
  supports(target: ContentMediaUploadTarget, file: File): boolean;
  upload(target: ContentMediaUploadTarget, file: File, ctx: ContentMediaUploadContext): Promise<ContentMediaUploadResult>;
}
