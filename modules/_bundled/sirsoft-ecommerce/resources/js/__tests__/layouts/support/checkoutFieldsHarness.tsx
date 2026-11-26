/**
 * @file checkoutFieldsHarness.tsx
 * @description 이커머스 관리자 화면(주문서 항목 표, 기능별 표시 이름, 실명 보조 줄) 레이아웃 테스트가 함께 쓰는 보조 도구
 *
 * 레이아웃 JSON 과 번역은 파일에서 그대로 읽는다(사본 금지). 조각의 `partial` 참조는 실제 조각으로 풀어 한 트리로 만들고,
 * 요청은 가짜 fetch 가 받아 주소와 메서드로 갈라 답한다. 컴포넌트는 태그를 그대로 그리는 시험용 부품이다
 * (스위치만은 실물처럼 잠겼거나 진행 중이면 눌리지 않게 한다).
 */

import React from 'react';
import * as fs from 'fs';
import * as path from 'path';
import { vi } from 'vitest';
import { createMockComponentRegistry, type MockComponentRegistry } from '@core/template-engine/__tests__/utils/layoutTestUtils';

export type Json = Record<string, any>;

const MODULE_ROOT = path.resolve(__dirname, '../../../../..');
const LAYOUTS_DIR = path.join(MODULE_ROOT, 'resources/layouts/admin');
const LANG_DIR = path.join(MODULE_ROOT, 'resources/lang');
const SETTINGS_PARTIALS = 'partials/admin_ecommerce_settings';

/** 저장소 루트(artisan 이 있는 폴더)를 찾는다. */
function projectRoot(): string {
  let dir = MODULE_ROOT;
  while (dir !== path.dirname(dir)) {
    if (fs.existsSync(path.join(dir, 'artisan'))) return dir;
    dir = path.dirname(dir);
  }

  return dir;
}

/** 관리자 레이아웃 JSON 을 파일에서 읽는다(`resources/layouts/admin` 기준 경로). */
export function loadLayout(relative: string): Json {
  return JSON.parse(fs.readFileSync(path.join(LAYOUTS_DIR, relative), 'utf-8'));
}

/** 설정 화면 조각을 읽는다. */
export function loadSettingsPartial(file: string): Json {
  return loadLayout(`${SETTINGS_PARTIALS}/${file}`);
}

/** `partial` 참조 노드를 실제 조각으로 바꿔 한 트리로 만든다(설치가 하는 인라인과 같은 모양). */
export function resolveIncludes(node: any, baseDir: string = SETTINGS_PARTIALS): any {
  if (Array.isArray(node)) return node.map((child) => resolveIncludes(child, baseDir));
  if (!node || typeof node !== 'object') return node;
  if (typeof node.partial === 'string' && !node.name) {
    return resolveIncludes(loadLayout(`${baseDir}/${node.partial}`), baseDir);
  }

  return Object.fromEntries(Object.entries(node).map(([k, v]) => [k, resolveIncludes(v, baseDir)]));
}

/** 노드 묶음을 설정 레이아웃의 계산값과 함께 렌더 가능한 레이아웃으로 감싼다. */
export function buildSettingsLayout(components: Json[], extra: Json = {}): Json {
  const settings = loadLayout('admin_ecommerce_settings.json');

  return {
    version: '1.0.0',
    layout_name: 'test_ecommerce_issue490',
    computed: settings.computed,
    components: components.map((c) => resolveIncludes(c)),
    ...extra,
  };
}

/** 언어 파일의 `$partial` 참조를 풀어 한 객체로 만든다. */
function resolveLangPartials(node: any): any {
  if (Array.isArray(node)) return node.map(resolveLangPartials);
  if (!node || typeof node !== 'object') return node;
  if (typeof node.$partial === 'string') {
    return resolveLangPartials(JSON.parse(fs.readFileSync(path.join(LANG_DIR, node.$partial), 'utf-8')));
  }

  return Object.fromEntries(Object.entries(node).map(([k, v]) => [k, resolveLangPartials(v)]));
}

/**
 * 번역 묶음을 만든다. 모듈 번역은 `sirsoft-ecommerce` 이름 아래, 관리자 템플릿의 공용 번역(`common.*`)은 최상위에 둔다.
 *
 * @param locale 로케일(ko, en)
 * @returns 식 안의 `$t()` 와 `$t:` 가 실제 문장으로 풀리는 번역 객체
 */
export function translations(locale: 'ko' | 'en' = 'ko'): Json {
  const moduleLang = resolveLangPartials(JSON.parse(fs.readFileSync(path.join(LANG_DIR, `${locale}.json`), 'utf-8')));
  const common = JSON.parse(
    fs.readFileSync(path.join(projectRoot(), 'templates/_bundled/sirsoft-admin_basic/lang/partial', locale, 'common.json'), 'utf-8'),
  );

  return { 'sirsoft-ecommerce': moduleLang, common };
}

