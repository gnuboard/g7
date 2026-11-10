# Settings API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## 목록 한계값 (`advanced` 탭)

대용량 목록에서 총 건수를 세는 범위와 직접 요청할 수 있는 페이지 번호의 상한입니다.
저장 경로는 다른 고급 설정과 같은 `advanced` 탭이며, 저장소에는 `pagination` 카테고리로 남습니다.

| 필드 | 타입 | 범위 | 의미 |
| --- | --- | --- | --- |
| `advanced.pagination_result_cap` | integer | 0 ~ 1,000,000 | 총 건수를 정확히 세는 상한. 0 이면 항상 전부 셉니다 |
| `advanced.pagination_max_page` | integer | 0 ~ 100,000 | 주소로 직접 요청할 수 있는 최대 페이지 번호. 0 이면 제한하지 않습니다 |

경계값은 설정 응답의 `_meta.limits` 로 함께 내려오며(`advanced_pagination_result_cap_min` 등),
화면 입력 칸의 min/max 와 저장 검증이 같은 값을 공유합니다.

상한을 넘긴 목록의 응답 형태는 [pagination.md](../pagination.md) 를 참고하세요.

## IP 접근 제한 (`security` 탭)

허용한 IP 에서만 관리자 영역·지정 경로에 접근하게 합니다. 저장 경로는 `security` 탭이며, 키는
`ip_restriction_enabled`(마스터 토글)·`admin_allowed_ips`(관리자 영역 허용 목록)·`ip_restriction_rules`(경로별 규칙) 셋입니다.

판정은 전역 미들웨어가 모든 요청에 대해 수행하고, 순서는 다음과 같습니다(첫 거부에서 멈춤).

1. 마스터 토글이 꺼져 있으면 통과
2. **관리자 영역**이면 `admin_allowed_ips` 를 대조한다 — 빈 목록이면 이 축은 적용하지 않는다.
   관리자 영역은 관리자 화면(`/admin*`)·코어 관리자 API(`/api/admin/*`)·관리자 로그인 API(`/api/auth/admin/*`)·
   확장 관리자 API(`/api/modules/{id}/admin/*`, `/api/plugins/{id}/admin/*`)다.
3. 관리자 영역이 아니면 **인바운드 콜백 경로**(결제 승인·입금통보·웹훅·본인인증 콜백)인지 먼저 보고, 해당하면 통과시킨다.
   코어 고정 목록(`plugins/*/payment/*`, `*/webhook*`, `plugins/*/plugin/*/callback`) 외의 경로는 확장이
   `core.security.ip_restriction_exempt_paths` 필터 훅으로 등록한다. 관리자 영역은 어떤 제외 경로로도 빠져나갈 수 없다.
4. `ip_restriction_rules` 에서 **사용 중인 첫 매칭 규칙** 하나의 `ips` 를 대조한다.

IP 는 신뢰 프록시 해석 뒤의 값이다 — 리버스 프록시 뒤에서 신뢰 프록시를 설정하지 않으면 전원이 프록시 주소 하나로
보이므로, 환경설정 화면이 그 상태를 경고로 띄운다([reverse-proxy.md](../reverse-proxy.md)).

**차단 응답** — API 요청은 403 JSON, 웹 요청은 403 페이지(접속 IP 표시)입니다.

```json
{
    "success": false,
    "message": "허용된 IP 에서만 접근할 수 있습니다. 접근이 필요하면 사이트 관리자에게 문의하세요."
}
```

차단은 활동 로그 `security.ip_blocked`(유형 시스템)로 남으며, 같은 IP 는 60초에 한 건만 기록합니다.

