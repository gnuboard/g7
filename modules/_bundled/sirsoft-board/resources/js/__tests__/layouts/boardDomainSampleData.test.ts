/**
 * 게시판 도메인 편집기 샘플 데이터 계약 테스트
 *
 * 관리자 게시판 화면(게시글 목록/상세/글쓰기, 신고 목록/상세)은 sirsoft-board 모듈 레이아웃이며,
 * 데이터소스를 선언한 레이아웃 소유 확장이 모듈이므로 편집기 샘플 SSoT 는
 * 모듈 editor-spec(`editor-spec.json`)의 `sampleData.byDataSourceId` 다.
 *
 * 실제 Resource shape 대조:
 *  - posts          : PostCollection::withBoardInfo (admin 권한 can_manage:true, ip 노출)
 *  - post           : PostResource::toArray (admin — ip_address 노출 + comments/replies/attachments)
 *  - form_meta/form_data: posts/form-meta·form-data
 *  - reports        : ReportCollection::withStatisticsAndPermissions (data[] = ReportResource, statistics, abilities)
 *  - report_detail  : ReportDetailResource (post/comment + reporters[] 인라인 + histories[] + available_actions)
 *  - reporters_list : ReportLogResource[] (data[] + pagination)
 *
 * 바인딩 SSoT: resources/layouts/admin/{admin_board_posts_index,admin_board_post_detail,
 *              admin_board_post_form,admin_board_reports_index,admin_board_reports_detail}.json (+ partials).
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
const SPEC_PATH = path.join(REPO_ROOT, 'modules/_bundled/sirsoft-board/editor-spec.json');

const spec = JSON.parse(fs.readFileSync(SPEC_PATH, 'utf-8'));
const byId = spec.sampleData.byDataSourceId as Record<string, any>;
const states = spec.states as any;

function hasStub(node: unknown): boolean {
  if (node === '샘플') return true;
  if (typeof node === 'string' && /^샘플\s/.test(node)) return true;
  if (Array.isArray(node)) {
    if (node.length === 1 && node[0] === '샘플') return true;
    return node.some(hasStub);
  }
  if (node && typeof node === 'object') return Object.values(node).some(hasStub);
  return false;
}

describe('게시판 도메인 (board admin) — stub 0', () => {
  for (const id of ['posts', 'post', 'form_meta', 'form_data', 'reports', 'report_detail', 'reporters_list']) {
    it(`${id} 에 "샘플" stub 이 없다`, () => {
      expect(byId[id]).toBeDefined();
      expect(hasStub(byId[id])).toBe(false);
    });
  }
});

describe('posts (admin 목록) — 분기 양면 + admin 권한', () => {
  const root = byId.posts.data;
  it('목록 ≥3 + row_type 3종 + secret/deleted 분기 공존', () => {
    expect(root.data.length).toBeGreaterThanOrEqual(3);
    const types = root.data.map((p: any) => p.row_type);
    expect(types).toContain('notice');
    expect(types).toContain('normal');
    expect(types).toContain('reply');
    expect(root.data.some((p: any) => p.is_secret)).toBe(true);
    expect(root.data.some((p: any) => p.deleted_at)).toBe(true);
  });
  it('admin abilities can_manage:true', () => {
    expect(root.abilities.can_manage).toBe(true);
    expect(root.abilities.can_view_deleted).toBe(true);
  });
});

describe('post (admin 상세) — ip_address 노출 + 본체 채움', () => {
  const d = byId.post.data;
  it('ip_address 노출 + can_manage', () => {
    expect(d.ip_address).toBeTruthy();
    expect(d.abilities.can_manage).toBe(true);
  });
  it('comments ≥3 (depth/삭제 분기) + attachments(image/non-image) + replies', () => {
    expect(d.comments.length).toBeGreaterThanOrEqual(3);
    expect(d.comments.some((c: any) => c.depth >= 1)).toBe(true);
    expect(d.comments.some((c: any) => c.deleted_at)).toBe(true);
    expect(d.comments.every((c: any) => c.ip_address !== undefined)).toBe(true);
    expect(d.attachments.some((a: any) => a.is_image)).toBe(true);
    expect(d.attachments.some((a: any) => !a.is_image)).toBe(true);
    expect(d.replies.length).toBeGreaterThanOrEqual(1);
  });
});

describe('reports (신고 목록) — 상태 다양 + target_type 분기 + statistics', () => {
  const root = byId.reports.data;
  it('신고 ≥3 + status 다양(pending/review/rejected/suspended 중 ≥3종)', () => {
    expect(root.data.length).toBeGreaterThanOrEqual(3);
    const statuses = new Set(root.data.map((r: any) => r.status));
    expect(statuses.size).toBeGreaterThanOrEqual(3);
  });
  it('target_type post/comment 분기 공존', () => {
    const types = root.data.map((r: any) => r.target_type);
    expect(types).toContain('post');
    expect(types).toContain('comment');
  });
  it('각 행 필수 필드 + statistics + abilities', () => {
    for (const r of root.data) {
      for (const f of ['id', 'target_type', 'target_type_label', 'status', 'status_label', 'status_variant', 'reporter', 'reason_type_label', 'report_count', 'target_status_label', 'content_preview', 'created_at']) {
        expect(r[f] !== undefined, `report row missing ${f}`).toBe(true);
      }
    }
    expect(root.statistics.total).toBeGreaterThan(0);
    expect(root.abilities.can_manage).toBe(true);
  });
});

describe('report_detail (신고 상세) — reporters 인라인 + histories + available_actions', () => {
  const d = byId.report_detail.data;
  it('대상(post) + target_status_label + available_actions(복수)', () => {
    expect(d.target_type).toBeTruthy();
    expect(d.post?.id || d.comment?.id).toBeTruthy();
    expect(d.target_status_label).toBeTruthy();
    expect(Array.isArray(d.available_actions)).toBe(true);
    expect(d.available_actions.length).toBeGreaterThanOrEqual(2);
  });
  it('reporters ≥3 (reason_detail null/값 분기) + 필수 필드', () => {
    expect(d.reporters.length).toBeGreaterThanOrEqual(3);
    expect(d.reporters.some((r: any) => r.reason_detail)).toBe(true);
    expect(d.reporters.some((r: any) => r.reason_detail === null)).toBe(true);
    for (const r of d.reporters) {
      for (const f of ['id', 'reporter', 'reason_type', 'reason_type_label', 'reported_at']) {
        expect(r[f] !== undefined, `reporter missing ${f}`).toBe(true);
      }
    }
  });
  it('histories ≥2 + reason_summary + report_count', () => {
    expect(d.histories.length).toBeGreaterThanOrEqual(2);
    for (const h of d.histories) {
      for (const f of ['id', 'type', 'processor_name', 'created_at']) {
        expect(h[f] !== undefined, `history missing ${f}`).toBe(true);
      }
    }
    expect(d.reason_summary).toBeTruthy();
    expect(d.report_count).toBeGreaterThanOrEqual(3);
    expect(d.abilities.can_view).toBeDefined();
  });
});

describe('reporters_list — ReportLogResource[] + pagination', () => {
  const root = byId.reporters_list.data;
  it('data ≥3 + pagination', () => {
    expect(root.data.length).toBeGreaterThanOrEqual(3);
    for (const f of ['total', 'from', 'to', 'per_page', 'current_page', 'last_page']) {
      expect(root.pagination[f] !== undefined, `pagination missing ${f}`).toBe(true);
    }
    for (const r of root.data) {
      expect(r.reporter?.name || r.reporter === null).toBeTruthy();
      expect(r.reason_type_label).toBeDefined();
    }
  });
});

describe('states 정정 — read_secret_locked + write 폼 시드', () => {
  const groups = states.groups as any[];
  const findGroup = (m: string) => groups.find((g) => g.scope && g.scope.match === m);

  it('read_secret_locked override 가 실제 PostResource shape (content=null, base 충실)', () => {
    const sg = findGroup('/board/:slug/:id');
    const locked = sg.items.find((it: any) => it.id === 'read_secret_locked');
    const p = locked.sampleDataOverrides.byDataSourceId.post.data;
    // base 통째 교체이므로 헤더 렌더 필드 보유 (붕괴 가드)
    expect(p.title).toBeTruthy();
    expect(p.created_at).toBeTruthy();
    expect(p.author?.name).toBeTruthy();
    expect(p.board?.name).toBeTruthy();
    // 비밀글 잠김 핵심 분기
    expect(p.is_secret).toBe(true);
    expect(p.content).toBeNull();
    expect(Array.isArray(p.comments)).toBe(true);
    expect(p.comments.length).toBe(0);
  });

  it('write edit_existing 에 _local.form prefill 시드', () => {
    const wg = findGroup('/board/:slug/write');
    const edit = wg.items.find((it: any) => it.id === 'edit_existing');
    expect(edit.initialState.route.id).toBe(1);
    expect(edit.initialState.local.form.title).toBeTruthy();
    expect(edit.initialState.local.form.content).toBeTruthy();
  });

  it('write create_mode 빈 폼 마커 + validation_failed formErrors 보존', () => {
    const wg = findGroup('/board/:slug/write');
    const create = wg.items.find((it: any) => it.id === 'create_mode');
    expect(create.initialState.route.id).toBeNull();
    expect(create.initialState.local.form).toEqual({});
    const vf = wg.items.find((it: any) => it.id === 'validation_failed');
    expect(vf.formErrors['_local.errors.title']).toBeDefined();
    expect(vf.initialState.local.form.title).toBe('');
  });
});

describe('토글 게이트 본체 상태 — 편집기 정적 시뮬레이션(클릭 불가)이라 상태로 ON 패치 필요', () => {
  const groups = states.groups as any[];
  const findGroup = (m: string) => groups.find((g) => g.scope && g.scope.match === m);

  it('admin 게시글상세: 첨부·답글 펼침 상태가 showAttachments:true + collapsedReplies[root]:false 패치', () => {
    const g = findGroup('*/admin/board/:slug/post/:id');
    expect(g, 'admin 게시글상세 states 그룹이 실제 라우트 path 와 일치해야 함').toBeDefined();
    const open = g.items.find((it: any) => it.id === 'attachments_replies_open');
    expect(open.initialState.local.showAttachments).toBe(true);
    // 대댓글 502(parent_id 501) 의 root=501 이 펼쳐져야 본체 렌더
    expect(open.initialState.local.collapsedReplies['501']).toBe(false);
  });

  it('admin 게시글상세 default·open 둘 다 route.id=142 패치 (댓글섹션 `post.data.id == route.id` 게이트 충족)', () => {
    const g = findGroup('*/admin/board/:slug/post/:id');
    const def = g.items.find((it: any) => it.id === 'default');
    const open = g.items.find((it: any) => it.id === 'attachments_replies_open');
    // 샘플 post.data.id 와 동일해야 댓글 섹션이 렌더됨
    expect(def.initialState.route.id).toBe(byId.post.data.id);
    expect(open.initialState.route.id).toBe(byId.post.data.id);
  });

  it('admin post 샘플에 첨부 image/non-image 분기 + depth>0 대댓글이 실재 (상태로 보여줄 본체)', () => {
    const d = byId.post.data;
    expect(d.attachments.some((a: any) => a.is_image)).toBe(true);
    expect(d.attachments.some((a: any) => !a.is_image)).toBe(true);
    const reply = d.comments.find((c: any) => c.depth >= 1);
    expect(reply, 'depth>0 대댓글 본체가 샘플에 있어야 펼침 상태가 의미를 가짐').toBeDefined();
    // commentRootMap 산식: depth>0 댓글의 parent_id 가 root id 가 됨
    expect(reply.parent_id).toBe(501);
  });

  it('basic 게시글상세: 답글 펼침 상태가 collapsedReplies[root]:false 패치', () => {
    const g = findGroup('/board/:slug/:id');
    const open = g.items.find((it: any) => it.id === 'replies_expanded');
    expect(open, 'basic 게시글상세 답글 펼침 상태').toBeDefined();
    expect(open.initialState.local.collapsedReplies['501']).toBe(false);
  });
});
