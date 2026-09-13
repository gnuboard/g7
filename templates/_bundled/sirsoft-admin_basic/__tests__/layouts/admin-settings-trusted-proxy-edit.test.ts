/**
 * @file admin-settings-trusted-proxy-edit.test.ts
 * @description 환경설정 > 고급 「신뢰 프록시」 편집 블록의 레이아웃 계약 (#609)
 *
 * 이 화면의 결함은 **오류를 내지 않는다.** 라디오가 폼 값을 쓰지 못해도, 목록 입력이
 * 저장 키에서 풀려도, 와일드카드 정리가 빠져도 화면은 그려지고 저장 버튼도 눌린다.
 * 방문자 IP 만 조용히 어긋난다. 그래서 렌더가 아니라 **배선 자체**를 값으로 못박는다.
 *
 * 특히 세 갈래를 고정한다.
 *   ① 태그 입력이 저장 키 `advanced.trusted_proxies` 에 묶여 있다 — 풀리면 입력은 되는데
 *      저장되지 않는다.
 *   ② 「지정한 주소만」 으로 옮길 때 `*` 를 걷어낸다 — 남으면 화면은 목록인데 실제로는
 *      와일드카드로 돈다(미들웨어가 `*` 를 만나면 나머지를 보지 않는다).
 *   ③ 잠금·권한이 모든 입력에 걸린다 — 한 곳만 빠지면 `.env` 소유 설치에서 그 입력만
 *      살아 있고, 저장은 서버가 조용히 버린다.
 *
 * @effects saved_list_is_normalized_to_a_config_value, env_priority_locks_the_trusted_proxy_field, probe_reports_resolution_without_saving
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const LAYOUT = resolve(
  __dirname,
  '../../layouts/partials/admin_settings/_tab_advanced.json'
);

type Node = {
  id?: string;
  name?: string;
  type?: string;
  if?: string;
  props?: Record<string, unknown>;
  actions?: Array<Record<string, unknown>>;
  children?: Node[];
  [key: string]: unknown;
};

const layout: Node = JSON.parse(readFileSync(LAYOUT, 'utf8'));

/** 트리 전체를 훑어 조건에 맞는 노드를 모은다. */
function collect(node: unknown, match: (n: Node) => boolean, found: Node[] = []): Node[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, match, found));

    return found;
  }
  if (!node || typeof node !== 'object') return found;

  const current = node as Node;
  if (match(current)) found.push(current);
  Object.values(current).forEach((value) => collect(value, match, found));

  return found;
}

function findById(id: string): Node {
  const [node] = collect(layout, (n) => n.id === id);
  expect(node, `노드 ${id} 가 레이아웃에서 사라졌다`).toBeTruthy();

  return node;
}

/** 라디오 하나가 change 시 폼에 쓰는 값 표현식 */
function formWriteOf(radio: Node): string {
  const action = (radio.actions ?? []).find((a) => a.handler === 'setState');
  expect(action, '라디오가 폼 값을 쓰지 않는다').toBeTruthy();

  const params = (action as { params?: Record<string, unknown> }).params ?? {};

  return String(params['form.advanced.trusted_proxies'] ?? '');
}

