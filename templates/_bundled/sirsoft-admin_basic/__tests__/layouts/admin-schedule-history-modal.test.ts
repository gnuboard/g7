/**
 * @file admin-schedule-history-modal.test.ts
 * @description 예약 작업 실행 이력 창 바인딩 회귀 테스트 (7.1.0 영역 J D-J2)
 *
 * 시나리오 SSoT: tests/scenarios/schedule-history-modal.yaml
 *
 * 결함: 실행 이력 창이 이력이 있어도 늘 「실행 이력이 없습니다」만 보였다. 창의 데이터소스는 엔진
 * (ModalDataSourceWrapper)이 창을 열 때 받아 `initGlobal` 로만 넘기고 id 로는 화면에 싣지 않는데,
 * 창이 `schedule_history?.data?.data` 를 id 로 읽고 있었다. 쪽 이동(7.1.0 에서 고친 것)도 이력이
 * 보여야 닿는 자리라 함께 죽어 있었다. 실행자 칸은 응답에 없는 `triggered_by_name` 을 읽었다.
 *
 * 테스트 대상:
 * - layouts/partials/admin_schedule_list/_modal_history.json — `initGlobal: scheduleHistory` 로 받아
 *   `_global.scheduleHistory.data`·`.pagination` 을 읽는다, 실행자는 `triggered_by?.name`.
 * - layouts/partials/admin_schedule_list/_tab_schedules.json — 「실행 이력」 을 열 때 앞 작업의 이력·쪽 번호를 비운다.
 *   「즉시 실행」 을 열 때 지난 실행 결과를 비운다(Esc·X 로 닫아도 다시 실행할 수 있게).
 */

import { describe, it, expect } from 'vitest';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

type Node = Record<string, any>;

/** 레이아웃 JSON 전체에서 조건에 맞는 객체를 모두 모은다. */
function collect(node: unknown, pred: (n: Node) => boolean, out: Node[] = []): Node[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, pred, out));
  } else if (node && typeof node === 'object') {
    if (pred(node as Node)) out.push(node as Node);
    Object.values(node as Node).forEach((child) => collect(child, pred, out));
  }

  return out;
}

describe('실행 이력 창', () => {
  const modal = require('../../layouts/partials/admin_schedule_list/_modal_history.json');
  const raw = JSON.stringify(modal);

  /**
   * @scenario surface=admin_schedule_history_modal
   * @effects schedule_history_modal_shows_runs_and_output
   */
  it('창 데이터소스는 initGlobal 로 이력을 전역에 넘긴다', () => {
    const ds = (modal.data_sources ?? []).find((d: Node) => d.id === 'schedule_history');
    expect(ds).toBeDefined();
    expect(ds.initGlobal).toBe('scheduleHistory');
  });

  /**
   * @scenario surface=admin_schedule_history_modal
   * @effects schedule_history_modal_shows_runs_and_output
   */
  it('목록·빈 상태·쪽 이동이 id(schedule_history?.)가 아니라 _global.scheduleHistory 를 읽는다', () => {
    expect(raw).not.toMatch(/schedule_history\?\./);
    const each = collect(modal, (n) => typeof n.iteration?.source === 'string');
    expect(each.map((n) => n.iteration.source)).toContain('_global.scheduleHistory?.data');
    const pager = collect(modal, (n) => n.name === 'Pagination')[0];
    expect(pager.props.currentPage).toContain('_global.scheduleHistory?.pagination?.current_page');
  });

  /**
   * 실행 시각은 응답의 ISO 원문(「2026-10-06T21:18:00+09:00」)이 아니라 날짜 파이프로 사람이 읽는 시각으로 보인다(O-J2).
   *
   * @scenario surface=admin_schedule_history_modal
   * @effects schedule_history_modal_shows_runs_and_output
   */
  it('실행 시각은 datetime 파이프로 표시한다', () => {
    expect(raw).toContain('item.started_at | datetime');
    expect(raw).not.toMatch(/\{\{item\.started_at\}\}/);
  });

  /**
   * 실행자 칸은 응답에 없는 triggered_by_name 이 아니라 triggered_by.name 을 읽는다.
   *
   * @scenario surface=admin_schedule_history_modal
   * @effects schedule_history_modal_shows_runs_and_output
   */
  it('실행자는 응답의 triggered_by.name 을 읽는다', () => {
    expect(raw).not.toContain('triggered_by_name');
    expect(raw).toContain('item.triggered_by?.name');
  });
});

describe('실행 이력 열기 동작', () => {
  const tab = require('../../layouts/partials/admin_schedule_list/_tab_schedules.json');

  /**
   * @scenario surface=admin_schedule_history_modal
   * @effects schedule_history_modal_shows_runs_and_output
   */
  it('열 때 앞 작업의 이력을 비우고 1쪽부터 연다', () => {
    const opens = collect(tab, (n) => n.handler === 'openModal' && n.target === 'schedule_history_modal');
    expect(opens.length).toBe(1);
    const seqs = collect(tab, (n) => n.actions !== undefined && asArray(n.actions).some((a: Node) => a === opens[0]));
    expect(seqs.length).toBe(1);
    const setState = asArray(seqs[0].actions).find((a: Node) => a.handler === 'setState');
    expect(setState.params.selectedSchedule).toBe('{{$args[1]}}');
    expect(setState.params.scheduleHistory).toBeNull();
    expect(setState.params.historyPage).toBe(1);
  });

  /**
   * 「즉시 실행」 창은 결과가 오면 실행 버튼을 감추고 결과를 싣는다. 결과를 비우는 일이 [닫기] 버튼에만 있어
   * Esc·X 로 닫고 다시 열면 지난 결과와 [닫기]만 남아 다시 실행할 수 없었다(2026-10-07 재실측). 그래서 여는 쪽이 비운다.
   *
   * @scenario surface=admin_schedule_run_modal
   * @effects schedule_run_modal_reopens_without_previous_result
   */
  it('즉시 실행 창은 열 때 지난 실행 결과를 비운다', () => {
    const opens = collect(tab, (n) => n.handler === 'openModal' && n.target === 'schedule_run_modal');
    expect(opens.length).toBe(1);
    const seqs = collect(tab, (n) => n.actions !== undefined && asArray(n.actions).some((a: Node) => a === opens[0]));
    expect(seqs.length).toBe(1);
    const setState = asArray(seqs[0].actions).find((a: Node) => a.handler === 'setState');
    expect(setState.params.selectedSchedule).toBe('{{$args[1]}}');
    expect(setState.params.runResult).toBeNull();
  });
});

describe('예약 작업 목록 — 실행 시각 칸', () => {
  const tab = require('../../layouts/partials/admin_schedule_list/_tab_schedules.json');
  const raw = JSON.stringify(tab);

  /**
   * 「다음 실행 예정」·「마지막 실행」 칸도 응답의 ISO 원문(「2026-10-07T11:10:21+09:00」)이 아니라
   * 날짜 파이프로 보인다 — 실행 이력 창만 고치면 같은 화면 목록에 원문이 남는다(2026-10-07 재실측).
   *
   * @scenario surface=admin_schedule_list
   * @effects schedule_list_shows_readable_run_times
   */
  it('다음 실행·마지막 실행은 datetime 파이프로 표시한다', () => {
    expect(raw).toContain('row.next_run_at | datetime');
    expect(raw).toContain('row.last_run_at | datetime');
    // 표시 글(text)만 본다 — 같은 값을 조건(if)으로 읽는 자리는 파이프가 필요 없다.
    expect(raw).not.toMatch(/"text":"\{\{row\.(next|last)_run_at\}\}"/);
  });
});
