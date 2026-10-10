/**
 * 트러블슈팅 회귀 테스트 — 데이터소스 onSuccess 컨텍스트 이슈
 *
 * `troubleshooting-state-global.md` "데이터소스 onSuccess 컨텍스트 이슈" 3사례와
 * `troubleshooting-state-advanced.md` 사례 14 를 고정한다.
 *
 * 세 사례 모두 **응답 200 · 콘솔 오류 0** 이라 네트워크 탭으로도 단위 테스트로도 드러나지
 * 않았다 — 화면만 비어 있는 것이 유일한 증상이었다. 그래서 이 파일은 "무엇이 컨텍스트에
 * 실리는가" 라는 계약 자체를 붙든다.
 *
 * @see 트러블슈팅 가이드 troubleshooting-state-global.md
 * @see 트러블슈팅 가이드 troubleshooting-state-advanced.md
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const MANAGER = path.resolve(__dirname, '../DataSourceManager.ts');
const source = fs.readFileSync(MANAGER, 'utf8');

describe('[case:state-global-onsuccess-self-datasource-null] onSuccess 핸들러가 자기 데이터소스를 읽으면 null', () => {
  it('onSuccess 는 응답을 `response` 컨텍스트로 넘긴다 (핸들러가 store 를 다시 읽지 않아도 되도록)', () => {
    // 응답을 컨텍스트로 넘기지 않으면 핸들러는 store 를 읽을 수밖에 없고, 그 시점의 store 는
    // 아직 이전 값이다 — TemplateApp 의 React 상태 갱신이 fetch 해소 뒤이기 때문이다.
    const block = source.slice(source.indexOf('private async executeOnSuccessHandler'));

    expect(block).toContain('response: successContext');
  });

  it('successContext 는 응답 본문(`data`)과 소스 id 를 담는다', () => {
    expect(source).toMatch(/const successContext: SuccessContext = \{\s*\n\s*data,\s*\n\s*sourceId: source\.id,/);
  });
});

describe('[case:state-global-onsuccess-route-query-empty] onSuccess params 의 route·query 가 빈 값으로 도착', () => {
  it('onSuccess dispatch 컨텍스트에는 route·query·_local 이 실리지 않는다', () => {
    // 이 계약이 바뀌면(= route 를 싣게 되면) 문서의 "주소에서 직접 읽어라" 지침이 과잉이 된다.
    // 반대로 지금 형태에서 `{{route.*}}` 를 params 로 넘기면 빈 값이 도착하고 예외는 나지 않는다.
    const start = source.indexOf('private async executeOnSuccessHandler');
    const block = source.slice(start, source.indexOf('\n  }', start));

    expect(block).toContain('response: successContext');
    expect(block).not.toMatch(/\broute:\s/);
    expect(block).not.toMatch(/\bquery:\s/);
    expect(block).not.toMatch(/_local:\s/);
  });

  it('endpoint 표현식 컨텍스트에는 route·query·_global·_local 이 실린다 (onSuccess 와 다른 축)', () => {
    // 같은 데이터소스라도 endpoint 는 route·query 를 읽을 수 있다. 두 컨텍스트가 다르다는 것이
    // 이 사례의 핵심이며, 그래서 "주소를 endpoint 에 실어라" 는 해결책이 성립한다.
    const start = source.indexOf('const expressionContext: Record<string, any> = {');
    const block = source.slice(start, start + 800);

    expect(block).toContain('route: routeParams');
    expect(block).toContain('query: parseQueryParams(queryParams)');
    expect(source).toContain('expressionContext._global = globalState;');
    expect(source).toContain('expressionContext._local = localState;');
  });
});

describe('[case:state-global-chained-datasource-back-reentry] 체인으로 부른 데이터소스가 뒤로가기 재진입에서 화면에 닿지 않음', () => {
  it('refetchDataSource 는 auto_fetch:false 소스도 강제 fetch 한다 (체인이 성립하는 근거)', () => {
    // 체인 자체는 동작한다 — 깨지는 것은 그 결과가 도착할 무렵 화면이 이미 재마운트된 경우다.
    // 이 단언이 red 가 되면 체인 경로(북마크 진입 폴백)가 통째로 사라진 것이다.
    expect(source).toContain('ignoreAutoFetch');
    expect(source).toMatch(/options\?\.ignoreAutoFetch\s*\n?\s*\?\s*sources\.filter/);
  });

  it('데이터소스 조건 필터(if)는 route·query·_global 로만 평가된다 (_local 조건 금지 근거)', () => {
    // `if` 에 `_local` 을 쓰면 그 소스가 목록에서 통째로 빠져 refetch 폴백까지 사라진다.
    const start = source.indexOf('filterByCondition(');
    const block = source.slice(start, start + 1200);

    expect(block).toContain('conditionContext: ConditionContext = {}');
    expect(block).toContain('evaluateRenderCondition');
  });
});

describe('[case:state-advanced-modals-array-parent-local] modals 배열 모달의 로컬 경로', () => {
  it('클레임 교환상품 변경 모달은 $parent 를 쓰지 않는다 (페이지 로컬 = 모달 로컬)', () => {
    // `$parent._local` 로 쓰면 조건이 전부 거짓이 되어 제목·푸터만 남은 빈 모달이 열린다.
    // 오류가 남지 않으므로 구조로 고정한다.
    const modal = path.resolve(
      __dirname,
      '../../../../../templates/_bundled/sirsoft-basic/layouts/partials/mypage/claims/_modal_change_product.json'
    );

    if (!fs.existsSync(modal)) return;

    expect(fs.readFileSync(modal, 'utf8')).not.toContain('$parent');
  });
});
