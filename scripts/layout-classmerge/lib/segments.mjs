/**
 * 컴파일 산출 `.json` 에서 responsive 구간을 모으고 classMerge 변환 가능 여부를 판정한다.
 *
 * 구간 = 노드(`name` 을 가진 객체)의 `responsive.{key}` 오버라이드. 문서 전체를 키 순서대로 깊이 우선으로
 * 돈다(오버라이드 안 `children` 노드의 구간도 모은다). 엔진 적용 순서(R-4)는
 *   1) 구간 props 얕은 병합 → 2) 바인딩 → 3) classMap(구간 classMap 이 기본 classMap 을 대신) → 4) classMerge
 * 이므로, 구간 className 을 classMerge 로 옮겨도 같은지는 classMap 이 낼 수 있는 모든 값마다 확인한다.
 */

/** 토큰 배열 (공백 분리, 빈 토큰 제외) */
export function tokens(value) {
  return typeof value === 'string' ? value.split(/\s+/).filter(Boolean) : [];
}

/** 토큰 집합이 같은지 */
export function sameTokenSet(a, b) {
  const sa = new Set(tokens(a));
  const sb = new Set(tokens(b));
  if (sa.size !== sb.size) return false;
  for (const t of sa) if (!sb.has(t)) return false;
  return true;
}

function isPlainObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function hasBinding(value) {
  return typeof value === 'string' && value.includes('{{');
}

