// e2e:allow 권한 게이트(입력·버튼 잠금) 레이아웃 계약 검증 — 브라우저 실측은 W1·W2·W3 통합 뒤 총괄 세션이 권한 회수 계정으로 함께 한다(이력 20261010_0208_710_abilities수정_W3)
/**
 * @file plugin-abilities-gate.test.ts
 * @description sirsoft-koreaexim 화면 abilities 게이트 (7.1.0 abilities 게이트 plugins·G04)
 */

import { describe, it, expect } from 'vitest';
import page from '../../../layouts/admin/plugin_settings.json';
import form from '../../../layouts/admin/partials/plugin_settings/_settings_form.json';

type Node = Record<string, any>;

/** 트리에서 조건에 맞는 노드를 모두 모은다 */
function collect(node: unknown, pred: (n: Node) => boolean, out: Node[] = []): Node[] {
  if (Array.isArray(node)) {
    node.forEach((v) => collect(v, pred, out));
    return out;
  }
  if (node && typeof node === 'object') {
    if (pred(node as Node)) out.push(node as Node);
    Object.values(node).forEach((v) => collect(v, pred, out));
  }
  return out;
}

/** `{{ ... }}` 표현식을 주어진 이름들로 평가한다 */
function evaluate(raw: unknown, scope: Record<string, unknown>): any {
  if (typeof raw === 'boolean') return raw;
  const expr = String(raw).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), `return (${expr});`)(...Object.values(scope));
}

const WRITE = ['POST', 'PUT', 'PATCH', 'DELETE'];
/** 쓰기 apiCall 을 품은 버튼 */
function writeButtons(tree: unknown, exclude: (target: string) => boolean = () => false): Node[] {
  return collect(tree, (n) => n.name === 'Button' && collect(n, (a) => a.handler === 'apiCall' && WRITE.includes(String(a.params?.method ?? '')) && !exclude(String(a.target ?? ''))).length > 0);
}

describe('환율 설정 읽기 전용 (#3·#5·#10)', () => {
  /**
   * 설정 폼은 코어 설정 저장 권한(can_update)과 환율 관리 권한(can_manage)을 함께 본다.
   *
   * @scenario case=settings_without_plugin_update
   *
   * @effects settings_form_follows_both_axes
   */
  it('isSettingsReadOnly 가 두 축을 본다', () => {
    const expr = (page as Node).computed.isSettingsReadOnly;
    const s = (manage: boolean, update: boolean) => ({ koreaeximStatus: { data: { abilities: { can_manage: manage } } }, settings: { data: { abilities: { can_update: update } } } });
    expect(evaluate(expr, s(true, false))).toBe(true);
    expect(evaluate(expr, s(false, true))).toBe(true);
    expect(evaluate(expr, s(true, true))).toBe(false);
    const inputs = collect(form, (n) => ['Input', 'Select', 'Toggle', 'Checkbox'].includes(n.name));
    expect(inputs.length).toBeGreaterThan(0);
    inputs.forEach((i) => expect(i.props.disabled).toBe('{{_computed.isSettingsReadOnly}}'));
  });

  /**
   * 읽기 전용 안내는 빠진 권한마다 따로 맞는 문구로 보이고, 응답이 오기 전에는 보이지 않는다.
   * 환율 관리 권한은 있고 플러그인 설정 수정 권한만 없는 관리자에게 「조회 권한으로 접속」 안내를 보이지 않는다.
   *
   * @scenario case=settings_without_plugin_update
   *
   * @effects read_only_banners_match_missing_permission
   */
  it('읽기 전용 안내가 빠진 권한에 맞는 문구로 보인다', () => {
    const computed = (page as Node).computed as Record<string, string>;
    const pluginBanner = collect(page, (n) => n.id === 'plugin_read_only_banner')[0];
    expect(pluginBanner, '플러그인 설정 읽기 전용 안내가 없습니다').toBeTruthy();
    expect(JSON.stringify(pluginBanner)).toContain('$t:sirsoft-koreaexim.plugin_read_only');
    const manageBanner = collect(page, (n) => n.text === '$t:sirsoft-koreaexim.read_only')[0];
    expect(manageBanner, '환율 관리 읽기 전용 안내가 없습니다').toBeTruthy();

    /** 두 권한 판정과 응답 도착 여부로 평가 범위를 만든다 */
    const scope = (manage: boolean | null, update: boolean | null) => {
      const ds = {
        koreaeximStatus: manage === null ? undefined : { data: { abilities: { can_manage: manage } } },
        settings: update === null ? undefined : { data: { abilities: { can_update: update } } },
      };
      const c = Object.fromEntries(['isReadOnly', 'isPluginReadOnly', 'isSettingsReadOnly'].map((k) => [k, evaluate(computed[k], ds)]));
      return { ...ds, _computed: c, _local: {} };
    };
    // 환율 관리 권한만 있고 플러그인 설정 수정 권한이 없다 → 플러그인 안내만
    expect(evaluate(pluginBanner.if, scope(true, false))).toBe(true);
    expect(evaluate(manageBanner.if, scope(true, false))).toBe(false);
    // 플러그인 설정 수정 권한만 있고 환율 관리 권한이 없다 → 조회 권한 안내만
    expect(evaluate(pluginBanner.if, scope(false, true))).toBe(false);
    expect(evaluate(manageBanner.if, scope(false, true))).toBe(true);
    // 둘 다 있다 → 안내 없음
    expect(evaluate(pluginBanner.if, scope(true, true))).toBe(false);
    expect(evaluate(manageBanner.if, scope(true, true))).toBe(false);
    // 응답 전(또는 설정 403) → 안내 없음
    expect(evaluate(pluginBanner.if, scope(null, null))).toBe(false);
    expect(evaluate(manageBanner.if, scope(null, null))).toBe(false);
  });

  /**
   * 설정·알림 정의를 읽을 권한이 없으면(403) 빈 화면 대신 이유를 보인다.
   *
   * @scenario case=settings_forbidden
   *
   * @effects forbidden_reasons_shown
   */
  it('403 안내', () => {
    expect(evaluate(collect(page, (n) => n.id === 'koreaexim_settings_forbidden')[0].if, { _local: { koreaeximSettingsForbidden: true } })).toBe(true);
    expect(JSON.stringify(page)).toContain('koreaeximNotificationsForbidden');
  });
});
