/**
 * 7.1.0 전수 실측 영역 E 후속 — 관측(O-E2·O-E5·O-E6·O-E8·O-E10)을 화면 계약으로 잠근다.
 *
 * 합성 입력이 아니라 구운 `.json`(런타임 계약)과 실제 언어 파일(ko·en·ja 팩)을 읽는다. 모집단은
 * 저장소에서 파생한다 — 「고친 한 곳」만 세면 같은 결함이 다른 화면에 남아도 초록이다.
 *
 * ① 재집계 「건너뜀」 행이 그 이유를 그린다 (O-E5)
 * ② 주소를 그리는 칸이 그 행의 주소가 설정 전 원래 주소인지 알린다 (O-E8)
 * ③ 봇 「최근 요청」이 고른 기간을 따른다는 사실을 화면이 말한다 (O-E10)
 * ④ 기간 바의 날짜 칸은 입력이 끝난 뒤에 이동한다 (O-E2)
 * ⑤ 통계 화면의 스위치는 사람이 읽는 이름을 가진다 (O-E6)
 */
import { describe, expect, it } from 'vitest';
import { evaluateSafeExpression } from '@core/template-engine/SafeExpressionEvaluator';

import {
  analyticsLayoutFiles,
  collectObjects,
  langValue,
  loadLayout,
  loadLocale,
  localeNames,
  nodeById,
} from './analyticsLayoutTestUtils';

/** `{{…}}` 한 겹을 벗긴다 */
function unwrap(expression: string): string {
  const t = expression.trim();
  return t.startsWith('{{') && t.endsWith('}}') ? t.slice(2, -2) : t;
}

/** 식을 평가한다 — 평가기에서 실패하면 `'error'` */
function evaluate(expression: string, context: Record<string, unknown>): unknown {
  try {
    return evaluateSafeExpression(unwrap(expression), {
      _computed: {},
      _global: {},
      _local: {},
      query: {},
      $t: (key: string) => key,
      ...context,
    });
  } catch {
    return 'error';
  }
}

/** `$t:` 키가 모든 로케일(ko·en·ja)에 비지 않은 값으로 있는지 — 없으면 누락 목록을 돌려준다 */
function missingTranslations(key: string): string[] {
  const dotted = key.replace(/^\$t:/, '');
  const ns = dotted.split('.')[0];
  return localeNames().filter((locale) => {
    const value = langValue(loadLocale(locale, ns), dotted);
    return typeof value !== 'string' || value.trim() === '';
  }).map((locale) => `${locale}: ${dotted}`);
}

describe('① 재집계 「건너뜀」 행은 그 이유를 그린다', () => {
  // @scenario screen=diagnostics, state=normal, viewport=1440, locale=ko
  // @effects skipped_run_shows_its_reason
  it('집계 이력 표의 상태 칸이 건너뛴 행에만 서버가 준 이유를 보인다', () => {
    const grid = nodeById(loadLayout('admin_analytics_diagnostics.json'), 'archive_runs_grid');
    expect(grid, '집계 이력 표').toBeTruthy();

    // 이유를 그리는 노드 — 텍스트가 row.error 를 읽는다.
    const reasonNodes = collectObjects(grid).filter((n) => typeof n.text === 'string' && n.text.includes('row.error'));
    expect(reasonNodes.length, 'row.error 를 그리는 노드').toBeGreaterThanOrEqual(1);

    const shows = (row: Record<string, unknown>): boolean => reasonNodes.some((n) => {
      const cond = typeof n.if === 'string' ? evaluate(n.if, { row }) : true;
      return cond !== 'error' && !!cond && evaluate(n.text, { row }) === row.error;
    });

    expect(shows({ status: 'skipped', error: '같은 기간을 이미 집계하고 있어 건너뛰었습니다.' }), '건너뜀 행').toBe(true);
    expect(shows({ status: 'success', error: null }), '성공 행').toBe(false);
    // 실패 행의 error 는 예외 원문(SQL 조각 등)이라 조회 권한 화면에 흘리지 않는다.
    expect(shows({ status: 'failed', error: 'SQLSTATE[HY000]' }), '실패 행').toBe(false);
  });
});