function pointerEscape(key) {
  return String(key).replace(/~/g, '~0').replace(/\//g, '~1');
}

/**
 * 문서의 모든 responsive 오버라이드를 깊이 우선 순서로 모은다.
 *
 * @param {unknown} doc 컴파일 산출 JSON
 * @returns {Array<{node: object, key: string, override: unknown, pointer: string}>}
 */
export function collectOverrides(doc) {
  const out = [];
  const walk = (value, pointer) => {
    if (Array.isArray(value)) {
      value.forEach((item, index) => walk(item, `${pointer}/${index}`));
      return;
    }
    if (!isPlainObject(value)) return;
    if (typeof value.name === 'string' && isPlainObject(value.responsive)) {
      for (const [key, override] of Object.entries(value.responsive)) {
        out.push({ node: value, key, override, pointer: `${pointer}/responsive/${pointerEscape(key)}` });
      }
    }
    for (const [key, child] of Object.entries(value)) {
      walk(child, `${pointer}/${pointerEscape(key)}`);
    }
  };
  walk(doc, '');
  return out;
}

/**
 * classMap 이 낼 수 있는 클래스 문자열 후보 (`resolveClassMap` 규칙: base + variant | default | 없음).
 *
 * @param {unknown} classMap 노드 또는 구간의 classMap
 * @returns {{values: string[], binding: boolean}} values: 후보(classMap 이 없으면 [''])
 */
export function classMapOutcomes(classMap) {
  if (!isPlainObject(classMap)) return { values: [''], binding: false };
  const base = typeof classMap.base === 'string' ? classMap.base.trim() : '';
  const join = (variant) => [base, typeof variant === 'string' ? variant.trim() : ''].filter(Boolean).join(' ');
  const values = [];
  let binding = hasBinding(base);
  if (isPlainObject(classMap.variants)) {
    for (const variant of Object.values(classMap.variants)) {
      if (typeof variant !== 'string') continue;
      binding = binding || hasBinding(variant);
      values.push(join(variant));
    }
  }
  if (typeof classMap.default === 'string' && classMap.default.trim() !== '') {
    binding = binding || hasBinding(classMap.default);
    values.push(join(classMap.default));
  } else {
    values.push(join(''));
  }
  return { values: [...new Set(values)], binding };
}

/** 문자열 두 개를 공백으로 잇는다 (엔진 classMap 결합과 같이 빈 쪽은 뺀다) */
function joinClass(a, b) {
  return [a, b].filter((v) => typeof v === 'string' && v.trim() !== '').join(' ');
}

/**
 * 구간 하나의 변환 가능 여부를 판정한다.
 *
 * @param {{node: object, key: string, override: unknown}} entry collectOverrides 항목
 * @param {{applyResponsiveClassMerge: Function}} engine 엔진 병합 함수
 * @returns {object|null} 판정. 구간 props.className 이 없으면 null(변환 대상 아님)
 */
export function analyzeOverride(entry, engine) {
  const { node, override } = entry;
  if (!isPlainObject(override) || !isPlainObject(override.props) || !('className' in override.props)) return null;

  const segment = override.props.className;
  const baseRaw = isPlainObject(node.props) ? node.props.className : undefined;
  const result = {
    key: entry.key,
    pointer: entry.pointer,
    base: typeof baseRaw === 'string' ? baseRaw : baseRaw === undefined ? '' : baseRaw,
    segment,
    otherProps: Object.keys(override.props).filter((k) => k !== 'className'),
    status: 'skip',
    reason: null,
    diff: null,
    overlap: null,
    extra: [],
    missing: [],
  };

  if (typeof segment !== 'string' || (baseRaw !== undefined && typeof baseRaw !== 'string')) {
    result.reason = 'non-string';
    return result;
  }
  if ('classMerge' in override) {
    result.reason = 'has-class-merge';
    return result;
  }
  const base = result.base;
  if (hasBinding(segment) || hasBinding(base)) {
    result.reason = 'binding';
    return result;
  }

  const baseSet = new Set(tokens(base));
  const segTokens = [...new Set(tokens(segment))];
  const segSet = new Set(segTokens);
  const kept = segTokens.filter((t) => baseSet.has(t)).length;
  result.overlap = baseSet.size === 0 ? 0 : Math.round((kept / baseSet.size) * 1000) / 1000;

  const diff = segTokens.filter((t) => !baseSet.has(t));
  if (diff.length === 0) {
    result.reason = segSet.size === baseSet.size ? 'same-as-base' : 'pure-removal';
    result.extra = [...baseSet].filter((t) => !segSet.has(t));
    return result;
  }
  result.diff = diff.join(' ');

  const merged = new Set(tokens(engine.applyResponsiveClassMerge(base, result.diff)));
  result.extra = [...merged].filter((t) => !segSet.has(t));
  result.missing = segTokens.filter((t) => !merged.has(t));
  if (result.extra.length > 0) {
    result.reason = 'removed-base-token';
    return result;
  }
  if (result.missing.length > 0) {
    result.reason = 'merge-drops-token';
    return result;
  }

  // classMap 이 붙는 노드 — classMap 결과가 diff 와 충돌해 지워지면 화면이 달라진다
  const classMap = 'classMap' in override ? override.classMap : node.classMap;
  if (classMap !== undefined) {
    const outcomes = classMapOutcomes(classMap);
    if (outcomes.binding) {
      result.reason = 'class-map-binding';
      return result;
    }
    for (const cm of outcomes.values) {
      const before = joinClass(segment, cm);
      const after = engine.applyResponsiveClassMerge(joinClass(base, cm), result.diff);
      if (!sameTokenSet(before, after)) {
        const beforeSet = new Set(tokens(before));
        const afterSet = new Set(tokens(after));
        result.reason = 'class-map-mismatch';
        result.extra = [...afterSet].filter((t) => !beforeSet.has(t));
        result.missing = [...beforeSet].filter((t) => !afterSet.has(t));
        result.classMapValue = cm;
        return result;
      }
    }
  }

  result.status = 'convertible';
  return result;
}

/**
 * 오버라이드 하나의 실효 className 후보 (classMap 결과값마다 하나).
 *
 * 실효 = (구간 props.className ?? 기본 props.className) 에 classMap 을 붙이고, 구간 classMerge 를 덧붙인 값.
 *
 * @returns {{binding: boolean, raw: string, values: string[]}}
 */
export function effectiveClassNames(entry, engine) {
  const { node, override } = entry;
  const ov = isPlainObject(override) ? override : {};
  const segProps = isPlainObject(ov.props) ? ov.props : {};
  const baseProps = isPlainObject(node.props) ? node.props : {};
  const start = 'className' in segProps ? segProps.className : baseProps.className;
  const merge = ov.classMerge;
  const classMap = 'classMap' in ov ? ov.classMap : node.classMap;
  const raw = JSON.stringify([start ?? null, merge ?? null, classMap ?? null]);
  if ((start !== undefined && typeof start !== 'string') || hasBinding(start) || hasBinding(merge)) {
    return { binding: true, raw, values: [] };
  }
  const outcomes = classMapOutcomes(classMap);
  if (outcomes.binding) return { binding: true, raw, values: [] };
  return {
    binding: false,
    raw,
    values: outcomes.values.map((cm) => engine.applyResponsiveClassMerge(joinClass(start ?? '', cm), typeof merge === 'string' ? merge : undefined)),
  };
}

/**
 * 판정이 「변환 가능」인 구간을 JSON 사본에 적용한다 (verify 의 예상 산출 — 컴파일러가 낼 모양).
 *
 * @param {unknown} doc 원본 JSON (바꾸지 않는다)
 * @param {Map<string, string>} conversions pointer → diff
 * @returns {unknown} 적용한 사본
 */
export function applyConversions(doc, conversions) {
  const copy = structuredClone(doc);
  for (const entry of collectOverrides(copy)) {
    const diff = conversions.get(entry.pointer);
    if (diff === undefined) continue;
    const override = entry.override;
    delete override.props.className;
    if (Object.keys(override.props).length === 0) delete override.props;
    override.classMerge = diff;
  }
  return copy;
}

/**
 * 키 순서를 무시한 깊은 비교. 처음 어긋난 JSON pointer 를 돌려준다(같으면 null).
 */
export function firstDifference(a, b, pointer = '') {
  if (Array.isArray(a) || Array.isArray(b)) {
    if (!Array.isArray(a) || !Array.isArray(b)) return pointer || '/';
    if (a.length !== b.length) return `${pointer}(length ${a.length}≠${b.length})`;
    for (let i = 0; i < a.length; i++) {
      const d = firstDifference(a[i], b[i], `${pointer}/${i}`);
      if (d !== null) return d;
    }
    return null;
  }
  if (isPlainObject(a) || isPlainObject(b)) {
    if (!isPlainObject(a) || !isPlainObject(b)) return pointer || '/';
    const keys = new Set([...Object.keys(a), ...Object.keys(b)]);
    for (const key of keys) {
      if (!(key in a) || !(key in b)) return `${pointer}/${pointerEscape(key)}`;
      const d = firstDifference(a[key], b[key], `${pointer}/${pointerEscape(key)}`);
      if (d !== null) return d;
    }
    return null;
  }
  return Object.is(a, b) ? null : pointer || '/';
}
