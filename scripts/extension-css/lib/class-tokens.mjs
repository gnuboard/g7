/**
 * 클래스 속성 값 분석기 — 정적 토큰과 `{{ }}` 표현식 안 문자열 리터럴
 *
 * 확장 레이아웃의 클래스 값은 정적 토큰과 바인딩 표현식이 섞여 있다.
 *
 *   `px-4 {{row.status === 'done' ? 'bg-green-100' : 'bg-gray-100'}}`
 *
 * 변환 대상은 정적 토큰과 **클래스 자리에 놓인** 문자열 리터럴뿐이다. 같은 표현식 안에서도
 * 비교 피연산자(`'done'`), 메서드 인자(`.includes('x')`), 객체 리터럴 키(`{ 'a': … }`),
 * 대괄호 속성 접근(`obj['x']`)은 클래스가 아니므로 건드리지 않는다.
 * 조립형(`'bg-' + x`, `` `bg-${x}` ``)처럼 토큰 한쪽이 다른 값과 붙는 경우와 판정할 수 없는
 * 리터럴은 「수동 확인」으로 돌려준다.
 *
 * 이 파일은 파일 형식별 스캐너(JSON·`.g7.html`·PHP)도 함께 둔다. 스캐너는 클래스 값의
 * **원문 위치**만 돌려주고, 치환은 호출자가 뒤에서부터 한다(형식 보존).
 */

const COMPARE_OPS = new Set(['===', '!==', '==', '!=', '<=', '>=', '<', '>']);
const GROUP_KEYWORDS = new Set(['typeof', 'return', 'in', 'of', 'case', 'void', 'delete', 'new', 'instanceof', 'await', 'yield']);
const IDENT_CHAR = /[\w$]/;

/**
 * @typedef {object} ValueToken
 * @property {string} token 토큰 원문
 * @property {number} start 값 안 시작 위치
 * @property {number} end 값 안 끝 위치(배타)
 * @property {boolean} assembled 다른 값과 이어 붙는 조립형 여부(변환 금지)
 * @property {'static'|'literal'} origin 정적 부분인지 표현식 리터럴인지
 */

/**
 * @typedef {object} ValueNote
 * @property {string} kind 분류(`compare`·`argument`·`object-key`·`index`·`keyword-operand` 은 제외 리터럴,
 *   `undecidable`·`unclosed-binding`·`escaped-literal` 은 수동 확인)
 * @property {string} text 해당 원문
 */

/**
 * 따옴표 문자열 끝을 찾는다(템플릿 리터럴의 `${}` 중첩 포함).
 *
 * @param {string} s 원문
 * @param {number} i 여는 따옴표 위치
 * @returns {number} 닫는 따옴표 다음 위치(닫히지 않으면 s.length)
 */
function skipString(s, i) {
  const quote = s[i];
  let j = i + 1;
  while (j < s.length) {
    const ch = s[j];
    if (ch === '\\') { j += 2; continue; }
    if (ch === quote) return j + 1;
    if (quote === '`' && ch === '$' && s[j + 1] === '{') {
      j = skipBraces(s, j + 1);
      continue;
    }
    j += 1;
  }
  return s.length;
}

/**
 * `{` 에서 짝이 맞는 `}` 다음 위치를 찾는다(문자열 인지).
 *
 * @param {string} s 원문
 * @param {number} i 여는 중괄호 위치
 * @returns {number} 닫는 중괄호 다음 위치
 */
function skipBraces(s, i) {
  let depth = 0;
  let j = i;
  while (j < s.length) {
    const ch = s[j];
    if (ch === '"' || ch === "'" || ch === '`') { j = skipString(s, j); continue; }
    if (ch === '{') depth += 1;
    else if (ch === '}') { depth -= 1; if (depth === 0) return j + 1; }
    j += 1;
  }
  return s.length;
}

/**
 * 값을 정적 구간과 `{{ }}` 표현식 구간으로 나눈다.
 *
 * @param {string} value 클래스 값
 * @returns {{ segments: Array<{type:'static'|'expr', start:number, end:number}>, unclosed: boolean }}
 *   expr 구간의 start/end 는 중괄호를 뺀 표현식 본문 범위다.
 */