/** 번역 키 하나를 한국어 문장으로 푼다(없으면 `$t:키`). */
export function ko(key: string): string {
  const value = key.split('.').reduce<any>((node, part) => (node && typeof node === 'object' ? node[part] : undefined), {
    ...translations('ko'),
  });

  return typeof value === 'string' ? value : `$t:${key}`;
}

/** 주문서 항목 표본 한 건을 만든다(FieldDefinitionResource 모양 + `source_label`). */
export function field(overrides: Json): Json {
  return {
    field_set: 'sirsoft-ecommerce.order_contact',
    source_type: 'module',
    source_identifier: 'sirsoft-ecommerce',
    source_label: '이커머스',
    is_custom: false,
    type: 'text',
    type_label: '한 줄 글',
    available: true,
    storage: 'handler',
    name: { ko: overrides.label ?? overrides.key },
    description: {},
    placeholder: {},
    options: [],
    constraints: {},
    enabled: true,
    required: false,
    locks: {},
    ui: { group: 'shipping' },
    user_overrides: [],
    ...overrides,
  };
}

export const NAME_LOCK = '주문자 이름은 주문에 꼭 필요해 끌 수 없습니다.';
export const GIFT_LOCK = '선물 포장은 선택 항목으로만 쓸 수 있습니다.';

/**
 * 표본 항목 여덟 건(서버 순서). 이커머스 선언(잠긴 것과 풀린 것), 다른 확장 기여(필수 잠금), 꺼진 확장 기여(사용 불가),
 * 운영자 추가(한 줄 글, 하나 선택)를 섞고, 구획은 일부러 섞어 둔다(화면이 주문자를 앞으로 모으는지 보려고).
 */
export function sampleFields(): Json[] {
  return [
    field({ id: 11, key: 'recipient_name', label: '수령인 이름', required: true, locks: { enabled: NAME_LOCK, required: NAME_LOCK } }),
    field({ id: 12, key: 'orderer_name', label: '주문자 이름', required: true, ui: { group: 'orderer' }, locks: { enabled: NAME_LOCK, required: NAME_LOCK } }),
    field({ id: 13, key: 'shipping_memo', label: '배송 메모' }),
    field({ id: 14, key: 'orderer_email', label: '주문자 이메일', type: 'email', type_label: '이메일', ui: { group: 'orderer' }, description: { ko: '비회원은 필수', en: 'Required for guests' } }),
    field({ id: 15, key: 'gift_wrap', label: '선물 포장', type: 'boolean', type_label: '예/아니오', source_type: 'plugin', source_identifier: 'acme-gift', source_label: '선물 포장 플러그인', locks: { required: GIFT_LOCK } }),
    field({ id: 16, key: 'tax_invoice', label: '세금계산서', type: 'acme.tax', type_label: 'acme.tax', available: false, source_type: 'plugin', source_identifier: 'acme-tax', source_label: '세금계산서 플러그인', ui: { group: 'orderer' } }),
    field({ id: 17, key: 'door_code', label: '현관 비밀번호', source_type: 'custom', source_identifier: 'custom', source_label: null, is_custom: true, storage: 'value', name: { ko: '현관 비밀번호', en: 'Door code' } }),
    field({
      id: 18,
      key: 'delivery_time',
      label: '희망 배송 시간',
      type: 'choice',
      type_label: '하나 선택',
      source_type: 'custom',
      source_identifier: 'custom',
      source_label: null,
      is_custom: true,
      storage: 'value',
      name: { ko: '희망 배송 시간' },
      description: { ko: '가능한 시간대를 골라 주세요.' },
      options: [
        { value: 'am', label: { ko: '오전' } },
        { value: 'pm', label: { ko: '오후' } },
      ],
    }),
  ];
}

/** 표본 유형 목록(코어 셋 + 꺼진 확장 유형 하나). */
export function sampleTypes(): Json[] {
  const type = (key: string, label: string, extra: Json = {}) => ({ key, label, description: `${label} 설명`, category: 'text', renderer: 'builtin', owner_type: 'core', owner: 'core', available: true, ...extra });

  return [type('text', '한 줄 글'), type('choice', '하나 선택', { category: 'choice' }), type('multi_choice', '여러 개 선택', { category: 'choice' }), type('acme.tax', '세금계산서', { category: 'extension', renderer: 'extension', owner_type: 'plugin', owner: 'acme-tax', available: false })];
}

