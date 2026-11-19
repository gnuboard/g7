/**
 * editorSpecBundleFetcher.ts — 편집기 스펙 묶음 엔드포인트 가짜 fetch (시험 도우미)
 *
 * `GET /api/templates/{id}/editor-spec-bundle?modules[]=..&plugins[]=..` 를 흉내 낸다.
 * 서버(`EditorSpecBundleService`)와 같이 모듈 → 플러그인 → 템플릿 순서로 항목을 내고,
 * `specs` 에 없는 식별자는 항목 status 404(비활성·미존재)로 낸다. 묶음이 아닌 URL 은 404 응답이다.
 *
 * @since engine-v1.95.0
 */

import { vi } from 'vitest';
import type { EditorSpec } from '../../spec/specTypes';

/** 묶음 URL 판별식 */
export const EDITOR_SPEC_BUNDLE_URL = /\/templates\/([^/?]+)\/editor-spec-bundle(?:\?(.*))?$/;

/**
 * 식별자 → spec 매핑으로 묶음 엔드포인트를 흉내 내는 fetch 를 만든다.
 *
 * @param specs 식별자 → spec (null 이면 「스펙 파일 미작성」 정상 응답)
 * @param overrides 식별자 → 항목 status 강제값 (예: 500 판정 실패)
 * @return vi.fn 으로 감싼 fetch
 */
export function makeBundleFetcher(
  specs: Record<string, EditorSpec | null>,
  overrides: Record<string, number> = {},
): typeof fetch {
  return vi.fn(async (url: string | URL | Request) => {
    const m = EDITOR_SPEC_BUNDLE_URL.exec(String(url));
    if (!m) {
      return { ok: false, status: 404, json: async () => ({}) } as unknown as Response;
    }
    const templateId = decodeURIComponent(m[1]);
    const params = new URLSearchParams(m[2] ?? '');
    const ordered: Array<{ kind: 'module' | 'plugin' | 'template'; identifier: string }> = [
      ...params.getAll('modules[]').map((identifier) => ({ kind: 'module' as const, identifier })),
      ...params.getAll('plugins[]').map((identifier) => ({ kind: 'plugin' as const, identifier })),
      { kind: 'template' as const, identifier: templateId },
    ];
    const items = ordered.map(({ kind, identifier }) => {
      const forced = overrides[identifier];
      if (forced !== undefined && forced !== 200) {
        return { kind, identifier, status: forced, spec: null, error: 'forced' };
      }
      if (!(identifier in specs)) {
        return { kind, identifier, status: 404, spec: null, error: `${kind}_not_found` };
      }
      return { kind, identifier, status: 200, spec: specs[identifier], error: null };
    });
    return {
      ok: true,
      status: 200,
      json: async () => ({ success: true, data: { identifier: templateId, items } }),
    } as unknown as Response;
  }) as unknown as typeof fetch;
}
