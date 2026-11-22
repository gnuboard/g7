/**
 * @file admin-settings-upload-thumbnail.test.tsx
 * @description 환경설정 업로드 탭 「썸네일」·「썸네일 규격」 카드와 드라이버 탭 「이미지 처리」 카드 (#869)
 *
 * 화면이 지켜야 할 약속을 고정한다.
 *  - 저장 위치 select 가 저장값과 다르면 그 줄은 사용량 대신 「저장 후 집계」를 보이고 규격 select·비우기를 잠근다
 *    (저장 전 디스크를 비우는 사고 방지). 원격 디스크는 「집계 안 함」.
 *  - og 행은 크기를 SEO 탭이 소유하므로 값 칸이 모두 잠기고 「SEO 탭에서 설정」을 단다. 편집 가능한 행과 잠긴 행이 시각으로 갈린다.
 *  - 저장 본문은 화면용 행(`thumbnail_presets_rows`)을 저장 키 `thumbnail_presets` 로 바꾸고 og 행·표시용 칸을 뺀다.
 *  - 서버 422 의 `upload.thumbnail_presets.{번호}` 는 og 행을 뺀 저장 본문 기준 번호라, 화면 행 번호로 옮겨 그 칸 아래에 단다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { screen, within } from '@testing-library/react';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import adminSettings from '../../layouts/admin_settings.json';
import uploadPartial from '../../layouts/partials/admin_settings/_tab_upload.json';
import driversPartial from '../../layouts/partials/admin_settings/_tab_drivers.json';
import purgeAllModal from '../../layouts/partials/admin_settings/_modal_thumbnail_purge_all.json';
import commonKo from '../../lang/partial/ko/common.json';
import pregenModal from '../../layouts/partials/admin_settings/_modal_thumbnail_pregenerate.json';
import adminKo from '../../lang/partial/ko/admin.json';
import adminEn from '../../lang/partial/en/admin.json';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expandSemanticClassName } from './support/semanticClasses';

/** 번들 ja 언어팩은 템플릿 밖에 있어 파일로 읽는다 */
const adminJa = JSON.parse(
  fs.readFileSync(
    path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../../../lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/partial/admin.json'),
    'utf-8',
  ),
);

type Json = Record<string, any>;

/**
 * 태그 이름으로 testid·class·텍스트·disabled 를 그대로 옮기는 테스트 컴포넌트를 만듭니다.
 *
 * @param Tag 렌더할 HTML 태그
 * @returns 테스트 컴포넌트
 */
function passthrough(Tag: keyof JSX.IntrinsicElements): React.FC<any> {
  return ({ className, children, text, title, disabled, 'data-testid': testId }) =>
    React.createElement(Tag, { className, title, disabled, 'data-testid': testId }, children ?? text);
}

const TestIcon: React.FC<any> = ({ name }) => <i data-icon={name} />;
const TestBadge: React.FC<any> = ({ text }) => <span data-badge="">{text}</span>;
const TestInput: React.FC<any> = ({ className, disabled, value, placeholder, min, max, 'aria-label': ariaLabel, 'data-testid': testId }) => (
  <input className={className} disabled={disabled} value={value ?? ''} placeholder={placeholder} min={min} max={max} aria-label={ariaLabel} readOnly data-testid={testId} />
);
const TestSelect: React.FC<any> = ({ className, disabled, options, 'data-testid': testId }) => (
  <select className={className} disabled={disabled} data-testid={testId}>
    {(options ?? []).map((o: any) => (
      <option key={String(o.value)} value={o.value}>
        {o.label}
      </option>
    ))}
  </select>
);
const TestToggle: React.FC<any> = ({ disabled, checked }) => (
  <span data-toggle="" data-disabled={disabled ? 'true' : 'false'} data-checked={checked ? 'true' : 'false'} />
);

/**
 * 테스트용 컴포넌트 레지스트리를 구성합니다.
 *
 * @returns 구성된 레지스트리
 */
