/**
 * @file memberTabHarness.tsx
 * @description 환경설정 「회원」 탭 레이아웃 테스트가 함께 쓰는 보조 도구
 *
 * 레이아웃 JSON 은 파일에서 그대로 읽는다(사본 금지). 회원 탭 조각은 `partial` 참조로 서로를 끌어오므로,
 * 여기서 참조를 실제 조각으로 풀어 한 트리로 만든다. 요청은 가짜 fetch 가 받아 주소와 메서드로 갈라 답한다.
 */

import React from 'react';
import * as fs from 'fs';
import * as path from 'path';
import { vi } from 'vitest';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

export type Json = Record<string, any>;

const LAYOUTS_DIR = path.resolve(__dirname, '../../../layouts');
const LANG_DIR = path.resolve(__dirname, '../../../lang/partial');

/** 레이아웃 JSON 을 파일에서 읽는다. */
export function loadLayout(relative: string): Json {
  return JSON.parse(fs.readFileSync(path.join(LAYOUTS_DIR, relative), 'utf-8'));
}

/** `partial` 참조 노드를 실제 조각으로 바꿔 한 트리로 만든다(설치가 하는 인라인과 같은 모양). */
export function resolveIncludes(node: any): any {
  if (Array.isArray(node)) return node.map(resolveIncludes);
  if (!node || typeof node !== 'object') return node;
  if (typeof node.partial === 'string' && !node.name) {
    return resolveIncludes(loadLayout(node.partial));
  }
  return Object.fromEntries(Object.entries(node).map(([k, v]) => [k, resolveIncludes(v)]));
}

/** 조각 하나를 본 레이아웃의 계산값과 함께 렌더 가능한 레이아웃으로 감싼다. */
export function buildLayout(partialFile: string, extra: Json = {}): Json {
  const settings = loadLayout('admin_settings.json');

  return {
    version: '1.0.0',
    layout_name: 'test_member_tab',
    computed: settings.computed,
    components: [resolveIncludes(loadLayout(`partials/admin_settings/${partialFile}`))],
    ...extra,
  };
}

/** 한국어 번역 묶음(관리자, 공용). 식 안의 `$t()` 와 `$t:` 가 실제 문장으로 풀린다. */
export function koTranslations(): Json {
  const read = (name: string) => JSON.parse(fs.readFileSync(path.join(LANG_DIR, 'ko', name), 'utf-8'));

  return { admin: read('admin.json'), common: read('common.json') };
}

/** 회원 항목 표본 한 건을 만든다(FieldDefinitionResource 모양). */
export function field(overrides: Json): Json {
  return {
    field_set: 'core.user',
    source_type: 'core',
    source_identifier: 'core',
    is_custom: false,
    type: 'text',
    type_label: '한 줄 글',
    available: true,
    storage: 'column',
    name: { ko: overrides.label ?? overrides.key },
    description: {},
    placeholder: {},
    options: [],
    constraints: {},
    enabled: true,
    required: false,
    show_on_signup: true,
    edit_scope: 'self',
    require_existing_members: false,
    public_profile: false,
    admin_list_column: false,
    admin_searchable: false,
    display_name_usable: false,
    idv_claim: null,
    idv_lock: false,
    locks: {},
    ui: {},
    supports: { signup: true, unique: false, search: true, list_column: true, display_name: false, options: false },
    user_overrides: [],
    ...overrides,
  };
}

export const NICKNAME_LOCK = '표시 이름이 닉네임을 쓰고 있어 사용과 필수가 고정됩니다.';

/** 표본 항목 여섯 건. 기본, 잠긴 기본, 선택 기본, 확장 선언, 운영자 추가, 유형 사용 불가. */
export function sampleFields(): Json[] {
  return [
    field({ id: 1, key: 'name', label: '이름', required: true, sort_order: 10 }),
    field({ id: 2, key: 'nickname', label: '닉네임', required: true, sort_order: 20, locks: { enabled: NICKNAME_LOCK, required: NICKNAME_LOCK } }),
    field({ id: 3, key: 'mobile', label: '휴대폰', type: 'phone', type_label: '전화번호', sort_order: 30 }),
    field({ id: 4, key: 'preferred_currency', label: '결제 통화', type: 'choice', type_label: '하나 선택', source_type: 'module', source_identifier: 'sirsoft-ecommerce', sort_order: 40 }),
    field({
      id: 5,
      key: 'job',
      label: '직업',
      type: 'choice',
      type_label: '하나 선택',
      source_type: 'custom',
      source_identifier: 'custom',
      is_custom: true,
      storage: 'value',
      sort_order: 50,
      options: [
        { value: 'student', label: { ko: '학생' } },
        { value: 'worker', label: { ko: '직장인' } },
      ],
      supports: { signup: true, unique: false, search: true, list_column: true, display_name: false, options: true },
    }),
    field({ id: 6, key: 'color', label: '색상', type: 'acme.color', type_label: 'acme.color', available: false, source_type: 'custom', source_identifier: 'custom', is_custom: true, storage: 'value', sort_order: 60 }),
  ];
}

