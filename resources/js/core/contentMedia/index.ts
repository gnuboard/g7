/**
 * 본문 미디어 업로드 런타임 진입점 (engine-v1.89.0, #883)
 *
 * `G7Core.contentMedia` 파사드(`initContentMediaAPI`)와 시험이 {@link getContentMediaRuntime} 으로 같은 인스턴스를 쓴다.
 * 관리자·사용자 템플릿 모두에서 선다(편집기가 양쪽에 있다).
 */

import { getApiClient } from '../api/ApiClient';
import { ContentMediaRuntime } from './ContentMediaRuntime';

export {
  CONTENT_MEDIA_ALLOWED_HEADERS,
  CONTENT_MEDIA_CANDIDATE_EXTENSIONS,
  CONTENT_MEDIA_CONTRACT_VERSION,
  ContentMediaRuntime,
  DEFAULT_ADAPTER_ID,
} from './ContentMediaRuntime';
export type { ContentMediaHttpClient, ContentMediaRuntimeDeps } from './ContentMediaRuntime';
export type {
  ContentMediaAdapter,
  ContentMediaKind,
  ContentMediaPolicyPayload,
  ContentMediaUploadContext,
  ContentMediaUploadResult,
  ContentMediaUploadTarget,
  ContentMediaValidation,
  KindPolicy,
} from './types';

let runtime: ContentMediaRuntime | null = null;

/**
 * 본문 미디어 런타임을 돌려줍니다(처음 부를 때 만든다).
 *
 * @returns 런타임
 */
export function getContentMediaRuntime(): ContentMediaRuntime {
  if (!runtime) {
    runtime = new ContentMediaRuntime({ getHttpClient: () => getApiClient() as any });
  }
  return runtime;
}

/**
 * 시험용 — 런타임을 버린다.
 *
 * @returns 반환값 없음
 */
export function resetContentMediaRuntimeForTests(): void {
  runtime = null;
}
