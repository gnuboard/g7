/**
 * editorSpecLoader.stateSource.test.ts — 상태 묶음 출처(`__source`) 부착
 *
 * @since engine-v1.78.3
 */

import { describe, it, expect } from 'vitest';
import { loadEditorSpecBundle } from '../../spec/editorSpecLoader';
import type { EditorSpec } from '../../spec/specTypes';
import { makeBundleFetcher } from './editorSpecBundleFetcher';

/** 식별자 → spec 매핑으로 묶음 엔드포인트 fetch 를 모킹 (engine-v1.95.0 — 편집기는 묶음 한 요청으로 받는다) */
function makeFetcher(specs: Record<string, EditorSpec | null>): typeof fetch {
  return makeBundleFetcher(specs);
}

/**
 * 7.1.0 실측: 같은 창 id(`delete_confirm_modal`·`privacy_run_modal`·`identity_policy_form_modal` …)를 여러 확장이 따로
 * 쓰는데, 상태 묶음에 출처가 없어 회원 목록의 삭제 창에 게시판·페이지·CKEditor 의 창 상태가 섞여 5개(같은 id 중복)로 보였고,
 * 먼저 병합된 확장의 표본이 다른 화면의 창에 적용됐다. 병합할 때 묶음마다 출처를 남긴다.
 */
describe('loadEditorSpecBundle — 상태 묶음 출처', () => {
  // @scenario source=fixture
  // @effects state_groups_carry_source
  it('states 묶음마다 `__source`(kind·id)를 남긴다', async () => {
    const fetcher = makeFetcher({
      tpl: { states: { groups: [{ scope: { kind: 'modal', match: 'delete_confirm_modal' }, items: [{ id: 'modal_filled' }] }] } },
      board: { states: { groups: [{ scope: { kind: 'modal', match: 'delete_confirm_modal' }, items: [{ id: 'modal_filled' }] }] } },
      gdpr: { states: { groups: [{ scope: { kind: 'modal', match: 'privacy_run_modal' }, items: [{ id: 'modal_filled' }] }] } },
    });
    const { spec } = await loadEditorSpecBundle({
      templateIdentifier: 'tpl',
      activeModuleIdentifiers: ['board'],
      activePluginIdentifiers: ['gdpr'],
      fetcher,
    });
    const sources = (spec!.states!.groups ?? []).map((g) => (g as { __source?: unknown }).__source);
    expect(sources).toEqual([
      { kind: 'module', id: 'board' },
      { kind: 'plugin', id: 'gdpr' },
      { kind: 'template', id: 'tpl' },
    ]);
  });
});
