/**
 * @file analyticsDiagnostics.test.tsx
 * @description 수집 진단 화면 런타임 계약 — 진행률은 방송이 기본, 폴링이 폴백 (이슈 #682 S3)
 *
 * 잠그는 것: 방송 소스가 `analytics.archive.progress` 를 받아 전역에 담고 끝나면 이력을 다시 받는 것, 소켓이 없고 실행 중 이력이
 * 있을 때만 2초 폴링을 거는 것, 상태 카드 4장이 한 줄 요약행인 것, 수동 집계 버튼이 update 권한과 실행 중 여부로 잠기는 것,
 * 진행률 바가 방송 값을 이력 행보다 우선하는 것, 재집계 창의 요청 본문.
 */

import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';
import { evaluateSafeExpression } from '@core/template-engine/SafeExpressionEvaluator';
import { scanBindings } from '@core/template-engine/BindingShape';
import { TEMPLATE_DIR, loadLayout, dataSource, nodesNamed, nodeById, actionsWithHandler, stringify, collectObjects } from './analyticsLayoutTestUtils';

const REPO_ROOT = path.resolve(TEMPLATE_DIR, '../../..');
const layout = loadLayout('admin_analytics_diagnostics.json');
const modal = loadLayout('partials/admin_analytics/_modal_reaggregate.json');

describe('진행률 — 방송 기본 + 폴링 폴백', () => {
  // @scenario screen=diagnostics, state=normal, viewport=1440, locale=ko
  // @effects archive_progress_broadcast_updates_the_bar
  it('방송 소스는 재집계 진행률 이벤트를 받아 전역에 담고 끝난 방송이면 이력·진단을 다시 받는다', () => {
    const ws = dataSource(layout, 'analytics_archive_progress_ws');
    expect(ws).toMatchObject({ type: 'websocket', channel: 'core.admin.analytics', event: 'analytics.archive.progress', channel_type: 'private' });
    const receive = stringify(ws?.onReceive ?? ws?.on_receive);
    expect(receive).toContain('analyticsArchiveProgress');
    expect(receive).toContain("['success', 'failed', 'skipped']");
    expect(receive).toContain('"dataSourceId":"analytics_archive_runs"');
  });

  // @scenario screen=diagnostics, state=ws_polling, viewport=1440, locale=ko
  // @effects polling_fallback_only_without_socket_and_while_running
  it('진단 응답에 실행 중 이력이 있고 소켓이 없을 때만 2초 폴링을 걸고, 없어지면 멈춘다', () => {
    const diag = dataSource(layout, 'analytics_diagnostics');
    const success = collectObjects(diag?.onSuccess ?? diag?.success);
    const start = success.find((o) => o.handler === 'startInterval');
    expect(start?.params?.id).toBe('analytics_archive_poll');
    expect(start?.params?.intervalMs).toBe(2000);
    expect(start?.if).toContain('broadcasting?.configured !== true');
    expect(start?.if).toContain('running_run_id');
    const stop = success.find((o) => o.handler === 'stopInterval');
    expect(stop?.if).toContain('!response?.data?.data?.archive?.running_run_id');
    expect(actionsWithHandler(layout, 'stopInterval').some((a) => a.params?.id === 'analytics_archive_poll' && !a.if)).toBe(true);
  });

  // @scenario screen=diagnostics, state=running, viewport=1440, locale=ko
  // @effects queued_reaggregate_shows_progress_on_both_paths
  it('큐가 맡는 실행은 행이 늦게 생겨도 두 경로 모두 진행률을 집는다', () => {
    // 모집단은 화면에서 파생한다 — 이 화면이 집계 실행 API 로 보내는 POST 전부를 모아
    // 그 중 큐로 가는 유형(재집계)이 유예를 심는지 본다. 새 실행 유형이 큐로 붙으면
    // 이 목록이 늘어 케이스가 같이 걸린다(손으로 적으면 조용히 빠진다).
    const posts = [...collectObjects(layout), ...collectObjects(modal)].filter(
      (o) => o.handler === 'apiCall' && String(o.target ?? '').includes('/analytics/archive-runs') && o.params?.method === 'POST',
    );
    expect(posts.length).toBeGreaterThanOrEqual(3);

    const queued = posts.filter((o) => stringify(o.params?.body).includes('reaggregate'));
    expect(queued.length).toBeGreaterThanOrEqual(1);
    for (const post of queued) {
      // ① 폴링 경로: 202 에 run_id 가 없어 응답 직후엔 행이 없을 수 있다 — 유예를 심어
      //    행이 없어도 폴링이 붙게 한다. 이 한 줄을 빼면 소켓 끈 사이트에서 바가 안 뜬다.
      expect(stringify(post.onSuccess ?? post.params?.onSuccess ?? post.success)).toContain('analyticsReaggWait');
    }

    // ② 유예는 폴링 한 번마다 줄어 워커가 영영 안 뜨면 저절로 멎는다(무한 폴링 금지).
    const diag = dataSource(layout, 'analytics_diagnostics');
    const onSuccess = collectObjects(diag?.onSuccess ?? diag?.success);
    const decrement = onSuccess.find((o) => o.handler === 'setState' && stringify(o.params).includes('analyticsReaggWait'));
    expect(stringify(decrement?.params)).toContain('- 1');
    const start = onSuccess.find((o) => o.handler === 'startInterval');
    expect(start?.if).toContain('analyticsReaggWait');
    const stopWithGrace = onSuccess.find((o) => o.handler === 'stopInterval');
    expect(stopWithGrace?.if).toContain('analyticsReaggWait');

    // ③ 소켓 경로: 진행률 바는 이력 행 위에만 그려진다 — 처음 보는 실행 번호의 running
    //    방송이면 그때 표를 한 번 다시 받는다. 이 분기를 빼면 소켓 켠 사이트에서 바가 안 뜬다.
    const ws = dataSource(layout, 'analytics_archive_progress_ws');
    const refetches = collectObjects(ws?.onReceive ?? ws?.on_receive).filter(
      (o) => o.handler === 'refetchDataSource' && o.params?.dataSourceId === 'analytics_archive_runs',
    );
    const unseen = refetches.find((o) => String(o.if ?? '').includes("'running'"));
    expect(unseen?.if).toContain('analytics_archive_runs?.data?.data');
    expect(unseen?.if).toContain('$args[0]?.data?.id');
  });

  // @scenario screen=diagnostics, state=normal, viewport=1440, locale=ko
  // @effects archive_progress_broadcast_updates_the_bar
  it('진행률 바는 방송 행이 같은 이력이면 방송 값을, 아니면 이력 행 값을 쓴다', () => {
    const bar = nodeById(layout, 'run_progress_bar');
    // 표(반복 렌더) 안이라 style 은 객체여야 한다 — 문자열 CSS 는 반복 경로가 적용하지 않는다.
    expect(typeof bar?.props?.style).toBe('object');
    expect(bar?.props?.style?.width).toContain('_global.analyticsArchiveProgress?.id === row.id');
    expect(bar?.props?.style?.width).toContain('row.progress_percent');
    expect(bar?.props?.className).toContain('dark:bg-blue-400');
  });
});

