/**
 * #682 PO 실측 후속 4 — 통계 화면의 필터 영역마다 「초기화」가 있는가.
 *
 * 모집단은 저장소에서 파생한다: 통계 레이아웃·조각(구운 `.json`) 전부에서 **사용자가 조작하는 입력**(입력칸·선택·
 * 토글·라디오)이 주소에 쓰는 질의 키를 모으고, 그 입력을 품은 가장 가까운 카드(`admin-card`)를 한 「영역」으로 본다.
 * 입력이 이름 붙은 액션(`actionRef`)으로 주소를 바꾸면 그 액션의 키까지 따라간다(정보주체 요청 검색 폼).
 *
 * 영역마다 그 안의 버튼 하나가 그 키 전부를 지우는(`{{undefined}}`) 이동을 가져야 한다. 입력이 화면 상태
 * (`_local.*`)에 먼저 담기는 폼이면 그 상태도 함께 되돌려야 한다 — 주소만 비우면 입력칸에 옛 값이 남는다.
 *
 * 보기 선택(무엇을 볼지 고르는 것)은 걸러 낸 조건이 아니라 대상이 아니다 — 실시간 창(`minutes`)·요일 지표
 * (`metric`)·확장 차원(`dimension`)·한 쪽 행 수(`per_page`)·서브탭(`tab`)·쪽 번호.
 */
import { describe, expect, it } from 'vitest';

import { analyticsLayoutFiles, loadLayout } from './analyticsLayoutTestUtils';

/** 사용자가 조작하는 입력 컴포넌트 */
const CONTROLS = new Set(['Input', 'Select', 'Toggle', 'RadioGroup', 'Textarea', 'MultiSelect', 'SearchableDropdown']);

/** 조건이 아닌 질의 키 — 보기 선택·쪽 번호 */
const NOT_FILTER = new Set(['page', 'failures_page', 'runs_page', 'per_page', 'tab', 'minutes', 'metric', 'dimension']);

type Node = Record<string, any>;

interface Area {
  file: string;
  card: Node;
  keys: Set<string>;
  localKeys: Set<string>;
}

/** 액션 목록을 펼친다 — sequence·조건 분기·actionRef(이름 붙은 액션) 안까지 */
function flattenActions(actions: unknown, named: Record<string, Node>, seen = new Set<string>()): Node[] {
  const out: Node[] = [];
  const walk = (cur: unknown): void => {
    if (Array.isArray(cur)) { cur.forEach(walk); return; }
    if (!cur || typeof cur !== 'object') return;
    const a = cur as Node;
    if (typeof a.actionRef === 'string' && !seen.has(a.actionRef)) {
      seen.add(a.actionRef);
      walk(named[a.actionRef]);
    }
    if (typeof a.handler === 'string') out.push(a);
    for (const v of Object.values(a)) if (v && typeof v === 'object') walk(v);
  };
  walk(actions);
  return out;
}

/**
 * 이동이 **바꾸는** 질의 키. 병합하지 않는 이동이 지금 값을 그대로 잇는 키(`{{query.preset}}`)는 조건을
 * 바꾸지 않으므로 뺀다 — 행동 화면의 차원 선택이 기간 키를 그렇게 옮긴다.
 */
function navigateKeys(action: Node): string[] {
  if (action.handler !== 'navigate') return [];
  return Object.entries(action.params?.query ?? {})
    .filter(([key, value]) => value !== `{{query.${key}}}`)
    .map(([key]) => key);
}

/** 화면 상태 쓰기(`setState target=local`)의 키 */
function localStateKeys(action: Node): string[] {
  if (action.handler !== 'setState' || action.params?.target !== 'local') return [];
  return Object.keys(action.params ?? {}).filter((k) => k !== 'target');
}

/** 트리를 돌며 입력마다 가장 가까운 카드 조상을 찾아 영역으로 묶는다 */
function areasOf(file: string): Area[] {
  const layout = loadLayout(file);
  const named: Record<string, Node> = layout.named_actions ?? {};
  const areas = new Map<Node, Area>();

  const walk = (cur: unknown, card: Node | null): void => {
    if (Array.isArray(cur)) { cur.forEach((c) => walk(c, card)); return; }
    if (!cur || typeof cur !== 'object') return;
    const node = cur as Node;
    const isCard = typeof node.props?.className === 'string' && /(^|\s)admin-card(\s|$)/.test(node.props.className);
    const here = isCard ? node : card;

    if (typeof node.name === 'string' && CONTROLS.has(node.name) && here) {
      const actions = flattenActions(node.actions, named);
      const keys = actions.flatMap(navigateKeys).filter((k) => !NOT_FILTER.has(k));
      const locals = actions.flatMap(localStateKeys);
      // 폼 상태 키는 주소를 직접 바꾸지 않는 입력(라디오·달력)에서도 모은다 — 검색 버튼이 그 상태를 주소로 옮긴다.
      const area = areas.get(here) ?? { file, card: here, keys: new Set<string>(), localKeys: new Set<string>() };
      keys.forEach((k) => area.keys.add(k));
      locals.forEach((k) => area.localKeys.add(k));
      areas.set(here, area);
    }
    for (const [k, v] of Object.entries(node)) {
      if (k !== 'actions' && v && typeof v === 'object') walk(v, here);
    }
  };
  walk(layout, null);
  // 주소 조건을 하나도 쓰지 않는 카드(보기 선택만 있는 카드)는 필터 영역이 아니다.
  return [...areas.values()].filter((area) => area.keys.size > 0);
}