describe('환경설정 > 고급 — 신뢰 프록시 편집 블록', () => {
  /**
   * @scenario proxies_setting=unset
   *
   * @effects the_edit_block_sits_with_the_diagnostic_rows
   */
  it('편집 블록이 진단 행과 문서 링크 사이에 있다', () => {
    const card = findById('card_trusted_proxy');
    const stack = (card.children ?? []).find(
      (c) => (c.props as { className?: string } | undefined)?.className === 'row-stack'
    );
    const ids = (stack?.children ?? []).map((c) => c.id);

    const edit = ids.indexOf('trusted_proxy_edit');
    const doc = ids.indexOf('trusted_proxy_doc_hint');

    expect(edit, '편집 블록이 없다').toBeGreaterThan(-1);
    expect(doc, '문서 링크 행이 없다').toBeGreaterThan(-1);
    // 진단을 본 자리 바로 아래에서 고치게 한다 — 카드 밖이나 문서 링크 뒤로 밀리면
    // 「경고를 본 화면에서 조치한다」는 이 기능의 전제가 깨진다.
    expect(edit).toBeLessThan(doc);
  });

  /**
   * @scenario proxies_setting=star
   *
   * @effects mode_choice_writes_the_saved_list
   */
  it('세 모드가 각각 다른 저장값을 쓴다', () => {
    const radios = collect(
      findById('trusted_proxy_modes'),
      (n) => (n.props as { type?: string } | undefined)?.type === 'radio'
    );

    expect(radios).toHaveLength(3);

    const writes = radios.map(formWriteOf);

    // 「신뢰하지 않음」은 빈 배열 — null(미설정)이 아니라 명시적 선택이어야 `.env` 를 덮는다.
    expect(writes[0]).toBe('{{[]}}');
    expect(writes[1]).toBe("{{['*']}}");
    // 「지정한 주소만」은 기존 태그를 유지하되 와일드카드를 걷어낸다.
    expect(writes[2]).toContain("filter(v => v !== '*')");
  });

  /**
   * @scenario proxies_setting=star
   *
   * @effects mode_choice_writes_the_saved_list
   */
  it('모드 전환이 변경 상태를 올려 저장 버튼을 깨운다', () => {
    const radios = collect(
      findById('trusted_proxy_modes'),
      (n) => (n.props as { type?: string } | undefined)?.type === 'radio'
    );

    for (const radio of radios) {
      const action = (radio.actions ?? []).find((a) => a.handler === 'setState');
      const params = (action as { params?: Record<string, unknown> }).params ?? {};

      // 이것이 빠지면 값은 바뀌는데 저장 버튼이 잠긴 채라 운영자가 저장할 수 없다.
      expect(params.hasChanges, `${String(params.trustedProxyListMode)} 모드가 변경 상태를 올리지 않는다`).toBe(true);
    }
  });

  /**
   * 폼 안의 라디오에 `name` 만 있고 `autoBinding: false` 가 없으면 엔진의 폼 자동바인딩이
   * `value` 를 폼 값으로 되쓴다 — 세 라디오가 같은 값을 읽어 **전부 클릭한 모드의 value 가
   * 된다**(실측 2026-09-12: 초기 none/nearest/list → 클릭 후 셋 다 nearest). 화면은 멀쩡해
   * 보이고 checked 도 맞게 붙어서 눈으로는 드러나지 않는다.
   *
   * 해법은 트러블슈팅 사례 4(engine-v1.17.6)의 선례대로 `autoBinding: false` 다. `name` 을
   * 빼는 우회는 native 라디오 그룹(방향키 이동·스크린리더의 「3개 중 1」)을 잃는다.
   *
   * @scenario proxies_setting=star
   *
   * @effects mode_choice_writes_the_saved_list
   */
  it('모드 라디오는 이름으로 묶이되 폼 자동바인딩은 꺼져 있다', () => {
    const radios = collect(
      findById('trusted_proxy_modes'),
      (n) => (n.props as { type?: string } | undefined)?.type === 'radio'
    );

    expect(radios).toHaveLength(3);

    for (const r of radios) {
      const props = r.props as { name?: string; value?: string; autoBinding?: unknown; checked?: unknown };

      expect(props.autoBinding, `라디오(${props.value})의 자동바인딩이 켜져 있으면 value 가 폼 값으로 덮인다`).toBe(false);
      expect(props.name, 'native 라디오 그룹이 사라지면 방향키 이동·그룹 낭독을 잃는다').toBe('trusted_proxy_mode');
      expect(String(props.checked)).toContain('_computed.trustedProxyMode');
    }

    expect(radios.map((r) => (r.props as { value?: string }).value)).toEqual(['none', 'nearest', 'list']);
  });

  /**
   * @scenario proxies_setting=cidr
   *
   * @effects saved_list_is_normalized_to_a_config_value
   */
  it('태그 입력이 저장 키에 묶여 있고 목록 모드에서만 나타난다', () => {
    const field = findById('trusted_proxy_list_field');

    expect(field.if).toContain("_computed.trustedProxyMode === 'list'");

    const [tagInput] = collect(field, (n) => n.name === 'TagInput');
    expect(tagInput, '태그 입력이 없다').toBeTruthy();

    const props = tagInput.props as { name?: string; creatable?: boolean };
    expect(props.name).toBe('advanced.trusted_proxies');
    // creatable 이 아니면 새 주소를 적을 수 없고 목록 모드가 무의미해진다.
    expect(props.creatable).toBe(true);
  });

  /**
   * @scenario proxies_setting=star
   *
   * @effects env_priority_locks_the_trusted_proxy_field
   */
  it('권한·잠금이 모든 입력에 걸려 있다', () => {
    const block = findById('trusted_proxy_edit');
    const inputs = collect(
      block,
      (n) => (n.props as { type?: string } | undefined)?.type === 'radio' || n.name === 'TagInput'
    );

    expect(inputs.length, '검사 대상 입력이 없다').toBeGreaterThan(0);

    for (const input of inputs) {
      const disabled = String((input.props as { disabled?: unknown }).disabled ?? '');

      expect(disabled, `${input.name} 입력에 권한 가드가 없다`).toContain('_computed.isReadOnly');
      expect(disabled, `${input.name} 입력에 .env 잠금 가드가 없다`).toContain('_computed.trustedProxyLocked');
    }
  });

  /**
   * @scenario proxies_setting=star
   *
   * @effects probe_reports_resolution_without_saving
   */
  it('사전 검사가 저장 키의 현재 값을 그대로 보낸다', () => {
    const button = findById('btn_probe_trusted_proxy');
    const call = (button.actions ?? []).find((a) => a.handler === 'apiCall') as
      | { target?: string; params?: { body?: Record<string, unknown> } }
      | undefined;

    expect(call?.target).toBe('/api/admin/settings/test-trusted-proxy');

    // 저장된 값이 아니라 **화면이 들고 있는 후보값**을 보내야 "저장 전 확인" 이 성립한다.
    expect(String(call?.params?.body?.trusted_proxies)).toContain(
      '_local.form?.advanced?.trusted_proxies'
    );
  });

  /**
   * @scenario proxies_setting=star
   *
   * @effects probe_reports_resolution_without_saving
   */
  it('검사 결과는 응답이 있을 때만 그린다', () => {
    const result = findById('trusted_proxy_probe_result');

    expect(result.if).toBe('{{_local.trustedProxyProbe}}');
  });

  /**
   * @scenario proxies_setting=star
   *
   * @effects the_spoof_warning_shows_only_when_trust_is_opened
   */
  it('위조 경고는 신뢰를 여는 모드에서만 나온다', () => {
    const warning = findById('trusted_proxy_spoof_warning');

    // 「신뢰하지 않음」에서도 뜨면 경고가 배경 소음이 되어 아무도 읽지 않게 된다.
    expect(warning.if).toContain("_computed.trustedProxyMode !== 'none'");
  });
});
