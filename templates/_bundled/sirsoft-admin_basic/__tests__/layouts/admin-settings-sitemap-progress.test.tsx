/**
 * @file admin-settings-sitemap-progress.test.tsx
 * @description 환경설정 > SEO > Sitemap 재생성 진행상황 UI 회귀 가드 (이슈 #79 / S5)
 *
 * 검증 포인트:
 * - admin_settings.json: sitemap_status(api) + sitemap_progress_ws(websocket) 데이터소스 배선
 *   - Reverb OFF(realtime_enabled!=true) + 진행 중 → startInterval 폴링 시작
 *   - 완료/실패 전이 → stopInterval 폴링 중단
 *   - websocket target_source 가 sitemap_status 로 수신 데이터를 병합(D27 동형 payload)
 * - _tab_seo.json: 진행상황 표시 블록 + 재생성 버튼 재배선
 *   - 상태 배지(대기/생성중/파일작성중/완료/실패) classMap
 *   - 실시간(구독) / 폴링 안내 문구 분기
 *   - 버튼 disabled/label 이 progress.status 로 분기, 클릭 시 sitemap_status refetch
 */

import { describe, it, expect } from 'vitest';

const layout = require('../../layouts/admin_settings.json');
const seoTab = require('../../layouts/partials/admin_settings/_tab_seo.json');

interface AnyJson { [k: string]: any }

/**
 * 트리에서 조건에 맞는 노드를 모두 수집.
 */
function collectNodes(node: any, predicate: (n: any) => boolean): AnyJson[] {
  const out: AnyJson[] = [];
  const visit = (n: any) => {
    if (!n || typeof n !== 'object') return;
    if (Array.isArray(n)) { n.forEach(visit); return; }
    if (predicate(n)) out.push(n);
    Object.values(n).forEach(visit);
  };
  visit(node);
  return out;
}

const sources: AnyJson[] = layout.data_sources;
const byId = (id: string) => sources.find(s => s.id === id);

describe('admin_settings — sitemap 진행상황 데이터소스', () => {
  it('sitemap_status 는 seo 탭 게이트 + 상태 엔드포인트를 가진다', () => {
    const s = byId('sitemap_status');
    expect(s).toBeTruthy();
    expect(s!.type).toBe('api');
    expect(s!.endpoint).toBe('/api/admin/seo/sitemap/status');
    expect(s!.method).toBe('GET');
    expect(typeof s!.if).toBe('string');
    expect(s!.if).toContain("'seo'");
    expect(s!.auto_fetch).not.toBe(false);
  });

  it('sitemap_status onSuccess: OFF + 진행중이면 startInterval 로 폴링', () => {
    const s = byId('sitemap_status')!;
    const start = (s.onSuccess as AnyJson[]).find(a => a.handler === 'startInterval');
    expect(start).toBeTruthy();
    // realtime OFF 조건 + 진행 상태 집합을 참조
    expect(start!.if).toContain('realtime_enabled');
    expect(start!.if).toContain('queued');
    expect(start!.if).toContain('running');
    expect(start!.if).toContain('writing');
    // onSuccess 조건은 방금 받은 응답(response.data)을 봐야 한다. 명명 소스(sitemap_status)는
    // onSuccess 실행 시점엔 아직 이전 렌더의 값(stale)이라, 재생성 직후(queued)에도 폴링이
    // 시작되지 않는 회귀가 발생한다(Reverb OFF 폴백 붕괴). 신선한 response 로 게이트해야 한다.
    // onSuccess 의 response.data 는 응답 봉투 전체({success,message,data}) 이므로 progress 는
    // response.data.data.progress (double .data) 다. 한 단계 부족하면 undefined → 폴링 미시작.
    expect(start!.if).toContain('response?.data?.data?.progress?.status');
    expect(start!.if).not.toContain('sitemap_status?.data?.progress?.status');
    // 폴링 액션은 sitemap_status 재조회
    const refetch = (start!.params.actions as AnyJson[])[0];
    expect(refetch.handler).toBe('refetchDataSource');
    expect(refetch.params.dataSourceId).toBe('sitemap_status');
    // 같은 id 로 idempotent 등록
    expect(start!.params.id).toBe('sitemap_progress_poll');
  });

  it('sitemap_status onSuccess: 완료/실패 전이면 stopInterval', () => {
    const s = byId('sitemap_status')!;
    const stop = (s.onSuccess as AnyJson[]).find(a => a.handler === 'stopInterval');
    expect(stop).toBeTruthy();
    expect(stop!.if).toContain('completed');
    expect(stop!.if).toContain('failed');
    // startInterval 과 동일하게 신선한 response(double .data) 로 게이트 (stale 명명 소스 금지)
    expect(stop!.if).toContain('response?.data?.data?.progress?.status');
    expect(stop!.if).not.toContain('sitemap_status?.data?.progress?.status');
    expect(stop!.params.id).toBe('sitemap_progress_poll');
  });

  it('sitemap_progress_ws 는 관리자 채널을 구독하고 sitemap_status 로 병합한다', () => {
    const ws = byId('sitemap_progress_ws');
    expect(ws).toBeTruthy();
    expect(ws!.type).toBe('websocket');
    expect(ws!.channel).toBe('core.admin.seo.sitemap');
    expect(ws!.event).toBe('sitemap.progress.updated');
    expect(ws!.channel_type).toBe('private');
    expect(ws!.target_source).toBe('sitemap_status');
  });
});

