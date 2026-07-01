/**
 * 게시판 도메인 편집기 샘플 데이터 계약 테스트
 *
 * 게시판 유저 화면(board/index 목록, board/show 상세, board/form 글쓰기)은 sirsoft-basic
 * 템플릿 레이아웃이며, 데이터소스를 선언한 레이아웃 소유 확장이 템플릿이므로 편집기 샘플
 * SSoT 는 템플릿 editor-spec(`editor-spec/sampleData.json`)의 `byDataSourceId` 다.
 *
 * 실제 Resource shape 대조:
 *  - posts     : PostCollection::withBoardInfo (data.data[] = PostResource::toListArray + row_type/number,
 *                data.board, data.pagination, data.abilities)
 *  - post      : PostResource::toArray (board/navigation/parent/comments/attachments/replies + abilities)
 *  - navigation: PostController::navigation (data.prev/next = {id,title,is_notice})
 *  - form_meta : posts/form-meta (data.board.user_abilities/secret_mode/categories + author + requires_password)
 *  - form_data : posts/form-data (PostResource::toFormArray — board/form 입력칸 prefill 원본)
 *
 * 바인딩 SSoT: layouts/board/{index,show,form}.json (+ partials/board/{index,show,form}/*).
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
  if (Array.isArray(node)) {
    if (node.length === 1 && node[0] === '샘플') return true;
    return node.some(hasStub);
  }
  if (node && typeof node === 'object') return Object.values(node).some(hasStub);
  return false;
}

function get(obj: any, dotted: string): any {
  return dotted.split('.').reduce((o, k) => (o == null ? o : o[k]), obj);
}

describe('게시판 도메인 (basic) — stub 0', () => {
  for (const id of ['posts', 'post', 'navigation', 'form_meta', 'form_data']) {
    it(`${id} 에 "샘플" stub 이 없다`, () => {
      expect(byId[id]).toBeDefined();
      expect(hasStub(byId[id])).toBe(false);
    });
  }
});

describe('posts (게시판 목록) — PostCollection::withBoardInfo shape', () => {
  const root = byId.posts.data;

  it('board 메타 + categories(복수) + settings 채움', () => {
    expect(root.board.name).toBeTruthy();
    expect(Array.isArray(root.board.categories)).toBe(true);
    expect(root.board.categories.length).toBeGreaterThanOrEqual(3);
    expect(root.board.settings.use_comment).toBe(true);
  });

  it('게시글 목록 ≥ 3 건', () => {
    expect(Array.isArray(root.data)).toBe(true);
    expect(root.data.length).toBeGreaterThanOrEqual(3);
  });

  it('row_type 분기 양면 공존: notice + normal + reply', () => {
    const types = root.data.map((p: any) => p.row_type);
    expect(types).toContain('notice');
    expect(types).toContain('normal');
    expect(types).toContain('reply');
  });

  it('게시글 속성 분기 공존: is_secret on/off, deleted_at 유/무, is_guest_post on/off', () => {
    expect(root.data.some((p: any) => p.is_secret === true)).toBe(true);
    expect(root.data.some((p: any) => p.is_secret === false)).toBe(true);
    expect(root.data.some((p: any) => p.deleted_at)).toBe(true);
    expect(root.data.some((p: any) => !p.deleted_at)).toBe(true);
    expect(root.data.some((p: any) => p.is_guest_post === true)).toBe(true);
    expect(root.data.some((p: any) => p.is_guest_post === false)).toBe(true);
  });

  it('각 행은 toListArray 필수 필드 보유', () => {
    for (const p of root.data) {
      for (const f of ['id', 'title', 'row_type', 'number', 'slug', 'author', 'status', 'view_count', 'comment_count', 'created_at_formatted']) {
        expect(p[f] !== undefined, `posts row missing ${f}`).toBe(true);
      }
      expect(typeof p.author.is_guest).toBe('boolean');
    }
  });

  it('pagination 전 경로 + abilities 채움', () => {
    for (const f of ['total', 'current_page', 'last_page', 'from', 'to', 'has_more_pages']) {
      expect(root.pagination[f] !== undefined, `pagination missing ${f}`).toBe(true);
    }
    expect(root.abilities.can_write).toBeDefined();
    expect(root.abilities.can_view_deleted).toBeDefined();
  });
});

describe('post (게시글 상세) — PostResource::toArray shape', () => {
  const d = byId.post.data;

  it('본문/작성자/게시판/네비게이션 채움', () => {
    expect(d.content).toBeTruthy();
    expect(d.content_mode).toBeTruthy();
    expect(d.author.name).toBeTruthy();
    expect(d.board.use_comment).toBe(true);
    expect(Array.isArray(d.board.report_types)).toBe(true);
    expect(d.board.report_types.length).toBeGreaterThanOrEqual(3);
  });

  it('comments ≥ 3 + depth 분기(0/1) + 삭제 분기 공존', () => {
    expect(Array.isArray(d.comments)).toBe(true);
    expect(d.comments.length).toBeGreaterThanOrEqual(3);
    expect(d.comments.some((c: any) => c.depth === 0)).toBe(true);
    expect(d.comments.some((c: any) => c.depth >= 1)).toBe(true);
    expect(d.comments.some((c: any) => c.deleted_at)).toBe(true);
    expect(d.comments.some((c: any) => !c.deleted_at)).toBe(true);
    for (const c of d.comments) {
      for (const f of ['id', 'post_id', 'parent_id', 'content', 'author', 'status', 'depth', 'replies_count', 'is_already_reported']) {
        expect(c[f] !== undefined, `comment missing ${f}`).toBe(true);
      }
    }
  });

  it('attachments 복수 + is_image 분기 공존', () => {
    expect(Array.isArray(d.attachments)).toBe(true);
    expect(d.attachments.length).toBeGreaterThanOrEqual(2);
    expect(d.attachments.some((a: any) => a.is_image === true)).toBe(true);
    expect(d.attachments.some((a: any) => a.is_image === false)).toBe(true);
    for (const a of d.attachments) {
      for (const f of ['id', 'hash', 'original_filename', 'mime_type', 'size_formatted', 'download_url']) {
        expect(a[f] !== undefined, `attachment missing ${f}`).toBe(true);
      }
    }
  });

  it('replies 복수 + abilities 채움', () => {
    expect(Array.isArray(d.replies)).toBe(true);
    expect(d.replies.length).toBeGreaterThanOrEqual(1);
    expect(d.abilities.can_write_comments).toBeDefined();
    expect(d.abilities.can_download).toBeDefined();
  });
});

describe('navigation — prev/next.id 분기 켜짐', () => {
  const d = byId.navigation.data;
  it('prev/next 모두 id 보유(분기 ON)', () => {
    expect(d.prev?.id).toBeTruthy();
    expect(d.next?.id).toBeTruthy();
  });
});

describe('form_meta / form_data — 글쓰기 폼 메타 + prefill', () => {
  it('form_meta.board.user_abilities + categories + secret_mode 채움', () => {
    const b = byId.form_meta.data.board;
    expect(b.user_abilities.can_write).toBe(true);
    expect(Array.isArray(b.categories)).toBe(true);
    expect(b.categories.length).toBeGreaterThanOrEqual(3);
    expect(b.secret_mode).toBeTruthy();
    expect(Array.isArray(b.allowed_extensions)).toBe(true);
  });

  it('form_data.data = toFormArray 입력칸 prefill 원본', () => {
    const d = byId.form_data.data;
    for (const f of ['title', 'content', 'content_mode', 'category', 'is_notice', 'is_secret']) {
      expect(d[f] !== undefined, `form_data missing ${f}`).toBe(true);
    }
    expect(d.title).toBeTruthy();
    expect(d.content).toBeTruthy();
  });
});
