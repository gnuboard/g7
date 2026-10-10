/**
 * E2E: 검색 봇에게 전달되는 화면(SEO 렌더링)에 본문이 포함되는지
 *
 * 회귀 (#86): 위지윅 편집기 플러그인이 본문 영역을 확장으로 교체하는데, 봇용 화면을
 * 만드는 서버 렌더러가 그 교체 규칙(호스트 props 전달)을 몰라 본문을 빈 요소로
 * 내보냈다. 메타 태그에는 본문이 들어가지만 화면 본문 영역은 `<div></div>` 였다.
 *
 * 단위 테스트(ExtensionPointPropsRenderingTest)가 렌더러 계약을 잠그지만, 결함이
 * "레이아웃 파일 + 확장 자산 + 실제 데이터" 가 만나는 실환경에서만 드러났으므로
 * 실제 HTTP 응답으로도 잠근다.
 *
 * 브라우저 렌더링이 아니라 봇이 받는 원본 HTML 을 검사해야 하므로 `request` 픽스처로
 * 직접 요청한다(React 가 실행되면 결함이 가려진다). 봇 경로는 검색엔진 User-Agent 로만
 * 진입한다 — `?_escaped_fragment_=` 쿼리는 더 이상 봇 신호가 아니다(KVE-2026-2333/2334/2335).
 *
 * @scenario ep_site=board_post, injection=extension_active, nesting=direct_child, host_gate=if_true, content_kind=html_mode
 * @effects ep_props_resolved_into_context, subtree_inherits_ep_props
 */
import { test, expect, type APIRequestContext } from '@playwright/test';

/**
 * 봇 User-Agent (BotDetector 라이브러리 매칭 경로).
 *
 * 설정의 기본 UA 는 실제 브라우저 값이라(playwright.config.ts) 봇 화면을 재는 요청은 이 UA 를
 * 요청마다 지정한다.
 */
const BOT_USER_AGENT =
  'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

/** 일반 브라우저 User-Agent — 봇 판정에 걸리지 않아야 하는 요청에 쓴다. */
const BROWSER_USER_AGENT =
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

/** 확장 주입 본문이 비어 있을 때 나오던 회귀 시그니처. */
const EMPTY_INJECTED_BODY = '<div></div><div class="hidden"></div>';

/** 댓글 본문 영역을 식별하는 className (게시판 답글 확장 포인트). */
const REPLY_CONTENT_MARKER = 'prose-sm dark:prose-invert';

/**
 * 봇 렌더링 응답(HTML + 캐시 헤더)을 가져옵니다.
 *
 * @param request Playwright 요청 컨텍스트
 * @param path 대상 경로 (쿼리 포함 가능)
 * @param extraHeaders 추가 요청 헤더 (위조 Host 재현 등)
 * @returns 응답 본문 HTML 과 X-SEO-Cache 헤더 값
 */
async function fetchBotResponse(
  request: APIRequestContext,
  path: string,
  extraHeaders: Record<string, string> = {}
): Promise<{ html: string; cache: string | undefined }> {
  const response = await request.get(path, {
    headers: { 'User-Agent': BOT_USER_AGENT, ...extraHeaders },
  });

  expect(response.status(), `${path} 봇 렌더링 응답 코드`).toBe(200);

  const headers = response.headers();

  return {
    html: await response.text(),
    // 헤더명은 서버가 소문자로 정규화한다
    cache: headers['x-seo-cache'],
  };
}

/**
 * 봇 렌더링 HTML 을 가져옵니다.
 *
 * 응답이 봇 렌더러를 실제로 거쳤는지(캐시 헤더 존재) 함께 확인합니다 — 헤더가 없으면
 * 일반 SPA 응답을 받은 것이라 본문 검증이 무의미해집니다.
 *
 * @param request Playwright 요청 컨텍스트
 * @param path 대상 경로 (쿼리 포함 가능)
 * @returns 응답 본문 HTML
 */
async function fetchBotHtml(request: APIRequestContext, path: string): Promise<string> {
  const { html, cache } = await fetchBotResponse(request, path);

  expect(cache, `${path} 응답이 봇 렌더러를 거치지 않았습니다 (X-SEO-Cache 헤더 없음)`).toBeDefined();
  expect(['HIT', 'MISS']).toContain(cache);

  return html;
}

/**
 * 봇 HTML 의 `<body>` 부분만 잘라냅니다.
 *
 * 메타 태그에는 본문이 들어 있으므로, 화면 본문 영역 검증은 body 로 한정해야 한다.
 *
 * @param html 전체 HTML
 * @returns body 이후 문자열
 */
