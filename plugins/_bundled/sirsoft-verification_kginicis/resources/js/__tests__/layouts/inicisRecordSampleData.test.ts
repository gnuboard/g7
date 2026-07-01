/**
 * KG이니시스 본인인증 카드 편집기 샘플 데이터 계약 테스트
 *
 * 본인인증 카드(resources/extensions/mypage_identity_card.json)는 유저 프로필
 * (mypage/profile)에 layout_extension 으로 주입되며, 데이터소스 `inicisRecord` 를 선언한다.
 * 편집기 캔버스에서 이 카드가 stub("샘플 name_masked"/"sample")로 렌더되던 결함을 MCP 실측으로
 * 발견(stage C) → editor-spec.json 의 sampleData.byDataSourceId.inicisRecord 를 실제 Resource
 * (InicisIdentityResource::toArray) shape 로 충실화. 본 테스트가 stub 재발을 차단한다.
 *
 * 바인딩 SSoT: resources/extensions/mypage_identity_card.json
 *   (inicisRecord.data.{method,verified_at,name_masked,birthday_masked,phone_masked,is_adult})
 */
import { describe, it, expect } from 'vitest';
import * as fs from 'node:fs';
import * as path from 'node:path';

function findProjectRoot(startDir: string): string {
  let dir = startDir;
  while (dir !== path.dirname(dir)) {
    if (fs.existsSync(path.join(dir, 'artisan'))) return dir;
    dir = path.dirname(dir);
  }
  return path.resolve(startDir, '../../../../../../..');
}

const REPO_ROOT = findProjectRoot(__dirname);
const SPEC_PATH = path.join(
  REPO_ROOT,
  'plugins/_bundled/sirsoft-verification_kginicis/editor-spec.json',
);
const spec = JSON.parse(fs.readFileSync(SPEC_PATH, 'utf-8'));
const record = spec.sampleData?.byDataSourceId?.inicisRecord;

function hasStub(node: unknown): boolean {
  if (node === '샘플' || node === 'sample') return true;
  if (typeof node === 'string' && (/^샘플\s/.test(node) || node.includes('name_masked'))) return true;
  if (Array.isArray(node)) return node.some(hasStub);
  if (node && typeof node === 'object') return Object.values(node).some(hasStub);
  return false;
}

describe('KG이니시스 본인인증 카드 inicisRecord 샘플', () => {
  it('inicisRecord 샘플이 정의되어 있다', () => {
    expect(record).toBeTruthy();
    expect(record.data).toBeTruthy();
  });

  it('stub("샘플"/"sample"/"name_masked") leaf 가 없다', () => {
    expect(hasStub(record)).toBe(false);
  });

  it('실제 Resource shape (method/verified_at/마스킹 필드/is_adult) 를 채운다', () => {
    const d = record.data;
    // 인증 방식은 라벨 문자열 (Resource: 'KG이니시스 본인확인')
    expect(typeof d.method).toBe('string');
    expect(d.method.length).toBeGreaterThan(2);
    expect(d.method).not.toBe('sample');
    // 인증 일시 — 절대 일시
    expect(d.verified_at).toMatch(/^\d{4}-\d{2}-\d{2}/);
    // 마스킹 필드 — 마스킹 문자(*) 포함, 비-stub
    expect(d.name_masked).toMatch(/\*/);
    expect(d.name_masked).not.toMatch(/name_masked/);
    expect(d.birthday_masked).toMatch(/\*/);
    expect(d.phone_masked).toMatch(/\*/);
    // 성인/외국인 여부 boolean
    expect(typeof d.is_adult).toBe('boolean');
    expect(typeof d.is_foreigner).toBe('boolean');
  });
});
