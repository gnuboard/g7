/**
 * `.g7.html` 원문에서 `<responsive>` 시작 태그와 속성값 위치를 찾고, 속성 하나만 바꿔 쓴다.
 *
 * 컴파일러 토크나이저(PHP `MarkupTokenizer`)와 같은 부분집합을 읽는다 — 요소·텍스트·주석, 속성값은 늘 인용,
 * raw text 요소 없음. 의미 판정은 하지 않는다(그건 짝 `.json` 과 컴파일러 몫). 주석 안의 `<responsive>` 는
 * 건너뛴다. 원문은 바꾸는 속성 구간 밖을 한 글자도 건드리지 않는다.
 */

/**
 * 원문의 `<responsive>` 시작 태그를 문서 순서로 모은다.
 *
 * @param {string} src `.g7.html` 원문
 * @returns {Array<{start: number, end: number, attrs: Array<{name: string, value: string|null, start: number, end: number, quote: string|null}>}>}
 */
export function scanResponsiveTags(src) {
  const found = [];
  const n = src.length;
  let i = 0;
  while (i < n) {
    const lt = src.indexOf('<', i);
    if (lt < 0) break;
    if (src.startsWith('<!--', lt)) {
      const close = src.indexOf('-->', lt + 4);
      i = close < 0 ? n : close + 3;
      continue;
    }
    if (src[lt + 1] === '/') {
      const gt = src.indexOf('>', lt);
      i = gt < 0 ? n : gt + 1;
      continue;
    }
    if (!/[A-Za-z]/.test(src[lt + 1] || '')) {
      i = lt + 1;
      continue;
    }
    const nameMatch = src.slice(lt + 1, lt + 200).match(/^[^\s/>]+/);
    const name = nameMatch[0];
    let k = lt + 1 + name.length;
    const attrs = [];
    while (k < n) {
      while (k < n && /\s/.test(src[k])) k++;
      if (src[k] === '>') {
        k++;
        break;
      }
      if (src[k] === '/' && src[k + 1] === '>') {
        k += 2;
        break;
      }
      const an = src.slice(k, k + 300).match(/^[^\s"'>/=<]+/);
      if (!an) {
        k++;
        continue;
      }
      const aStart = k;
      k += an[0].length;
      let j = k;
      while (j < n && /\s/.test(src[j])) j++;
      if (src[j] === '=') {
        j++;
        while (j < n && /\s/.test(src[j])) j++;
        const quote = src[j];
        if (quote !== '"' && quote !== "'") {
          // 무인용 값 — 컴파일러가 거부하는 형식이다. 위치를 확정할 수 없으니 값 없음으로 둔다.
          attrs.push({ name: an[0], value: null, start: aStart, end: j, quote: null });
          k = j;
          continue;
        }
        const vEnd = src.indexOf(quote, j + 1);
        const end = vEnd < 0 ? n : vEnd;
        attrs.push({ name: an[0], value: src.slice(j + 1, end), start: aStart, end: end + 1, quote });
        k = end + 1;
      } else {
        attrs.push({ name: an[0], value: null, start: aStart, end: k, quote: null });
      }
    }
    if (name === 'responsive') found.push({ start: lt, end: k, attrs });
    i = k;
  }
  return found;
}

const NAMED_REFERENCES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ' };

/**
 * 속성값의 문자 참조를 푼다 — 컴파일러처럼 세미콜론으로 끝나는 참조만(`&&` 는 그대로).
 * 모르는 이름은 풀지 않는다(컴파일러는 그런 참조를 오류로 거부한다).
 */
export function decodeReferences(raw) {
  return raw.replace(/&(#x[0-9a-fA-F]+|#[0-9]+|[A-Za-z][A-Za-z0-9]*);/g, (whole, body) => {
    if (body[0] === '#') {
      const code = body[1] === 'x' || body[1] === 'X' ? parseInt(body.slice(2), 16) : parseInt(body.slice(1), 10);
      return Number.isFinite(code) ? String.fromCodePoint(code) : whole;
    }
    return NAMED_REFERENCES[body] ?? whole;
  });
}

/**
 * 원문에 쓸 속성값 — 원문이 문자 참조를 썼으면 같은 관례로 `&`·`<`·`>`·따옴표를 다시 인코딩한다.
 */
export function encodeForAttribute(value, quote, originalRaw) {
  const usesReferences = decodeReferences(originalRaw) !== originalRaw;
  let out = usesReferences ? value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') : value;
  if (quote === '"') out = out.replace(/"/g, '&quot;');
  else if (quote === "'") out = out.replace(/'/g, '&#39;');
  return out;
}

/** 요소의 속성 하나 (없으면 null) */
export function attrOf(tag, name) {
  return tag.attrs.find((a) => a.name === name) ?? null;
}

/**
 * 구간(range) 교체 목록을 원문에 적용한다. 구간은 겹치지 않아야 한다.
 *
 * @param {string} src 원문
 * @param {Array<{start: number, end: number, text: string}>} edits 교체 목록
 * @returns {string} 바꾼 원문
 */
export function applyEdits(src, edits) {
  const sorted = [...edits].sort((a, b) => b.start - a.start);
  let out = src;
  let guard = Infinity;
  for (const edit of sorted) {
    if (edit.end > guard) throw new Error(`겹치는 교체 구간 (${edit.start}-${edit.end})`);
    out = out.slice(0, edit.start) + edit.text + out.slice(edit.end);
    guard = edit.start;
  }
  return out;
}