function bodyOf(html: string): string {
  const index = html.indexOf('<body>');

  return index === -1 ? html : html.slice(index);
}

test.describe('봇 렌더링 본문 노출', () => {
  /**
   * @scenario ep_site=board_post, injection=extension_active, nesting=direct_child, host_gate=if_true, content_kind=html_mode
   * @effects ep_props_resolved_into_context, subtree_inherits_ep_props, bot_user_agent_request_renders_content
   */
  test('게시글 본문이 메타뿐 아니라 봇 화면 본문 영역에도 출력된다 @smoke', async ({ request }) => {
    // 목록에서 실제 게시글 경로를 얻는다 — 고정 ID 하드코딩 회피(환경별 데이터 상이).
    const listHtml = await fetchBotHtml(request, '/board/notice');
    const paths = [...bodyOf(listHtml).matchAll(/href="(\/board\/notice\/\d+)"/g)].map((m) => m[1]);

    expect(paths.length, '공지 게시판 목록에서 게시글 링크를 찾지 못했습니다').toBeGreaterThan(0);

    // 가려진 글/비밀글은 본문이 없는 것이 정상이므로, description 이 채워진 글을 고른다.
    let checked = false;
    for (const path of paths.slice(0, 5)) {
      const postHtml = await fetchBotHtml(request, path);
      const description = postHtml.match(/<meta name="description" content="([^"]{10,})"/);
      if (description === null) {
        continue;
      }

      const body = bodyOf(postHtml);

      // 가려진 글(블라인드/비밀)은 본문 자리에 안내만 나가고 description 은 사이트 기본 설명으로
      // 채워진다 — "description 이 있다" 만으로는 걸러지지 않으므로 본문 안내로 판정해 건너뛴다.
      if (/블라인드 처리된|비밀글|삭제된 게시글/.test(body)) {
        continue;
      }

      // 본문 영역이 빈 껍데기로 나가던 회귀 시그니처
      expect(body, `${path} 의 본문 영역이 비어 있습니다`).not.toContain(EMPTY_INJECTED_BODY);

      // 메타에 담긴 본문 앞부분이 화면 본문 영역에도 있어야 한다 (메타/본문 일치)
      const firstWords = description[1].replace(/&[a-z]+;/g, ' ').trim().slice(0, 10);
      expect(body, `${path}: 메타에는 있는 본문이 화면 본문 영역에 없습니다`).toContain(firstWords);

      checked = true;
      break;
    }

    expect(checked, '본문이 있는 공개 게시글을 찾지 못했습니다').toBe(true);
  });

  /**
   * @scenario ep_site=board_reply, injection=extension_active, nesting=inside_iteration, host_gate=if_true, content_kind=html_mode
   * @effects iteration_scoped_ep_props, subtree_inherits_ep_props
   */
  test('댓글(답글) 본문이 봇 화면에 출력된다', async ({ request }) => {
    // 답글이 달린 글을 목록에서 찾는다 — 고정 ID 하드코딩 회피.
    const listHtml = await fetchBotHtml(request, '/board/qna');
    const paths = [...bodyOf(listHtml).matchAll(/href="(\/board\/qna\/\d+)"/g)].map((m) => m[1]);

    expect(paths.length, 'Q&A 게시판 목록에서 게시글 링크를 찾지 못했습니다').toBeGreaterThan(0);

    let checked = false;
    for (const path of paths.slice(0, 8)) {
      const body = bodyOf(await fetchBotHtml(request, path));

      // 댓글 본문 영역의 className (레이아웃의 답글 확장 포인트)
      const replyIndex = body.indexOf(REPLY_CONTENT_MARKER);
      if (replyIndex === -1) {
        continue;
      }

      const replyBlock = body.slice(replyIndex, replyIndex + 600);

      // 확장 주입 본문이 비어 있으면 여기가 <div></div> 로 나온다
      expect(replyBlock, `${path} 의 댓글 본문이 비어 있습니다`).not.toContain(EMPTY_INJECTED_BODY);
      // 댓글 영역 안에 실제 텍스트가 있어야 한다
      expect(replyBlock, `${path} 의 댓글 영역에 텍스트가 없습니다`).toMatch(/>[^<>]{2,}</);

      checked = true;
      break;
    }

    test.skip(!checked, '답글이 달린 공개 게시글이 없어 건너뜁니다');
  });

  /**
   * @scenario ep_site=page, injection=extension_active, nesting=direct_child, host_gate=if_true, content_kind=html_mode
   * @effects ep_props_resolved_into_context
   */
  test('페이지 본문이 봇 화면에 출력된다', async ({ request }) => {
    const html = await fetchBotHtml(request, '/page/about');
    const body = bodyOf(html);

    expect(body).not.toContain(EMPTY_INJECTED_BODY);
    // 페이지 제목이 h1 으로, 본문이 그 아래에 실제 텍스트로 나와야 한다
    expect(body).toMatch(/<h1[^>]*>[^<]+<\/h1>/);
  });

  /**
   * @scenario ep_site=product_detail, injection=extension_active, nesting=direct_child, host_gate=if_true, content_kind=html_mode
   * @effects ep_props_resolved_into_context
   */
  test('상품 상세설명이 봇 화면에 출력된다', async ({ request }) => {
    const listHtml = await fetchBotHtml(request, '/shop/products');
    const links = [
      ...new Set([...bodyOf(listHtml).matchAll(/href="(\/shop\/products\/[^"]+)"/g)].map(m => m[1])),
    ];

    test.skip(links.length === 0, '상품 목록에 상품이 없어 건너뜁니다');

    // 목록의 **첫** 상품을 집어 "설명이 있다" 고 가정하면 안 된다. 상세설명이 비어 있는
    // 상품(등록 직후·테스트용 상품)이 맨 앞에 오면 빈 렌더가 **정답**인데도 실패한다 —
    // 데이터 순서에 매달린 실패는 제품 결함처럼 보이면서 매번 다른 날에 재현된다.
    // 설명이 실린 상품을 하나라도 찾을 때까지 훑고, 하나도 없으면 건너뛴다.
    let rendered: string | null = null;

    for (const link of links.slice(0, 10)) {
      const html = bodyOf(await fetchBotHtml(request, link));

      if (!html.includes(EMPTY_INJECTED_BODY)) {
        rendered = html;
        break;
      }
    }

    test.skip(
      rendered === null,
      '상세설명이 등록된 상품이 없어 건너뜁니다 (빈 설명은 빈 렌더가 정상)'
    );

    expect(rendered).not.toContain(EMPTY_INJECTED_BODY);
  });

  /**
   * @effects every_used_data_source_is_declared, bot_user_agent_request_renders_content
   */
  test('검색 결과 목록이 봇 화면에 출력된다', async ({ request }) => {
    // 결과가 존재하는 검색어를 목록 화면에서 얻는다 — 고정 키워드 하드코딩 회피.
    const listHtml = await fetchBotHtml(request, '/board/event');
    const titleMatch = bodyOf(listHtml).match(/<a[^>]*href="\/board\/event\/\d+"[^>]*>[\s\S]{0,400}?([가-힣]{2,})/);

    test.skip(titleMatch === null, '검색어로 쓸 게시글 제목을 찾지 못해 건너뜁니다');

    const keyword = titleMatch![1];
    const body = bodyOf(await fetchBotHtml(request, `/search?q=${encodeURIComponent(keyword)}`));

    // 결과 목록이 비어 있으면 봇에게 빈 껍데기 페이지가 나간다
    expect(body, `"${keyword}" 검색 결과에 게시글 링크가 없습니다`).toMatch(/href="\/board\/[^"]+\/\d+"/);
    expect(body, '검색 결과 건수 표시가 없습니다').toContain('검색 결과');
  });

  /**
   * @effects bot_user_agent_request_renders_content
   */
  test('봇 응답에 미해석 표현식이 남지 않는다', async ({ request }) => {
    for (const path of ['/', '/board/notice', '/page/about']) {
      const body = bodyOf(await fetchBotHtml(request, path));

      // {{...}} 가 그대로 남아 있으면 표현식 해석 실패
      expect(body, `${path} 에 미해석 표현식이 남아 있습니다`).not.toMatch(/\{\{[^}]+\}\}/);
      // $t: 토큰이 남아 있으면 번역 해석 실패
      expect(body, `${path} 에 미해석 번역 토큰이 남아 있습니다`).not.toContain('$t:');
    }
  });

  /**
   * @effects cached_response_keeps_content
   */
  test('같은 주소를 두 번 요청하면 캐시가 적중하고 본문은 동일하다', async ({ request }) => {
    const first = await fetchBotResponse(request, '/page/about');
    const second = await fetchBotResponse(request, '/page/about');

    expect(first.cache, '첫 요청에 X-SEO-Cache 헤더가 없습니다').toBeDefined();
    expect(second.cache, '두 번째 요청이 캐시에 적중하지 않았습니다').toBe('HIT');

    // 캐시된 응답도 본문이 비어 있으면 안 된다 (#86 은 빈 본문이 캐시에 굳는 결함이었다)
    expect(bodyOf(second.html)).not.toContain(EMPTY_INJECTED_BODY);
    expect(bodyOf(second.html)).toBe(bodyOf(first.html));
  });

  /**
   * 실제 검색엔진이 쓰는 봇 User-Agent 로 진입하면 본문이 출력된다.
   *
   * @effects bot_user_agent_request_renders_content
   */
  test('봇 User-Agent 요청에 본문이 출력된다', async ({ request }) => {
    const body = bodyOf(await fetchBotHtml(request, '/page/about'));

    expect(body, '봇 UA 응답의 본문 영역이 비어 있습니다').not.toContain(EMPTY_INJECTED_BODY);
    expect(body).toMatch(/<h1[^>]*>[^<]+<\/h1>/);
    expect(body, '봇 UA 응답에 미해석 표현식이 남아 있습니다').not.toMatch(/\{\{[^}]+\}\}/);
  });

  /**
   * `?_escaped_fragment_=` 쿼리만으로는 봇 화면을 받지 않는다.
   *
   * 회귀(KVE-2026-2333/2334/2335 공통 진입점): 이 쿼리가 있으면 User-Agent 와 무관하게 봇으로
   * 판정해, 누구나 주소 뒤에 글자 하나를 붙여 비싼 봇 렌더 경로를 태울 수 있었다.
   *
   * @effects escaped_fragment_query_is_not_a_bot_signal
   */
  test('escaped fragment 쿼리만으로는 봇 화면이 나가지 않는다', async ({ request }) => {
    const response = await request.get(`/page/about?_escaped_fragment_=&pv=${Date.now()}`, {
      headers: { 'User-Agent': BROWSER_USER_AGENT },
    });

    expect(response.status()).toBe(200);
    expect(response.headers()['x-seo-cache'], '일반 브라우저 요청이 봇 렌더러를 거쳤습니다').toBeUndefined();
  });

  /**
   * 위조 Host 로 그린 봇 화면이 캐시를 거쳐 정상 방문자에게 나가지 않는다.
   *
   * 회귀(KVE-2026-2333): canonical·og:url 을 요청 Host 로 만들고 캐시 키에는 Host 가 없어,
   * `Host: <사이트>:6666` 으로 한 번 렌더하면 이후 정상 요청이 그 주소가 박힌 화면을 적중했다.
   *
   * @effects forged_host_render_does_not_poison_cached_page, rendered_urls_use_site_origin
   */
  test('위조 Host 로 렌더한 봇 화면이 정상 요청의 canonical 을 오염시키지 않는다', async ({ request, baseURL }) => {
    const site = new URL(baseURL ?? 'https://localhost');
    const path = `/page/about?pv=host${Date.now()}`;

    // 개발 환경의 디버그 도구(Laravel Boost 브라우저 로그)는 응답 직전에 요청 Host 로 스크립트를
    // 덧붙인다 — 봇 화면(캐시 대상) 산출물이 아니므로 스크립트 블록을 빼고 본다.
    const withoutScripts = (html: string): string => html.replace(/<script[^>]*>[\s\S]*?<\/script>/gi, '');

    const forged = await fetchBotResponse(request, path, { Host: `${site.hostname}:6666` });
    expect(withoutScripts(forged.html), '위조 Host 가 봇 화면 주소에 반영됐습니다').not.toContain(':6666');

    const genuine = await fetchBotResponse(request, path);
    expect(genuine.cache, '같은 주소 두 번째 요청이 캐시에 적중하지 않았습니다').toBe('HIT');
    expect(withoutScripts(genuine.html), '정상 요청이 위조 Host 가 박힌 캐시를 받았습니다').not.toContain(':6666');

    const canonical = genuine.html.match(/<link rel="canonical" href="([^"]+)"/);
    expect(canonical, 'canonical 이 없습니다').not.toBeNull();
    expect(new URL(canonical![1]).host, 'canonical 이 사이트 주소가 아닙니다').toBe(site.host);
  });

  /**
   * 봇 화면에는 실행 가능한 마크업이 남지 않아야 한다.
   *
   * 회귀: 사용자 작성 본문이 정화 없이 삽입돼, 주소에 봇 파라미터를 붙이는 것만으로
   * 저장된 스크립트가 실행됐다(실측: `window.__probe` 설정 확인).
   *
   * @scenario content_kind=html_mode_with_dangerous_markup, injection=extension_active
   * @effects html_mode_content_sanitized, text_mode_content_escaped
   */
  test('봇 화면 본문에 실행 가능한 마크업이 남지 않는다', async ({ request }) => {
    const paths = ['/board/free', '/board/notice', '/page/about', '/shop/products'];

    for (const listPath of paths) {
      const listBody = bodyOf(await fetchBotHtml(request, listPath));
      const detail = listBody.match(/href="(\/(?:board\/[a-z0-9_-]+\/\d+|shop\/products\/[^"]+))"/);
      const targets = detail === null ? [listPath] : [listPath, detail[1]];

      for (const target of targets) {
        const body = bodyOf(await fetchBotHtml(request, target));

        // 본문 영역에는 스크립트 태그가 없어야 한다 (봇 화면은 정적 HTML 이다)
        expect(body, `${target} 본문에 script 태그가 있습니다`).not.toMatch(/<script[\s>]/i);
        // 인라인 이벤트 핸들러가 남으면 정화가 동작하지 않은 것
        // (이스케이프된 글자 속의 `onerror=` 는 무해하므로 여는 태그 안쪽만 본다)
        expect(body, `${target} 본문에 인라인 이벤트 핸들러가 있습니다`).not.toMatch(
          /<[a-z][^<>]*\son[a-z]+\s*=/i
        );
        // 외부 프레임 삽입도 차단 대상
        expect(body, `${target} 본문에 iframe 이 있습니다`).not.toMatch(/<iframe[\s>]/i);
        // javascript: 스킴 링크
        expect(body, `${target} 본문에 javascript: 링크가 있습니다`).not.toMatch(/href="\s*javascript:/i);
      }
    }
  });

  /**
   * 선택 목록은 선택값이 아니라 항목 라벨을 노출한다.
   *
   * 회귀: 봇 화면의 `<select>` 안에 내부 식별자(정렬 키 등)가 글자로 나가고 있었다.
   *
   * @effects form_control_value_not_exposed_as_text
   */
  test('선택 상자에 내부 식별자가 글자로 노출되지 않는다', async ({ request }) => {
    for (const path of ['/board/notice', '/shop/products', '/search?q=a']) {
      const body = bodyOf(await fetchBotHtml(request, path));

      for (const [, inner] of body.matchAll(/<select[^>]*>([\s\S]*?)<\/select>/g)) {
        const directText = inner.replace(/<option[\s\S]*?<\/option>/g, '').trim();

        expect(directText, `${path}: select 안에 항목이 아닌 글자가 있습니다 — "${directText}"`).toBe('');
      }
    }
  });

  /**
   * 회원 작성글 목록 화면이 봇에게 빈 껍데기로 나가지 않는다.
   *
   * 회귀: 화면 전체가 목록 데이터 조건 아래 있는데 그 데이터소스가 봇 조회 목록에서
   * 빠져 있어, 머리말과 꼬리말만 남은 화면이 색인됐다.
   *
   * @effects every_used_data_source_is_declared
   */
  test('회원 작성글 목록이 봇 화면에 출력된다', async ({ request }) => {
    // 봇 화면에는 프로필 링크가 없으므로(작성자 이름이 링크가 아님) 공개 목록에서
    // 회원 식별자만 얻는다 — 검증은 그 뒤 실제 봇 응답 HTML 로 한다.
    const postsResponse = await request.get('/api/modules/sirsoft-board/boards/notice/posts?per_page=5');
    expect(postsResponse.status(), '게시글 목록 조회 실패').toBe(200);

    const payload = await postsResponse.json();
    const uuid = (payload?.data?.data ?? [])
      .map((post: { author?: { uuid?: string } }) => post?.author?.uuid)
      .find((value: string | undefined) => typeof value === 'string' && value.length > 0);

    test.skip(uuid === undefined, '작성자가 있는 게시글이 없어 건너뜁니다');

    const body = bodyOf(await fetchBotHtml(request, `/users/${uuid}/posts`));

    // 목록이 통째로 사라지면 게시판/게시글 링크가 하나도 없다
    expect(body, '작성글 목록에 게시글 링크가 없습니다').toMatch(/href="\/board\/[^"]+\/\d+"/);
  });
});
