/**
 * 번들 레이아웃과 편집기 표본이 가리키는 정적 이미지 경로가 서버에 실재하는지 검증한다.
 *
 * 쇼핑몰 상품 폼의 복사 원본 칸과 사용자 주문 완료 화면은 썸네일이 없을 때 `/images/placeholder.png`
 * 로 떨어졌고, 편집기 표본 93곳도 같은 경로를 썼다. 그 파일은 `public/` 에 없어 실화면과 편집기
 * 미리보기 모두 깨진 그림이 보였다(2026-02 부터). 2026-10-04 결정 4 로 실화면은 썸네일이 없으면
 * 아이콘 상자를 보이고, 표본은 서버에 기대지 않는 인라인 그림을 쓴다.
 *
 * 문자열 안의 `/images/…`·`/img/…` 처럼 사이트 루트에서 시작하는 이미지 경로를 모아 `public/` 아래
 * 실재하는지 본다. 라우트 패턴(`/images/:id`)은 이미지 확장자로 끝나지 않으므로 대상이 아니다.
 *
 * 시나리오 매니페스트: tests/scenarios/bundled-static-image-paths.yaml
 */
import { describe, it, expect } from 'vitest';
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { join, resolve, relative, sep } from 'node:path';

const ROOT = resolve(process.cwd());

/**
 * 텍스트에서 사이트 루트 기준 정적 이미지 경로를 뽑는다.
 *
 * @param text 파일 내용
 * @returns 경로 목록(나온 순서, 중복 포함)
 */
