/**
 * @file admin-settings-security-csp-style.test.tsx
 * @description 환경설정 > 보안 > 콘텐츠 보안 정책 카드의 **스타일 정책** 블록 회귀 테스트
 *
 * 스타일 정책은 스크립트 정책과 따로 켜고 끈다(적용 모드·강도·허용 호스트가 별도 키). 네 축으로 잠근다.
 *
 *  (a) 구조 — 미리보기 필드 뒤에 소제목과 필드 5개가 정해진 순서로 놓인다
 *  (b) 실제 `_tab_security_csp.json` 조각 렌더 — 강도별 「깨지는 화면」 경고 분기, 강제 경고,
 *      `.env`(`_meta.csp.style_override`) 잠금, 자동 호스트·미리보기·검증 오류 표시
 *  (c) 저장 — 환경설정 저장 요청 본문이 보안 탭 폼을 통째로 싣으므로 새 3키가 함께 나간다
 *  (d) 언어 — 스타일 키 18개가 ko·en 양쪽에 있고, 경고 문구에 자리표시자(`{{KNOWN_`)가 남아 있지 않다
 *
 * @scenario-manifest tests/scenarios/csp-header-setting.yaml
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';

const readJson = (relative: string) => JSON.parse(readFileSync(resolve(__dirname, '../..', relative), 'utf-8'));

const cspPartial = readJson('layouts/partials/admin_settings/_tab_security_csp.json');
const settingsLayout = readJson('layouts/admin_settings.json');
const koAdmin = readJson('lang/partial/ko/admin.json');
const enAdmin = readJson('lang/partial/en/admin.json');

interface AnyJson { [k: string]: any }

/** 트리를 깊이 우선으로 펼친다. */
function flatten(node: any): AnyJson[] {
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap(flatten);
  return [node, ...(Array.isArray(node.children) ? node.children.flatMap(flatten) : [])];
}

const nodes = flatten(cspPartial);
const byId = (id: string) => nodes.find((n) => n.id === id);

const STYLE_REFS = [
  'csp_style_heading',
  'field_csp_style_mode',
  'field_csp_style_level',
  'field_csp_extra_style_hosts',
  'field_csp_style_auto_hosts',
  'field_csp_style_preview',
];

const STYLE_LANG_KEYS = [
  'title', 'desc', 'mode', 'mode_desc', 'level', 'level_basic', 'level_strict', 'level_desc',
  'enforce_warning', 'known_issues_basic', 'known_issues_strict', 'override_notice',
  'extra_hosts', 'extra_hosts_placeholder', 'extra_hosts_hint', 'auto_hosts', 'auto_hosts_empty', 'preview',
];

// ──────────────────────────────────────────────────────────────────────────────
// (a) 구조
// ──────────────────────────────────────────────────────────────────────────────

describe('CSP 카드 — 스타일 정책 블록 구조', () => {
  /**
   * @scenario case=style_settings_screen
   * @effects csp_style_block_structure
   */
  it('미리보기 필드 바로 뒤에 소제목과 필드 5개가 순서대로 놓인다', () => {
    const rowStack = nodes.find((n) => Array.isArray(n.children) && n.children.some((c: AnyJson) => c.id === 'field_csp_preview'));
    const ids = (rowStack!.children as AnyJson[]).map((c) => c.id);
    const previewIdx = ids.indexOf('field_csp_preview');

    expect(previewIdx).toBeGreaterThanOrEqual(0);
    expect(ids.slice(previewIdx + 1)).toEqual(STYLE_REFS);
  });

  /** @effects csp_style_block_structure */
  it('모드·강도는 select, 추가 호스트는 태그 입력이고 설정 키 이름으로 묶인다', () => {
    const names = (id: string, component: string) =>
      flatten(byId(id)).filter((n) => n.name === component).map((n) => n.props?.name);

    expect(names('field_csp_style_mode', 'Select')).toEqual(['security.csp_style_mode']);
    expect(names('field_csp_style_level', 'Select')).toEqual(['security.csp_style_level']);
    expect(names('field_csp_extra_style_hosts', 'TagInput')).toEqual(['security.csp_extra_style_hosts']);
  });

  /** @effects csp_style_block_structure */
  it('모드 옵션은 스크립트 정책의 문구 키를 다시 쓰고, 강도 옵션은 기본·엄격 둘이다', () => {
    const mode = flatten(byId('field_csp_style_mode')).find((n) => n.name === 'Select');
    const level = flatten(byId('field_csp_style_level')).find((n) => n.name === 'Select');

    for (const key of ['mode_off', 'mode_report_only', 'mode_enforce']) {
      expect(String(mode!.props.options)).toContain(`$t('admin.settings.security.csp.${key}')`);
    }
    expect(String(mode!.props.options)).toMatch(/value: 'off'.*value: 'report_only'.*value: 'enforce'/);
    expect(String(level!.props.options)).toMatch(/value: 'basic'.*level_basic.*value: 'strict'.*level_strict/);
  });

  /** @effects csp_style_block_structure */
  it('모드·강도 select 폭은 스크립트 모드 select 처럼 엔진 responsive 로 가른다 — 640px 미만 w-full, 이상 w-80', () => {
    const select = (id: string) => flatten(byId(id)).find((n) => n.name === 'Select')!;
    const script = select('field_csp_mode');

    for (const id of ['field_csp_style_mode', 'field_csp_style_level']) {
      const node = select(id);
      // Tailwind 반응형 접두(`sm:w-80`)는 창 폭을 따라 편집기 미리보기 폭과 어긋난다 — 접두 없는 기본값 + 구간 값
      expect(node.props.className, id).toBe('w-full');
      expect(node.responsive, id).toEqual({ '640-': { props: { className: 'w-80' } } });
      expect(node.responsive, id).toEqual(script.responsive);
    }
  });
});

