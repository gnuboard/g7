/**
 * 내보내기 파일을 일회용 주소로 받게 하는 핸들러.
 *
 * 「내보내기 관리」의 파일명 버튼과 목록 화면의 진행 표시 [받기]가 쓴다(#787). 파일 주소를 그냥 링크로 열면 인증 헤더가 실리지 않으므로, 먼저 코어 ApiClient
 * (`G7Core.api.post` — Authorization 헤더 자동)로 일회용 다운로드 주소를 발급받고, 브라우저가 그 주소를 직접 열게 한다.
 * 파일을 blob 으로 받아 브라우저 메모리에 올리지 않으므로 큰 파일도 스트리밍으로 저장된다. 주소는 60초 안에 한 번만 쓸 수
 * 있고, 서버가 쓰는 시점에 권한을 다시 본다. 파일명은 서버 응답(Content-Disposition)이 정하고 레이아웃이 준 이름은
 * 브라우저 저장 이름의 힌트로만 쓴다.
 *
 * 같은 모듈의 `downloadFile`(파일을 blob 으로 받아 저장 — 주문 목록 엑셀 창)과는 받는 방식이 달라 따로 둔다. 코어에는 파일
 * 내려받기 핸들러가 없다.
 *
 * 실패하면 예외를 던진다 — 안내 문구와 진행 상태 해제는 레이아웃이 `<fail>` 에서 한다.
 *
 * 클릭한 뒤 기록이 반영될 때까지 기다렸다 끝낸다 — 레이아웃은 끝나자마자 목록을 다시 불러 「n회」를 갱신하는데, 브라우저의
 * 파일 요청보다 목록 요청이 먼저 서버에 닿으면 횟수가 한 번 늦게 보인다(서버는 파일 요청을 받으면 스트리밍 전에 기록한다).
 * 고정 시간으로는 느린 망을 보장하지 못하므로, 발급 응답의 횟수(`download_count`)보다 커질 때까지 상세를 짧게 다시 읽는다.
 * 끝내 보이지 않아도 정해진 횟수 뒤에는 끝낸다 — 파일 받기 자체는 이미 시작됐다.
 */

/** 기록 반영을 확인하는 간격(ms) */
export const RECORD_POLL_INTERVAL_MS = 500;

/** 기록 반영을 확인하는 최대 횟수 */
export const RECORD_POLL_ATTEMPTS = 10;

/** ActionDispatcher 가 넘기는 액션 객체 중 이 핸들러가 읽는 부분 */
export interface DownloadExportFileAction {
    params?: {
        url?: unknown;
        filename?: unknown;
    };
}

/**
 * 일회용 다운로드 주소를 발급받아 브라우저가 직접 받게 한다.
 *
 * @param action `url`(주소 발급 API)·`filename`(저장할 파일명 힌트) 파라미터를 실은 액션 객체
 * @param _context 액션 컨텍스트 (미사용)
 * @returns 받기를 시작했으면 true, 주소가 없어 건너뛰었으면 false
 * @throws {Error} 코어 ApiClient 가 없거나 주소를 받지 못했을 때
 */
export async function downloadExportFileHandler(action?: DownloadExportFileAction, _context?: unknown): Promise<boolean> {
    const url = typeof action?.params?.url === 'string' ? action.params.url : '';
    const filename = typeof action?.params?.filename === 'string' ? action.params.filename : '';

    if (url === '') {
        return false;
    }

    const api = (window as any).G7Core?.api;
    if (typeof api?.post !== 'function') {
        throw new Error('G7Core.api is not available');
    }

    const response = await api.post(url, {});
    const fileUrl = response?.data?.url ?? response?.url;
    if (typeof fileUrl !== 'string' || fileUrl === '') {
        throw new Error('Download link was not issued');
    }

    const link = document.createElement('a');
    link.href = fileUrl;
    link.download = filename;
    document.body.appendChild(link);

    try {
        link.click();
    } finally {
        link.remove();
    }

    await waitForRecord(api, url.replace(/\/download-link$/, ''), Number(response?.data?.download_count));

    return true;
}

/**
 * 작업 상세의 다운로드 횟수가 발급 시점보다 커질 때까지 기다린다(최대 {@link RECORD_POLL_ATTEMPTS}번).
 *
 * @param api 코어 ApiClient
 * @param detailUrl 작업 상세 API 주소
 * @param before 발급 시점의 다운로드 횟수 (알 수 없으면 기다리지 않는다)
 */
async function waitForRecord(api: any, detailUrl: string, before: number): Promise<void> {
    if (!Number.isFinite(before) || typeof api?.get !== 'function') {
        return;
    }

    for (let attempt = 0; attempt < RECORD_POLL_ATTEMPTS; attempt++) {
        await new Promise((resolve) => setTimeout(resolve, RECORD_POLL_INTERVAL_MS));
        try {
            const detail = await api.get(detailUrl);
            if (Number(detail?.data?.download_count) > before) {
                return;
            }
        } catch {
            // 상세를 못 읽으면 더 기다릴 근거가 없다 — 목록 재조회에 맡긴다
            return;
        }
    }
}
