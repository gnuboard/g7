/**
 * layoutAttachments.ts — 레이아웃 첨부 이미지 API 클라이언트
 *
 * `template_layout_attachments` 백엔드(부록 C — 이미 완비)에 대한 list/upload/
 * delete 호출을 단일 진실 공급원화한다. 인라인 미니 갤러리(ImagePickerControl) /
 * 관리 모달(LayoutAttachmentManager) / 업로드 컨트롤이 모두 본 클라이언트를 쓴다.
 *
 * 모든 요청은 `buildAuthHeaders` 로 `Authorization: Bearer` 를 첨부한다 —
 * 종전 업로드 fetch 가 토큰을 누락해 401 을 받던 결함(항목3-2)을 근본 차단.
 *
 * 엔드포인트(권한 `core.templates.layouts.edit`):
 *  - `GET    /api/admin/templates/{id}/layout-attachments?layout_name=`  → index
 *  - `POST   /api/admin/templates/{id}/layout-attachments` (multipart)   → store
 *  - `DELETE /api/admin/templates/layout-attachments/{attachment}`        → destroy
 *
 * 응답 필드: `{ id, layout_name, original_name, mime_type, size, url, created_at }`.
 *
 * @since engine-v1.50.0
 */

import { buildAuthHeaders } from './authToken';

/** 첨부 1건 — 백엔드 LayoutAttachmentResource 필드 */
export interface LayoutAttachment {
  id: number | string;
  layout_name: string;
  original_name: string;
  mime_type: string;
  size: number;
  url: string;
  /** store(업로드) 응답에는 미포함 — index(목록) 응답에만 존재 */
  created_at?: string;
}

/** 첨부 API 결과 — 호출자가 분기 처리 */
export type AttachmentResult<T> =
  | { ok: true; data: T }
  | { ok: false; status: number; message: string };

function encodeId(value: string): string {
  return encodeURIComponent(value);
}

/**
 * 현재 레이아웃의 첨부 목록 조회.
 *
 * @param templateIdentifier 템플릿 식별자
 * @param layoutName 레이아웃 이름 (slash 포함 가능 — query 인코딩으로 안전)
 * @return 첨부 배열 또는 에러
 */
export async function listLayoutAttachments(
  templateIdentifier: string,
  layoutName: string,
): Promise<AttachmentResult<LayoutAttachment[]>> {
  const url =
    `/api/admin/templates/${encodeId(templateIdentifier)}/layout-attachments` +
    `?layout_name=${encodeURIComponent(layoutName)}`;
  try {
    const res = await fetch(url, {
      method: 'GET',
      headers: buildAuthHeaders(),
      credentials: 'same-origin',
    });
    const body = await res.json().catch(() => null);
    if (!res.ok || !body?.success) {
      return { ok: false, status: res.status, message: messageOf(body, res.status) };
    }
    const data = Array.isArray(body?.data) ? (body.data as LayoutAttachment[]) : [];
    return { ok: true, data };
  } catch (err) {
    return { ok: false, status: 0, message: err instanceof Error ? err.message : 'network error' };
  }
}

/**
 * 이미지 파일 업로드 (multipart). 성공 시 생성된 첨부 1건 반환.
 *
 * Content-Type 을 명시하지 않는다 — 브라우저가 multipart boundary 를 포함해
 * 자동 설정한다(명시 시 boundary 누락으로 서버 파싱 실패).
 *
 * @param templateIdentifier 템플릿 식별자
 * @param layoutName 레이아웃 이름
 * @param file 업로드 파일
 * @return 생성된 첨부 또는 에러
 */
export async function uploadLayoutAttachment(
  templateIdentifier: string,
  layoutName: string,
  file: File,
): Promise<AttachmentResult<LayoutAttachment>> {
  const url = `/api/admin/templates/${encodeId(templateIdentifier)}/layout-attachments`;
  const form = new FormData();
  form.append('file', file);
  form.append('layout_name', layoutName);
  try {
    const res = await fetch(url, {
      method: 'POST',
      headers: buildAuthHeaders(),
      body: form,
      credentials: 'same-origin',
    });
    const body = await res.json().catch(() => null);
    if (!res.ok || !body?.success || !body?.data?.url) {
      return { ok: false, status: res.status, message: messageOf(body, res.status) };
    }
    return { ok: true, data: body.data as LayoutAttachment };
  } catch (err) {
    return { ok: false, status: 0, message: err instanceof Error ? err.message : 'network error' };
  }
}

/**
 * 첨부 삭제. 백엔드가 StorageInterface 파일 실삭제 + DB 행 삭제(2단계) 수행.
 *
 * @param attachmentId 첨부 id
 * @return 성공 여부
 */
export async function deleteLayoutAttachment(
  attachmentId: number | string,
): Promise<AttachmentResult<true>> {
  const url = `/api/admin/templates/layout-attachments/${encodeId(String(attachmentId))}`;
  try {
    const res = await fetch(url, {
      method: 'DELETE',
      headers: buildAuthHeaders(),
      credentials: 'same-origin',
    });
    const body = await res.json().catch(() => null);
    if (!res.ok || (body && body.success === false)) {
      return { ok: false, status: res.status, message: messageOf(body, res.status) };
    }
    return { ok: true, data: true };
  } catch (err) {
    return { ok: false, status: 0, message: err instanceof Error ? err.message : 'network error' };
  }
}

function messageOf(body: unknown, status: number): string {
  if (body && typeof body === 'object' && 'message' in body) {
    const m = (body as { message?: unknown }).message;
    if (typeof m === 'string' && m.length > 0) return m;
  }
  return `HTTP ${status}`;
}
