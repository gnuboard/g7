/**
 * E2E: `iteration` 과 `if` 를 함께 쓴 노드가 항목별로 조건을 평가하는지 (배포 번들 기준)
 *
 * 배경: 조건이 항목 변수(`{{comment.depth === 0}}` 등)를 참조하면, 부모 시점 컨텍스트에는
 * 그 변수가 없다. 조건을 iteration 보다 먼저 평가하던 종전 구조에서는 조건이 항상 거짓이
 * 되어 **목록 전체가 사라졌다** — 예외도 콘솔 경고도 남지 않으므로 API 응답만 봐서는
 * 드러나지 않는다. 같은 작성이 반복 렌더 경로(`cellChildren`)에서는 정상 동작했기 때문에
 * 재현 위치를 특정하기 어려운 결함이었다.
 *
 * 단위 테스트(`DynamicRenderer.iterationCondition.test.tsx`)가 소스를 잠그고
 * `SeoIterationConditionParityTest` 가 봇(PHP) 렌더러를 잠그지만, 실제 사용자가 보는 것은
 * 빌드된 번들이므로 브라우저에서 배포 번들의 `DynamicRenderer` 를 직접 렌더해 결과를 잠근다.
 *
 * 두 렌더러를 동시에 바꾼 수정이라 한쪽만 되돌아가면 브라우저에서는 목록이 보이는데
 * 봇 화면에서만 비는 비대칭이 생긴다.
 *
 * @scenario iteration_item_condition, iteration_item_index_variable
 * @effects iteration_renders_matching_items, iteration_injects_item_index
 */
import { test, expect } from '../fixtures/auth';

/**
 * 배포 번들에서 `DynamicRenderer` 를 임의 컨테이너에 렌더하고 결과 텍스트를 돌려줍니다.
 *
 * 엔진 인스턴스는 반드시 `G7Core.get*()` 접근자로 얻는다 — 클래스의 `getInstance()` 싱글톤은
 * 화면이 실제로 쓰는 인스턴스가 아니라서(템플릿 부팅 시 앱이 자기 레지스트리를 주입한다)
 * 컴포넌트가 하나도 등록돼 있지 않고 렌더 결과가 빈 문자열이 된다.
 */
const RENDER_IN_BUNDLE = `
(componentDef, dataContext) => {
  const g7 = window.G7Core;
  const runtime = g7 && g7.__runtime;
  const React = window.React;
  const ReactDOM = window.ReactDOM;

  const missing = [];
  if (!runtime) missing.push('G7Core.__runtime');
  if (!React) missing.push('window.React');
  if (!ReactDOM || !ReactDOM.createRoot) missing.push('window.ReactDOM.createRoot');
  if (runtime && !runtime.DynamicRenderer) missing.push('__runtime.DynamicRenderer');
  for (const name of ['getComponentRegistry', 'getDataBindingEngine', 'getTranslationEngine', 'getActionDispatcher']) {
    if (!g7 || typeof g7[name] !== 'function') missing.push('G7Core.' + name);
  }
  if (missing.length) return { missing };

  const registry = g7.getComponentRegistry();
  if (!registry || !registry.hasComponent('Div')) {
    return { missing: ['registry:Div'], texts: [], html: '' };
  }

  const container = document.createElement('div');
  document.body.appendChild(container);

  const element = React.createElement(runtime.DynamicRenderer, {
    componentDef,
    dataContext,
    translationContext: { templateId: 'e2e', locale: 'ko' },
    registry,
    bindingEngine: g7.getDataBindingEngine(),
    translationEngine: g7.getTranslationEngine(),
    actionDispatcher: g7.getActionDispatcher(),
  });

  const root = ReactDOM.createRoot(container);
  return new Promise((resolve) => {
    root.render(element);
    // 커밋이 끝난 뒤 DOM 을 읽는다 (두 프레임이면 초기 effect 까지 반영된다).
    requestAnimationFrame(() => requestAnimationFrame(() => {
      const texts = Array.from(container.querySelectorAll('.e2e-item')).map(
        (n) => (n.textContent || '').trim()
      );
      const html = container.innerHTML;
      root.unmount();
      container.remove();
      resolve({ missing: [], texts, html });
    }));
  });
}
`;