/** 카드 안 버튼 중 영역의 키를 전부 지우는 것 */
function resetButtonsOf(area: Area, named: Record<string, Node>): Node[] {
  const buttons: Node[] = [];
  const walk = (cur: unknown): void => {
    if (Array.isArray(cur)) { cur.forEach(walk); return; }
    if (!cur || typeof cur !== 'object') return;
    const node = cur as Node;
    if (node.name === 'Button') buttons.push(node);
    for (const [k, v] of Object.entries(node)) if (k !== 'actions' && v && typeof v === 'object') walk(v);
  };
  walk(area.card);

  return buttons.filter((button) => {
    const actions = flattenActions(button.actions, named);
    const cleared = new Set(
      actions
        .filter((a) => a.handler === 'navigate')
        .flatMap((a) => Object.entries(a.params?.query ?? {}).filter(([, v]) => v === '{{undefined}}').map(([k]) => k)),
    );
    const resetLocals = new Set(actions.flatMap(localStateKeys));
    return [...area.keys].every((k) => cleared.has(k)) && [...area.localKeys].every((k) => resetLocals.has(k));
  });
}

describe('통계 화면 — 필터 영역마다 초기화', () => {
  const files = analyticsLayoutFiles();
  const areas = files.flatMap(areasOf);

  // @scenario screen=visitors, state=normal, viewport=1440, locale=ko
  // @effects every_filter_area_has_a_reset_that_clears_its_keys
  it('모집단 — 필터 영역이 파생된다(기간 바·리포트 표·방문자·실패 로그·정보주체 검색)', () => {
    const refs = areas.map((a) => `${a.file}#${a.card.id ?? '?'}`);
    expect(areas.length, `파생한 필터 영역: ${refs.join(', ')}`).toBeGreaterThanOrEqual(5);
    for (const expected of ['analytics_period_bar', 'analytics_report_section', 'visitors_card', 'failures_card', 'search_card']) {
      expect(areas.some((a) => a.card.id === expected), `${expected} 가 필터 영역으로 잡혀야 한다(판정이 입력을 못 읽는다)`).toBe(true);
    }
  });

  // @scenario screen=visitors, state=normal, viewport=1440, locale=ko
  // @scenario screen=pages, state=normal, viewport=1440, locale=ko
  // @scenario screen=diagnostics, state=normal, viewport=1440, locale=ko
  // @scenario screen=privacy_requests, state=normal, viewport=1440, locale=ko
  // @effects every_filter_area_has_a_reset_that_clears_its_keys
  it('영역마다 그 영역이 쓰는 질의 키(와 폼 상태)를 전부 걷는 초기화 버튼이 있다', () => {
    const missing = areas
      .filter((area) => resetButtonsOf(area, loadLayout(area.file).named_actions ?? {}).length === 0)
      .map((a) => `${a.file}#${a.card.id ?? '?'} — 키 ${[...a.keys].join(',')}${a.localKeys.size ? ` · 폼 ${[...a.localKeys].join(',')}` : ''}`);
    expect(missing, '초기화가 없거나 일부 조건을 남기는 영역').toEqual([]);
  });

  // @scenario screen=visitors, state=normal, viewport=1440, locale=ko
  // @effects filter_reset_is_locked_when_nothing_to_clear
  it('초기화 버튼은 걷을 조건이 없으면 잠긴다(누르면 아무 일도 없는 버튼을 두지 않는다)', () => {
    const unlocked = areas.flatMap((area) =>
      resetButtonsOf(area, loadLayout(area.file).named_actions ?? {})
        .filter((b) => typeof b.props?.disabled !== 'string' || !b.props.disabled.includes('{{'))
        .map((b) => `${area.file}#${b.id ?? '?'}`),
    );
    expect(unlocked).toEqual([]);
  });
});
