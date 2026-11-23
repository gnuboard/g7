/**
 * @file admin-template-layout-version-source.test.tsx
 * @description 레이아웃 편집 화면의 [버전 히스토리]·[확장 버전 히스토리] 창 — 판마다 어디서 왔는지 한 줄 (#888)
 *
 * 확장 업데이트·레이아웃 갱신·「새 판 적용」·업그레이드가 운영자 판을 덮기 직전에 남긴 판과, 병합 결과 판은
 * 서버가 `changes_summary.source`·`before_overwrite` 로 알려 준다. 창은 줄 수 아래에 그 출처를 한 줄로 보여 준다.
 *
 * - 렌더: 실제로 구운 창 JSON 의 판 카드(반복 노드)를 응답 모양 그대로 그려, 출처 × 덮기 전 여부 6가지 문구를 잰다.
 * - 출처가 없는 판(운영자가 저장한 판·옛 판)에는 그 줄이 그려지지 않는다.
 * - 번역 키(`$t:`)나 키 경로가 화면에 날것으로 새지 않는다.
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  screen,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import versionModal from '../../layouts/partials/admin_template_layout_edit/_modal_version_history.json';
import extensionVersionModal from '../../layouts/partials/admin_template_layout_edit/_modal_extension_version_history.json';
import adminKo from '../../lang/partial/ko/admin.json';
import adminEn from '../../lang/partial/en/admin.json';
import { expandSemanticTokens } from './support/semanticClasses';

type Json = Record<string, any>;

/** 트리에서 조건을 만족하는 노드를 모두 모은다. */
function collect(tree: unknown, pred: (n: Json) => boolean, out: Json[] = []): Json[] {
  if (Array.isArray(tree)) {
    tree.forEach((child) => collect(child, pred, out));
  } else if (tree && typeof tree === 'object') {
    const node = tree as Json;
    if (pred(node)) out.push(node);
    Object.values(node).forEach((value) => collect(value, pred, out));
  }
  return out;
}

const TestIcon: React.FC<{ name?: string; className?: string }> = ({ name, className }) => (
  <span className={className} data-icon={name} />
);

/** 기본 HTML 래퍼 + 아이콘만 둔 레지스트리. */
function createRegistry() {
  const registry = createMockComponentRegistryWithBasics();
  registry.register('basic', 'Icon', TestIcon);

  return registry;
}

interface Window {
  /** 창 이름(테스트 제목용) */
  label: string;
  /** 구운 창 JSON */
  modal: Json;
  /** 판 목록 데이터소스 ID */
  dataSource: string;
  /** 출처 줄의 data-testid 접두 */
  testIdPrefix: string;
}

const WINDOWS: Window[] = [
  { label: '버전 히스토리', modal: versionModal, dataSource: 'layout_versions', testIdPrefix: 'layout-version-source-' },
  { label: '확장 버전 히스토리', modal: extensionVersionModal, dataSource: 'extension_versions', testIdPrefix: 'extension-version-source-' },
];

/** 출처 × 덮기 전 여부 → 화면 문구(ko) */
const WORDINGS: Array<{ source: string; beforeOverwrite: boolean; key: string; ko: string }> = [
  { source: 'extension_update', beforeOverwrite: true, key: 'before_extension_update', ko: '확장 업데이트로 덮기 전 운영자 판' },
  { source: 'refresh', beforeOverwrite: true, key: 'before_refresh', ko: '레이아웃 갱신으로 덮기 전 운영자 판' },
  { source: 'decision_take_theirs', beforeOverwrite: true, key: 'before_decision_take_theirs', ko: '「새 판 적용」으로 덮기 전 운영자 판' },
  { source: 'upgrade_step', beforeOverwrite: true, key: 'before_upgrade_step', ko: '업그레이드로 바꾸기 전 운영자 판' },
  { source: 'refresh', beforeOverwrite: false, key: 'merge_refresh', ko: '레이아웃 갱신 병합 결과' },
  { source: 'extension_update', beforeOverwrite: false, key: 'merge_extension_update', ko: '확장 업데이트 병합 결과' },
];