export function splitBindings(value) {
  const segments = [];
  let i = 0;
  let staticStart = 0;
  let unclosed = false;
  while (i < value.length) {
    if (value[i] === '{' && value[i + 1] === '{') {
      // 표현식 끝: 깊이 0 에서 만나는 `}}`
      let j = i + 2;
      let depth = 0;
      let end = -1;
      while (j < value.length) {
        const ch = value[j];
        if (ch === '"' || ch === "'" || ch === '`') { j = skipString(value, j); continue; }
        if (ch === '{') depth += 1;
        else if (ch === '}') {
          if (depth === 0 && value[j + 1] === '}') { end = j; break; }
          depth -= 1;
        }
        j += 1;
      }
      if (end === -1) { unclosed = true; break; }
      if (i > staticStart) segments.push({ type: 'static', start: staticStart, end: i });
      segments.push({ type: 'expr', start: i + 2, end });
      i = end + 2;
      staticStart = i;
      continue;
    }
    i += 1;
  }
  if (staticStart < value.length) segments.push({ type: 'static', start: staticStart, end: value.length });
  return { segments, unclosed };
}

/**
 * 구간 안 공백 구분 토큰을 뽑는다.
 *
 * @param {string} s 원문
 * @param {number} start 시작
 * @param {number} end 끝(배타)
 * @param {{left:boolean, right:boolean}} edges 구간 왼쪽·오른쪽이 다른 값과 붙어 있는지
 * @param {'static'|'literal'} origin 출처
 * @returns {ValueToken[]} 토큰
 */
function tokensIn(s, start, end, edges, origin) {
  const out = [];
  const re = /\S+/g;
  const text = s.slice(start, end);
  let m;
  while ((m = re.exec(text))) {
    const tStart = start + m.index;
    const tEnd = tStart + m[0].length;
    const assembled = (edges.left && tStart === start) || (edges.right && tEnd === end);
    out.push({ token: m[0], start: tStart, end: tEnd, assembled, origin });
  }
  return out;
}

/**
 * 위치 앞의 의미 있는 기호를 읽는다(공백 건너뜀).
 *
 * @param {string} s 원문
 * @param {number} i 기준 위치(이 위치 앞을 본다)
 * @returns {{kind:'none'|'op'|'word'|'char', text:string}} 앞 기호
 */
function prevSig(s, i) {
  let j = i - 1;
  while (j >= 0 && /\s/.test(s[j])) j -= 1;
  if (j < 0) return { kind: 'none', text: '' };
  const ch = s[j];
  if (/[=!<>]/.test(ch)) {
    let k = j;
    while (k > 0 && /[=!<>]/.test(s[k - 1])) k -= 1;
    return { kind: 'op', text: s.slice(k, j + 1) };
  }
  if (ch === '.' && s[j - 1] === '?') return { kind: 'char', text: '?.' };
  if (ch === '?' && s[j - 1] === '?') return { kind: 'char', text: '??' };
  if (ch === '|' && s[j - 1] === '|') return { kind: 'char', text: '||' };
  if (ch === '&' && s[j - 1] === '&') return { kind: 'char', text: '&&' };
  if (IDENT_CHAR.test(ch)) {
    let k = j;
    while (k > 0 && IDENT_CHAR.test(s[k - 1])) k -= 1;
    return { kind: 'word', text: s.slice(k, j + 1) };
  }
  return { kind: 'char', text: ch, pos: j };
}

/**
 * 위치 뒤의 의미 있는 기호를 읽는다(공백 건너뜀).
 *
 * @param {string} s 원문
 * @param {number} i 기준 위치(이 위치부터 본다)
 * @returns {{kind:'none'|'op'|'word'|'char', text:string}} 뒤 기호
 */
