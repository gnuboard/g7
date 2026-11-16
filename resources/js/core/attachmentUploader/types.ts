/**
 * 첨부 업로더 확장점 계약 (`attachment_uploader`, 계약 버전 1)
 *
 * 레이아웃은 첨부 업로더를 `<extension-point name="attachment_uploader">` 로 감싸고, 확장점 props 의 `uploader` 에
 * 이 계약 객체를 조립해 넘긴다. 업로더를 바꾸는 플러그인은 `mode="replace"` 로 주입해 기본 업로더(default)를 걷어내고,
 * `{{extensionPointProps.uploader}}` 로 이 객체를, `{{extensionPointCallbacks.<이름>}}` 으로 화면의 콜백 액션을 읽는다.
 *
 * 타입만 둔다 — 런타임 파사드는 없다. 화면은 이미 가진 값(업로드 주소·정책·초기 파일)으로 객체를 조립하므로
 * 새 API 가 없고, 콜백 실행은 `G7Core.dispatch(액션, { componentContext: { data: { $event } } })` 를 그대로 쓴다.
 *
 * 공개 문서: docs/extension/attachment-uploader.md
 */

/** 계약 버전 — 형식이 바뀌면 올린다. 업로더는 모르는 버전이면 주입하지 않은 것처럼 비켜야 한다. */
export type AttachmentUploaderContractVersion = 1;

/**
 * 업로드 방식
 *
 * - `upload`: 업로더가 `endpoints.upload` 로 파일을 올린다(자동 또는 `triggerEvent` 신호를 받을 때).
 * - `select`: 업로더는 파일을 고르기만 한다. 화면 핸들러가 제출 요청에 실어 올린다(클레임 신청·리뷰 작성).
 *   이때 `onFilesChange` 콜백의 `$event` 는 `{ target: { files, value } }` 형태를 지킨다.
 */
export type AttachmentUploaderMode = 'upload' | 'select';

/** 서버가 돌려준 첨부 한 건 — 기본 업로더가 다루는 모양과 같다. */
export interface AttachmentItem {
  id: number | string;
  hash: string;
  original_filename: string;
  mime_type: string;
  size: number;
  size_formatted?: string;
  download_url: string;
  preview_url?: string | null;
  order?: number;
  is_image?: boolean;
  meta?: Record<string, unknown>;
  [key: string]: unknown;
}

/**
 * 업로드·삭제·정렬 주소
 *
 * - `delete` 의 `:id` 는 첨부 id 로 바꿔 부른다. 빈 문자열이면 서버 삭제 없이 화면에서만 뺀다.
 * - `reorder` 가 `null` 이면 정렬 요청을 보내지 않는다(생성 화면, 정렬 API 가 없는 화면).
 */
export interface AttachmentUploaderEndpoints {
  upload: string;
  delete: string;
  reorder: string | null;
}

/** 파일 고르기 정책 — 기본 업로더에 넘기던 값 그대로다. */
export interface AttachmentUploaderPolicy {
  /** `<input accept>` 형식 목록(예 `.jpg,.png`). 빈 문자열이면 제한 없음 */
  accept: string;
  /** 파일 하나의 상한(MB). `null` 이면 업로더가 거르지 않고 서버가 판정한다 */
  maxSize: number | null;
  /** 개수 상한. `null` 이면 제한 없음 */
  maxFiles: number | null;
  /** 동시에 올리는 파일 수. 서버가 도착 순서로 번호를 매기는 화면은 1 이다 */
  maxConcurrentUploads: number;
  /** 업로드 전 이미지 압축 옵션. `null` 이면 압축하지 않는다 */
  imageCompression: Record<string, unknown> | null;
}

/** 첨부 업로더 계약 객체 (`extensionPointProps.uploader`) */
export interface AttachmentUploaderContract {
  version: AttachmentUploaderContractVersion;
  mode: AttachmentUploaderMode;
  /** `select` 이면 `null` */
  endpoints: AttachmentUploaderEndpoints | null;
  /** 업로드 요청 본문에 함께 싣는 값(예 `temp_key`, `device`) */
  fields: Record<string, string | number>;
  policy: AttachmentUploaderPolicy;
  /** 첨부 컬렉션 이름 — 업로드 요청의 `collection` */
  collection: string;
  /** 업로드 요청의 `attachmentable_type`·`attachmentable_id`(화면이 주소로 소속을 정하면 둘 다 `null`) */
  attachmentable: { type: string | null; id: number | string | null };
  /**
   * 저장 흐름이 보내는 업로드 신호 이름(`emit-event`). `null` 이면 신호를 받지 않는다(자동 업로드·선택형).
   * 업로더는 이 이름을 `G7Core.componentEvent.on` 으로 구독해 {@link AttachmentUploaderResult} 를 돌려준다.
   */
  triggerEvent: string | null;
  /** 화면에 처음 보일 기존 첨부 */
  initialFiles: AttachmentItem[];
  disabled: boolean;
  /** 고르는 즉시 올리는지(`false` 면 `triggerEvent` 신호를 받을 때 올린다) */
  autoUpload: boolean;
  ui?: {
    confirmBeforeRemove?: boolean;
    enablePrimarySelection?: boolean;
    primaryFileId?: string | number | null;
  };
  /** 어느 확장의 어느 자리인지 — 업로더가 화면별로 모양을 다르게 하고 싶을 때 읽는다 */
  context: { owner: string; purpose: string };
}

/**
 * `triggerEvent` 리스너가 돌려주는 값 — 저장 흐름은 `_eventResult.data[i]` 로 읽는다(기본 업로더와 같은 모양).
 * `failedCount` 가 0 보다 크면 저장 흐름이 저장을 멈춘다.
 */
export interface AttachmentUploaderResult {
  uploadedAttachments: AttachmentItem[];
  existingFiles: AttachmentItem[];
  allFiles: AttachmentItem[];
  failedCount: number;
}

/**
 * 화면이 `<callback>` 으로 넘기는 콜백 이름(`extensionPointCallbacks.<이름>`)
 *
 * 콜백의 `$event` 는 기본 업로더가 같은 이벤트에 넘기던 첫 인자와 같다.
 *
 * | 이름 | `$event` |
 * | --- | --- |
 * | `onUploadComplete` | 이번에 올린 첨부 배열 |
 * | `onRemove` | 뺀 첨부의 `hash`(없으면 `id`) |
 * | `onReorder` | 새 순서의 기존 첨부 배열 |
 * | `onFilesChange` | 업로드형은 대기 파일 배열, 선택형은 `{ target: { files, value } }` |
 * | `onUploadError` | 사람이 읽는 오류 문장 |
 * | `onDeleteError` | 사람이 읽는 오류 문장(서버 삭제 실패) |
 * | `onPrimaryChange` | 대표로 고른 첨부의 `hash`(없으면 `id`) |
 */
export type AttachmentUploaderCallbackName =
  | 'onUploadComplete'
  | 'onRemove'
  | 'onReorder'
  | 'onFilesChange'
  | 'onUploadError'
  | 'onDeleteError'
  | 'onPrimaryChange';

/** 확장점이 넘기는 props 전체 (`extensionPointProps`) */
export interface AttachmentUploaderExtensionPointProps {
  uploader: AttachmentUploaderContract;
  [key: string]: unknown;
}