/** 서버 목록 응답의 판 한 건 (`changes_summary` 는 Resource 가 내리는 모양). */
function versionRow(id: number, summary: Partial<{ source: string | null; before_overwrite: boolean; conflict_count: number }>): Json {
  return {
    id,
    version: id,
    created_at: `2026-11-1${id % 10} 10:00`,
    created_by_name: null,
    changes_summary: { added_count: 1, removed_count: 0, char_diff: 3, source: null, before_overwrite: false, conflict_count: 0, ...summary },
  };
}

/** 창 JSON 에서 판 카드(반복 노드)를 꺼내 렌더 가능한 레이아웃으로 감싼다. */
function cardLayout(win: Window): Json {
  const [card] = collect(win.modal, (n) => n.iteration?.item_var === 'version');

  return {
    version: '1.0.0',
    layout_name: `test_${win.dataSource}_source_line`,
    components: [JSON.parse(JSON.stringify(card))],
  };
}

describe('[버전 히스토리] 창 — 판의 출처 한 줄 (#888)', () => {
  let testUtils: ReturnType<typeof createLayoutTest> | undefined;

  afterEach(() => {
    testUtils?.cleanup();
    testUtils = undefined;
  });

  /** 창 하나를 판 목록으로 그린다. */
  async function renderWindow(win: Window, rows: Json[], locale: 'ko' | 'en' = 'ko') {
    testUtils = createLayoutTest(cardLayout(win) as any, {
      componentRegistry: createRegistry() as any,
      translations: { admin: locale === 'ko' ? adminKo : adminEn },
      templateId: 'sirsoft-admin_basic',
      locale,
      initialData: { [win.dataSource]: { data: rows } },
    });

    return testUtils.render();
  }

  describe.each(WINDOWS)('$label 창', (win) => {
    /**
     * @scenario case=wording
     * @effects version_row_shows_sync_source_wording
     */
    it.each(WORDINGS)('$source · 덮기 전=$beforeOverwrite → 「$ko」', async ({ source, beforeOverwrite, ko }) => {
      await renderWindow(win, [versionRow(2, { source, before_overwrite: beforeOverwrite })]);

      const line = screen.getByTestId(`${win.testIdPrefix}0`);
      expect(line.textContent).toBe(ko);
    });

    /**
     * @scenario case=no_source
     * @effects version_row_hides_source_line_without_source
     */
    it('출처가 없는 판(운영자 저장·옛 판)에는 출처 줄을 그리지 않는다', async () => {
      await renderWindow(win, [versionRow(3, {}), versionRow(2, { source: 'refresh', before_overwrite: true }), versionRow(1, { source: null })]);

      // 출처가 있는 둘째 판에만 한 줄이 있다
      expect(screen.queryByTestId(`${win.testIdPrefix}0`)).toBeNull();
      expect(screen.getByTestId(`${win.testIdPrefix}1`).textContent).toBe('레이아웃 갱신으로 덮기 전 운영자 판');
      expect(screen.queryByTestId(`${win.testIdPrefix}2`)).toBeNull();
    });

    /**
     * @scenario case=raw_key
     * @effects version_row_source_line_never_shows_raw_key
     */
    it('번역 키·키 경로가 화면에 날것으로 새지 않는다 (ko·en, 모르는 출처 포함)', async () => {
      const rows = [
        ...WORDINGS.map((w, i) => versionRow(i + 10, { source: w.source, before_overwrite: w.beforeOverwrite })),
        versionRow(30, { source: 'future_source', before_overwrite: true }),
        versionRow(31, { source: 'future_source', before_overwrite: false }),
      ];

      for (const locale of ['ko', 'en'] as const) {
        const { container } = await renderWindow(win, rows, locale);
        const lines = screen.getAllByTestId(new RegExp(`^${win.testIdPrefix}`));

        expect(lines).toHaveLength(rows.length);
        for (const line of lines) {
          expect(line.textContent?.trim()).not.toBe('');
          expect(line.textContent).not.toContain('$t:');
          expect(line.textContent).not.toContain('source_labels');
        }
        expect(container.textContent).not.toContain('$t:');

        testUtils?.cleanup();
        testUtils = undefined;
      }
    });

    /**
     * 덮기 전 운영자 판은 맨 위(최신)에 있어도 지금 본문과 다르다 — 거기서 [복구] 가 없으면 남긴 판을 되돌릴 길이 없다.
     * 일반 판의 맨 위는 지금 본문과 같으므로 종전처럼 [복구] 를 두지 않는다.
     *
     * @scenario case=restore_latest_before_overwrite
     * @effects version_row_latest_before_overwrite_can_restore
     */
    it('맨 위 판이 덮기 전 운영자 판이면 [복구] 를 보이고, 일반 판이면 감춘다', async () => {
      const restoreButtons = () => screen.queryAllByRole('button').filter((b) => b.textContent?.includes('복구'));

      await renderWindow(win, [versionRow(5, { source: 'decision_take_theirs', before_overwrite: true }), versionRow(4, {})]);
      expect(restoreButtons()).toHaveLength(2);

      testUtils?.cleanup();
      testUtils = undefined;

      await renderWindow(win, [versionRow(7, { source: 'refresh', before_overwrite: false }), versionRow(6, {})]);
      expect(restoreButtons()).toHaveLength(1);
    });

    /**
     * 사람 없이 동기화가 남긴 판(출처 있음)은 저장자를 「시스템」으로, 사람이 저장한 판은 그 이름으로 보인다.
     * 출처도 이름도 없는 옛 판에는 저장자 줄이 없다 — 대체 글자(「admin」)를 지어내지 않는다 (#888).
     *
     * @scenario case=saved_by_system
     * @effects version_row_saved_by_system_for_sync_rows
     */
    it('저장자: 동기화 판은 「시스템」, 사람 판은 이름, 옛 판은 줄 없음 (ko·en)', async () => {
      const rows = [
        versionRow(3, { source: 'refresh', before_overwrite: true }),
        { ...versionRow(2, {}), created_by_name: '홍길동' },
        versionRow(1, {}),
      ];

      const { container } = await renderWindow(win, rows);
      const text = container.textContent ?? '';
      expect(text).toContain('저장자: 시스템');
      expect(text).toContain('저장자: 홍길동');
      expect(text).not.toContain('admin');
      expect(text.match(/저장자:/g) ?? []).toHaveLength(2);

      testUtils?.cleanup();
      testUtils = undefined;

      const en = await renderWindow(win, rows, 'en');
      expect(en.container.textContent).toContain('Saved by: System');
      expect(en.container.textContent).not.toContain('$t:');
    });

    /**
     * @scenario case=dark_pair
     * @effects version_row_source_line_pairs_dark_class
     */
    it('출처 줄은 밝은·어두운 글자색을 짝으로 갖는다', () => {
      const [line] = collect(win.modal, (n) => typeof n.props?.['data-testid'] === 'string' && n.props['data-testid'].startsWith(win.testIdPrefix));
      // #399 R-1 — 인라인 짝 대신 의미 클래스(`text-muted`)를 쓰면 그 정의(semantic.css @apply)를 펼쳐 같은 짝을 확인한다
      const classes = expandSemanticTokens(String(line?.props?.className ?? ''));

      expect(classes.some((c) => /^text-(gray|slate)-\d+$/.test(c))).toBe(true);
      expect(classes.some((c) => /^dark:text-(gray|slate)-\d+$/.test(c))).toBe(true);
    });
  });

  /**
   * @scenario case=lang_keys
   * @effects version_row_source_labels_exist_in_ko_and_en
   */
  it('출처 문구 키가 ko·en 에 같은 모양으로 있다', () => {
    const ko = (adminKo as Json).templates.modals.version_history.source_labels ?? {};
    const en = (adminEn as Json).templates.modals.version_history.source_labels ?? {};

    expect(Object.keys(en).sort()).toEqual(Object.keys(ko).sort());
    for (const { key, ko: wording } of WORDINGS) {
      expect(ko[key]).toBe(wording);
      expect(typeof en[key]).toBe('string');
    }
  });
});
