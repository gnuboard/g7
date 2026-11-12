/**
 * @file pluginSettingsPublicAssetDisk.test.tsx
 * @description 팝업 환경설정 화면 — 공개 자산 디스크 필드 (#878, 에디터 플러그인 공개#100 계약 이식).
 * 필드는 저장 키(public_asset_disk)와 **플러그인 설정 응답에 서버가 붙인** 카탈로그(available_public_asset_disks)를 바인딩한다.
 * 코어 설정 API(core.settings.read)를 교차 조회하면 화면 권한(core.plugins.read)과 표면이 갈려 커스텀 역할에서 목록만 빈다.
 * 설정 스키마·defaults 에 같은 키가 없으면 저장 검증이 규칙을 만들지 않아 값이 조용히 버려진다 — 세 지점을 대조한다.
 * (매니페스트 public-asset-disk.yaml)
 *
 * @vitest-environment node
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import settings from '../../../layouts/admin/plugin_settings.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';
import { findById, collect } from './helpers';

const layout = settings as any;
const json = (node: unknown) => JSON.stringify(node);

/** 플러그인 루트(plugin.json 기준)를 위로 훑어 찾는다. */
function pluginRoot(): string {
  let current = path.dirname(fileURLToPath(import.meta.url));
  for (let depth = 0; depth < 10; depth++) {
    if (fs.existsSync(path.join(current, 'plugin.json'))) {
      return current;
    }
    current = path.dirname(current);
  }
  throw new Error('plugin.json 을 가진 플러그인 루트를 찾지 못했습니다.');
}

/** 점 경로로 번역 값을 꺼낸다. */
const at = (obj: any, key: string) => key.split('.').reduce((acc, k) => (acc == null ? acc : acc[k]), obj);

describe('팝업 공개 자산 디스크 필드', () => {
  // @scenario surface=settings_catalog
  // @effects popup_settings_catalog_attached
  it('코어 설정 API 를 교차 조회하지 않는다 (권한 표면 단일화)', () => {
    expect(layout.data_sources.find((d: any) => d.id === 'settings')).toBeDefined();
    expect(layout.data_sources.find((d: any) => d.endpoint === '/api/admin/settings')).toBeUndefined();
  });

  // @scenario surface=settings_catalog
  // @effects popup_settings_catalog_attached
  it('선택 칸이 저장 키와 설정 응답 부착 카탈로그를 바인딩하고, 「코어 설정 따름」이 맨 앞이다', () => {
    const field = findById(settings, 'field_public_asset_disk');
    expect(field).not.toBeNull();
    const select = collect(field, (n) => n.name === 'Select' && n.props?.name === 'public_asset_disk')[0];
    expect(select).toBeDefined();
    expect(select.props.options).toContain('_local.form?.available_public_asset_disks');
    expect(select.props.options).toContain("$t('sirsoft-popup.settings.fields.public_asset_disk.follow_core')");
    expect(select.props.options).toContain('$localized(d.label)');
    expect(select.props.disabled).toBe('{{_computed.isPluginReadOnly}}');
  });

  // @scenario surface=settings_catalog
  // @effects popup_settings_catalog_attached
  it('설정 스키마(getSettingsSchema)·defaults 가 같은 키를 선언한다 (저장 whitelist)', () => {
    const pluginSource = fs.readFileSync(path.join(pluginRoot(), 'plugin.php'), 'utf-8');
    const defaults = JSON.parse(fs.readFileSync(path.join(pluginRoot(), 'config/settings/defaults.json'), 'utf-8'));
    expect(pluginSource).toContain("'public_asset_disk' => [");
    expect(pluginSource).toContain('function getStorageDiskFor(');
    expect(defaults.defaults).toHaveProperty('public_asset_disk', '');
    expect(defaults.frontend_schema.public_asset_disk).toEqual({ expose: false });
  });

  // @scenario surface=settings_catalog
  // @effects popup_settings_catalog_attached
  it('쓸 수 없게 된 디스크는 이유와 옛 이름을 선택 칸 아래에 알린다(role=status)', () => {
    const notice = findById(settings, 'public_asset_disk_unavailable')!;
    expect(notice.if).toBe('{{!!_local.form?.public_asset_disk_unavailable}}');
    expect(notice.props?.role).toBe('status');
    expect(json(notice)).toContain('public_asset_disk.unavailable|disk={{_local.form?.public_asset_disk_unavailable}}');
  });

  // @scenario surface=settings_catalog
  // @effects popup_settings_catalog_attached
  it('저장 성공 시 쓸 수 없는 디스크 안내 키를 응답값으로 덮는다 — 새 디스크로 저장한 뒤 옛 안내가 남지 않게', () => {
    const resets = collect(settings, (n) => n.handler === 'setState' && n.params?.target === 'local' && 'form.public_asset_disk_unavailable' in (n.params ?? {}));
    expect(resets).toHaveLength(1);
    expect(resets[0].params['form.public_asset_disk_unavailable']).toBe('{{response.data?.public_asset_disk_unavailable ?? null}}');
  });

  // @scenario surface=settings_catalog
  // @effects popup_settings_catalog_attached
  it('필드 문구가 한국어·영어 모두 있다', () => {
    for (const key of ['label', 'hint', 'follow_core', 'unavailable']) {
      expect(at(ko, `settings.fields.public_asset_disk.${key}`), `ko ${key}`).toBeTruthy();
      expect(at(en, `settings.fields.public_asset_disk.${key}`), `en ${key}`).toBeTruthy();
    }
    expect(String(at(ko, 'settings.fields.public_asset_disk.unavailable'))).toContain('{disk}');
  });
});
