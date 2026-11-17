# System API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 System 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
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


### GET /api/system/cron/run
<!-- @generated:start:api.public.system.cron.run -->
- **라우트명**: `api.public.system.cron.run`
- **컨트롤러**: `App\Http\Controllers\Api\Public\WebCronController@run`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| token | query | string | 아니오 | — | 인증/검증 토큰 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.web_cron.run_validation_rules`).

**요청 예시**

```http
GET /api/system/cron/run?token=%7BYOUR_TOKEN%7D HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

> 문서 생성기는 호출 번호 없이 요청하므로 401 을 받는다. 아래 표는 `WebCronController::run()` 과 `ScheduleRunSummary::toArray()` 를 읽어 작성했다.

| 이름 | 타입 | 예시값 | 설명 |
| --- | --- | --- | --- |
| ran | boolean | `true` | 이번 호출이 실제로 러너를 돌렸는지. 최소 간격 미도달이거나 실행 방식이 서버 크론 전용이거나 이 서버의 스케줄이 꺼져 있으면(`G7_SCHEDULER=off`·검수 서버) `false`. 최소 간격 판정과 호출 기록은 한 잠금 안에서 하므로, 같은 순간에 도착한 두 호출 중 하나만 `true` 다(다른 하나는 `min_interval`) |
| reason | string | `min_interval` | `ran=false` 일 때의 사유 — `min_interval` / `web_call_disabled` / `scheduler_disabled_on_server`(요청을 처리한 서버의 스케줄이 꺼짐 — `G7_SCHEDULER=off` 또는 검수 서버) |
| retry_after_seconds | integer | `240` | 최소 간격까지 남은 초 (`reason=min_interval` 일 때만) |
| mode | string | `cron` | 저장된 예약 작업 실행 방식 (`reason=web_call_disabled`·`scheduler_disabled_on_server` 일 때만) |
| started_at | string | `2026-08-31T05:00:00+00:00` | 실행 시작 시각 (ISO 8601) |
| finished_at | string | `2026-08-31T05:00:03+00:00` | 실행 종료 시각 (ISO 8601) |
| duration_ms | integer | `3179` | 소요 시간(밀리초) |
| due | integer | `3` | 소급 창 안에서 실행 대상으로 뽑힌 예약 수 |
| executed | integer | `3` | 실제로 실행한 예약 수 |
| failed | integer | `0` | 실패한 예약 수 (커맨드 되뽑기 실패 포함) |
| skipped | integer | `0` | 건너뛴 예약 수 (조건 미충족·중복 실행 방지·다른 서버 담당) |
| truncated | boolean | `false` | 실행 시간 상한에 걸려 남은 예약을 다음 호출로 넘겼는지 |
| events | array | `[{"name":"sanctum:prune-expired","expression":"0 4 * * *","status":"executed"}]` | 예약별 결과 — `name`/`expression`/`status`(`executed`/`failed`/`skipped`)/`reason` |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "예약 작업 실행을 마쳤습니다.",
    "data": {
        "ran": true,
        "started_at": "2026-08-31T05:00:00+00:00",
        "finished_at": "2026-08-31T05:00:03+00:00",
        "duration_ms": 3179,
        "due": 2,
        "executed": 2,
        "failed": 0,
        "skipped": 0,
        "truncated": false,
        "events": [
            {
                "name": "sanctum:prune-expired",
                "expression": "0 4 * * *",
                "status": "executed"
            },
            {
                "name": "App\Jobs\GenerateSitemapJob",
                "expression": "0 2 * * *",
                "status": "executed"
            }
        ]
    },
    "error": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | 호출 번호가 없거나 저장된 번호와 다름 (번호 미발급 사이트 포함) | `schedule.cron.invalid_token` |
| 403 | 허용 주소 목록이 설정되어 있고 호출자 IP 가 그 밖 | `schedule.cron.ip_not_allowed` |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