/** 항목 목록 응답(`checkoutFieldDefs`)을 만든다. */
export function fieldDefs(overrides: Json = {}): Json {
  return {
    data: {
      set: { key: 'sirsoft-ecommerce.order_contact', label: '주문서 항목', owner_type: 'module', owner: 'sirsoft-ecommerce', allows_custom: true, surfaces: ['checkout'] },
      fields: sampleFields(),
      fields_version: 'v1',
      types: sampleTypes(),
      abilities: { can_update: true },
      ...overrides,
    },
  };
}

export interface RecordedRequest {
  method: string;
  url: string;
  body: any;
}

export interface FetchRoute {
  method: string;
  /** 주소 끝이 이 글자로 끝나면(쿼리 제외) 맞는 것으로 본다 */
  endsWith: string;
  status?: number;
  body: Json;
}

/**
 * 가짜 fetch 를 전역에 건다. 받은 요청을 기록하고, 등록한 경로에 맞는 답을 돌려준다(없으면 빈 성공).
 *
 * @param routes 응답 경로 목록(앞에 둔 것이 이긴다)
 * @returns 기록 목록
 */
export function stubFetch(routes: FetchRoute[] = []): RecordedRequest[] {
  const requests: RecordedRequest[] = [];

  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: any, init: any = {}) => {
      const url = String(typeof input === 'string' ? input : input?.url ?? '');
      const method = String(init?.method ?? 'GET').toUpperCase();
      let body: any = null;
      try {
        body = typeof init?.body === 'string' ? JSON.parse(init.body) : init?.body ?? null;
      } catch {
        body = init?.body;
      }
      // 쓰기 요청 앞에 엔진이 먼저 받는 CSRF 쿠키 요청은 화면 배선이 아니므로 적지 않는다
      if (!url.includes('/sanctum/csrf-cookie')) {
        requests.push({ method, url, body });
      }

      const pathOnly = url.split('?')[0];
      const route = routes.find((r) => r.method === method && pathOnly.endsWith(r.endsWith));
      const status = route?.status ?? 200;
      const payload = route?.body ?? { success: true, data: {} };

      return {
        ok: status >= 200 && status < 300,
        status,
        statusText: String(status),
        headers: new Headers({ 'Content-Type': 'application/json' }),
        json: async () => payload,
        text: async () => JSON.stringify(payload),
        clone() {
          return this;
        },
      };
    }),
  );

  return requests;
}

/** 재조회 요청을 받아 적는 가짜 `G7Core.dataSource.refetch` 를 건다(렌더 뒤에 불러야 한다). */
export function stubRefetch(): string[] {
  const refetched: string[] = [];
  const g7 = (window as any).G7Core ?? ((window as any).G7Core = {});
  g7.dataSource = {
    ...(g7.dataSource ?? {}),
    refetch: vi.fn(async (id: string) => {
      refetched.push(id);
    }),
  };

  return refetched;
}

/**
 * 쌓인 토스트를 화면에 보이는 문장으로 돌려준다. 고정 문구(`$t:키`)는 엔진이 키째로 쌓기도 하므로 여기서 번역해 한 모양으로 맞춘다.
 *
 * @param toasts `getToasts()` 결과
 * @returns 번역된 토스트 목록
 */
export function shownToasts(toasts: Array<{ type: string; message: string }>): Array<{ type: string; message: string }> {
  return toasts.map((toast) => ({ type: toast.type, message: toast.message.startsWith('$t:') ? ko(toast.message.slice(3)) : toast.message }));
}

let watched: { getState: () => { _local: any; _global: any } } | null = null;

/** 지금 렌더한 화면을 `settle()` 이 지켜보게 한다(렌더할 때마다 부른다). */
export function watch(t: { getState: () => { _local: any; _global: any } } | null): void {
  watched = t;
}

/**
 * 비동기 액션 사슬(요청, 응답 분기, 상태 쓰기)이 끝나도록 기다린다. 지켜보는 화면이 있으면 상태가 두 번 연속 같아질 때까지 기다린다.
 *
 * @param ms 한 번 기다리는 시간
 */
export async function settle(ms = 20): Promise<void> {
  const tick = () => new Promise((resolve) => setTimeout(resolve, ms));
  await tick();
  if (!watched) return;

  const snapshot = () => {
    const state = watched!.getState();

    return JSON.stringify([state._local, state._global]);
  };
  let previous = snapshot();
  let stable = 0;
  for (let i = 0; i < 50 && stable < 2; i += 1) {
    await tick();
    const current = snapshot();
    stable = current === previous ? stable + 1 : 0;
    previous = current;
  }
}

const DOM_KEYS = new Set(['id', 'role', 'title', 'type', 'value', 'checked', 'disabled', 'href', 'target', 'placeholder', 'name', 'min', 'max', 'step', 'colSpan', 'maxLength']);

/** DOM 에 실어도 되는 props 만 고른다(data-*, aria-*, 이벤트 핸들러, 표준 속성). */
function domProps(props: Json): Json {
  return Object.fromEntries(Object.entries(props).filter(([k]) => k.startsWith('data-') || k.startsWith('aria-') || /^on[A-Z]/.test(k) || DOM_KEYS.has(k)));
}