function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const basic = (name: string, component: React.FC<any>) => ({ component, metadata: { name, type: 'basic' } });

  (registry as any).registry = {
    Div: basic('Div', passthrough('div')),
    P: basic('P', passthrough('p')),
    Span: basic('Span', passthrough('span')),
    H3: basic('H3', passthrough('h3')),
    H4: basic('H4', passthrough('h4')),
    Label: basic('Label', passthrough('label')),
    Button: basic('Button', passthrough('button')),
    A: basic('A', passthrough('a')),
    Table: basic('Table', passthrough('table')),
    Thead: basic('Thead', passthrough('thead')),
    Tbody: basic('Tbody', passthrough('tbody')),
    Tr: basic('Tr', passthrough('tr')),
    Th: basic('Th', passthrough('th')),
    Td: basic('Td', passthrough('td')),
    Icon: basic('Icon', TestIcon),
    Input: basic('Input', TestInput),
    Select: basic('Select', TestSelect),
    Badge: { component: TestBadge, metadata: { name: 'Badge', type: 'composite' } },
    Toggle: { component: TestToggle, metadata: { name: 'Toggle', type: 'composite' } },
    Fragment: { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

/**
 * 레이아웃 트리에서 id 로 노드를 찾습니다.
 *
 * @param node 탐색 시작 노드
 * @param id 찾을 노드 id
 * @returns 찾은 노드 또는 null
 */
function findNodeById(node: any, id: string): any {
  if (!node || typeof node !== 'object') return null;
  if (node.id === id) return node;
  for (const child of node.children ?? []) {
    const found = findNodeById(child, id);
    if (found) return found;
  }
  return null;
}

/**
 * 조각 JSON 에서 id 노드를 꺼냅니다.
 *
 * @param partial 조각 JSON
 * @param id 노드 id
 * @returns 노드
 */
function nodeOf(partial: Json, id: string): Json {
  for (const root of partial.components ?? [partial]) {
    const found = findNodeById(root, id);
    if (found) return found;
  }
  throw new Error(`${id} 노드가 없다`);
}

/** 기본 규격 행 — 서버 presentRows() 모양(고정 5행 + og) 에 운영자 행 1개를 더한다 */
const ROWS = [
  { name: 'xs', source: 'core', width: 64, height: 64, fit: 'cover', anchor: 'center', quality: null, format: 'original', upscale: false, pregenerate: false, numeric: false, enabled: true, locked: true, kind: 'fixed' },
  { name: 'sm', source: 'core', width: 160, height: 160, fit: 'cover', anchor: 'center', quality: null, format: 'original', upscale: false, pregenerate: false, numeric: false, enabled: true, locked: true, kind: 'fixed' },
  { name: 'md', source: 'core', width: 640, height: null, fit: 'width', anchor: 'center', quality: null, format: 'original', upscale: false, pregenerate: false, numeric: true, enabled: true, locked: true, kind: 'fixed' },
  { name: 'lg', source: 'core', width: 1280, height: null, fit: 'width', anchor: 'center', quality: null, format: 'original', upscale: false, pregenerate: false, numeric: true, enabled: true, locked: true, kind: 'fixed' },
  { name: 'xl', source: 'core', width: 1920, height: null, fit: 'width', anchor: 'center', quality: null, format: 'original', upscale: false, pregenerate: false, numeric: true, enabled: true, locked: true, kind: 'fixed' },
  { name: 'og', source: 'core', width: 1200, height: 630, fit: 'cover', anchor: 'center', quality: null, format: 'original', upscale: false, pregenerate: false, numeric: false, enabled: true, locked: true, kind: 'og', size_readonly: true },
  { name: 'banner_wide', source: 'custom', width: 1200, height: 400, fit: 'cover', anchor: 'center', quality: null, format: 'original', upscale: false, pregenerate: false, numeric: false, enabled: true, locked: false, kind: 'custom' },
];

const STATS = {
  locations: [
    { kind: 'public', disk: 'public', disk_label: 'public', remote: false, count: 3, bytes: 2048, by_preset: { sm: 3 } },
    { kind: 'gated', disk: 'thumbnails', disk_label: 'thumbnails', remote: false, count: 5, bytes: 3 * 1048576, by_preset: { sm: 5 } },
  ],
  presets: ['xs', 'sm', 'md', 'lg', 'xl', 'og', 'banner_wide'],
  sources: [{ key: 'core_attachment', label: '첨부파일 이미지' }],
};

/**
 * 본 레이아웃 계산값을 주어진 문맥으로 평가합니다.
 *
 * @param context 평가 문맥(_local·_global·데이터 소스)
 * @returns `_computed` 로 실을 값
 */
function computedFor(context: Json): Json {
  const engine = new DataBindingEngine();
  const computed = (adminSettings as Json).computed as Record<string, string>;

  return Object.fromEntries(
    Object.entries(computed).map(([key, expression]) => [
      key,
      engine.evaluateExpression(expression.replace(/^\{\{|\}\}$/g, ''), context as any),
    ])
  );
}

/**
 * 업로드 탭의 썸네일 두 카드를 렌더합니다.
 *
 * @param opts 폼 업로드 값·저장된 업로드 값·오류·사용량 응답·메타·추가 전역 상태
 * @returns 레이아웃 테스트 유틸
 */
async function renderCards(
  opts: { upload?: Json; savedUpload?: Json; drivers?: Json; errors?: Json; stats?: Json; meta?: Json; readOnly?: boolean; global?: Json; locale?: 'ko' | 'en' } = {}
) {
  const savedUpload = opts.savedUpload ?? { thumbnail_disk_public: 'public', thumbnail_disk_gated: 'thumbnails' };
  const local = {
    form: {
      upload: { image_quality: 85, thumbnail_disk_public: 'public', thumbnail_disk_gated: 'thumbnails', thumbnail_presets_rows: ROWS, ...opts.upload },
      drivers: { public_asset_disk: 'none', ...opts.drivers },
      _meta: {
        thumbnail_engine_available: true,
        thumbnail_disk_options: {
          public: [{ id: 'thumbnails', label: 'thumbnails' }, { id: 'public', label: 'public' }],
          gated: [{ id: 'thumbnails', label: 'thumbnails' }, { id: 's3', label: 's3' }],
        },
        ...opts.meta,
      },
    },
    errors: opts.errors ?? null,
    thumbnailPurgePreset: {},
    thumbnailPurging: null,
  };
  const global = { settingsAbilities: { can_update: !opts.readOnly }, ...opts.global };
  const data = { settings: { data: { upload: savedUpload } }, thumbnailStats: { data: opts.stats ?? STATS } };
  const testUtils = createLayoutTest(
    {
      version: '1.0.0',
      layout_name: 'test_upload_thumbnail',
      computed: (adminSettings as Json).computed,
      components: [nodeOf(uploadPartial as Json, 'card_thumbnail_settings'), nodeOf(uploadPartial as Json, 'card_thumbnail_presets')],
    } as any,
    {
      componentRegistry: setupRegistry(),
      translations: { admin: adminKo, common: commonKo },
      locale: opts.locale,
      initialState: { _local: local, _global: global },
      initialData: { ...data, _computed: computedFor({ _local: local, _global: global, ...data }) },
    }
  );
  await testUtils.render();

  return testUtils;
}

/**
 * 점 경로로 객체 값을 꺼냅니다.
 *
 * @param source 원본 객체
 * @param dotted 점 경로
 * @returns 값
 */
function pick(source: Json, dotted: string): unknown {
  return dotted.split('.').reduce<any>((acc, key) => (acc == null ? undefined : acc[key]), source);
}

/**
 * 레이아웃 문자열에서 정적 `$t:` 키와 동적 `$t('…' + …)` 접두를 모읍니다.
 *
 * @param json 레이아웃 JSON
 * @returns 정적 키 목록
 */
function staticKeys(json: unknown): string[] {
  const text = JSON.stringify(json);
  const keys = new Set<string>();
  for (const m of text.matchAll(/\$t:([a-z_][a-z0-9_.]*[a-z0-9_])/gi)) keys.add(m[1]);
  for (const m of text.matchAll(/\$t\('([a-z_][a-z0-9_.]*[a-z0-9_])'\)/gi)) keys.add(m[1]);

  return [...keys].filter((k) => k.startsWith('admin.settings.upload.thumbnail') || k.startsWith('admin.settings.drivers.image') || k.startsWith('admin.modal_label.admin_settings.settings_thumbnail'));
}

describe('업로드 탭 — 썸네일 카드 (#869)', () => {
  let testUtils: Awaited<ReturnType<typeof renderCards>> | undefined;

  beforeEach(() => {
    (window as any).__templateApp = { getConfig: () => ({ templateId: 'test-template', locale: 'ko' }) };
  });

  afterEach(() => {
    testUtils?.cleanup();
    testUtils = undefined;
    delete (window as any).__templateApp;
  });

  // @scenario surface=storage_rows
  // @effects storage_rows_show_usage_and_unlocked_purge
  it('저장 위치 두 줄이 사용량을 그리고, 저장값과 같으면 비우기가 풀려 있다', async () => {
    testUtils = await renderCards();

    expect(screen.getByTestId('thumbnail-location-public')).toBeInTheDocument();
    expect(screen.getByTestId('thumbnail-location-gated')).toBeInTheDocument();
    expect(screen.getByTestId('thumbnail-usage-public')).toHaveTextContent('3');
    expect(screen.getByTestId('thumbnail-usage-public')).toHaveTextContent('2.0 KB');
    expect(screen.getByTestId('thumbnail-usage-gated')).toHaveTextContent('3.0 MB');
    expect(screen.getByTestId('thumbnail-purge-public')).not.toBeDisabled();
    expect(screen.getByTestId('thumbnail-purge-gated')).not.toBeDisabled();
    expect(screen.queryByTestId('thumbnail-storage-locked-hint')).not.toBeInTheDocument();
    // 공개 위치가 비공개 thumbnails 가 아니므로 CDN 퍼지 안내가 보인다
    expect(screen.getByTestId('thumbnail-cdn-purge-notice')).toBeInTheDocument();
  });

  // @scenario surface=storage_rows
  // @effects unsaved_disk_locks_row_purge
  it('권한 줄 디스크를 저장 전에 바꾸면 그 줄만 「저장 후 집계」로 바뀌고 규격 select·비우기가 잠긴다', async () => {
    testUtils = await renderCards({ upload: { thumbnail_disk_gated: 's3' } });

    const gated = screen.getByTestId('thumbnail-usage-gated');
    expect(gated).toHaveTextContent(adminKo.settings.upload.thumbnail.storage.after_save);
    expect(gated).not.toHaveTextContent('MB');
    expect(screen.getByTestId('thumbnail-purge-gated')).toBeDisabled();
    expect(screen.getByTestId('thumbnail-purge-preset-gated')).toBeDisabled();
    // 다른 줄은 그대로 풀려 있다 — 시각 대조는 .btn 의 disabled:opacity-50 이 맡는다
    expect(screen.getByTestId('thumbnail-purge-public')).not.toBeDisabled();
    expect(screen.getByTestId('thumbnail-purge-preset-public')).not.toBeDisabled();
    expect(screen.getByTestId('thumbnail-purge-gated').className).toContain('btn');
    // 두 곳 모두 비우기·기존 이미지 만들기도 잠긴다(한 줄이라도 저장 전 디스크면)
    expect(screen.getByTestId('thumbnail-purge-all')).toBeDisabled();
    expect(screen.getByTestId('thumbnail-pregenerate-open')).toBeDisabled();
    expect(screen.getByTestId('thumbnail-storage-locked-hint')).toBeInTheDocument();
  });

  // @scenario surface=storage_rows
  // @effects gated_same_bucket_warning_shown
  it('권한 줄과 공개 자산 디스크가 둘 다 s3 일 때만 권한 줄 아래 같은 버킷 경고가 보인다', async () => {
    const text = adminKo.settings.upload.thumbnail.storage.same_bucket_warning;
    expect(text).toBeTruthy();

    testUtils = await renderCards({ upload: { thumbnail_disk_gated: 's3' }, drivers: { public_asset_disk: 's3' } });
    const warning = screen.getByTestId('thumbnail-same-bucket-warning-gated');
    expect(warning).toHaveTextContent(text);
    // 권한 줄 안에만 있다 — 공개 줄에는 그리지 않는다
    expect(screen.getByTestId('thumbnail-location-gated')).toContainElement(warning);
    expect(screen.getAllByTestId('thumbnail-same-bucket-warning-gated')).toHaveLength(1);
    testUtils.cleanup();

    // 한쪽만 s3 이면 없다
    testUtils = await renderCards({ upload: { thumbnail_disk_gated: 's3' }, drivers: { public_asset_disk: 'public' } });
    expect(screen.queryByTestId('thumbnail-same-bucket-warning-gated')).not.toBeInTheDocument();
    testUtils.cleanup();

    testUtils = await renderCards({ upload: { thumbnail_disk_gated: 'thumbnails' }, drivers: { public_asset_disk: 's3' } });
    expect(screen.queryByTestId('thumbnail-same-bucket-warning-gated')).not.toBeInTheDocument();
  });

  // @scenario surface=storage_rows
  // @effects remote_disk_not_counted_and_engine_missing_notice
  it('원격 디스크는 「집계 안 함」, 엔진이 없으면 안내와 함께 만들기가 잠긴다', async () => {
    testUtils = await renderCards({
      stats: { ...STATS, locations: [{ ...STATS.locations[0], remote: true, count: null, bytes: null }, STATS.locations[1]] },
      meta: { thumbnail_engine_available: false },
    });

    expect(screen.getByTestId('thumbnail-usage-public')).toHaveTextContent(adminKo.settings.upload.thumbnail.storage.not_counted);
    expect(screen.getByTestId('thumbnail-engine-missing')).toBeInTheDocument();
    expect(screen.getByTestId('thumbnail-pregenerate-open')).toBeDisabled();
  });

  // @scenario surface=storage_rows
  // @effects storage_rows_zero_usage_shown
  it('썸네일이 하나도 없으면 두 줄이 그대로 남아 「0개 · 0 B」를 보이고, 비우기·만들기는 그대로 쓸 수 있다', async () => {
    testUtils = await renderCards({
      stats: {
        ...STATS,
        presets: [],
        locations: STATS.locations.map((l) => ({ ...l, count: 0, bytes: 0, by_preset: {} })),
      },
    });

    for (const kind of ['public', 'gated']) {
      expect(screen.getByTestId(`thumbnail-location-${kind}`)).toBeInTheDocument();
      const usage = screen.getByTestId(`thumbnail-usage-${kind}`);
      expect(usage).toHaveTextContent('0');
      expect(usage).toHaveTextContent('0 B');
      expect(usage).not.toHaveTextContent(adminKo.settings.upload.thumbnail.storage.not_counted);
      expect(screen.getByTestId(`thumbnail-purge-${kind}`)).not.toBeDisabled();
    }
    // 규격별 비우기 선택지는 「전체 규격」 하나만 남는다
    const presetSelect = screen.getByTestId('thumbnail-purge-preset-public') as HTMLSelectElement;
    expect(presetSelect.options).toHaveLength(1);
    expect(screen.getByTestId('thumbnail-pregenerate-open')).not.toBeDisabled();
  });

  // @scenario surface=presets_table
  // @effects og_row_value_cells_locked
  it('규격 표 — og 행은 값 칸이 모두 잠기고 「SEO 탭에서 설정」을, 운영자 행은 이름 입력·삭제를 갖는다', async () => {
    testUtils = await renderCards();

    const og = screen.getByTestId('thumbnail-preset-row-og');
    const ogWidth = screen.getByTestId('thumbnail-preset-width-5');
    expect(ogWidth).toBeDisabled();
    expect(ogWidth.className).toContain('disabled:opacity-50');
    expect(screen.getByTestId('thumbnail-preset-height-5')).toBeDisabled();
    expect(within(og).getAllByRole('combobox', { hidden: true }).every((el) => (el as HTMLSelectElement).disabled)).toBe(true);
    expect(within(og).getByTestId('thumbnail-preset-og-notice')).toHaveTextContent(adminKo.settings.upload.thumbnail.presets.og_size_notice);
    expect(within(og).queryByTestId('thumbnail-preset-name-5')).not.toBeInTheDocument();

    // 고정 행은 편집 가능(값만) — og 와 대조
    expect(screen.getByTestId('thumbnail-preset-width-1')).not.toBeDisabled();
    expect(screen.queryByTestId('thumbnail-preset-name-1')).not.toBeInTheDocument();

    // 운영자 행
    expect(screen.getByTestId('thumbnail-preset-name-6')).not.toBeDisabled();
    expect(screen.getByTestId('thumbnail-preset-remove-6')).toBeInTheDocument();
    expect(screen.queryByTestId('thumbnail-preset-remove-1')).not.toBeInTheDocument();
    expect(screen.getAllByTestId(/^thumbnail-preset-row-/)).toHaveLength(7);
  });

  // @scenario surface=presets_table
  // @effects preset_row_aria_fallback_translated
  it('이름이 빈 새 규격 행의 칸 이름은 번역된 행 번호를 쓰고, 표 어디에도 고정 기호 대체값이 없다', async () => {
    const rows = [...ROWS, { ...ROWS[6], name: '' }];
    testUtils = await renderCards({ upload: { thumbnail_presets_rows: rows } });

    const width = adminKo.settings.upload.thumbnail.presets.col.width;
    expect(screen.getByTestId('thumbnail-preset-width-7')).toHaveAttribute('aria-label', `8번째 행 ${width}`);
    // 이름이 있는 행은 대체값을 쓰지 않는다
    expect(screen.getByTestId('thumbnail-preset-width-6')).toHaveAttribute('aria-label', `banner_wide ${width}`);
    expect(JSON.stringify(uploadPartial)).not.toContain("'#' + (rowIdx + 1)");
  });

  // @scenario surface=presets_table
  // @effects preset_errors_mapped_to_display_rows
  it('서버 422 키(og 를 뺀 저장 번호)를 화면 행 번호로 옮겨 그 칸 아래에 단다', async () => {
    // 저장 본문 번호 5 = og 다음 행(화면 6번 banner_wide)
    testUtils = await renderCards({
      errors: { 'upload.thumbnail_presets.5.name': ['이름 중복'], 'upload.thumbnail_presets.1.width': ['가로 범위'], 'upload.thumbnail_presets': ['개수 초과'] },
    });

    const custom = screen.getByTestId('thumbnail-preset-row-banner_wide');
    expect(custom).toHaveTextContent('이름 중복');
    expect(screen.getByTestId('thumbnail-preset-row-sm')).toHaveTextContent('가로 범위');
    expect(screen.getByTestId('thumbnail-preset-row-og')).not.toHaveTextContent('이름 중복');
    expect(screen.getByTestId('thumbnail-presets-error')).toHaveTextContent('개수 초과');
  });

  // @scenario surface=presets_table
  // @effects content_preset_options_are_enabled_presets
  it('본문 이미지 규격 선택지는 사용 중인 규격(og 제외)이다', async () => {
    testUtils = await renderCards({ upload: { thumbnail_presets_rows: ROWS.map((r) => (r.name === 'xl' ? { ...r, enabled: false } : r)) } });

    const field = screen.getByText(adminKo.settings.upload.thumbnail.content_preset).closest('div') as HTMLElement;
    const values = Array.from(field.querySelectorAll('option')).map((o) => o.getAttribute('value'));
    expect(values).toEqual(['xs', 'sm', 'md', 'lg', 'banner_wide']);
  });

  /** 확장 행 둘 — 이름(라벨)을 등록한 행과 등록하지 않은 행. 서버는 라벨을 로케일 맵(없으면 null)으로 싣는다 */
  const HERO_ROW = { ...ROWS[6], name: 'sirsoft-hero_section.list', source: 'sirsoft-hero_section', kind: 'extension', locked: true, width: 240, height: null, fit: 'width', label: { ko: '배너 목록', en: 'Banner list' } };
  const BARE_ROW = { ...ROWS[6], name: 'gnuboard7-hello_module.sample', source: 'gnuboard7-hello_module', kind: 'extension', locked: true, width: 480, height: 270, label: null };
  const LABELED_ROWS = [...ROWS, HERO_ROW, BARE_ROW];
  const LABELED_STATS = { ...STATS, presets: [...STATS.presets, HERO_ROW.name, BARE_ROW.name] };

  /**
   * select 의 선택지 문구를 값 → 문구로 모읍니다.
   *
   * @param select select 요소
   * @returns 값 → 문구
   */
  const optionTexts = (select: Element): Record<string, string> =>
    Object.fromEntries(Array.from(select.querySelectorAll('option')).map((o) => [o.getAttribute('value') ?? '', o.textContent ?? '']));

  // @scenario surface=presets_table
  // @effects extension_row_shows_localized_label
  it('이름을 등록한 확장 행은 그 이름을 보이고 식별자를 아래에 작게 둔다 — 이름이 없으면 식별자만 보인다', async () => {
    testUtils = await renderCards({ upload: { thumbnail_presets_rows: LABELED_ROWS }, stats: LABELED_STATS });

    const hero = screen.getByTestId('thumbnail-preset-row-sirsoft-hero_section.list');
    expect(within(hero).getByTestId('thumbnail-preset-label-7')).toHaveTextContent('배너 목록');
    expect(within(hero).getByTestId('thumbnail-preset-ident-7')).toHaveTextContent('sirsoft-hero_section.list');
    // 식별자 줄은 보조 표기라 작고 흐린 고정폭 글꼴이다(다크모드 짝 포함)
    const identClass = within(hero).getByTestId('thumbnail-preset-ident-7').className;
    expect(identClass.split(/\s+/)).toContain('text-muted');
    expect(expandSemanticClassName(identClass)).toContain('dark:text-gray-400');

    const bare = screen.getByTestId('thumbnail-preset-row-gnuboard7-hello_module.sample');
    expect(within(bare).queryByTestId('thumbnail-preset-label-8')).not.toBeInTheDocument();
    expect(within(bare).getByTestId('thumbnail-preset-ident-8')).toHaveTextContent('gnuboard7-hello_module.sample');

    // 고정·og 행은 이름 칸이 없어 지금처럼 이름(xs, og)만 보인다
    expect(screen.queryByTestId('thumbnail-preset-label-1')).not.toBeInTheDocument();
    expect(screen.getByTestId('thumbnail-preset-ident-1')).toHaveTextContent('sm');
    expect(screen.queryByTestId('thumbnail-preset-label-5')).not.toBeInTheDocument();

    // 본문 이미지 규격·비우기 규격 select 도 같은 이름을 쓰고, 이름이 없으면 식별자로 폴백한다
    const field = screen.getByText(adminKo.settings.upload.thumbnail.content_preset).closest('div') as HTMLElement;
    const content = optionTexts(field.querySelector('select') as HTMLSelectElement);
    expect(content['sirsoft-hero_section.list']).toBe('배너 목록 (240)');
    expect(content['gnuboard7-hello_module.sample']).toBe('gnuboard7-hello_module.sample (480 × 270)');
    expect(content.sm).toBe('sm (160 × 160)');

    const purge = optionTexts(screen.getByTestId('thumbnail-purge-preset-public'));
    expect(purge['sirsoft-hero_section.list']).toBe('배너 목록');
    expect(purge['gnuboard7-hello_module.sample']).toBe('gnuboard7-hello_module.sample');
    expect(purge.og).toBe('og');
  });

  // @scenario surface=presets_table
  // @effects extension_row_shows_localized_label
  it('이름은 현재 언어로 고르고, 그 언어가 없으면 다른 언어로 폴백한다', async () => {
    testUtils = await renderCards({ upload: { thumbnail_presets_rows: LABELED_ROWS }, stats: LABELED_STATS, locale: 'en' });
    expect(screen.getByTestId('thumbnail-preset-label-7')).toHaveTextContent('Banner list');
    testUtils.cleanup();

    const koOnly = LABELED_ROWS.map((r) => (r.name === HERO_ROW.name ? { ...r, label: { ko: '배너 목록' } } : r));
    testUtils = await renderCards({ upload: { thumbnail_presets_rows: koOnly }, stats: LABELED_STATS, locale: 'en' });
    expect(screen.getByTestId('thumbnail-preset-label-7')).toHaveTextContent('배너 목록');
  });
});

describe('업로드 탭 저장 본문 (#869)', () => {
  /**
   * 저장 버튼의 요청 본문 식을 꺼냅니다.
   *
   * @returns 본문 식
   */
  function saveBodyExpression(): string {
    const button = findNodeById((adminSettings as Json).slots.content[0], 'save_button');
    const save = asArray(button.actions)[0].cases.save;
    const call = asArray(save.actions).find((a: Json) => a.handler === 'apiCall');

    return String(call.params.body);
  }

  // @scenario surface=save_body
  // @effects save_body_maps_rows_to_presets
  it('화면용 행을 thumbnail_presets 로 바꾸고 og 행·표시용 칸을 뺀다', () => {
    const engine = new DataBindingEngine();
    const form = { upload: { max_file_size: 10, thumbnail_enabled: true, thumbnail_presets_rows: ROWS, thumbnail_presets: [{ name: 'old' }] } };
    const body = engine.evaluateExpression(saveBodyExpression().replace(/^\{\{|\}\}$/g, ''), {
      _local: { form },
      _global: { activeSettingsTab: 'upload' },
      query: {},
    } as any) as Json;

    expect(body._tab).toBe('upload');
    expect(body.upload.max_file_size).toBe(10);
    expect(body.upload.thumbnail_presets_rows).toBeUndefined();
    expect(body.upload.thumbnail_presets.map((r: Json) => r.name)).toEqual(['xs', 'sm', 'md', 'lg', 'xl', 'banner_wide']);
    expect(Object.keys(body.upload.thumbnail_presets[0]).sort()).toEqual(
      ['anchor', 'enabled', 'fit', 'format', 'height', 'name', 'numeric', 'pregenerate', 'quality', 'source', 'upscale', 'width'].sort()
    );
  });

  // @scenario surface=save_body
  // @effects other_tab_save_body_unchanged
  it('다른 탭 본문은 종전 그대로다', () => {
    const engine = new DataBindingEngine();
    const body = engine.evaluateExpression(saveBodyExpression().replace(/^\{\{|\}\}$/g, ''), {
      _local: { form: { seo: { a: 1 } } },
      _global: { activeSettingsTab: 'seo' },
      query: {},
    } as any) as Json;

    expect(body).toEqual({ _tab: 'seo', seo: { a: 1 } });
  });
});

describe('기존 이미지 썸네일 만들기 사슬 (#869)', () => {
  /**
   * 한 걸음 명명 액션의 api-call 과 응답 처리 갈래를 꺼냅니다.
   *
   * @returns api-call 정의, 토큰 판정 switch, 이어 갈지 정하는 switch
   */
  function stepParts(): { call: Json; guard: Json; decide: Json } {
    const step = (adminSettings as Json).named_actions.thumbnailPregenerateStep;
    const call = asArray(step.actions).find((a: Json) => a.handler === 'apiCall');
    const guard = asArray(call.onSuccess).find((a: Json) => a.handler === 'switch');
    const current = asArray(guard.cases.current).flatMap((a: Json) => (a.handler === 'sequence' ? asArray(a.actions) : [a]));
    const decide = current.find((a: Json) => a.handler === 'switch');

    return { call, guard, decide };
  }

  // @scenario surface=pregenerate_chain
  // @effects pregenerate_chain_continues_until_cursor_null
  it('명명 액션이 next_cursor 가 있고 멈춤이 아니면 자기 자신을 다시 부른다', () => {
    const { call, decide: sw } = stepParts();

    expect(call.target).toBe('/api/admin/thumbnails/pregenerate');
    expect(sw.cases.next).toEqual({ actionRef: 'thumbnailPregenerateStep' });
    const engine = new DataBindingEngine();
    const decide = (cursor: string | null, paused: boolean) =>
      engine.evaluateExpression(String(sw.params.value).replace(/^\{\{|\}\}$/g, ''), {
        response: { data: { next_cursor: cursor } },
        _global: { thumbnailPregen: { paused } },
      } as any);
    expect(decide('20', false)).toBe('next');
    expect(decide('20', true)).toBe('paused');
    expect(decide(null, false)).toBe('done');
  });

  // @scenario surface=pregenerate_chain
  // @effects thumbnail_modals_registered
  it('두 창이 화면 창 목록에 있고, 만들기 창은 닫힘 표지로 멈춤을 남긴다', () => {
    const partials = ((adminSettings as Json).modals ?? []).map((m: Json) => m.partial ?? m.layout_name ?? m.id);
    expect(JSON.stringify(partials)).toContain('_modal_thumbnail_purge_all');
    expect(JSON.stringify(partials)).toContain('_modal_thumbnail_pregenerate');
    expect(JSON.stringify(pregenModal)).toContain('onUnmount');
    expect(JSON.stringify(purgeAllModal)).toContain('"location":"all"');
  });

  // @scenario surface=pregenerate_chain
  // @effects pregenerate_stale_response_discarded
  it('요청을 보낸 실행 토큰과 지금 토큰이 다르면 응답을 쌓지 않고 버린다(성공·실패 모두)', () => {
    const { call, guard } = stepParts();
    const step = (adminSettings as Json).named_actions.thumbnailPregenerateStep;
    const engine = new DataBindingEngine();
    const evaluate = (expr: string, pregen: Json) =>
      engine.evaluateExpression(String(expr).replace(/^\{\{|\}\}$/g, ''), { _global: { thumbnailPregen: pregen } } as any);

    // 걸음을 떠날 때 지금 토큰을 요청 토큰으로 적는다
    const mark = asArray(step.actions).find((a: Json) => a.handler === 'setState');
    expect(evaluate(mark.params['thumbnailPregen.requestRun'], { runId: 'r1' })).toBe('r1');

    expect(evaluate(guard.params.value, { runId: 'r1', requestRun: 'r1' })).toBe('current');
    expect(evaluate(guard.params.value, { runId: 'r2', requestRun: 'r1' })).toBe('stale');
    // 창을 다시 열어 상태를 새로 깐 뒤(토큰 없음) 늦게 온 응답도 버린다
    expect(evaluate(guard.params.value, { runId: null, requestRun: null })).toBe('stale');
    // 버리는 갈래는 아무것도 쓰지 않는다 — 처리 건수·inflight 는 지금 실행의 것이다
    expect(guard.cases.stale).toBeUndefined();
    expect(guard.cases.default).toBeUndefined();

    const failGuard = asArray(call.onError).find((a: Json) => a.handler === 'switch');
    expect(failGuard?.params?.value).toBe(guard.params.value);
    expect(failGuard.cases.stale).toBeUndefined();
  });

  // @scenario surface=pregenerate_chain
  // @effects pregenerate_start_issues_new_run_token
  it('시작 단추는 새 실행 토큰을 받고, 여는 버튼은 토큰을 비운다', () => {
    const text = JSON.stringify(pregenModal);
    expect(text).toContain('"thumbnailPregen.runId":"{{$uuid()}}"');
    const opener = JSON.stringify(nodeOf(uploadPartial as Json, 'card_thumbnail_settings'));
    expect(opener).toContain('"runId":null');
  });

  /**
   * 만들기 창의 아래 단추 줄을 주어진 전역 상태로 렌더합니다.
   *
   * @param pregen `_global.thumbnailPregen`
   * @returns 레이아웃 테스트 유틸
   */
  async function renderPregenFooter(pregen: Json) {
    const modal = (pregenModal as Json).components?.[0] ?? pregenModal;
    // 바닥 단추는 Modal 고정 하단(props.footer, 마크업 <footer>)에 있다 — 본문 children 에 단추 줄이 남지 않는다
    expect((modal.children ?? []).some((c: Json) => String(c.props?.className ?? '').includes('modal-footer-buttons'))).toBe(false);
    const footer = { type: 'basic', name: 'Div', props: {}, children: modal.props?.footer ?? [] };
    const utils = createLayoutTest(
      { version: '1.0.0', layout_name: 'test_pregen_footer', components: [footer] } as any,
      {
        componentRegistry: setupRegistry(),
        translations: { admin: adminKo },
        initialState: { _local: {}, _global: { thumbnailPregen: pregen } },
      }
    );
    await utils.render();

    return utils;
  }

  // @scenario surface=pregenerate_chain
  // @effects pregenerate_locked_while_inflight
  it('요청이 도는 동안(inflight) 여는 버튼과 시작 단추가 잠긴다 — 닫고 다시 열어 사슬을 하나 더 걸 수 없다', async () => {
    (window as any).__templateApp = { getConfig: () => ({ templateId: 'test-template', locale: 'ko' }) };
    const base = { sources: ['core_attachment'], presets: ['sm'], running: false, paused: true, steps: 1 };
    try {
      let utils = await renderCards({ global: { thumbnailPregen: { ...base, inflight: true } } });
      expect(screen.getByTestId('thumbnail-pregenerate-open')).toBeDisabled();
      utils.cleanup();

      utils = await renderCards({ global: { thumbnailPregen: { ...base, inflight: false } } });
      expect(screen.getByTestId('thumbnail-pregenerate-open')).not.toBeDisabled();
      utils.cleanup();

      utils = await renderPregenFooter({ ...base, inflight: true });
      expect(screen.getByTestId('thumbnail-pregen-start')).toBeDisabled();
      utils.cleanup();

      utils = await renderPregenFooter({ ...base, inflight: false });
      expect(screen.getByTestId('thumbnail-pregen-start')).not.toBeDisabled();
      utils.cleanup();
    } finally {
      delete (window as any).__templateApp;
    }
  });
});

describe('썸네일 비우기 사슬 (#869)', () => {
  /**
   * 비우기 한 걸음 명명 액션의 api-call 과 성공 갈래(쌓기 set-state·이어 갈지 정하는 switch)를 꺼냅니다.
   *
   * @returns api-call 정의, 쌓기 set-state, 이어 갈지 정하는 switch
   */
  function purgeStepParts(): { call: Json; accumulate: Json; decide: Json } {
    const call = (adminSettings as Json).named_actions.thumbnailPurgeStep;
    const success = asArray(call.onSuccess).flatMap((a: Json) => (a.handler === 'sequence' ? asArray(a.actions) : [a]));

    return {
      call,
      accumulate: success.find((a: Json) => a.handler === 'setState'),
      decide: success.find((a: Json) => a.handler === 'switch'),
    };
  }

  /**
   * `{{식}}` 바인딩을 문맥으로 평가합니다.
   *
   * @param binding 바인딩 문자열
   * @param context 평가 문맥
   * @returns 평가 값
   */
  function evaluate(binding: unknown, context: Json): unknown {
    return new DataBindingEngine().evaluateExpression(String(binding).replace(/^\{\{|\}\}$/g, ''), context as any);
  }

  // @scenario surface=purge_chain
  // @effects purge_chain_continues_until_cursor_null
  it('next_cursor 가 있으면 자기 자신을 다시 부르고, 없으면 부른 곳(줄·두 곳 모두)에 맞게 끝낸다', () => {
    const { call, decide } = purgeStepParts();

    expect(call.target).toBe('/api/admin/thumbnails/purge');
    expect(call.params.body.cursor).toBe('{{_global.thumbnailPurgeRun?.cursor ?? null}}');
    expect(decide.cases.next).toEqual({ actionRef: 'thumbnailPurgeStep' });

    const at = (cursor: string | null, origin: string) =>
      evaluate(decide.params.value, { response: { data: { next_cursor: cursor } }, _global: { thumbnailPurgeRun: { origin } } });
    expect(at('0:dGh1bWJuYWlscy9h', 'row')).toBe('next');
    expect(at(null, 'row')).toBe('done_row');
    expect(at(null, 'all')).toBe('done_all');

    // 줄 단추와 두 곳 모두 확인 창이 같은 걸음 액션을 부른다
    expect(JSON.stringify(uploadPartial)).toContain('"actionRef":"thumbnailPurgeStep"');
    expect(JSON.stringify(purgeAllModal)).toContain('"actionRef":"thumbnailPurgeStep"');
  });

  // @scenario surface=purge_chain
  // @effects purge_chain_accumulates_counts
  it('걸음마다 위치별 삭제 수를 쌓고, 디스크째 비운 -1 은 -1 로 남긴다', () => {
    const { accumulate } = purgeStepParts();
    const next = evaluate(accumulate.params['thumbnailPurgeRun.deleted'], {
      _global: { thumbnailPurgeRun: { deleted: { public: 2 } } },
      response: { data: { deleted: { public: 3, gated: -1 } } },
    });

    expect(next).toEqual({ public: 5, gated: -1 });
    expect(evaluate(accumulate.params['thumbnailPurgeRun.cursor'], { response: { data: { next_cursor: null } } })).toBeNull();
  });

  // @scenario surface=purge_chain
  // @effects purge_uncounted_shows_server_message
  it('개수를 세지 않은 위치(-1)는 「-1개」 대신 서버 문구로 알린다', () => {
    const { decide } = purgeStepParts();
    const toastOf = (branch: string) =>
      asArray(decide.cases[branch]).flatMap((a: Json) => (a.handler === 'sequence' ? asArray(a.actions) : [a])).find((a: Json) => a.handler === 'toast');

    const response = { message: '썸네일을 비웠습니다.' };
    expect(evaluate(toastOf('done_row').params.message, { response, _global: { thumbnailPurgeRun: { location: 'public', deleted: { public: -1 } } } })).toBe('썸네일을 비웠습니다.');
    expect(evaluate(toastOf('done_all').params.message, { response, _global: { thumbnailPurgeRun: { deleted: { public: 4, gated: -1 } } } })).toBe('썸네일을 비웠습니다.');
  });

  // @scenario surface=purge_chain
  // @effects purge_chain_locks_buttons
  it('비우기가 도는 동안 줄 비우기·규격 select·두 곳 모두 비우기가 잠기고, 부른 줄에만 진행 표시가 돈다', async () => {
    (window as any).__templateApp = { getConfig: () => ({ templateId: 'test-template', locale: 'ko' }) };
    try {
      const utils = await renderCards({ global: { thumbnailPurgeRun: { origin: 'row', location: 'public', cursor: '0:', deleted: {} } } });

      expect(screen.getByTestId('thumbnail-purge-public')).toBeDisabled();
      expect(screen.getByTestId('thumbnail-purge-gated')).toBeDisabled();
      expect(screen.getByTestId('thumbnail-purge-preset-public')).toBeDisabled();
      expect(screen.getByTestId('thumbnail-purge-all')).toBeDisabled();
      expect(screen.getByTestId('thumbnail-purge-public').querySelector('[data-icon="spinner"]')).not.toBeNull();
      expect(screen.getByTestId('thumbnail-purge-gated').querySelector('[data-icon="spinner"]')).toBeNull();
      utils.cleanup();
    } finally {
      delete (window as any).__templateApp;
    }
  });
});

describe('기존 이미지 썸네일 만들기 창 — 확장 규격 이름 (#869)', () => {
  // @scenario surface=pregenerate_chain
  // @effects pregenerate_modal_shows_extension_preset_label
  it('여는 버튼이 규격 이름표를 함께 넘기고, 창은 확장 규격을 이름(현재 언어)과 작은 식별자로 보인다', async () => {
    const opener = JSON.stringify(nodeOf(uploadPartial as Json, 'card_thumbnail_settings'));
    expect(opener).toContain('"presetLabels"');

    const modal = (pregenModal as Json).components?.[0] ?? pregenModal;
    const block = findNodeById(modal, 'thumbnail_pregen_presets');
    (window as any).__templateApp = { getConfig: () => ({ templateId: 'test-template', locale: 'ko' }) };
    try {
      const utils = createLayoutTest(
        { version: '1.0.0', layout_name: 'test_pregen_presets', components: [block] } as any,
        {
          componentRegistry: setupRegistry(),
          translations: { admin: adminKo },
          initialState: {
            _local: {},
            _global: {
              thumbnailPregen: {
                presetOptions: ['sm', 'sirsoft-hero_section.list'],
                presetLabels: { sm: null, 'sirsoft-hero_section.list': { ko: '배너 목록', en: 'Banner list' } },
                presets: [],
              },
            },
          },
        }
      );
      await utils.render();

      expect(screen.getByTestId('thumbnail-pregen-preset-label-sirsoft-hero_section.list').textContent).toBe('배너 목록');
      expect(screen.getByTestId('thumbnail-pregen-preset-ident-sirsoft-hero_section.list').textContent).toBe('sirsoft-hero_section.list');
      expect(screen.queryByTestId('thumbnail-pregen-preset-label-sm')).toBeNull();
      expect(screen.getByTestId('thumbnail-pregen-preset-ident-sm').textContent).toBe('sm');
      utils.cleanup();
    } finally {
      delete (window as any).__templateApp;
    }
  });

  // @scenario surface=pregenerate_chain
  // @effects pregenerate_modal_no_sources_notice
  it('원본 선택지가 0개면 「썸네일을 만들 수 있는 이미지 소스가 없습니다」를 보이고, 1개 이상이면 그 안내 대신 원본 목록을 그린다', async () => {
    const modal = (pregenModal as Json).components?.[0] ?? pregenModal;
    const block = findNodeById(modal, 'thumbnail_pregen_sources');
    (window as any).__templateApp = { getConfig: () => ({ templateId: 'test-template', locale: 'ko' }) };
    const renderSources = async (sourceOptions: Json[] | undefined) => {
      const utils = createLayoutTest(
        { version: '1.0.0', layout_name: 'test_pregen_sources', components: [block] } as any,
        {
          componentRegistry: setupRegistry(),
          translations: { admin: adminKo },
          initialState: { _local: {}, _global: { thumbnailPregen: { sourceOptions, sources: [] } } },
        }
      );
      await utils.render();
      return utils;
    };
    const notice = adminKo.settings.upload.thumbnail.pregenerate_modal.no_sources;
    try {
      // 응답 전(선택지 없음)과 빈 목록 모두 안내
      for (const options of [undefined, []]) {
        const utils = await renderSources(options);
        expect(screen.getByText(notice)).toBeInTheDocument();
        expect(screen.queryByText('코어 첨부')).toBeNull();
        utils.cleanup();
      }

      // 시험 등록에 Checkbox 가 없어 체크 칸은 그리지 않는다 — 같은 반복(label data-each)의 원본 이름으로 본다
      const utils = await renderSources([{ key: 'core_attachment', label: { ko: '코어 첨부', en: 'Core attachments' } }]);
      expect(screen.queryByText(notice)).toBeNull();
      expect(screen.getByText('코어 첨부')).toBeInTheDocument();
      utils.cleanup();
    } finally {
      delete (window as any).__templateApp;
    }
  });
});

describe('규격 표 행 추가·삭제와 422 행 번호 (#869 검토)', () => {
  /**
   * 업로드 조각에서 data-testid 식에 주어진 조각이 든 노드를 찾습니다.
   *
   * @param needle data-testid 에 든 문자열
   * @returns 노드
   */
  function nodeByTestId(needle: string): Json {
    const stack: Json[] = [...((uploadPartial as Json).components ?? [uploadPartial])];
    while (stack.length > 0) {
      const node = stack.shift() as Json;
      if (String(node?.props?.['data-testid'] ?? '').includes(needle)) return node;
      stack.push(...(node?.children ?? []));
    }
    throw new Error(`${needle} 노드가 없다`);
  }

  const ERRORS = {
    'upload.thumbnail_presets.5.name': ['이름 중복'],
    'upload.thumbnail_presets.1.width': ['가로 범위'],
    'upload.thumbnail_presets': ['개수 초과'],
    'upload.max_file_size': ['크기 범위'],
  };

  for (const [label, needle] of [['추가', 'thumbnail-preset-add'], ['삭제', 'thumbnail-preset-remove-']] as const) {
    // @scenario surface=presets_table
    // @effects preset_row_change_clears_indexed_errors
    it(`행 ${label}는 번호가 달린 규격 오류(upload.thumbnail_presets.*)를 지우고 다른 오류는 둔다`, () => {
      const setState = asArray(nodeByTestId(needle).actions).find((a: Json) => a.handler === 'setState');
      const engine = new DataBindingEngine();
      const evaluate = (errors: Json | null) =>
        engine.evaluateExpression(String(setState.params.errors).replace(/^\{\{|\}\}$/g, ''), {
          _local: { errors, form: { upload: { thumbnail_presets_rows: ROWS } } },
          rowIdx: 6,
        } as any);

      expect(evaluate(ERRORS)).toEqual({ 'upload.thumbnail_presets': ['개수 초과'], 'upload.max_file_size': ['크기 범위'] });
      expect(evaluate(null)).toBeNull();
    });
  }
});

describe('업로드 탭 placeholder·전환 대기 (#869 검토)', () => {
  // @scenario surface=presets_table
  // @effects preset_placeholders_translated
  it('규격 표 이름·세로 칸의 placeholder 는 다국어 키다', () => {
    const text = JSON.stringify(nodeOf(uploadPartial as Json, 'card_thumbnail_presets'));
    expect(text).not.toContain('"placeholder":"auto"');
    expect(text).not.toContain('"placeholder":"banner_wide"');
    expect(text).toContain('"placeholder":"$t:admin.settings.upload.thumbnail.presets.placeholder.name"');
    expect(text).toContain('"placeholder":"$t:admin.settings.upload.thumbnail.presets.placeholder.height"');
  });

  // @scenario surface=storage_rows
  // @effects thumbnail_stats_not_awaited_by_transition
  it('사용량 집계는 background 로 받아 탭 전환 덮개가 기다리지 않는다', () => {
    // progressive 소스는 화면 규약(admin-base-transition-overlay 가드)상 wait-for 에 들어가야 하므로, 덮개가 기다리지 않을
    // 소스는 background 다 — 받는 시점·성공/실패 처리는 같고 덮개 대기만 빠진다
    const source = ((adminSettings as Json).data_sources ?? []).find((d: Json) => d.id === 'thumbnailStats');
    expect(source.loading_strategy).toBe('background');
    expect(asArray(source.onError).length + asArray(source.onSuccess).length).toBeGreaterThan(0);
    expect((adminSettings as Json).transition_overlay.wait_for).not.toContain('thumbnailStats');
  });
});

describe('드라이버 탭 — 이미지 처리 카드 (#869)', () => {
  // @scenario surface=driver_card
  // @effects image_driver_card_bound_to_catalog
  it('선택지는 available_drivers.image, 값 키는 drivers.image_driver, 사용 불가 안내가 있다', () => {
    const card = nodeOf(driversPartial as Json, 'card_image_driver_settings');
    const text = JSON.stringify(card);

    expect(text).toContain('"name":"drivers.image_driver"');
    expect(text).toContain('available_drivers?.image');
    expect(findNodeById(card, 'unavailable_driver_notice_image')).not.toBeNull();
    expect(text).toContain("envLocked?.['drivers.image_driver']");
  });
});

describe('모두 비우기 창 — 위험 단추 진행 표시 (#869 검토)', () => {
  /**
   * 창의 하단 단추 줄을 주어진 진행 상태로 렌더합니다.
   *
   * @param purging 진행 중 여부
   * @returns 레이아웃 테스트 유틸
   */
  async function renderConfirm(purging: boolean) {
    // 바닥 단추는 Modal 고정 하단(props.footer, 마크업 <footer>)에 있다 — 본문 children 에 단추 줄이 남지 않는다
    expect(((purgeAllModal as Json).children ?? []).some((node: Json) => node.props?.className === 'modal-footer-buttons')).toBe(false);
    const footer = { type: 'basic', name: 'Div', props: {}, children: (purgeAllModal as Json).props?.footer ?? [] };
    const utils = createLayoutTest(
      { version: '1.0.0', layout_name: 'test_purge_all_confirm', components: [footer] } as any,
      {
        componentRegistry: setupRegistry(),
        translations: { admin: adminKo, common: commonKo },
        initialState: { _local: {}, _global: { thumbnailPurgeAll: { purging, locations: [] } } },
      },
    );
    await utils.render();

    return utils;
  }

  // @scenario surface=purge_all_modal
  // @effects purge_all_confirm_shows_progress_text
  it.each([
    [false, adminKo.settings.upload.thumbnail.purge_all_modal.confirm],
    [true, commonKo.deleting],
  ])('진행 중=%s 이면 단추 문구는 「%s」 다', async (purging, label) => {
    (window as any).__templateApp = { getConfig: () => ({ templateId: 'test-template', locale: 'ko' }) };
    try {
      const utils = await renderConfirm(purging);
      const confirm = screen.getByTestId('thumbnail-purge-all-confirm') as HTMLButtonElement;
      expect(confirm).toHaveTextContent(label);
      expect(confirm.disabled).toBe(purging);
      expect(confirm.querySelector('[data-icon="spinner"]') !== null).toBe(purging);
      utils.cleanup();
    } finally {
      delete (window as any).__templateApp;
    }
  });
});

describe('썸네일 화면 다국어 (#869)', () => {
  // @scenario surface=i18n
  // @effects thumbnail_keys_exist_in_three_locales
  it('정적·동적 키가 ko·en·ja 세 언어에 모두 있다', () => {
    const dynamic: Record<string, string[]> = {
      'admin.settings.upload.thumbnail.storage.kinds.': ['public', 'gated'],
      'admin.settings.upload.thumbnail.storage.kind_hints.': ['public', 'gated'],
      'admin.settings.upload.thumbnail.presets.kinds.': ['fixed', 'og', 'extension', 'custom'],
      'admin.settings.upload.thumbnail.presets.fit.': ['cover', 'contain', 'width'],
      'admin.settings.upload.thumbnail.presets.anchor.': ['center', 'top', 'bottom', 'left', 'right'],
      'admin.settings.upload.thumbnail.presets.format.': ['original', 'webp', 'avif'],
    };
    const keys = new Set([
      ...staticKeys(uploadPartial),
      ...staticKeys(driversPartial),
      ...staticKeys(purgeAllModal),
      ...staticKeys(pregenModal),
      ...staticKeys(adminSettings),
      ...Object.entries(dynamic).flatMap(([prefix, values]) => values.map((v) => prefix + v)),
    ]);
    expect(keys.size).toBeGreaterThan(60);

    for (const [locale, bundle] of [['ko', adminKo], ['en', adminEn], ['ja', adminJa]] as const) {
      const missing = [...keys].filter((key) => typeof pick({ admin: bundle }, key) !== 'string');
      expect(missing, locale).toEqual([]);
    }
  });
});

describe('업로드 탭 — 움직이는 이미지는 원본 사용 토글 (#869)', () => {
  let testUtils: Awaited<ReturnType<typeof renderCards>> | undefined;

  beforeEach(() => {
    (window as any).__templateApp = { getConfig: () => ({ templateId: 'test-template', locale: 'ko' }) };
  });

  afterEach(() => {
    testUtils?.cleanup();
    testUtils = undefined;
    delete (window as any).__templateApp;
  });

  /**
   * 토글 칸(라벨의 부모)과 그 안의 토글을 찾습니다.
   *
   * @returns 칸과 토글 요소
   */
  function animatedField(): { field: HTMLElement; toggle: HTMLElement } {
    const label = screen.getByText(adminKo.settings.upload.thumbnail.animated_original);
    const field = label.parentElement as HTMLElement;
    const toggle = field.querySelector('[data-toggle]') as HTMLElement;
    expect(toggle, '라벨 옆에 토글이 있다').not.toBeNull();

    return { field, toggle };
  }

  // @scenario surface=animated_original_toggle
  // @effects animated_original_toggle_rendered_with_hint
  it('썸네일 카드에 라벨·안내와 함께 토글이 있고, 수정 권한이 있으면 풀려 있다', async () => {
    testUtils = await renderCards({ upload: { thumbnail_animated_original: true } });

    const { field, toggle } = animatedField();
    expect(field).toHaveTextContent(adminKo.settings.upload.thumbnail.animated_original_hint);
    expect(toggle).toHaveAttribute('data-disabled', 'false');
    // 레이아웃 노드가 저장 키에 묶여 있다
    expect(JSON.stringify(nodeOf(uploadPartial as Json, 'field_thumbnail_animated_original'))).toContain('"name":"upload.thumbnail_animated_original"');
  });

  // @scenario surface=animated_original_toggle
  // @effects animated_original_toggle_locked_when_read_only
  it('설정 수정 권한이 없으면(isReadOnly) 토글이 잠긴다', async () => {
    testUtils = await renderCards({ readOnly: true, upload: { thumbnail_animated_original: true } });

    expect(animatedField().toggle).toHaveAttribute('data-disabled', 'true');
  });

  // @scenario surface=save_body
  // @effects animated_original_toggle_saved_and_persisted
  it('업로드 탭 저장 본문에 토글 값이 그대로 실린다', () => {
    const button = findNodeById((adminSettings as Json).slots.content[0], 'save_button');
    const save = asArray(button.actions)[0].cases.save;
    const call = asArray(save.actions).find((a: Json) => a.handler === 'apiCall');
    const engine = new DataBindingEngine();

    for (const value of [false, true]) {
      const form = { upload: { max_file_size: 10, thumbnail_animated_original: value, thumbnail_presets_rows: ROWS } };
      const body = engine.evaluateExpression(String(call.params.body).replace(/^\{\{|\}\}$/g, ''), {
        _local: { form },
        _global: { activeSettingsTab: 'upload' },
        query: {},
      } as any) as Json;

      expect(body.upload.thumbnail_animated_original, String(value)).toBe(value);
    }
  });
});

describe('업로드 탭 — 썸네일 「고급」 서버 보호 값 (#869)', () => {
  let testUtils: Awaited<ReturnType<typeof renderCards>> | undefined;

  /** 칸 이름 → [저장 키 뒤, 서버 기본값, 하한, 상한] */
  const FIELDS: Array<[string, number, number, number]> = [
    ['generate_per_minute', 120, 1, 10000],
    ['lock_wait_seconds', 10, 1, 30],
    ['max_source_pixels', 16000000, 1000000, 100000000],
    ['pregenerate_batch', 20, 1, 500],
    ['pregenerate_time_budget', 15, 1, 60],
    ['purge_time_budget', 10, 1, 60],
  ];

  const META = {
    limits: Object.fromEntries(
      FIELDS.flatMap(([key, , min, max]) => [
        [`upload_thumbnail_${key}_min`, min],
        [`upload_thumbnail_${key}_max`, max],
      ])
    ),
    thumbnail_server_defaults: Object.fromEntries(FIELDS.map(([key, value]) => [key, value])),
  };

  beforeEach(() => {
    (window as any).__templateApp = { getConfig: () => ({ templateId: 'test-template', locale: 'ko' }) };
  });

  afterEach(() => {
    testUtils?.cleanup();
    testUtils = undefined;
    delete (window as any).__templateApp;
  });

  /**
   * 「고급」 칸의 입력 요소를 찾습니다.
   *
   * @param key 저장 키 뒤 (`thumbnail_` 다음)
   * @returns 입력 요소
   */
  function limitInput(key: string): HTMLInputElement {
    return screen.getByTestId(`thumbnail-server-limit-${key}`) as HTMLInputElement;
  }

  // @scenario surface=server_limits
  // @effects server_limits_fields_rendered_with_defaults
  it('여섯 칸이 라벨·안내와 함께 보이고, 비운 칸은 서버 기본값을 placeholder 로, 입력 범위는 _meta.limits 로 보인다', async () => {
    testUtils = await renderCards({ meta: META });

    const section = screen.getByTestId('thumbnail-server-limits');
    expect(section).toHaveTextContent(adminKo.settings.upload.thumbnail.server_limits.title);
    expect(section).toHaveTextContent(adminKo.settings.upload.thumbnail.server_limits.desc);

    for (const [key, defaultValue, min, max] of FIELDS) {
      const input = limitInput(key);
      expect(input, key).not.toBeDisabled();
      expect(input.getAttribute('placeholder'), key).toBe(String(defaultValue));
      expect(input.getAttribute('min'), key).toBe(String(min));
      expect(input.getAttribute('max'), key).toBe(String(max));
      expect(section).toHaveTextContent(adminKo.settings.upload.thumbnail.server_limits[key]);
      expect(section).toHaveTextContent(adminKo.settings.upload.thumbnail.server_limits[`${key}_hint`]);
      // 레이아웃 노드가 저장 키에 묶여 있다
      expect(JSON.stringify(nodeOf(uploadPartial as Json, `field_thumbnail_${key}`))).toContain(`"name":"upload.thumbnail_${key}"`);
    }
    expect(within(section).queryByText(adminKo.settings.env_locked_badge)).toBeNull();
  });

  // @scenario surface=server_limits
  // @effects server_limits_env_locked_field_disabled_with_badge
  it('.env 가 정한 칸만 잠기고 「.env 고정」 배지를 단다', async () => {
    testUtils = await renderCards({ meta: { ...META, env_locked: { 'upload.thumbnail_lock_wait_seconds': true } } });

    expect(limitInput('lock_wait_seconds')).toBeDisabled();
    const lockedField = limitInput('lock_wait_seconds').closest('[data-testid="thumbnail-server-limit-field-lock_wait_seconds"]') as HTMLElement;
    expect(within(lockedField).getByText(adminKo.settings.env_locked_badge)).toBeInTheDocument();

    for (const [key] of FIELDS.filter(([k]) => k !== 'lock_wait_seconds')) {
      expect(limitInput(key), key).not.toBeDisabled();
    }
    expect(within(screen.getByTestId('thumbnail-server-limits')).getAllByText(adminKo.settings.env_locked_badge)).toHaveLength(1);
  });

  // @scenario surface=server_limits
  // @effects server_limits_locked_when_read_only
  it('설정 수정 권한이 없으면 여섯 칸이 모두 잠긴다', async () => {
    testUtils = await renderCards({ readOnly: true, meta: META });

    for (const [key] of FIELDS) {
      expect(limitInput(key), key).toBeDisabled();
    }
  });

  // @scenario surface=server_limits
  // @effects server_limits_error_shown_under_field
  it('서버 422 오류를 그 칸 아래에 단다', async () => {
    testUtils = await renderCards({ meta: META, errors: { 'upload.thumbnail_lock_wait_seconds': ['범위 밖'] } });

    const field = screen.getByTestId('thumbnail-server-limit-field-lock_wait_seconds');
    expect(field).toHaveTextContent('범위 밖');
    expect(limitInput('lock_wait_seconds').className).toContain('input-error');
    expect(limitInput('generate_per_minute').className).not.toContain('input-error');
  });

  // @scenario surface=save_body
  // @effects server_limits_saved_in_body
  it('업로드 탭 저장 본문에 여섯 값이 그대로 실린다(비운 칸은 null)', () => {
    const button = findNodeById((adminSettings as Json).slots.content[0], 'save_button');
    const save = asArray(button.actions)[0].cases.save;
    const call = asArray(save.actions).find((a: Json) => a.handler === 'apiCall');
    const engine = new DataBindingEngine();
    const form = { upload: { max_file_size: 10, thumbnail_lock_wait_seconds: 20, thumbnail_generate_per_minute: null, thumbnail_presets_rows: ROWS } };
    const body = engine.evaluateExpression(String(call.params.body).replace(/^\{\{|\}\}$/g, ''), {
      _local: { form },
      _global: { activeSettingsTab: 'upload' },
      query: {},
    } as any) as Json;

    expect(body.upload.thumbnail_lock_wait_seconds).toBe(20);
    expect(body.upload.thumbnail_generate_per_minute).toBeNull();
  });
});
