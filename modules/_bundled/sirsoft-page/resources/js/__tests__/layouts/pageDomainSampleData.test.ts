/**
 * 페이지 도메인 편집기 샘플 데이터 + 상태 계약 테스트
 *
 * 관리자 페이지 화면(admin_page_list/detail/form)은 sirsoft-page 모듈 레이아웃이며, 데이터소스를
 * 선언한 레이아웃 소유 확장이 모듈이므로 편집기 샘플 SSoT 는 모듈 editor-spec(`editor-spec.json`)의
 * `sampleData.byDataSourceId` 와 `states` 다.
 *
 * 실제 Resource shape 대조:
 *  - page     : PageResource::toArray (다국어 객체 title/content, creator, attachments[], seo_meta 다국어,
 *               current_version) — admin_page_detail.json (page?.data?.title?.[locale]) 소비
 *  - pageData : 동일 PageResource (admin_page_form.json — 첨부 initialFiles + 읽기전용 표시)
 *  - versions : PageVersionResource (id/version/creator.name/created_at/changes_summary) — detail 버전 탭
 *  - pages    : PageResource::toListArray (data.data[] + meta + abilities) — admin_page_list DataGrid
 *
 * states:
 *  - /page/:slug draft_preview     : byDataSourceId.page override = base 충실 shape + published:false
 *  - (star)/admin/pages/:id/edit edit_mode : initialState.local.form 폼 시드(다국어 title/content)
 *  - (star)/admin/pages/:id attachments_open : _local.showAttachments:true (첨부 토글 게이트 노출)
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
const SPEC_PATH = path.join(REPO_ROOT, 'modules/_bundled/sirsoft-page/editor-spec.json');

const spec = JSON.parse(fs.readFileSync(SPEC_PATH, 'utf-8'));
const byId = spec.sampleData.byDataSourceId as Record<string, any>;

function hasStub(node: unknown): boolean {
  if (node === '샘플') return true;
  if (typeof node === 'string' && /^샘플\s/.test(node)) return true;
  if (typeof node === 'string' && /샘플\s*(내용|설명|title|name|본문)/.test(node)) return true;
  if (Array.isArray(node)) {
    if (node.length === 1 && node[0] === '샘플') return true;
    return node.some(hasStub);
  }
  if (node && typeof node === 'object') return Object.values(node).some(hasStub);
  return false;
}

function isMultilingual(v: unknown): boolean {
  return !!v && typeof v === 'object' && !Array.isArray(v)
    && typeof (v as any).ko === 'string' && (v as any).ko.length > 0;
}

describe('페이지 도메인 (page 모듈) — stub 0', () => {
  for (const id of ['page', 'pageData', 'versions', 'pages']) {
    it(`${id} 에 "샘플" stub 이 없다`, () => {
      expect(byId[id]).toBeDefined();
      expect(hasStub(byId[id])).toBe(false);
    });
  }
});

describe('page (admin 상세) — PageResource 다국어 shape', () => {
  const root = byId.page.data;

  it('title/content 가 다국어 객체({ko,en}) — detail 이 [locale] 로 접근', () => {
    expect(isMultilingual(root.title)).toBe(true);
    expect(isMultilingual(root.content)).toBe(true);
    expect(root.content.ko).toContain('<');
  });

  it('seo_meta 전 경로가 flat string (model cast array — 평면, MultilingualInput 아님; detail 직접 바인딩)', () => {
    // 주의: seo_meta 를 다국어 객체로 두면 admin detail 이 {{...seo_meta.title}} 를 객체째 렌더 → React #31 크래시.
    expect(typeof root.seo_meta.title).toBe('string');
    expect(root.seo_meta.title.length).toBeGreaterThan(0);
    expect(typeof root.seo_meta.description).toBe('string');
    expect(typeof root.seo_meta.keywords).toBe('string');
  });

  it('creator.name + 메타(current_version/published_at) 채움', () => {
    expect(root.creator.name).toBeTruthy();
    expect(root.creator.uuid).toBeTruthy();
    expect(root.current_version).toBeGreaterThanOrEqual(1);
    expect(root.published).toBe(true);
    expect(root.published_at).toBeTruthy();
  });

  it('attachments ≥ 3 + is_image 분기 양면 + 바인딩 필드(download_url/preview_url/original_filename/size_formatted)', () => {
    expect(root.attachments.length).toBeGreaterThanOrEqual(3);
    expect(root.attachments.some((a: any) => a.is_image)).toBe(true);
    expect(root.attachments.some((a: any) => !a.is_image)).toBe(true);
    for (const a of root.attachments) {
      expect(a.download_url).toBeTruthy();
      expect(a.original_filename).toBeTruthy();
      expect(a.size_formatted).toBeTruthy();
    }
  });
});

describe('pageData (admin 폼) — PageResource 다국어 + 첨부', () => {
  const root = byId.pageData.data;
  it('title/content 다국어 + can_update + attachments', () => {
    expect(isMultilingual(root.title)).toBe(true);
    expect(isMultilingual(root.content)).toBe(true);
    expect(root.abilities.can_update).toBe(true);
    expect(root.attachments.length).toBeGreaterThanOrEqual(3);
  });
});

describe('versions (버전 탭) — PageVersionResource shape, 분기 양면', () => {
  const list = byId.versions.data;
  it('≥ 3 건', () => {
    expect(Array.isArray(list)).toBe(true);
    expect(list.length).toBeGreaterThanOrEqual(3);
  });
  it('creator.name + version + created_at 바인딩 채움', () => {
    for (const v of list) {
      expect(v.version).toBeGreaterThanOrEqual(1);
      expect(v.creator.name).toBeTruthy();
      expect(v.created_at).toBeTruthy();
    }
  });
  it('changes_summary 분기 양면 — initial(null) + changed_fields + restored_from 공존', () => {
    const initial = list.find((v: any) => v.version === 1);
    expect(initial.changes_summary).toBeNull(); // "최초 작성" 분기
    const restored = list.find((v: any) => v.changes_summary?.restored_from);
    expect(restored).toBeDefined(); // 복원 배지 분기
    const changed = list.find((v: any) => v.changes_summary?.changed_fields?.length > 0);
    expect(changed).toBeDefined(); // 변경 필드 분기
  });
});

describe('pages (admin 목록) — toListArray, published 분기 양면', () => {
  const root = byId.pages.data;
  it('data.data ≥ 3 + meta + abilities', () => {
    expect(root.data.length).toBeGreaterThanOrEqual(3);
    expect(root.meta.total).toBeGreaterThanOrEqual(3);
    expect(root.abilities.can_create).toBe(true);
  });
  it('published 분기 양면(발행 + 미발행) 공존', () => {
    expect(root.data.some((p: any) => p.published === true)).toBe(true);
    expect(root.data.some((p: any) => p.published === false)).toBe(true);
  });
  it('각 행 바인딩 필드(slug/title/current_version/creator) 채움', () => {
    for (const p of root.data) {
      expect(p.slug).toBeTruthy();
      expect(p.title).toBeTruthy();
      expect(p.current_version).toBeGreaterThanOrEqual(1);
      expect(p.creator.name).toBeTruthy();
    }
  });
});

// ─────────────────────────────────────────────────────────────────────────
// states 계약 — 게이트 본체 노출 + override 충실 shape
describe('states — 페이지 도메인 상태 정합', () => {
  const groups = spec.states.groups as any[];
  const findGroup = (m: string) => groups.find((g) => g.scope.match === m);

  it('/page/:slug draft_preview override 가 byDataSourceId.page 충실 shape + published:false', () => {
    const g = findGroup('/page/:slug');
    const draft = g.items.find((i: any) => i.id === 'draft_preview');
    const ov = draft.sampleDataOverrides.byDataSourceId.page.data;
    // byEndpointPattern 가짜 필드(body_html/status) 가 아니라 byDataSourceId 충실 shape
    expect(ov.body_html).toBeUndefined();
    expect(ov.status).toBeUndefined();
    // /page/:slug 는 basic 유저 화면(PublicPageResource — 문자열 title/content).
    // 다국어 객체로 두면 basic H1/HtmlContent 가 객체째 렌더 → 크래시(MCP 실측 결함). 문자열 shape 강제.
    expect(typeof ov.title).toBe('string');
    expect(ov.title.length).toBeGreaterThan(0);
    expect(typeof ov.content).toBe('string');
    expect(ov.published).toBe(false);              // 미게시 분기
    expect(ov.published_at).toBeNull();
    expect(hasStub(ov)).toBe(false);
  });

  it('*/admin/pages/:id/edit edit_mode 가 _local.form 다국어 폼 시드', () => {
    const g = findGroup('*/admin/pages/:id/edit');
    const edit = g.items.find((i: any) => i.id === 'edit_mode');
    const form = edit.initialState.local.form;
    expect(form.slug).toBeTruthy();
    expect(isMultilingual(form.title)).toBe(true);
    expect(isMultilingual(form.content)).toBe(true);
    expect(form.seo_meta.title).toBeDefined();
    expect(typeof form.published).toBe('boolean');
  });

  it('*/admin/pages/:id/edit validation_failed 가 폼 prefill 유지 + slug 비움(필수 오류 정합)', () => {
    const g = findGroup('*/admin/pages/:id/edit');
    const vf = g.items.find((i: any) => i.id === 'validation_failed');
    const form = vf.initialState.local.form;
    expect(form.slug).toBe('');                    // slug 필수 오류와 정합
    expect(isMultilingual(form.title)).toBe(true); // 나머지 입력값 유지
    expect(vf.formErrors['_local.errors.slug']).toBeDefined();
  });

  it('*/admin/pages/:id 상세 첨부 게이트 상태(attachments_open) 가 showAttachments:true 패치', () => {
    const g = findGroup('*/admin/pages/:id');
    expect(g).toBeDefined();
    const open = g.items.find((i: any) => i.id === 'attachments_open');
    expect(open.initialState.local.showAttachments).toBe(true);
  });
});
