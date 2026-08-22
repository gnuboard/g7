/**
 * E2E: 목록 필터의 따옴표 키 인덱싱 바인딩 (`{{query['xxx[]']}}`)
 *
 * 배경: 판정 통일(engine-v1.55.0) 이전에는 단일 바인딩 판정기가 6벌로 갈라져 있었고,
 * 그중 일부 방언이 `query['sales_status[]']` 처럼 **따옴표 대괄호 인덱싱**을 단일 바인딩으로
 * 인정하지 않았다. 그 결과 같은 작성이 어느 렌더 경로를 타느냐에 따라 값이 나오기도 하고
 * `undefined` 가 되기도 했다. 데이터소스 params 자리에서 `undefined` 가 되면 **URL 로 필터를
 * 걸고 들어와도 그 필터가 서버로 전달되지 않는다** — 예외도 경고도 없이 "필터가 안 걸린
 * 전체 목록" 이 보이므로 사용자가 원인을 특정할 수 없다.
 *
 * 이 spec 은 계획서 「브라우저 검증」의 "라우팅이 바뀌는 식의 소속 화면 직접 확인" 을
 * 재현 가능한 형태로 고정한다. 스냅샷(`BindingShape.routingParity.test.ts.snap`) 기준
 * 판정이 갈리는 식 63개 중 **동작이 실제로 바뀌는 것은 따옴표 대괄호 22건**이며(숫자 인덱싱
 * 39건은 동치성 테스트로 입증됨), 그 22건은 아래 13개 목록 필터 화면에 걸려 있다.
 * 여기서는 소유 확장이 서로 다른 4개 화면을 대표로 잠근다.
 *
 * 검증 방식은 화면 눈으로 보기가 아니라 **나가는 API 요청에 필터가 실렸는가** 다 —
 * 이 결함의 관측 가능한 결과가 바로 그것이기 때문이다.
 *
 * @scenario list_filter_quoted_key_reaches_api
 * @effects filter_param_sent_to_server
 */
import { test, expect, issueToken, authenticatePage } from '../fixtures/auth';

/**
 * 대표 화면 4종.
 *
 * `param` 은 URL 에 싣는 다중값 쿼리 키(`xxx[]`), `apiParam` 은 그 값이 도달해야 하는
 * 서버측 파라미터 이름이다. 레이아웃의 데이터소스 정의에서 `{{query['<param>']}}` 가
 * `<apiParam>` 으로 매핑된다.
 */
const SCREENS = [
  {
    name: '상품 목록 — 판매상태',
    path: '/admin/ecommerce/products',
    permissions: ['sirsoft-ecommerce.product.read'],
    param: 'sales_status[]',
    value: 'selling',
    apiParam: 'sales_status',
    apiPattern: /\/api\/modules\/sirsoft-ecommerce\/admin\/products/,
  },
  {
    name: '주문 목록 — 결제수단',
    path: '/admin/ecommerce/orders',
    permissions: ['sirsoft-ecommerce.order.read'],
    param: 'payment_method[]',
    value: 'card',
    apiParam: 'payment_method',
    apiPattern: /\/api\/modules\/sirsoft-ecommerce\/admin\/orders/,
  },
  {
    name: '신고 목록 — 처리상태',
    path: '/admin/boards/reports',
    permissions: ['sirsoft-board.notice.admin.manage'],
    param: 'status[]',
    value: 'pending',
    apiParam: 'status',
    apiPattern: /\/api\/modules\/sirsoft-board\/admin\/reports/,
  },
  {
    name: '본인인증 로그 — 상태',
    path: '/admin/identity/logs',
    permissions: ['core.identity.logs.read'],
    param: 'statuses[]',
    value: 'requested',
    apiParam: 'statuses',
    apiPattern: /\/api\/admin\/identity\/(logs|verifications)/,
  },
] as const;

test.describe('목록 필터 — 따옴표 키 인덱싱 바인딩이 서버까지 도달한다', () => {
  for (const screen of SCREENS) {
    // @scenario list_filter_quoted_key_reaches_api
    // @effects filter_param_sent_to_server
    test(`${screen.name}: ?${screen.param} 가 API 요청에 실린다`, async ({ page }) => {
      // 관리자 목록 화면은 부트스트랩(레이아웃/번들/권한) 요청이 많아 여유를 둔다.
      // `networkidle` 은 쓰지 않는다 — 이 SPA 는 유휴 상태로 수렴하지 않아 항상 타임아웃난다.
      test.setTimeout(120_000);

      const token = issueToken(...screen.permissions);
      await authenticatePage(page, token);

      /** 목록 API 로 나간 요청의 전체 URL 들 */
      const listRequests: string[] = [];
      page.on('request', (req) => {
        if (screen.apiPattern.test(req.url())) listRequests.push(req.url());
      });

      // 목록 API 가 나가는 것을 직접 기다린다. goto 전에 걸어야 초기 요청을 놓치지 않는다.
      const firstListRequest = page
        .waitForRequest((req) => screen.apiPattern.test(req.url()), { timeout: 60_000 })
        .catch(() => null);

      const url = `${screen.path}?${encodeURIComponent(screen.param)}=${screen.value}`;
      await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60_000 });
      await firstListRequest;
      // 목록 요청이 여러 번(초기 + 필터 반영) 나가는 화면이 있어 잠시 더 수집한다.
      await page.waitForTimeout(3_000);

      // ① 목록 API 가 실제로 호출됐는지 먼저 확정한다.
      //    이 단언 없이 아래 필터 검사만 두면, 요청이 0건일 때도 "위반 없음" 으로
      //    조용히 통과한다(공허한 통과).
      expect(
        listRequests.length,
        `목록 API(${screen.apiPattern}) 요청이 한 건도 관측되지 않았다 — ` +
          `화면이 로드되지 않았거나 권한이 부족하다`
      ).toBeGreaterThan(0);

      // ② 그 요청 중 하나는 필터를 싣고 있어야 한다.
      const carrying = listRequests.filter((u) => {
        const qs = new URL(u).searchParams;
        // 서버는 배열을 `k[]=v` 또는 `k=v` 어느 쪽으로도 받을 수 있다.
        return (
          qs.getAll(`${screen.apiParam}[]`).includes(screen.value) ||
          qs.getAll(screen.apiParam).includes(screen.value)
        );
      });

      expect(
        carrying.length,
        `필터가 서버로 전달되지 않았다. 관측된 목록 요청:\n${listRequests.join('\n')}`
      ).toBeGreaterThan(0);

      // ③ 원본 바인딩 문자열이 그대로 새어나가지 않았는지 — 해석 실패의 다른 증상.
      for (const u of listRequests) {
        expect(decodeURIComponent(u)).not.toContain('{{');
      }
    });
  }
});