인증을 통과한 뒤에는 실행 건수·실패 건수와 무관하게 **200** 이다. 실행이 일어나지 않은 경우(`ran=false`)도 200 이며 사유는 `data.reason` 이 알린다.



**설명**

서버에 예약 실행(크론)을 걸 수 없는 호스팅에서 예약 작업을 돌리는 진입점이다. 호스팅 제어판의 URL 호출형 예약 실행이나 외부 크론 서비스가 이 주소를 주기적으로 부른다. 실행 본체는 `g7:schedule-run` 커맨드와 **같은 러너**이며, 진입점만 둘이다.

**인증** — 사이트마다 발급되는 호출 번호를 `?token=` 쿼리 또는 `X-G7-Cron-Token` 헤더로 싣는다. 헤더를 설정할 수 없는 외부 서비스가 많아 쿼리 방식도 유지하지만, 접근 로그에 쿼리스트링이 남는 환경에서는 헤더 쪽이 낫다. 번호는 상수 시간 비교로 대조하며 어떤 로그에도 남기지 않는다. 번호 조회·재발급은 `GET|POST /api/admin/settings/system/cron-token[/rotate]` 만 제공하고, 일반 설정 조회 응답에는 실리지 않는다.

**응답 규약** — 인증을 통과하면 **실행 건수와 무관하게 언제나 200** 이다. 외부 크론 서비스는 비-200 을 장애로 보고 알림을 쏟는데, "지금은 돌 것이 없다" 는 장애가 아니기 때문이다. 인증 실패만 401(번호 불일치)·403(허용 IP 밖)이다.

| `data` 필드 | 타입 | 설명 |
| --- | --- | --- |
| ran | boolean | 이번 호출이 실제로 러너를 돌렸는지. 최소 간격 미만 호출이거나 실행 방식이 "서버 크론만" 이거나 이 서버의 스케줄이 꺼져 있으면 `false` |
| reason | string | `ran=false` 일 때의 사유 — `min_interval`(최소 간격 미도달) · `web_call_disabled`(주소 호출 미허용) · `scheduler_disabled_on_server`(요청을 처리한 서버의 스케줄이 꺼짐 — `G7_SCHEDULER=off` 또는 검수 서버) |
| retry_after_seconds | integer | 최소 간격까지 남은 초 (`reason=min_interval` 일 때만) |
| due | integer | 소급 창 안에서 실행 대상으로 뽑힌 예약 수 |
| executed | integer | 실제로 실행한 예약 수 |
| failed | integer | 실패한 예약 수 (커맨드 되뽑기 실패 포함) |
| skipped | integer | 건너뛴 예약 수 (조건 미충족·중복 실행 방지·다른 서버 담당) |
| truncated | boolean | 실행 시간 상한에 걸려 남은 예약을 다음 호출로 넘겼는지 |
| duration_ms | integer | 이번 호출의 소요 시간(밀리초) |
| events | array | 예약별 결과 (`name`/`expression`/`status`/`reason`) |

**호출 주기와 소급 실행** — 등록된 예약의 다수가 고정 시각(`dailyAt`)이라, 호출이 5~15분에 한 번인 환경에서는 그 분을 놓친다. 그래서 러너는 마지막 점검 시각 이후 시각이 지난 예약을 **소급 포함**해 실행한다. 소급 창 안에서 여러 번 due 였던 주기 예약도 1회만 실행하며, 창의 상한(`core.cron.catch_up_window_minutes`, 기본 하루)을 두어 장기 미호출 뒤 첫 호출이 폭주하지 않게 한다.

최소 호출 간격(`system.cron_min_interval`)은 **호출 횟수 제한이지 실행 누락 사유가 아니다.** 간격 미만 호출을 건너뛰어도 점검 시각이 전진하지 않으므로, 다음 유효 호출의 소급 창이 그 사이 시각을 그대로 덮는다.

