/**
 * 파일 응답(blob)을 로그인 토큰을 실어 받아 저장하는 핸들러.
 *
 * 관리자 주문 목록의 엑셀 내려받기 창이 쓴다. 코어 `apiCall` 은 응답을 JSON 으로만 읽어 파일 바이트를
 * 넘겨주지 못하므로, 파일을 돌려주는 요청은 이 핸들러가 코어 ApiClient(`G7Core.api.get`/`post`,
 * `responseType: 'blob'`)로 직접 보낸다 — Authorization 헤더가 자동으로 실린다. 파일명은 서버가 아니라
 * 레이아웃이 정해 넘긴다.
 *
 * 이 모듈이 직접 소유한다 — 관리자 레이아웃은 어느 관리자 템플릿으로 그려지든 같은 동작을 해야 하므로
 * 특정 템플릿이 등록한 핸들러에 기대지 않는다. 코어에는 파일 내려받기 핸들러가 없다.
 *
 * 실패하면 예외를 던진다 — 안내 문구와 진행 상태 해제는 레이아웃이 `onError`(`<fail>`)에서 한다.
 */

/** ActionDispatcher 가 넘기는 액션 객체 중 이 핸들러가 읽는 부분 */
interface DownloadFileAction {
    params?: {
        url?: unknown;
        method?: unknown;
        body?: unknown;
        filename?: unknown;
    };
}

/**
 * 파일을 토큰 동반 요청으로 받아 지정한 파일명으로 저장한다.
 *
 * @param action `url`(요청 주소)·`method`(GET|POST, 기본 GET)·`body`(POST 본문)·`filename`(저장할 파일명)
 *               파라미터를 실은 액션 객체
 * @param _context 액션 컨텍스트 (미사용)
 * @returns 저장을 시작했으면 true, 주소가 없어 건너뛰었으면 false
 * @throws {Error} 코어 ApiClient 가 없거나 요청이 실패했을 때
 */
export async function downloadFileHandler(
    action?: DownloadFileAction,
    _context?: unknown
): Promise<boolean> {
    const url = typeof action?.params?.url === 'string' ? action.params.url : '';
    const filename = typeof action?.params?.filename === 'string' ? action.params.filename : '';
    const usePost = typeof action?.params?.method === 'string' && action.params.method.toUpperCase() === 'POST';

    if (url === '') {
        return false;
    }

    const api = (window as any).G7Core?.api;
    const request = usePost ? api?.post : api?.get;

    if (typeof request !== 'function') {
        throw new Error('G7Core.api is not available');
    }

    // 코어 ApiClient 경로 → Authorization(Bearer) 헤더가 자동으로 실린다.
    const blob: Blob | undefined = usePost
        ? await api.post(url, action?.params?.body ?? {}, { responseType: 'blob' })
        : await api.get(url, { responseType: 'blob' });

    if (!blob) {
        throw new Error('Empty download response');
    }

    const objectUrl = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = filename;
    document.body.appendChild(link);

    try {
        link.click();
    } finally {
        link.remove();
        URL.revokeObjectURL(objectUrl);
    }

    return true;
}