test.describe('iteration + if 항목별 조건 평가', () => {
  test.beforeEach(async ({ page }) => {
    // 코어 번들이 부팅된 화면이면 충분하다(로그인 화면도 엔진을 부팅한다).
    await page.goto('/admin/login');
    // 컴포넌트 등록은 템플릿 부팅이 끝난 뒤에 일어난다. `__templateApp` 존재만 기다리면
    // 레지스트리가 비어 있는 시점에 렌더해 결과가 빈 문자열이 되고, 그러면 이 테스트가
    // "항목이 0개" 를 정상으로 오판한다.
    await page.waitForFunction(
      () => {
        const g7 = (window as any).G7Core;
        return Boolean(
          g7?.__runtime?.DynamicRenderer &&
            typeof g7.getComponentRegistry === 'function' &&
            g7.getComponentRegistry()?.hasComponent?.('Div')
        );
      },
      null,
      { timeout: 30_000 }
    );
  });

  // @scenario iteration_item_condition
  // @effects iteration_renders_matching_items
  test('항목 변수를 참조하는 if 가 목록 전체를 지우지 않는다', async ({ page }) => {
    const result: any = await page.evaluate(
      ([source, componentDef, dataContext]) =>
        // eslint-disable-next-line no-eval
        (0, eval)(source as string)(componentDef, dataContext),
      [
        RENDER_IN_BUNDLE,
        {
          type: 'basic',
          name: 'Div',
          if: '{{user.is_active}}',
          iteration: { source: '{{users}}', item_var: 'user' },
          props: { className: 'e2e-item' },
          text: '{{user.name}}',
        },
        {
          users: [
            { name: '가', is_active: true },
            { name: '나', is_active: false },
            { name: '다', is_active: true },
          ],
        },
      ] as any
    );

    expect(result.missing, `배포 번들에 필요한 런타임 표면이 없습니다: ${result.missing?.join(', ')}`).toEqual([]);
    // 수정 전: 부모 시점에 `user` 가 없어 조건이 거짓 → 항목이 0개(목록 전체 소실)
    expect(result.texts, '항목 조건이 항목별로 평가되지 않았습니다').toEqual(['가', '다']);
  });

  // @scenario iteration_item_condition
  // @effects iteration_renders_matching_items
  test('외곽 변수만 참조하는 if 는 종전대로 목록 전체를 차단한다', async ({ page }) => {
    const blocked: any = await page.evaluate(
      ([source, componentDef, dataContext]) =>
        // eslint-disable-next-line no-eval
        (0, eval)(source as string)(componentDef, dataContext),
      [
        RENDER_IN_BUNDLE,
        {
          type: 'basic',
          name: 'Div',
          if: '{{showList}}',
          iteration: { source: '{{items}}', item_var: 'item' },
          props: { className: 'e2e-item' },
          text: '{{item.name}}',
        },
        { showList: false, items: [{ name: '가' }, { name: '나' }] },
      ] as any
    );

    expect(blocked.missing).toEqual([]);
    expect(blocked.texts, '외곽 조건이 거짓인데 항목이 렌더됐습니다').toEqual([]);

    const shown: any = await page.evaluate(
      ([source, componentDef, dataContext]) =>
        // eslint-disable-next-line no-eval
        (0, eval)(source as string)(componentDef, dataContext),
      [
        RENDER_IN_BUNDLE,
        {
          type: 'basic',
          name: 'Div',
          if: '{{showList}}',
          iteration: { source: '{{items}}', item_var: 'item' },
          props: { className: 'e2e-item' },
          text: '{{item.name}}',
        },
        { showList: true, items: [{ name: '가' }, { name: '나' }] },
      ] as any
    );

    expect(shown.texts).toEqual(['가', '나']);
  });

  // @scenario iteration_item_index_variable
  // @effects iteration_injects_item_index
  test('{item_var}_index 자동 변수가 단발 렌더 경로에도 주입된다', async ({ page }) => {
    const result: any = await page.evaluate(
      ([source, componentDef, dataContext]) =>
        // eslint-disable-next-line no-eval
        (0, eval)(source as string)(componentDef, dataContext),
      [
        RENDER_IN_BUNDLE,
        {
          type: 'basic',
          name: 'Div',
          iteration: { source: '{{items}}', item_var: 'row' },
          props: { className: 'e2e-item' },
          text: '{{row_index}}',
        },
        { items: [{ name: '가' }, { name: '나' }, { name: '다' }] },
      ] as any
    );

    expect(result.missing).toEqual([]);
    // 반복 렌더 경로(renderItemChildren)에는 원래 있던 변수다 — 경로 간 격차 해소
    expect(result.texts).toEqual(['0', '1', '2']);
  });

  // @scenario iteration_item_condition
  // @effects iteration_renders_matching_items
  test('저장소 실사용 형태(게시판 댓글 접기 조건)가 항목별로 평가된다', async ({ page }) => {
    // 아래 조건식은 합성 예시가 아니라 저장소에 실제로 쓰인 작성 그대로다.
    //   modules/_bundled/sirsoft-board/resources/layouts/admin/partials/
    //     admin_board_post_detail/_comments.json
    // 항목 변수(`comment`)와 외곽 상태(`_local`/`$computed`)를 함께 참조하는 형태라,
    // 수정 전에는 부모 시점에 `comment` 가 없어 조건이 거짓 → 댓글 목록이 통째로 비었다.
    const condition =
      '{{(comment?.depth ?? 0) === 0 || (_local.collapsedReplies?.[$computed.commentRootMap?.[comment.id]] === false)}}';

    const result: any = await page.evaluate(
      ([source, componentDef, dataContext]) =>
        // eslint-disable-next-line no-eval
        (0, eval)(source as string)(componentDef, dataContext),
      [
        RENDER_IN_BUNDLE,
        {
          type: 'basic',
          name: 'Div',
          if: condition,
          iteration: { source: '{{comments}}', item_var: 'comment' },
          props: { className: 'e2e-item' },
          text: '{{comment.id}}',
        },
        {
          comments: [
            { id: 'c1', depth: 0 },
            // 답글이지만 부모가 펼쳐진 상태 → 표시
            { id: 'c2', depth: 1 },
            // 답글이고 부모가 접힌 상태 → 미표시
            { id: 'c3', depth: 1 },
          ],
          _local: { collapsedReplies: { c1: false, root2: true } },
          $computed: { commentRootMap: { c2: 'c1', c3: 'root2' } },
        },
      ] as any
    );

    expect(result.missing).toEqual([]);
    expect(result.texts, '실사용 조건이 항목별로 평가되지 않았습니다').toEqual(['c1', 'c2']);
  });
});