export function rootImagePaths(text: string): string[] {
  return [...text.matchAll(/(?<![\w:/.-])\/(?:images|img)\/[^\s"'\\)<>{}]+?\.(?:png|jpe?g|gif|svg|webp)(?![\w.])/gi)].map(
    (m) => m[0],
  );
}

/** 번들 디렉터리에서 레이아웃·확장 조각·편집기 표본 JSON 을 모은다. */
function collect(dir: string, out: string[]): void {
  for (const name of readdirSync(dir)) {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) {
      if (!/^(node_modules|__tests__|dist|tests|test-results)$/.test(name)) collect(path, out);
    } else if (path.endsWith('.json')) {
      const parts = path.split(sep);
      if (parts.includes('layouts') || parts.includes('extensions') || parts.includes('editor-spec') || name === 'editor-spec.json') {
        out.push(path);
      }
    }
  }
}

describe('번들 정적 이미지 경로 실재', () => {
  // @scenario source=fixture
  // @effects root_image_paths_are_extracted
  it('사이트 루트 이미지 경로만 뽑고 라우트 패턴과 외부 주소는 넘긴다', () => {
    const text = [
      '{"src": "{{item.thumbnail_url ?? \'/images/placeholder.png\'}}"}',
      '"<p><img src=\\"/img/a/b.jpg\\" /></p>"',
      '"path": "/images/:id"',
      '"url": "https://cdn.example.com/images/x.png"',
      '"inline": "data:image/svg+xml,%3Csvg%3E"',
    ].join('\n');

    expect(rootImagePaths(text)).toEqual(['/images/placeholder.png', '/img/a/b.jpg']);
  });

  // @scenario source=bundled
  // @effects bundled_image_paths_exist_in_public
  it('번들 레이아웃과 편집기 표본의 정적 이미지 경로가 public 에 실재한다', () => {
    const files: string[] = [];
    for (const base of ['templates/_bundled', 'modules/_bundled', 'plugins/_bundled']) collect(join(ROOT, base), files);
    expect(files.length, '검사 대상 파일이 없습니다(경로 해석 확인).').toBeGreaterThan(100);

    const missing: string[] = [];
    for (const file of files) {
      for (const path of new Set(rootImagePaths(readFileSync(file, 'utf-8')))) {
        if (!existsSync(join(ROOT, 'public', path))) missing.push(`${relative(ROOT, file).split(sep).join('/')}: ${path}`);
      }
    }

    expect(missing).toEqual([]);
  });

  // @scenario source=bundled
  // @effects editor_sample_images_do_not_point_to_absent_server_files
  it('편집기 표본의 그림 칸이 표본용으로 실재하지 않는 서버 파일(첨부 미리보기·storage)을 가리키지 않는다', () => {
    // 7.1.0 실측(영역 I2): 게시판 글 상세 첨부 미리보기(`/api/modules/sirsoft-board/boards/free/attachment/img-hash-1/preview`)와
    // 페이지 첨부(`/storage/pages/b7e2d4/thumb_…png`)가 편집기에서 깨진 그림으로 그려졌다. 표본의 그림 칸은 인라인 그림을 쓴다.
    // 확장 아이콘(`/api/extensions/{type}/{id}/icon`)처럼 서버가 늘 내어 주는 주소는 허용한다.
    const files: string[] = [];
    for (const base of ['templates/_bundled', 'modules/_bundled', 'plugins/_bundled']) collect(join(ROOT, base), files);
    const specFiles = files.filter((f) => f.split(sep).includes('editor-spec') || f.endsWith(`${sep}editor-spec.json`));
    expect(specFiles.length).toBeGreaterThan(5);

    const IMAGE_KEY = /(thumb|image|img|avatar|photo|preview_url|cover|banner)/i;
    const offenders: string[] = [];
    const visit = (node: unknown, file: string): void => {
      if (Array.isArray(node)) {
        node.forEach((child) => visit(child, file));
        return;
      }
      if (!node || typeof node !== 'object') return;
      for (const [key, value] of Object.entries(node as Record<string, unknown>)) {
        // 그림 칸 이름이거나, 이름과 무관하게 확장의 이미지 서빙 경로(`/api/plugins/{id}/images/…` — CKEditor 업로드 목록은
        // download_url 을 썸네일 src 로 쓴다)를 가리키면 본다.
        const imageKeyServerPath = IMAGE_KEY.test(key) && /^\/(api\/(modules|plugins)\/|storage\/)/.test(String(value));
        const extensionImagePath = /^\/api\/(modules|plugins)\/[^/]+\/images\//.test(String(value));
        if (typeof value === 'string' && (imageKeyServerPath || extensionImagePath)) {
          offenders.push(`${relative(ROOT, file).split(sep).join('/')}: ${key}=${value}`);
        } else {
          visit(value, file);
        }
      }
    };
    for (const file of specFiles) visit(JSON.parse(readFileSync(file, 'utf-8')), file);

    expect([...new Set(offenders)]).toEqual([]);
  });

  // @scenario source=bundled
  // @effects missing_thumbnail_falls_back_to_icon
  it('썸네일이 없으면 상품 폼 복사 원본 칸과 주문 완료 화면이 아이콘 상자를 보인다', () => {
    const screens = [
      { file: 'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_product_form.json', expr: 'copy_source?.data?.thumbnail_url' },
      { file: 'templates/_bundled/sirsoft-basic/layouts/shop/order_complete.json', expr: 'item.thumbnail_url' },
    ];

    for (const { file, expr } of screens) {
      const layout = JSON.parse(readFileSync(join(ROOT, file), 'utf-8'));
      const found: Array<{ img: Record<string, any>; siblings: Array<Record<string, any>> }> = [];
      const visit = (node: unknown, siblings: Array<Record<string, any>>): void => {
        if (Array.isArray(node)) {
          node.forEach((child) => visit(child, siblings));
          return;
        }
        if (!node || typeof node !== 'object') return;
        const record = node as Record<string, any>;
        if (record.name === 'Img' && String(record.props?.src ?? '').includes(expr)) found.push({ img: record, siblings });
        for (const [key, value] of Object.entries(record)) {
          if (key === 'children' && Array.isArray(value)) value.forEach((child) => visit(child, value));
          else visit(value, siblings);
        }
      };
      visit(layout, []);

      expect(found.length, `${file}: 썸네일 그림을 찾지 못했습니다.`).toBe(1);
      const [{ img, siblings }] = found;
      expect(img.if, file).toBe(`{{${expr}}}`);
      expect(img.props.src, file).toBe(`{{${expr}}}`);
      const fallback = siblings.find((node) => node.if === `{{!${expr}}}`);
      expect(fallback, `${file}: 썸네일이 없을 때의 대체 상자가 없습니다.`).toBeDefined();
      expect(JSON.stringify(fallback), file).toMatch(/"name":"Icon"[^}]*"props":\{[^}]*"name":"image"/);
    }
  });
});