**자기 잠금 방지** — 저장 요청자의 현재 IP 가 저장 후 설정에서 막히면(관리자 영역 허용 목록 밖, 또는 설정 화면 경로를
막는 규칙) 저장하지 않고 422 를 돌려줍니다. 그래도 잠긴 경우의 구제는 서버 셸의
`php artisan security:ip-restriction --disable` 입니다(목록·규칙은 지우지 않고 토글만 끈다).

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Settings 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/admin/settings
<!-- @generated:start:api.admin.settings.index -->
- **라우트명**: `api.admin.settings.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@index`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/settings HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| general | object | `{"site_name":"그누보드7","site_url":"https:\/\/api.example.com","sit…` | 일반 탭 설정 그룹 (사이트명·사이트 URL·설명·관리자 이메일·타임존·기본 언어·통화·점검 모드·사이트 로고·사이트 파비콘 첨부). site_logo·site_favicon 은 SettingsService 가 별도 주입한 첨부 정보(업로더 초기 파일). 공개 계산 필드 `site_logo_url`·`site_favicon_url` 은 이 관리자 응답에는 없고 저장 응답 `frontend_settings`·셸 `G7Config.settings` 에 있다 |
| security | object | `{"force_https":false,"login_attempt_enabled":true,"auth_t…` | 보안 탭 설정 그룹 (HTTPS 강제·로그인 시도 제한 사용·인증 토큰 유지시간(분, 0=무한)·최대 로그인 시도 횟수·잠금 시간·내부 네트워크 주소 호출 허용). `allow_internal_outbound_urls`(boolean, 기본 false): 서버가 대신 호출하는 outbound 요청(예약 작업 URL 호출, 외부 API 연동)에서 사설 IP·`localhost` 등 내부 주소를 허용할지 여부 — 켜면 사내 서버 호출이 가능해지지만 서버가 내부망으로 요청을 보낼 수 있게 되므로 기본은 차단입니다. 언어팩 URL 설치는 원격 코드를 내려받으므로 이 설정과 무관하게 항상 내부 주소를 거부합니다. IP 접근 제한 3키(`ip_restriction_enabled`·`admin_allowed_ips`·`ip_restriction_rules`)도 이 그룹에 실린다 — 의미는 아래 「IP 접근 제한」 절. 비밀번호 정책 키(`password_min_length`·`require_password_special_char` 와 `password_*` 16키 — 작성 규칙·재사용 금지·만료, 서버가 기록하는 만료 기준 시작일 `password_expiry_started_at` 포함 — #736)도 이 그룹에 실린다 — 범위·기본값은 `POST /api/admin/settings` 의 `security.password_*` 행. 활동 로그(접속기록) 보관 기간 `activity_log_retention_days`(정수 일, 기본 365)도 이 그룹이다. 콘텐츠 보안 정책 2키(`csp_mode` 기본 `off`·`csp_extra_script_hosts` 기본 `[]`, #158)도 이 그룹이다 — 의미는 `POST /api/admin/settings` 의 `security.csp_*` 행 |
| mail | object | `{"mailer":"smtp","host":"","port":587,"username":"","pass…` | 메일 탭 설정 그룹 (메일러 종류(smtp/mailgun/ses)·SMTP 호스트/포트/인증 정보·암호화 방식·발신자 주소/이름·Mailgun/SES 자격 정보) |
| upload | object | `{"max_file_size":10,"allowed_extensions":["jpg","jpeg","p…` | 업로드 탭 설정 그룹 (최대 파일 크기(MB)·허용 확장자 목록·이미지 최대 가로/세로·이미지 품질). `thumbnail_presets` 와 `thumbnail_presets_rows` 는 저장 행이 아니라 레지스트리가 합친 썸네일 규격 표(고정 5행, og, 켜진 확장 규격, 운영자 규격 — 행마다 `kind` fixed/og/extension/custom, 확장 행 `defaults`, og 행 `size_readonly`)다 (#869). 행마다 `label` 은 확장이 등록한 표시 이름의 로케일 맵(`{"ko":"배너 목록","en":"Banner list"}`)이고, 이름을 등록하지 않은 확장 행과 고정·og·운영자 행은 null 이다(화면은 null 이면 `name` 을 보인다). 표시 전용이라 저장하지 않는다. `thumbnail_presets_rows` 는 응답 전용이라 저장 요청에 실려 와도 버린다 |
| seo | object | `{"meta_title_suffix":null,"meta_description":null,"meta_k…` | SEO 탭 설정 그룹 (메타 타이틀 접미사·메타 설명/키워드·검색엔진 인증 코드·봇 감지·OG/Twitter 기본값·SEO 캐시·사이트맵·생성기 설정) |
| advanced | object | `{"cache_enabled":true,"cache_default_ttl":86400,"layout_c…` | 고급 탭 설정 그룹 (캐시·디버그·코어 업데이트·GeoIP 설정을 한 탭으로 합친 병합 뷰). cache/debug 카테고리 값이 함께 노출됨 |
| cache | object | `{"cache_enabled":true,"cache_default_ttl":86400,"layout_c…` | 캐시 원본 카테고리 (전역 캐시 사용·기본 TTL·레이아웃/통계/SEO 캐시 사용 및 TTL). advanced 탭에 병합되면서 개별 접근용으로 별도 노출된 파생 뷰 |
| debug | object | `{"debug_mode":false,"sql_query_log":false,"log_level":"er…` | 디버그 원본 카테고리 (디버그 모드·SQL 쿼리 로그·로그 레벨). advanced 탭에 병합되면서 개별 접근용으로 별도 노출된 파생 뷰 |
| drivers | object | `{"storage_driver":"local","s3_bucket":null,"s3_region":"a…` | 드라이버 탭 설정 그룹 (스토리지/캐시/세션/큐/로그 드라이버 선택 + S3·Redis·Memcached·WebSocket·검색엔진 접속 파라미터) |
| core_update | object | `{"core_update_github_url":"https:\/\/github.com\/gnuboard…` | 코어 업데이트 원본 카테고리 (코어 업데이트를 받아올 GitHub 저장소 URL·비공개 저장소 접근용 토큰). advanced 탭에 병합된 파생 뷰 |
| geoip | object | `{"geoip_enabled":false,"geoip_license_key":null,"geoip_au…` | GeoIP 원본 카테고리 (GeoIP 사용 여부·MaxMind 라이선스 키·DB 자동 갱신 사용). advanced 탭에 병합된 파생 뷰 |
| notifications | object | `{"channels":[{"id":"mail","is_active":true,"sort_order":1…` | 알림 탭 설정 그룹. channels 는 알림 채널 목록으로 각 원소가 id(채널 식별자)·is_active(활성 여부)·sort_order(표시 순서)를 가짐 |
| identity | object | `{"default_provider":"g7:core.mail","purpose_providers":{"…` | 본인인증(IDV) 탭 설정 그룹 (기본 provider·목적별 provider 매핑(purpose_providers)·챌린지 유효시간(분)·최대 시도 횟수) |
| pagination | object | `{"pagination_result_cap":10000,"pagination_max_page":1000}` | 목록 한계값 카테고리 — 총 건수를 정확히 세는 상한과 직접 요청할 수 있는 최대 페이지 번호 |
| system | object | `{"execution_mode":"auto","cron_mode":"auto","cron_allowed…` | 실행 방식·예약 작업 카테고리 — 커맨드 실행 모드와 웹 크론 설정. 호출 번호(토큰)는 이 응답에 실리지 않는다 |
| privacy | object | `{"withdrawn_retention_enabled":false,"withdrawn_retention_…` | 개인정보 탭 설정 그룹 (탈퇴 회원 잔여정보 2차 파기 사용 여부·보유일, 휴면 전환 사용 여부·전환 기준일·사전 통지 선행일, 휴면 후 자동 탈퇴 사용 여부·기준일). 전 항목 기본 꺼짐 |
| available_drivers | object | `{"storage":[{"id":"local","label":{"ko":"로컬","en":"Local"…` | 드라이버 선택지 카탈로그 (DriverRegistryService 산물). 종류별(storage/public_asset/cache/session/queue 등) 선택 가능한 드라이버 목록을 id/다국어 label 형태로 제공. `public_asset` 은 공개 자산 직접 URL 서빙 디스크 선택지 (코어 none/public/s3 + 플러그인 훅 등록분) |
| _meta | object | `{"limits":{"upload_max_file_size_min":1,"upload_max_file_…` | 설정값이 아니라 화면이 쓰는 메타. 조회(GET)와 저장(POST) 응답이 같은 모양으로 동봉한다. 아래 열 필드로 구성 |
| _meta.limits | object | `{"upload_max_file_size_min":1,"upload_max_file_size_max":1024,…}` | 각 설정 항목의 min/max 경계값 맵 (`config/core.php` 의 `settings_limits` 가 SSoT, 화면 입력 힌트와 FormRequest 검증이 같은 값을 공유) |
| _meta.env_priority_enabled | boolean | `false` | `.env` 키 단위 우선 모드(`G7_ENV_PRIORITY`) 활성 여부. 화면은 이 값으로 안내 배너 표시를 판정한다. 기본값 false (스위치 미설정 시 종전 동작) |
| _meta.env_locked | object | `{"general.site_name":true,"advanced.debug_mode":true}` | `.env` 에 값이 명시되어 편집이 잠긴 필드 목록. 키는 **화면이 쓰는 프론트엔드 키**(`frontend_key`/`merge_into` 변환 후 — 예: `debug.mode` → `advanced.debug_mode`)이며 값은 항상 true. 병합 대상 카테고리와 원본 카테고리 양쪽에 실린다(설정값 자체가 두 위치에 실리는 것과 같은 규칙). 스위치가 꺼져 있으면 빈 객체. 잠긴 필드는 저장 요청에 포함되어도 서버가 제거하므로 값이 반영되지 않는다. 잠긴 필드의 값은 저장값이 아니라 실제로 적용 중인 값으로 내려가되, 비밀번호·시크릿·토큰 등 민감 항목은 그 대체를 하지 않는다 |
| _meta.consent_providers | array | `[{"id":"sirsoft-gdpr","label":"쿠키 동의 배너 (sirsoft-gdpr)"}]` | 등록된 동의 관리 제공자 `{id, label}` 목록 — 개인정보 탭 「동의 관리 제공자」 선택 상자의 항목. 동의 수집을 꺼 둔 제공자도 실린다(고를 수 있다) |
| _meta.consent_selection | object | `{"state":"auto","reason":null,"provider_id":"sirsoft-gdpr","provider_label":"GDPR …"}` | 지금 선택 상태. `state` 는 `none`·`auto`·`selected`·`ambiguous`·`missing`, `reason` 은 `none` 의 사유(`no_provider`·`provider_unavailable`) |
| _meta.queue_connection_options | array | `[{"name":"database","driver":"database"},{"name":"database-long","driver":"database"},{"name":"redis","driver":"redis"}]` | 드라이버 탭 「큐 워커」 의 등록 연결 선택지 — `config/queue.php` 연결 중 드라이버가 `sync`·`null` 이 아닌 것 (#139) |
| _meta.csp | object | `{"override":null,"preview":"script-src 'self' t1.daumcdn.net; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; report-uri /api/csp-report","auto_hosts":["t1.daumcdn.net"]}` | 보안 탭 「콘텐츠 보안 정책」 카드가 읽는 메타(#158). `override`: `.env G7_CSP_MODE` 탈출구 값(`off`·`report_only`·`enforce`, 없으면 null — 값이 있으면 화면이 모드 선택을 잠근다) · `preview`: **저장된 값** 기준으로 붙을 정책 문자열(모드가 끔이어도 미리 보여 준다) · `auto_hosts`: 활성 확장 선언(`trusted_script_hosts`·템플릿 externals 외부 script)에서 자동 합산된 호스트 |
| _meta.favicon | object\|null | `{"user":{"source":"template","template_identifier":"sirsoft-basic","template_name":"Basic","settings_route":"/admin/templates/sirsoft-basic/settings"},"admin":{…}}` | 화면 종류별(사용자·관리자) 현재 파비콘 계층(`template`·`core`·`custom_ico`·`bundled`)과 활성 템플릿·설정 화면 경로 — 일반 탭 파비콘 칸 아래 「○○ 템플릿 설정의 파비콘이 우선 적용됩니다」 안내용 (#434). 해석 실패 시 null |
| _meta.thumbnail_engine_available | boolean | `true` | 이 서버에 쓸 수 있는 이미지 처리 엔진(`drivers.image_driver` 가 고른 엔진, 쓸 수 없으면 gd)이 있는지 (#869). false 면 썸네일 없이 원본을 내고 업로드 축소도 하지 않는다. 업로드 탭은 이 값으로 「엔진 없음」 안내를 띄운다 |
| _meta.thumbnail_disk_options | object | `{"public":[{"id":"thumbnails","label":{"ko":"서버 비공개 저장소 (기본)",…}},{"id":"public",…}],"gated":[{"id":"thumbnails",…},{"id":"s3",…}]}` | 업로드 탭 썸네일 「저장 위치」 선택지 (#869). `public` 은 `thumbnails` 와 공개 자산 디스크 카탈로그(`available_drivers.public_asset` 에서 `none` 제외), `gated` 는 `thumbnails` 와 `s3`(이 서버에서 S3 어댑터를 쓸 수 있을 때만). `label` 은 `available_drivers` 와 같은 로케일 맵. 저장 검증(`upload.thumbnail_disk_public`/`_gated`)이 같은 목록을 쓴다 |
| _meta.thumbnail_server_defaults | object | `{"generate_per_minute":120,"lock_wait_seconds":10,"max_source_pixels":16000000,"pregenerate_batch":20,"pregenerate_time_budget":15,"purge_time_budget":10}` | 업로드 탭 「고급」 칸(썸네일 서버 보호 값)을 비웠을 때 쓰는 값 — `.env` 의 `G7_THUMBNAIL_*` 가 있으면 그 값, 없으면 기본값 (`config('core.thumbnail_server_defaults')`). 화면은 칸의 placeholder 로 보인다 (#869) |
| abilities | object | `{"can_update":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "설정을 성공적으로 가져왔습니다.",
    "data": {
        "general": {
            "site_name": "그누보드7",
            "site_url": "https://api.example.com",
            "site_description": null,
            "admin_email": "admin@example.com",
            "timezone": "Asia/Seoul",
            "...": "(5개 키 생략, 총 10개)"
        },
        "security": {
            "force_https": false,
            "login_attempt_enabled": true,
            "auth_token_lifetime": "{MASKED}",
            "max_login_attempts": 5,
            "login_lockout_time": 5,
            "...": "(4개 키 생략, 총 9개)"
        },
        "mail": {
            "mailer": "smtp",
            "host": "",
            "port": 587,
            "username": "",
            "password": "{MASKED}",
            "...": "(9개 키 생략, 총 14개)"
        },
        "upload": {
            "max_file_size": 10,
            "allowed_extensions": [
                "jpg",
                "jpeg",
                "png",
                "gif",
                "webp",
                "... (총 11건 중 5건 표시)"
            ],
            "image_max_width": 2000,
            "image_max_height": 2000,
            "image_quality": 85,
            "...": "(2개 키 생략, 총 7개)"
        },
        "seo": {
            "meta_title_suffix": "| 그누보드7",
            "meta_description": "그누보드7 데모 사이트 — 커뮤니티와 쇼핑몰을 한 곳에서 만나보세요.",
            "meta_keywords": "그누보드7, 커뮤니티, 쇼핑몰, 오픈소스 CMS",
            "google_analytics_id": null,
            "google_site_verification": null,
            "...": "(24개 키 생략, 총 29개)"
        },
        "...": "(13개 키 생략, 총 18개)"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.read`)이 없는 경우 |

<!-- @generated:end -->



**설명**

관리자 통합 환경설정 화면(`admin_settings.g7.html`)이 사용하는 전체 설정 조회 엔드포인트입니다. 각 탭에 해당하는 설정 그룹(general/security/mail/upload/seo/advanced/drivers/geoip/notifications/identity 등)과 드라이버 선택지 카탈로그(`available_drivers`)를 한 번에 반환합니다. 응답은 Eloquent 모델이 아니라 SettingsService 가 여러 설정 소스를 병합해 만든 집계 배열이며, 일부 그룹(cache/debug 등)은 원본 카테고리 값을 별도 키로 함께 노출한 파생 뷰입니다.


### POST /api/admin/settings
<!-- @generated:start:api.admin.settings.store -->
- **라우트명**: `api.admin.settings.store`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@store`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| _tab | body | string | 아니오 | — | 활성 탭 식별자 (general/mail/upload/seo/security/drivers/advanced/notifications/identity/privacy). 지정 시 해당 탭 필드만 필수 검증되고 나머지 탭은 nullable 처리되어 탭 단위 부분 저장을 가능케 함 |
| general | body | array | 아니오 | — | 일반 탭 설정 묶음 (사이트명·URL·설명·관리자 이메일·타임존·기본 언어·통화·점검 모드·사이트 로고) |
| mail | body | array | 아니오 | — | 메일 탭 설정 묶음 (메일러 종류·SMTP 호스트/포트/인증·암호화·발신자 정보·Mailgun/SES 자격 정보) |
| upload | body | array | 아니오 | — | 업로드 탭 설정 묶음 (최대 파일 크기·허용 확장자·이미지 최대 크기 및 품질) |
| seo | body | array | 아니오 | — | SEO 탭 설정 묶음 (메타 태그·검색엔진 인증·봇 감지·OG/Twitter 기본값·SEO 캐시·사이트맵·생성기) |
| security | body | array | 아니오 | — | 보안 탭 설정 묶음 (HTTPS 강제·로그인 시도 제한·인증 토큰 유지시간·최대 시도 횟수·잠금 시간·비밀번호 정책 — 비밀번호 키별 범위는 아래 `security.password_*` 행) |
| drivers | body | array | 아니오 | — | 드라이버 탭 설정 묶음 (스토리지/캐시/세션/큐/로그 드라이버 및 S3·Redis·Memcached·WebSocket·검색엔진 접속 정보) |
| advanced | body | array | 아니오 | — | 고급 탭 설정 묶음 (캐시·디버그·코어 업데이트·GeoIP 설정) |
| notifications | body | array | 아니오 | — | 알림 탭 설정 묶음. channels 배열로 각 알림 채널의 id·is_active(활성 여부)·sort_order(표시 순서)를 저장 |
| identity | body | array | 아니오 | — | 본인인증(IDV) 탭 설정 묶음 (기본 provider·목적별 provider 매핑·챌린지 유효시간·최대 시도 횟수) |
| system | body | array | 아니오 | — | 시스템 탭 컨테이너 — 실행 방식·예약 작업 설정을 담는다 |
| privacy | body | array | 아니오 | — | 개인정보 탭 설정 묶음 (탈퇴 회원 잔여정보 파기·휴면 전환·휴면 후 자동 탈퇴의 사용 여부와 기간) |
| notifications.channels | body | array | 아니오 | — | 알림 채널 목록. 각 원소는 `id`(채널 식별자, 필수·최대 50자)·`is_active`(활성 여부, 필수 boolean)·`sort_order`(표시 순서, 0 이상 정수) 로 구성 |
| general.site_name | body | string | 예 | max 100 | general.site 이름 (식별자) |
| general.site_url | body | string | 예 | max 255 | 사이트 기본 URL |
| general.user_screen_url | body | string | 아니오 | url:http,https · max 255 | 사용자 화면 URL (7.2.0). 회원 화면을 외부 사이트(Next.js 등)가 그릴 때 그 주소. 비우면 사이트 주소를 쓴다. 앞뒤 공백·끝 슬래시는 저장 전에 지운다. 알림 메일·비밀번호 재설정·결제 복귀 링크가 이 주소로 바뀐다 |
| general.site_description | body | string | 아니오 | max 500 | 사이트 설명 |
| general.admin_email | body | email | 예 | max 255 | 관리자 이메일 주소 |
| general.timezone | body | string | 예 | — | 타임존 식별자 |
| general.language | body | string | 예 | — | 언어 코드 |
| general.currency | body | string | 아니오 | max 10 | 통화 코드 (ISO 4217 — 예: KRW) |
| general.maintenance_mode | body | boolean | 아니오 | — | 점검 모드 사용 여부 (사이트 접근 차단) |
| general.asset_url_mode | body | string | 아니오 | — | 자산 URL 형태 — `auto` / `extension`(확장자 형태) / `extensionless`(확장자 없는 형태). 확장자로 끝나는 동적 응답을 서버의 정적 최적화 블록이 가로채는 환경에서 후자를 쓴다 |
| system.execution_mode | body | string | 아니오 | — | 웹 요청 안의 커맨드 실행 방식 — `auto`(환경 감지) / `subprocess`(별도 프로세스) / `inprocess`(같은 요청 안) |
| system.cron_mode | body | string | 아니오 | — | 예약 작업 실행 방식 — `auto` / `web`(주소 호출만) / `cron`(서버 크론만) / `both` |
| system.cron_allowed_ips | body | string | 아니오 | max 1000 | 웹 크론 호출을 허용할 IP·CIDR 목록 (줄바꿈 또는 쉼표 구분). 비우면 제한하지 않는다 |
| system.cron_min_interval | body | integer | 아니오 | min 0, max 1440 | 웹 크론 최소 호출 간격(분). 간격 미만 호출은 실행 없이 정상 응답하고, 그 사이 시각은 다음 유효 호출의 소급 창이 덮는다 (0 = 제한 없음) |
| system.cron_visit_fallback | body | boolean | 아니오 | — | 방문자 접속 시 예약 작업 실행 여부. 기본 꺼짐 — 켜면 방문이 없는 사이트에서는 예약도 돌지 않는다 |
| general.site_logo | body | array | 아니오 | — | 사이트 로고 이미지 |
| general.site_favicon | body | array | 아니오 | max 1, 항목: `site_favicon` 컬렉션에 존재하는 첨부 ID | 사이트 파비콘(브라우저 탭 아이콘) 첨부 ID 배열 — ico·png·svg·gif·jpg·jpeg·webp, 1MB 이하. 보내지 않으면 기존 값 유지, 빈 배열이면 지우고 첨부를 저장 뒤 파기. 활성 템플릿 설정의 파비콘이 있으면 그것이 우선한다(`_meta.favicon`) |
| mail.mailer | body | string | 아니오 | — | 메일 발송 드라이버 (smtp/mailgun/ses) |
| mail.host | body | string | 아니오 | max 255 | 호스트 주소 |
| mail.port | body | integer | 아니오 | min 1, max 65535 | 포트 번호 |
| mail.username | body | string | 아니오 | max 255 | 사용자명 (로그인/인증 아이디) |
| mail.password | body | string | 아니오 | max 255 | 비밀번호 |
| mail.encryption | body | string | 아니오 | — | 전송 암호화 방식 (tls/ssl) |
| mail.mailgun_domain | body | string | 아니오 | max 255 | Mailgun 도메인 |
| mail.mailgun_secret | body | string | 아니오 | max 255 | Mailgun 시크릿 키 |
| mail.mailgun_endpoint | body | string | 아니오 | max 255 | Mailgun 엔드포인트 |
| mail.ses_key | body | string | 아니오 | max 255 | SES 액세스 키 |
| mail.ses_secret | body | string | 아니오 | max 255 | SES 시크릿 키 |
| mail.ses_region | body | string | 아니오 | max 255 | SES 리전 |
| mail.from_address | body | email | 아니오 | max 255 | 발신자 주소 |
| mail.from_name | body | string | 아니오 | max 255 | mail.from 이름 (식별자) |
| upload.max_file_size | body | integer | 아니오 | min 1, max 1024 | 업로드 허용 최대 파일 크기 |
| upload.allowed_extensions | body | string | 아니오 | — | 업로드 허용 확장자 목록 |
| upload.image_max_width | body | integer | 아니오 | min 100, max 10000 | 이미지 리사이즈 최대 너비 (px) |
| upload.image_max_height | body | integer | 아니오 | min 100, max 10000 | 이미지 리사이즈 최대 높이 (px) |
| upload.image_quality | body | integer | 아니오 | min 1, max 100 | 이미지 리사이즈 시 압축 품질 (1~100) |
| upload.orphan_cleanup_enabled | body | boolean | 아니오 | — | 고아 첨부(소유자 없이 남은 첨부) 자동 정리 활성화 여부. **기본 false** — 사용자 파일을 실제로 파기하므로 운영자가 직접 켜야 동작하며, 예약 작업(`attachments:prune-orphans --scheduled`)이 이 값을 false 폴백으로 재확인한다 |
| upload.orphan_retention_days | body | integer | 아니오 | min 1, max 3650 | 고아 첨부 보존기간(일, 기본 30). 폼 작성 중 이탈을 감안한 유예 기간이다. 하한·상한은 `config('core.settings_limits.upload_orphan_retention_days_*')` 가 정하며 화면 입력도 같은 값을 읽는다 |
| upload.thumbnail_enabled | body | boolean | 아니오 | — | 이미지 썸네일 사용 여부 (기본 true). 끄면 모든 이미지를 원본으로 낸다 (#869) |
| upload.thumbnail_presets | body | array | 아니오 | 최대 `settings_limits.upload_thumbnail_presets_max`(30) 행, 표 전체 기준 | 썸네일 규격 표. 조회 응답의 `upload.thumbnail_presets` 를 고쳐 그대로 보낸다. 저장 때 `ThumbnailPresetSettings::mergeForSave` 가 고정 슬롯 5행을 늘 보충하고, og 행은 저장하지 않으며(크기는 SEO 탭), 지금 꺼진 확장의 수정값은 기존 저장본에서 이어 붙인다. 오류 키는 `upload.thumbnail_presets.{i}.{칸}` |
| upload.thumbnail_presets.*.name | body | string | 예 | 중복 불가. 운영자 행 `^[a-z][a-z0-9_]{1,31}$`, 예약어(xs/sm/md/lg/xl/og) 불가. `true`/`false`/`null`/`undefined` 는 행 종류와 무관하게 불가(레이아웃 파이프가 리터럴로 읽는다). 고정 행(`source=core`)과 확장 행(`source`=확장 식별자)은 이름 변경 불가, 확장 형식 이름은 켜진 확장이 등록한 것만 | 규격 이름 (`?size=` 값) |
| upload.thumbnail_presets.*.source | body | string | 아니오 | max 100 | 규격 출처 (core/custom/확장 식별자). 행 종류 판정에 쓰고 저장값은 서버가 다시 정한다 |
| upload.thumbnail_presets.*.width | body | integer | 예(og 행 제외) | min 16, max 4096 | 가로 (px) |
| upload.thumbnail_presets.*.height | body | integer | fit 이 width 가 아니면 예(og 행 제외) | min 16, max 4096 | 세로 (px) |
| upload.thumbnail_presets.*.fit | body | string | 예 | cover/contain/width | 맞춤 방식 |
| upload.thumbnail_presets.*.anchor | body | string | 아니오 | center/top/bottom/left/right | 자르기 기준점 |
| upload.thumbnail_presets.*.quality | body | integer | 아니오 | min 1, max 100 | 인코딩 품질. 비우면 업로드 탭 이미지 품질 |
| upload.thumbnail_presets.*.format | body | string | 아니오 | original/webp/avif | 출력 형식 (엔진이 못 쓰는 형식이면 원본 형식) |
| upload.thumbnail_presets.*.upscale | body | boolean | 아니오 | — | 원본이 규격보다 작을 때 확대 여부 |
| upload.thumbnail_presets.*.pregenerate | body | boolean | 아니오 | — | 원본 등록 직후 미리 만들지 |
| upload.thumbnail_presets.*.numeric | body | boolean | 아니오 | — | `?w=` 숫자 요청의 후보인지 |
| upload.thumbnail_presets.*.enabled | body | boolean | 아니오 | — | 규격 사용 여부 |
| upload.thumbnail_content_preset | body | string | 아니오 | 저장 뒤 켜져 있을 규격 이름 (같은 요청의 표 기준) | 본문 이미지에 쓸 규격 (기본 lg) |
| upload.thumbnail_disk_public | body | string | 아니오 | `_meta.thumbnail_disk_options.public` 의 id | 공개 이미지 썸네일 저장 디스크 (기본 thumbnails). 공개 자산 디스크를 고르면 302 로 직접 URL 을 낸다 |
| upload.thumbnail_disk_gated | body | string | 아니오 | `_meta.thumbnail_disk_options.gated` 의 id (thumbnails/s3) | 권한이 걸린 이미지 썸네일 저장 디스크 (기본 thumbnails). 늘 서버를 거쳐 스트림으로 낸다 |
| upload.thumbnail_prune_enabled | body | boolean | 아니오 | — | 썸네일 정리 스케줄(`thumbnails:prune --scheduled`, 매일 05:20) 사용 여부 (기본 true) |
| upload.thumbnail_animated_original | body | boolean | 아니오 | — | 움직이는 이미지(GIF·WebP·APNG)를 썸네일 대신 원본으로 낼지 (기본 true, 끄면 첫 프레임 썸네일. 확장 필터 `core.thumbnail.filter_animated_original` 가 바꿀 수 있다) |
| upload.thumbnail_generate_per_minute | body | integer | 아니오 | min 1, max 10000 | IP 하나가 1분에 새로 만들게 할 수 있는 썸네일 수. 비우면(null) `.env` 의 `G7_THUMBNAIL_GENERATE_PER_MINUTE` 또는 120. 범위는 `settings_limits.upload_thumbnail_*`, `.env` 우선 모드에서 `.env` 가 정한 키는 저장에서 빠지고 `_meta.env_locked` 에 실린다 (#869) |
| upload.thumbnail_lock_wait_seconds | body | integer | 아니오 | min 1, max 30 | 같은 썸네일을 다른 요청이 만들고 있을 때 기다리는 시간(초). 비우면 `G7_THUMBNAIL_LOCK_WAIT_SECONDS` 또는 10. 범위는 `settings_limits.upload_thumbnail_*`, `.env` 우선 모드에서 `.env` 가 정한 키는 저장에서 빠지고 `_meta.env_locked` 에 실린다 (#869) |
| upload.thumbnail_max_source_pixels | body | integer | 아니오 | min 1000000, max 100000000 | 썸네일을 만들 원본의 픽셀 수 상한(넘으면 원본, `memory_limit` 으로 한 번 더 줄인다). 비우면 `G7_THUMBNAIL_MAX_SOURCE_PIXELS` 또는 16000000. 범위는 `settings_limits.upload_thumbnail_*`, `.env` 우선 모드에서 `.env` 가 정한 키는 저장에서 빠지고 `_meta.env_locked` 에 실린다 (#869) |
| upload.thumbnail_pregenerate_batch | body | integer | 아니오 | min 1, max 500 | 「기존 이미지 썸네일 만들기」와 `thumbnails:pregenerate` 가 한 번에 처리하는 원본 수. 비우면 `G7_THUMBNAIL_PREGENERATE_BATCH` 또는 20. 범위는 `settings_limits.upload_thumbnail_*`, `.env` 우선 모드에서 `.env` 가 정한 키는 저장에서 빠지고 `_meta.env_locked` 에 실린다 (#869) |
| upload.thumbnail_pregenerate_time_budget | body | integer | 아니오 | min 1, max 60 | 「기존 이미지 썸네일 만들기」 한 번 호출의 시간 예산(초). 비우면 `G7_THUMBNAIL_PREGENERATE_TIME_BUDGET` 또는 15. 범위는 `settings_limits.upload_thumbnail_*`, `.env` 우선 모드에서 `.env` 가 정한 키는 저장에서 빠지고 `_meta.env_locked` 에 실린다 (#869) |
| upload.thumbnail_purge_time_budget | body | integer | 아니오 | min 1, max 60 | 썸네일 「비우기」 한 번 호출의 시간 예산(초). 비우면 `G7_THUMBNAIL_PURGE_TIME_BUDGET` 또는 10. 범위는 `settings_limits.upload_thumbnail_*`, `.env` 우선 모드에서 `.env` 가 정한 키는 저장에서 빠지고 `_meta.env_locked` 에 실린다 (#869) |
| seo.meta_title_suffix | body | string | 아니오 | max 100 | 모든 페이지 SEO 제목 뒤에 붙는 접미 문구 |
| seo.meta_description | body | string | 아니오 | max 160 | SEO 메타 설명 (검색엔진/소셜 공유 표시 요약) |
| seo.meta_keywords | body | string | 아니오 | max 255 | SEO 메타 키워드 (검색엔진 노출 키워드, 쉼표 구분) |
| seo.google_analytics_id | body | string | 아니오 | max 50 | seo.google analytics 식별자 |
| seo.google_site_verification | body | string | 아니오 | max 100 | Google Search Console 사이트 소유 확인 코드 |
| seo.naver_site_verification | body | string | 아니오 | max 100 | 네이버 서치어드바이저 사이트 소유 확인 코드 |
| seo.bot_user_agents | body | array | 아니오 | — | 봇으로 판정할 User-Agent 목록 |
| seo.bot_detection_enabled | body | boolean | 아니오 | — | 검색엔진 봇 감지 사용 여부 (봇 요청에 SEO 렌더링 적용) |
| seo.bot_detection_library_enabled | body | boolean | 아니오 | — | 봇 감지 라이브러리 사용 여부 (User-Agent 목록 대신 라이브러리 판정) |
| seo.og_default_site_name | body | string | 아니오 | max 200 | seo.og default site 이름 (식별자) |
| seo.og_image_default | body | array | 아니오 | 항목: 존재하는 첨부 ID | 사이트 기본 공유 이미지(og:image) 첨부 ID 배열 — 화면별 og:image 가 없을 때 폴백으로 사용. 화면 제출 형태(첨부 객체 배열)도 수용하며 정수 ID 배열로 정규화된다 (7.0.9+) |
| seo.og_image_default_width | body | integer | 아니오 | min 0, max 8000 | 기본 Open Graph 이미지 너비 (px) |
| seo.og_image_default_height | body | integer | 아니오 | min 0, max 8000 | 기본 Open Graph 이미지 높이 (px) |
| seo.twitter_default_card | body | string | 아니오 | — | 기본 트위터 카드 유형 (summary 등) |
| seo.twitter_default_site | body | string | 아니오 | max 50 | 기본 트위터 사이트 계정 (@handle) |
| seo.cache_enabled | body | boolean | 아니오 | — | 캐시 사용 여부 |
| seo.cache_ttl | body | integer | 아니오 | min 60, max 86400 | 캐시 유효 시간 (초) |
| seo.sitemap_enabled | body | boolean | 아니오 | — | sitemap.xml 생성 사용 여부 |
| seo.sitemap_cache_ttl | body | integer | 아니오 | min 3600, max 604800 | sitemap 캐시 유효 시간 (초) |
| seo.sitemap_urls_per_file | body | integer | 아니오 | min 1000, max 50000 | 사이트맵 자식 파일 하나에 담는 URL 수 (분할 기준). 상한은 sitemaps.org 프로토콜 제한이다 |
| seo.sitemap_gzip | body | boolean | 아니오 | — | 사이트맵 자식 파일을 gzip 으로 압축할지 여부. 인덱스 파일은 항상 비압축이다 |
| seo.sitemap_serve_stale_on_miss | body | boolean | 아니오 | — | 신선도가 만료됐을 때 기존 세트를 그대로 서빙할지 여부. `false` 면 503 을 반환한다 |
| seo.sitemap_max_urls_per_contributor | body | integer | 아니오 | min 0 | 수집기 하나가 실을 수 있는 URL 상한 (0 = 무제한). 초과분은 잘리고 경고 로그가 남는다 |
| seo.sitemap_hreflang_enabled | body | boolean | 아니오 | — | 다국어 alternate(hreflang) 링크를 함께 출력할지 여부 |
| seo.generator_enabled | body | boolean | 아니오 | — | SEO 페이지 생성기 사용 여부 |
| seo.generator_content | body | string | 아니오 | max 200 | SEO 렌더링 본문 생성 방식 |
| security.registration_enabled | body | boolean | 아니오 | — | 일반 회원가입 받기(7.2.0, 기본 true). 끄면 공개 가입 API 가 403 `auth.registration_disabled` 이고 소셜 로그인 플러그인의 신규 가입도 닫힌다. 관리자 추가·외부 인증 확장의 회원 생성은 계속된다. 다른 보안 칸 없이 보내도 된다(부분 저장) |
| security.force_https | body | boolean | 아니오 | — | HTTPS 강제 리다이렉트 여부 |
| security.login_attempt_enabled | body | boolean | 아니오 | — | 로그인 시도 제한 사용 여부 |
| security.auth_token_lifetime | body | integer | 아니오 | min 0, max 3600 | 인증 토큰 유효 시간 (분) |
| security.max_login_attempts | body | integer | 아니오 | min 0, max 100 | 로그인 실패 허용 횟수 (초과 시 잠금) |
| security.login_lockout_time | body | integer | 아니오 | min 0, max 1440 | 로그인 잠금 지속 시간 (분) |
| security.password_min_length | body | integer | 아니오 | min 6, max 64 | 비밀번호 최소 길이 |
| security.require_password_special_char | body | boolean | 아니오 | — | 비밀번호에 특수문자를 반드시 포함할지 여부 |
| security.two_factor_auth | body | boolean | 아니오 | — | 2단계 인증 사용 여부 |
| security.allow_internal_outbound_urls | body | boolean | 아니오 | — | 내부 네트워크 주소로의 아웃바운드 호출을 허용할지 여부 (기본 차단 — 켜면 SSRF 방어가 완화된다) |
| security.ip_restriction_enabled | body | boolean | 아니오 | — | IP 접근 제한 마스터 토글 (기본 false). 끄면 아래 목록·규칙은 저장만 되고 적용되지 않는다 |
| security.admin_allowed_ips | body | array | 아니오 | max 100 | 관리자 영역(관리자 화면·코어/확장 관리자 API·관리자 로그인 API)에 접근을 허용할 IP·CIDR 목록. **빈 목록 = 이 축 미적용**. 앞뒤 공백·빈 항목·중복은 저장 전에 정리된다 |
| security.admin_allowed_ips.* | body | string | 아니오 | IPv4·IPv6·CIDR (`203.0.113.0/24`, `2001:db8::/32`) | 허용 항목. 호스트명·범위 표기(`a-b`)·와일드카드(`192.168.*`)는 422 |
| security.ip_restriction_rules | body | array | 아니오 | max 50 | 경로별 접근 규칙 목록. **사용 중인 첫 매칭 규칙 하나만** 적용된다 |
| security.ip_restriction_rules.*.pattern | body | string | 예 | max 255, 공백·`?`·`#` 불가 | URI glob — `*` 는 `/` 를 포함해 무엇이든 맞고 앞뒤 `/` 는 무시 (예: `/api/admin/users*`) |
| security.ip_restriction_rules.*.ips | body | array | 예 | 1개 이상, max 100 | 이 규칙에 맞는 경로를 허용할 IP·CIDR 목록 |
| security.ip_restriction_rules.*.ips.* | body | string | 아니오 | IPv4·IPv6·CIDR | 허용 항목 |
| security.ip_restriction_rules.*.memo | body | string | 아니오 | max 255 | 운영자 메모 |
| security.ip_restriction_rules.*.enabled | body | boolean | 아니오 | — | 규칙 사용 여부 (미지정 = 사용). 삭제하지 않고 잠시 끌 때 쓴다 |
| security.csp_mode | body | string | 아니오 | `off`·`report_only`·`enforce` | 콘텐츠 보안 정책 헤더 모드 (#158, 기본 `off`). `report_only` 는 `Content-Security-Policy-Report-Only`, `enforce` 는 `Content-Security-Policy` 를 사이트 HTML 응답에 붙인다. `.env G7_CSP_MODE` 가 있으면 저장값과 무관하게 그 값이 적용된다 |
| security.csp_extra_script_hosts | body | array | 아니오 | max 50 | `script-src` 에 더할 운영자 추가 호스트. 활성 확장이 선언한 호스트는 자동으로 들어가므로 그 밖의 호스트만 넣는다. 앞뒤 공백 제거·소문자화·빈 항목·중복은 저장 전에 정리된다 |
| security.csp_extra_script_hosts.* | body | string | 아니오 | 호스트 소스 (`cdn.example.com`·`*.example.com`·`https://cdn.example.com`·`cdn.example.com:8443`) | 따옴표·세미콜론·공백·`'unsafe-*'` 같은 키워드·`data:`·`*` 단독·`http://` 는 422 (정책 문자열에 그대로 이어 붙이므로) |
| security.password_require_char_types | body | boolean | 아니오 | — | 비밀번호 조합 규칙 — 켜면 영문·숫자·특수문자 중 2종류 이상 (기본 false). 가입·변경·재설정·관리자 지정 전 경로에 적용 |
| security.password_block_identifier | body | boolean | 아니오 | — | 이메일 `@` 앞부분(3자 이상, 대소문자 무시)이 들어간 비밀번호 거부 (기본 false) |
| security.password_block_repeated_chars | body | boolean | 아니오 | — | 같은 문자가 연속으로 반복되는 비밀번호 거부 (기본 false) |
| security.password_repeated_chars_length | body | integer | 아니오 | min 3, max 6 | 반복 금지가 거부하기 시작하는 글자 수 (기본 4) |
| security.password_block_sequential_chars | body | boolean | 아니오 | — | 연속 문자(숫자 `1234`·영문 `abcd`·자판 행 `qwer`, 오름·내림) 거부 (기본 false) |
| security.password_sequential_chars_length | body | integer | 아니오 | min 3, max 6 | 연속 문자 금지가 거부하기 시작하는 글자 수 (기본 4) |
| security.password_reuse_prevention_enabled | body | boolean | 아니오 | — | 최근 비밀번호 재사용 금지 (기본 false). 관리자를 포함한 전 회원, 관리자가 **다른 회원**의 비밀번호를 정할 때는 검사하지 않는다 |
| security.password_reuse_count | body | integer | 아니오 | min 1, max 10 | 다시 쓸 수 없는 최근 비밀번호 개수 — 현재 비밀번호 포함 (기본 3) |
| security.password_expiry_enabled | body | boolean | 아니오 | — | 비밀번호 만료 사용 (기본 false). 꺼짐→켜짐으로 저장한 순간을 서버가 만료 기준 시작일로 기록한다(기존 회원은 그날부터 계산) |
| security.password_expiry_days | body | integer | 아니오 | min 30, max 730 | 비밀번호 사용 기간(일) (기본 90) |
| security.password_expiry_notice_days | body | integer | 아니오 | min 1, max 30 | 만료 며칠 전에 사전 안내를 보낼지 (기본 7). **만료가 켜져 있으면 `security.password_expiry_days` 보다 작아야 하며**, 같거나 크면 422 (`validation.settings.password_expiry_notice_days_lt_expiry_days`) |
| security.password_expiry_scope | body | string | 아니오 | `all`, `admin`, `selected` | 만료 적용 범위 — 전체 회원 / 관리자만 / 역할·회원에서 '적용'으로 지정한 대상만 (기본 `all`). 회원 개별 설정 > 역할 설정 > 이 범위 순으로 판정 |
| security.password_expiry_action | body | string | 아니오 | `force`, `remind` | 만료 시 동작 — 변경해야 로그인 / 다음에 변경 허용 (기본 `force`). `force` 면 만료 처리 때 그 회원의 로그인 토큰을 모두 폐기 |
| security.password_expiry_remind_days | body | integer | 아니오 | min 1, max 90 | '다음에 변경'을 고른 뒤 다시 안내하기까지의 일수 (기본 30, `remind` 일 때만 사용) |
| security.password_expiry_batch_size | body | integer | 아니오 | min 0, max 100000 | 1회 실행당 안내 상한 — 사전 안내·만료 처리에 각각 적용, 만료가 가까운 순 (기본 1000, 0 = 무제한) |
| security.activity_log_retention_days | body | integer | 아니오 | min 365, max 3650 | 활동 로그(접속기록) 보관 기간(일) — 매일 04:45 자동 파기가 따른다. 365 미만은 법정 최소 안내 문구(`validation.settings.activity_log_retention_days_min`)로 422. 권한·역할 변경 기록은 이 값과 관계없이 최소 3년 보관 (기본 365) |
| advanced.cache_enabled | body | boolean | 아니오 | — | 캐시 사용 여부 |
| advanced.layout_cache_enabled | body | boolean | 아니오 | — | 레이아웃 캐시 사용 여부 (레이아웃 데이터를 캐시) |
| advanced.layout_cache_ttl | body | integer | 아니오 | min 0, max 14400 | 레이아웃 캐시 만료 시간 (초, 0 = 만료 없음) |
| advanced.stats_cache_enabled | body | boolean | 아니오 | — | 통계 캐시 사용 여부 (대시보드 통계를 캐시) |
| advanced.stats_cache_ttl | body | integer | 아니오 | min 0, max 14400 | 통계 캐시 만료 시간 (초, 0 = 만료 없음) |
| advanced.seo_cache_enabled | body | boolean | 아니오 | — | SEO 캐시 사용 여부 (SEO 메타데이터를 캐시) |
| advanced.seo_cache_ttl | body | integer | 아니오 | min 0, max 14400 | SEO 캐시 만료 시간 (초, 0 = 만료 없음) |
| advanced.seo_sitemap_cache_ttl | body | integer | 아니오 | min 3600, max 604800 | 사이트맵 메타 캐시 만료 시간(초) |
| advanced.debug_mode | body | boolean | 아니오 | — | 디버그 모드 사용 여부 (상세 오류 노출) |
| advanced.sql_query_log | body | boolean | 아니오 | — | SQL 쿼리 로그 기록 여부 |
| advanced.outbound_proxy | body | string | 아니오 | max 500 | 외부 HTTP 호출이 경유할 프록시 주소 (예: `socks5h://127.0.0.1:1080`). 빈 값이면 사용하지 않으며, 디버그 모드가 꺼져 있으면 저장되어도 적용되지 않는다 |
| advanced.outbound_proxy_bypass | body | array | 아니오 | — | 프록시를 경유하지 않을 호스트 목록 |
| advanced.outbound_proxy_bypass.* | body | string | 아니오 | max 255 | 프록시 예외 호스트 |
| advanced.trusted_proxies | body | array | 아니오 | 최대 50개 | 신뢰할 리버스 프록시 목록. 빈 배열은 「아무 프록시도 신뢰하지 않음」이라는 **명시적 선택**이며, 키를 보내지 않으면 기존 값이 유지된다 (`.env` 의 `TRUSTED_PROXIES` 권위가 살아 있는 상태) |
| advanced.trusted_proxies.* | body | string | 아니오 | max 60 | 신뢰할 프록시 주소. IPv4/IPv6 또는 CIDR, 혹은 직전 호출 IP 만 신뢰하는 `*` |
| advanced.core_update_github_url | body | string | 아니오 | max 500 | 코어 업데이트를 확인할 GitHub 저장소 URL |
| advanced.core_update_github_token | body | string | 아니오 | max 500 | 프라이빗 저장소의 코어/확장 업데이트에 사용할 GitHub 액세스 토큰 (공개 저장소는 비워둘 수 있음) |
| advanced.geoip_enabled | body | boolean | 아니오 | — | IP 기반 타임존 감지(GeoIP) 사용 여부 |
| advanced.geoip_license_key | body | string | 아니오 | max 200 | MaxMind GeoLite2 라이선스 키 |
| advanced.geoip_auto_update_enabled | body | boolean | 아니오 | — | GeoIP DB 자동 업데이트 사용 여부 (주 1회 자동 재다운로드) |
| advanced.pagination_result_cap | body | integer | 아니오 | min 0, max 1000000 | 총 건수를 정확히 세는 상한. 0 이면 항상 전부 센다 |
| advanced.pagination_max_page | body | integer | 아니오 | min 0, max 100000 | 주소로 직접 요청할 수 있는 최대 페이지 번호. 0 이면 제한하지 않는다 |
| drivers.storage_driver | body | string | 아니오 | — | 스토리지 드라이버 (local/s3) |
| drivers.s3_bucket | body | string | 아니오 | max 255 | S3 버킷명 |
| drivers.s3_region | body | string | 아니오 | max 64 | S3 리전 — AWS 리전 코드 또는 S3 호환 스토리지 값 (Cloudflare R2 는 `auto`, MinIO 관례는 `us-east-1`) |
| drivers.s3_access_key | body | string | 아니오 | max 255 | S3 액세스 키 |
| drivers.s3_secret_key | body | string | 아니오 | max 255 | S3 시크릿 키 |
| drivers.s3_url | body | string | 아니오 | max 500 | S3 공개 URL(CDN) base — 파일 URL 생성에만 사용 (API 요청 주소 아님) |
| drivers.s3_endpoint | body | string | 아니오 | max 500 | S3 API 엔드포인트 — S3 호환 스토리지(R2/MinIO/NCP 등)용. AWS S3 는 미입력 (예: `https://<account-id>.r2.cloudflarestorage.com`) |
| drivers.s3_use_path_style | body | boolean | 아니오 | — | S3 path-style 주소 사용 여부 — MinIO 등 path-style 전용 스토리지에서 true |
| drivers.cache_driver | body | string | 아니오 | — | 캐시 드라이버 (file/redis/memcached) |
| drivers.redis_host | body | string | 아니오 | max 255 | Redis 호스트 주소 |
| drivers.redis_port | body | integer | 아니오 | min 1, max 65535 | Redis 포트 번호 |
| drivers.redis_password | body | string | 아니오 | max 255 | Redis 비밀번호 |
| drivers.redis_database | body | integer | 아니오 | min 0, max 15 | Redis 데이터베이스 번호 |
| drivers.memcached_host | body | string | 아니오 | max 255 | Memcached 호스트 주소 |
| drivers.memcached_port | body | integer | 아니오 | min 1, max 65535 | Memcached 포트 번호 |
| drivers.session_driver | body | string | 아니오 | — | 세션 드라이버 (file/database/redis) |
| drivers.session_lifetime | body | integer | 아니오 | min 1, max 43200 | 세션 유효 시간 (분) |
| drivers.queue_driver | body | string | 아니오 | — | 큐 드라이버 (sync/database/redis) |
| drivers.queue_connections | body | string[] | 아니오 | 각 원소 `_meta.queue_connection_options` 의 name, 중복 불가, 현재 큐 드라이버 연결 포함 | 이 서버가 워커를 돌리는 큐 연결 (#139). 빈 배열이면 현재 큐 드라이버 연결 하나로 저장된다 |
| drivers.queue_names | body | object | 아니오 | `{연결: string[]}`, 연결당 1~20개, 큐 이름 `^[A-Za-z0-9][A-Za-z0-9_.-]*$`·64자 이하·연결 안 중복 불가 | 연결별로 워커가 보는 큐 이름 (#139). 키가 없는 등록 연결은 기본값(`*-long` 은 `long`, 그 밖은 `default`)으로 저장되고, 등록하지 않은 연결의 키는 버려진다 |
| drivers.queue_job_log_enabled | body | boolean | 아니오 | — | 큐 작업 처리 기록(`queue_job_logs`) 사용 여부 (기본 true) — [큐 관리](../queue-management.md) |
| drivers.queue_record_retention_days | body | integer | 아니오 | min 1, max 90 (`core.settings_limits.drivers_queue_record_retention_days_min/max`) | 큐 처리 기록·실패 작업 공통 보관 기간(일, 기본 7) — 매일 `queue:prune-records` 가 정리 |
| drivers.websocket_enabled | body | boolean | 아니오 | — | WebSocket 사용 여부 |
| drivers.websocket_app_id | body | string | 아니오 | max 255 | drivers.websocket app 식별자 |
| drivers.websocket_app_key | body | string | 아니오 | max 255 | WebSocket 앱 키 |
| drivers.websocket_app_secret | body | string | 아니오 | max 255 | WebSocket 앱 시크릿 |
| drivers.websocket_host | body | string | 아니오 | max 255 | WebSocket 호스트 주소 |
| drivers.websocket_port | body | integer | 아니오 | min 1, max 65535 | WebSocket 포트 번호 |
| drivers.websocket_scheme | body | string | 아니오 | — | WebSocket 스킴 (http/https) |
| drivers.websocket_verify_ssl | body | boolean | 아니오 | — | WebSocket 서버 SSL 인증서 검증 여부 |
| drivers.websocket_server_host | body | string | 아니오 | max 255 | WebSocket 서버 호스트 주소 (서버측 발행 대상) |
| drivers.websocket_server_port | body | integer | 아니오 | min 1, max 65535 | WebSocket 서버 포트 번호 (서버측 발행 대상) |
| drivers.websocket_server_scheme | body | string | 아니오 | — | WebSocket 서버 스킴 (http/https — 서버측 발행 대상) |
| drivers.search_engine_driver | body | string | 아니오 | — | 검색 엔진 드라이버 (Scout 엔진 선택) |
| drivers.image_driver | body | string | 아니오 | max 100, 코어 엔진은 이 서버에서 쓸 수 있어야 함, 그 밖은 `available_drivers.image` 에 있어야 함 | 이미지 처리 엔진 (gd/imagick + 플러그인 등록 엔진, 기본 gd, #869). `available_drivers.image` 는 이 서버에서 쓸 수 없는 코어 엔진을 빼고 낸다(imagick 확장이 없으면 gd 만). 쓸 수 없는 엔진을 직접 보내면 422 와 사유(`settings.driver_unusable_*_extension`) |
| drivers.log_driver | body | string | 아니오 | — | 로그 드라이버 (single/daily/stack) |
| drivers.log_level | body | string | 아니오 | — | 로그 레벨 (debug/info/warning/error 등) |
| drivers.log_days | body | integer | 아니오 | min 1, max 365 | 로그 파일 보관 일수 |
| drivers.public_asset_disk | body | string | 아니오 | max 100 | 공개 자산 직접 URL 서빙 디스크 (none/public/s3 + 플러그인 훅 등록 디스크). 카탈로그(`available_drivers.public_asset`)에 없는 값은 422 |
| identity.default_provider | body | string | 아니오 | max 100 | 본인인증 기본 프로바이더 (목적별로 지정되지 않은 경우 사용). 예: `g7:core.mail` |
| identity.purpose_providers | body | array | 아니오 | — | 본인인증 목적(Purpose)별 프로바이더 매핑. 미지정 목적은 기본 프로바이더를 사용 |
| identity.challenge_ttl_minutes | body | integer | 아니오 | min 1, max 1440 | 발급된 인증 코드/링크(challenge)의 유효시간 (분) |
| identity.max_attempts | body | integer | 아니오 | min 1, max 20 | challenge 최대 시도 횟수 (연속 실패 시 잠금 — 재전송 필요) |
| privacy.consent_provider | body | string | 아니오 | 빈 값 · 등록된 동의 제공자 id · 현재 저장값 | 방문자 동의를 판단할 동의 관리 제공자. 빈 값이면 자동(수집 중인 제공자 1개면 그것, 2개 이상이면 고르기 전까지 모든 방문자 「확인 불가」). 저장값을 허용하는 이유: 고른 제공자가 비활성화된 상태에서 다른 칸만 고쳐 저장할 때 화면이 옛 id 를 다시 보낸다. 그 밖의 값은 422 |
| privacy.withdrawn_retention_enabled | body | boolean | 아니오 | — | 탈퇴 회원 잔여 개인정보의 2차 파기 사용 여부 (기본 false). 켜면 매일 배치가 보유기간이 지난 탈퇴 회원의 연락처·주소·본인인증 식별자를 되돌릴 수 없게 지운다 |
| privacy.withdrawn_retention_days | body | integer | 아니오 | min 1, max 3650 | 탈퇴 시점부터 잔여 정보를 보유할 일수 (기본 365). 경과분만 파기 대상 |
| privacy.dormant_enabled | body | boolean | 아니오 | — | 장기 미접속 회원의 휴면 전환 사용 여부 (기본 false). 활동 기준은 `max(users.last_login_at, personal_access_tokens.last_used_at)` |
| privacy.dormant_days | body | integer | 아니오 | min 30, max 3650 | 휴면 전환 기준 미접속 일수 (기본 730) |
| privacy.dormant_notice_days | body | integer | 아니오 | min 1, max 365 | 휴면 전환 사전 통지를 며칠 전에 보낼지 (기본 30). **`privacy.dormant_days` 보다 작아야 하며**, 같거나 크면 422 (`validation.settings.privacy_dormant_notice_days_lt_dormant_days`). 통지 후 이 일수가 지나야 실제 전환된다 |
| privacy.dormant_withdraw_enabled | body | boolean | 아니오 | — | 휴면이 오래 이어진 계정의 자동 탈퇴 사용 여부 (기본 false). 관리자 역할 보유자와 슈퍼 관리자는 예고·탈퇴 대상에서 모두 제외된다 |
| privacy.dormant_withdraw_days | body | integer | 아니오 | min 1, max 3650 | 휴면 전환 시점부터 자동 탈퇴까지의 일수 (기본 365). 이때도 `privacy.dormant_notice_days` 만큼 앞서 탈퇴 예고를 보낸다 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.settings.save_validation_rules`, `core.search.engine_drivers`).

> 로그인 보안 정수 키(`security.auth_token_lifetime`·`security.max_login_attempts`·`security.login_lockout_time`), 비밀번호 정책 정수 키(최소 길이 `security.password_min_length` 포함)와 `security.activity_log_retention_days` 는 `"5"` 처럼 문자열로 보내도 정수로 정규화해 저장한다. 그래서 `security.auth_token_lifetime` 의 `"0"`(무한대)도 `0` 으로 받아들인다. 만료 기준 시작일(`security.password_expiry_started_at`)은 서버가 기록하는 값이라 이 요청으로 보내도 저장되지 않는다.

**요청 예시**

```http
POST /api/admin/settings HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "_tab": "예시값",
    "general": [
        "예시값"
    ],
    "mail": [
        "예시값"
    ],
    "upload": [
        "예시값"
    ],
    "seo": [
        "예시값"
    ],
    "security": [
        "예시값"
    ],
    "drivers": [
        "예시값"
    ],
    "advanced": [
        "예시값"
    ],
    "notifications": [
        "예시값"
    ],
    "identity": [
        "예시값"
    ],
    "system": [
        "예시값"
    ],
    "notifications.channels": [
        "예시값"
    ],
    "general.site_name": "예시 이름",
    "general.site_url": "https://example.com",
    "general.site_description": "예시 내용입니다.",
    "general.admin_email": "user@example.com",
    "general.timezone": "Asia/Seoul",
    "general.language": "예시값",
    "general.currency": "예시값",
    "general.maintenance_mode": true,
    "general.asset_url_mode": "https://example.com",
    "system.execution_mode": "예시값",
    "system.cron_mode": "예시값",
    "system.cron_allowed_ips": "예시값",
    "system.cron_min_interval": 1,
    "system.cron_visit_fallback": true,
    "general.site_logo": [
        "예시값"
    ],
    "mail.mailer": "예시값",
    "mail.host": "예시값",
    "mail.port": 1,
    "mail.username": "예시 이름",
    "mail.password": "Password123!",
    "mail.encryption": "예시값",
    "mail.mailgun_domain": "예시값",
    "mail.mailgun_secret": "예시값",
    "mail.mailgun_endpoint": "예시값",
    "mail.ses_key": "예시값",
    "mail.ses_secret": "예시값",
    "mail.ses_region": "예시값",
    "mail.from_address": "user@example.com",
    "mail.from_name": "예시 이름",
    "upload.max_file_size": 1,
    "upload.allowed_extensions": "예시값",
    "upload.image_max_width": 1,
    "upload.image_max_height": 1,
    "upload.image_quality": 1,
    "upload.orphan_cleanup_enabled": true,
    "upload.orphan_retention_days": 1,
    "upload.thumbnail_enabled": true,
    "upload.thumbnail_presets": [
        {"name": "xs", "source": "core", "width": 64, "height": 64, "fit": "cover", "anchor": "center", "quality": null, "format": "original", "upscale": false, "pregenerate": false, "numeric": false, "enabled": true}
    ],
    "upload.thumbnail_content_preset": "lg",
    "upload.thumbnail_disk_public": "thumbnails",
    "upload.thumbnail_disk_gated": "thumbnails",
    "upload.thumbnail_prune_enabled": true,
    "upload.thumbnail_animated_original": true,
    "upload.thumbnail_generate_per_minute": 120,
    "upload.thumbnail_lock_wait_seconds": 10,
    "upload.thumbnail_max_source_pixels": null,
    "upload.thumbnail_pregenerate_batch": 20,
    "upload.thumbnail_pregenerate_time_budget": 15,
    "upload.thumbnail_purge_time_budget": null,
    "seo.meta_title_suffix": "예시 제목",
    "seo.meta_description": "예시 내용입니다.",
    "seo.meta_keywords": "예시값",
    "seo.google_analytics_id": "예시값",
    "seo.google_site_verification": "예시값",
    "seo.naver_site_verification": "예시값",
    "seo.bot_user_agents": [
        "예시값"
    ],
    "seo.bot_detection_enabled": true,
    "seo.bot_detection_library_enabled": true,
    "seo.og_default_site_name": "예시 이름",
    "seo.og_image_default_width": 1,
    "seo.og_image_default_height": 1,
    "seo.twitter_default_card": "예시값",
    "seo.twitter_default_site": "예시값",
    "seo.cache_enabled": true,
    "seo.cache_ttl": 1,
    "seo.sitemap_enabled": true,
    "seo.sitemap_cache_ttl": 1,
    "seo.sitemap_urls_per_file": 1,
    "seo.sitemap_gzip": true,
    "seo.sitemap_serve_stale_on_miss": true,
    "seo.sitemap_max_urls_per_contributor": 1,
    "seo.sitemap_hreflang_enabled": true,
    "seo.generator_enabled": true,
    "seo.generator_content": "예시 내용입니다.",
    "security.force_https": true,
    "security.login_attempt_enabled": true,
    "security.auth_token_lifetime": 1,
    "security.max_login_attempts": 1,
    "security.login_lockout_time": 1,
    "security.password_min_length": 1,
    "security.require_password_special_char": true,
    "security.two_factor_auth": true,
    "security.allow_internal_outbound_urls": true,
    "security.ip_restriction_enabled": true,
    "security.admin_allowed_ips": [
        "203.0.113.24",
        "10.0.0.0/8"
    ],
    "security.ip_restriction_rules": [
        {
            "pattern": "/api/admin/users*",
            "ips": ["203.0.113.0/24"],
            "memo": "본사망만",
            "enabled": true
        }
    ],
    "advanced.cache_enabled": true,
    "advanced.layout_cache_enabled": true,
    "advanced.layout_cache_ttl": 1,
    "advanced.stats_cache_enabled": true,
    "advanced.stats_cache_ttl": 1,
    "advanced.seo_cache_enabled": true,
    "advanced.seo_cache_ttl": 1,
    "advanced.seo_sitemap_cache_ttl": 1,
    "advanced.debug_mode": true,
    "advanced.sql_query_log": true,
    "advanced.outbound_proxy": "예시값",
    "advanced.outbound_proxy_bypass": [
        "예시값"
    ],
    "advanced.core_update_github_url": "https://example.com",
    "advanced.core_update_github_token": "{YOUR_TOKEN}",
    "advanced.geoip_enabled": true,
    "advanced.geoip_license_key": "예시값",
    "advanced.geoip_auto_update_enabled": true,
    "advanced.pagination_result_cap": 1,
    "advanced.pagination_max_page": 1,
    "drivers.storage_driver": "예시값",
    "drivers.s3_bucket": "예시값",
    "drivers.s3_region": "예시값",
    "drivers.s3_access_key": "예시값",
    "drivers.s3_secret_key": "예시값",
    "drivers.s3_url": "https://example.com",
    "drivers.s3_endpoint": "예시값",
    "drivers.s3_use_path_style": true,
    "drivers.cache_driver": "예시값",
    "drivers.redis_host": "예시값",
    "drivers.redis_port": 1,
    "drivers.redis_password": "Password123!",
    "drivers.redis_database": 1,
    "drivers.memcached_host": "예시값",
    "drivers.memcached_port": 1,
    "drivers.session_driver": "예시값",
    "drivers.session_lifetime": 1,
    "drivers.queue_driver": "예시값",
    "drivers.queue_connections": ["database", "database-long"],
    "drivers.queue_names": {"database": ["default", "notifications"], "database-long": ["long"]},
    "drivers.websocket_enabled": true,
    "drivers.websocket_app_id": "예시값",
    "drivers.websocket_app_key": "예시값",
    "drivers.websocket_app_secret": "예시값",
    "drivers.websocket_host": "예시값",
    "drivers.websocket_port": 1,
    "drivers.websocket_scheme": "예시값",
    "drivers.websocket_verify_ssl": true,
    "drivers.websocket_server_host": "예시값",
    "drivers.websocket_server_port": 1,
    "drivers.websocket_server_scheme": "예시값",
    "drivers.search_engine_driver": "예시값",
    "drivers.image_driver": "gd",
    "drivers.log_driver": "예시값",
    "drivers.log_level": "예시값",
    "drivers.log_days": 1,
    "drivers.public_asset_disk": "예시값",
    "identity.default_provider": "예시값",
    "identity.purpose_providers": [
        "예시값"
    ],
    "identity.challenge_ttl_minutes": 1,
    "identity.max_attempts": 1
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| settings | object | `{"general":{...},"security":{...},"available_drivers":{...}}` | 저장 직후 다시 조회한 전체 설정 집계. 구조는 `GET /api/admin/settings` 의 `data` 와 동일 (general/security/mail/upload/seo/advanced/cache/debug/drivers/core_update/geoip/notifications/identity + `available_drivers`) |
| frontend_settings | object | `{"general":{"site_logo_url":"/api/attachment/…","site_favicon_url":null,…},…}` | 셸 `G7Config.settings` 와 같은 공개 설정(노출 필드 + 계산 필드 `general.site_logo_url`·`general.site_favicon_url`) — 화면은 저장 뒤 전역 설정을 이것으로 바꿔 새로고침 없이 상단 로고·파비콘 주소를 유지한다 |

**응답 예시**

```json
{
    "success": true,
    "message": "설정이 성공적으로 저장되었습니다.",
    "data": {
        "settings": {
            "general": {
                "site_name": "Test Site",
                "site_url": "https://test.example.com",
                "site_description": "",
                "admin_email": "admin@example.com",
                "timezone": "Asia/Seoul",
                "language": "ko",
                "currency": "KRW",
                "maintenance_mode": false,
                "site_logo": []
            },
            "security": {
                "force_https": true,
                "login_attempt_enabled": true,
                "auth_token_lifetime": 0,
                "max_login_attempts": 5,
                "login_lockout_time": 30
            },
            "available_drivers": {
                "storage": [
                    {
                        "id": "local",
                        "label": {
                            "ko": "로컬",
                            "en": "Local"
                        }
                    }
                ]
            }
        }
    }
}
```

> 위 예시는 지면상 일부 그룹만 표기한 것으로, 실제 응답의 `data.settings` 에는 `GET /api/admin/settings` 와 동일한 전체 그룹이 포함됩니다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). 보안 탭에서는 **저장하면 요청자 자신이 잠기는 IP 접근 제한**(현재 IP 가 관리자 영역 허용 목록 밖이거나, 설정 화면 경로를 막는 규칙에 걸림)도 422 — `security.admin_allowed_ips` 또는 `security.ip_restriction_rules` 에 현재 IP 가 담긴 문구 |

<!-- @generated:end -->

**큐 워커 등록 검증 (#139, `drivers` 탭)**

| # | 경우 | 오류 키 | 메시지 키 (`validation.settings.*`) |
| --- | --- | --- | --- |
| V1 | 등록할 수 없는(정의되지 않았거나 sync·null) 연결 | `drivers.queue_connections.N` | `queue_connection_invalid` |
| V2 | 같은 연결이 두 번 (두 번째 칸) | `drivers.queue_connections.N` | `queue_connection_duplicate` |
| V3 | 현재 큐 드라이버(sync 아님) 연결을 목록에서 뺌 — 빈 목록은 통과(현재 드라이버 연결 하나로 저장) | `drivers.queue_connections` | `queue_connection_default_required` (`:driver`) |
| V4 | 큐 목록 키가 정의되지 않은 연결 (화면 밖 직접 요청 방어) | `drivers.queue_names` | `queue_names_connection_unknown` (`:connection`) |
| V5 | 등록한 연결의 큐 목록이 빈 배열 | `drivers.queue_names.{연결}` | `queue_names_required` (`:connection`) |
| V6 | 빈 큐 이름 | `drivers.queue_names.{연결}.N` | `queue_name_required` |
| V7 | 허용 외 문자·첫 글자 | `drivers.queue_names.{연결}.N` | `queue_name_invalid` |
| V8 | 64자 초과 | `drivers.queue_names.{연결}.N` | `queue_name_max` |
| V9 | 한 연결 안에서 같은 큐 이름 두 번 (연결이 다르면 허용) | `drivers.queue_names.{연결}` | `queue_name_duplicate` (`:connection`, `:name`) |

연결당 20개 초과는 `queue_names_max`. 조회·저장 응답의 `drivers.queue_names` 는 비어 있어도 객체(`{}`)로 내려간다 — 배열(`[]`)이면 화면이 붙인 키가 저장 본문에서 사라진다(#758 과 같은 결함군).

**설명**

통합 환경설정 화면에서 한 탭의 설정을 일괄 저장합니다. `_tab` 으로 활성 탭을 지정하면 해당 탭의 필드만 필수 검증되고 다른 탭 필드는 nullable 로 처리되므로, 탭 단위로 부분 저장할 수 있습니다. 저장 성공 시 응답 `data.settings` 에 갱신된 전체 설정과 `available_drivers` 를 함께 반환하여, 프론트엔드가 새로고침 없이 전역 상태를 갱신할 수 있습니다. 검증 실패 시 422, 그 외 오류 시 500 을 반환합니다.

**공개 설정 동봉(#434)** 저장 응답에는 `data.settings`(관리자 형태) 외에 `data.frontend_settings`(셸 `G7Config.settings` 와 같은 공개 설정 — 계산 필드 `general.site_logo_url`·`general.site_favicon_url` 포함)가 함께 실린다. 화면은 저장 직후 전역 설정(`_global.settings`)을 이 값으로 바꾼다 — 관리자 형태로 바꾸면 계산 필드가 없어 상단 로고가 새로고침 전까지 사라졌다. 일반 탭의 `general.site_favicon` 은 첨부 ID 배열(최대 1개, `site_favicon` 컬렉션에 실재하는 첨부만)이며, 제출하지 않으면 기존 값을 유지하고 빠진 첨부는 저장 성공 뒤 파기한다.

**파비콘 우선순위·서빙(#434)** 브라우저 탭 아이콘은 화면 종류마다 위 계층부터 고른다 — ① 그 화면 종류의 활성 템플릿 설정 `branding.favicon`(사용자 화면 = 활성 사용자 템플릿, 관리자 화면 = 활성 관리자 템플릿) ② 이 API 의 `general.site_favicon` ③ 기본 아이콘(운영자가 `public/favicon.ico` 를 직접 바꿔 두었으면 그것 하나, 아니면 코어 번들 `public/assets/core/favicon/` 의 ico·svg·apple-touch 3종). 첨부 행이 지워졌거나 형식이 목록(ico·png·svg·gif·jpeg·webp) 밖이면 다음 계층으로 내려간다. 지금 어느 계층이 쓰이는지는 조회 응답 `_meta.favicon` 이 알린다. 업로드한 파비콘은 공개 경로 `GET /api/favicon/{hash}`([첨부 API](attachment.md))로만 나간다 — 파비콘 컬렉션 첨부만, 형식별 `Content-Type`·`X-Content-Type-Options: nosniff`·1년 `immutable` 캐시(교체하면 새 첨부라 주소가 바뀜), svg 는 스크립트를 막는 CSP. 오류·점검·DB 접속 오류 화면과 설치 마법사는 DB 를 보지 않으므로 ③ 만 쓴다. 코어 업데이트는 운영자가 넣은 `public/favicon.ico` 를 덮지 않는다([코어 업데이트](../core-update-system.md)). 템플릿 쪽 형식은 [템플릿 환경설정](../../extension/template-settings.md).


### GET /api/admin/settings/privacy-preview
<!-- @generated:start:api.admin.settings.privacy-preview -->
- **라우트명**: `api.admin.settings.privacy-preview`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@privacyPreview`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/settings/privacy-preview HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| retention | object | `{"user-withdrawn": {…}}` | 보유기간 핸들러 식별자 => 상태 객체. 확장이 등록한 핸들러도 같은 객체에 실린다 |
| retention.{id}.enabled | boolean | `false` | 그 핸들러의 기능이 켜져 있는지 |
| retention.{id}.count | integer\|null | `2` | 대상 건수 — **꺼져 있어도 센다**(저장된 기간값 기준). `null` 은 미리보기 실패(로그 참조) |
| retention.{id}.can_view_targets | boolean | `true` | 요청 관리자가 대상 목록(「더보기」)을 볼 권한이 있는지 — 핸들러가 선언한 설정 읽기 + 도메인 읽기 |
| dormant.enabled | boolean | `false` | 휴면 기능이 켜져 있는지 |
| dormant.withdraw_enabled | boolean | `false` | 휴면 자동 탈퇴가 켜져 있는지 |
| dormant.can_view_targets | boolean | `true` | 휴면 대상 목록을 볼 권한(`core.settings.read` + `core.users.read`) |
| dormant.pre_notice | integer | `0` | 사전 안내 대상 건수 |
| dormant.convert | integer | `0` | 휴면 전환 대상 건수 (안내 후 통지 기간 경과분만) |
| dormant.withdraw_notice | integer | `0` | 탈퇴 예정 안내 대상 건수 (자동 탈퇴가 꺼져 있어도 센다) |
| dormant.withdraw | integer | `0` | 탈퇴 처리 대상 건수 (자동 탈퇴가 꺼져 있어도 센다) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "retention": {
            "user-withdrawn": {
                "enabled": false,
                "count": 2,
                "can_view_targets": true
            }
        },
        "dormant": {
            "enabled": false,
            "withdraw_enabled": false,
            "can_view_targets": true,
            "pre_notice": 0,
            "convert": 0,
            "withdraw_notice": 0,
            "withdraw": 0
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.read`)이 없는 경우 |

<!-- @generated:end -->



**설명**

개인정보 보유기간·휴면의 **처리 대상 건수를 미리** 돌려줍니다. 관리자 > 환경설정 > 개인정보 탭의 「대상 N건」 배지가 이 값을 씁니다. 읽기 전용이며 데이터를 바꾸지 않습니다.

건수는 커맨드(`privacy:process-retention --dry-run` · `privacy:process-dormant --dry-run`)와 **같은 조건식**을 지납니다 — 화면에서 본 숫자와 실제 처리 건수가 어긋나면 운영자가 규모를 오판한 채 기능을 켜게 되고, 그 처리는 되돌릴 수 없습니다.

**기능이 꺼져 있어도 건수를 돌려줍니다.** 운영자는 켜기 **전에** 규모와 명단을 확인해야 하기 때문입니다. "꺼짐" 과 "대상 0건" 은 `enabled` 로 구분합니다. 명단은 [개인정보 조치 대상 API](privacy.md)로 한 페이지씩 받으며, `can_view_targets` 가 `false` 면 화면이 「더보기」 버튼을 그리지 않습니다.


### GET /api/admin/settings/password-expiry-preview
<!-- @generated:start:api.admin.settings.password-expiry-preview -->
- **라우트명**: `api.admin.settings.password-expiry-preview`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@passwordExpiryPreview`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.settings.password_expiry_preview_validation_rules`).

**요청 예시**

```http
GET /api/admin/settings/password-expiry-preview HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`PasswordExpiryService::preview()`)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| enabled | boolean | `false` | 비밀번호 만료 기능이 켜져 있는지 (`security.password_expiry_enabled`) |
| started_at | string(ISO8601)\|null | `2026-06-01T03:12:00+00:00` | 만료 기준 시작일(기능을 켠 순간 — 서버 기록). 한 번도 켜지 않았으면 `null` |
| notice | integer | `12` | 사전 안내 대상 수 — **꺼져 있어도 센다**(저장된 기간값 기준) |
| expire | integer | `3` | 만료 처리 대상 수 |
| batch_size | integer | `1000` | 1회 실행당 처리 상한 (0 = 무제한) |
| can_view_targets | boolean | `true` | 요청 관리자가 명단(「대상 보기」)을 볼 권한이 있는지 — `core.settings.read` + `core.users.read`, 둘 다 전체 범위 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "enabled": true,
        "started_at": "2026-06-01T03:12:00+00:00",
        "notice": 12,
        "expire": 3,
        "batch_size": 1000,
        "can_view_targets": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**

비밀번호 만료 처리(`auth:process-password-expiry`)의 **사전 안내·만료 처리 대상 수를 미리** 돌려줍니다 (#736). 관리자 > 환경설정 > 보안 탭 「비밀번호 만료」 카드의 대상 배지가 이 값을 씁니다. 읽기 전용입니다.

건수는 배치·명단(`GET /api/admin/privacy/password-expiry-targets/{stage}`)·직권 실행과 **같은 쿼리**를 지납니다 — 활성 회원, 관리자 지정 변경 대기자 제외, 적용 범위(회원 개별 > 역할 > 사이트 범위), 날짜 경계(기준일 = max(마지막 변경일 ?? 시작일, 시작일)), 이번 주기 미처리. 기능이 꺼져 있어도 셉니다 — 켜기 전에 규모를 확인하는 자리입니다. `{key}` 와일드카드 라우트보다 먼저 등록돼 그 라우트에 흡수되지 않습니다.


### GET /api/admin/settings/app-key
<!-- @generated:start:api.admin.settings.app-key -->
- **라우트명**: `api.admin.settings.app-key`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@getAppKey`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/settings/app-key HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| app_key | string | `base64:YlAis*************************…` | 현재 애플리케이션 키(`APP_KEY`)를 마스킹한 문자열. 앞부분 일부만 노출하고 나머지는 별표로 가려 전체 원문은 반환하지 않음 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "app_key": "{MASKED}"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.read`)이 없는 경우 |

<!-- @generated:end -->



**설명**

현재 애플리케이션 키(`APP_KEY`)를 마스킹된 형태로 조회합니다. 관리자 화면에서 앱 키 존재/일부만 표시하는 용도이며, 전체 키 원문은 반환하지 않습니다.


### POST /api/admin/settings/backup
<!-- @generated:start:api.admin.settings.backup -->
- **라우트명**: `api.admin.settings.backup`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@backup`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/settings/backup HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| backup_path | string | `backups/backup_2026-07-14_030000.zip` | 생성된 설정 백업 ZIP 의 저장소 상대 경로 (카테고리별 설정 JSON 을 묶은 아카이브). `POST /api/admin/settings/restore` 의 `backup_path` 로 그대로 전달해 복원에 사용 |

**응답 예시**

```json
{
    "success": true,
    "message": "데이터베이스 백업이 성공적으로 시작되었습니다.",
    "data": {
        "backup_path": "backups/backup_2026-07-14_030000.zip"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 서버에 PHP `zip` 확장이 없어 백업을 만들 수 없는 경우 (`settings.errors.zip_extension_required`, 7.1.4) |
| 500 | Internal Server Error | 백업 파일 생성에 실패한 경우 (`settings.backup_failed`) |

<!-- @generated:end -->



**설명**

현재 설정을 백업 파일로 저장합니다. 응답 `data.backup_path` 에 생성된 백업 경로를 반환하며, 이 경로는 이후 `POST /restore` 의 `backup_path` 로 사용할 수 있습니다. 설정 변경 전 스냅샷을 남길 때 사용합니다.


### POST /api/admin/settings/backup-database
<!-- @generated:start:api.admin.settings.backup-database -->
- **라우트명**: `api.admin.settings.backup-database`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@backupDatabase`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/settings/backup-database HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 성공 응답을 반환하지 않습니다. 데이터베이스 백업 기능이 아직 제공되지 않아 항상 501 로 응답합니다._

**응답 예시**

```json
{
    "success": false,
    "message": "데이터베이스 백업 기능은 아직 제공하지 않습니다. 설정 백업은 설정 백업 기능을 이용해 주세요.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 501 | Not Implemented | 항상 (데이터베이스 백업 미제공, `settings.database_backup_unavailable`) |

<!-- @generated:end -->


**설명**

**데이터베이스 백업은 아직 제공하지 않습니다.** 이 엔드포인트는 라우트만 존재하며 호출하면 항상 501(Not Implemented)로 응답합니다. 코어에는 데이터베이스 덤프 수단이 없어 구현된 적이 없으며, 관리자 화면에도 이 기능을 호출하는 지점이 없습니다.

설정 파일 백업이 필요하면 `POST /api/admin/settings/backup` 을 사용하십시오 — 그쪽은 정상 동작하며 생성된 백업 경로를 `data.backup_path` 로 돌려줍니다.


### POST /api/admin/settings/clear-cache
<!-- @generated:start:api.admin.settings.clear-cache -->
- **라우트명**: `api.admin.settings.clear-cache`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@clearCache`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/settings/clear-cache HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만). 컨트롤러가 `ResponseHelper::success('settings.cache_clear_success')` 를 데이터 없이 호출하므로 `data` 는 `null` 입니다._

**응답 예시**

```json
{
    "success": true,
    "message": "캐시가 성공적으로 정리되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 캐시 정리가 수행되지 않은 경우 (`settings.cache_clear_failed`) |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 500 | Internal Server Error | 캐시 정리 중 예외가 발생한 경우 (`settings.cache_clear_error`) |

<!-- @generated:end -->


**설명**

시스템 캐시를 정리합니다. 시스템 정보 캐시를 지원 로케일별로 비운 뒤 `cache:clear`, `route:clear`, `view:clear` 를 실행하고, config 캐시는 비운 직후 즉시 재생성합니다(비워 두면 이후 모든 요청이 config 를 재파싱하므로). 설정/코드 변경 후 오래된 캐시를 초기화할 때 사용합니다.


### POST /api/admin/settings/geoip/update
<!-- @generated:start:api.admin.settings.geoip.update -->
- **라우트명**: `api.admin.settings.geoip.update`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\GeoIpController@update`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/settings/geoip/update HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (GeoIpDatabaseService::updateDatabase() 의 `data`)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| database_path | string | `storage/app/geoip/GeoLite2-City.mmdb` | 갱신된 GeoLite2 DB 파일의 절대 경로 (`config('geoip.database_path')`) |
| file_size_bytes | integer | `60000000` | 다운로드·전개 후 최종 DB 파일 크기 (바이트) |
| elapsed_seconds | number | `12.34` | 다운로드부터 교체 완료까지 소요 시간 (초) |

**응답 예시**

```json
{
    "success": true,
    "message": "settings.geoip.update_success",
    "data": {
        "database_path": "storage/app/geoip/GeoLite2-City.mmdb",
        "file_size_bytes": 60000000,
        "elapsed_seconds": 12.34
    }
}
```

> `message` 는 컨트롤러가 넘기는 `settings.geoip.update_success` 키를 번역한 값입니다. 현재 코어 `lang/{ko,en}/settings.php` 에 `geoip.*` 항목이 정의되어 있지 않아 번역이 없으면 키 문자열이 그대로 내려갑니다 (관리자 화면은 프론트엔드 다국어 키로 별도 문구를 표시).

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | MaxMind 라이선스 키가 설정되지 않은 경우 (`missing_license_key`) |
| 401 | Unauthorized | MaxMind 라이선스 키가 유효하지 않은 경우 (`unauthorized`) |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 500 | Internal Server Error | MaxMind 연결 실패(`connection_failed`) 또는 다운로드·압축 해제 실패 |

<!-- @generated:end -->



**설명**

MaxMind GeoLite2-City DB 를 즉시 재다운로드합니다. GeoIpDatabaseService 에 위임하며 동기(즉시) 실행되므로 웹서버/PHP-FPM 타임아웃(90초 이상)이 필요합니다. 라이선스 키 미설정 시 400, 키가 잘못된 경우 401, 연결 실패/기타 오류 시 500 을 반환합니다. 정기 갱신은 스케줄(`geoip:update`)이 담당하고, 이 엔드포인트는 수동 갱신 트리거입니다.


### POST /api/admin/settings/optimize-system
<!-- @generated:start:api.admin.settings.optimize-system -->
- **라우트명**: `api.admin.settings.optimize-system`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@optimizeSystem`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/settings/optimize-system HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만). 컨트롤러가 `ResponseHelper::success('settings.optimize_success')` 를 데이터 없이 호출하므로 `data` 는 `null` 입니다._

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "시스템이 성공적으로 최적화되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |

<!-- @generated:end -->



**설명**

시스템을 최적화합니다. `config:cache`, `route:cache`, `view:cache` 를 실행해 설정·라우트·뷰 캐시를 생성함으로써 이후 요청의 부팅 비용을 줄입니다. 캐시를 비우는 `clear-cache` 와 반대로, 캐시를 사전 생성하는 프로덕션 성능용 작업입니다.


### POST /api/admin/settings/regenerate-app-key
<!-- @generated:start:api.admin.settings.regenerate-app-key -->
- **라우트명**: `api.admin.settings.regenerate-app-key`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@regenerateAppKey`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| password | body | string | verification_token 이 없으면 예 | — | 최고관리자 비밀번호 — 비밀번호가 없는 관리자가 보내면 값은 쓰지 않고 **428** 본인인증 요구(목적 `sensitive_action`, `return_request` = 이 요청)로 답한다(7.2.0) |
| verification_token | body | string | 아니오 | 최대 128자 | 비밀번호가 없는 관리자(외부 인증 전용)의 대체 확인 — 코어 본인인증(`purpose: sensitive_action`)으로 확인해 받은 토큰(본인 것·1회) (7.2.0) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.settings.regenerate_app_key_validation_rules`). 최고관리자 검사는 라우트 미들웨어(`super.admin` — 회원의 최고관리자 표지 `is_super`)가 한다.

**요청 예시**

```http
POST /api/admin/settings/regenerate-app-key HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "password": "Password123!"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| app_key | string | `base64:97gZH********************************` | 재생성된 애플리케이션 키를 마스킹한 문자열 (앞 12자 + 별표 32개). 전체 원문 키는 반환하지 않으며 `.env` 의 `APP_KEY` 에만 기록됨 |

**응답 예시**

```json
{
    "success": true,
    "message": "어플리케이션 키가 성공적으로 재생성되었습니다.",
    "data": {
        "app_key": "base64:97gZH********************************"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthorized | 본문 `password` 가 요청자 본인의 비밀번호와 일치하지 않는 경우 (`settings.invalid_password`) |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없거나, 최고관리자(`is_super`)가 아닌 관리자인 경우 (`auth.super_admin_required`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 428 | Precondition Required | 비밀번호가 없는 관리자가 `password` 로 확인하려 한 경우 (`error_code`: `identity_verification_required`) — 확인 뒤 `?verification_token=` 을 붙여 같은 요청을 다시 보낸다 (7.2.0) |
| 500 | Internal Server Error | `.env` 기록/config 캐시 재생성 실패 (`settings.app_key_regenerate_failed`) |

<!-- @generated:end -->



**설명**

애플리케이션 키(`APP_KEY`)를 재생성합니다. 라우트 미들웨어 단계에서 최고관리자(`is_super`)만 허용하고, Service 단계에서 요청자 본인의 비밀번호가 일치하는지 다시 확인합니다(불일치 시 401). 성공 시 새 키를 `.env` 의 `APP_KEY` 에 기록하고 config 캐시를 재생성하며, 응답 `data.app_key` 에 새 키를 반환합니다. 앱 키 변경은 기존 암호화 값/서명 무효화를 동반하므로 주의가 필요합니다.


### POST /api/admin/settings/restore
<!-- @generated:start:api.admin.settings.restore -->
- **라우트명**: `api.admin.settings.restore`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@restore`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| backup_path | body | string | 예 | — | 복원할 백업 파일 경로. `POST /api/admin/settings/backup` 응답의 `backup_path` 로 받은 값을 그대로 지정 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.settings.restore_validation_rules`).

**요청 예시**

```http
POST /api/admin/settings/restore HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "backup_path": "예시값"
}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만). 컨트롤러가 `ResponseHelper::success('settings.restore_success')` 를 데이터 없이 호출하므로 `data` 는 `null` 입니다._

**응답 예시**

```json
{
    "success": true,
    "message": "설정이 성공적으로 복원되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 복원이 수행되지 않은 경우 (`settings.restore_failed`) |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 422 | Unprocessable Entity | 서버에 PHP `zip` 확장이 없어 백업을 풀 수 없는 경우 (`settings.errors.zip_extension_required`, 7.1.4) |
| 500 | Internal Server Error | 백업 파일을 읽을 수 없는 등 복원 중 예외 발생 (`settings.restore_error`) |

<!-- @generated:end -->



**설명**

이전에 만든 설정 백업에서 설정을 복원합니다. `backup_path` 로 `POST /backup` 이 반환한 백업 경로를 지정합니다. 복원 성공 시 시스템 설정 캐시를 무효화합니다. 잘못된 설정을 되돌릴 때 사용합니다.


### GET /api/admin/settings/system-info
<!-- @generated:start:api.admin.settings.system-info -->
- **라우트명**: `api.admin.settings.system-info`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@systemInfo`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/settings/system-info HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| os_info | string | `Windows NT 10.0` | 운영체제 종류와 버전 (`php_uname` 산물). probe 차단 시 "알 수 없음" 폴백 |
| web_server | string | `nginx/1.27.3` | 웹서버 소프트웨어 식별 문자열 (`$_SERVER['SERVER_SOFTWARE']`) |
| php_version | string | `8.3.26` | 실행 중인 PHP 버전 (`PHP_VERSION`) |
| mysql_version | string | `Mysql 8.4.3` | 연결된 데이터베이스 서버 종류와 버전 (DB 조회 산물). probe 실패 시 "알 수 없음" 폴백 |
| g7_version | string | `7.1.0` | G7 코어 버전 (`config('app.version')`) |
| g7_release_year | string | `2026` | G7 릴리즈 연도 (`config('app.release_year')`, 저작권 표기 등에 사용) |
| laravel_version | string | `12.62.0` | 프레임워크 Laravel 버전 (`app()->version()`) |
| environment | string | `local` | 현재 실행 환경 (`app()->environment()` — local/production/testing 등) |
| cpu_info | string | `Intel(R) Core(TM) Ultra 5 225H` | CPU 모델명 (OS별 시스템 probe 산물). 수집 실패 시 "알 수 없음" 폴백 |
| memory_usage | object | `{"total":"31.49 GB","used":"24.31 GB","free":"7.18 GB","p…` | 물리 메모리 사용량. total/used/free 는 사람이 읽기 쉬운 단위 문자열, percentage 는 사용률(%). 측정 불가(외부 명령 실행 차단 등으로 수집 경로 없음) 시 total/used/free 는 "알 수 없음", percentage 는 `null` — `0` 은 실측 0% 다 |
| disk_usage | object | `{"total":"474.72 GB","used":"408.15 GB","free":"66.57 GB"…` | 설치 볼륨 디스크 사용량. total/used/free 단위 문자열 + percentage 사용률(%). 측정 불가 시 percentage `null` (memory_usage 와 같은 계약) |
| php_memory_limit | string | `512M` | PHP `memory_limit` ini 값 |
| max_execution_time | string | `36000초` | PHP `max_execution_time` ini 값 (초 단위 접미사 부착) |
| upload_max_filesize | string | `2G` | PHP `upload_max_filesize` ini 값 |
| opcache | object | `{"loaded":true,"enabled":true}` | OPcache 상태 (`OpcacheStatus::probe()`). `loaded` 는 확장 적재 여부, `enabled` 는 `opcache.enable` 지시자 값이며 `ini_get` 이 차단·미정의인 환경에서는 **확인 불가를 뜻하는 `null`** 이 된다 (false 와 구분된다) |
| install_path | string | `/var/www/g7` | 애플리케이션 설치 루트 경로 (`base_path()`) |
| config_path | string | `/var/www/g7/storage/…` | 설정 파일 저장 경로 (`storage/app/settings`) |
| log_path | string | `/var/www/g7/storage/…` | 로그 파일 저장 경로 (`storage/logs`) |
| upload_path | string | `/var/www/g7/storage/…` | 공개 업로드 파일 저장 경로 (`storage/app/public`) |
| php_extensions | object | `{"required":{"ctype":true,"curl":true,…},"optional":{"bcmath":true,…},"requested":{"soap":true},"requested_by":{"soap":[{"type":"plugin",…}]}}` | PHP 확장 로드 상태. `required`(필수 16)·`optional`(선택)은 코어 SSoT(`app/Support/Requirements/php-extensions.json`)의 목록이며 각 확장명→로드 여부(bool). `requested_by` 는 설치된 모듈·플러그인이 composer.json 으로 요구한 확장 → 요구한 확장 목록(`type`·`identifier`·`name`·`level`: require\|suggest), `requested` 는 그중 코어 목록 밖 확장(soap 등)의 로드 여부 (7.1.4) |
| database_config | object | `{"has_read_write_split":false,"write":{"host":"localhost"…` | DB 연결 구성 요약. has_read_write_split(읽기/쓰기 분리 여부)·write(쓰기 연결 정보)·read(읽기 replica 목록, write 와 동일하면 제외) |
| timezone | string | `UTC` | 애플리케이션 기본 타임존 (`config('app.timezone')`) |
| server_time | string | `2026-08-04 12:53:55` | 서버 현재 시각 (Y-m-d H:i:s) |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "os_info": "Windows NT 10.0",
        "web_server": "nginx/1.27.3",
        "php_version": "8.3.26",
        "mysql_version": "Mysql 8.4.3",
        "g7_version": "7.1.0",
        "g7_release_year": "2026",
        "laravel_version": "12.62.0",
        "environment": "production",
        "cpu_info": "Intel(R) Core(TM) Ultra 5 225H",
        "memory_usage": {
            "total": "31.49 GB",
            "used": "28.24 GB",
            "free": "3.25 GB",
            "percentage": 89.69
        },
        "disk_usage": {
            "total": "474.72 GB",
            "used": "375.43 GB",
            "free": "99.28 GB",
            "percentage": 79.09
        },
        "php_memory_limit": "512M",
        "max_execution_time": "36000초",
        "upload_max_filesize": "2G",
        "opcache": {
            "loaded": true,
            "enabled": true
        },
        "install_path": "/var/www/g7",
        "config_path": "/var/www/g7/storage/app/settings",
        "log_path": "/var/www/g7/storage/logs",
        "upload_path": "/var/www/g7/storage/app/public",
        "php_extensions": {
            "required": {
                "openssl": true,
                "pdo": true,
                "mbstring": true,
                "tokenizer": "{MASKED}",
                "xml": true,
                "curl": true,
                "json": true,
                "zip": true,
                "fileinfo": true,
                "bcmath": true
            },
            "optional": {
                "gd": true,
                "imagick": false,
                "redis": true,
                "memcached": false,
                "sodium": true,
                "exif": true,
                "intl": true,
                "ldap": false,
                "zlib": true
            }
        },
        "database_config": {
            "has_read_write_split": false,
            "write": {
                "host": "localhost",
                "port": 3306,
                "database": "g7",
                "username": "g7"
            },
            "read": []
        },
        "timezone": "UTC",
        "server_time": "2026-08-13 05:00:24"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.read`)이 없는 경우 |

<!-- @generated:end -->



**설명**

서버 실행 환경 정보를 한 번에 조회합니다. OS/웹서버/PHP/DB/Laravel/코어 버전, CPU·메모리·디스크 사용량, PHP 주요 설정값(memory_limit·max_execution_time·upload_max_filesize), 주요 경로, PHP 확장 로드 상태, DB 연결 구성 요약 등을 포함합니다. 관리자 시스템 정보 화면과 요구사항 점검용 진단 데이터로 사용됩니다.


### GET /api/admin/settings/static-cache
<!-- @generated:start:api.admin.settings.static-cache -->
- **라우트명**: `api.admin.settings.static-cache`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@staticCacheStatus`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/settings/static-cache HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| enabled | boolean | `true` | kill-switch(`G7_STATIC_CACHE`) 상태. `false` 면 게시하지 않고 모든 화면이 API 로 동작한다 |
| publishable | boolean | `true` | 지금 이 설치에서 게시가 실제로 쓰이는지 — `enabled` 이고 운영 모드(`APP_ENV=production`)일 때만 `true`. 화면의 [지금 다시 만들기] 활성 조건 |
| environment | string | `production` | 현재 앱 환경 |
| version | integer | `1788656566` | 현재 확장 캐시 버전 (정적 게시 디렉토리 이름). 키가 없으면 새로 만들어 돌려주므로 항상 유효값 |
| published | boolean | `true` | 현재 버전의 게시 완료(manifest 존재) 여부 |
| files | integer | `714` | manifest 에 기록된 게시 파일 수 (미게시면 0) |
| published_at | string\|null | `2026-09-06T01:02:49+00:00` | 마지막 게시 시각 (ISO 8601, 미게시면 `null`) |
| tree_writable | boolean | `true` | 게시 트리(`public/build/ext`)에 쓸 수 있는지 — 없으면 부모에 만들 수 있는지 |
| process_user | string | `www-data` | 웹 프로세스 계정명 (posix 미지원 환경은 `unknown`). 게시 산출물의 소유자가 될 계정 |
| failure | object\|null | `null` | 최근 게시 실패 마커 `{version, at, reason, count, message}`. `reason` 은 `parent_not_writable` / `write_failed` / `lock_unavailable` |
| retained_versions | array | `[1788171201,1788656566]` | 게시 루트에 남아 있는 버전 디렉토리 목록 (오름차순). 정상은 현재 + 직전 1개 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "초기 화면 정적 파일 상태를 불러왔습니다.",
    "data": {
        "enabled": true,
        "publishable": true,
        "environment": "production",
        "version": 1788656566,
        "published": true,
        "files": 714,
        "published_at": "2026-09-06T01:02:49+00:00",
        "tree_writable": true,
        "process_user": "www-data",
        "failure": null,
        "retained_versions": [
            1788171201,
            1788656566
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**

부트스트랩 리소스 정적 게시(초기 화면 정적 파일)의 상태를 돌려줍니다. 판정은 서비스 한 곳이 소유하며 CLI `php artisan ext-static:status` 와 관리자 > 환경설정 > 일반 「초기 화면 정적 파일」 카드가 같은 값을 소비합니다. 읽기 전용이며 입력이 없습니다.

`publishable` 이 `false` 이면 게시가 쓰이지 않는 환경(kill-switch 꺼짐 또는 비프로덕션)이라 화면은 [지금 다시 만들기] 를 비활성으로 두고 그 이유를 안내합니다. `failure` 는 게시가 거듭 실패했을 때만 채워지며, 대시보드 알림과 같은 마커를 읽습니다. 상세: [static-asset-publishing.md](../static-asset-publishing.md) "5-1. 관리자 화면에서의 수동 복구".


### GET /api/admin/settings/queue-status
<!-- @generated:start:api.admin.settings.queue-status -->
- **라우트명**: `api.admin.settings.queue-status`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@queueStatus`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/settings/queue-status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| driver | string | `database` | 저장된 큐 드라이버(기본 연결) |
| measured_at | string | `2026-10-12T16:41:55+00:00` | 측정 시각 (ISO 8601) |
| heartbeat_stale_after_minutes | integer | `5` | 이 시간 넘게 워커 신호가 없으면 경고 (`queue.worker_registry.heartbeat_stale_seconds` ÷ 60) |
| rows | array | `[{"connection":"database","queue":"default",…}]` | 등록된 연결·큐 쌍마다 1행. 드라이버가 sync 면 빈 배열 |
| rows[].connection | string | `database` | 연결 이름 |
| rows[].queue | string | `default` | 큐 이름 |
| rows[].driver | string | `database` | 연결의 드라이버 |
| rows[].pending | integer\|null | `0` | 대기 건수 — 셀 수 없는 드라이버(redis 등)는 `null` |
| rows[].pending_measurable | boolean | `true` | 대기 건수를 셀 수 있는지 (database 드라이버만 true) |
| rows[].last_heartbeat_at | string\|null | `null` | 마지막 워커 신호 시각 — 이 서버의 기본 캐시 기준(다중 서버 파일 캐시면 다른 서버 워커는 보이지 않는다) |
| rows[].warnings | string[] | `["no_heartbeat"]` | `no_heartbeat`(신호가 없거나 오래됨) · `pending_without_worker`(대기가 있는데 신호 없음) — 둘 중 하나 |
| rows[].clamped_timeout_count | integer | `0` | 이 연결·큐로 디스패치된 리스너의 timeout 을 retry_after 아래로 줄인 횟수(24시간 보관) |
| rows[].long_queue_hint | boolean | `false` | 긴 작업 큐(`long`)를 retry_after 가 짧은 일반 연결에 등록했으면 true — `*-long` 연결로 띄우라는 안내 |
| long_worker | object | `{"connection":"database-long","queue":"long","status":"registered"}` | 긴 작업 전용 워커 등록 단계 — `registered`(연결·큐 둘 다) · `partial`(연결만) · `not_registered` |
| commands | array | `[{"connection":"database","queues":"default,notifications","command":"php artisan queue:work database --queue=default,notifications --sleep=3 --tries=3 --max-time=3600"}]` | 등록 연결별 Supervisor 실행 명령. 긴 작업 연결은 `--timeout=3600` 이 붙는다 |
| placement | object | `{"timeout_clamped":{"count":0,"last":null},"fallback":{"count":0,"last":null}}` | 리스너 timeout 축소·미등록 연결/큐 폴백의 누적 횟수와 마지막 사례(마지막 발생 뒤 24시간 보관). 관리자 화면은 `fallback.count` 가 1 이상이면 「큐 워커」 구역에 경고 안내와 `fallback.last`(리스너·요청 연결·요청 큐)를 그린다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "큐 워커 상태를 불러왔습니다.",
    "data": {
        "driver": "database",
        "measured_at": "2026-10-12T16:41:55+00:00",
        "heartbeat_stale_after_minutes": 5,
        "rows": [
            {
                "connection": "database",
                "queue": "default",
                "driver": "database",
                "pending": 0,
                "pending_measurable": true,
                "last_heartbeat_at": null,
                "warnings": [
                    "no_heartbeat"
                ],
                "clamped_timeout_count": 0,
                "long_queue_hint": false
            }
        ],
        "long_worker": {
            "connection": "database-long",
            "queue": "long",
            "status": "not_registered"
        },
        "commands": [
            {
                "connection": "database",
                "queues": "default",
                "command": "php artisan queue:work database --queue=default --sleep=3 --tries=3 --max-time=3600"
            }
        ],
        "placement": {
            "timeout_clamped": {
                "count": 0,
                "last": null
            },
            "fallback": {
                "count": 0,
                "last": null
            }
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**

환경설정 → 드라이버 탭 「큐 워커」 구역이 읽는 상태입니다(#139). 판정은 **운영자가 등록한 연결·큐 목록**으로 하고, 워커 생존 신호(하트비트)는 큐별로 표시만 합니다. 신호는 워커 루프(`Illuminate\Queue\Events\Looping`)와 잡 시작(`JobProcessing` — `--once` 워커용)마다 프로세스당 30초에 한 번 캐시(`queue:heartbeat:{connection}:{queue}`)에 남습니다. 코어 잡 전용 큐(사이트맵 생성 `sitemap`)는 운영자가 등록하지 않아도 큐 드라이버 연결에 잡이 쌓이므로, 등록 목록에 없으면 `rows` 와 `commands` 에 `dedicated: true` 항목으로 함께 싣습니다(워커 1개 전용 명령, `--timeout=1800`). 등록 큐 항목은 `dedicated: false` 입니다. 읽기 전용이며 입력이 없습니다. 등록 규칙은 위 `POST /api/admin/settings` 의 「큐 워커 등록 검증」, 리스너가 큐를 고르는 방법은 [hooks.md](../../extension/hooks.md) 「큐 옵션 — 등록 큐만 허용·폴백·timeout 클램프」 를 보세요.


### POST /api/admin/settings/static-cache/republish
<!-- @generated:start:api.admin.settings.static-cache.republish -->
- **라우트명**: `api.admin.settings.static-cache.republish`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@republishStaticCache`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/settings/static-cache/republish HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 — 아래 4개 + `GET /api/admin/settings/static-cache` 의 상태 보고서 필드 전부._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| republished | boolean | `true` | 새 버전 게시가 완료됐는지. `false` 여도 HTTP 200 으로 응답한다 — 게시 실패는 요청 처리 실패가 아니라 진단 결과다 (사이트는 API 폴백으로 정상) |
| version_changed | boolean | `true` | 캐시 버전이 이전과 달라졌는지. 같은 초에 연속 호출하면 `false` 일 수 있으나 내용은 강제로 다시 만들어진다 |
| previous_version | integer | `1788656566` | 호출 전 캐시 버전 |
| reason | string\|null | `null` | `republished=false` 일 때의 사유. `disabled`(kill-switch 꺼짐) / `not_production`(비프로덕션 — 이 둘은 버전을 올리지 않는다) / 실패 마커 사유(`parent_not_writable` · `write_failed` · `lock_unavailable`) / `publish_failed` |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "초기 화면 정적 파일을 다시 만들었습니다.",
    "data": {
        "republished": true,
        "version_changed": true,
        "previous_version": 1788656566,
        "reason": null,
        "enabled": true,
        "publishable": true,
        "environment": "production",
        "version": 1788657022,
        "published": true,
        "files": 714,
        "published_at": "2026-09-06T01:10:22+00:00",
        "tree_writable": true,
        "process_user": "www-data",
        "failure": null,
        "retained_versions": [
            1788656566,
            1788657022
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |

<!-- @generated:end -->

**설명**

초기 화면 정적 파일을 지금 다시 만듭니다(관리자 수동 복구). 확장 캐시 버전을 올리고 현재 버전을 **강제** 재게시하므로, 같은 초에 다시 호출해도 내용을 새로 만듭니다. 캐시 버전은 만료로 재생성되지 않으므로(영구 번호) 재게시 트리거가 빠진 상태의 안전망이 이 엔드포인트입니다 — 관리자 > 환경설정 > 일반 「초기 화면 정적 파일」의 [지금 다시 만들기] 와 대시보드 「초기 화면 파일 생성 실패」 알림의 [다시 만들기] 가 호출합니다.

게시가 쓰이지 않는 환경(`publishable=false`)에서는 버전을 올리지 않고 `republished=false` + `reason` 으로 돌아옵니다. 게시 실패도 HTTP 200 으로 돌아오며(연결 테스트·드라이버 테스트와 같은 규약) 실패 마커가 `failure` 에 실립니다.

715파일·40MB 복사와 확장 번들 재병합이 요청 안에서 동기로 수행됩니다. 서버는 실행 시간을 게시 락 TTL(300초)만큼 확보하고 클라이언트가 끊어도 게시를 끝내지만, FPM·nginx 타임아웃이 그보다 짧으면 응답이 먼저 끊길 수 있습니다 — 그 경우에도 게시는 계속되므로 상태 조회로 결과를 확인합니다.


### GET /api/admin/settings/trusted-proxy
<!-- @generated:start:api.admin.settings.trusted-proxy -->
- **라우트명**: `api.admin.settings.trusted-proxy`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@trustedProxy`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/settings/trusted-proxy HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| forwarded_headers | array | `["X-Forwarded-For","X-Forwarded-Proto"]` | 이 요청이 수신 중인 `X-Forwarded-*` 계열 헤더 이름 목록. 비어 있으면 앞단에 프록시가 없는 직접 노출 구성이다 |
| trusted_configured | boolean | `false` | `TRUSTED_PROXIES` 가 지정되어 있는지. 빈 문자열은 미설정과 같게 판정된다 |
| configured_proxies | string\|null | `null` | 지정된 신뢰 프록시 값 (미설정이면 `null`) |
| is_secure | boolean\|null | `false` | 요청이 HTTPS 로 인식되었는지. 요청이 없는 맥락에서는 `null` |
| client_ip | string\|null | `10.0.0.5` | 방문자 IP 로 인식된 값. 신뢰 프록시가 없으면 프록시 자신의 주소가 된다 |
| remote_addr | string\|null | `10.0.0.5` | 직전 호출 IP(`REMOTE_ADDR`). `client_ip` 와 같으면서 `forwarded_headers` 가 비어 있지 않으면 모든 방문자가 한 사람으로 기록되고 있는 상태다 |
| status | string | `warning` | 진단 결과. `warning`(프록시 헤더 수신 중 + 신뢰 프록시 미설정) / `ok` / `not_applicable`(요청이 없는 맥락) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "forwarded_headers": [
            "X-Forwarded-For",
            "X-Forwarded-Proto"
        ],
        "trusted_configured": false,
        "configured_proxies": null,
        "is_secure": false,
        "client_ip": "10.0.0.5",
        "remote_addr": "10.0.0.5",
        "status": "warning"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**

리버스 프록시 뒤에서 구동 중인지, 그리고 신뢰할 프록시가 지정되어 있는지를 진단합니다. 이 엔드포인트는 **읽기 전용**입니다 — 값 저장은 설정 저장 엔드포인트의 `advanced.trusted_proxies` 가 맡고, 저장 전 확인은 [POST /api/admin/settings/test-trusted-proxy](#post-apiadminsettingstest-trusted-proxy) 가 맡습니다.

`configured_proxies` 는 **실제로 적용 중인 값**입니다. 저장값이 있으면 그것이, 없으면 `.env` 의 `TRUSTED_PROXIES` 가 그 자리에 옵니다 — 저장한 적 없는 설치에서 `.env` 설정이 조용히 사문화되지 않도록 한 것입니다.

판정식은 "HTTPS 인식 실패" 가 아니라 `forwarded_headers 가 비어 있지 않음 AND trusted_configured 가 거짓` 입니다. HTTP 전용 사이트가 프록시 뒤에 있는 구성에서는 혼합 콘텐츠 차단이 없어 화면이 정상으로 보이지만, 방문자 IP 기록·통보 IP 화이트리스트·로그인 시도 제한은 그대로 어긋나기 때문입니다.

같은 판정을 관리자 대시보드 알림, 환경설정 > 고급 화면, 설치 마법사, `php artisan trusted-proxy:status` 가 공유합니다. 설정 방법과 도입 시 후속 조치는 [reverse-proxy.md](../reverse-proxy.md) 를 참고하세요.


### GET /api/admin/settings/system/cron-token
<!-- @generated:start:api.admin.settings.system.cron-token -->
- **라우트명**: `api.admin.settings.system.cron-token`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SystemExecutionController@cronToken`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/settings/system/cron-token HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| url | string | `https://example.com/api/system/cron/run?…` | 호출 번호가 실린 절대 호출 주소 (하위 폴더 마운트 접두사·오리진 포함). 문서 예시의 번호는 마스킹된다 |
| header_name | string | `X-G7-Cron-Token` | 번호를 헤더로 실을 때 쓰는 헤더 이름 |
| issued_at | string | `2026-08-31T05:00:28+00:00` | issued 일시 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "호출 주소를 조회했습니다.",
    "data": {
        "url": "https://api.example.com/api/system/cron/run?token={MASKED}",
        "header_name": "X-G7-Cron-Token",
        "issued_at": "2026-08-31T05:00:28+00:00"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |

<!-- @generated:end -->



**설명**

웹 크론 호출 주소를 돌려주는 **전용** 경로다. 그 주소에는 인증 없이 예약 작업을 실행할 수 있는 호출 번호가 들어 있으므로, 일반 설정 조회(`GET /api/admin/settings`)·단건 조회(`GET /api/admin/settings/{key}`)·상태 조회(`GET .../system/status`) 어디에도 실리지 않는다. 이 경로가 유일한 통로다.

**권한이 `core.settings.read` 가 아니라 `core.settings.update` 인 이유** — 이 값은 자격증명이다. 설정을 읽을 수만 있는 계정이 그것을 얻으면 등급 구분이 사라진다. 관리자 화면의 [보기]·[복사] 버튼도 같은 기준으로 수정 권한이 있을 때만 노출된다.

번호가 아직 발급되지 않았으면 **이 호출 시점에 발급**한다. 운영자가 [보기] 를 눌렀다는 것은 그 주소를 쓰겠다는 뜻이고, 발급 버튼을 따로 두면 빈 값만 보이기 때문이다.

| `data` 필드 | 타입 | 설명 |
| --- | --- | --- |
| url | string | 번호가 실린 절대 호출 주소. 하위 폴더 마운트의 접두사와 오리진이 붙어 있어 호스팅 제어판에 그대로 붙여넣을 수 있다 |
| header_name | string | 번호를 헤더로 실을 때 쓰는 헤더 이름 (`X-G7-Cron-Token`) |
| issued_at | string\|null | 번호 발급 시각 (ISO 8601) |

응답 예시의 번호는 문서 생성 시 마스킹된다.


### POST /api/admin/settings/system/cron-token/rotate
<!-- @generated:start:api.admin.settings.system.cron-token.rotate -->
- **라우트명**: `api.admin.settings.system.cron-token.rotate`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SystemExecutionController@rotateCronToken`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/settings/system/cron-token/rotate HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| url | string | `https://example.com/api/system/cron/run?…` | 호출 번호가 실린 절대 호출 주소 (하위 폴더 마운트 접두사·오리진 포함). 문서 예시의 번호는 마스킹된다 |
| header_name | string | `X-G7-Cron-Token` | 번호를 헤더로 실을 때 쓰는 헤더 이름 |
| issued_at | string | `2026-08-31T05:01:13+00:00` | issued 일시 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "호출 번호를 재발급했습니다. 기존 주소는 즉시 무효가 됩니다.",
    "data": {
        "url": "https://api.example.com/api/system/cron/run?token={MASKED}",
        "header_name": "X-G7-Cron-Token",
        "issued_at": "2026-08-31T05:01:13+00:00"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |

<!-- @generated:end -->



**설명**

호출 번호를 새로 발급한다. **기존 주소는 즉시 무효**가 되므로, 호스팅 제어판이나 외부 예약 서비스에 등록해 둔 주소를 바꾸지 않으면 예약 작업이 그 시점부터 실행되지 않는다. 관리자 화면은 이 사실을 확인 모달로 먼저 알린다.

접근 로그에 쿼리스트링이 남는 환경에서 주소가 유출됐다고 판단될 때 쓰는 경로다. 응답 형태는 조회와 같다.


### POST /api/admin/settings/system/cron/run
<!-- @generated:start:api.admin.settings.system.cron.run -->
- **라우트명**: `api.admin.settings.system.cron.run`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SystemExecutionController@runSchedule`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/settings/system/cron/run HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| started_at | string | `2026-08-31T05:01:13+00:00` | started 일시 |
| finished_at | string | `2026-08-31T05:01:16+00:00` | finished 일시 |
| duration_ms | integer | `3179` | 이번 호출의 소요 시간(밀리초) |
| due | integer | `3` | 소급 창 안에서 실행 대상으로 뽑힌 예약 수 |
| executed | integer | `3` | 실제로 실행한 예약 수 |
| failed | integer | `0` | 실패한 예약 수 (커맨드 되뽑기 실패 포함) |
| skipped | integer | `0` | 건너뛴 예약 수 (조건 미충족·중복 실행 방지·다른 서버 담당) |
| truncated | boolean | `false` | 실행 시간 상한에 걸려 남은 예약을 다음 호출로 넘겼는지 여부 |
| events | array | `[{"name":"start \/b cmd \/v:on \/c \"(\"C:\\laragon\\bin\…` | 이벤트 정의 목록 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "예약 작업 실행을 마쳤습니다.",
    "data": {
        "started_at": "2026-08-31T05:01:13+00:00",
        "finished_at": "2026-08-31T05:01:16+00:00",
        "duration_ms": 3179,
        "due": 3,
        "executed": 3,
        "failed": 0,
        "skipped": 0,
        "truncated": false,
        "events": [
            {
                "name": "start /b cmd /v:on /c \"(\"C:\\laragon\\bin\\php\\php-8.3.26-Win32-vs16-x64\\php.exe\" \"artisan\" dashboard:broadcast-resources & \"C:\\laragon\\bin\\php\\php-8.3.26-Win32-vs16-x64\\php.exe\" \"artisan\" schedule:finish \"framework\\schedule-2cc9df99ea408fb2532d26890a921a1250b61d8d\" ^!ERRORLEVEL^!) > \"NUL\" 2>&1\"",
                "expression": "* * * * *",
                "status": "executed"
            },
            {
                "name": "\"C:\\laragon\\bin\\php\\php-8.3.26-Win32-vs16-x64\\php.exe\" \"artisan\" layout-previews:cleanup > \"NUL\" 2>&1",
                "expression": "*/30 * * * *",
                "status": "executed"
            },
            {
                "name": "\"C:\\laragon\\bin\\php\\php-8.3.26-Win32-vs16-x64\\php.exe\" \"artisan\" identity:expire-challenges > \"NUL\" 2>&1",
                "expression": "0 * * * *",
                "status": "executed"
            }
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |

<!-- @generated:end -->



**설명**

관리자 화면의 [지금 실행] 이 부르는 경로다. 공개 웹 크론 주소와 **같은 러너**를 돌리되, 최소 호출 간격은 적용하지 않는다 — 그 제한은 외부의 과다 호출을 막기 위한 것이고, 이 경로는 권한을 확인한 운영자의 명시적 행동이기 때문이다.

응답의 `data` 는 공개 웹 크론과 같은 실행 요약(`due`/`executed`/`failed`/`skipped`/`truncated`/`events`)이다. 필드 뜻은 `GET /api/system/cron/run` 항목을 참조한다. 다만 `started_at`·`finished_at` 은 `GET /api/admin/settings/system/status` 의 `cron.last_run` 과 같이 사이트 시간대로 나간다 — 화면이 이 응답을 「마지막 실행」 줄에 바로 그리므로, 새로고침 전후의 시각이 같아야 한다.

이 경로로 돈 실행은 이력에 `trigger_type=manual` 과 누른 관리자(`triggered_by`)로 남는다. 스케줄 관리에 등록된 작업도 같다 — 실행기가 남기는 행 이력에 같은 계기와 실행자가 실린다. 실행기가 실패로 마감한 작업은 요약의 `failed` 로 센다.


### POST /api/admin/settings/system/detect
<!-- @generated:start:api.admin.settings.system.detect -->
- **라우트명**: `api.admin.settings.system.detect`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SystemExecutionController@detect`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/settings/system/detect HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| detection | object | `{"mode":"subprocess","blocked_functions":[],"php_binary":…` | 환경 감지 결과 — `mode`/`blocked_functions`/`php_binary`/`php_probe`(사유는 `message_localized` 로 번역되어 온다) |
| effective_mode | string | `subprocess` | 실제로 적용되는 실행 모드 (명시 설정 > 감지 순서로 해석한 결과) |
| configured_mode | string | `auto` | 운영자가 저장한 실행 모드 설정값 (`auto` 면 미고정) |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "실행 환경을 다시 감지했습니다.",
    "data": {
        "detection": {
            "mode": "subprocess",
            "blocked_functions": [],
            "php_binary": "php",
            "php_probe": {
                "status": "valid",
                "version": "8.3.26",
                "sapi": "cli",
                "message": "PHP 8.3.26 (최소 요구 버전 8.2.0 충족)",
                "message_key": "php_probe_ok",
                "message_params": {
                    "version": "8.3.26",
                    "min_version": "8.2.0"
                },
                "message_localized": "PHP 8.3.26 확인 (최소 요구 8.2.0)"
            }
        },
        "effective_mode": "subprocess",
        "configured_mode": "auto"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.read`)이 없는 경우 |

<!-- @generated:end -->



**설명**

실행 환경을 그 자리에서 다시 판정한다. 호스팅이 `disable_functions` 나 PHP 실행 파일 배치를 바꾼 뒤 운영자가 저장된 판정을 갱신하는 수단이다.

**저장된 설정을 덮지 않는다.** `system.execution_mode` 가 `auto` 가 아니면 그 고정값이 계속 우선하며, 이 호출은 **근거만** 갱신한다. 응답이 감지 결과와 유효 모드를 함께 실어 화면이 그 차이("자동 판정은 CLI 없음인데 운영자가 CLI 사용으로 고정해 두었다")를 보일 수 있게 한다.

| `data` 필드 | 타입 | 설명 |
| --- | --- | --- |
| detection.mode | string | 환경 감지가 내린 모드 (`subprocess` / `inprocess`) |
| detection.blocked_functions | array | 호스팅이 차단한 실행 함수 목록 (판정 근거) |
| detection.php_binary | string | 판정에 쓴 PHP 실행 파일 경로 |
| detection.php_probe | object | PHP 실행 파일 확인 결과 — `status`/`version`/`sapi`/`message_key`/`message_localized` |
| effective_mode | string | 실제로 적용되는 모드 (명시 설정 > 감지 순서로 해석한 결과) |
| configured_mode | string | 운영자가 저장한 설정값 (`auto` 면 미고정) |

`php_probe.message_localized` 는 서버가 번역해 내려보내는 사유 문구다. 화면은 동적 번역 키에 치환값을 조합할 수단이 없어 그 자리가 비어 버리므로, 번역은 서버에서 끝난다.


### GET /api/admin/settings/system/status
<!-- @generated:start:api.admin.settings.system.status -->
- **라우트명**: `api.admin.settings.system.status`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SystemExecutionController@show`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/settings/system/status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| execution | object | `{"effective_mode":"subprocess","configured_mode":"auto","…` | 실행 방식 상태 — `effective_mode`/`configured_mode`/`detection` |
| cron | object | `{"mode":"auto","min_interval_minutes":5,"visit_fallback_e…` | 예약 작업 상태 — 실행 방식·최소 간격·방문 폴백·번호 발급 여부·마지막 실행 요약·미실행 경고 판정 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "실행 방식 정보를 조회했습니다.",
    "data": {
        "execution": {
            "effective_mode": "subprocess",
            "configured_mode": "auto",
            "detection": {
                "mode": "subprocess",
                "blocked_functions": [],
                "php_binary": "php",
                "php_probe": {
                    "status": "valid",
                    "version": "8.3.26",
                    "sapi": "cli",
                    "message": "PHP 8.3.26 (최소 요구 버전 8.2.0 충족)",
                    "message_key": "php_probe_ok",
                    "message_params": {
                        "version": "8.3.26",
                        "min_version": "8.2.0"
                    },
                    "message_localized": "PHP 8.3.26 확인 (최소 요구 8.2.0)"
                }
            }
        },
        "cron": {
            "mode": "auto",
            "min_interval_minutes": 5,
            "visit_fallback_enabled": false,
            "token_issued": true,
            "token_issued_at": "{MASKED}",
            "last_run": {
                "started_at": "2026-08-31T05:01:13+00:00",
                "finished_at": "2026-08-31T05:01:16+00:00",
                "duration_ms": 3179,
                "due": 3,
                "executed": 3,
                "failed": 0,
                "skipped": 0,
                "truncated": false,
                "events": [
                    {
                        "name": "start /b cmd /v:on /c \"(\"C:\\laragon\\bin\\php\\php-8.3.26-Win32-vs16-x64\\php.exe\" \"artisan\" dashboard:broadcast-resources & \"C:\\laragon\\bin\\php\\php-8.3.26-Win32-vs16-x64\\php.exe\" \"artisan\" schedule:finish \"framework\\schedule-2cc9df99ea408fb2532d26890a921a1250b61d8d\" ^!ERRORLEVEL^!) > \"NUL\" 2>&1\"",
                        "expression": "* * * * *",
                        "status": "executed"
                    },
                    {
                        "name": "\"C:\\laragon\\bin\\php\\php-8.3.26-Win32-vs16-x64\\php.exe\" \"artisan\" layout-previews:cleanup > \"NUL\" 2>&1",
                        "expression": "*/30 * * * *",
                        "status": "executed"
                    },
                    {
                        "name": "\"C:\\laragon\\bin\\php\\php-8.3.26-Win32-vs16-x64\\php.exe\" \"artisan\" identity:expire-challenges > \"NUL\" 2>&1",
                        "expression": "0 * * * *",
                        "status": "executed"
                    }
                ]
            },
            "last_executed_at": "2026-08-31T05:01:16+00:00",
            "stale": false,
            "stale_after_days": 2,
            "recent_history": [
                {
                    "id": 42,
                    "name": "sitemap:generate",
                    "expression": "0 4 * * *",
                    "status": "success",
                    "trigger_type": "scheduled",
                    "started_at": "2026-08-31T04:00:02+00:00",
                    "duration": 3,
                    "error_output": null
                }
            ],
            "state_persist_failed": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.read`)이 없는 경우 |

<!-- @generated:end -->



**설명**

환경설정 「시스템」 탭이 화면을 그리는 데 쓰는 상태다. 실행 방식 카드(현재 모드·판정 근거)와 예약 작업 카드(실행 방식·최소 간격·마지막 실행)가 이 한 응답을 소비한다.

**호출 번호는 싣지 않는다.** 발급되어 있는지(`cron.token_issued`)와 언제 발급됐는지만 알리고, 값 자체는 전용 경로로만 내려간다.

| `data` 필드 | 타입 | 설명 |
| --- | --- | --- |
| execution.effective_mode | string | 실제로 적용되는 실행 모드 |
| execution.configured_mode | string | 운영자가 저장한 설정값 (`auto` 면 미고정) |
| execution.detection | object | 판정 근거 (`POST .../system/detect` 와 같은 형태) |
| cron.mode | string | 예약 작업 실행 방식 (`auto`/`web`/`cron`/`both`) |
| cron.min_interval_minutes | integer | 최소 호출 간격(분). 0 이면 제한 없음 |
| cron.visit_fallback_enabled | boolean | 방문 트리거 폴백 사용 여부 |
| cron.token_issued | boolean | 호출 번호 발급 여부 (**값은 싣지 않는다**) |
| cron.token_issued_at | string\|null | 번호 발급 시각 |
| cron.last_run | object\|null | 마지막 실행 요약 (`finished_at`/`duration_ms`/`due`/`executed`/`failed`/`skipped`/`truncated`) |
| cron.last_executed_at | string\|null | 예약이 마지막으로 **실행된** 시각. 최소 간격에 걸려 되돌아간 호출은 여기에 실리지 않는다 |
| cron.stale | boolean | 미실행 경고 발화 여부 (마지막 실행 이후 `stale_after_days` 경과) |
| cron.stale_after_days | integer | 경고 발화 기준 일수 |
| cron.recent_history | array | 예약 작업의 최근 실행 이력 (시각 역순, 최대 20건). 스케줄 관리에 등록된 작업(실행기가 남긴 행 이력)과 코드로 등록된 예약의 이력을 함께 싣는다. 예전 판 웹 러너가 DB 스케줄 이벤트에 짝으로 남긴 기록(이름 `schedule:{id}`)은 같은 실행의 중복이라 싣지 않는다. `last_run` 은 직전 1회만 담고 다음 호출에 덮이므로 지난 실행은 이 목록으로만 되짚을 수 있다 |
| cron.recent_history[].name | string\|null | 예약 표시명. 스케줄 관리에 등록된 작업은 그 화면과 같은 표시 명칭(지금 언어 → 기본 언어 → 식별자), 코드 예약은 실행 시점 스냅샷 |
| cron.recent_history[].expression | string\|null | 실행 주기 (코드 예약은 실행 시점 스냅샷, 등록된 작업은 지금 주기) |
| cron.recent_history[].status | string\|null | `success` / `failed` / `running`(큐로 보낸 작업이 아직 도는 중) |
| cron.recent_history[].trigger_type | string\|null | `scheduled`(주기 도래) / `manual`(화면의 [지금 실행]·스케줄 관리의 [실행]) |
| cron.recent_history[].started_at | string\|null | 실행 시작 시각 (ISO 8601) |
| cron.recent_history[].duration | integer\|null | 소요 시간(초) |
| cron.recent_history[].error_output | string\|null | 실패 사유 앞 500자 (성공이면 null) |
| cron.state_persist_failed | boolean | 실행 상태 파일 쓰기 실패 여부. `true` 면 점검 시각이 남지 않아 다음 호출의 소급 창이 상한까지 열리고 창 안의 예약이 매 호출 전부 다시 실행된다 |


### POST /api/admin/settings/test-driver
<!-- @generated:start:api.admin.settings.test-driver -->
- **라우트명**: `api.admin.settings.test-driver`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@testDriverConnection`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| storage_driver | body | string | 아니오 | — | 스토리지 드라이버 (local/s3) |
| cache_driver | body | string | 아니오 | — | 캐시 드라이버 (file/redis/memcached) |
| session_driver | body | string | 아니오 | — | 세션 드라이버 (file/database/redis) |
| queue_driver | body | string | 아니오 | — | 큐 드라이버 (sync/database/redis) |
| websocket_enabled | body | boolean | 아니오 | — | WebSocket 사용 여부 |
| s3_bucket | body | string | 아니오 | max 255 | S3 버킷명 |
| s3_region | body | string | 아니오 | max 64 | S3 리전 — AWS 리전 코드 또는 S3 호환 스토리지 값 (Cloudflare R2 는 `auto`) |
| s3_access_key | body | string | 아니오 | max 255 | S3 액세스 키 |
| s3_secret_key | body | string | 아니오 | max 255 | S3 시크릿 키 |
| s3_url | body | string | 아니오 | max 500 | S3 공개 URL(CDN) base — 연결 테스트에는 사용되지 않음 |
| s3_endpoint | body | string | 아니오 | max 500 | S3 API 엔드포인트 — 테스트 시 실제 아웃바운드 대상에 반영 (S3 호환 스토리지용) |
| s3_use_path_style | body | boolean | 아니오 | — | S3 path-style 주소 사용 여부 (MinIO 등) |
| redis_host | body | string | 아니오 | max 255 | Redis 호스트 주소 |
| redis_port | body | integer | 아니오 | min 1, max 65535 | Redis 포트 번호 |
| redis_password | body | string | 아니오 | max 255 | Redis 비밀번호 |
| redis_database | body | integer | 아니오 | min 0, max 15 | Redis 데이터베이스 번호 |
| memcached_host | body | string | 아니오 | max 255 | Memcached 호스트 주소 |
| memcached_port | body | integer | 아니오 | min 1, max 65535 | Memcached 포트 번호 |
| websocket_app_key | body | string | 아니오 | max 255 | WebSocket 앱 키 |
| websocket_host | body | string | 아니오 | max 255 | WebSocket 클라이언트(브라우저 접속) 호스트 주소 |
| websocket_port | body | integer | 아니오 | min 1, max 65535 | WebSocket 클라이언트 포트 번호 |
| websocket_scheme | body | string | 아니오 | — | WebSocket 클라이언트 스킴 (http/https) |
| websocket_server_host | body | string | 아니오 | max 255 | WebSocket 서버(백엔드 발송용) 호스트 주소 — 미입력 시 클라이언트 값으로 폴백 |
| websocket_server_port | body | integer | 아니오 | min 1, max 65535 | WebSocket 서버 포트 번호 — 미입력 시 클라이언트 값으로 폴백 |
| websocket_server_scheme | body | string | 아니오 | — | WebSocket 서버 스킴 (http/https) — 미입력 시 클라이언트 값으로 폴백 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.settings.test_driver_connection_validation_rules`).

> WebSocket 테스트는 클라이언트/서버 양측 endpoint 를 모두 probe 합니다. 백엔드 broadcast 는 서버 endpoint 를 사용하므로, 서버 endpoint 실패 시 별도 메시지(`settings.websocket_server_test_failed`)로 구분 보고됩니다.

> S3 테스트는 Flysystem 어댑터(`league/flysystem-aws-s3-v3`) 존재를 선검사하며, `s3_endpoint`/`s3_use_path_style` 이 실제 아웃바운드 대상에 반영됩니다.

> 사용 불능 드라이버(어댑터 클래스·PHP 확장 부재)는 저장/테스트 모두 422 (`validation.settings.driver_unusable`) 로 거부됩니다.

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.settings.test_driver_connection_validation_rules`).

**요청 예시**

```http
POST /api/admin/settings/test-driver HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "storage_driver": "예시값",
    "cache_driver": "예시값",
    "session_driver": "예시값",
    "queue_driver": "예시값",
    "websocket_enabled": true,
    "s3_bucket": "예시값",
    "s3_region": "예시값",
    "s3_access_key": "예시값",
    "s3_secret_key": "예시값",
    "s3_url": "https://example.com",
    "s3_endpoint": "예시값",
    "s3_use_path_style": true,
    "redis_host": "예시값",
    "redis_port": 1,
    "redis_password": "Password123!",
    "redis_database": 1,
    "memcached_host": "예시값",
    "memcached_port": 1,
    "websocket_app_key": "예시값",
    "websocket_host": "예시값",
    "websocket_port": 1,
    "websocket_scheme": "예시값",
    "websocket_server_host": "예시값",
    "websocket_server_port": 1,
    "websocket_server_scheme": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (DriverConnectionTester::testAll() 산물)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| results | object | `{"redis":{"success":true,"message":"Redis 서버에 성공적으로 연결되었습니다.","latency":"3ms"}}` | 드라이버별 테스트 결과 맵. 키는 실제 테스트가 수행된 드라이버(`s3`/`redis`/`memcached`/`websocket`)만 포함 — 요청 설정에서 해당 드라이버를 쓰지 않으면 키 자체가 없음 |
| results.{driver}.success | boolean | `true` | 해당 드라이버 연결 성공 여부 |
| results.{driver}.message | string | `Redis 서버에 성공적으로 연결되었습니다.` | 결과 메시지 (성공/실패 사유 — 설정 누락·확장 미설치·인증 실패 등) |
| results.{driver}.latency | string | `3ms` | 성공 시에만 포함. 연결 왕복 소요 시간 |
| results.{driver}.error | string | `Connection refused` | 실패 시에만 포함. 원본 예외 메시지 |
| all_passed | boolean | `true` | 수행된 모든 드라이버 테스트가 성공했는지 여부. `false` 여도 HTTP 200 으로 응답하며 message 가 `일부 드라이버 연결 테스트가 실패했습니다.` 로 바뀜 |

**응답 예시**

```json
{
    "success": true,
    "message": "모든 드라이버 연결 테스트가 성공했습니다.",
    "data": {
        "results": {
            "redis": {
                "success": true,
                "message": "Redis 서버에 성공적으로 연결되었습니다.",
                "latency": "3ms"
            }
        },
        "all_passed": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 테스트 실행 중 예외가 발생한 경우 (`settings.driver_test_error`) |

<!-- @generated:end -->



**설명**

폼에 입력한 드라이버 접속 정보(S3·Redis·Memcached·Websocket 등)로 실제 연결을 시도해 결과를 반환합니다. 설정을 저장하기 전에 접속 정보가 유효한지 확인하는 용도입니다. 모든 테스트 통과 시 성공 메시지, 일부 실패 시에도 HTTP 성공 응답으로 항목별 결과(`all_passed=false` 포함)를 함께 반환합니다.


### POST /api/admin/settings/test-mail
<!-- @generated:start:api.admin.settings.test-mail -->
- **라우트명**: `api.admin.settings.test-mail`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@testMail`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| to_email | body | email | 예 | max 255 | 테스트 수신 주소 |
| mailer | body | string | 아니오 | `smtp`, `mailgun`, `ses` | 메일 발송 드라이버 (smtp/mailgun/ses) |
| from_address | body | email | 예 | max 255 | 발신자 주소 |
| from_name | body | string | 예 | max 255 | 발신자 이름 |
| host | body | string | 예 | max 255 | 호스트 주소 |
| port | body | integer | 예 | min 1, max 65535 | 포트 번호 |
| username | body | string | 아니오 | max 255 | 사용자명 (로그인/인증 아이디) |
| password | body | string | 아니오 | max 255 | 비밀번호 |
| encryption | body | string | 아니오 | `tls`, `ssl`, `null` | 전송 암호화 방식 (tls/ssl) |
| mailgun_domain | body | string | 아니오 | max 255 | Mailgun 도메인 |
| mailgun_secret | body | string | 아니오 | max 255 | Mailgun 시크릿 키 |
| mailgun_endpoint | body | string | 아니오 | max 255 | Mailgun 엔드포인트 |
| ses_key | body | string | 아니오 | max 255 | SES 액세스 키 |
| ses_secret | body | string | 아니오 | max 255 | SES 시크릿 키 |
| ses_region | body | string | 아니오 | max 255 | SES 리전 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.settings.test_mail_validation_rules`).

**요청 예시**

```http
POST /api/admin/settings/test-mail HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "to_email": "user@example.com",
    "mailer": "smtp",
    "from_address": "user@example.com",
    "from_name": "예시 이름",
    "host": "예시값",
    "port": 1,
    "username": "예시 이름",
    "password": "Password123!",
    "encryption": "tls",
    "mailgun_domain": "예시값",
    "mailgun_secret": "예시값",
    "mailgun_endpoint": "예시값",
    "ses_key": "예시값",
    "ses_secret": "예시값",
    "ses_region": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (발송 성공 시에만 반환)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| subject | string | `그누보드7 테스트 메일` | 실제로 발송한 테스트 메일의 제목 (`settings.test_mail_subject` — 앱 이름이 치환됨) |
| body | string | `이것은 그누보드7에서 발송한 테스트 메일입니다. 이 메일을 받으셨다면 메일 설정이 올바르게 구성되어 있습니다.` | 실제로 발송한 테스트 메일의 본문 (`settings.test_mail_body`, 평문) |

**응답 예시**

```json
{
    "success": true,
    "message": "테스트 메일이 성공적으로 발송되었습니다.",
    "data": {
        "subject": "그누보드7 테스트 메일",
        "body": "이것은 그누보드7에서 발송한 테스트 메일입니다. 이 메일을 받으셨다면 메일 설정이 올바르게 구성되어 있습니다."
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 발송 실패 시 — `message` 는 `테스트 메일 발송에 실패했습니다.`, `error` 에 원본 예외 메시지(SMTP 인증 실패·연결 거부 등)가 담김 |

<!-- @generated:end -->



**설명**

폼에 입력한 메일 설정으로 지정한 주소에 테스트 메일을 발송합니다. 요청에서 전달한 값(호스트·포트·인증 정보 등)을 저장된 메일 설정 위에 임시로 덮어써 그 값으로만 발송을 시도하므로, 설정을 저장하기 전에 실제 발송 가능 여부를 검증할 수 있습니다. 성공 시 발송한 제목/본문을 응답에 포함하고, 실패 시 오류 사유와 함께 500 을 반환합니다.


### POST /api/admin/settings/test-outbound-proxy
<!-- @generated:start:api.admin.settings.test-outbound-proxy -->
- **라우트명**: `api.admin.settings.test-outbound-proxy`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@testOutboundProxy`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| outbound_proxy | body | string | 예 | max 500 | 검사할 프록시 주소 |
| outbound_proxy_bypass | body | array | 아니오 | — | 프록시를 경유하지 않을 호스트 목록 |
| outbound_proxy_bypass.* | body | string | 아니오 | max 255 | 프록시 예외 호스트 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.settings.test_outbound_proxy_validation_rules`).

**요청 예시**

```http
POST /api/admin/settings/test-outbound-proxy HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "outbound_proxy": "예시값",
    "outbound_proxy_bypass": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| success | boolean | `true` | 프록시를 거쳐 외부에 도달했는지 여부. `false` 여도 HTTP 200 으로 응답한다 — 연결 실패는 요청 처리 실패가 아니라 진단 결과다 |
| egress_ip | string|null | `203.0.113.9` | 프록시를 거쳤을 때 상대편에 보이는 출발지 IP. 외부 서비스에 등록할 값이며 실패 시 `null` |
| elapsed_ms | integer | `512` | 검사에 걸린 시간 (밀리초) |
| error | string|null | `cURL error 7: Failed to connect` | 실패 시에만 채워지는 원인 원문 (관리자 진단용) |

**응답 예시**

```json
{
    "success": true,
    "message": "프록시 연결에 성공했습니다. 외부 서비스에는 이 IP 로 보입니다.",
    "data": {
        "success": true,
        "message_key": "settings.outbound_proxy_test_success",
        "egress_ip": "203.0.113.9",
        "elapsed_ms": 512,
        "error": null
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 프록시 주소가 비어 있거나 허용 스킴이 아닌 경우 |

<!-- @generated:end -->


**설명**

입력한 프록시로 외부에 연결해 보고, 성공하면 그 프록시를 거쳤을 때 상대편에 보이는 출발지 IP 를 함께 반환합니다. 이 IP 는 운영자가 결제사·외부 서비스의 허용 목록에 등록해야 하는 값이라, 설정을 저장하기 전에 확인할 수 있도록 제공합니다.

검사 대상은 **이번 요청이 제출한 값**입니다. 저장된 설정이나 전역 프록시 옵션을 보지 않으므로, 저장 전에도 그대로 확인할 수 있고 이 호출이 다른 요청의 경로를 바꾸지도 않습니다.

조회 대상은 `config('core.outbound_proxy.egress_lookup_urls')` 가 소유하며 순차 시도합니다. 목록을 비우면 도달성만 확인하고 IP 는 보고하지 않습니다(폐쇄망 대응).


### POST /api/admin/settings/test-trusted-proxy
<!-- @generated:start:api.admin.settings.test-trusted-proxy -->
- **라우트명**: `api.admin.settings.test-trusted-proxy`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@testTrustedProxy`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| trusted_proxies | body | array | 예 (키 자체는 필수) | 최대 50개 | 검사할 신뢰 프록시 후보 목록. 빈 배열은 「아무 프록시도 신뢰하지 않음」이라는 정상 선택이므로 `required` 가 아니라 `present` 다 |
| trusted_proxies.* | body | string | 아니오 | max 60 | IPv4/IPv6 또는 CIDR, 혹은 직전 호출 IP 만 신뢰하는 `*` |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.settings.test_trusted_proxy_validation_rules`).

**요청 예시**

```http
POST /api/admin/settings/test-trusted-proxy HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "trusted_proxies": [
        "*"
    ]
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| candidate | array | `["*"]` | 정규화된 후보 목록 (빈 토큰·중복 제거, `**` → `*`) |
| mode | string | `nearest` | 후보가 해당하는 화면 모드 — `none` · `nearest` · `list` |
| forwarded_headers | array | `["X-Forwarded-For","X-Forwarded-Proto"]` | 이번 요청이 실제로 수신 중인 전달 헤더 이름 |
| remote_addr | string\|null | `10.0.0.5` | 직전 호출 IP (`REMOTE_ADDR`) |
| current.client_ip | string\|null | `10.0.0.5` | **현재 설정**에서 방문자 IP 로 해석되는 값 |
| current.is_secure | boolean | `false` | 현재 설정에서의 HTTPS 인식 여부 |
| result.client_ip | string\|null | `203.0.113.9` | **후보값**을 적용했을 때 방문자 IP 로 해석되는 값 |
| result.is_secure | boolean | `true` | 후보값 적용 시 HTTPS 인식 여부 |
| result.trusted | array | `["10.0.0.5"]` | 후보값이 실제로 신뢰하게 되는 앞단 주소 목록 (`*` 는 직전 호출 IP 하나로 풀린다) |
| changed | boolean | `true` | 방문자 IP 또는 HTTPS 인식이 달라지는지 여부 |
| verdict | string | `resolves` | 판정 — `no_headers`(앞단 미감지) · `resolves`(체인에서 해석됨) · `unchanged`(그대로) |

**응답 예시**

```json
{
    "success": true,
    "message": "이 값이면 방문자 IP 가 프록시 너머의 실제 주소로 해석됩니다.",
    "data": {
        "candidate": ["*"],
        "mode": "nearest",
        "forwarded_headers": ["X-Forwarded-For", "X-Forwarded-Proto"],
        "remote_addr": "10.0.0.5",
        "current": { "client_ip": "10.0.0.5", "is_secure": false },
        "result": { "client_ip": "203.0.113.9", "is_secure": true, "trusted": ["10.0.0.5"] },
        "changed": true,
        "verdict": "resolves"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | `trusted_proxies` 키가 없거나, 항목이 IP·CIDR·`*` 가 아닌 경우 |

<!-- @generated:end -->


**설명**

저장하지 않고, **이번 요청**에 후보값을 적용했다면 방문자 IP 와 HTTPS 인식이 어떻게 해석될지 돌려줍니다. 이 설정은 틀려도 오류를 내지 않고 방문자 IP 만 조용히 어긋나므로 저장 후에 알아차릴 단서가 없고, HTTPS 종단 구성에서 잘못 저장하면 관리자 화면 자체에 들어가지 못해 되돌릴 통로가 사라집니다. 그 사고를 막는 자리입니다.

판정은 프레임워크의 해석기(`Illuminate\Http\Middleware\TrustProxies`)를 그대로 돌려 얻습니다 — 같은 규칙을 여기서 다시 구현하면 검사 결과와 실제 적용이 갈리고, 그 어긋남은 이 기능이 막으려는 결함과 같은 모양이 됩니다.

검사는 전역 신뢰 상태(`Request::setTrustedProxies()` 는 정적입니다)와 `trustedproxy.proxies` 를 잠시 바꿨다가 반드시 되돌립니다. 호출 전후의 런타임 상태는 같습니다.

`verdict` 가 `unchanged` 면 후보 목록에 앞단 프록시 주소가 빠져 있을 수 있습니다. `no_headers` 는 앞단이 아예 감지되지 않은 상태로, 프록시를 쓰지 않는 직접 노출 구성에서는 정상입니다.


### GET /api/admin/settings/{key}
<!-- @generated:start:api.admin.settings.show -->
- **라우트명**: `api.admin.settings.show`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@show`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| key | path | string | 예 | — | 대상 설정/항목의 키 |

**요청 예시**

```http
GET /api/admin/settings/{key} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| key | string | `general.site_name` | 요청한 설정 키를 그대로 되돌려 준 값 (`{category}.{field}` 형태) |
| value | mixed | `Test Site` | 해당 설정 키의 현재 값. 설정 항목의 자료형에 따라 문자열/정수/불리언/배열이 될 수 있으며, 키가 없으면 `null` |

**응답 예시**

```json
{
    "success": true,
    "message": "설정을 성공적으로 가져왔습니다.",
    "data": {
        "key": "general.site_name",
        "value": "Test Site"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 500 | Internal Server Error | 설정 조회 중 예외가 발생한 경우 (`settings.fetch_failed`) |

<!-- @generated:end -->



**설명**

단일 설정 키의 값을 조회합니다. 응답의 `data.key` 는 요청한 키, `data.value` 는 해당 설정 값입니다. 통합 조회(`GET /api/admin/settings`)와 달리 특정 키 하나만 필요할 때 사용합니다.


### PUT /api/admin/settings/{key}
<!-- @generated:start:api.admin.settings.update -->
- **라우트명**: `api.admin.settings.update`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@update`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| key | path | string | 예 | — | 대상 설정/항목의 키 |
| value | body | string | 아니오 | — | 값 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.settings.update_validation_rules`).

**요청 예시**

```http
PUT /api/admin/settings/{key} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "value": "예시값"
}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만). 컨트롤러가 `ResponseHelper::success('settings.update_success')` 를 데이터 없이 호출하므로 `data` 는 `null` 입니다._

**응답 예시**

```json
{
    "success": true,
    "message": "설정이 성공적으로 업데이트되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 저장이 수행되지 않은 경우 (`settings.update_failed`) |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). 서버가 기록하는 키(`security.password_expiry_started_at`)는 값과 관계없이(null 포함) 422 (`validation.setting.value.server_only`) |
| 500 | Internal Server Error | 저장 중 예외가 발생한 경우 (`settings.update_error`) |

<!-- @generated:end -->



**설명**

단일 설정 키의 값을 업데이트합니다. 경로의 `key` 로 대상 설정을, 본문의 `value` 로 새 값을 지정합니다. 탭 단위 일괄 저장(`POST /api/admin/settings`)과 달리 개별 키 하나만 변경할 때 사용합니다. 검증 실패 시 422, 그 외 오류 시 500 을 반환합니다.

`.env` 가 값을 정하는 키(`env-priority` 가 켜져 있고 그 키의 환경변수가 명시된 경우 — 예: `MAIL_HOST` → `mail.host`)는 값과 관계없이 422 입니다(`validation.setting.value.env_locked`, 7.1.4). 탭 일괄 저장은 그런 키를 조용히 버리지만, 단건 저장은 그 키 하나를 지목한 요청이라 거부합니다. 터미널의 `php artisan settings:set` 도 같은 키를 같은 사유로 거부합니다.

값의 형태가 정해진 키는 탭 일괄 저장과 **같은 표**(`SettingShapeRules`)로 검증합니다. `system.cron_allowed_ips` 는 IP·CIDR 목록 문자열이어야 하고, `debug.trusted_proxies` 는 50개 이하의 배열이며 각 항목이 60자 이하의 IP 또는 CIDR 이어야 합니다(콤마 문자열은 422). 한쪽 경로에만 형태 검증이 있으면 다른 쪽이 우회로가 됩니다.


