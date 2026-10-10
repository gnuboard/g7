import { describe, it, expect } from 'vitest';
import moduleList from '../../layouts/admin_module_list.json';
import pluginList from '../../layouts/admin_plugin_list.json';
import scheduleListLayout from '../../layouts/admin_schedule_list.json';
import scheduleTab from '../../layouts/partials/admin_schedule_list/_tab_schedules.json';
import scheduleHistory from '../../layouts/partials/admin_schedule_list/_modal_history.json';
import languagePackContent from '../../layouts/partials/admin_language_pack_list/_content.json';

/**
 * 모듈/플러그인 목록의 페이지네이션 바인딩이 **응답의 실제 경로**를 보는지 고정한다.
 *
 * `/api/admin/{modules,plugins}` 의 응답 봉투는
 * `data: { data: [...], pagination: { total, current_page, last_page, per_page }, ... }` 다.
 * `data.total` / `data.last_page` 를 읽으면 항상 `undefined` 라서
 *  - 총건수가 "총 0개 중 1-0개 표시" 로 나오고
 *  - `serverTotalPages` 가 1 로 고정돼 2페이지 이후 확장이 화면에서 사라진다.
 *
 * 확장이 12개(기본 per_page)를 넘는 사이트에서만 드러나므로 데이터가 적을 때는
 * 눈으로 확인되지 않는다. 그래서 경로를 정적으로 고정한다.
 */

const WRONG_PATHS = ['?.data?.total', '?.data?.last_page', '?.data?.current_page', '?.data?.per_page'];

describe('확장 목록 — 페이지네이션 바인딩 경로', () => {
  const cases = [
    { name: '모듈', layout: moduleList, source: 'modules' },
    { name: '플러그인', layout: pluginList, source: 'plugins' },
  ];

  // @scenario envelope_slot=pagination, surface=module_list, consumer=pager_buttons, position=first
  // @effects extension_list_pager_reads_pagination_slot
  it.each(cases)('$name 목록은 pagination 하위 경로를 읽는다', ({ layout, source }) => {
    const json = JSON.stringify(layout);

    WRONG_PATHS.forEach((suffix) => {
      expect(
        json.includes(`${source}${suffix}`),
        `${source}${suffix} 는 응답에 없는 경로입니다 — ${source}?.data?.pagination${suffix.replace('?.data', '')} 를 써야 합니다`,
      ).toBe(false);
    });

    expect(json).toContain(`${source}?.data?.pagination?.total`);
    expect(json).toContain(`${source}?.data?.pagination?.last_page`);
    expect(json).toContain(`${source}?.data?.pagination?.current_page`);
  });

  // @scenario envelope_slot=pagination, surface=plugin_list, consumer=pager_buttons, position=first
  // @effects list_array_path_is_left_untouched
  it.each(cases)('$name 목록은 목록 배열 경로는 그대로 유지한다', ({ layout, source }) => {
    expect(JSON.stringify(layout)).toContain(`${source}?.data?.data ?? []`);
  });
});

/**
 * 같은 결함이 확장 목록 밖에서도 났다(2026-09-16 제보 — 언어팩 63건인데 쪽 이동 불가).
 *
 * 어긋나는 방향이 둘이다.
 *  · 화면이 평면 경로를 읽는 쪽 — 예약 작업 목록·이력 창이 `data.current_page` 를 봤다.
 *  · 서버가 `pagination` 을 안 담는 쪽 — 언어팩 API 가 `meta` 에만 담았다(컨트롤러에서 수정).
 * 둘 다 "쪽 단추가 하나만 남는" 같은 증상이라 여기서 화면 쪽 경로를 함께 잠근다.
 *
 * @scenario envelope_slot=pagination, surface=schedule_list, consumer=pager_buttons, position=first
 * @effects pager_reads_pagination_slot_not_flat_path, total_summary_reads_same_slot_as_pager, fallback_shape_matches_response_shape
 */
