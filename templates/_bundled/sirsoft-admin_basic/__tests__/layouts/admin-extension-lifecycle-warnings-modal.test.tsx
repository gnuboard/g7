/**
 * @file admin-extension-lifecycle-warnings-modal.test.tsx
 * @description 확장 작업 경고 결과 창 — 일부 항목이 실패한 완료를 운영자에게 보인다 (코어 7.1.3)
 *
 * 확장 설치·활성화·업데이트·레이아웃 갱신은 항목 하나가 실패해도 작업을 끝내고 응답 data.warnings 에
 * 실패 목록(단계·대상·사유)을 싣는다. 종전에는 이 실패가 로그에만 남고 화면은 「완료」였다.
 * 창은 건수 요약을 보이고, 목록은 접혀 있다가 「자세히 보기」로 펼친다.
 * 각 목록 화면의 성공 갈래는 경고가 있을 때만 창을 연다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { createLayoutTest, screen, fireEvent, waitFor } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

type Json = Record<string, any>;

const LAYOUTS = resolve(__dirname, '../../layouts');

const TestDiv: React.FC<any> = ({ className, children }) => <div className={className}>{children}</div>;
const TestSpan: React.FC<any> = ({ className, children, text }) => <span className={className}>{children || text}</span>;
const TestP: React.FC<any> = ({ className, children, text }) => <p className={className}>{children || text}</p>;
const TestIcon: React.FC<any> = ({ name }) => <i data-icon={name} />;
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;
const TestButton: React.FC<any> = ({ className, children, onClick, type, 'aria-expanded': expanded }) => (
  <button type={type} className={className} onClick={onClick} aria-expanded={expanded}>{children}</button>
);

function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

const translations = {
  admin: {
    extensions: {
      lifecycle_warnings: {
        title: '일부 항목이 실패했습니다',
        summary: '완료했지만 일부 항목이 실패했습니다 ({{count}}건)',
        description: '작업 자체는 끝났습니다.',
        toggle: '자세히 보기',
        notice_title: '확인할 안내가 있습니다',
        notice_summary: '완료했습니다 — 확인할 안내 {{count}}건',
        notice_description: '작업은 모두 반영됐습니다.',
      },
    },
  },
  common: { close: '닫기' },
};

const WARNINGS = [
  { step: 'layout', target: 'sirsoft-board:board_list', message: '레이아웃 JSON 형식 오류', level: 'warning' },
  { step: 'seeder', target: 'sirsoft-board:BoardSeeder', message: '저장소 바인딩 없음', level: 'warning' },
];

/** 실패가 아닌 안내만 있는 결과(압축 경로 정규화) */
const NOTICES = [
  { step: 'extract', target: 'my-module.zip', message: '역슬래시(\) 경로 8개를 / 로 정규화해 풀었습니다', level: 'notice' },
];

/** 경고 창 본문(Modal 의 첫 자식)을 레이아웃으로 감싼다 — Modal 껍데기는 템플릿 컴포넌트라 본문만 그린다. */
function buildLayout(items: Json[] = WARNINGS): Json {
  const modal = JSON.parse(readFileSync(join(LAYOUTS, 'partials/_modal_lifecycle_warnings.json'), 'utf-8'));
  return {
    version: '1.0.0',
    layout_name: 'lifecycle_warnings_modal_test',
    initGlobal: { lifecycleWarnings: items, lifecycleWarningsOpen: false },
    components: [modal.children[0]],
  };
}

/** 디렉터리 아래 컴파일된 레이아웃 JSON 경로를 모두 모은다. */
function layoutJsonFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const full = join(dir, name);
    if (statSync(full).isDirectory()) return layoutJsonFiles(full);
    return name.endsWith('.json') ? [full] : [];
  });
}

/** 액션 목록 배열을 트리에서 모두 모은다(onSuccess·actions·then 등 어느 자리든). */
function actionLists(tree: unknown): Json[][] {
  const out: Json[][] = [];
  const walk = (n: unknown): void => {
    if (Array.isArray(n)) {
      if (n.some((a) => a && typeof a === 'object' && 'handler' in a)) out.push(n as Json[]);
      n.forEach(walk);
      return;
    }
    if (n && typeof n === 'object') Object.values(n).forEach(walk);
  };
  walk(tree);
  return out;
}

