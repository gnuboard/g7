/**
 * @file admin-settings-static-cache.test.tsx
 * @description 환경설정 > 일반 「초기 화면 정적 파일」 카드 + 다시 만들기 확인 모달 (#651 D4·D7)
 *
 * 캐시 버전이 만료로 재생성되지 않으므로(영구 번호) 재게시 누락은 무기한 stale 이 된다 —
 * 이 카드의 [지금 다시 만들기] 가 그 안전망이다. 두 축으로 잠근다:
 *
 *  (a) 스키마 — admin_settings.json 의 `staticCacheStatus` 데이터소스(일반 탭 게이트·엔드포인트·
 *      transition_overlay 대기 목록·모달 등록)와 모달의 apiCall 체인
 *      (refetchDataSource → toast → closeModal 순서)
 *  (b) 실제 _tab_general.json 파샬 렌더 — 상태 배지 4분기·버튼 disabled 분기·입력 컨트롤 0개 계약
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const layout = require('../../layouts/admin_settings.json');
const generalPartial = JSON.parse(
  readFileSync(resolve(__dirname, '../../layouts/partials/admin_settings/_tab_general.json'), 'utf-8')
);
const republishModal = JSON.parse(
  readFileSync(resolve(__dirname, '../../layouts/partials/admin_settings/_modal_static_cache_republish.json'), 'utf-8')
);

interface AnyJson { [k: string]: any }

const sources: AnyJson[] = layout.data_sources;
const byId = (id: string) => sources.find(s => s.id === id);

function findNodeById(node: any, id: string): any {
  if (!node || typeof node !== 'object') return null;
  if (node.id === id) return node;
  for (const child of node.children ?? []) {
    const found = findNodeById(child, id);
    if (found) return found;
  }
  return null;
}

function findInPartial(partial: any, id: string): any {
  for (const root of partial.components ?? partial.children ?? [partial]) {
    const found = findNodeById(root, id);
    if (found) return found;
  }
  return null;
}

// ──────────────────────────────────────────────────────────────────────────────
// (a) 스키마
// ──────────────────────────────────────────────────────────────────────────────

describe('admin_settings — staticCacheStatus 데이터소스 배선', () => {
  // @scenario permission=granted, publishable=production_enabled, outcome=success
  // @effects card_renders_status_rows
  it('일반 탭 게이트 + 상태 엔드포인트를 가진다', () => {
    const s = byId('staticCacheStatus');
    expect(s).toBeTruthy();
    expect(s!.type).toBe('api');
    expect(s!.endpoint).toBe('/api/admin/settings/static-cache');
    expect(s!.method).toBe('GET');
    expect(s!.if).toContain("'general'");
    expect(s!.if).toContain('query.tab');
    expect(s!.auto_fetch).not.toBe(false);
    expect(s!.auth_required).toBe(true);
  });

  it('fallback 은 버튼을 비활성으로 두는 안전 기본값이다 (publishable:false)', () => {
    const s = byId('staticCacheStatus')!;
    expect(s.fallback.data.publishable).toBe(false);
    expect(s.fallback.data.published).toBe(false);
    expect(s.fallback.data.failure).toBeNull();
  });

  it('탭 전환 오버레이가 이 소스를 기다린다', () => {
    expect(layout.transition_overlay.wait_for).toContain('staticCacheStatus');
  });

  it('다시 만들기 확인 모달이 등록되어 있다', () => {
    const partials = (layout.modals as AnyJson[]).map(m => m.partial);
    expect(partials).toContain('partials/admin_settings/_modal_static_cache_republish.json');
    expect(republishModal.id).toBe('settings_static_cache_republish_modal');
  });
});

describe('_tab_general — 초기 화면 정적 파일 카드 스키마', () => {
  it('카드가 에셋 서빙 방식 카드 바로 다음에 있다', () => {
    const ids = (generalPartial.children as AnyJson[]).map(c => c.id);
    const assetIdx = ids.indexOf('card_asset_serving');
    const cardIdx = ids.indexOf('card_static_cache');
    expect(assetIdx).toBeGreaterThanOrEqual(0);
    expect(cardIdx).toBe(assetIdx + 1);
  });

  // @scenario permission=granted, publishable=non_production, outcome=success
  // @effects republish_button_disabled_when_not_publishable
  it('버튼은 publishable 이 아니면 비활성이고 확인 모달을 연다', () => {
    const btn = findInPartial(generalPartial, 'static_cache_republish_button');
    expect(btn).not.toBeNull();
    expect(btn.props.type).toBe('button');
    expect(btn.props.disabled).toContain('staticCacheStatus?.data?.publishable');
    expect(btn.props.disabled).toContain('_computed.isReadOnly');
    const open = btn.actions.find((a: AnyJson) => a.handler === 'openModal');
    expect(open).toBeTruthy();
    expect(open.target).toBe('settings_static_cache_republish_modal');
  });

  it('상태 배지는 게시됨/미게시/비활성/개발 모드 4분기를 갖는다', () => {
    const badge = findInPartial(generalPartial, 'static_cache_status_badge');
    expect(badge).not.toBeNull();
    const variants = Object.keys(badge.props.classMap.variants).sort();
    expect(variants).toEqual(['dev_mode', 'disabled', 'published', 'unpublished']);
  });
});

describe('_modal_static_cache_republish — 확인 → POST → 후속 체인', () => {
  const confirmBtn = findNodeById(republishModal, 'static_cache_republish_confirm_button');
  const seq = confirmBtn?.actions?.[0];
  const apiCall = (seq?.actions as AnyJson[] | undefined)?.find(a => a.handler === 'apiCall');

  // @scenario permission=granted, publishable=production_enabled, outcome=success
  // @effects confirm_modal_then_toast_and_refetch
  it('확인 버튼이 재게시 엔드포인트를 POST 한다 (top-level target)', () => {
    expect(confirmBtn).not.toBeNull();
    expect(seq.handler).toBe('sequence');
    expect(apiCall).toBeTruthy();
    expect(apiCall!.target).toBe('/api/admin/settings/static-cache/republish');
    expect(apiCall!.params.method).toBe('POST');
    expect(apiCall!.auth_required).toBe(true);
  });

  // @scenario permission=granted, publishable=production_enabled, outcome=publish_failed_marker
  // @effects confirm_modal_then_toast_and_refetch
  it('성공 시 상태 재조회 → 토스트 → 모달 닫기 순서다', () => {
    const handlers = (apiCall!.onSuccess as AnyJson[]).map(a => a.handler);
    const refetchIdx = handlers.indexOf('refetchDataSource');
    const toastIdx = handlers.indexOf('toast');
    const closeIdx = handlers.indexOf('closeModal');
    expect(refetchIdx).toBeGreaterThanOrEqual(0);
    expect(toastIdx).toBeGreaterThan(refetchIdx);
    expect(closeIdx).toBeGreaterThan(toastIdx);

    const refetch = (apiCall!.onSuccess as AnyJson[])[refetchIdx];
    expect(refetch.params.dataSourceId).toBe('staticCacheStatus');

    // 게시 실패도 HTTP 200 이다 — 토스트가 페이로드(republished)로 분기해야 한다
    const toast = (apiCall!.onSuccess as AnyJson[])[toastIdx];
    expect(toast.params.message).toContain('response?.data?.republished');
    expect(toast.params.type).toContain('response?.data?.republished');
  });

  it('실패 시 에러 토스트 후 모달을 닫는다', () => {
    const handlers = (apiCall!.onError as AnyJson[]).map(a => a.handler);
    expect(handlers).toContain('toast');
    expect(handlers).toContain('closeModal');
  });
});

// ──────────────────────────────────────────────────────────────────────────────
// (b) 실제 파샬 렌더
// ──────────────────────────────────────────────────────────────────────────────

const TestDiv: React.FC<any> = ({ id, className, children }) => (
  <div id={id} className={className}>{children}</div>
);
const TestSpan: React.FC<any> = ({ id, className, children, text }) => (
  <span id={id} className={className}>{children || text}</span>
);
const TestH3: React.FC<any> = ({ id, className, children, text }) => (
  <h3 id={id} className={className}>{children || text}</h3>
);
const TestP: React.FC<any> = ({ id, className, children, text }) => (
  <p id={id} className={className}>{children || text}</p>
);
const TestInput: React.FC<any> = ({ name, type }) => <input name={name} type={type} />;
const TestSelect: React.FC<any> = ({ name }) => <select name={name} />;
const TestTextarea: React.FC<any> = ({ name }) => <textarea name={name} />;
const TestToggle: React.FC<any> = ({ name }) => <input type="checkbox" role="switch" name={name} />;
const TestButton: React.FC<any> = ({ id, disabled, className, children, text }) => (
  <button id={id} type="button" disabled={!!disabled} aria-disabled={!!disabled} className={className}>
    {children || text}
  </button>
);
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Select: { component: TestSelect, metadata: { name: 'Select', type: 'basic' } },
    Textarea: { component: TestTextarea, metadata: { name: 'Textarea', type: 'basic' } },
    Toggle: { component: TestToggle, metadata: { name: 'Toggle', type: 'composite' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

const translations = {
  admin: {
    settings: {
      general: {
        static_cache_title: '초기 화면 정적 파일',
        static_cache_description: '설명',
        static_cache_status: '상태',
        static_cache_status_published: '게시됨',
        static_cache_status_unpublished: '미게시',
        static_cache_status_disabled: '비활성',
        static_cache_status_dev_mode: '개발 모드',
        static_cache_version: '현재 버전',
        static_cache_files: '파일 수',
        static_cache_published_at: '마지막 게시 시각',
        static_cache_never: '아직 없음',
        static_cache_process_user: '실행 계정',
        static_cache_failure: '최근 {{count}}회 연속 실패 —',
        static_cache_failure_parent_not_writable: '저장 폴더에 쓸 수 없습니다.',
        static_cache_failure_write_failed: '파일을 쓰는 중 실패했습니다.',
        static_cache_failure_lock_unavailable: '캐시 잠금을 얻지 못했습니다.',
        static_cache_hint_disabled: '꺼져 있음 힌트',
        static_cache_hint_dev_mode: '개발 모드 힌트',
        static_cache_republish_now: '지금 다시 만들기',
      },
    },
  },
};

function renderCard(report: Record<string, unknown>) {
  const card = findInPartial(generalPartial, 'card_static_cache');
  expect(card, 'card_static_cache 노드가 _tab_general.json 에 없습니다').not.toBeNull();

  const testUtils = createLayoutTest(
    {
      version: '1.0.0',
      layout_name: 'test_static_cache_card',
      computed: { isReadOnly: '{{false}}' },
      data_sources: [
        {
          id: 'staticCacheStatus',
          type: 'api',
          endpoint: '/api/admin/settings/static-cache',
          method: 'GET',
          auto_fetch: true,
          auth_required: true,
        },
      ],
      components: [card],
    } as any,
    { translations, locale: 'ko' }
  );

  testUtils.mockApi('staticCacheStatus', { response: { data: report } });

  return testUtils;
}

const PUBLISHED = {
  enabled: true, publishable: true, environment: 'production', version: 1788656566, published: true,
  files: 714, published_at: '2026-09-06T01:02:49+00:00', tree_writable: true, process_user: 'www-data',
  failure: null, retained_versions: [1788656566],
};

describe('환경설정 일반 — 초기 화면 정적 파일 카드 렌더', () => {
  let registry: ComponentRegistry;

  beforeEach(() => { registry = setupTestRegistry(); });
  afterEach(() => { (registry as any).registry = {}; });

  it('입력 컨트롤을 하나도 렌더하지 않는다 (진단 + 동작 패널 — 저장 필드 없음)', async () => {
    const testUtils = renderCard(PUBLISHED);
    await testUtils.render();

    expect(document.querySelector('#static_cache_status_badge')).not.toBeNull();
    expect(document.querySelectorAll('input, select, textarea').length).toBe(0);

    testUtils.cleanup();
  });

  // @scenario permission=granted, publishable=production_enabled, outcome=success
  // @effects card_renders_status_rows
  it('게시됨: 배지 「게시됨」 + 버전·파일 수 표시 + 버튼 활성', async () => {
    const testUtils = renderCard(PUBLISHED);
    await testUtils.render();

    expect(document.querySelector('#static_cache_status_badge')!.textContent!.trim()).toBe('게시됨');
    expect(document.querySelector('#static_cache_version_row')!.textContent).toContain('1788656566');
    expect(document.querySelector('#static_cache_files_row')!.textContent).toContain('714');
    expect(document.querySelector('#static_cache_process_user_row')!.textContent).toContain('www-data');
    expect(document.querySelector('#static_cache_failure_row')).toBeNull();
    expect(document.querySelector('#static_cache_not_publishable_hint')).toBeNull();

    const btn = document.querySelector('#static_cache_republish_button') as HTMLButtonElement;
    expect(btn).not.toBeNull();
    expect(btn.disabled).toBe(false);

    testUtils.cleanup();
  });

  // @scenario permission=granted, publishable=production_enabled, outcome=publish_failed_marker
  // @effects card_renders_status_rows
  it('미게시 + 실패 마커: 배지 「미게시」 + 실패 행 노출', async () => {
    const testUtils = renderCard({
      ...PUBLISHED, published: false, files: 0, published_at: null,
      failure: { version: 1, at: '2026-09-06T00:00:00+00:00', reason: 'parent_not_writable', count: 3, message: 'denied' },
    });
    await testUtils.render();

    expect(document.querySelector('#static_cache_status_badge')!.textContent!.trim()).toBe('미게시');
    const failure = document.querySelector('#static_cache_failure_row');
    expect(failure).not.toBeNull();
    expect(failure!.textContent).toContain('3');
    expect(failure!.textContent).toContain('저장 폴더에 쓸 수 없습니다.');
    expect(document.querySelector('#static_cache_published_at_never')).not.toBeNull();

    testUtils.cleanup();
  });

  // @scenario permission=granted, publishable=kill_switch_off, outcome=publish_failed_marker
  // @effects republish_button_disabled_when_not_publishable
  it('kill-switch 꺼짐: 배지 「비활성」 + 버튼 비활성 + 사유 힌트', async () => {
    const testUtils = renderCard({ ...PUBLISHED, enabled: false, publishable: false, published: false });
    await testUtils.render();

    expect(document.querySelector('#static_cache_status_badge')!.textContent!.trim()).toBe('비활성');
    const btn = document.querySelector('#static_cache_republish_button') as HTMLButtonElement;
    expect(btn.disabled).toBe(true);
    expect(btn.getAttribute('aria-disabled')).toBe('true');
    expect(document.querySelector('#static_cache_not_publishable_hint')!.textContent!.trim()).toBe('꺼져 있음 힌트');

    testUtils.cleanup();
  });

  // @scenario permission=granted, publishable=non_production, outcome=publish_failed_marker
  // @effects republish_button_disabled_when_not_publishable
  it('개발 모드: 배지 「개발 모드」 + 버튼 비활성', async () => {
    const testUtils = renderCard({ ...PUBLISHED, environment: 'local', publishable: false, published: false });
    await testUtils.render();

    expect(document.querySelector('#static_cache_status_badge')!.textContent!.trim()).toBe('개발 모드');
    expect((document.querySelector('#static_cache_republish_button') as HTMLButtonElement).disabled).toBe(true);
    expect(document.querySelector('#static_cache_not_publishable_hint')!.textContent!.trim()).toBe('개발 모드 힌트');

    testUtils.cleanup();
  });

  it('다국어 키가 화면에 그대로 노출되지 않는다', async () => {
    const testUtils = renderCard(PUBLISHED);
    await testUtils.render();

    const text = document.querySelector('#card_static_cache')!.textContent ?? '';
    expect(text).not.toContain('$t:');
    expect(text).not.toContain('admin.settings');

    testUtils.cleanup();
  });
});
