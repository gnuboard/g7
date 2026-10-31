/**
 * injectedPropsCrossSave 테스트
 *
 * inject_props 교차 저장 — 확장 content 의 inject_props injection props 를 교체해
 * layout-extensions API 로 저장한다.
 */

import { describe, it, expect, vi } from 'vitest';
import { saveInjectedPropsToExtension } from '../../utils/injectedPropsCrossSave';

function jsonRes(status: number, body: unknown, ok = status >= 200 && status < 300) {
  return { ok, status, json: async () => body } as Response;
}

describe('saveInjectedPropsToExtension', () => {
  const tpl = 'sirsoft-admin_basic';

  // @scenario overlay_position=inject_props, inject_props_save_target=extension_row_correctly, followup_action=save
  // @effects inject_props_change_cross_saves_to_extension_row_not_host_layout
  it('inject_props injection props 교체 후 PUT 저장 + lock_version 흐름', async () => {
    const content = {
      target_layout: 'admin_user_detail',
      injections: [
        { target_id: 'user_detail_tabs', position: 'inject_props', props: { tabs: { _append: [{ id: 'old' }] } } },
        { target_id: 'slot', position: 'append_child', components: [{ id: 'c1' }] },
      ],
    };
    const fetchMock = vi.fn();
    // 1) GET show
    fetchMock.mockResolvedValueOnce(
      jsonRes(200, { data: { id: 2, content: JSON.stringify(content), lock_version: 5 } }),
    );
    // 2) PUT
    fetchMock.mockResolvedValueOnce(jsonRes(200, { data: { lock_version: 6 } }));

    const nextProps = { tabs: { _append: [{ id: 'edited' }] } };
    const result = await saveInjectedPropsToExtension(tpl, 2, 'user_detail_tabs', nextProps, fetchMock as any);

    expect(result).toEqual({ kind: 'success', newLockVersion: 6 });
    // PUT body 검증.
    const putCall = fetchMock.mock.calls[1];
    expect(putCall[0]).toContain('/layout-extensions/2');
    expect(putCall[1].method).toBe('PUT');
    const body = JSON.parse(putCall[1].body);
    // inject_props injection 의 props 만 교체, 다른 injection(append_child) 보존.
    expect(body.content.injections[0].props).toEqual(nextProps);
    expect(body.content.injections[1].components).toEqual([{ id: 'c1' }]);
    expect(body.expected_lock_version).toBe(5);
  });

  // @scenario overlay_position=inject_props, inject_props_save_target=extension_row_correctly
  // @effects inject_props_cross_save_reports_injection_not_found_without_put
  it('대상 injection 없으면 injection_not_found (PUT 미발생)', async () => {
    const content = { injections: [{ target_id: 'other', position: 'inject_props', props: {} }] };
    const fetchMock = vi.fn();
    fetchMock.mockResolvedValueOnce(
      jsonRes(200, { data: { id: 2, content: JSON.stringify(content), lock_version: 1 } }),
    );
    const result = await saveInjectedPropsToExtension(tpl, 2, 'user_detail_tabs', {}, fetchMock as any);
    expect(result.kind).toBe('injection_not_found');
    expect(fetchMock).toHaveBeenCalledTimes(1); // GET 만, PUT 없음
  });

  // @scenario overlay_position=inject_props, inject_props_save_target=extension_row_correctly, followup_action=save
  // @effects inject_props_cross_save_409_returns_conflict_versions
  it('409 → conflict', async () => {
    const content = { injections: [{ target_id: 'h', position: 'inject_props', props: {} }] };
    const fetchMock = vi.fn();
    fetchMock.mockResolvedValueOnce(
      jsonRes(200, { data: { id: 2, content: JSON.stringify(content), lock_version: 3 } }),
    );
    fetchMock.mockResolvedValueOnce(jsonRes(409, { current_version: 7, your_version: 3 }, false));
    const result = await saveInjectedPropsToExtension(tpl, 2, 'h', { a: 1 }, fetchMock as any);
    expect(result).toEqual({ kind: 'conflict', currentVersion: 7, yourVersion: 3 });
  });

  // @scenario overlay_position=inject_props, inject_props_save_target=extension_row_correctly
  // @effects inject_props_cross_save_404_returns_not_found
  it('확장 404 → not_found', async () => {
    const fetchMock = vi.fn();
    fetchMock.mockResolvedValueOnce(jsonRes(404, { message: 'not found' }, false));
    const result = await saveInjectedPropsToExtension(tpl, 99, 'h', {}, fetchMock as any);
    expect(result.kind).toBe('not_found');
  });
  /** @effects save_conflict_reads_versions_from_response_helper_errors_payload, inject_props_change_cross_saves_to_extension_row_not_host_layout */
  it('409 본문이 errors 아래에 버전을 담아도(ResponseHelper 형식) conflict 버전을 읽는다', async () => {
    const content = { injections: [{ target_id: 'h', position: 'inject_props', props: {} }] };
    const fetchMock = vi.fn();
    fetchMock.mockResolvedValueOnce(
      jsonRes(200, { data: { id: 2, content: JSON.stringify(content), lock_version: 3 } }),
    );
    fetchMock.mockResolvedValueOnce(
      jsonRes(409, { success: false, errors: { current_version: 7, your_version: 3 } }, false),
    );
    const result = await saveInjectedPropsToExtension(tpl, 2, 'h', { a: 1 }, fetchMock as any);
    expect(result).toEqual({ kind: 'conflict', currentVersion: 7, yourVersion: 3 });
  });
});
