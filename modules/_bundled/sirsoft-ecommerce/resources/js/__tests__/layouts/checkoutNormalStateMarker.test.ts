/**
 * checkout 기본(normal) 상태 마커 패치 계약 테스트
 *
 * 받는분/주소/배송메모 폼 시드는 템플릿 `sampleGlobal._local` 1곳에만 둔다(상태별
 * 중복 없음). 다만 편집기 PreviewCanvas 는 그 상태에 `initialState.local`(또는
 * formErrors)이 있을 때만 baseline `_local` 을 캔버스에 force-inject 한다. 패치가
 * 없는 기본 상태(normal)는 레이아웃 자체 initLocal 이 baseline 을 덮어써 폼이 빈 칸이
 * 된다(브라우저 실측 확인). 따라서 normal 에 빈 `initialState.local: {}` 마커를 두어
 * force-inject 를 트리거한다.
 *
 * 본 테스트는 그 마커가 유지되는지(회귀 시 normal 화면 폼이 다시 비는 결함) 가드한다.
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
const spec = JSON.parse(
  fs.readFileSync(path.join(REPO_ROOT, 'modules/_bundled/sirsoft-ecommerce/editor-spec.json'), 'utf-8'),
);
const checkoutGroup = spec.states.groups.find(
  (g: any) => g.scope?.kind === 'route' && g.scope?.match === '/shop/checkout',
);
const normal = checkoutGroup?.items.find((i: any) => i.id === 'normal');

describe('checkout normal 상태 force-inject 마커', () => {
  it('checkout 상태 그룹과 기본(normal) 상태가 존재한다', () => {
    expect(checkoutGroup).toBeTruthy();
    expect(normal?.default).toBe(true);
  });

  it('normal 에 빈 local 패치 마커가 있어 baseline 폼 시드를 force-inject 한다', () => {
    // initialState.local 자체가 존재해야 PreviewCanvas 의 hasLocalPatch 가 true 가 된다.
    expect(normal?.initialState).toBeTruthy();
    expect(normal?.initialState?.local).toBeTruthy();
    // 마커는 비어 있어야 한다 — 시드 데이터는 sampleGlobal._local 1곳에만(중복 금지).
    expect(Object.keys(normal.initialState.local).length).toBe(0);
  });
});
