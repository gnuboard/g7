/**
 * URL 경로의 끝·연속 슬래시를 정규화합니다.
 *
 * 중간의 연속 슬래시(`/board//free`)를 하나로 줄이고, 루트(`/`)가 아니면 끝 슬래시를 뗀다.
 * 라우트 패턴은 끝 슬래시 없는 형태로만 정의되므로(routes.json 규약) 매칭과 `route.path` 는 이 정규형을 쓴다.
 * 서버 `RequestPath::normalize()` 와 같은 규칙이다 (공개#156).
 *
 * 라우터 밖에서 주소 경로를 `route.path` 와 비교하는 곳(첫 화면 페이지뷰 거르기·언어 전환 인계)도 이 함수를
 * 거쳐야 한다 — 라우터는 진입 즉시 주소창을 정규형으로 바꾸므로, 그 전에 읽은 원본 경로와 어긋난다.
 * Router 모듈은 많은 시험이 통째로 모의하므로 함수는 이 모듈에 따로 둔다.
 *
 * @param pathname 원본 URL 경로
 * @returns 끝·연속 슬래시가 정규화된 경로 (루트는 `/` 유지)
 * @since engine-v1.84.0
 */
export function normalizePathname(pathname: string): string {
  const collapsed = pathname.replace(/\/{2,}/g, '/');

  if (collapsed.length > 1 && collapsed.endsWith('/')) {
    return collapsed.slice(0, -1);
  }

  return collapsed;
}