**실행 시간 상한** — 한 번의 호출은 `core.cron.max_runtime_seconds`(기본 50초) 안에서만 예약을 처리하고(PHP `max_execution_time` 이 더 짧으면 그 8할 — 웹 기본 30초면 24초), 남은 예약은 다음 호출로 넘긴다(`truncated=true`). 이때 점검 시각을 전진시키지 않으므로 잔여 예약이 창 밖으로 밀려나지 않는다.

**중복 실행** — 서버 크론과 주소 호출이 함께 걸린 환경에서도 두 진입점이 프레임워크의 같은 mutex 를 공유하므로 같은 예약이 겹쳐 돌지 않는다.

**실측 제외 사유** — 문서 생성기는 번호 없이 호출하므로 401 을 받는다. 위 응답 필드 표는 그래서 사람이 작성한 것이다.


### GET /api/system/public-storage
<!-- @generated:start:api.public.system.public-storage.extensionless -->
- **라우트명**: `api.public.system.public-storage.extensionless`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicStorageController@serve`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| path | query | string | 예 | — | 컨트롤러가 읽는 내부 파라미터명. 이 형태에서는 `file` 로 전달한다 |
| file | query | string | 예 | — | `storage/app/public` 기준 상대 파일 경로 (예: `images/a.png`). 확장자 형태의 `{path}` 세그먼트와 **같은 값**이며, 어느 형태로 오든 컨트롤러는 `path` 하나만 본다 |
| v | query | string | 아니오 | — | 캐시 무효화 버전 (선택) |

**요청 예시**

```http
GET /api/system/public-storage?file=images%2Fa.png HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

확장자 형태(`GET /api/system/public-storage/{path}`)와 **같은 컨트롤러 메서드**이므로 응답이 동일하다 — JSON 봉투를 쓰지 않고 파일 자체를 스트리밍(BinaryFileResponse)으로 반환하며, `Content-Type` / `Cache-Control` / `ETag` / `Last-Modified` / `Content-Encoding` / `Vary` 는 그 항목의 표를 그대로 따른다. 이 형태에만 설명이 필요한 헤더는 하나다.

| 항목 | 값 | 설명 |
| --- | --- | --- |
| Content-Disposition | `inline; filename="a.png"` | 파일 이름은 서버가 정한다. 이 형태에서는 URL 마지막 세그먼트가 `public-storage` 라, 선언이 없으면 내려받기가 그 이름으로 저장된다 — 같은 파일이 자산 URL 모드에 따라 다른 이름이 되지 않도록 원래 파일 이름을 싣는다. 확장자 형태와 정적 서빙(`/storage/...`)도 같은 이름을 준다 |

**응답 예시**

```http
HTTP/1.1 200 OK
Content-Type: image/png
Content-Length: 20480
Cache-Control: public, max-age=3600, must-revalidate
Content-Disposition: inline; filename="a.png"
ETag: "9a1c2f6b5d0e4a37c8b1f2e3d4a5b6c7"
Last-Modified: Mon, 25 Aug 2026 04:12:33 GMT
Accept-Ranges: bytes
```

**에러 응답**

확장자 형태와 동일하다.

| 상태 | 조건 |
| --- | --- |
| `404` | 파일 부재 · **경로 구성요소가 `.` 으로 시작하는 요청**(숨김 파일·디렉토리 — 대상이 실재해도 404) |
| `403` | 심볼릭 링크 경유 접근 (링크 비추적 계약) · 실재하지만 **읽을 수 없는** 파일(권한 부재 — 정적 서빙과 같은 코드. 예외로 500 이 되지 않는다) |
| `422` | 경로 순회(`../` 등)·절대 경로·NULL 바이트 — FormRequest 검증 실패 |

<!-- @generated:end -->



**설명**