describe('_tab_seo — sitemap 진행상황 표시 UI', () => {
  it('진행상황 블록은 상태 집합으로 게이트되고 progress 를 바인딩한다', () => {
    const block = collectNodes(seoTab, n => n.id === 'sitemap_progress_block')[0];
    expect(block).toBeTruthy();
    expect(block.if).toContain('sitemap_status?.data?.progress?.status');
    expect(block.if).toContain('completed');
    expect(block.if).toContain('failed');
  });

  it('상태 배지 classMap 이 5개 상태 variant 를 모두 정의한다', () => {
    const badge = collectNodes(seoTab, n => n?.props?.classMap?.key
      && String(n.props.classMap.key).includes('progress?.status'))[0];
    expect(badge).toBeTruthy();
    const variants = badge.props.classMap.variants;
    ['queued', 'running', 'writing', 'completed', 'failed'].forEach(st => {
      expect(variants[st]).toBeTruthy();
    });
  });

  it('실시간(구독) / 폴링 안내 문구가 realtime_enabled 로 분기된다', () => {
    const hint = collectNodes(seoTab, n => typeof n.text === 'string'
      && n.text.includes('sitemap_realtime_connected')
      && n.text.includes('sitemap_polling'))[0];
    expect(hint).toBeTruthy();
    expect(hint.text).toContain('realtime_enabled');
  });

  it('현재 단계(phase)와 누적 URL 문구가 존재한다', () => {
    const phase = collectNodes(seoTab, n => typeof n.text === 'string'
      && n.text.includes('sitemap_progress_phase'))[0];
    const urls = collectNodes(seoTab, n => typeof n.text === 'string'
      && n.text.includes('sitemap_progress_urls'))[0];
    expect(phase).toBeTruthy();
    expect(urls).toBeTruthy();
  });

  it('재생성 버튼은 progress 상태로 disabled/label 분기하고 클릭 시 status 를 refetch 한다', () => {
    const btn = collectNodes(seoTab, n => n.name === 'Button'
      && typeof n?.props?.disabled === 'string'
      && n.props.disabled.includes('progress?.status'))[0];
    expect(btn).toBeTruthy();
    expect(String(btn.text)).toContain('sitemap_regenerating');

    const apiCall = (btn.actions as AnyJson[]).find(a => a.handler === 'apiCall');
    expect(apiCall).toBeTruthy();
    expect(apiCall!.target).toBe('/api/admin/seo/sitemap/regenerate');
    const refetch = (apiCall!.onSuccess as AnyJson[]).find(a => a.handler === 'refetchDataSource');
    expect(refetch).toBeTruthy();
    expect(refetch!.params.dataSourceId).toBe('sitemap_status');
  });
});