describe('상태 카드와 버튼', () => {
  // @scenario screen=diagnostics, state=queue_backlog, viewport=390, locale=en
  // @effects status_cards_are_single_row
  it('상태 카드 4장은 각각 flex-wrap 한 줄 요약행이다', () => {
    for (const id of ['card_collection', 'card_queue', 'card_archive', 'card_storage']) {
      const card = nodeById(layout, id);
      expect(card, id).toBeDefined();
      const row = card!.children?.[0];
      expect(row?.props?.className, id).toContain('flex flex-wrap items-center');
      expect(nodesNamed(card, 'Badge').length, id).toBeGreaterThan(0);
    }
  });

  // @scenario screen=diagnostics, state=locked, viewport=1440, locale=ko
  // @effects manual_archive_reports_skipped_when_locked
  it('집계가 건너뛰어지면 성공 토스트 대신 건너뜀을 알린다', () => {
    // 다른 집계가 자물쇠를 쥐고 있으면 이력에 「건너뜀」만 남는데 화면은 성공과 같은 말을
    // 띄웠다(브라우저 실측 M7-T10 — 「집계를 실행했습니다」 + 이력 「건너뜀」).
    for (const id of ['archive_hour_button', 'finalize_yesterday_button']) {
      const call = actionsWithHandler(nodeById(layout, id), 'apiCall')[0];
      const success = stringify(call.onSuccess);
      expect(success, id).toContain("status !== 'skipped'");
      expect(success, id).toContain("status === 'skipped'");
      expect(success, id).toContain('diagnostics.skipped_toast');
    }
  });

  // @scenario screen=diagnostics, state=running, viewport=1440, locale=ko
  // @effects translation_params_read_data_sources_not_computed
  // [case:components-misc-translation-param-computed]
  it('번역 파라미터는 데이터 소스 경로로 읽는다 — computed 로 읽으면 값이 조용히 빠진다', () => {
    // `$t:키|id={{_computed.x}}` 는 번역문의 `{{id}}` 자리를 그대로 화면에 내보낸다
    // (브라우저 실측 M7-T3c: 「실행 중 #{{id}}」). 같은 화면의 다른 파라미터 10건처럼
    // 데이터 소스 경로로 읽으면 값이 들어온다(「실행 중 #117」).
    const texts = stringify(layout).match(/\$t:[a-zA-Z0-9._]+\|[a-z_]+=\{\{[^"]*?\}\}/g) ?? [];
    expect(texts.length).toBeGreaterThan(0);
    for (const t of texts) {
      expect(t, t).not.toContain('_computed.');
    }
    const running = texts.find((t) => t.includes('fields.running'));
    expect(running).toContain('archive.running_run_id');
  });

  // @scenario screen=diagnostics, state=normal, viewport=1440, locale=ko
  // @effects manual_archive_buttons_need_update_permission
  it('수동 집계 두 버튼은 update 권한·실행 중·다른 집계 진행 중이면 잠기고 각각 hour/day 를 보낸다', () => {
    expect(layout.computed?.canUpdate).toContain("'core.analytics.update'");
    for (const [id, type] of [['archive_hour_button', 'hour'], ['finalize_yesterday_button', 'day']] as const) {
      const btn = nodeById(layout, id);
      expect(btn?.props?.disabled, id).toContain('!_computed.canUpdate');
      expect(btn?.props?.disabled, id).toContain('runningRunId');
      const call = actionsWithHandler(btn, 'apiCall')[0];
      expect(call.target).toBe('/api/admin/analytics/archive-runs');
      expect(call.params.method).toBe('POST');
      expect(call.params.body).toEqual({ run_type: type });
    }
    const reagg = nodeById(layout, 'reaggregate_button');
    expect(actionsWithHandler(reagg, 'openModal')[0]?.target).toBe('analytics_reaggregate_modal');
  });

  // @scenario screen=diagnostics, state=normal, viewport=1440, locale=ko
  // @effects reaggregate_modal_posts_range
  it('재집계 창은 from·to 없이는 잠기고 run_type=reaggregate 로 보내며 큐 안내 뒤 이력을 다시 받는다', () => {
    const btn = nodeById(modal, 'reaggregate_run_button');
    // 잠금 식을 실제로 평가한다 — `!from` 하나만 문자열로 재면 `!to`·실행 중 잠금을 지워도 초록이었다(#682 S3 R16-D1).
    const [lockExpr] = scanBindings(String(btn?.props?.disabled ?? '')).map((b) => b.expr);
    expect(lockExpr, '실행 버튼 잠금 식').toBeTruthy();
    const locked = (reagg: Record<string, unknown>, running: boolean): boolean =>
      !!evaluateSafeExpression(lockExpr, { _global: { analyticsReagg: reagg, analyticsReaggRunning: running } });
    expect(locked({ from: '2026-09-01', to: '2026-09-07' }, false), '두 날짜가 있고 실행 중이 아니면 풀린다').toBe(false);
    expect(locked({ to: '2026-09-07' }, false), '시작일이 없으면 잠긴다').toBe(true);
    expect(locked({ from: '2026-09-01' }, false), '종료일이 없으면 잠긴다').toBe(true);
    expect(locked({ from: '2026-09-01', to: '2026-09-07' }, true), '보내는 중이면 잠긴다(두 번 누름 방지)').toBe(true);
    const call = actionsWithHandler(modal, 'apiCall')[0];
    expect(call.target).toBe('/api/admin/analytics/archive-runs');
    expect(call.params.body).toMatchObject({ run_type: 'reaggregate', from: '{{_global.analyticsReagg?.from}}', to: '{{_global.analyticsReagg?.to}}' });
    expect(stringify(call.onSuccess)).toContain('"dataSourceId":"analytics_archive_runs"');
  });

  // @scenario screen=diagnostics, state=normal, viewport=1440, locale=ko
  // @effects diagnostics_tables_page_via_query
  it('실행 이력·실패 로그 표는 쪽 이동이 질의(runs_page·failures_page)이고 사유 필터도 질의다', () => {
    const navs = actionsWithHandler(layout, 'navigate');
    expect(navs.some((n) => n.params.query?.runs_page === '{{$args[0]}}')).toBe(true);
    expect(navs.some((n) => n.params.query?.failures_page === '{{$args[0]}}')).toBe(true);
    expect(navs.some((n) => typeof n.params.query?.reason === 'string')).toBe(true);
    expect(dataSource(layout, 'analytics_collect_failures')?.params?.reason).toBe('{{query.reason}}');
  });
});

describe('화면이 읽는 진단 키는 서버가 내려주는 키다', () => {
  // @scenario screen=diagnostics, state=normal, viewport=1440, locale=ko
  // @effects diagnostics_screen_only_reads_keys_the_server_sends
  it('trusted_proxy 하위 키를 서버 반환 배열에서 파생해 대조한다 — 없는 키를 읽으면 배지가 조용히 굳는다', () => {
    // 라운드 4 까지 화면은 `trusted_proxy?.configured` 를 읽었다. 서버가 주는 이름은
    // `trusted_configured` 라서, 프록시 뒤 사이트가 TRUSTED_PROXIES 를 **올바로 설정해도**
    // 배지가 늘 「꺼짐」이었다 — 전 방문자가 1명으로 뭉치는 실제 미설정 사이트와 화면상
    // 구분되지 않았다(#682 S3 라운드 5, §6.3-2). 오타는 조용하므로 모집단을 PHP 에서 파생한다.
    const php = fs.readFileSync(path.join(REPO_ROOT, 'app/Support/TrustedProxyDiagnostic.php'), 'utf8');
    const serverKeys = new Set([...php.matchAll(/^\s{16}'([a-z0-9_]+)' =>/gm)].map((m) => m[1]));
    expect(serverKeys.size, 'TrustedProxyDiagnostic::forRequest() 가 돌려주는 키').toBeGreaterThanOrEqual(7);
    expect(serverKeys.has('trusted_configured')).toBe(true);
    expect(serverKeys.has('configured'), '이 이름은 서버에 없다 — 있으면 이 검사의 전제가 바뀐 것이다').toBe(false);

    const read = [...new Set([...stringify(layout).matchAll(/trusted_proxy\?\.([a-z0-9_]+)/g)].map((m) => m[1]))];
    expect(read.length, '화면이 읽는 trusted_proxy 하위 키').toBeGreaterThanOrEqual(1);
    expect(read.filter((k) => !serverKeys.has(k)), '서버가 내려주지 않는 키를 읽는 자리').toEqual([]);

    // fallback 선언도 같은 이름을 써야 한다 — 여기만 틀리면 첫 응답 전 배지가 다른 값을 그린다.
    const fallback = dataSource(layout, 'analytics_diagnostics')?.fallback?.data?.trusted_proxy ?? {};
    expect(Object.keys(fallback).filter((k) => !serverKeys.has(k)), 'fallback 의 서버에 없는 키').toEqual([]);
  });
});

describe('집계 실행은 그 카드 안에서 한다', () => {
  // @scenario screen=diagnostics, state=normal, viewport=1440, locale=ko
  // @effects archive_actions_live_inside_the_archive_card
  it('집계 버튼 3종이 집계 카드의 ml-auto 묶음 안에 있고 페이지 헤더에는 없다', () => {
    // 「집계가 6시간 밀렸다」를 읽은 자리에서 바로 돌릴 수 있어야 한다 — 라운드 4 까지 버튼이
    // 페이지 헤더에만 있어 화면 위로 올라가야 했다(계획서 §4.11 진단 와이어프레임의 `ml-auto` 버튼).
    const card = nodeById(layout, 'card_archive');
    const header = nodeById(layout, 'page_header');
    expect(card, '집계 카드').toBeTruthy();
    expect(header, '페이지 헤더').toBeTruthy();

    const group = nodeById(card, 'card_archive_actions');
    expect(group, '카드 안 실행 묶음').toBeTruthy();
    expect(String(group?.props?.className), 'ml-auto 로 오른쪽에 붙인다').toContain('ml-auto');

    // 모집단을 손으로 적지 않는다 — 레이아웃에서 update 권한으로 잠긴 버튼 전량을 뽑아 대조한다.
    const gatedButtons = collectObjects(layout)
      .filter((o) => typeof o.id === 'string' && typeof o.props?.disabled === 'string' && o.props.disabled.includes('_computed.canUpdate'))
      .map((o) => o.id as string);
    expect(gatedButtons.length, 'update 권한으로 잠긴 버튼').toBeGreaterThanOrEqual(3);

    for (const id of gatedButtons) {
      expect(nodeById(group, id), `${id} 은 집계 카드 안에 있어야 한다`).toBeTruthy();
      expect(nodeById(header, id), `${id} 이 페이지 헤더에 남아 있으면 버튼이 두 벌이다`).toBeUndefined();
    }
  });
});

describe('확장 선언 경고', () => {
  // @scenario screen=diagnostics, state=normal, viewport=1440, locale=ko
  // @effects declaration_warnings_are_shown_on_the_diagnostics_screen
  it('서버가 준 선언 경고가 있을 때만 경고 줄을 그리고 사유를 한 줄씩 보인다', () => {
    // 서버는 `DiagnosticsService` 가 `declaration_warnings` 로 싣는다 — 키 이름이 갈리면 경고가 조용히 사라진다.
    const php = fs.readFileSync(path.join(REPO_ROOT, 'app/Services/Analytics/DiagnosticsService.php'), 'utf8');
    expect(php).toContain("'declaration_warnings' =>");

    const alert = nodeById(layout, 'alert_declaration_warnings');
    expect(alert, '진단 화면에 선언 경고 줄이 있어야 한다').toBeTruthy();
    const [ifExpr] = scanBindings(String(alert?.if ?? '')).map((b) => b.expr);
    expect(ifExpr, '경고 줄의 표시 조건').toBeTruthy();
    const shown = (warnings: unknown): boolean =>
      !!evaluateSafeExpression(ifExpr, { analytics_diagnostics: { data: { declaration_warnings: warnings } } });
    expect(shown(['vendor-shop: 목표 키 형식 위반']), '경고가 있으면 보인다').toBe(true);
    expect(shown([]), '경고가 없으면 그리지 않는다').toBe(false);
    expect(shown(undefined), '옛 응답(키 없음)에서도 깨지지 않고 숨는다').toBe(false);
    expect(stringify(alert)).toContain('analytics_diagnostics?.data?.declaration_warnings');
  });
});

describe('집계 카드 — 스케줄러 판정·화면 열 때 집계', () => {
  // @scenario screen=diagnostics, state=stale, viewport=1440, locale=ko
  // @effects diagnostics_shows_scheduler_state
  it('스케줄러 마지막 실행 시각을 늘 보이고, 미가동 배지는 판정이 false 일 때만(모름 null 은 띄우지 않음) 뜬다', () => {
    expect(stringify(nodeById(layout, 'scheduler_last_run'))).toContain('analytics_diagnostics?.data?.archive?.scheduler?.last_run_at');
    const badge = nodeById(layout, 'scheduler_missing_badge');
    const [expr] = scanBindings(String(badge?.if ?? '')).map((b) => b.expr);
    const show = (alive: unknown) => Boolean(evaluateSafeExpression(expr, { analytics_diagnostics: { data: { archive: { scheduler: { alive } } } } }));
    expect(show(false)).toBe(true);
    expect(show(null)).toBe(false);
    expect(show(true)).toBe(false);
  });

  // @scenario screen=diagnostics, state=normal, viewport=1440, locale=ko
  // @effects run_trigger_on_view_is_labelled
  it('실행 이력의 실행 주체 `on_view` 는 「화면 열 때」로 읽힌다(3로케일 모두 키가 있다)', () => {
    for (const locale of ['ko', 'en']) {
      const lang = JSON.parse(fs.readFileSync(path.join(TEMPLATE_DIR, 'lang', 'partial', locale, 'admin.json'), 'utf8'));
      expect(lang.analytics.diagnostics.run_triggers.on_view, locale).toBeTruthy();
    }
    expect(stringify(layout)).toContain("$t('admin.analytics.diagnostics.run_triggers.' + row.trigger)");
  });
});