확장자로 끝나는 동적 응답을 서버의 정적 최적화 블록이 가로채는 환경에서 쓰는, **같은 파일의 두 번째 주소**다. 정규식 location 은 쿼리스트링을 제외한 경로에만 매칭되므로 파일 경로를 `?file=` 쿼리로 옮기면 그 블록에 걸리지 않는다. 어느 형태를 쓸지는 서버 설정(`general.asset_url_mode`)이 정하며, 판정은 프로브 쌍(`GET /api/system/asset-probe.js` / `GET /api/system/asset-probe`)이 한다.

- **두 형태는 같은 검사를 받는다.** 라우트 세그먼트는 전역 문자열 정리(`TrimStrings`·`ConvertEmptyStringsToNull`)의 대상이 아니지만 쿼리는 대상이라, 같은 입력이 모드에 따라 다른 판정을 받을 수 있다. 요청은 쿼리 원문을 직접 읽어 그 비대칭을 없앤다 — 이중 모드는 같은 파일을 두 형태로 여는 장치이지 검사 강도를 가르는 장치가 아니다.
- **점(`.`)으로 시작하는 이름을 막는 것은 이 형태에서 더 중요하다.** 서버 설정의 숨김 파일 차단은 **URL 경로**에만 걸리는데, 이 형태는 그 경로를 `?file=` 로 옮겨 그 방어선을 지나간다. 그래서 거부는 서버 설정이 아니라 애플리케이션이 스스로 하며, 코드는 정적 서빙과 같은 `404` 다.
- **URL 을 직접 조립하지 않는다.** 어느 형태로 내보낼지는 서버가 정하고(`App\Support\AssetUrl::publicStorage()`), 화면·레이아웃은 서버가 준 URL 을 그대로 쓴다.


