/**
 * 페이지 도메인 편집기 샘플 데이터 계약 테스트
 *
 * 사용자 페이지 화면(page/show — /page/:slug)과 회원가입 약관/개인정보 모달은 sirsoft-basic
 * 템플릿 레이아웃이며, 데이터소스를 선언한 레이아웃 소유 확장이 템플릿이므로 편집기 샘플
 * SSoT 는 템플릿 editor-spec(`editor-spec/sampleData.json`)의 `byDataSourceId` 다.
 *
 * 실제 Resource shape 대조:
 *  - page           : PublicPageResource (서버 localize 된 문자열 title/content, attachments[],
 *                     seo_meta, current_version, published_at) — layouts/page/show.json 소비
 *  - termsContent   : PageController(공개) terms — data.{content, content_mode, published_at}
 *                     (partials/auth/_modal_terms.json 소비, 모달 — 단위 계약 검증)
 *  - privacyContent : 동일 shape (partials/auth/_modal_privacy.json 소비)
 *
 * 바인딩 SSoT: layouts/page/show.json, partials/auth/_modal_{terms,privacy}.json.
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
  return path.resolve(startDir, '../../../../..');
}

const REPO_ROOT = findProjectRoot(__dirname);
const SAMPLE_PATH = path.join(REPO_ROOT, 'templates/_bundled/sirsoft-basic/editor-spec/sampleData.json');

const sample = JSON.parse(fs.readFileSync(SAMPLE_PATH, 'utf-8'));
const byId = sample.byDataSourceId as Record<string, any>;

function hasStub(node: unknown): boolean {
  if (node === '샘플') return true;
  if (typeof node === 'string' && /^샘플\s/.test(node)) return true;
  if (typeof node === 'string' && /샘플\s*(내용|설명|title|name|excerpt)/.test(node)) return true;
  if (Array.isArray(node)) {
    if (node.length === 1 && node[0] === '샘플') return true;
    return node.some(hasStub);
  }
  if (node && typeof node === 'object') return Object.values(node).some(hasStub);
  return false;
}

describe('페이지 도메인 (basic) — stub 0', () => {
  for (const id of ['page', 'termsContent', 'privacyContent']) {
    it(`${id} 에 "샘플" stub 이 없다`, () => {
      expect(byId[id]).toBeDefined();
      expect(hasStub(byId[id])).toBe(false);
    });
  }
});

describe('page (사용자 페이지 상세) — PublicPageResource shape', () => {
  const root = byId.page.data;

  it('핵심 표시 필드(문자열 title/content) 채움', () => {
    expect(typeof root.title).toBe('string');
    expect(root.title.length).toBeGreaterThan(0);
    expect(typeof root.content).toBe('string');
    expect(root.content).toContain('<');
    expect(root.id).toBeTruthy();
    expect(root.slug).toBeTruthy();
    expect(root.content_mode).toBe('html');
  });

  it('메타(발행일/버전/요약) 채움', () => {
    expect(root.published).toBe(true);
    expect(root.published_at).toBeTruthy();
    expect(root.current_version).toBeGreaterThanOrEqual(1);
    expect(typeof root.excerpt).toBe('string');
    expect(root.created_at).toBeTruthy();
    expect(root.updated_at).toBeTruthy();
  });

  it('seo_meta 전 경로(title/description/keywords) 채움', () => {
    expect(root.seo_meta.title).toBeTruthy();
    expect(root.seo_meta.description).toBeTruthy();
    expect(root.seo_meta.keywords).toBeTruthy();
  });

  it('attachments ≥ 3 건 + is_image 분기 양면 + 바인딩 필드 채움', () => {
    expect(Array.isArray(root.attachments)).toBe(true);
    expect(root.attachments.length).toBeGreaterThanOrEqual(3);
    const images = root.attachments.filter((a: any) => a.is_image);
    const files = root.attachments.filter((a: any) => !a.is_image);
    expect(images.length).toBeGreaterThanOrEqual(1);
    expect(files.length).toBeGreaterThanOrEqual(1);
    for (const a of root.attachments) {
      // basic att_item 바인딩: download_url, is_image, original_filename
      expect(a.download_url).toBeTruthy();
      expect(typeof a.is_image).toBe('boolean');
      expect(a.original_filename).toBeTruthy();
    }
  });
});

describe('termsContent / privacyContent (약관/개인정보 모달) — data.{content,content_mode,published_at}', () => {
  for (const id of ['termsContent', 'privacyContent']) {
    it(`${id} content 본문 + 메타 채움`, () => {
      const d = byId[id].data;
      expect(typeof d.content).toBe('string');
      expect(d.content).toContain('<');
      expect(d.content.length).toBeGreaterThan(40);
      expect(d.content_mode).toBe('html');
      expect(d.published_at).toBeTruthy();
    });
  }
});
