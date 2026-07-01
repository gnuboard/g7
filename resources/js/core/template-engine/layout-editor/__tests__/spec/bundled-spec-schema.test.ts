/**
 * 번들 editor-spec.json 스키마/구조 계약 테스트.
 *
 * 코어가 발행하는 5종 번들 editor-spec(sirsoft-basic / sirsoft-admin_basic /
 * sirsoft-ecommerce / sirsoft-board / sirsoft-page)이 LayoutEditor 가 의존하는 최소 계약을
 * 충족하는지 정적 검증한다. 캔버스 미리보기가 sampleData / sampleGlobal 폴백에 의존하므로,
 * 이 키들이 누락되면 편집기 프리뷰가 즉시 깨진다.
 *
 * S6-2 후속: 각 번들 spec 의 레이아웃 data_source 전수가 sampleData
 * (byDataSourceId 또는 byEndpointPattern)로 커버되는지 검증하는 커버리지 가드를 추가.
 * 향후 레이아웃에 새 data_source 가 추가되면 sampleData 누락이 이 테스트 fail 로 잡힌다.
 *
 * @since engine-v1.50.0
 */
import { describe, it, expect } from 'vitest';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { assembleEditorSpec } from './assembleEditorSpecFixture';

const REPO_ROOT = path.resolve(__dirname, '../../../../../../..');

interface BundledSpecTarget {
  id: string;
  specPath: string;
  layoutsDir: string;
}

const TARGETS: BundledSpecTarget[] = [
  {
    id: 'sirsoft-basic',
    specPath: 'templates/_bundled/sirsoft-basic/editor-spec.json',
    layoutsDir: 'templates/_bundled/sirsoft-basic/layouts',
  },
  {
    id: 'sirsoft-admin_basic',
    specPath: 'templates/_bundled/sirsoft-admin_basic/editor-spec.json',
    layoutsDir: 'templates/_bundled/sirsoft-admin_basic/layouts',
  },
  {
    id: 'sirsoft-ecommerce',
    specPath: 'modules/_bundled/sirsoft-ecommerce/editor-spec.json',
    layoutsDir: 'modules/_bundled/sirsoft-ecommerce/resources/layouts',
  },
  {
    id: 'sirsoft-board',
    specPath: 'modules/_bundled/sirsoft-board/editor-spec.json',
    layoutsDir: 'modules/_bundled/sirsoft-board/resources/layouts',
  },
  {
    id: 'sirsoft-page',
    specPath: 'modules/_bundled/sirsoft-page/editor-spec.json',
    layoutsDir: 'modules/_bundled/sirsoft-page/resources/layouts',
  },
];

interface DataSourceRef {
  id: string;
  endpoint: string | null;
  method: string;
}

function readSpec(specPath: string): any {
  // editor-spec 은 S7 에서 manifest + `$include` 블록으로 분할됨 — 합본 헬퍼로 단일 spec 복원
  // (PHP EditorSpecAssembler 와 동일 규칙). 미분할 spec 은 원본 그대로 반환.
  const full = path.join(REPO_ROOT, specPath);
  return assembleEditorSpec(full);
}

/** 디렉토리를 재귀 walk 하여 모든 *.json 레이아웃 파일 경로를 수집한다. */
function walkLayouts(dir: string): string[] {
  const out: string[] = [];
  if (!fs.existsSync(dir)) return out;
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, entry.name);
    if (entry.isDirectory()) out.push(...walkLayouts(p));
    else if (entry.name.endsWith('.json')) out.push(p);
  }
  return out;
}

/** 레이아웃 JSON 노드를 재귀 순회하며 data_sources 배열의 항목을 누적한다. */
function collectDataSources(node: any, acc: DataSourceRef[]): void {
  if (!node || typeof node !== 'object') return;
  if (Array.isArray(node)) {
    for (const child of node) collectDataSources(child, acc);
    return;
  }
  if (Array.isArray(node.data_sources)) {
    for (const ds of node.data_sources) {
      if (ds && ds.id) {
        acc.push({ id: ds.id, endpoint: ds.endpoint ?? null, method: ds.method ?? 'GET' });
      }
    }
  }
  for (const key of Object.keys(node)) collectDataSources(node[key], acc);
}

/** glob 패턴(`*` 와일드카드)을 정규식으로 변환한다. */
function globToRegExp(glob: string): RegExp {
  const escaped = glob
    .split('*')
    .map((segment) => segment.replace(/[.+?^${}()|[\]\\]/g, '\\$&'))
    .join('.*');
  return new RegExp('^' + escaped + '$');
}

function endpointCoveredByPattern(patterns: string[], endpoint: string | null): boolean {
  if (!endpoint) return false;
  return patterns.some((pattern) => globToRegExp(pattern).test(endpoint));
}

/** 한 타깃의 미커버 data_source 목록을 반환한다. */
function findUncovered(target: BundledSpecTarget): DataSourceRef[] {
  const spec = readSpec(target.specPath);
  const sampleData = spec.sampleData || {};
  const byId = sampleData.byDataSourceId || {};
  const patterns = Object.keys(sampleData.byEndpointPattern || {});

  const layouts = walkLayouts(path.join(REPO_ROOT, target.layoutsDir));
  const uncovered: DataSourceRef[] = [];
  const seen = new Set<string>();

  for (const layoutFile of layouts) {
    let json: any;
    try {
      json = JSON.parse(fs.readFileSync(layoutFile, 'utf8'));
    } catch {
      continue;
    }
    const acc: DataSourceRef[] = [];
    collectDataSources(json, acc);
    for (const ds of acc) {
      if (seen.has(ds.id)) continue;
      seen.add(ds.id);
      const covered = byId[ds.id] !== undefined || endpointCoveredByPattern(patterns, ds.endpoint);
      if (!covered) uncovered.push(ds);
    }
  }
  return uncovered;
}

describe('번들 editor-spec.json 스키마 계약', () => {
  for (const target of TARGETS) {
    describe(target.id, () => {
      it('sampleData.byDataSourceId 계약을 갖춘다 (캔버스 프리뷰 폴백 SSoT)', () => {
        const spec = readSpec(target.specPath);
        expect(spec.sampleData).toBeTruthy();
        expect(spec.sampleData.byDataSourceId).toBeTruthy();
      });

      it('레이아웃의 모든 data_source 가 sampleData 로 커버된다', () => {
        const uncovered = findUncovered(target);
        // 미커버가 있으면 id/endpoint 를 메시지로 노출해 어떤 키를 추가해야 하는지 드러낸다.
        expect(
          uncovered,
          uncovered.length
            ? `미커버 data_source ${uncovered.length}건 — sampleData.byDataSourceId 또는 byEndpointPattern 추가 필요:\n` +
                uncovered.map((d) => `  - ${d.id} (${d.method} ${d.endpoint ?? '(WS)'})`).join('\n')
            : undefined,
        ).toHaveLength(0);
      });
    });
  }
});