// ──────────────────────────────────────────────────────────────────────────────
// (b) 실제 조각 렌더
// ──────────────────────────────────────────────────────────────────────────────

const passthrough = (Tag: string): React.FC<any> => ({ id, className, children, text }) =>
  React.createElement(Tag, { id, className }, children || text);

const TestSelect: React.FC<any> = ({ name, disabled, options, error }) => (
  <select name={name} disabled={!!disabled} data-error={error || ''}>
    {(Array.isArray(options) ? options : []).map((o: AnyJson) => (
      <option key={o.value} value={o.value}>{o.label}</option>
    ))}
  </select>
);

const TestTagInput: React.FC<any> = ({ id, name, disabled, placeholder }) => (
  <input id={id} name={name} disabled={!!disabled} placeholder={placeholder} data-component="tag-input" />
);

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const basic = (name: string, tag: string) => ({ component: passthrough(tag), metadata: { name, type: 'basic' } });

  (registry as any).registry = {
    Div: basic('Div', 'div'),
    H3: basic('H3', 'h3'),
    H4: basic('H4', 'h4'),
    P: basic('P', 'p'),
    Span: basic('Span', 'span'),
    Label: basic('Label', 'label'),
    Pre: basic('Pre', 'pre'),
    Select: { component: TestSelect, metadata: { name: 'Select', type: 'basic' } },
    TagInput: { component: TestTagInput, metadata: { name: 'TagInput', type: 'composite' } },
    Fragment: { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

interface CardState {
  security?: AnyJson;
  csp?: AnyJson;
  errors?: AnyJson | null;
  readOnly?: boolean;
  locale?: 'ko' | 'en';
}

/** 실제 CSP 카드 조각을 주어진 폼 상태로 그린다. */
async function renderCard(state: CardState = {}) {
  const locale = state.locale ?? 'ko';
  const testUtils = createLayoutTest(
    {
      version: '1.0.0',
      layout_name: 'test_security_csp_style',
      computed: { isReadOnly: state.readOnly ? '{{true}}' : '{{false}}' },
      components: [cspPartial],
    } as any,
    {
      translations: { admin: locale === 'ko' ? koAdmin : enAdmin },
      locale,
      // settings 는 폼(_local.form)을 채우는 소스 — 받은 뒤 상태를 재현한다(받는 중이면 빈 상태 문구를 그리지 않는다, #915)
      initialData: { settings: { data: {} } },
      initialState: {
        _local: {
          errors: state.errors ?? null,
          form: {
            security: {
              csp_mode: 'off',
              csp_extra_script_hosts: [],
              csp_style_mode: 'off',
              csp_style_level: 'basic',
              csp_extra_style_hosts: [],
              ...(state.security ?? {}),
            },
            _meta: {
              csp: {
                override: null,
                auto_hosts: [],
                preview: '',
                style_override: null,
                style_auto_hosts: [],
                style_preview: '',
                ...(state.csp ?? {}),
              },
            },
          },
        },
      },
    },
  );

  await testUtils.render();

  return testUtils;
}

const select = (name: string) => document.querySelector(`select[name="${name}"]`) as HTMLSelectElement | null;
const el = (id: string) => document.querySelector(`#${id}`);

describe('CSP 카드 — 스타일 정책 블록 렌더', () => {
  let registry: ComponentRegistry;

  beforeEach(() => { registry = setupTestRegistry(); });
  afterEach(() => { (registry as any).registry = {}; });

  /**
   * @scenario case=style_settings_screen
   * @effects csp_style_block_renders_saved_policy
   */
  it('소제목·설명과 필드 5개를 그리고, 끔 상태에서는 경고·잠금 문구가 없다', async () => {
    const testUtils = await renderCard();

    for (const id of STYLE_REFS) {
      expect(el(id), `${id} 가 렌더되지 않았다`).not.toBeNull();
    }
    expect(el('csp_style_heading')!.textContent).toContain(koAdmin.settings.security.csp.style.title);
    expect(el('csp_style_heading')!.textContent).toContain(koAdmin.settings.security.csp.style.desc);

    expect(Array.from(select('security.csp_style_mode')!.options).map((o) => o.value)).toEqual(['off', 'report_only', 'enforce']);
    expect(Array.from(select('security.csp_style_level')!.options).map((o) => o.text)).toEqual([
      koAdmin.settings.security.csp.style.level_basic,
      koAdmin.settings.security.csp.style.level_strict,
    ]);
    expect(select('security.csp_style_mode')!.disabled).toBe(false);
    expect(select('security.csp_style_level')!.disabled).toBe(false);
    expect(document.querySelector('input[name="security.csp_extra_style_hosts"]')).not.toBeNull();

    for (const id of ['csp_style_enforce_warning', 'csp_style_override_notice', 'csp_style_known_issues_basic', 'csp_style_known_issues_strict']) {
      expect(el(id), `${id} 는 끔 상태에서 보이면 안 된다`).toBeNull();
    }

    testUtils.cleanup();
  });

  /** @effects csp_style_block_renders_saved_policy */
  it('자동 호스트와 미리보기는 서버가 준 저장값을 그대로 보인다', async () => {
    const testUtils = await renderCard({
      csp: {
        style_auto_hosts: ['cdn.example.com', 'fonts.example.com'],
        style_preview: "style-src-elem 'self' cdn.example.com fonts.example.com; report-uri /api/csp-report",
      },
    });

    expect(el('csp_style_auto_hosts')!.textContent).toBe('cdn.example.com · fonts.example.com');
    expect(el('field_csp_style_auto_hosts')!.textContent).not.toContain(koAdmin.settings.security.csp.style.auto_hosts_empty);
    expect(el('csp_style_preview')!.textContent).toBe("style-src-elem 'self' cdn.example.com fonts.example.com; report-uri /api/csp-report");

    testUtils.cleanup();
  });

  /** @effects csp_style_block_renders_saved_policy */
  it('자동 호스트가 없으면 빈 상태 문구를 보인다', async () => {
    const testUtils = await renderCard();

    expect(el('csp_style_auto_hosts')).toBeNull();
    expect(el('field_csp_style_auto_hosts')!.textContent).toContain(koAdmin.settings.security.csp.style.auto_hosts_empty);

    testUtils.cleanup();
  });

  /**
   * @scenario case=style_settings_screen
   * @effects csp_style_level_warning_follows_effective_mode
   */
  it.each([
    ['report_only', 'basic', true, false],
    ['report_only', 'strict', false, true],
    ['enforce', 'basic', true, false],
    ['enforce', 'strict', false, true],
    ['off', 'basic', false, false],
    ['off', 'strict', false, false],
  ])('모드 %s · 강도 %s → 기본 경고 %s, 엄격 경고 %s', async (mode, level, basicShown, strictShown) => {
    const testUtils = await renderCard({ security: { csp_style_mode: mode, csp_style_level: level } });

    expect(el('csp_style_known_issues_basic') !== null).toBe(basicShown);
    expect(el('csp_style_known_issues_strict') !== null).toBe(strictShown);

    testUtils.cleanup();
  });

  /** @effects csp_style_level_warning_follows_effective_mode */
  it('강도 경고는 실제로 적용되는 모드를 본다 — 저장값이 끔이어도 .env 가 켜면 보이고, .env 가 끄면 숨는다', async () => {
    const forcedOn = await renderCard({ security: { csp_style_mode: 'off', csp_style_level: 'strict' }, csp: { style_override: 'enforce' } });
    expect(el('csp_style_known_issues_strict')).not.toBeNull();
    expect(el('csp_style_known_issues_basic')).toBeNull();
    forcedOn.cleanup();

    const forcedOff = await renderCard({ security: { csp_style_mode: 'enforce', csp_style_level: 'basic' }, csp: { style_override: 'off' } });
    expect(el('csp_style_known_issues_basic')).toBeNull();
    expect(el('csp_style_known_issues_strict')).toBeNull();
    forcedOff.cleanup();
  });

  /** @effects csp_style_enforce_warning_shown */
  it('강제를 고르면 점검 먼저 경고를 보이고, 점검만에서는 보이지 않는다', async () => {
    const enforce = await renderCard({ security: { csp_style_mode: 'enforce' } });
    expect(el('csp_style_enforce_warning')!.textContent).toBe(koAdmin.settings.security.csp.style.enforce_warning);
    // 스크립트 정책의 강제 경고는 스크립트 모드만 본다
    expect(el('csp_enforce_warning')).toBeNull();
    enforce.cleanup();

    const reportOnly = await renderCard({ security: { csp_style_mode: 'report_only' } });
    expect(el('csp_style_enforce_warning')).toBeNull();
    reportOnly.cleanup();
  });

  /**
   * @scenario case=style_settings_screen
   * @effects csp_style_mode_locked_by_env_override
   */
  it('.env 잠금: style_override 가 있으면 모드 select 가 잠기고 잠금 문구가 강제 경고를 대신한다', async () => {
    const testUtils = await renderCard({ security: { csp_style_mode: 'enforce' }, csp: { style_override: 'report_only' } });

    expect(select('security.csp_style_mode')!.disabled).toBe(true);
    expect(el('csp_style_override_notice')!.textContent).toContain('G7_CSP_STYLE_MODE=report_only');
    expect(el('csp_style_enforce_warning')).toBeNull();

    // 잠기는 것은 스타일 모드뿐이다 — 강도·호스트와 스크립트 정책 모드는 그대로 고칠 수 있다
    expect(select('security.csp_style_level')!.disabled).toBe(false);
    expect((document.querySelector('input[name="security.csp_extra_style_hosts"]') as HTMLInputElement).disabled).toBe(false);
    expect(select('security.csp_mode')!.disabled).toBe(false);
    expect(el('csp_override_notice')).toBeNull();

    testUtils.cleanup();
  });

  /** @effects csp_style_mode_locked_by_env_override */
  it('스크립트 쪽 .env 잠금(override)은 스타일 모드를 잠그지 않는다', async () => {
    const testUtils = await renderCard({ csp: { override: 'off' } });

    expect(select('security.csp_mode')!.disabled).toBe(true);
    expect(select('security.csp_style_mode')!.disabled).toBe(false);
    expect(el('csp_style_override_notice')).toBeNull();

    testUtils.cleanup();
  });

  /** @effects csp_style_mode_locked_by_env_override */
  it('영문에서도 잠금 문구가 .env 값을 싣는다', async () => {
    const testUtils = await renderCard({ csp: { style_override: 'off' }, locale: 'en' });

    expect(el('csp_style_override_notice')!.textContent).toBe(
      String(enAdmin.settings.security.csp.style.override_notice).replace('{{mode}}', 'off'),
    );

    testUtils.cleanup();
  });

  /** @effects csp_style_fields_locked_when_readonly */
  it('읽기 전용이면 모드·강도·추가 호스트가 모두 잠긴다', async () => {
    const testUtils = await renderCard({ readOnly: true });

    expect(select('security.csp_style_mode')!.disabled).toBe(true);
    expect(select('security.csp_style_level')!.disabled).toBe(true);
    expect((document.querySelector('input[name="security.csp_extra_style_hosts"]') as HTMLInputElement).disabled).toBe(true);

    testUtils.cleanup();
  });

  /** @effects csp_style_host_errors_shown_per_field */
  it('추가 스타일 호스트의 항목별 검증 오류를 입력 아래에 보이고, 스크립트 호스트 오류와 섞지 않는다', async () => {
    const testUtils = await renderCard({
      errors: {
        'security.csp_extra_style_hosts.1': ['스타일 호스트 형식 오류'],
        'security.csp_style_level': ['강도 오류'],
      },
    });

    expect(el('field_csp_extra_style_hosts')!.querySelector('.form-error')!.textContent).toBe('스타일 호스트 형식 오류');
    expect(el('field_csp_extra_script_hosts')!.querySelector('.form-error')).toBeNull();
    expect(select('security.csp_style_level')!.getAttribute('data-error')).toBe('강도 오류');

    testUtils.cleanup();
  });
});

// ──────────────────────────────────────────────────────────────────────────────
// (c) 저장 요청 본문
// ──────────────────────────────────────────────────────────────────────────────

describe('환경설정 저장 — 스타일 정책 3키', () => {
  /** 트리 어디에 있든 조건에 맞는 객체를 모은다(액션은 children 밖에 있다). */
  function collect(node: unknown, predicate: (o: AnyJson) => boolean, acc: AnyJson[] = []): AnyJson[] {
    if (Array.isArray(node)) {
      node.forEach((n) => collect(n, predicate, acc));
    } else if (node && typeof node === 'object') {
      if (predicate(node as AnyJson)) acc.push(node as AnyJson);
      Object.values(node as AnyJson).forEach((v) => collect(v, predicate, acc));
    }
    return acc;
  }

  /**
   * @scenario case=style_settings_screen
   * @effects csp_style_keys_sent_on_save
   */
  it('저장 요청은 보안 탭 폼을 통째로 싣으므로 새 3키가 함께 나간다', () => {
    const save = collect(
      settingsLayout,
      (o) => o.handler === 'apiCall' && o.target === '/api/admin/settings' && typeof o.params?.body === 'string',
    );
    expect(save.length, '환경설정 저장 apiCall 을 찾지 못했다').toBeGreaterThan(0);

    const security = {
      csp_mode: 'off',
      csp_style_mode: 'report_only',
      csp_style_level: 'strict',
      csp_extra_style_hosts: ['fonts.example.com'],
    };
    const body = dataBindingEngine.evaluateExpression(
      String(save[0].params.body).trim().replace(/^\{\{|\}\}$/g, ''),
      { _global: { activeSettingsTab: 'security' }, query: {}, _local: { form: { security, _meta: { csp: {} } } } } as any,
    ) as AnyJson;

    expect(body._tab).toBe('security');
    expect(body.security).toMatchObject({
      csp_style_mode: 'report_only',
      csp_style_level: 'strict',
      csp_extra_style_hosts: ['fonts.example.com'],
    });
    // 응답 메타는 저장 본문에 실리지 않는다
    expect(body._meta).toBeUndefined();
  });
});

// ──────────────────────────────────────────────────────────────────────────────
// (d) 언어 키
// ──────────────────────────────────────────────────────────────────────────────

describe('스타일 정책 언어 키', () => {
  /**
   * @scenario case=style_settings_screen
   * @effects csp_style_lang_keys_complete
   */
  it.each([['ko', koAdmin], ['en', enAdmin]])('%s 에 스타일 키 18개가 모두 있다', (_locale, admin) => {
    const style = (admin as AnyJson).settings.security.csp.style;

    expect(Object.keys(style).sort()).toEqual([...STYLE_LANG_KEYS].sort());
    for (const key of STYLE_LANG_KEYS) {
      expect(typeof style[key], `${key} 가 문자열이 아니다`).toBe('string');
      expect(style[key].length, `${key} 가 비어 있다`).toBeGreaterThan(0);
    }
  });

  /** @effects csp_style_lang_keys_complete */
  it('조각이 참조하는 스타일 키는 모두 정의돼 있다', () => {
    const referenced = new Set(
      Array.from(JSON.stringify(cspPartial).matchAll(/admin\.settings\.security\.csp\.style\.([a-z_]+)/g)).map((m) => m[1]),
    );

    expect(referenced.size).toBeGreaterThan(0);
    for (const key of referenced) {
      expect(STYLE_LANG_KEYS, `정의되지 않은 키 ${key}`).toContain(key);
    }
  });

  /** @effects csp_style_lang_keys_complete */
  it('.env 잠금 문구는 스타일 전용 환경 변수 이름을 쓴다', () => {
    expect(koAdmin.settings.security.csp.style.override_notice).toContain('G7_CSP_STYLE_MODE={{mode}}');
    expect(enAdmin.settings.security.csp.style.override_notice).toContain('G7_CSP_STYLE_MODE={{mode}}');
  });

  // 「깨지는 화면」 문구는 강도별 강제 모드 브라우저 실측 결과로 채운다. 자리표시자가 남아 있으면 미완료다.
  /** @effects csp_style_lang_keys_complete */
  it('ko·en 경고 문구에 자리표시자({{KNOWN_)가 남아 있지 않다', () => {
    expect(JSON.stringify(koAdmin.settings.security.csp)).not.toContain('{{KNOWN_');
    expect(JSON.stringify(enAdmin.settings.security.csp)).not.toContain('{{KNOWN_');
  });
});