/** 표본 유형 목록(코어 넷 + 꺼진 확장 유형 하나). */
export function sampleTypes(): Json[] {
  const type = (key: string, label: string, category: string, extra: Json = {}) => ({
    key,
    label,
    description: `${label} 설명`,
    category,
    renderer: 'builtin',
    owner_type: 'core',
    owner: 'core',
    available: true,
    ...extra,
  });

  return [
    type('text', '한 줄 글', 'text'),
    type('number', '숫자', 'number'),
    type('choice', '하나 선택', 'choice'),
    type('multi_choice', '여러 개 선택', 'choice'),
    type('file', '파일', 'file', { renderer: 'file' }),
    type('acme.color', '색상', 'extension', { renderer: 'extension', owner_type: 'plugin', owner: 'acme-color', available: false }),
  ];
}

/** 항목 목록 응답(`memberFieldDefs`)을 만든다. */
export function fieldDefs(overrides: Json = {}): Json {
  return {
    data: {
      set: { key: 'core.user', label: '회원 항목', owner_type: 'core', owner: 'core', allows_custom: true, surfaces: ['signup'] },
      fields: sampleFields(),
      fields_version: 'v1',
      types: sampleTypes(),
      abilities: { can_update: true },
      notices: { signup_layout_modified: false },
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
 * 쌓인 토스트를 화면에 보이는 문장으로 돌려준다.
 *
 * 고정 문구(`$t:키`)는 엔진이 키째로 쌓고 토스트 컴포넌트가 그릴 때 번역한다. 실행 경로에 따라 이미 번역돼 쌓이기도 하므로
 * 여기서 키를 번역해 한 모양으로 맞춘다.
 *
 * @param toasts `getToasts()` 결과
 * @returns 번역된 토스트 목록
 */
export function shownToasts(toasts: Array<{ type: string; message: string }>): Array<{ type: string; message: string }> {
  const dict = koTranslations();
  const translate = (key: string): string => {
    const value = key.split('.').reduce<any>((node, part) => (node && typeof node === 'object' ? node[part] : undefined), dict);

    return typeof value === 'string' ? value : `$t:${key}`;
  };

  return toasts.map((toast) => ({ type: toast.type, message: toast.message.startsWith('$t:') ? translate(toast.message.slice(3)) : toast.message }));
}

let watched: { getState: () => { _local: any; _global: any } } | null = null;

/**
 * 지금 렌더한 화면을 `settle()` 이 지켜보게 한다(렌더할 때마다 부른다).
 *
 * @param t 레이아웃 테스트 도구
 */
export function watch(t: { getState: () => { _local: any; _global: any } } | null): void {
  watched = t;
}

/**
 * 비동기 액션 사슬(요청, 응답 분기, 상태 쓰기)이 끝나도록 기다린다.
 *
 * 고정 시간만 기다리면 다른 테스트 파일과 함께 돌 때(CPU 가 밀릴 때) 사슬이 덜 끝난 상태를 읽는다. 지켜보는 화면이 있으면
 * 상태가 두 번 연속 같아질 때까지(최대 약 1.5초) 기다린다.
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

const DOM_KEYS = new Set([
  'id', 'role', 'title', 'type', 'value', 'checked', 'disabled', 'href', 'target', 'placeholder', 'name', 'min', 'max', 'step', 'rows',
  'colSpan', 'maxLength', 'tabIndex', 'htmlFor',
]);

/** DOM 에 실어도 되는 props 만 고른다(data-*, aria-*, 이벤트 핸들러, 표준 속성). */
function domProps(props: Json): Json {
  return Object.fromEntries(
    Object.entries(props).filter(([k]) => k.startsWith('data-') || k.startsWith('aria-') || /^on[A-Z]/.test(k) || DOM_KEYS.has(k)),
  );
}

/** 태그 하나를 그대로 그리는 시험 컴포넌트를 만든다. */
function passthrough(Tag: string): React.FC<any> {
  return ({ className, children, text, ...rest }) => React.createElement(Tag, { className, ...domProps(rest) }, children ?? text);
}

const VoidInput: React.FC<any> = ({ className, value, onChange, children: _children, text: _text, ...rest }) => (
  <input className={className} {...domProps(rest)} value={value ?? ''} onChange={(e) => onChange?.(e)} />
);

const TestTextarea: React.FC<any> = ({ className, value, onChange, children: _children, text: _text, ...rest }) => (
  <textarea className={className} {...domProps(rest)} value={value ?? ''} onChange={(e) => onChange?.(e)} />
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

const TestCheckbox: React.FC<any> = ({ className, checked, onChange, children: _children, text: _text, ...rest }) => (
  <input type="checkbox" className={className} {...domProps(rest)} checked={!!checked} onChange={(e) => onChange?.(e)} />
);

/** 스위치 — 실물과 같이 잠겼거나 진행 중이면 눌리지 않고, 누르면 다음 값을 change 로 알린다. */
const TestToggle: React.FC<any> = ({ checked, onChange, disabled, pending, ariaLabel, awaitConfirmation, 'data-testid': testId }) => (
  <button
    type="button"
    role="switch"
    aria-checked={!!checked}
    aria-label={ariaLabel}
    aria-busy={pending ? true : undefined}
    disabled={!!disabled || !!pending}
    data-testid={testId}
    data-await-confirmation={awaitConfirmation ? 'true' : 'false'}
    onClick={() => onChange?.({ target: { checked: !checked } })}
  />
);

const TestTagInput: React.FC<any> = ({ name, value, disabled, onChange, 'data-testid': testId }) => (
  <div data-tag-input={name ?? ''} data-testid={testId} data-disabled={disabled ? 'true' : 'false'}>
    {(Array.isArray(value) ? value : []).map((chip: string) => (
      <span key={chip} data-chip="">
        {chip}
      </span>
    ))}
    <button type="button" data-tag-add="" onClick={() => onChange?.({ target: { value: [...(Array.isArray(value) ? value : []), 'png'] } })} />
  </div>
);

/** 다국어 입력 — 사이트 기본 언어(ko) 칸 하나만 그리고, 고치면 실물처럼 로케일 객체를 change 로 알린다. */
const TestMultilingualInput: React.FC<any> = ({ value, onChange, disabled, ariaLabel, error, 'data-testid': testId }) => (
  <span>
    <input
      type="text"
      aria-label={ariaLabel}
      data-testid={testId}
      disabled={!!disabled}
      value={value?.ko ?? ''}
      onChange={(e) => onChange?.({ target: { value: { ...(value ?? {}), ko: e.target.value } } })}
    />
    {error ? <span data-error="">{error}</span> : null}
  </span>
);

const TestRadioGroup: React.FC<any> = ({ name, value, options, onChange, disabled, 'data-testid': testId }) => (
  <div role="radiogroup" data-testid={testId ?? `radio-${name}`} data-value={value ?? ''}>
    {(Array.isArray(options) ? options : []).map((o: any) => (
      <label key={o.value}>
        <input type="radio" name={name} value={o.value} disabled={!!disabled} checked={String(value) === String(o.value)} onChange={(e) => onChange?.(e)} />
        {o.label}
      </label>
    ))}
  </div>
);

const TestIcon: React.FC<any> = ({ name, className }) => <i className={className} data-icon={name} />;
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

/**
 * 회원 탭 레이아웃 테스트용 컴포넌트 레지스트리를 만든다.
 *
 * @param extra 더 얹거나 바꿀 컴포넌트(예: 실물 Modal)
 * @returns 레지스트리
 */
export function setupRegistry(extra: Record<string, { component: React.FC<any>; metadata: Json }> = {}): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const basic = (name: string, component: React.FC<any>) => ({ component, metadata: { name, type: 'basic' } });
  const composite = (name: string, component: React.FC<any>) => ({ component, metadata: { name, type: 'composite' } });
  const tags: Record<string, string> = {
    Div: 'div', P: 'p', Span: 'span', H3: 'h3', H4: 'h4', Label: 'label', Button: 'button', A: 'a', Ul: 'ul', Li: 'li',
    Table: 'table', Thead: 'thead', Tbody: 'tbody', Tr: 'tr', Th: 'th', Td: 'td',
  };

  (registry as any).registry = {
    ...Object.fromEntries(Object.entries(tags).map(([name, tag]) => [name, basic(name, passthrough(tag))])),
    Input: basic('Input', VoidInput),
    Textarea: basic('Textarea', TestTextarea),
    Select: basic('Select', TestSelect),
    Checkbox: basic('Checkbox', TestCheckbox),
    Icon: basic('Icon', TestIcon),
    Fragment: basic('Fragment', TestFragment),
    Toggle: composite('Toggle', TestToggle),
    TagInput: composite('TagInput', TestTagInput),
    MultilingualInput: composite('MultilingualInput', TestMultilingualInput),
    RadioGroup: composite('RadioGroup', TestRadioGroup),
    ...extra,
  };

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