const setsWarnings = (a: Json): boolean => a?.handler === 'setState' && a.params?.target === 'global' && 'lifecycleWarnings' in (a.params ?? {});

const branchFiles = layoutJsonFiles(LAYOUTS).filter((f) => readFileSync(f, 'utf-8').includes('"lifecycleWarnings"'));

/** 확장 생명주기 API — 설치·활성화·비활성화·제거·업데이트·레이아웃 갱신(경로 마디 끝 기준 — check-updates·uninstall-info 는 아님) */
const LIFECYCLE_TARGET = /\/api\/admin\/(modules|plugins|templates|language-packs)\b.*\/(install[a-z-]*|activate|deactivate|uninstall|update|refresh-layouts|bulk-activate)(?=$|[/?'"\s])/;
/** 언어팩 제거는 `DELETE /api/admin/language-packs/{id}` 라 경로에 동사가 없다 */
const LANGUAGE_PACK_DELETE = /\/api\/admin\/language-packs\/\{\{[^}]+\}\}$/;
/** 대시보드 「복구」는 주소를 알림이 실어 온다(`alert.recover_endpoint` — 서버가 자동 비활성 확장 재활성화 주소를 넣는다) */
const ALERT_RECOVER_TARGET = /\brecover_endpoint\b/;

/** 레이아웃 트리에서 확장 생명주기 apiCall 을 모두 모은다 — 저장소에서 파생한 모집단 */
function lifecycleApiCalls(): Array<{ rel: string; call: Json }> {
  const out: Array<{ rel: string; call: Json }> = [];
  for (const file of layoutJsonFiles(LAYOUTS)) {
    const rel = file.slice(LAYOUTS.length + 1);
    const walk = (n: unknown): void => {
      if (Array.isArray(n)) return n.forEach(walk);
      if (!n || typeof n !== 'object') return;
      const node = n as Json;
      if (node.handler === 'apiCall' && typeof node.target === 'string') {
        const method = String(node.params?.method ?? 'GET').toUpperCase();
        if (LIFECYCLE_TARGET.test(node.target) || ALERT_RECOVER_TARGET.test(node.target) || (method === 'DELETE' && LANGUAGE_PACK_DELETE.test(node.target))) out.push({ rel, call: node });
      }
      Object.values(node).forEach(walk);
    };
    walk(JSON.parse(readFileSync(file, 'utf-8')));
  }
  return out;
}

const lifecycleCalls = lifecycleApiCalls();

describe('확장 작업 경고 결과 창 — 렌더', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;

  beforeEach(() => {
    setupRegistry();
  });

  afterEach(() => {
    if (testUtils) testUtils.cleanup();
  });

  /**
   * @scenario case=summary_with_count_and_collapsed_list
   * @effects lifecycle_warnings_modal_shows_failed_items
   */
  it('건수 요약을 보이고 목록은 접혀 있다', async () => {
    testUtils = createLayoutTest(buildLayout(), { translations, locale: 'ko', componentRegistry: ComponentRegistry.getInstance() });
    await testUtils.render();

    expect(screen.getByText('완료했지만 일부 항목이 실패했습니다 (2건)')).toBeTruthy();
    expect(screen.queryByText('완료했습니다 — 확인할 안내 2건')).toBeNull();
    expect(screen.queryByText('sirsoft-board:board_list')).toBeNull();
  });

  /**
   * @scenario case=notice_only_uses_notice_wording
   * @effects lifecycle_warnings_modal_separates_notices_from_failures
   */
  it('실패 없이 안내만 있으면 「실패」 문구 대신 안내 문구를 쓴다', async () => {
    testUtils = createLayoutTest(buildLayout(NOTICES), { translations, locale: 'ko', componentRegistry: ComponentRegistry.getInstance() });
    await testUtils.render();

    expect(screen.getByText('완료했습니다 — 확인할 안내 1건')).toBeTruthy();
    expect(screen.queryByText(/일부 항목이 실패했습니다/)).toBeNull();
  });

  /**
   * @scenario case=toggle_expands_warning_rows
   * @effects lifecycle_warnings_modal_shows_failed_items
   */
  it('「자세히 보기」를 누르면 단계·대상·사유 행이 보인다', async () => {
    testUtils = createLayoutTest(buildLayout(), { translations, locale: 'ko', componentRegistry: ComponentRegistry.getInstance() });
    await testUtils.render();

    fireEvent.click(screen.getByText('자세히 보기'));
    await waitFor(() => expect(testUtils.getState()._global.lifecycleWarningsOpen).toBe(true));
    // 시험 도구는 전역 상태 쓰기 뒤 자동으로 다시 그리지 않는다 — 실제 앱의 재렌더를 대신한다
    await testUtils.rerender();

    expect(screen.getByText('sirsoft-board:board_list')).toBeTruthy();
    expect(screen.getByText('레이아웃 JSON 형식 오류')).toBeTruthy();
    expect(screen.getByText('sirsoft-board:BoardSeeder')).toBeTruthy();
    expect(screen.getByText('seeder')).toBeTruthy();
  });
});

