/**
 * 화면은 그대로 두고 주소만 바꾼 경로 변경(URL-only) 기록
 *
 * `navigate` 의 `replace: true`(`G7Core.updateQueryParams`)는 공개 계약상 「URL 만 변경」이다 — 경로 전환
 * (`handleRouteChange`)이 일어나지 않아 화면·저장소 A/B·라우트 파라미터가 그대로다. 그런데 path 에 다른 경로를 주면
 * `window.location.pathname` 이 바뀌어, 경로 문자열로 「다른 화면」을 가르는 장치(디바운스 자동바인딩 대기 쓰기의 경로 가드 —
 * engine-v1.79.0)가 같은 화면에서 친 입력을 버리고 칸을 다시 마운트했다. 그 장치가 「주소만 바뀐 같은 화면」을 알아보도록,
 * 같은 경로 전환 세대 안에서 URL 만 바뀐 경로들을 한 묶음으로 기록한다. 경로 전환이 일어나면(세대가 바뀌면) 기록은 무효다.
 *
 * 엔진 모듈을 import 하지 않는다(순환 방지) — 세대는 호출부가 넘긴 TemplateApp 또는 `window.__templateApp` 에서 읽는다.
 *
 * @since engine-v1.81.2
 */

interface UrlOnlyPathRecord {
    generation: number;
    paths: string[];
}

interface RouteGenerationSource {
    getRouteChangeId?: () => number;
}

const readGeneration = (source: RouteGenerationSource | undefined | null): number | undefined => {
    const id = source?.getRouteChangeId?.();

    return typeof id === 'number' && Number.isFinite(id) ? id : undefined;
};

/**
 * 주소만 바꾸는 이동 직전에 부른다 — 지금 경로와 새 경로가 다르면 같은 화면의 별칭으로 기록한다.
 *
 * @param newPath 새 주소(경로·쿼리)
 * @param templateApp 경로 전환 세대를 줄 TemplateApp
 */
export const registerUrlOnlyPathChange = (newPath: string, templateApp: RouteGenerationSource | undefined | null): void => {
    if (typeof window === 'undefined') return;
    let to: string;
    try {
        to = new URL(newPath, window.location.origin).pathname;
    } catch {
        return;
    }
    const from = window.location.pathname;
    if (to === from) return;
    const generation = readGeneration(templateApp);
    if (generation === undefined) return;

    const previous = (window as any).__g7UrlOnlyPaths as UrlOnlyPathRecord | undefined;
    const paths = new Set(previous && previous.generation === generation ? previous.paths : []);
    paths.add(from);
    paths.add(to);
    (window as any).__g7UrlOnlyPaths = { generation, paths: Array.from(paths) } satisfies UrlOnlyPathRecord;
};

/**
 * 두 경로가 같은 경로 전환 세대 안에서 주소만 바뀐 같은 화면인지 판정한다.
 *
 * @param fromPath 앞 경로(대기 값을 만든 경로 등)
 * @param toPath 지금 경로
 * @returns 같은 화면의 별칭이면 true (기록이 없거나 세대가 바뀌었으면 false)
 */
export const isUrlOnlyPathAlias = (fromPath: string, toPath: string): boolean => {
    if (typeof window === 'undefined' || fromPath === toPath) return false;
    const record = (window as any).__g7UrlOnlyPaths as UrlOnlyPathRecord | undefined;
    if (!record || !Array.isArray(record.paths)) return false;
    const generation = readGeneration((window as any).__templateApp);
    if (generation === undefined || generation !== record.generation) return false;

    return record.paths.includes(fromPath) && record.paths.includes(toPath);
};
