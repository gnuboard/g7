/**
 * 방문자 통계 레이아웃의 모든 바인딩 식이 엔진 평가기에서 **구문 오류 없이** 읽히는지 검사한다.
 * [case:components-misc-evaluator-rejected-syntax]
 *
 * 엔진 평가기는 JS 부분집합이라 대입문(`body[k] = v`)·증감 연산자 같은 문법을 거부한다. 거부된 식은
 * 예외 없이 원본 `{{…}}` 문자열로 흘러가므로(저장 본문이 문자열이 되어 PUT 이 아무것도 바꾸지 않는 식)
 * 화면에서는 조용히 틀린다. 그래서 구운 `.json` 의 바인딩을 평가기에 직접 넣어 본다.
 * 값이 비어 생기는 실행 오류는 무시하고, 구문·금지 문법 오류만 실패로 본다.
 */
import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { evaluateSafeExpression } from '@core/template-engine/SafeExpressionEvaluator';
import { scanBindings } from '@core/template-engine/BindingShape';
import { splitPipes } from '@core/template-engine/PipeRegistry';
import { TEMPLATE_DIR, collectObjects, loadLayout, analyticsLayoutFiles } from './analyticsLayoutTestUtils';

/**
 * 평가기가 식을 읽지 못했음을 뜻하는 오류 — 값이 비어 나는 실행 오류와 구분한다.
 * 「금지 전역 참조」는 넣지 않는다: 반복 변수(`module in …`)처럼 실행 시 스코프가 채우는 이름도
 * 빈 컨텍스트에서는 전역으로 읽혀 거짓 양성이 된다.
 */
const PARSE_ERROR =
  /^(Unexpected|Unterminated|Expected|Invalid|Missing|Only |Assignment operators|Increment operator|Decrement operator|Bitwise|Exponentiation|Keyword|Access to|The comma|new is only|delete is only)/;

/** 트리의 모든 문자열 값을 (경로, 값) 으로 모은다 */
function collectStrings(root: unknown): Array<{ at: string; value: string }> {
  const out: Array<{ at: string; value: string }> = [];
  const walk = (cur: unknown, at: string): void => {
    if (typeof cur === 'string') { out.push({ at, value: cur }); return; }
    if (Array.isArray(cur)) { cur.forEach((v, i) => walk(v, `${at}[${i}]`)); return; }
    if (cur && typeof cur === 'object') {
      Object.entries(cur as Record<string, unknown>).forEach(([k, v]) => walk(v, at ? `${at}.${k}` : k));
    }
  };
  walk(root, '');
  return out;
}

/** 한 식을 평가해 구문 오류면 메시지를, 아니면 null 을 돌려준다 */
function parseFailure(expression: string): string | null {
  const [base] = splitPipes(expression.trim());
  try {
    evaluateSafeExpression(base, {});
    return null;
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return PARSE_ERROR.test(message) ? message : null;
  }
}

/** 레이아웃 한 장의 구문 오류 목록 */
function failuresIn(relative: string): string[] {
  const failures: string[] = [];
  for (const { at, value } of collectStrings(loadLayout(relative))) {
    // 작성자 주석은 평가 대상이 아니다(설명 안의 `{{…}}` 예시를 식으로 읽으면 거짓 양성).
    if (at === 'comment' || at.endsWith('.comment')) continue;
    const isIterationSource = at.endsWith('iteration.source');
    const expressions = value.includes('{{')
      ? scanBindings(value).map((b) => b.expr)
      : isIterationSource ? [value] : [];
    for (const expression of expressions) {
      const failure = parseFailure(expression);
      if (failure) failures.push(`${at}: ${failure} — ${expression.slice(0, 120)}`);
    }
  }
  return failures;
}