describe('확장 작업 경고 결과 창 — 여는 쪽', () => {
  /**
   * @scenario case=success_branches_open_only_with_warnings
   * @effects success_branch_opens_warnings_modal_only_when_warnings_exist
   */
  it.each(branchFiles.map((f) => [f.slice(LAYOUTS.length + 1)]))('%s: 경고를 담은 뒤 경고가 있을 때만 창을 연다', (rel) => {
    const tree = JSON.parse(readFileSync(join(LAYOUTS, rel), 'utf-8'));
    let found = 0;
    for (const actions of actionLists(tree)) {
      const at = actions.findIndex(setsWarnings);
      if (at === -1) continue;
      found++;
      const open = actions.slice(at + 1).find((a) => a?.handler === 'openModal' && (a.target ?? a.params?.id) === 'lifecycle_warnings_modal');
      expect(open, `${rel}: 경고를 담은 뒤 lifecycle_warnings_modal 을 열어야 합니다.`).toBeDefined();
      expect(String(open?.if ?? ''), `${rel}: 경고가 없을 때는 열지 않아야 합니다.`).toContain('warnings');
      expect(actions[at].params.lifecycleWarningsOpen, `${rel}: 열 때 목록을 접어야 합니다.`).toBe(false);
    }
    expect(found, `${rel}: 경고를 담는 성공 갈래가 없습니다.`).toBeGreaterThan(0);
  });

  /**
   * @scenario case=population_not_empty
   * @effects success_branch_opens_warnings_modal_only_when_warnings_exist
   */
  it('검사 대상 성공 갈래가 있다(모집단 0 방지)', () => {
    expect(branchFiles.length).toBeGreaterThanOrEqual(26);
  });

  /**
   * 비활성화·제거도 정리 단계 실패를 data.warnings 로 싣는다 — 경고를 담는 파일만 보면 연결이 빠진 화면을 놓친다.
   *
   * @scenario case=every_lifecycle_call_opens_warnings
   * @effects every_lifecycle_api_success_opens_warnings_modal
   */
  it.each(lifecycleCalls.map(({ rel, call }) => [`${rel} ${call.target}`, call]))('%s: 성공 갈래가 경고 창을 연다', (_label, call) => {
    const success = JSON.stringify((call as Json).onSuccess ?? []);
    expect(success, '경고 목록을 _global.lifecycleWarnings 에 담아야 합니다.').toContain('"lifecycleWarnings"');
    expect(success, '경고가 있을 때 lifecycle_warnings_modal 을 열어야 합니다.').toContain('lifecycle_warnings_modal');
  });

  /**
   * @scenario case=lifecycle_call_population_not_empty
   * @effects every_lifecycle_api_success_opens_warnings_modal
   */
  it('생명주기 apiCall 모집단에 비활성화·제거가 들어 있다(모집단 0 방지)', () => {
    const targets = lifecycleCalls.map(({ call }) => String(call.target));
    expect(targets.some((t) => t.includes('/uninstall'))).toBe(true);
    expect(targets.some((t) => t.endsWith('/deactivate'))).toBe(true);
    expect(targets.some((t) => LANGUAGE_PACK_DELETE.test(t))).toBe(true);
    expect(targets.some((t) => ALERT_RECOVER_TARGET.test(t)), '대시보드 복구 버튼(재활성화)도 모집단에 들어야 합니다.').toBe(true);
    expect(targets.some((t) => t.includes('check-updates'))).toBe(false);
  });
});
