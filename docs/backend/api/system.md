# System API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 System 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/system/asset-probe
<!-- @generated:start:api.public.system.asset-probe.extensionless -->
- **라우트명**: `api.public.system.asset-probe.extensionless`
- **컨트롤러**: `App\Http\Controllers\Api\Public\AssetProbeController@probe`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/system/asset-probe HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

이 엔드포인트는 JSON 봉투(`success`/`message`/`data`)를 쓰지 않는다. 정적 자산으로 오인될 응답을 그대로 흉내내는 것이 목적이라, 본문은 자바스크립트이고 `data` 구조가 존재하지 않는다.

| 항목 | 값 | 설명 |
| --- | --- | --- |
| Content-Type | `application/javascript; charset=utf-8` | 정적 `.js` 응답과 동일한 형태 |
| Cache-Control | `no-store, no-cache, must-revalidate, max-age=0` | 프로브는 매 요청 실측이어야 하므로 캐시 금지 |
| Pragma | `no-cache` | 구형 프록시 대응 |
| X-Content-Type-Options | `nosniff` | MIME 스니핑 차단 |
| 본문 매직 토큰 | `G7_ASSET_PROBE_OK` | 성공 판정용. 상태코드가 아니라 **이 토큰의 존재**로 판정한다 |

**응답 예시**

```http
HTTP/1.1 200
Content-Type: application/javascript; charset=utf-8
Cache-Control: no-store, no-cache, must-revalidate, max-age=0
```

```javascript
/* G7 asset URL mode probe */
window.__g7AssetProbe = 'G7_ASSET_PROBE_OK';
```

**에러 응답**

_에러 응답이 정의되어 있지 않다. 이 라우트는 DB 에 접근하지 않으므로 설치 전에도 응답하며, 도달하기만 하면 항상 `200` 이다. 도달하지 못해 `404`/`5xx` 가 관측되면 그것은 에러가 아니라 **판정 입력**이다 (아래 설명 참조)._

<!-- @generated:end -->

**설명**

서버(nginx/Apache)의 정적 최적화 블록이 확장자 붙은 동적 응답을 가로채는지 판정하기 위한 **대조군** 엔드포인트다. 확장자가 없으므로 정적 블록의 표적이 되지 않는다. 클라이언트는 이 URL 과 `/api/system/asset-probe.js` 를 쌍으로 요청해 다음과 같이 판정한다.

| `asset-probe.js` | `asset-probe` (본 엔드포인트) | 판정 |
| --- | --- | --- |
| 성공 | 성공 | `extension` — 확장자 붙은 URL 을 그대로 써도 된다 |
| 실패 | 성공 | `extensionless` — 정적 블록 가로채기 확정. 확장자 없는 형태로 전환한다 |
| 실패 | 실패 | 자산 URL 모드 문제가 아니다 (PHP/라우팅 장애) — 별도 안내 |

주의사항:

- **판정은 상태코드가 아니라 본문으로 한다.** `res.ok && body.includes('G7_ASSET_PROBE_OK')` 로 확인해야 한다. 상태코드만 보면 "404 대신 200 + 에러 HTML" 이나 catch-all 200 페이지를 반환하는 설정에서 영원히 `extension` 으로 오판하고, 재감지를 몇 번 눌러도 같은 오답이 나온다.
- **감지는 반드시 브라우저에서 수행한다.** 서버측에서 자기 `APP_URL` 로 curl 하면 loopback 이 nginx vhost·SSL·프록시 체인을 우회하거나 다른 vhost 를 타서 오판한다.
- **`public/` 하위에 실물 `asset-probe.js` 를 두지 않는다.** 실제 파일이 있으면 nginx 가 그것을 성공적으로 서빙해 거짓 양성이 된다.


### GET /api/system/asset-probe.js
<!-- @generated:start:api.public.system.asset-probe -->
- **라우트명**: `api.public.system.asset-probe`
- **컨트롤러**: `App\Http\Controllers\Api\Public\AssetProbeController@probe`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/system/asset-probe.js HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

확장자 없는 대조군(`GET /api/system/asset-probe`)과 **같은 컨트롤러 메서드**이므로 응답 형태가 동일하다. JSON 봉투를 쓰지 않으며 `data` 구조가 없다.

| 항목 | 값 | 설명 |
| --- | --- | --- |
| Content-Type | `application/javascript; charset=utf-8` | 정적 `.js` 응답과 동일한 형태 |
| Cache-Control | `no-store, no-cache, must-revalidate, max-age=0` | 프로브는 매 요청 실측이어야 하므로 캐시 금지 |
| Pragma | `no-cache` | 구형 프록시 대응 |
| X-Content-Type-Options | `nosniff` | MIME 스니핑 차단 |
| 본문 매직 토큰 | `G7_ASSET_PROBE_OK` | 성공 판정용. 상태코드가 아니라 **이 토큰의 존재**로 판정한다 |

**응답 예시**

애플리케이션까지 도달했을 때 (`extension` 모드 가능):

```http
HTTP/1.1 200
Content-Type: application/javascript; charset=utf-8
Cache-Control: no-store, no-cache, must-revalidate, max-age=0
```

```javascript
/* G7 asset URL mode probe */
window.__g7AssetProbe = 'G7_ASSET_PROBE_OK';
```

서버의 정적 최적화 블록이 가로챘을 때 (`extensionless` 모드 확정) — 응답은 서버 설정에 좌우되며 매직 토큰이 없다:

```http
HTTP/1.1 404
Content-Type: text/html
```

> 위 문서의 실측이 `404` 로 관측된 것이 이 경우다. 정규식 location(`location ~* \.(js|css|json)$`)이 프리픽스 location 보다 먼저 매칭되어, 확장자 붙은 동적 응답이 `try_files ... /index.php` 폴백 기회 없이 404 가 된다.

**에러 응답**

_에러 응답이 정의되어 있지 않다. 이 라우트는 DB 에 접근하지 않으므로 애플리케이션에 도달하면 항상 `200` 이다. `404`/`5xx` 는 에러가 아니라 **판정 입력**이며, 대조군이 성공했다면 `extensionless` 모드로 확정한다._

<!-- @generated:end -->

**설명**

자산 URL 모드 감지의 **표적** 엔드포인트다. 확장자(`.js`)로 끝나므로 서버의 정적 최적화 블록이 가로채는지 여부가 그대로 드러난다. 판정표와 주의사항은 대조군 `GET /api/system/asset-probe` 항목을 참조한다.

이 프로브가 실패하고 대조군이 성공하면, 코드는 확장자 없는 URL 형태를 써야 한다. 서버측 URL 조립은 `App\Support\AssetUrl`, 프론트측은 `resources/js/core/support/assetUrl.ts` 가 같은 규칙을 공유하므로 한쪽만 바꾸면 그 자산만 404 가 된다. 라우트 등록은 단일 `Route::get()` 이 아니라 `Route::dualSuffix()` / `dualSuffixSegment()` / `dualAsset()` 로 확장자 형태와 확장자 없는 형태를 동시에 등록한다.