describe('방문자 통계 레이아웃 식의 구문', () => {
  // @scenario screen=settings, state=normal
  // @effects bindings_parse_in_the_engine_evaluator
  it('모든 바인딩 식이 엔진 평가기에서 구문 오류 없이 읽힌다', () => {
    const files = analyticsLayoutFiles();
    expect(files.length).toBeGreaterThan(20);
    const failures = files.flatMap((file) => failuresIn(file).map((f) => `${file} ${f}`));
    expect(failures).toEqual([]);
  });

  // @scenario screen=settings, state=normal
  // @effects bindings_parse_in_the_engine_evaluator
  it('검사기는 대입문을 구문 오류로 잡는다', () => {
    expect(parseFailure("(function() { const b = {}; b.x = 1; return b; })()")).toMatch(/Unexpected token/);
    expect(parseFailure("Object.fromEntries(['a'].map(k => [k, 1]))")).toBeNull();
  });

  // @scenario screen=settings, state=normal
  // @effects translation_params_are_substituted
  it('번역 인자는 `$t:key|param={{식}}` 으로만 넘긴다 — 식 안의 `$t()` 는 키만 받아 인자를 버린다', () => {
    const files = analyticsLayoutFiles();
    // 모집단이 비면 아래 비교는 빈 배열끼리라 조용히 통과한다 — 훑은 파일과 식 안 `$t(` 호출 자리를 먼저 센다.
    expect(files.length, '훑은 레이아웃·조각').toBeGreaterThan(20);
    const calls = files.flatMap((file) =>
      collectStrings(loadLayout(file))
        .filter(({ value }) => /\$t\(\s*['"]/.test(value))
        .map(({ at, value }) => ({ at: `${file} ${at}`, value })),
    );
    expect(calls.length, '식 안에서 $t() 를 부르는 자리').toBeGreaterThan(0);
    const offenders = calls
      .filter(({ value }) => /\$t\(\s*['"][^'"]+['"]\s*,/.test(value))
      .map(({ at, value }) => `${at}: ${value.slice(0, 100)}`);
    expect(offenders).toEqual([]);
  });

  // @scenario screen=settings, state=normal
  // @effects settings_save_sends_changed_keys_across_tabs
  it('설정 저장 본문 식은 받아 온 값과 달라진 키만 담은 객체로 평가된다', () => {
    const layout = loadLayout('admin_analytics_settings.json');
    const bodies = collectStrings(layout).filter(({ at, value }) => at.endsWith('params.body') && value.includes('_local.form'));
    expect(bodies.length).toBeGreaterThan(0);
    const [expression] = scanBindings(bodies[0].value).map((b) => b.expr);

    const base = { privacy_mode: 'full', retention_days: 90, ip_mask_octets: 0, visit_timeout_minutes: 30 };
    // 개인정보 탭을 보고 있지만 보존 탭도 고쳐 두었다 — 둘 다 담겨야 한다(브라우저 실측 M6-T4).
    const body = evaluateSafeExpression(expression, {
      query: { tab: 'privacy' },
      analytics_settings: { data: { settings: base } },
      _local: { form: { settings: { ...base, privacy_mode: 'anonymous', retention_days: 30 } } },
    });
    expect(body).toEqual({ privacy_mode: 'anonymous', retention_days: 30 });

    // 손대지 않은 키는 어느 탭이든 보내지 않는다 — 안 본 탭을 덮어쓰지 않는다.
    const untouched = evaluateSafeExpression(expression, {
      query: { tab: 'privacy' },
      analytics_settings: { data: { settings: base } },
      _local: { form: { settings: { ...base, ip_mask_octets: 2 } } },
    });
    expect(untouched).toEqual({ ip_mask_octets: 2 });

    // 되돌려 달라진 키가 없으면 지금 탭의 키로 물러선다(빈 본문을 보내지 않는다).
    const none = evaluateSafeExpression(expression, {
      query: { tab: 'privacy' },
      analytics_settings: { data: { settings: base } },
      _local: { form: { settings: { ...base } } },
    });
    expect(Object.keys(none)).toEqual(expect.arrayContaining(['privacy_mode', 'ip_mask_octets']));
    expect(none).not.toHaveProperty('retention_days');
  });

  // @scenario screen=pages, state=normal
  // @effects report_page_size_follows_the_setting
  it('리포트 쪽 크기 선택은 응답이 알려 준 쪽 크기를 보이고, 고른 값은 그대로 주소에 싣는다', () => {
    // 주소에 `per_page` 가 없으면 서버가 설정 「상세 TOP N」(예 30)으로 채운다 — 선택란이 25 를 보이면 거짓이고,
    // 고정 선택지에 30 이 없으면 빈칸이 된다. 25 를 고르면 주소에서 지우던 옛 동작은 설정값으로 되돌아가 버린다.
    const grid = loadLayout('partials/admin_analytics/_partial_report_grid.json');
    const select = collectObjects(grid).find((node) => node.props?.name === 'analytics_report_per_page');
    expect(select, '쪽 크기 선택란').toBeTruthy();
    const context = { query: {}, analytics_report: { data: { pagination: { per_page: 30 } } } };
    const [valueExpr] = scanBindings(select!.props.value).map((b) => b.expr);
    const [optionsExpr] = scanBindings(select!.props.options).map((b) => b.expr);
    expect(evaluateSafeExpression(valueExpr, context)).toBe('30');
    const options = evaluateSafeExpression(optionsExpr, context) as Array<{ value: string }>;
    expect(options.map((o) => o.value)).toEqual(['10', '25', '30', '50', '100']);

    const change = collectObjects(select).find((node) => node.handler === 'navigate');
    const [perPageExpr] = scanBindings(change!.params.query.per_page).map((b) => b.expr);
    expect(evaluateSafeExpression(perPageExpr, { $event: { target: { value: '25' } } })).toBe('25');
  });

  // @scenario screen=privacy_requests, state=normal
  // @effects action_conditions_are_evaluated
  it('액션의 조건은 `if` 로 적는다 — `data-if` 는 노드 렌더 조건이라 액션에 붙이면 평가되지 않고 모든 갈래가 돈다', () => {
    // [case:components-misc-action-data-if-ignored]
    // 정보주체 삭제 창이 `<navigate data-if>` 로 적어, 구운 JSON 에서 조건이 `params["data-if"]` 로 들어갔다.
    // 엔진은 `action.if` 만 보므로 삭제 뒤 목록 이동과 결과 재조회가 둘 다 돌았다(#682 S3 라운드 15).
    const actions = analyticsLayoutFiles().flatMap((file) =>
      collectObjects(loadLayout(file))
        .filter((node) => typeof node.handler === 'string')
        .map((node) => ({ file, node })),
    );
    expect(actions.length).toBeGreaterThan(100);
    const offenders = actions
      .filter(({ node }) => node.params && typeof node.params === 'object' && 'data-if' in node.params)
      .map(({ file, node }) => `${file}: ${node.handler} 의 조건이 params["data-if"] 에 있다`);
    expect(offenders).toEqual([]);
  });

  // @scenario screen=privacy_requests, state=normal
  // @effects subject_delete_confirm_names_the_subject
  it('정보주체 삭제 창은 정보주체 화면이 채운 대상(IP·회원·키)을 그대로 보인다 — 빈 칸 문자열에서 멈추지 않는다', () => {
    // 정보주체 화면은 쓰지 않는 칸을 '' 로 채운다. 창이 `??` 로 이으면 빈 문자열에서 멈춰 대상이 공란이 된다.
    const screen = loadLayout('admin_analytics_privacy_requests.json');
    const opener = collectObjects(screen).find(
      (node) => node.handler === 'setState' && node.params?.analyticsSubject && typeof node.params.analyticsSubject === 'object',
    );
    expect(opener, '정보주체 화면이 삭제 창에 대상을 싣는 setState').toBeTruthy();
    const modal = loadLayout('partials/admin_analytics/_modal_subject_delete.json');
    const targetLine = collectStrings(modal).find(({ at, value }) => at.endsWith('.text') && value.includes('analyticsSubject?.visitor_key'));
    expect(targetLine, '창이 대상을 그리는 줄').toBeTruthy();
    const [targetExpression] = scanBindings(targetLine!.value).map((b) => b.expr);

    const cases: Array<[Record<string, string>, string]> = [
      [{ subject: 'ip', value: '203.0.113.77' }, '203.0.113.77'],
      [{ subject: 'user_id', value: '6654' }, '#6654'],
      [{ subject: 'visitor_key', value: 'abc123' }, 'abc123'],
    ];
    for (const [query, expected] of cases) {
      const subject: Record<string, unknown> = {};
      for (const [field, binding] of Object.entries(opener!.params.analyticsSubject as Record<string, string>)) {
        const [expr] = scanBindings(binding).map((b) => b.expr);
        subject[field] = expr ? evaluateSafeExpression(expr, { query }) : binding;
      }
      expect(evaluateSafeExpression(targetExpression, { _global: { analyticsSubject: subject } }), `${query.subject} 검색`).toBe(expected);
    }
  });

  // @scenario screen=privacy_requests, state=normal
  // @effects privacy_screen_reads_only_fields_the_server_sends
  it('정보주체 화면이 방문 항목에서 읽는 필드는 서버가 싣는 필드 안에 있다 — 없는 필드를 세면 늘 0 이다', () => {
    // 결과 요약이 `v.actions` 배열 길이를 셌는데 서버는 `actions_count` 만 싣는다 — 「액션 0건」 고정(#682 S3 라운드 15).
    const controller = fs.readFileSync(
      path.resolve(TEMPLATE_DIR, '../../..', 'app/Http/Controllers/Api/Admin/Analytics/PrivacyController.php'),
      'utf8',
    );
    const mapBody = controller.match(/'visits'\s*=>\s*\$found\['visits'\]->map\(([\s\S]*?)\)->all\(\)/);
    expect(mapBody, 'PrivacyController::subjects 의 방문 항목 map').toBeTruthy();
    const sent = new Set([...mapBody![1].matchAll(/'([a-z_]+)'\s*=>/g)].map((m) => m[1]));
    expect(sent.size).toBeGreaterThanOrEqual(5);

    const screen = loadLayout('admin_analytics_privacy_requests.json');
    const strings = collectStrings(screen).filter(({ at }) => !(at === 'comment' || at.endsWith('.comment')));
    const read = new Set<string>();
    for (const { value } of strings) {
      if (/\brow\??\./.test(value)) for (const m of value.matchAll(/\brow\??\.([a-z_]+)/g)) read.add(m[1]);
      if (value.includes('analytics_subjects')) for (const m of value.matchAll(/\bv\??\.([a-z_]+)/g)) read.add(m[1]);
    }
    for (const node of collectObjects(screen)) {
      if (typeof node.props?.idField === 'string' && String(node.props?.data ?? '').includes('analytics_subjects')) read.add(node.props.idField);
    }
    expect(read.size).toBeGreaterThanOrEqual(5);
    expect([...read].filter((field) => !sent.has(field))).toEqual([]);
  });
});
