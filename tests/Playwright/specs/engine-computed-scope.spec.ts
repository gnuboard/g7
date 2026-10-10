/**
 * E2E: computed 스코프 물려받기·정의 순서 참조 (배포 번들 기준, 공개 이슈 140 / engine-v1.74.0)
 *
 * computed 는 상태 스코프 단위로 1회 계산하고 입력이 같은 자식은 부모 값을 물려받는다. 단위 테스트는
 * React 커밋·브라우저 스케줄링을 모사하지 못하므로, 배포 번들로 실제 화면에서 부모·중첩 자식·iteration
 * 항목·모달의 computed 표시가 입력 직후 최신인지 잠근다.
 *
 * 합성 레이아웃은 제품의 레이아웃 미리보기 API 로 등록한다(상속 없는 독립 레이아웃 — 기본 컴포넌트만).
 * 미리보기 레코드는 서비스가 자동 만료시킨다.
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/engine-computed-scope.yaml 참조.
 */
import { test, expect, issueToken, authenticatePage } from './../fixtures/auth';

const span = (id: string, text: string) => ({ id, type: 'basic', name: 'Span', text });
const div = (id: string, children: unknown[]) => ({ id, type: 'basic', name: 'Div', children });

const layout = {
  version: '1.0.0',
  layout_name: '__e2e_engine_computed_scope',
  meta: { title: 'engine computed scope e2e' },
  data_sources: [],
  computed: {
    c_base: "{{(_local.filter.f0 ?? '') + '|base'}}",
    c_mid: "{{$computed.c_base + '|mid'}}",
    c_item: "{{(row.label ?? 'none') + ':' + (_local.filter.f0 ?? '')}}",
    c_parent: "{{($parent._local.filter.f0 ?? 'noparent') + '|p'}}",
  },
  components: [
    div('e_root', [
      {
        id: 'e_input', type: 'basic', name: 'Input',
        props: { name: 'e_input', type: 'text', value: "{{_local.filter.f0 ?? ''}}" },
        actions: [{ type: 'change', handler: 'setState', params: { target: 'local', 'filter.f0': '{{$event.target.value}}' } }],
      },
      span('e_mid', '{{_computed.c_mid}}'),
      div('e_n1', [div('e_n2', [div('e_n3', [span('e_deep_mid', '{{_computed.c_mid}}')])])]),
      {
        id: 'e_iter', type: 'basic', name: 'Div',
        iteration: { source: "{{[{label: 'r1'}, {label: 'r2'}]}}", item_var: 'row' },
        children: [span('e_item', '{{_computed.c_item}}'), span('e_item_mid', '{{_computed.c_mid}}')],
      },
      { id: 'e_open', type: 'basic', name: 'Button', props: { type: 'button', name: 'e_open' }, text: 'open',
        actions: [{ type: 'click', handler: 'openModal', target: 'e_modal' }] },
    ]),
  ],
  modals: [
    { id: 'e_modal', type: 'composite', name: 'Modal', props: { title: 'e2e modal' },
      children: [div('e_m_body', [span('e_m_parent', '{{_computed.c_parent}}'), div('e_m_n1', [span('e_m_parent_deep', '{{_computed.c_parent}}')])])] },
  ],
};

test.describe('computed 스코프 물려받기 (배포 번들)', () => {
  // @scenario layer=browser
  // @effects browser_computed_latest_across_parent_child_item_modal
  test('입력 직후 부모·중첩 자식·iteration 항목·모달의 computed 표시가 최신이고 콘솔 error 가 없다', async ({ page, request }) => {
    const token = issueToken('core.templates.read');
    const res = await request.post(`/api/admin/templates/sirsoft-admin_basic/layouts/${layout.layout_name}/preview`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      data: { content: layout },
    });
    expect(res.status()).toBe(200);
    const previewUrl: string = (await res.json()).data.preview_url;

    const errors: string[] = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));

    await authenticatePage(page, token);
    await page.goto(previewUrl);
    await expect(page.locator('#e_mid')).toHaveText('|base|mid', { timeout: 30_000 });

    await page.locator('input[name=e_input]').pressSequentially('ab');
    await expect(page.locator('#e_mid')).toHaveText('ab|base|mid');
    await expect(page.locator('#e_deep_mid')).toHaveText('ab|base|mid');
    await expect(page.locator('#e_item')).toHaveText(['r1:ab', 'r2:ab']);
    await expect(page.locator('#e_item_mid')).toHaveText(['ab|base|mid', 'ab|base|mid']);

    await page.locator('button[name=e_open]').click();
    await expect(page.locator('#e_m_parent')).toHaveText('ab|p');
    await expect(page.locator('#e_m_parent_deep')).toHaveText('ab|p');

    expect(errors).toEqual([]);
  });
});