describe('관리자 목록 — 페이지네이션 바인딩 경로(확장 목록 외)', () => {
  // 이력 창은 창 데이터소스를 initGlobal(`_global.scheduleHistory` = 응답 data)로 받는다 — 창 데이터소스는
  // id 로 화면에 실리지 않아 `schedule_history?.data…` 로 읽으면 이력이 늘 비어 보였다(7.1.0 J D-J2).
  const cases = [
    { name: '예약 작업 목록', layout: scheduleTab, source: 'schedules', pager: 'schedules?.data?.pagination?.current_page' },
    { name: '예약 작업 이력 창', layout: scheduleHistory, source: 'schedule_history', pager: '_global.scheduleHistory?.pagination?.current_page' },
    { name: '언어팩 목록', layout: languagePackContent, source: 'language_packs', pager: 'language_packs?.data?.pagination?.current_page' },
  ];

  // @scenario envelope_slot=flat, surface=schedule_list, consumer=pager_buttons, position=first
  // @effects pager_reads_pagination_slot_not_flat_path
  it.each(cases)('$name 은 평면 경로를 읽지 않는다', ({ layout, source }) => {
    const json = JSON.stringify(layout);

    WRONG_PATHS.forEach((suffix) => {
      expect(
        json.includes(`${source}${suffix}`),
        `${source}${suffix} 는 응답에 없는 경로입니다 — ${source}?.data?.pagination${suffix.replace('?.data', '')} 를 써야 합니다`,
      ).toBe(false);
    });
  });

  // @scenario envelope_slot=pagination, surface=schedule_history_modal, consumer=pager_buttons, position=first
  // @effects pager_reads_pagination_slot_not_flat_path
  it.each(cases)('$name 은 pagination 하위에서 쪽 번호를 읽는다', ({ layout, pager }) => {
    expect(JSON.stringify(layout)).toContain(pager);
  });

  // @scenario envelope_slot=pagination, surface=language_pack_list, consumer=total_summary_line, position=last
  // @effects total_summary_reads_same_slot_as_pager
  it('총 건수 표시 줄이 쪽 단추와 같은 자리를 읽는다', () => {
    // 한 화면이 두 자리를 나눠 읽으면 한쪽만 고쳐도 다른 쪽이 조용히 남는다 —
    // 언어팩이 딱 그 모양이었다(단추는 pagination, 요약 줄은 meta).
    expect(JSON.stringify(scheduleTab)).toContain('schedules?.data?.pagination?.total');
    expect(JSON.stringify(scheduleTab)).not.toContain('schedules?.data?.total');

    const lp = JSON.stringify(languagePackContent);
    expect(lp).toContain('language_packs?.data?.pagination?.total');
    // from/to 는 서버가 준 값을 쓴다 — 화면에서 산술로 만들면 마지막 쪽에서 실제 건수를 넘는다.
    expect(lp).toContain('language_packs?.data?.pagination?.from');
    expect(lp).toContain('language_packs?.data?.pagination?.to');
  });

  // @scenario envelope_slot=pagination, surface=schedule_list, consumer=fallback_before_fetch, position=first
  // @effects fallback_shape_matches_response_shape
  it('받기 전 기본값이 응답과 같은 모양이다', () => {
    // fallback 이 평면이면 첫 그림에서만 엉뚱한 쪽 수를 읽는다.
    const ds = (scheduleListLayout as { data_sources?: Array<Record<string, unknown>> }).data_sources ?? [];
    const schedules = ds.find((d) => d.id === 'schedules') as
      | { fallback?: { data?: { pagination?: Record<string, unknown> } } }
      | undefined;

    expect(schedules?.fallback?.data?.pagination, 'fallback 이 pagination 자리를 갖고 있지 않다').toBeTruthy();
    expect(schedules?.fallback?.data).not.toHaveProperty('current_page');
  });
});
