/**
 * 화면 스타일 지문 — 브라우저에서 실행하는 함수 원문
 *
 * 확장 화면의 클래스를 다른 이름으로 바꾸는 작업(확장 CSS 자기 공급 전환)은 모양을 바꾸지 않아야
 * 한다. 그래서 전환 전후에 같은 화면의 **계산된 스타일**을 DOM 경로별로 떠서 비교한다.
 * 클래스명은 전환으로 바뀌므로 키에 쓰지 않고, 태그와 `nth-of-type` 으로 만든 경로를 키로 쓴다.
 *
 * 이 파일은 두 곳에서 쓴다.
 * - `capture-style-fingerprints.mjs` 가 읽어 Playwright `page.evaluate` 로 실행한다.
 * - DevTools·Chrome MCP `evaluate_script` 에 함수 본문을 그대로 붙여 넣어도 같은 결과가 나온다.
 *
 * 반환 형태: `{ url, width, scheme, count, styles: { [경로]: "값|값|…" }, boxes: { [경로]: "w×h" } }`
 * - `styles` 는 `PROPS` 순서의 계산값을 `|` 로 이은 문자열이다(비교 단위).
 * - `boxes` 는 테두리 상자 크기(소수 첫째 자리 반올림)다. 데이터 길이에 따라 흔들릴 수 있어
 *   비교 도구가 `styles` 와 따로 보고한다.
 */
// eslint-disable-next-line no-unused-vars
function g7StyleFingerprint() {
  const PROPS = [
    'display', 'position', 'visibility', 'color', 'background-color', 'background-image',
    'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
    'border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style',
    'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
    'border-top-left-radius', 'border-top-right-radius', 'border-bottom-right-radius', 'border-bottom-left-radius',
    'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
    'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
    'row-gap', 'column-gap', 'font-size', 'font-weight', 'font-style', 'line-height', 'text-align',
    'text-decoration-line', 'text-transform', 'white-space', 'overflow-x', 'overflow-y',
    'min-width', 'max-width', 'min-height', 'max-height',
    'grid-template-columns', 'flex-direction', 'flex-wrap', 'flex-grow', 'flex-shrink',
    'justify-content', 'align-items', 'opacity', 'box-shadow', 'outline-style', 'cursor',
    'z-index', 'top', 'right', 'bottom', 'left', 'transform', 'fill', 'stroke',
  ];
  const SKIP = new Set(['SCRIPT', 'STYLE', 'LINK', 'META', 'NOSCRIPT', 'TEMPLATE']);
  const styles = {};
  const boxes = {};

  /**
   * 형제 중 같은 태그의 순번으로 경로 조각을 만든다.
   *
   * @param {Element} el 대상 요소
   * @returns {string} `tag` 또는 `tag:n`
   */
  const segment = (el) => {
    const tag = el.tagName.toLowerCase();
    let index = 1;
    let sibling = el.previousElementSibling;
    while (sibling) {
      if (sibling.tagName === el.tagName) index += 1;
      sibling = sibling.previousElementSibling;
    }
    return index === 1 ? tag : `${tag}:${index}`;
  };

  /**
   * 요소와 그 자손을 깊이 우선으로 기록한다.
   *
   * @param {Element} el 대상 요소
   * @param {string} path 부모까지의 경로
   * @returns {void}
   */
  const walk = (el, path) => {
    if (SKIP.has(el.tagName)) return;
    const key = path ? `${path}>${segment(el)}` : segment(el);
    const cs = getComputedStyle(el);
    styles[key] = PROPS.map((p) => cs.getPropertyValue(p)).join('|');
    const rect = el.getBoundingClientRect();
    boxes[key] = `${Math.round(rect.width * 10) / 10}x${Math.round(rect.height * 10) / 10}`;
    for (const child of el.children) walk(child, key);
  };

  walk(document.body, '');
  return {
    url: location.pathname + location.search,
    width: window.innerWidth,
    scheme: document.documentElement.classList.contains('dark') ? 'dark' : 'light',
    props: PROPS,
    count: Object.keys(styles).length,
    styles,
    boxes,
  };
}
