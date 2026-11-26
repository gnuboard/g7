/**
 * @file admin-settings-env-locked-save-body.test.tsx
 * @description 환경설정 저장 본문에서 `.env` 로 잠긴 칸을 뺀다
 *
 * `.env` 가 값을 정하는 칸은 화면에서 잠기지만(`_computed.envLocked['{탭}.{키}'] === true`) 저장 본문에는 그 값이
 * 그대로 실렸다 — 서버가 빼서 결과는 같았지만, 사용자가 바꿀 수 없는 값을 매번 보내고 서버의 거름에만 기댔다.
 * 저장 단추의 본문 식(admin_settings 레이아웃)이 잠긴 키를 뺀다. 업로드 탭의 썸네일 행 변환과 다른 탭 모두 같다.
 *
 * 조합과 효과 표식은 그것을 재는 시험마다 단다.
 */
import { describe, it, expect } from 'vitest';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import adminSettings from '../../layouts/admin_settings.json';

type Json = Record<string, any>;

const asArray = <T = Json>(v: unknown): T[] => (Array.isArray(v) ? (v as T[]) : v ? [v as T] : []);

function findNodeById(node: any, id: string): Json | null {
  if (!node || typeof node !== 'object') return null;
  if (Array.isArray(node)) {
    for (const n of node) {
      const f = findNodeById(n, id);
      if (f) return f;
    }
    return null;
  }
  if (node.id === id) return node;
  for (const v of Object.values(node)) {
    if (v && typeof v === 'object') {
      const f = findNodeById(v, id);
      if (f) return f;
    }
  }
  return null;
}

/** 저장 단추의 요청 본문 식 */
function saveBodyExpression(): string {
  const button = findNodeById((adminSettings as Json).slots.content[0], 'save_button');
  const save = asArray(button!.actions)[0].cases.save;
  const call = asArray(save.actions).find((a: Json) => a.handler === 'apiCall');
  return String(call!.params.body).replace(/^\{\{|\}\}$/g, '');
}

function evaluateBody(tab: string, form: Json, envLocked: Json | null): Json {
  const engine = new DataBindingEngine();
  return engine.evaluateExpression(saveBodyExpression(), {
    _local: { form: { ...form, _meta: { env_locked: envLocked } } },
    _global: { activeSettingsTab: tab },
    query: {},
  } as any) as Json;
}

describe('환경설정 저장 본문 — .env 잠긴 칸', () => {
  /**
   * @scenario case=upload_locked
   * @effects save_body_omits_env_locked_keys
   */
  it('업로드 탭: 잠긴 키는 본문에서 빠지고 썸네일 행 변환은 그대로다', () => {
    const body = evaluateBody(
      'upload',
      { upload: { max_file_size: 10, allowed_extensions: 'jpg', thumbnail_presets_rows: [] } },
      { 'upload.max_file_size': true }
    );

    expect(body._tab).toBe('upload');
    expect(body.upload).not.toHaveProperty('max_file_size');
    expect(body.upload.allowed_extensions).toBe('jpg');
    expect(body.upload.thumbnail_presets).toEqual([]);
    expect(body.upload).not.toHaveProperty('thumbnail_presets_rows');
  });

  /**
   * @scenario case=other_tab_locked
   * @effects save_body_omits_env_locked_keys
   */
  it('다른 탭(드라이버): 잠긴 키만 빠진다', () => {
    const body = evaluateBody('drivers', { drivers: { s3_bucket: 'b', s3_region: 'r' } }, { 'drivers.s3_bucket': true, 'drivers.s3_region': false });

    expect(body).toEqual({ _tab: 'drivers', drivers: { s3_region: 'r' } });
  });

  /**
   * @scenario case=no_lock
   * @effects save_body_keeps_unlocked_keys
   */
  it('잠금 정보가 없으면 본문은 종전 그대로다', () => {
    expect(evaluateBody('seo', { seo: { a: 1 } }, null)).toEqual({ _tab: 'seo', seo: { a: 1 } });
  });
});