function nextSig(s, i) {
  let j = i;
  while (j < s.length && /\s/.test(s[j])) j += 1;
  if (j >= s.length) return { kind: 'none', text: '' };
  const ch = s[j];
  if (/[=!<>]/.test(ch)) {
    let k = j;
    while (k + 1 < s.length && /[=!<>]/.test(s[k + 1])) k += 1;
    return { kind: 'op', text: s.slice(j, k + 1) };
  }
  if (ch === '?' && s[j + 1] === '.') return { kind: 'char', text: '?.' };
  if (ch === '?' && s[j + 1] === '?') return { kind: 'char', text: '??' };
  if (IDENT_CHAR.test(ch)) {
    let k = j;
    while (k + 1 < s.length && IDENT_CHAR.test(s[k + 1])) k += 1;
    return { kind: 'word', text: s.slice(j, k + 1) };
  }
  return { kind: 'char', text: ch, pos: j };
}

/**
 * 왼쪽 `+` 상대가 다른 값과 붙는지(상대가 공백으로 끝나는 리터럴이면 붙지 않음).
 *
 * @param {string} s 원문
 * @param {{kind:string, text:string, pos?:number}} prev 앞 기호
 * @returns {boolean} 붙음 여부
 */
function gluedLeft(s, prev) {
  if (!(prev.kind === 'char' && prev.text === '+')) return false;
  let j = prev.pos - 1;
  while (j >= 0 && /\s/.test(s[j])) j -= 1;
  return !(j >= 1 && /['"`]/.test(s[j]) && /\s/.test(s[j - 1]));
}

/**
 * 오른쪽 `+` 상대가 다른 값과 붙는지(상대가 공백으로 시작하는 리터럴이면 붙지 않음).
 *
 * @param {string} s 원문
 * @param {{kind:string, text:string, pos?:number}} next 뒤 기호
 * @returns {boolean} 붙음 여부
 */
function gluedRight(s, next) {
  if (!(next.kind === 'char' && next.text === '+')) return false;
  let j = next.pos + 1;
  while (j < s.length && /\s/.test(s[j])) j += 1;
  return !(j + 1 < s.length && /['"`]/.test(s[j]) && /\s/.test(s[j + 1]));
}

/**
 * 여는 괄호의 종류를 앞 기호로 판정한다.
 *
 * @param {string} s 원문
 * @param {number} i 괄호 위치
 * @returns {'call'|'group'|'index'|'array'|'object'} 종류
 */
function frameKind(s, i) {
  const ch = s[i];
  const prev = prevSig(s, i);
  const callable = (prev.kind === 'word' && !GROUP_KEYWORDS.has(prev.text))
    || (prev.kind === 'char' && (prev.text === ')' || prev.text === ']' || prev.text === '?.'));
  if (ch === '(') return callable ? 'call' : 'group';
  if (ch === '[') return callable ? 'index' : 'array';
  return 'object';
}

/**
 * 리터럴의 문맥을 판정한다.
 *
 * @param {string} expr 표현식
 * @param {number} litStart 여는 따옴표 위치
 * @param {number} litEnd 닫는 따옴표 다음 위치
 * @param {Array<{kind:string, pos:number}>} stack 감싸는 괄호(바깥→안)
 * @param {Map<number, number>} matches 괄호 짝 위치
 * @returns {{kind:string, concatLeft:boolean, concatRight:boolean}} 판정
 */
function literalContext(expr, litStart, litEnd, stack, matches) {
  const prev = prevSig(expr, litStart);
  const next = nextSig(expr, litEnd);
  let concatLeft = gluedLeft(expr, prev);
  let concatRight = gluedRight(expr, next);
  const isCompare = (sig) => sig.kind === 'op' && COMPARE_OPS.has(sig.text);
  if (isCompare(prev) || isCompare(next)) {
    return { kind: 'compare', concatLeft, concatRight };
  }
  // 괄호 그룹 안 값은 그룹 바깥 문맥을 따른다: `(x ?? 'a') === 'b'` 의 'a' 는 비교 피연산자,
  // `'fi-' + (x ?? 'xx')` 의 'xx' 는 조립형이다(상대 리터럴이 공백으로 끝나면 조립형이 아니다).
  let k = stack.length - 1;
  for (; k >= 0 && stack[k].kind === 'group'; k -= 1) {
    const open = stack[k].pos;
    const close = matches.get(open);
    const outerPrev = prevSig(expr, open);
    const outerNext = close === undefined ? { kind: 'none', text: '' } : nextSig(expr, close + 1);
    if (isCompare(outerPrev) || isCompare(outerNext)) return { kind: 'compare', concatLeft, concatRight };
    if (gluedLeft(expr, outerPrev)) concatLeft = true;
    if (gluedRight(expr, outerNext)) concatRight = true;
  }
  const frame = stack[stack.length - 1]?.kind;
  // 호출 인자·대괄호 접근 식 안(그룹을 거쳐도)은 값이지 클래스가 아니다: `.startsWith('a.' + x)`
  if (k >= 0 && k < stack.length - 1 && stack[k].kind === 'call') return { kind: 'argument', concatLeft, concatRight };
  if (k >= 0 && k < stack.length - 1 && stack[k].kind === 'index') return { kind: 'index', concatLeft, concatRight };
  if (frame === 'call' && !(prev.kind === 'char' && (prev.text === '(' || prev.text === ','))) {
    if (prev.kind === 'char' && prev.text === '+') return { kind: 'argument', concatLeft, concatRight };
    if (next.kind === 'char' && next.text === '+') return { kind: 'argument', concatLeft, concatRight };
  }
  if (frame === 'call' && prev.kind === 'char' && (prev.text === '(' || prev.text === ',')) {
    return { kind: 'argument', concatLeft, concatRight };
  }
  if (frame === 'index') return { kind: 'index', concatLeft, concatRight };
  if (frame === 'object' && prev.kind === 'char' && (prev.text === '{' || prev.text === ',')
    && next.kind === 'char' && next.text === ':') {
    return { kind: 'object-key', concatLeft, concatRight };
  }
  if ((prev.kind === 'word' && GROUP_KEYWORDS.has(prev.text) && prev.text !== 'return')
    || (next.kind === 'word' && (next.text === 'in' || next.text === 'instanceof'))) {
    return { kind: 'keyword-operand', concatLeft, concatRight };
  }
  if (next.kind === 'char' && (next.text === '.' || next.text === '[' || next.text === '?.')) {
    return { kind: 'undecidable', concatLeft, concatRight };
  }
  return { kind: 'class', concatLeft, concatRight };
}

/**
 * 표현식 안 문자열 리터럴을 문맥과 함께 분석한다.
 *
 * @param {string} value 전체 값
 * @param {number} start 표현식 시작(값 기준)
 * @param {number} end 표현식 끝(값 기준, 배타)
 * @param {ValueToken[]} tokens 클래스 토큰 누적
 * @param {ValueNote[]} excluded 제외 리터럴 누적
 * @param {ValueNote[]} manual 수동 확인 누적
 */
function analyzeExpression(value, start, end, tokens, excluded, manual) {
  const expr = value.slice(0, end);
  const matches = matchBrackets(expr, start, end);
  const stack = [];
  let i = start;
  while (i < end) {
    const ch = expr[i];
    if (ch === '"' || ch === "'" || ch === '`') {
      const litEnd = Math.min(skipString(expr, i), end);
      const ctx = literalContext(expr, i, litEnd, stack, matches);
      const text = expr.slice(i, litEnd);
      if (ctx.kind !== 'class') {
        if (ctx.kind === 'undecidable') manual.push({ kind: 'undecidable', text });
        else if (text.length > 2) excluded.push({ kind: ctx.kind, text });
        if (ch === '`') scanTemplateSubstitutions(value, i, litEnd, tokens, excluded, manual, false);
      } else if (ch === '`') {
        scanTemplateSubstitutions(value, i, litEnd, tokens, excluded, manual, true, ctx);
      } else {
        const cStart = i + 1;
        const cEnd = litEnd - 1;
        if (expr.slice(cStart, cEnd).includes('\\')) {
          manual.push({ kind: 'escaped-literal', text });
        } else {
          tokens.push(...tokensIn(value, cStart, cEnd, { left: ctx.concatLeft, right: ctx.concatRight }, 'literal'));
        }
      }
      i = litEnd;
      continue;
    }
    if (ch === '(' || ch === '[' || ch === '{') {
      stack.push({ kind: frameKind(expr, i), pos: i });
    } else if (ch === ')' || ch === ']' || ch === '}') {
      stack.pop();
    }
    i += 1;
  }
}

/**
 * 괄호 짝 위치를 미리 구한다(문자열 인지).
 *
 * @param {string} s 원문
 * @param {number} start 시작
 * @param {number} end 끝(배타)
 * @returns {Map<number, number>} 여는 위치 → 닫는 위치
 */
function matchBrackets(s, start, end) {
  const out = new Map();
  const open = [];
  let i = start;
  while (i < end) {
    const ch = s[i];
    if (ch === '"' || ch === "'" || ch === '`') { i = skipString(s, i); continue; }
    if (ch === '(' || ch === '[' || ch === '{') open.push(i);
    else if (ch === ')' || ch === ']' || ch === '}') { const o = open.pop(); if (o !== undefined) out.set(o, i); }
    i += 1;
  }
  return out;
}

/**
 * 템플릿 리터럴의 정적 조각과 `${}` 안 표현식을 처리한다.
 *
 * @param {string} value 전체 값
 * @param {number} litStart 여는 백틱 위치
 * @param {number} litEnd 닫는 백틱 다음 위치
 * @param {ValueToken[]} tokens 클래스 토큰 누적
 * @param {ValueNote[]} excluded 제외 리터럴 누적
 * @param {ValueNote[]} manual 수동 확인 누적
 * @param {boolean} classPosition 리터럴이 클래스 자리인지
 * @param {{concatLeft:boolean, concatRight:boolean}} [ctx] 이어 붙임 정보
 */
function scanTemplateSubstitutions(value, litStart, litEnd, tokens, excluded, manual, classPosition, ctx) {
  let chunkStart = litStart + 1;
  let j = litStart + 1;
  const contentEnd = litEnd - 1;
  let afterSubst = false;
  while (j <= contentEnd) {
    const atEnd = j === contentEnd;
    const isSubst = !atEnd && value[j] === '$' && value[j + 1] === '{';
    if (!atEnd && value[j] === '\\') { j += 2; continue; }
    if (atEnd || isSubst) {
      if (classPosition && j > chunkStart) {
        const left = afterSubst || (chunkStart === litStart + 1 && Boolean(ctx?.concatLeft));
        const right = isSubst || (atEnd && Boolean(ctx?.concatRight));
        tokens.push(...tokensIn(value, chunkStart, j, { left, right }, 'literal'));
      }
      if (atEnd) break;
      const close = skipBraces(value, j + 1);
      analyzeExpression(value, j + 2, close - 1, tokens, excluded, manual);
      j = close;
      chunkStart = close;
      afterSubst = true;
      continue;
    }
    j += 1;
  }
}

/**
 * 클래스 값 하나를 분석한다.
 *
 * @param {string} value 디코드된 값
 * @returns {{ tokens: ValueToken[], excluded: ValueNote[], manual: ValueNote[] }} 분석 결과
 */
export function analyzeClassValue(value) {
  const tokens = [];
  const excluded = [];
  const manual = [];
  const { segments, unclosed } = splitBindings(value);
  if (unclosed) {
    manual.push({ kind: 'unclosed-binding', text: value });
    return { tokens, excluded, manual };
  }
  segments.forEach((seg, idx) => {
    if (seg.type === 'expr') {
      analyzeExpression(value, seg.start, seg.end, tokens, excluded, manual);
      return;
    }
    const prev = segments[idx - 1];
    const next = segments[idx + 1];
    tokens.push(...tokensIn(value, seg.start, seg.end, {
      left: Boolean(prev && prev.type === 'expr'),
      right: Boolean(next && next.type === 'expr'),
    }, 'static'));
  });
  return { tokens, excluded, manual };
}

/**
 * 값에 토큰 치환을 적용한다.
 *
 * @param {string} value 원래 값
 * @param {Array<{start:number, end:number, text:string}>} edits 치환 목록
 * @returns {string} 새 값
 */
export function applyEdits(value, edits) {
  let out = value;
  [...edits].sort((a, b) => b.start - a.start).forEach((e) => {
    out = out.slice(0, e.start) + e.text + out.slice(e.end);
  });
  return out;
}

/**
 * @typedef {object} RawSlot
 * @property {number} start 파일 안 값 시작 위치
 * @property {number} end 파일 안 값 끝 위치(배타)
 * @property {string} where 위치 설명(키 경로·속성명)
 */

/**
 * JSON 텍스트에서 클래스 값 문자열의 원문 위치를 찾는다(파싱·재직렬화 없이 형식 보존).
 *
 * 대상 키: `className`·`*ClassName`, 그리고 `classMap` 의 `base`·`default`·`variants.*`.
 *
 * @param {string} text JSON 원문
 * @returns {RawSlot[]} 값 위치(따옴표 안쪽)
 */
export function scanJsonClassSlots(text) {
  const out = [];
  const stack = [];
  let i = 0;
  while (i < text.length) {
    const ch = text[i];
    if (ch === '"') {
      const start = i + 1;
      let j = start;
      while (j < text.length && text[j] !== '"') j += text[j] === '\\' ? 2 : 1;
      const top = stack[stack.length - 1];
      if (top && top.type === 'obj' && top.expectKey) {
        top.key = JSON.parse(`"${text.slice(start, j)}"`);
        top.expectKey = false;
      } else {
        const keys = stack.map((f) => (f.type === 'obj' ? f.key : '[]'));
        if (top && top.type === 'obj' && top.key === 'name') top.nameValue = JSON.parse(`"${text.slice(start, j)}"`);
        if (isClassPath(keys)) out.push({ start, end: j, where: keys.join('.'), ancestors: [...stack].reverse() });
      }
      i = j + 1;
      continue;
    }
    if (ch === '{') stack.push({ type: 'obj', key: null, expectKey: true });
    else if (ch === '[') stack.push({ type: 'arr' });
    else if (ch === '}' || ch === ']') stack.pop();
    else if (ch === ',') {
      const top = stack[stack.length - 1];
      if (top && top.type === 'obj') top.expectKey = true;
    }
    i += 1;
  }
  // 값이 속한 컴포넌트 이름: 안쪽부터 처음 만나는 컴포넌트형 `name`(PascalCase 또는 `확장.이름`) 값
  // (`name` 키가 `props` 뒤에 와도 스캔이 끝난 뒤 정하므로 순서와 무관하다)
  return out.map(({ ancestors, ...slot }) => {
    const owner = ancestors.find((f) => typeof f.nameValue === 'string' && /^([A-Z][A-Za-z0-9]*|[\w-]+\.[\w.-]+)$/.test(f.nameValue));
    return { ...slot, component: owner ? owner.nameValue : null };
  });
}

/**
 * 키 경로가 클래스 값 자리인지 본다.
 *
 * @param {Array<string|null>} keys 바깥부터의 키 경로(배열은 `[]`)
 * @returns {boolean} 클래스 자리 여부
 */
export function isClassPath(keys) {
  const last = keys[keys.length - 1];
  if (typeof last !== 'string') return false;
  if (last === 'className' || /^[a-z][A-Za-z0-9]*ClassName$/.test(last)) return true;
  if (keys[keys.length - 2] === 'classMap' && (last === 'base' || last === 'default')) return true;
  if (keys[keys.length - 3] === 'classMap' && keys[keys.length - 2] === 'variants') return true;
  return false;
}

/**
 * `.g7.html` 텍스트에서 클래스 값의 원문 위치를 찾는다.
 *
 * 대상: 태그의 `class`·`className`·`*ClassName` 속성, `<class-map>` 의 `base`·`default` 속성,
 * `<class-map>` 안 `<variant>`·`<default>` 의 본문, 데이터 필드 `<field name="className|*ClassName" value="…">` 의
 * `value`(짝 `.json` 에서는 같은 이름의 키가 된다). 주석(`<!-- -->`) 안은 보지 않는다.
 *
 * @param {string} text 원문
 * @returns {RawSlot[]} 값 위치
 */
export function scanHtmlClassSlots(text) {
  const out = [];
  let i = 0;
  let inClassMap = false;
  while ((i = text.indexOf('<', i)) !== -1) {
    if (text.startsWith('<!--', i)) {
      const e = text.indexOf('-->', i + 4);
      i = e === -1 ? text.length : e + 3;
      continue;
    }
    let j = i + 1;
    let quote = null;
    while (j < text.length) {
      const ch = text[j];
      if (quote) { if (ch === quote) quote = null; } else if (ch === '"' || ch === "'") quote = ch;
      else if (ch === '>') break;
      j += 1;
    }
    const tag = text.slice(i, j + 1);
    const nameMatch = /^<(\/?)([A-Za-z][\w.:-]*)/.exec(tag);
    if (!nameMatch) { i += 1; continue; }
    const closing = nameMatch[1] === '/';
    const name = nameMatch[2].toLowerCase();
    const selfClosing = /\/>$/.test(tag);
    if (name === 'class-map') inClassMap = !closing && !selfClosing;
    if (!closing) {
      const attrRe = /\s([A-Za-z_:@][\w:.@-]*)\s*=\s*(?:"([^"]*)"|'([^']*)')/g;
      const isClassName = (n) => n === 'className' || /^[a-z][A-Za-z0-9]*ClassName$/.test(n);
      const fieldName = name === 'field' ? /\sname\s*=\s*(?:"([^"]*)"|'([^']*)')/.exec(tag) : null;
      const isClassField = !!fieldName && isClassName(fieldName[1] ?? fieldName[2]);
      let m;
      while ((m = attrRe.exec(tag))) {
        const attr = m[1];
        const isClass = attr === 'class' || isClassName(attr)
          || (name === 'class-map' && (attr === 'base' || attr === 'default'))
          || (isClassField && attr === 'value');
        if (!isClass) continue;
        const raw = m[2] ?? m[3];
        const valueStart = i + m.index + m[0].length - raw.length - 1;
        out.push({ start: valueStart, end: valueStart + raw.length, where: `<${name} ${attr}>`, component: name });
      }
      if (inClassMap && !selfClosing && (name === 'variant' || name === 'default')) {
        const bodyStart = j + 1;
        const bodyEnd = text.indexOf('<', bodyStart);
        if (bodyEnd > bodyStart) out.push({ start: bodyStart, end: bodyEnd, where: `<class-map><${name}>` });
      }
    }
    i = j + 1;
  }
  return out;
}

/**
 * PHP 텍스트에서 `'class' =>`·`'className' =>`·`'*ClassName' =>` 문자열 값의 원문 위치를 찾는다.
 *
 * @param {string} text 원문
 * @returns {Array<RawSlot & {quote:string, after:string}>} 값 위치와 따옴표·뒤따르는 기호
 */
export function scanPhpClassSlots(text) {
  const out = [];
  const re = /(['"])(class|className|[a-z][A-Za-z0-9]*ClassName)\1\s*=>\s*(['"])/g;
  let m;
  while ((m = re.exec(text))) {
    const quote = m[3];
    const start = m.index + m[0].length;
    let j = start;
    while (j < text.length && text[j] !== quote) j += text[j] === '\\' ? 2 : 1;
    const after = nextSig(text, j + 1).text;
    out.push({ start, end: j, where: `'${m[2]}' =>`, quote, after });
    re.lastIndex = j + 1;
  }
  return out;
}

/**
 * 파일 안 위치의 줄 번호(1부터).
 *
 * @param {string} text 원문
 * @param {number} offset 위치
 * @returns {number} 줄 번호
 */
export function lineOf(text, offset) {
  let line = 1;
  for (let k = 0; k < offset && k < text.length; k += 1) if (text.charCodeAt(k) === 10) line += 1;
  return line;
}
