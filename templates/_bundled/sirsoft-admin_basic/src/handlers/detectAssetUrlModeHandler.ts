/**
 * detectAssetUrlMode 핸들러 (이슈 #486 §8)
 *
 * 관리자 환경설정에서 자산 URL 방식을 **브라우저에서** 재감지한다.
 *
 * 서버측에서 자기 `APP_URL` 로 curl 하면 loopback 이 nginx vhost·SSL·프록시 체인을
 * 우회하거나 다른 vhost 를 타서 오판한다. 실제로 방문자가 겪는 경로를 재현하려면
 * 브라우저가 던져야 한다.
 *
 * 감지 지점이 인스톨러 하나로 부족한 이유가 여기 있다 — 관리자가 설치 6개월 뒤
 * 정적 최적화 블록을 추가하면 저장된 값은 "확장자 OK" 인 채 사이트가 다시 죽는다.
 * 이 버튼이 그 상황의 재판정 수단이다.
 */

const logger = ((window as any).G7Core?.createLogger?.('Handler:DetectAssetUrlMode')) ?? {
    log: (...args: unknown[]) => console.log('[Handler:DetectAssetUrlMode]', ...args),
    warn: (...args: unknown[]) => console.warn('[Handler:DetectAssetUrlMode]', ...args),
    error: (...args: unknown[]) => console.error('[Handler:DetectAssetUrlMode]', ...args),
};

/** 프로브 응답 본문에 담기는 매직 토큰 (서버 AssetProbeController::PROBE_TOKEN 과 동일) */
const PROBE_TOKEN = 'G7_ASSET_PROBE_OK';

/** 판정 결과 */
export type DetectResult = 'extension' | 'extensionless' | 'unavailable';

/**
 * 프로브 1건을 던져 성공 여부를 판정한다.
 *
 * 성공 판정은 상태코드가 아니라 **본문의 매직 토큰 + Content-Type** 이다.
 * 상태코드만 보면 "404 대신 200 + 에러 HTML" 이나 catch-all 200 페이지를 반환하는
 * 설정에서 영원히 `extension` 으로 오판해, 재감지를 몇 번 눌러도 같은 오답이 나온다.
 *
 * @param path 프로브 경로
 * @returns 매직 토큰이 확인되면 true
 */
async function probe(path: string): Promise<boolean> {
    try {
        const res = await fetch(path, { cache: 'no-store', credentials: 'omit' });
        if (!res.ok) return false;

        // Content-Type 이 스크립트가 아니면 정적 블록이 아닌 다른 것이 응답한 것
        const contentType = res.headers.get('content-type') ?? '';
        if (!/javascript|ecmascript/i.test(contentType)) return false;

        return (await res.text()).includes(PROBE_TOKEN);
    } catch (e) {
        return false;
    }
}

/**
 * 프로브 쌍을 던져 자산 URL 방식을 판정한다.
 *
 * 단일 프로브는 "PHP 자체가 죽음" 과 구분되지 않으므로 반드시 쌍으로 던진다.
 *
 * | probe.js | probe | 판정 |
 * |---|---|---|
 * | 성공 | 성공 | `extension` |
 * | 실패 | 성공 | `extensionless` (정적 블록 가로채기 확정) |
 * | 그 외 | | `unavailable` (모드 문제 아님 — PHP/라우팅 장애) |
 *
 * @returns 판정 결과
 */
export async function detectAssetUrlMode(): Promise<DetectResult> {
    const [withExt, withoutExt] = await Promise.all([
        probe('/api/system/asset-probe.js'),
        probe('/api/system/asset-probe'),
    ]);

    if (withExt && withoutExt) return 'extension';
    if (!withExt && withoutExt) return 'extensionless';

    return 'unavailable';
}

/**
 * 서버에 **저장된** 자산 URL 방식을 읽는다.
 *
 * 런타임 전환값(`G7Config.assetUrlMode` / `__g7AssetUrlMode`)이 아니라 설정값을 본다.
 * 자가 복구가 이미 모드를 바꿔 놓았다면 런타임 값은 감지 결과와 같아져 드리프트가
 * 사라져 보이는데, 그때야말로 관리자에게 알려야 할 상황이다 — 봇은 JavaScript 를
 * 실행하지 않아 자가 복구가 닿지 않으므로 저장값이 틀린 채로 두면 SEO 는 계속 깨진다.
 *
 * @returns 저장된 모드 (미설정이면 기본값 `extension`)
 */
function getStoredAssetUrlMode(): string {
    const config = (window as any).G7Config;

    return config?.settings?.general?.asset_url_mode ?? 'extension';
}

