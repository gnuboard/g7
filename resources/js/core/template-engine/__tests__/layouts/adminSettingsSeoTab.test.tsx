/**
 * @file adminSettingsSeoTab.test.tsx
 * @description 환경설정 SEO 탭 레이아웃 렌더링 테스트
 *
 * 테스트 대상:
 * - templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_seo.g7.html
 *
 * 검증 항목:
 * 1. SEO 탭 UI 구조 — TagInput(봇 UA), Toggle(캐시), NumberInput(TTL) 존재
 * 2. 봇 UA 관리 TagInput creatable 설정 — creatable=true
 * 3. 캐시 설정 Toggle 바인딩 — seo.cache_enabled 바인딩 확인
 * 4. Sitemap 설정 섹션 존재 — sitemap_enabled Toggle, 생성 주기 링크 블록 존재(픽스처 ↔ 실제 _tab_seo.json 동기 대조 포함)
 * 5. 생성 주기·시각 입력 칸 없음 — 7.2.0(D10) 부터 주기는 코어 스케줄 행(core/sitemap-generate)이 원천이다
 * 6. 스케줄 관리 링크 — 소유=코어 + 식별자 검색 쿼리로 그 행을 걸러 연다, 저장 폼 값과 무관하게 보인다
 * 7. sitemap_cache_ttl NumberInput 존재 — seo.sitemap_cache_ttl 바인딩 확인
 * 8. 저장 버튼 apiCall params에 _tab=seo — body에 _tab, seo 데이터 포함
 *
 * 시나리오 매니페스트: settings-schedule-links (tests/scenarios — test_files 귀속)
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '../utils/layoutTestUtils';
import { ComponentRegistry } from '../../ComponentRegistry';

// ==============================
// 테스트용 컴포넌트 정의
// ==============================

const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestSpan: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <span className={className}>{children || text}</span>
);

const TestP: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <p className={className}>{children || text}</p>
);

const TestH3: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <h3 className={className}>{children || text}</h3>
);

const TestLabel: React.FC<{
  htmlFor?: string;
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ htmlFor, className, children, text }) => (
  <label htmlFor={htmlFor} className={className}>
    {children || text}
  </label>
);

const TestInput: React.FC<{
  type?: string;
  name?: string;
  placeholder?: string;
  className?: string;
  min?: number;
  max?: number;
  value?: string;
  onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
  'data-testid'?: string;
}> = ({ type, name, placeholder, className, min, max, value, onChange, 'data-testid': testId }) => (
  <input
    type={type}
    name={name}
    placeholder={placeholder}
    className={className}
    min={min}
    max={max}
    value={value}
    onChange={onChange}
    data-testid={testId || `input-${name}`}
  />
);

const TestTextarea: React.FC<{
  name?: string;
  rows?: number;
  maxLength?: number;
  placeholder?: string;
  className?: string;
  value?: string;
  onChange?: (e: React.ChangeEvent<HTMLTextAreaElement>) => void;
}> = ({ name, rows, maxLength, placeholder, className, value, onChange }) => (
  <textarea
    name={name}
    rows={rows}
    maxLength={maxLength}
    placeholder={placeholder}
    className={className}
    value={value}
    onChange={onChange}
    data-testid={`textarea-${name}`}
  />
);

const TestToggle: React.FC<{
  name?: string;
  size?: string;
  checked?: boolean;
  onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
}> = ({ name, size, checked, onChange }) => (
  <div data-testid={`toggle-${name}`} data-name={name} data-size={size}>
    <input type="checkbox" name={name} checked={checked} onChange={onChange} />
  </div>
);

const TestTagInput: React.FC<{
  name?: string;
  creatable?: boolean;
  placeholder?: string;
  className?: string;
  defaultVariant?: string;
}> = ({ name, creatable, placeholder, className, defaultVariant }) => (
  <div
    data-testid={`taginput-${name}`}
    data-name={name}
    data-creatable={String(creatable)}
    data-placeholder={placeholder}
    data-variant={defaultVariant}
    className={className}
  >
    TagInput: {name}
  </div>
);

const TestSelect: React.FC<{
  name?: string;
  className?: string;
  options?: Array<{ label: string; value: string }>;
  value?: string;
  onChange?: (e: React.ChangeEvent<HTMLSelectElement>) => void;
}> = ({ name, className, options, value, onChange }) => (
  <div data-testid={`select-${name}`}>
    <select name={name} className={className} value={value} onChange={onChange}>
      {options?.map((opt) => (
        <option key={opt.value} value={opt.value}>{opt.label}</option>
      ))}
    </select>
  </div>
);

const TestFragment: React.FC<{
  children?: React.ReactNode;
}> = ({ children }) => <>{children}</>;

// ==============================
// 레지스트리 설정
// ==============================

const TestA: React.FC<{
  href?: string;
  className?: string;
  children?: React.ReactNode;
  text?: string;
  id?: string;
}> = ({ href, className, children, text, id }) => (
  <a href={href} className={className} id={id} data-testid={id ? 'link-' + id : undefined}>{children || text}</a>
);

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Textarea: { component: TestTextarea, metadata: { name: 'Textarea', type: 'basic' } },
    Toggle: { component: TestToggle, metadata: { name: 'Toggle', type: 'composite' } },
    TagInput: { component: TestTagInput, metadata: { name: 'TagInput', type: 'composite' } },
    Select: { component: TestSelect, metadata: { name: 'Select', type: 'composite' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
    A: { component: TestA, metadata: { name: 'A', type: 'basic' } },
  };

  return registry;
}

// ==============================
// _tab_seo.g7.html 레이아웃 구조 (Partial)
// ==============================

const seoTabPartial: any = {
  meta: { is_partial: true, description: '환경설정 SEO 탭 콘텐츠' },
  id: 'tab_content_seo',
  type: 'basic',
  name: 'Div',
  if: "{{(_global.activeSettingsTab || query.tab || 'general') === 'seo'}}",
  props: { className: 'mt-6 space-y-6' },
  children: [
    {
      id: 'card_meta_settings',
      type: 'basic',
      name: 'Div',
      props: { className: 'bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-6' },
      children: [
        { type: 'basic', name: 'H3', props: { className: 'text-lg font-semibold text-gray-900 dark:text-white mb-1' }, text: '$t:admin.settings.seo.meta_settings' },
        { type: 'basic', name: 'P', props: { className: 'text-sm text-gray-500 dark:text-gray-400 mb-6' }, text: '$t:admin.settings.seo.meta_settings_desc' },
        {
          type: 'basic', name: 'Div', props: { className: 'space-y-4' },
          children: [
            {
              id: 'field_meta_title_suffix', type: 'basic', name: 'Div',
              children: [
                { type: 'basic', name: 'Label', props: { className: 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1' }, text: '$t:admin.settings.seo.meta_title_suffix' },
                { type: 'basic', name: 'Input', props: { type: 'text', name: 'seo.meta_title_suffix', placeholder: ' | 그누보드7 CMS', className: 'w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white text-sm' } },
                { type: 'basic', name: 'P', props: { className: 'text-xs text-gray-500 dark:text-gray-400 mt-1' }, text: '$t:admin.settings.seo.meta_title_suffix_hint' },
              ],
            },
            {
              id: 'field_meta_description', type: 'basic', name: 'Div',
              children: [
                { type: 'basic', name: 'Label', props: { className: 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1' }, text: '$t:admin.settings.seo.meta_description' },
                { type: 'basic', name: 'Textarea', props: { name: 'seo.meta_description', rows: 3, maxLength: 160, placeholder: '$t:admin.settings.seo.meta_description_placeholder', className: 'w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white text-sm resize-none' } },
                { type: 'basic', name: 'P', props: { className: 'text-xs text-gray-500 dark:text-gray-400 mt-1' }, text: '$t:admin.settings.seo.meta_description_hint' },
              ],
            },
            {
              id: 'field_meta_keywords', type: 'basic', name: 'Div',
              children: [
                { type: 'basic', name: 'Label', props: { className: 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1' }, text: '$t:admin.settings.seo.meta_keywords' },
                { type: 'basic', name: 'Input', props: { type: 'text', name: 'seo.meta_keywords', placeholder: 'CMS, G7, 오픈소스', className: 'w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white text-sm' } },
                { type: 'basic', name: 'P', props: { className: 'text-xs text-gray-500 dark:text-gray-400 mt-1' }, text: '$t:admin.settings.seo.meta_keywords_hint' },
              ],
            },
          ],
        },
      ],
    },
    {
      id: 'card_analytics_settings',
      type: 'basic',
      name: 'Div',
      props: { className: 'bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-6' },
      children: [
        { type: 'basic', name: 'H3', props: { className: 'text-lg font-semibold text-gray-900 dark:text-white mb-1' }, text: '$t:admin.settings.seo.analytics_settings' },
        { type: 'basic', name: 'P', props: { className: 'text-sm text-gray-500 dark:text-gray-400 mb-6' }, text: '$t:admin.settings.seo.analytics_settings_desc' },
        {
          type: 'basic', name: 'Div', props: { className: 'space-y-4' },
          children: [
            {
              id: 'field_google_analytics_id', type: 'basic', name: 'Div',
              children: [
                { type: 'basic', name: 'Label', props: { className: 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1' }, text: '$t:admin.settings.seo.google_analytics_id' },
                { type: 'basic', name: 'Input', props: { type: 'text', name: 'seo.google_analytics_id', placeholder: 'G-XXXXXXXXXX', className: 'w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white text-sm' } },
                { type: 'basic', name: 'P', props: { className: 'text-xs text-gray-500 dark:text-gray-400 mt-1' }, text: '$t:admin.settings.seo.google_analytics_id_hint' },
              ],
            },
          ],
        },
      ],
    },
    {
      id: 'card_verification_settings',
      type: 'basic',
      name: 'Div',
      props: { className: 'bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-6' },
      children: [
        { type: 'basic', name: 'H3', props: { className: 'text-lg font-semibold text-gray-900 dark:text-white mb-1' }, text: '$t:admin.settings.seo.verification_settings' },
        { type: 'basic', name: 'P', props: { className: 'text-sm text-gray-500 dark:text-gray-400 mb-6' }, text: '$t:admin.settings.seo.verification_settings_desc' },
        {
          type: 'basic', name: 'Div', props: { className: 'grid grid-cols-1 md:grid-cols-2 gap-4' },
          children: [
            {
              id: 'field_google_site_verification', type: 'basic', name: 'Div',
              children: [
                { type: 'basic', name: 'Label', props: { className: 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1' }, text: '$t:admin.settings.seo.google_site_verification' },
                { type: 'basic', name: 'Input', props: { type: 'text', name: 'seo.google_site_verification', placeholder: 'google-site-verification=XXXX...', className: 'w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white text-sm' } },
              ],
            },
            {
              id: 'field_naver_site_verification', type: 'basic', name: 'Div',
              children: [
                { type: 'basic', name: 'Label', props: { className: 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1' }, text: '$t:admin.settings.seo.naver_site_verification' },
                { type: 'basic', name: 'Input', props: { type: 'text', name: 'seo.naver_site_verification', placeholder: 'naver-site-verification=XXXX...', className: 'w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white text-sm' } },
              ],
            },
          ],
        },
      ],
    },
    {
      id: 'card_bot_settings',
      type: 'basic',
      name: 'Div',
      props: { className: 'bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-6' },
      children: [
        { type: 'basic', name: 'H3', props: { className: 'text-lg font-semibold text-gray-900 dark:text-white mb-1' }, text: '$t:admin.settings.seo.bot_settings' },
        { type: 'basic', name: 'P', props: { className: 'text-sm text-gray-500 dark:text-gray-400 mb-6' }, text: '$t:admin.settings.seo.bot_settings_desc' },
        {
          type: 'basic', name: 'Div', props: { className: 'space-y-4' },
          children: [
            {
              id: 'toggle_bot_detection_enabled', type: 'basic', name: 'Div',
              props: { className: 'flex items-center justify-between py-3 border-b border-gray-200 dark:border-gray-700' },
              children: [
                {
                  type: 'basic', name: 'Div',
                  children: [
                    { type: 'basic', name: 'Span', props: { className: 'text-sm font-medium text-gray-900 dark:text-white' }, text: '$t:admin.settings.seo.bot_detection_enabled' },
                    { type: 'basic', name: 'P', props: { className: 'text-sm text-gray-500 dark:text-gray-400' }, text: '$t:admin.settings.seo.bot_detection_enabled_desc' },
                  ],
                },
                { type: 'composite', name: 'Toggle', props: { name: 'seo.bot_detection_enabled', size: 'md' } },
              ],
            },
            {
              id: 'field_bot_user_agents', type: 'basic', name: 'Div',
              children: [
                { type: 'basic', name: 'Label', props: { className: 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1' }, text: '$t:admin.settings.seo.bot_user_agents' },
                { type: 'composite', name: 'TagInput', props: { name: 'seo.bot_user_agents', creatable: true, placeholder: '$t:admin.settings.seo.bot_user_agents_placeholder', className: 'w-full', defaultVariant: 'blue' } },
                { type: 'basic', name: 'P', props: { className: 'text-xs text-gray-500 dark:text-gray-400 mt-1' }, text: '$t:admin.settings.seo.bot_user_agents_hint' },
              ],
            },
          ],
        },
      ],
    },
    {
      id: 'card_seo_cache_settings',
      type: 'basic',
      name: 'Div',
      props: { className: 'bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-6' },
      children: [
        { type: 'basic', name: 'H3', props: { className: 'text-lg font-semibold text-gray-900 dark:text-white mb-1' }, text: '$t:admin.settings.seo.seo_cache_settings' },
        { type: 'basic', name: 'P', props: { className: 'text-sm text-gray-500 dark:text-gray-400 mb-6' }, text: '$t:admin.settings.seo.seo_cache_settings_desc' },
        {
          type: 'basic', name: 'Div', props: { className: 'space-y-4' },
          children: [
            {
              id: 'toggle_seo_cache_enabled', type: 'basic', name: 'Div',
              props: { className: 'flex items-center justify-between py-3 border-b border-gray-200 dark:border-gray-700' },
              children: [
                {
                  type: 'basic', name: 'Div',
                  children: [
                    { type: 'basic', name: 'Span', props: { className: 'text-sm font-medium text-gray-900 dark:text-white' }, text: '$t:admin.settings.seo.cache_enabled' },
                    { type: 'basic', name: 'P', props: { className: 'text-sm text-gray-500 dark:text-gray-400' }, text: '$t:admin.settings.seo.cache_enabled_desc' },
                  ],
                },
                { type: 'composite', name: 'Toggle', props: { name: 'seo.cache_enabled', size: 'md' } },
              ],
            },
            {
              id: 'field_seo_cache_ttl', type: 'basic', name: 'Div',
              children: [
                { type: 'basic', name: 'Label', props: { className: 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1' }, text: '$t:admin.settings.seo.cache_ttl' },
                { type: 'basic', name: 'Input', props: { type: 'number', name: 'seo.cache_ttl', min: 60, max: 86400, placeholder: '7200', className: 'w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white text-sm' } },
                { type: 'basic', name: 'P', props: { className: 'text-xs text-gray-500 dark:text-gray-400 mt-1' }, text: '$t:admin.settings.seo.cache_ttl_hint' },
              ],
            },
          ],
        },
      ],
    },
    {
      id: 'card_sitemap_settings',
      type: 'basic',
      name: 'Div',
      props: { className: 'bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-6' },
      children: [
        { type: 'basic', name: 'H3', props: { className: 'text-lg font-semibold text-gray-900 dark:text-white mb-1' }, text: '$t:admin.settings.seo.sitemap_settings' },
        { type: 'basic', name: 'P', props: { className: 'text-sm text-gray-500 dark:text-gray-400 mb-6' }, text: '$t:admin.settings.seo.sitemap_settings_desc' },
        {
          type: 'basic', name: 'Div', props: { className: 'space-y-4' },
          children: [
            {
              id: 'toggle_sitemap_enabled', type: 'basic', name: 'Div',
              props: { className: 'flex items-center justify-between py-3 border-b border-gray-200 dark:border-gray-700' },
              children: [
                {
                  type: 'basic', name: 'Div',
                  children: [
                    { type: 'basic', name: 'Span', props: { className: 'text-sm font-medium text-gray-900 dark:text-white' }, text: '$t:admin.settings.seo.sitemap_enabled' },
                    { type: 'basic', name: 'P', props: { className: 'text-sm text-gray-500 dark:text-gray-400' }, text: '$t:admin.settings.seo.sitemap_enabled_desc' },
                  ],
                },
                { type: 'composite', name: 'Toggle', props: { name: 'seo.sitemap_enabled', size: 'md' } },
              ],
            },
            {
              id: 'field_sitemap_schedule_link', type: 'basic', name: 'Div',
              props: { className: 'alert-info' },
              // 실제 블록은 [아이콘 · 문장 · 다음 실행 · 링크] 묶음이다(후속 H-42) — 픽스처는 아이콘과 다음 실행 줄을 뺀 골격만 베낀다.
              children: [
                {
                  type: 'basic', name: 'Div', props: { className: 'flex gap-3' },
                  children: [
                    {
                      type: 'basic', name: 'Div', props: { className: 'min-w-0 space-y-1' },
                      children: [
                        { type: 'basic', name: 'P', props: { className: 'text-label-subtle' }, text: '$t:admin.settings.seo.sitemap_schedule_managed' },
                        { id: 'sitemap_schedule_link', type: 'basic', name: 'A', props: { href: '/admin/schedules?extension_type=core&search_field=identifier&search_keyword=sitemap-generate', className: 'text-link' }, text: '$t:admin.settings.schedule_link.change' },
                      ],
                    },
                  ],
                },
              ],
            },
            {
              id: 'field_sitemap_cache_ttl', type: 'basic', name: 'Div',
              children: [
                { type: 'basic', name: 'Label', props: { className: 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1' }, text: '$t:admin.settings.seo.sitemap_cache_ttl' },
                { type: 'basic', name: 'Input', props: { type: 'number', name: 'seo.sitemap_cache_ttl', min: 3600, max: 604800, placeholder: '86400', className: 'w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white text-sm' } },
                { type: 'basic', name: 'P', props: { className: 'text-xs text-gray-500 dark:text-gray-400 mt-1' }, text: '$t:admin.settings.seo.sitemap_cache_ttl_hint' },
              ],
            },
          ],
        },
      ],
    },
  ],
};

const seoTabLayout = {
  version: '1.0.0',
  layout_name: 'admin_settings_seo_tab',
  components: [seoTabPartial],
};

// 부모 레이아웃의 저장 버튼 apiCall 구조 (검증용)
const saveButtonApiCall = {
  handler: 'apiCall',
  auth_required: true,
  target: '/api/admin/settings',
  params: {
    method: 'POST',
    body: "{{(function() { const tab = _global.activeSettingsTab || query.tab || 'general'; const form = _local.form || {}; return { _tab: tab, [tab]: form[tab] || {} }; })()}}",
  },
};

// ==============================
// 테스트
// ==============================

describe('환경설정 SEO 탭 레이아웃', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    testUtils?.cleanup();
  });

  // ==============================
  // 1. SEO 탭 UI 구조 — TagInput(봇 UA), Toggle(캐시), NumberInput(TTL) 존재
  // ==============================

  describe('SEO 탭 UI 구조', () => {
    // @scenario screen=seo
    // @effects seo_tab_layout_contract
    it('봇 UA TagInput, 캐시 Toggle, 캐시 TTL NumberInput이 존재한다', async () => {
      testUtils = createLayoutTest(seoTabLayout, {
        auth: {
          isAuthenticated: true,
          user: { id: 1, name: 'Admin', role: 'super_admin' },
          authType: 'admin',
        },
        locale: 'ko',
        componentRegistry: registry,
        initialState: {
          _global: { activeSettingsTab: 'seo' },
        },
        queryParams: { tab: 'seo' },
      });

      await testUtils.render();

      // TagInput (봇 UA)
      expect(screen.getByTestId('taginput-seo.bot_user_agents')).toBeInTheDocument();

      // Toggle (캐시 활성화)
      expect(screen.getByTestId('toggle-seo.cache_enabled')).toBeInTheDocument();

      // NumberInput (캐시 TTL)
      expect(screen.getByTestId('input-seo.cache_ttl')).toBeInTheDocument();
    });

    // @scenario screen=seo
    // @effects seo_tab_layout_contract
    it('메타 설정 섹션 — meta_title_suffix, meta_description, meta_keywords 필드가 존재한다', () => {
      const tabContent = seoTabPartial;
      const metaCard = tabContent.children[0]; // card_meta_settings
      expect(metaCard.id).toBe('card_meta_settings');

      // 필드 구조 확인
      const fieldsContainer = metaCard.children[2]; // space-y-4 Div
      expect(fieldsContainer.children).toHaveLength(3);

      expect(fieldsContainer.children[0].id).toBe('field_meta_title_suffix');
      expect(fieldsContainer.children[1].id).toBe('field_meta_description');
      expect(fieldsContainer.children[2].id).toBe('field_meta_keywords');
    });

    // @scenario screen=seo
    // @effects seo_tab_layout_contract
    it('분석 설정 섹션 — google_analytics_id 필드가 존재한다', () => {
      const tabContent = seoTabPartial;
      const analyticsCard = tabContent.children[1]; // card_analytics_settings
      expect(analyticsCard.id).toBe('card_analytics_settings');

      const fieldsContainer = analyticsCard.children[2]; // space-y-4 Div
      expect(fieldsContainer.children[0].id).toBe('field_google_analytics_id');
    });

    // @scenario screen=seo
    // @effects seo_tab_layout_contract
    it('검증 설정 섹션 — google, naver 사이트 인증 필드가 존재한다', () => {
      const tabContent = seoTabPartial;
      const verificationCard = tabContent.children[2]; // card_verification_settings
      expect(verificationCard.id).toBe('card_verification_settings');

      const fieldsContainer = verificationCard.children[2]; // grid Div
      expect(fieldsContainer.children[0].id).toBe('field_google_site_verification');
      expect(fieldsContainer.children[1].id).toBe('field_naver_site_verification');
    });
  });

  // ==============================
  // 2. 봇 UA 관리 TagInput creatable 설정
  // ==============================

  describe('봇 UA 관리 TagInput creatable 설정', () => {
    // @scenario screen=seo
    // @effects seo_tab_layout_contract
    it('TagInput의 creatable이 true로 설정되어 있다', () => {
      const tabContent = seoTabPartial;
      const botCard = tabContent.children[3]; // card_bot_settings
      expect(botCard.id).toBe('card_bot_settings');

      const fieldsContainer = botCard.children[2]; // space-y-4 Div
      const botUserAgentsField = fieldsContainer.children[1]; // field_bot_user_agents
      expect(botUserAgentsField.id).toBe('field_bot_user_agents');

      const tagInput = botUserAgentsField.children[1]; // TagInput
      expect(tagInput.name).toBe('TagInput');
      expect(tagInput.props.creatable).toBe(true);
    });

    // @scenario screen=seo
    // @effects seo_tab_layout_contract
    it('TagInput name이 seo.bot_user_agents이다', () => {
      const botCard = seoTabPartial.children[3];
      const tagInput = botCard.children[2].children[1].children[1];
      expect(tagInput.props.name).toBe('seo.bot_user_agents');
    });

    // @scenario screen=seo
    // @effects seo_tab_layout_contract
    it('렌더링 시 TagInput에 creatable 속성이 전달된다', async () => {
      testUtils = createLayoutTest(seoTabLayout, {
        auth: {
          isAuthenticated: true,
          user: { id: 1, name: 'Admin', role: 'super_admin' },
          authType: 'admin',
        },
        locale: 'ko',
        componentRegistry: registry,
        initialState: {
          _global: { activeSettingsTab: 'seo' },
        },
        queryParams: { tab: 'seo' },
      });

      await testUtils.render();

      const tagInput = screen.getByTestId('taginput-seo.bot_user_agents');
      expect(tagInput.getAttribute('data-creatable')).toBe('true');
    });
  });

  // ==============================
  // 3. 캐시 설정 Toggle 바인딩 — seo.cache_enabled 바인딩 확인
  // ==============================

  describe('캐시 설정 Toggle 바인딩', () => {
    // @scenario screen=seo
    // @effects seo_tab_layout_contract
    it('Toggle name이 seo.cache_enabled이다', () => {
      const cacheCard = seoTabPartial.children[4]; // card_seo_cache_settings
      expect(cacheCard.id).toBe('card_seo_cache_settings');

      const fieldsContainer = cacheCard.children[2]; // space-y-4 Div
      const toggleRow = fieldsContainer.children[0]; // toggle_seo_cache_enabled
      expect(toggleRow.id).toBe('toggle_seo_cache_enabled');

      const toggle = toggleRow.children[1]; // Toggle
      expect(toggle.name).toBe('Toggle');
      expect(toggle.props.name).toBe('seo.cache_enabled');
    });

    // @scenario screen=seo
    // @effects seo_tab_layout_contract
    it('렌더링 시 Toggle이 seo.cache_enabled name으로 표시된다', async () => {
      testUtils = createLayoutTest(seoTabLayout, {
        auth: {
          isAuthenticated: true,
          user: { id: 1, name: 'Admin', role: 'super_admin' },
          authType: 'admin',
        },
        locale: 'ko',
        componentRegistry: registry,
        initialState: {
          _global: { activeSettingsTab: 'seo' },
        },
        queryParams: { tab: 'seo' },
      });

      await testUtils.render();

      const toggle = screen.getByTestId('toggle-seo.cache_enabled');
      expect(toggle).toBeInTheDocument();
      expect(toggle.getAttribute('data-name')).toBe('seo.cache_enabled');
    });
  });

  // ==============================
  // 4. Sitemap 설정 섹션 존재
  // ==============================

  describe('Sitemap 설정 섹션', () => {
    // @scenario screen=seo
    // @effects seo_sitemap_schedule_link_replaces_inputs
    it('sitemap_enabled Toggle 과 생성 주기 링크 블록이 존재한다', () => {
      const sitemapCard = seoTabPartial.children[5]; // card_sitemap_settings
      expect(sitemapCard.id).toBe('card_sitemap_settings');

      const fieldsContainer = sitemapCard.children[2]; // space-y-4 Div

      // sitemap_enabled Toggle
      const toggleRow = fieldsContainer.children[0];
      expect(toggleRow.id).toBe('toggle_sitemap_enabled');
      const toggle = toggleRow.children[1];
      expect(toggle.name).toBe('Toggle');
      expect(toggle.props.name).toBe('seo.sitemap_enabled');

      // 생성 주기 — 입력 칸 대신 스케줄 관리 링크 블록
      const linkBlock = fieldsContainer.children[1];
      expect(linkBlock.id).toBe('field_sitemap_schedule_link');
      const link = linkBlock.children[0].children[0].children[1];
      expect(link.name).toBe('A');
      expect(link.id).toBe('sitemap_schedule_link');
    });

    // @scenario screen=seo
    // @effects seo_sitemap_schedule_link_replaces_inputs
    it('픽스처의 사이트맵 카드 항목이 실제 _tab_seo.json 에 같은 순서로 있다(손 복사 픽스처 낡음 방지)', () => {
      const real = require('../../../../../../templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_seo.json');
      const findCard = (root: any): any => {
        let found: any = null;
        const visit = (n: any) => {
          if (found || !n || typeof n !== 'object') return;
          if (Array.isArray(n)) { n.forEach(visit); return; }
          if (n.id === 'card_sitemap_settings') { found = n; return; }
          Object.values(n).forEach(visit);
        };
        visit(root);
        return found;
      };
      const ids = (node: any): string[] => {
        const out: string[] = [];
        const visit = (n: any) => {
          if (!n || typeof n !== 'object') return;
          if (Array.isArray(n)) { n.forEach(visit); return; }
          if (typeof n.id === 'string' && /^(toggle_sitemap|field_sitemap|sitemap_)/.test(n.id)) out.push(n.id);
          Object.values(n).forEach(visit);
        };
        visit(node);
        return out;
      };
      const realCard = findCard(real);
      expect(realCard).toBeTruthy();
      // 픽스처는 실제 카드의 일부만 베낀다 — 픽스처가 가진 항목이 실제에도 **같은 순서로** 있어야 한다(없어진 칸을 단언하지 않는다).
      const fixtureIds = ids(seoTabPartial.children[5]);
      const realIds = ids(realCard);
      expect(fixtureIds.length).toBeGreaterThan(0);
      expect(realIds.filter((id) => fixtureIds.includes(id))).toEqual(fixtureIds);
    });

    // @scenario screen=seo
    // @effects seo_sitemap_schedule_link_replaces_inputs
    it('렌더링 시 sitemap 토글과 스케줄 관리 링크가 표시된다', async () => {
      testUtils = createLayoutTest(seoTabLayout, {
        auth: {
          isAuthenticated: true,
          user: { id: 1, name: 'Admin', role: 'super_admin' },
          authType: 'admin',
        },
        locale: 'ko',
        componentRegistry: registry,
        initialState: {
          _global: { activeSettingsTab: 'seo' },
        },
        queryParams: { tab: 'seo' },
      });

      await testUtils.render();

      expect(screen.getByTestId('toggle-seo.sitemap_enabled')).toBeInTheDocument();
      expect(screen.getByTestId('link-sitemap_schedule_link')).toBeInTheDocument();
    });
  });

  // ==============================
  // 5. 생성 주기·시각 입력 칸 없음 (7.2.0 D10)
  // ==============================

  describe('사이트맵 생성 주기·시각 입력 칸 없음', () => {
    const allNames = (node: any): string[] => {
      const out: string[] = [];
      const visit = (n: any) => {
        if (!n || typeof n !== 'object') return;
        if (Array.isArray(n)) { n.forEach(visit); return; }
        if (typeof n.props?.name === 'string') out.push(n.props.name);
        Object.values(n).forEach(visit);
      };
      visit(node);
      return out;
    };

    // @scenario screen=seo
    // @effects seo_sitemap_schedule_link_replaces_inputs
    it('seo.sitemap_schedule 이름의 입력이 없다', () => {
      expect(allNames(seoTabPartial)).not.toContain('seo.sitemap_schedule');
    });

    // @scenario screen=seo
    // @effects seo_sitemap_schedule_link_replaces_inputs
    it('seo.sitemap_schedule_time 이름의 입력이 없다', () => {
      expect(allNames(seoTabPartial)).not.toContain('seo.sitemap_schedule_time');
    });

    // @scenario screen=seo
    // @effects seo_sitemap_schedule_link_replaces_inputs
    it('렌더링 시 주기 선택 상자·시각 입력이 나타나지 않는다', async () => {
      testUtils = createLayoutTest(seoTabLayout, {
        auth: {
          isAuthenticated: true,
          user: { id: 1, name: 'Admin', role: 'super_admin' },
          authType: 'admin',
        },
        locale: 'ko',
        componentRegistry: registry,
        initialState: {
          _global: { activeSettingsTab: 'seo' },
        },
        queryParams: { tab: 'seo' },
      });

      await testUtils.render();

      expect(screen.queryByTestId('select-seo.sitemap_schedule')).not.toBeInTheDocument();
      expect(screen.queryByTestId('input-seo.sitemap_schedule_time')).not.toBeInTheDocument();
    });
  });

  // ==============================
  // 6. 스케줄 관리 링크
  // ==============================

  describe('스케줄 관리 링크', () => {
    const link = () => seoTabPartial.children[5].children[2].children[1].children[0].children[0].children[1];

    // @scenario screen=seo
    // @effects seo_sitemap_schedule_link_replaces_inputs
    it('href 가 스케줄 관리 화면의 코어 사이트맵 행을 거르는 주소다', () => {
      expect(link().props.href).toBe('/admin/schedules?extension_type=core&search_field=identifier&search_keyword=sitemap-generate');
    });

    // @scenario screen=seo
    // @effects seo_sitemap_schedule_link_replaces_inputs
    it('href 쿼리가 스케줄 목록 서버 키(extension_type·search_field·search_keyword)만 쓴다', () => {
      const url = new URL(link().props.href, 'http://localhost');
      expect(url.pathname).toBe('/admin/schedules');
      expect([...url.searchParams.keys()].sort()).toEqual(['extension_type', 'search_field', 'search_keyword']);
      expect(url.searchParams.get('extension_type')).toBe('core');
      expect(url.searchParams.get('search_field')).toBe('identifier');
    });

    // @scenario screen=seo
    // @effects seo_sitemap_schedule_link_replaces_inputs
    it('안내·링크 문구는 번역 키다', () => {
      const block = seoTabPartial.children[5].children[2].children[1].children[0].children[0];
      expect(block.children[0].text).toBe('$t:admin.settings.seo.sitemap_schedule_managed');
      expect(link().text).toBe('$t:admin.settings.schedule_link.change');
    });

    // @scenario screen=seo
    // @effects seo_sitemap_schedule_link_replaces_inputs
    it('저장된 폼 값(옛 주기 hourly 등)과 무관하게 링크가 보인다', async () => {
      testUtils = createLayoutTest(seoTabLayout, {
        auth: {
          isAuthenticated: true,
          user: { id: 1, name: 'Admin', role: 'super_admin' },
          authType: 'admin',
        },
        locale: 'ko',
        componentRegistry: registry,
        initialState: {
          _global: { activeSettingsTab: 'seo' },
          _local: {
            form: { seo: { sitemap_schedule: 'hourly' } },
          },
        },
        queryParams: { tab: 'seo' },
      });

      await testUtils.render();

      expect(screen.getByTestId('link-sitemap_schedule_link')).toHaveAttribute('href', '/admin/schedules?extension_type=core&search_field=identifier&search_keyword=sitemap-generate');
    });
  });

  // ==============================
  // 7. sitemap_cache_ttl NumberInput 존재
  // ==============================

  describe('sitemap_cache_ttl NumberInput', () => {
    // @scenario screen=seo
    // @effects seo_tab_layout_contract
    it('Input type=number, name=seo.sitemap_cache_ttl이다', () => {
      const sitemapCard = seoTabPartial.children[5];
      const cacheTtlField = sitemapCard.children[2].children[2]; // field_sitemap_cache_ttl
      expect(cacheTtlField.id).toBe('field_sitemap_cache_ttl');

      const input = cacheTtlField.children[1];
      expect(input.name).toBe('Input');
      expect(input.props.type).toBe('number');
      expect(input.props.name).toBe('seo.sitemap_cache_ttl');
    });

    // @scenario screen=seo
    // @effects seo_tab_layout_contract
    it('min=3600, max=604800으로 설정되어 있다', () => {
      const sitemapCard = seoTabPartial.children[5];
      const input = sitemapCard.children[2].children[2].children[1];

      expect(input.props.min).toBe(3600);
      expect(input.props.max).toBe(604800);
    });

    // @scenario screen=seo
    // @effects seo_tab_layout_contract
    it('렌더링 시 sitemap_cache_ttl Input이 표시된다', async () => {
      testUtils = createLayoutTest(seoTabLayout, {
        auth: {
          isAuthenticated: true,
          user: { id: 1, name: 'Admin', role: 'super_admin' },
          authType: 'admin',
        },
        locale: 'ko',
        componentRegistry: registry,
        initialState: {
          _global: { activeSettingsTab: 'seo' },
        },
        queryParams: { tab: 'seo' },
      });

      await testUtils.render();

      expect(screen.getByTestId('input-seo.sitemap_cache_ttl')).toBeInTheDocument();
    });
  });

  // ==============================
  // 8. 저장 버튼 apiCall params에 _tab=seo
  // ==============================

  describe('저장 버튼 apiCall params에 _tab=seo', () => {
    // @scenario screen=seo
    // @effects seo_tab_layout_contract
    it('부모 레이아웃의 apiCall body에 _tab과 해당 탭 데이터가 포함된다', () => {
      // 부모 레이아웃(admin_settings.g7.html)의 저장 버튼 apiCall body 표현식 검증
      // body: "{{(function() { const tab = _global.activeSettingsTab || query.tab || 'general'; const form = _local.form || {}; return { _tab: tab, [tab]: form[tab] || {} }; })()}}"
      const bodyExpr = saveButtonApiCall.params.body;

      // _tab 키가 포함됨
      expect(bodyExpr).toContain('_tab: tab');
      // 탭 이름으로 동적 키 생성
      expect(bodyExpr).toContain('[tab]: form[tab]');
      // activeSettingsTab 또는 query.tab 사용
      expect(bodyExpr).toContain('_global.activeSettingsTab');
      expect(bodyExpr).toContain('query.tab');
    });

    // @scenario screen=seo
    // @effects seo_tab_layout_contract
    it('apiCall target이 /api/admin/settings이다', () => {
      expect(saveButtonApiCall.target).toBe('/api/admin/settings');
    });

    // @scenario screen=seo
    // @effects seo_tab_layout_contract
    it('apiCall method가 POST이다', () => {
      expect(saveButtonApiCall.params.method).toBe('POST');
    });

    // @scenario screen=seo
    // @effects seo_tab_layout_contract
    it('seo 탭 활성 시 body에 _tab=seo, seo 데이터가 포함된다', () => {
      // body 표현식을 직접 평가하여 검증
      // activeSettingsTab = 'seo' 일 때:
      // { _tab: 'seo', seo: form.seo || {} }
      const bodyExpr = saveButtonApiCall.params.body;

      // 표현식이 IIFE 구조임을 확인
      expect(bodyExpr).toMatch(/^\{\{.*\(\).*\}\}$/s);

      // 탭 변수 결정 로직 확인
      expect(bodyExpr).toContain("_global.activeSettingsTab || query.tab || 'general'");

      // 반환 객체 구조 확인: _tab과 동적 키
      expect(bodyExpr).toContain('return { _tab: tab, [tab]: form[tab] || {} }');
    });
  });

  // ==============================
  // 추가: SEO 탭 전체 if 조건
  // ==============================

  describe('SEO 탭 전체 if 조건', () => {
    // @scenario screen=seo
    // @effects seo_tab_layout_contract
    it('최상위 if 조건이 activeSettingsTab === seo일 때만 렌더링한다', () => {
      expect(seoTabPartial.if).toBe(
        "{{(_global.activeSettingsTab || query.tab || 'general') === 'seo'}}"
      );
    });

    // @scenario screen=seo
    // @effects seo_tab_layout_contract
    it('탭이 seo가 아닌 경우 콘텐츠가 렌더링되지 않는다', async () => {
      testUtils = createLayoutTest(seoTabLayout, {
        auth: {
          isAuthenticated: true,
          user: { id: 1, name: 'Admin', role: 'super_admin' },
          authType: 'admin',
        },
        locale: 'ko',
        componentRegistry: registry,
        initialState: {
          _global: { activeSettingsTab: 'general' },
        },
        queryParams: { tab: 'general' },
      });

      await testUtils.render();

      // seo 탭이 아니므로 관련 컴포넌트가 렌더링되지 않음
      expect(screen.queryByTestId('toggle-seo.cache_enabled')).not.toBeInTheDocument();
      expect(screen.queryByTestId('taginput-seo.bot_user_agents')).not.toBeInTheDocument();
    });
  });

  // ==============================
  // 추가: 봇 감지 토글
  // ==============================

  describe('봇 감지 토글', () => {
    // @scenario screen=seo
    // @effects seo_tab_layout_contract
    it('bot_detection_enabled Toggle이 존재한다', () => {
      const botCard = seoTabPartial.children[3]; // card_bot_settings
      const toggleRow = botCard.children[2].children[0]; // toggle_bot_detection_enabled
      expect(toggleRow.id).toBe('toggle_bot_detection_enabled');

      const toggle = toggleRow.children[1];
      expect(toggle.name).toBe('Toggle');
      expect(toggle.props.name).toBe('seo.bot_detection_enabled');
    });
  });

  // ==============================
  // 추가: SEO 캐시 TTL
  // ==============================

  describe('SEO 캐시 TTL', () => {
    // @scenario screen=seo
    // @effects seo_tab_layout_contract
    it('cache_ttl Input이 type=number, min=60, max=86400이다', () => {
      const cacheCard = seoTabPartial.children[4]; // card_seo_cache_settings
      const cacheTtlField = cacheCard.children[2].children[1]; // field_seo_cache_ttl
      expect(cacheTtlField.id).toBe('field_seo_cache_ttl');

      const input = cacheTtlField.children[1];
      expect(input.name).toBe('Input');
      expect(input.props.type).toBe('number');
      expect(input.props.name).toBe('seo.cache_ttl');
      expect(input.props.min).toBe(60);
      expect(input.props.max).toBe(86400);
    });
  });
});