describe('② 주소 칸은 그 행이 설정 전 원래 주소인지 알린다', () => {
  // @scenario screen=privacy_requests, state=normal, viewport=1440, locale=ko
  // @effects each_address_row_says_whether_it_is_masked
  it('주소를 그리는 통계 화면 전량이 ip_masked === false 인 행에 표시를 단다', () => {
    // 모집단 — 행 단위로 주소를 그리는 화면(현재 접속자는 지금 이 순간의 원장 값이라 제외, 기존 ⑩과 같은 경계).
    const files = analyticsLayoutFiles().filter((f) => /^admin_analytics_(privacy_requests|diagnostics|visitor_detail)\.json$/.test(f));
    expect(files.length).toBe(3);

    const missing: string[] = [];
    for (const file of files) {
      const objects = collectObjects(loadLayout(file));
      const markers = objects.filter((n) => typeof n.if === 'string' && n.if.includes('ip_masked'));
      // 행(row)·방문(visit)·카드(첫 방문) 어느 이름으로 읽든, ip_masked 가 false 일 때만 참이어야 한다.
      const reacts = markers.some((n) => {
        const ctxFor = (flag: unknown): Record<string, unknown> => {
          const visit = { ip: '203.0.113.7', ip_masked: flag };
          return { row: visit, visit, analytics_visitor: { data: { ip_mask_octets: 2, visits: [visit] } } };
        };
        const unmasked = evaluate(n.if, ctxFor(false));
        const masked = evaluate(n.if, ctxFor(true));
        const unknown = evaluate(n.if, ctxFor(null));
        return unmasked === true && masked === false && unknown === false;
      });
      if (!reacts) missing.push(`${file} (ip_masked 조건 ${markers.length}개 — false 에서만 참인 표시가 없다)`);
      for (const n of markers) {
        for (const key of collectObjects(n).flatMap((m) => [m.text, m.props?.text]).filter((t): t is string => typeof t === 'string' && t.startsWith('$t:'))) {
          missing.push(...missingTranslations(key.split('|')[0]));
        }
      }
    }

    expect(missing, '원래 주소를 「마스킹됨」 아래 그대로 두는 화면').toEqual([]);
  });
});

describe('③ 봇 최근 요청은 고른 기간을 따른다고 말한다', () => {
  // @scenario screen=bots, state=normal, viewport=1440, locale=ko
  // @effects bot_recent_hits_follow_the_selected_period
  it('최근 요청 카드에 기간 안내 문구가 있고 모든 로케일에 번역이 있다', () => {
    const card = nodeById(loadLayout('admin_analytics_bots.json'), 'recent_hits_card');
    expect(card, '최근 요청 카드').toBeTruthy();
    const texts = collectObjects(card).map((n) => n.text).filter((t): t is string => typeof t === 'string');
    expect(texts).toContain('$t:admin.analytics.bots.recent_hint');
    expect(missingTranslations('$t:admin.analytics.bots.recent_hint')).toEqual([]);
  });
});

describe('④ 기간 바의 날짜 칸은 입력이 끝난 뒤에 이동한다', () => {
  // @scenario screen=overview, state=normal, viewport=1440, locale=ko
  // @effects keyboard_date_entry_leaves_one_history_entry
  it('시작·끝 날짜 칸의 change 이동이 debounce 로 묶인다', () => {
    // 키보드로 날짜를 치면 브라우저는 칸이 유효한 날짜가 될 때마다 change 를 낸다(일 「28」 → 02 → 28).
    // 바로 이동하면 중간 날짜가 이력과 요청에 한 건씩 남아 뒤로 가기가 중간 날짜로 간다(7.1.0 실측 O-E2).
    const bar = loadLayout('partials/admin_analytics/_partial_period_bar.json');
    for (const id of ['period_from', 'period_to']) {
      const input = nodeById(bar, id);
      expect(input, id).toBeTruthy();
      const change = (input!.actions ?? []).find((a: any) => a.type === 'change');
      expect(change, `${id} change`).toBeTruthy();
      const debounce = typeof change.debounce === 'number' ? change.debounce : change.debounce?.delay;
      expect(debounce, `${id} debounce(ms)`).toBeGreaterThanOrEqual(300);
    }
  });
});

describe('⑤ 통계 화면의 스위치는 사람이 읽는 이름을 가진다', () => {
  // @scenario screen=settings, state=normal, viewport=1440, locale=ko
  // @effects analytics_switches_have_readable_names
  it('통계 레이아웃·조각의 Toggle 전량이 ariaLabel 또는 label 을 갖고 번역이 있다', () => {
    // Toggle 은 label 이 없으면 name(필드 키 `settings.enabled`)을 접근 가능한 이름으로 읽힌다(7.1.0 실측 O-E6).
    const offenders: string[] = [];
    let toggles = 0;
    for (const file of analyticsLayoutFiles()) {
      for (const node of collectObjects(loadLayout(file)).filter((n) => n.name === 'Toggle')) {
        toggles += 1;
        const name = node.props?.ariaLabel ?? node.props?.label;
        if (typeof name !== 'string' || name.trim() === '') {
          offenders.push(`${file}: ${node.props?.name ?? node.id ?? '(이름 없음)'}`);
          continue;
        }
        if (name.startsWith('$t:')) offenders.push(...missingTranslations(name).map((m) => `${file}: ${m}`));
      }
    }
    expect(toggles, '통계 화면 스위치').toBeGreaterThanOrEqual(16);
    expect(offenders, '사람이 읽는 이름이 없는 스위치').toEqual([]);
  });
});
