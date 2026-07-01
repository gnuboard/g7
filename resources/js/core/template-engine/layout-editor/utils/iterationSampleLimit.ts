/**
 * iterationSampleLimit.ts — 반복 항목 편집(iteration_item) 샘플 데이터 1개 제한
 *
 * 반복 항목 편집 모드는 iteration 1개 항목 템플릿을 편집하므로 캔버스에 항목이 하나만 보여야
 * 한다. 편집 대상 iteration 원본 노드의 `iteration.source` 표현식이 가리키는 데이터
 * 배열을 dataContext 에서 찾아 **첫 1개로 잘라** 캔버스 렌더용 컨텍스트를 만든다.
 *
 * source 표현식 예: `{{recent_posts?.data?.data}}`, `{{products.data}}`, `{{items}}`.
 * `{{ }}` 와 옵셔널 체이닝(`?.`)을 제거해 점 구분 경로를 얻고, dataContext 를 그 경로로 따라가
 * 배열이면 `slice(0, 1)` 한 사본을 같은 경로에 되돌린 새 컨텍스트를 반환한다(immutable).
 *
 * 경로 해석 실패/배열 아님/빈 배열이면 원본 컨텍스트를 그대로 반환한다(안전 폴백).
 *
 * @since engine-v1.50.0
 */

/**
 * iteration source 표현식에서 데이터 접근 경로 세그먼트를 추출한다.
 *
 * `{{recent_posts?.data?.data}}` → `['recent_posts', 'data', 'data']`.
 * 바인딩 형태(`{{...}}`)가 아니거나 식별자 경로가 아니면 null.
 *
 * @param expr iteration.source 표현식
 * @returns 점 구분 경로 세그먼트 배열 또는 null
 */
export function parseIterationSourcePath(expr: string): string[] | null {
  if (typeof expr !== 'string') return null;
  const m = /^\s*\{\{\s*([\s\S]*?)\s*\}\}\s*$/.exec(expr);
  if (!m) return null;
  let inner = m[1];
  // 흔한 폴백 패턴 `<경로> ?? []` / `<경로> ?? ''` 등은 앞 경로만 취한다(널리시 병합 우변은
  // 빈 폴백이라 데이터 경로가 아님). `{{popularPosts.data ?? []}}` → `popularPosts.data`.
  const nullish = inner.indexOf('??');
  if (nullish >= 0) inner = inner.slice(0, nullish);
  // 옵셔널 체이닝/공백 제거 후 점 분리. 순수 식별자 경로(a.b.c)만 허용 — 그 외 연산자/괄호/
  // 호출/인덱싱이 남아 있으면 안전하게 미적용(null).
  inner = inner.replace(/\?\./g, '.').replace(/\s+/g, '');
  if (inner === '' || !/^[A-Za-z_$][A-Za-z0-9_$]*(\.[A-Za-z_$][A-Za-z0-9_$]*)*$/.test(inner)) {
    return null;
  }
  return inner.split('.');
}

/**
 * dataContext 를 source 경로로 따라가 그 배열을 첫 1개로 자른 사본을 반환한다(immutable).
 * 경로 미존재/배열 아님이면 원본 반환.
 *
 * @param dataContext 캔버스 렌더용 데이터 컨텍스트
 * @param sourceExpr iteration.source 표현식
 * @returns 해당 배열만 1개로 제한된 새 컨텍스트 (또는 원본)
 */
export function limitIterationSourceToOne(
  dataContext: Record<string, any>,
  sourceExpr: string,
): Record<string, any> {
  const segs = parseIterationSourcePath(sourceExpr);
  if (!segs || segs.length === 0) return dataContext;

  // 경로를 따라가며 최종 배열 위치 확인.
  let cursor: any = dataContext;
  for (let i = 0; i < segs.length; i++) {
    if (cursor == null || typeof cursor !== 'object') return dataContext;
    cursor = cursor[segs[i]];
  }
  if (!Array.isArray(cursor) || cursor.length <= 1) return dataContext;

  // 경로를 따라 얕은 복사하며 마지막 배열만 slice(0,1) 로 교체(나머지 컨텍스트 보존).
  const root: Record<string, any> = { ...dataContext };
  let node: any = root;
  for (let i = 0; i < segs.length - 1; i++) {
    const key = segs[i];
    const child = node[key];
    if (child == null || typeof child !== 'object') return dataContext;
    node[key] = Array.isArray(child) ? [...child] : { ...child };
    node = node[key];
  }
  node[segs[segs.length - 1]] = (cursor as unknown[]).slice(0, 1);
  return root;
}
