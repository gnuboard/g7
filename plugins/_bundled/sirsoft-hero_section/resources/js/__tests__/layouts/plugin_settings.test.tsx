/**
 * @file plugin_settings.test.tsx
 * @description 히어로섹션 환경설정 화면 — 저장 실패를 화면에 돌려주고(onError), 서버 PHP 업로드 한도를 안내한다.
 * (매니페스트 admin-screens.yaml)
 *
 * @vitest-environment node
 */
import { describe, expect, it } from 'vitest';
import settings from '../../../layouts/admin/plugin_settings.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';
import { actionsOf, collect, findById, pick, translationKeys } from './helpers';

const json = (node: unknown) => JSON.stringify(node);
const layout = settings as any;

describe('히어로섹션 환경설정 화면', () => {
  // @scenario surface=plugin_settings_save_with_onerror
  it('저장은 코어 플러그인 설정 주소로 보내고, 실패하면 필드 오류를 남기며 진행 깃발을 끈다', () => {
    const calls = collect(settings, (n) => n.handler === 'apiCall' && json(n).includes('/settings') && json(n).includes('PUT'));
    expect(calls.length).toBeGreaterThan(0);
    const call = calls[0];
    expect(json(call)).toContain('/api/admin/plugins/{{route.identifier}}/settings');
    const onError = call.onError ?? call.params?.onError;
    expect(onError).toBeDefined();
    expect(json(onError)).toContain('errors');
    expect(json(actionsOf(call, 'toast'))).toContain('error');
  });

  // @scenario surface=plugin_settings_shows_server_upload_limit
  it('최대 용량 칸 아래에 현재 서버 한도를 보이고, 설정값이 그보다 크면 노란 경고를 띄운다', () => {
    const source = layout.data_sources.find((s: any) => s.id === 'upload_limit');
    expect(source.endpoint).toBe('/api/plugins/sirsoft-hero_section/admin/position-settings');
    // 배너 조회 권한이 없어 실패해도 화면 오류로 만들지 않는다
    expect(source.fallback).toEqual({ data: null });
    const limit = findById(settings, 'server_upload_limit')!;
    expect(limit.if).toBe('{{!!upload_limit?.data?.upload?.server_limit_mb}}');
    expect(json(limit)).toContain('max_upload_mb.server_limit|mb={{upload_limit?.data?.upload?.server_limit_mb}}');
    const warning = findById(settings, 'server_upload_limit_warning')!;
    expect(warning.if).toContain('_local.form?.max_upload_mb');
    expect(warning.if).toContain('server_limit_mb');
  });

  // @scenario surface=plugin_settings_shows_server_upload_limit
  // @effects accessible_names_present
  it('설정 화면이 쓰는 문구가 한국어·영어 모두 있다', () => {
    for (const key of translationKeys(settings)) {
      expect(pick(ko, key), `ko ${key}`).toBeTruthy();
      expect(pick(en, key), `en ${key}`).toBeTruthy();
    }
  });
});