### GET /api/system/public-storage/{path}
<!-- @generated:start:api.public.system.public-storage -->
- **라우트명**: `api.public.system.public-storage` (확장자 없는 형태: `api.public.system.public-storage.extensionless`)
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicStorageController@serve`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| path | path | string | 예 | — | `storage/app/public` 기준 상대 파일 경로 (예: `images/a.png`) |
| file | query | string | 아니오 | — | 확장자 없는 형태에서 `path` 대신 파일 경로를 담는다 |
| v | query | string | 아니오 | — | 캐시 무효화 버전 (선택) |

**요청 예시**

```http
GET /api/system/public-storage/images/a.png HTTP/1.1
Host: api.example.com
Accept: application/json
```

확장자 없는 형태 (정적 최적화 블록이 확장자 붙은 동적 응답을 가로채는 서버):

```http
GET /api/system/public-storage?file=images%2Fa.png HTTP/1.1
Host: api.example.com
```

**응답 필드** (`data` 내부)

이 엔드포인트는 JSON 봉투를 쓰지 않는다 — 파일 자체를 스트리밍(BinaryFileResponse)으로 반환한다.

| 항목 | 값 | 설명 |
| --- | --- | --- |
| Content-Type | 파일 확장자 기준 MIME | 확장자 표에 있는 유형만 그 값으로 나가고, **표에 없는 확장자는 내용을 보지 않고** `application/octet-stream` 으로 고정한다 (내용 스니핑 없음). 표는 nginx `mime.types` 와 같은 유형을 따른다(`.xhtml` → `application/xhtml+xml`). `.svgz` 는 표에 두지 않는다 — 본문이 gzip 이라 `Content-Encoding: gzip` 을 함께 실어야 하는데 이 통로에는 그 축이 없어, 유형만 선언하면 브라우저가 압축 바이트를 SVG 로 파싱해 깨진다 |
| Cache-Control | `public, max-age=3600, must-revalidate` (프로덕션) | 같은 URL 의 내용이 교체되므로 `immutable` 을 붙이지 않는다 — 브라우저가 매번 재확인하고, 변경이 없으면 `304` 로 끝난다 |
| ETag | 파일 mtime+size 기반 (RFC 9110 형식의 따옴표 포함) | `If-None-Match` 일치 시 `304`. 이 통로는 압축하지 않으므로 `-gzip` 접미사가 붙지 않는다 |
| Last-Modified | 파일 mtime | `If-Modified-Since` 만 보내는 클라이언트도 `304` 를 받는다 |
| Content-Encoding | — (압축 없음) | 이 통로는 gzip 압축에서 **명시적으로 제외**된다. 압축 경로는 파일을 통째로 메모리에 읽는데, 여기는 인증도 요청 상한도 없어 그 적재가 동시 요청 수만큼 곱해진다. 압축은 웹서버·CDN 의 몫이며, 링크가 되는 서버에서 같은 파일을 내주는 정적 서빙도 애플리케이션 메모리를 쓰지 않는다 |
| Vary | `Accept-Encoding` (압축 대상 유형에 한함) | 이 헤더는 압축 **여부**가 아니라 응답 **유형**을 보고 붙는다 — `text/*`·`application/json` 등을 이 통로로 내줄 때는 압축하지 않아도 붙으며, 이미지처럼 압축 대상이 아닌 유형에는 붙지 않는다 |
| X-Content-Type-Options | `nosniff` | 200·304 모두. 업로드된 SVG·HTML 형태 파일을 브라우저가 선언 유형과 다르게 해석하지 못하게 한다 — 정적 서빙 설정이 붙이는 것과 같은 헤더이며, 코어 파일 서빙 헬퍼가 모든 서빙 통로에 붙인다 |
| Content-Disposition | `inline; filename=a.png` · `attachment; filename=page.html` | 브라우저가 문서로 열어 스크립트를 실행할 수 있는 유형(`text/html`·`application/xhtml+xml`·`image/svg+xml`·`application/xml`)은 `attachment`(내려받기)이고 `Content-Security-Policy` 격리 정책이 함께 붙는다(`InlineMediaPolicy::harden()`). 이 통로는 업로드물(회원 아바타·public 디스크를 고른 확장의 업로드)을 내주므로, 그 파일이 앱 오리진에서 페이지로 열리지 않게 한다. 그 밖의 유형은 `inline` 이다 |

**응답 예시**

파일이 그대로 서빙된다 (본문은 파일 내용 자체 — JSON 봉투가 아니다):

```http
HTTP/1.1 200 OK
Content-Type: image/png
Content-Length: 20480
Cache-Control: public, max-age=3600, must-revalidate
ETag: "9a1c2f6b5d0e4a37c8b1f2e3d4a5b6c7"
Last-Modified: Mon, 25 Aug 2026 04:12:33 GMT
Accept-Ranges: bytes
```

재확인 요청에 변경이 없을 때:

```http
GET /api/system/public-storage/uploads/logo.png HTTP/1.1
If-None-Match: "9a1c2f6b5d0e4a37c8b1f2e3d4a5b6c7"
```

```http
HTTP/1.1 304 Not Modified
ETag: "9a1c2f6b5d0e4a37c8b1f2e3d4a5b6c7"
Last-Modified: Mon, 25 Aug 2026 04:12:33 GMT
```

**에러 응답**

| 상태 | 조건 |
| --- | --- |
| `404` | 파일 부재 · **경로 구성요소가 `.` 으로 시작하는 요청**(숨김 파일·디렉토리 — 대상이 실재해도 404) |
| `403` | 심볼릭 링크 경유 접근 (링크 비추적 계약) · 실재하지만 **읽을 수 없는** 파일(권한 부재 — 정적 서빙과 같은 코드. 예외로 500 이 되지 않는다) |
| `422` | 경로 순회(`../` 등)·절대 경로·NULL 바이트 — FormRequest 검증 실패 |

<!-- @generated:end -->



**설명**

`storage/app/public` 을 읽기 전용으로 서빙하는 **공개 스토리지 대체 통로**다. 표준 환경에서 public 디스크 파일은 `public/storage` 심볼릭 링크를 통해 정적 URL(`/storage/...`)로 서빙되지만, 링크를 만들 수 없는 호스팅(공유 호스팅의 `symlink` 차단·권한 부재)에서는 그 URL 이 전부 404 가 된다. 이 라우트가 그 환경의 서빙을 담당한다.

- **URL 선택은 서버가 한다(SSoT).** public 디스크 URL 생성기가 `public/storage` 링크 가용 여부를 판정해 링크가 있으면 정적 `/storage/...` 를, 없으면 이 라우트 URL 을 생성한다(`App\Support\AssetUrl::publicStorage()`). 화면·레이아웃은 서버가 준 URL 을 그대로 쓰고, 이 URL 형태를 직접 조립하지 않는다.
- **읽기 전용(GET)이다.** 인증 없는 쓰기 입구를 닫는 기존 정책(디스크 `serve => false`)과 충돌하지 않는다 — public 디스크는 정의상 공개 자산이다.
- **심볼릭 링크는 추적하지 않는다.** `storage/app/public` 아래 어느 경로 구성 요소든 링크면 대상이 어디를 가리키든 `403` 이다. 경로 순회는 FormRequest 와 서비스의 realpath 봉쇄가 이중 차단한다.
- **점(`.`)으로 시작하는 이름은 내주지 않는다.** 경로의 어느 구성 요소든 `.` 으로 시작하면 그 대상이 **실재해도** `404` 다(`storage/app/public/.gitignore` 가 그런 파일이다). 「파일이 없다」가 아니라 「이 통로로는 열지 않는다」는 뜻이므로, 404 를 파일 부재의 근거로 삼지 않는다. `.` 단독 세그먼트(`a/./b`)는 예외로 건너뛴다 — 정적 서빙이 그 주소에 200 을 주므로 여기서만 막으면 링크 유무로 같은 주소의 결과가 갈린다.
- **정적 서빙(`/storage/...`)과 다른 축이다.** 정적 경로에서 점 파일이 막히는지는 서버 설정이 정한다 — 배포 레이아웃 ②(문서 루트 = 프로젝트 루트)의 루트 `.htaccess` 는 점으로 시작하는 경로를 `403` 으로 막지만(`.well-known/` 제외), 그 규칙이 걸리지 않는 배치에서는 웹서버가 그대로 서빙할 수 있다. 이 라우트는 그 방어선에 기대지 않고 스스로 같은 규칙을 갖는다.
- 확장자로 끝나는 와일드카드 자산 라우트이므로 위 이중 모드 규약(`?file=` 형태)이 동일하게 적용된다.
- **링크 가용 판정의 기준이 되는 `public_path()` 는 진입 파일이 넘긴다.** public 디렉토리를 앱 루트 밖에 두는 문서 루트 분리 배치에서는 운영자의 진입 파일이 `G7_PUBLIC_PATH` 를 정의하고, 부팅 지점이 그 값으로 `public_path()` 를 고정한다. 진입 파일이 없는 문맥(콘솔 `artisan`·테스트)도 같은 값을 봐야 하므로 **같은 이름의 서버·셸 환경변수**로도 전달된다 — 우선순위는 `G7_PUBLIC_PATH` 상수 → `$_SERVER['G7_PUBLIC_PATH']`(웹서버가 넘긴 값: Apache `SetEnv`·nginx `fastcgi_param`) → `getenv('G7_PUBLIC_PATH')`(셸·크론)이며, 셋 다 없으면 아무것도 걸지 않는다. `.env` 는 이 판정 시점에 아직 로드되지 않으므로 그 파일로는 전달할 수 없다. 통로가 하나라도 빠지면 웹은 운영자의 public 디렉토리를, 콘솔은 앱 루트의 `public/` 을 보게 되어 `storage:link` 복구처럼 두 문맥이 함께 쓰는 코드가 서로 다른 트리를 대상으로 돈다(오류 없이 링크만 엉뚱한 곳에 생긴다). 배포본의 어떤 파일도 이 상수를 정의하지 않는다 — 그 배치는 운영자가 자기 진입 파일을 두는 형태다.


