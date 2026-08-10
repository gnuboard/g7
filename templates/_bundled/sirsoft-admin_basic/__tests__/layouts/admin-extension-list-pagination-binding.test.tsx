import { describe, it, expect } from 'vitest';
import moduleList from '../../layouts/admin_module_list.json';
import pluginList from '../../layouts/admin_plugin_list.json';

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

  it.each(cases)('$name 목록은 목록 배열 경로는 그대로 유지한다', ({ layout, source }) => {
    expect(JSON.stringify(layout)).toContain(`${source}?.data?.data ?? []`);
  });
});
