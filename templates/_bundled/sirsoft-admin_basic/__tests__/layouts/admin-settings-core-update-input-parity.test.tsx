import { describe, it, expect } from 'vitest';
import updateTab from '../../layouts/partials/admin_settings/_tab_update.json';
import koAdmin from '../../lang/partial/ko/admin.json';
import enAdmin from '../../lang/partial/en/admin.json';
// 번들 ja 팩도 같은 모집단이다 — ko·en 만 보면 ja 누락이 조용히 통과한다.
import jaAdmin from '../../../../../lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/partial/admin.json';

/**
 * 환경설정 「업데이트」 탭 — 착수 입력을 터미널(`core:update`)과 같은 판정으로 (7.2.1).
 *
 * 1. 검색 인덱스 다시 만들기 줄은 서버가 엔진의 점검 지원을 알릴 때만 보인다(옛 서버 응답이면 종전처럼 보인다).
 * 2. 수동 ZIP 안내가 서명 파일(같은 이름 .sig.json) 자동 인식을 알린다 — 웹에는 `--signature=` 입력 칸이 없다.
 *
 * 효과 요약(마커 아님 — 평문): 지원 안 하는 엔진에서는 재생성 선택지가 숨고, ZIP 안내가 서명 자동 검증을 알린다.
 */

type Json = Record<string, unknown>;

/** 레이아웃 트리를 훑어 조건을 만족하는 노드를 모은다. */
function collect(node: unknown, predicate: (obj: Json) => boolean, out: Json[] = []): Json[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, predicate, out));
    return out;
  }
  if (node && typeof node === 'object') {
    const obj = node as Json;
    if (predicate(obj)) out.push(obj);
    Object.values(obj).forEach((value) => collect(value, predicate, out));
  }
  return out;
}

/** 표현식 `{{…}}` 을 주어진 상태로 평가한다(이 시험이 쓰는 단순 식 전용). */
function evaluate(expr: string, computed: Json): unknown {
  const body = expr.replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function('_computed', `return (${body});`)(computed);
}

const coreUpdate = (admin: unknown) =>
  ((admin as Json).settings as Json).core_update as Record<string, string>;

describe('업데이트 탭 — 입력 격차 (터미널과 같은 판정)', () => {
  // @scenario actor=with_permission, run_state=idle, rebuild_search_index=on
  // @effects the_rebuild_search_index_choice_follows_engine_maintenance_support
  it('검색 인덱스 다시 만들기 줄은 엔진이 점검을 지원할 때만 보인다', () => {
    const row = collect(updateTab, (obj) => obj.id === 'core_update_rebuild_index_row')[0];

    expect(row, '검색 인덱스 줄을 찾지 못했다').toBeDefined();
    expect(typeof row.if, '줄에 조건이 없으면 지원하지 않는 엔진에서도 보인다').toBe('string');

    const cond = row.if as string;
    expect(cond).toContain('search_index_rebuild_available');

    expect(evaluate(cond, { coreUpdateStatus: { search_index_rebuild_available: true } })).toBe(true);
    expect(evaluate(cond, { coreUpdateStatus: { search_index_rebuild_available: false } })).toBe(false);
    // 필드가 없는 옛 서버 응답은 종전처럼 보인다
    expect(evaluate(cond, { coreUpdateStatus: {} })).toBe(true);
    // 상태를 아직 받지 못했으면(받는 중) 보이지 않는다 — 받은 뒤에만 뜻이 있는 선택지다(loadingNegativeState 규칙)
    expect(evaluate(cond, {})).toBe(false);
  });

  // @scenario actor=with_permission, run_state=idle, source=manual_zip
  // @effects the_manual_zip_hint_mentions_signature_auto_detection
  it('수동 ZIP 안내가 서명 파일 자동 검증을 ko·en·ja 모두에서 알린다', () => {
    expect(JSON.stringify(updateTab)).toContain('$t:admin.settings.core_update.manual_zip_hint');

    for (const [locale, admin] of [['ko', koAdmin], ['en', enAdmin], ['ja', jaAdmin]] as const) {
      const hint = coreUpdate(admin).manual_zip_hint;
      expect(hint, `${locale} manual_zip_hint 없음`).toBeTruthy();
      expect(hint, `${locale} 안내에 서명 파일 이름이 없다`).toContain('.sig.json');
    }
  });

  // @scenario actor=with_permission, run_state=idle, source=manual_zip
  // @effects the_manual_zip_hint_mentions_signature_auto_detection
  it('서명 파일 경로를 따로 받는 입력은 없다 (자동 인식으로 대체)', () => {
    const start = collect(updateTab, (obj) => obj.handler === 'startCoreUpdate');

    expect(start.length).toBeGreaterThan(0);
    for (const action of start) {
      const params = JSON.stringify(action.params ?? action);
      expect(params).not.toContain('signature');
    }
  });
});