/** 태그 하나를 그대로 그리는 시험 컴포넌트를 만든다. */
function passthrough(Tag: string): React.FC<any> {
  return ({ className, children, text, ...rest }) => React.createElement(Tag, { className, ...domProps(rest) }, children ?? text);
}

const VoidInput: React.FC<any> = ({ className, value, onChange, children: _children, text: _text, ...rest }) => (
  <input className={className} {...domProps(rest)} value={value ?? ''} onChange={(e) => onChange?.(e)} />
);

const TestSelect: React.FC<any> = ({ className, value, onChange, options, children: _children, text: _text, ...rest }) => (
  <select className={className} {...domProps(rest)} value={value ?? ''} onChange={(e) => onChange?.(e)}>
    {(Array.isArray(options) ? options : []).map((o: any) => (
      <option key={String(o.value)} value={o.value}>
        {o.label}
      </option>
    ))}
  </select>
);

/**
 * 스위치 — 실물과 같이 DOM id 는 바깥 상자에 달고 그 안에 `role="switch"` 를 둔다. 잠겼거나 진행 중이면 눌리지 않고,
 * 누르면 다음 값을 change 로 알린다.
 */
const TestToggle: React.FC<any> = ({ id, name, checked, onChange, disabled, pending, ariaLabel, awaitConfirmation }) => (
  <div className="toggle-container" id={id}>
    <button
      type="button"
      role="switch"
      name={name}
      aria-checked={!!checked}
      aria-label={ariaLabel}
      aria-busy={pending ? true : undefined}
      disabled={!!disabled || !!pending}
      data-await-confirmation={awaitConfirmation ? 'true' : 'false'}
      onClick={() => onChange?.({ target: { checked: !checked } })}
    />
  </div>
);

/** 다국어 입력 — 사이트 기본 언어(ko) 칸 하나만 그리고, 고치면 실물처럼 로케일 객체를 change 로 알린다. */
const TestMultilingualInput: React.FC<any> = ({ id, value, onChange, disabled, ariaLabel, error, 'data-testid': testId }) => (
  <span>
    <input
      type="text"
      id={id}
      aria-label={ariaLabel}
      data-testid={testId}
      disabled={!!disabled}
      value={value?.ko ?? ''}
      onChange={(e) => onChange?.({ target: { value: { ...(value ?? {}), ko: e.target.value } } })}
    />
    {error ? <span data-error="">{error}</span> : null}
  </span>
);

const TestIcon: React.FC<any> = ({ name, className }) => <i className={className} data-icon={name} />;

/**
 * 레이아웃 테스트용 컴포넌트 레지스트리를 만든다.
 *
 * @returns 레지스트리
 */
export function setupRegistry(): MockComponentRegistry {
  const registry = createMockComponentRegistry();
  const tags: Record<string, string> = {
    Div: 'div', P: 'p', Span: 'span', H3: 'h3', H4: 'h4', Label: 'label', Button: 'button', A: 'a', Ul: 'ul', Li: 'li',
    Table: 'table', Thead: 'thead', Tbody: 'tbody', Tr: 'tr', Th: 'th', Td: 'td',
  };

  Object.entries(tags).forEach(([name, tag]) => registry.register('basic', name, passthrough(tag)));
  registry.register('basic', 'Input', VoidInput);
  registry.register('basic', 'Select', TestSelect);
  registry.register('basic', 'Icon', TestIcon);
  registry.register('composite', 'Toggle', TestToggle);
  registry.register('composite', 'MultilingualInput', TestMultilingualInput);
  // 렌더러가 최상위 묶음을 그릴 때 쓰는 틀 — 없으면 화면이 통째로 비어 나온다
  registry.register('layout', 'Fragment', ({ children }: any) => React.createElement(React.Fragment, null, children));

  return registry;
}

/** 노드 트리를 돌며 조건에 맞는 노드를 모은다. */
export function collect(node: any, match: (n: any) => boolean, out: any[] = []): any[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, match, out));
    return out;
  }
  if (node && typeof node === 'object') {
    if (match(node)) out.push(node);
    Object.values(node).forEach((value) => collect(value, match, out));
  }
  return out;
}

/** id 로 노드 하나를 찾는다(없으면 실패). */
export function nodeById(tree: any, id: string): Json {
  const found = collect(tree, (n) => n.id === id);
  if (found.length === 0) throw new Error(`노드를 찾지 못했다: ${id}`);

  return found[0];
}

/** 요청을 `METHOD 주소` 로 줄인다. */
export const lines = (requests: Array<{ method: string; url: string }>) => requests.map((r) => `${r.method} ${r.url}`);
