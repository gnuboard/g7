/**
 * 확장 프론트엔드의 서버 주소 조립.
 *
 * 확장 번들은 코어와 **별도 IIFE** 라 `import { withBasePrefix }` 를 할 수 없다.
 * 그래서 종전에는 접두사를 붙일 수단 자체가 없었고, 번들 확장의 서버 호출이 전부
 * `fetch('/api/…')` 형태로 남아 하위 폴더 마운트(`https://host/g7/`)에서 사이트 밖을
 * 불렀다 — 증상은 오류가 아니라 **버튼이 조용히 무반응**인 것이다.
 *
 * 코어가 노출한 공식 수단(`G7Core.url.api`)을 우선 쓰고, 코어 초기화 전에 불릴 수
 * 있는 진입점(부트스트랩 순서 역전)을 위해 같은 SSoT(`G7Config.basePath`)를 읽는
 * 폴백을 둔다. 두 경로는 같은 값을 낸다.
 *
 * 이 파일은 번들 확장 전체가 **바이트 동일한 사본**으로 갖는다 — 사본이 갈리면 그
 * 확장만 다른 규칙을 따르게 되므로, 동일성을 재는 계약 테스트가 저장소에 있다.
 *
 * @param path `/` 로 시작하는 루트 상대 경로
 * @returns 사이트 마운트 접두사가 붙은 경로 (멱등)
 */
export function apiUrl(path: string): string {
    const core = (globalThis as any)?.G7Core;
    if (typeof core?.url?.api === 'function') {
        return core.url.api(path);
    }

    const raw = (globalThis as any)?.G7Config?.basePath;
    const prefix =
        typeof raw === 'string' && raw !== '' && raw !== '/'
            ? (raw.startsWith('/') ? raw : '/' + raw).replace(/\/+$/, '')
            : '';

    // 앞뒤 공백은 판정 전에 걷어낸다 — 코어 `withBasePrefix()`·서버 `AssetUrl::sitePath()`
    // 와 동형. 이 사본만 걷어내지 않아 `' /api/x'` 가 코어에서는 접두사를 얻고 확장에서는
    // 접두사 없이 나갔다 (회차 14 W1).
    path = typeof path === 'string' ? path.trim() : path;

    // `//host/…` 는 프로토콜 상대 **절대** URL 이다 — 붙이면 외부 주소가 깨진다.
    if (!prefix || !path.startsWith('/') || path.startsWith('//')) {
        return path;
    }

    // 접두사 뒤가 끝·`/`·`?`·`#` 이면 이미 접두사를 가진 값이다 — 코어 `hasBasePrefix()`
    // 와 같은 규칙. `?`·`#` 을 모르면 하위 폴더 홈의 복귀 주소(`/g7?ref=x`)가 이중 접두가 된다.
    if (hasPrefixBoundary(path, prefix)) {
        return path;
    }

    return prefix + path;
}

/**
 * **화면을 이동하는** 주소에 사이트 마운트 접두사를 붙입니다.
 *
 * 연산은 `apiUrl()` 과 같지만 부르는 자리가 다르다 — `window.location.href` 대입,
 * `window.open()`, 외부 인증기관에 넘기는 복귀 주소처럼 **브라우저가 그대로 여는**
 * 값이다. 그쪽은 라우터를 거치지 않으므로 접두사를 붙일 주체가 호출부밖에 없다.
 *
 * 앞서 「서버를 부르는 주소」만 닫고 이 축은 비어 있다고 판정했는데, 실제로는
 * 코어 1곳 + 번들 확장 7곳이 접두사 없이 남아 있었다. 하위 폴더
 * 사이트에서 소셜 로그인·본인인증 복귀·로그아웃 이동이 사이트 밖으로 나갔다.
 *
 * @param path `/` 로 시작하는 루트 상대 경로
 * @returns 사이트 마운트 접두사가 붙은 경로 (멱등)
 */
export function sitePath(path: string): string {
    return apiUrl(path);
}

/**
 * 브라우저 주소에서 사이트 마운트 접두사를 **벗겨** 라우트 기준 경로로 되돌립니다.
 *
 * `sitePath()` 의 역함수이고, 부르는 자리도 정확히 반대다 — 접두사를 붙이는 쪽은
 * 「내보내는 주소」이고, 벗기는 쪽은 「받아 온 주소를 라우트와 대조할 때」다.
 * 라우트 패턴은 언제나 접두사 없이 적히는데(`/^\/shop\/orders\/([^/]+)\/complete$/`)
 * `location.pathname` 은 접두사를 갖고 오므로, 벗기지 않으면 그 대조가 하위 폴더
 * 사이트에서 **항상 불일치**가 된다.
 *
 * 종전에는 확장의 `location.pathname` 30지점 중 접두사를
 * 벗기는 곳이 **한 곳도 없었다.** 증상은 오류가 아니라 조용한 부재였다 — 결제 영수증·
 * 가상계좌 안내·결제수단 표시 주입기가 통째로 실행되지 않고, 관리자/사용자 활성 메뉴가
 * 아무것도 선택되지 않은 상태로 남았다.
 *
 * @param path 브라우저에서 읽은 경로 (보통 `window.location.pathname`)
 * @returns 접두사를 벗긴 라우트 기준 경로 (멱등)
 */
export function routePath(path: string): string {
    const core = (globalThis as any)?.G7Core;
    if (typeof core?.url?.route === 'function') {
        return core.url.route(path);
    }

    const raw = (globalThis as any)?.G7Config?.basePath;
    const prefix =
        typeof raw === 'string' && raw !== '' && raw !== '/'
            ? (raw.startsWith('/') ? raw : '/' + raw).replace(/\/+$/, '')
            : '';

    // 앞뒤 공백은 판정 전에 걷어낸다 — 코어 `stripBasePrefix()` 와 동형.
    path = typeof path === 'string' ? path.trim() : path;

    // `/g7` 가 `/g7abc` 를 벗기지 않도록 경계(끝·`/`·`?`·`#`)를 확인한다.
    if (!prefix || typeof path !== 'string' || !hasPrefixBoundary(path, prefix)) {
        return path;
    }

    const stripped = path.slice(prefix.length);

    // 접두사만 있던 값(`/g7`·`/g7?x`·`/g7#f`)은 루트 `/` 를 되돌린다.
    return stripped === '' || stripped.startsWith('?') || stripped.startsWith('#')
        ? '/' + stripped
        : stripped;
}

/**
 * 루트 상대 경로가 접두사를 **이미** 갖고 있는지 — 접두사 뒤가 끝·`/`·`?`·`#` 이면 보유.
 * 코어 `assetUrl.ts` 의 `hasBasePrefix()` 와 같은 규칙이다(사본은 바이트 동일해야 한다).
 */
function hasPrefixBoundary(path: string, prefix: string): boolean {
    if (!path.startsWith(prefix)) {
        return false;
    }

    const next = path.charAt(prefix.length);

    return next === '' || next === '/' || next === '?' || next === '#';
}