/**
 * 저장된 모드와 실제 환경을 대조해 불일치를 전역 상태에 실어준다 (§5).
 *
 * 관리자 화면 자체가 안 뜨는 결함이라 "브라우저로 설정을 바꾸세요" 는 순환 참조다.
 * 반대로 자가 복구가 조용히 성공하면 관리자는 사이트가 정상이라 믿고 저장값을
 * 영영 고치지 않는다. 그래서 **대시보드가 스스로 프로브를 던져 저장값과 대조**한다.
 *
 * L5 위반이 아니다 — 서버 설정을 쓰지 않고 관리자에게 보여주기만 한다.
 * L9 위반도 아니다 — L9 가 금지하는 것은 엔진 레이어(Router/LayoutLoader/
 * ComponentRegistry)의 자산 로딩 중 재감지 캐스케이드이고, 이것은 관리자 화면
 * 1회 진단이다.
 *
 * 판정 불가(`unavailable`)면 아무것도 표시하지 않는다. 일시적 네트워크 장애로
 * 대시보드에 경고가 뜨면 신호가 아니라 소음이 된다.
 *
 * @param _action 액션 정의 (미사용)
 */
export async function checkAssetUrlModeDriftHandler(_action?: any): Promise<void> {
    try {
        const detected = await detectAssetUrlMode();
        if (detected === 'unavailable') return;

        const stored = getStoredAssetUrlMode();
        if (detected === stored) return;

        (window as any).G7Core?.state?.setGlobal?.({
            assetUrlModeDrift: { detected, stored },
        });
    } catch (e) {
        // 대시보드 진단이 실패해도 대시보드 자체는 정상 동작해야 한다.
        logger.warn('자산 URL 방식 대조 실패 (대시보드 동작에는 영향 없음):', e);
    }
}

/**
 * 자산 URL 방식 자동 감지 핸들러.
 *
 * 판정 결과를 폼 상태(`general.asset_url_mode`)에 반영하고, 감지 상태를
 * `asset_url_mode_detect_status` 로 로컬 폼 상태에 기록한다 — 레이아웃이 이 값을 읽어
 * 필드 하단에 결과 문구를 조건부로 렌더한다(토스트가 아니라 필드 옆 인라인 안내).
 * **저장은 하지 않는다** — 관리자가 결과를 보고 저장 버튼을 누르는 흐름을 유지해,
 * 감지가 오판했을 때 되돌릴 여지를 남긴다.
 *
 * 판정 불가(`unavailable`)면 값을 건드리지 않는다. 서버가 응답하지 않는 상황에서
 * 임의 값을 넣으면 멀쩡한 설정을 덮어쓸 수 있다(상태만 `unavailable` 로 표시).
 *
 * @param _action 액션 정의 (미사용)
 * @param context 액션 컨텍스트 (setState 등)
 */
export async function detectAssetUrlModeHandler(
    _action: any,
    context?: any,
): Promise<void> {
    const G7Core = (window as any).G7Core;
    const setState: ((patch: Record<string, unknown>) => void) | undefined =
        context?.setState ?? G7Core?.state?.setLocal;

    const setStatus = (status: string): void => {
        if (typeof setState === 'function') {
            setState({ asset_url_mode_detect_status: status });
        } else {
            logger.warn('setState 를 찾지 못해 감지 상태를 폼에 반영하지 못했습니다');
        }
    };

    try {
        setStatus('detecting');

        const result = await detectAssetUrlMode();

        if (result === 'unavailable') {
            setStatus('unavailable');

            return;
        }

        // 폼 값 + 상태를 함께 반영 (저장은 관리자가 명시적으로 수행)
        //
        // 값 경로가 'form.general.asset_url_mode' 인 이유: 이 필드를 감싸는 Div 가
        // dataKey: "form" 이라(admin_settings.json) 자동바인딩 경로가 _local.form.general.*
        // 이고, 저장 body 도 _local.form[tab] 을 읽는다. 'general.asset_url_mode' 로 쓰면
        // _local.general.* 에 떨어져 Select 표시에도 저장 body 에도 도달하지 않는다.
        // 감지 상태(asset_url_mode_detect_status)는 폼 밖 안내 문구용이라 루트에 둔다.
        if (typeof setState === 'function') {
            setState({
                'form.general.asset_url_mode': result,
                asset_url_mode_detect_status: result,
            });
        } else {
            logger.warn('setState 를 찾지 못해 감지 결과를 폼에 반영하지 못했습니다');
        }
    } catch (e) {
        logger.error('자산 URL 방식 감지 실패:', e);
        setStatus('unavailable');
    }
}
