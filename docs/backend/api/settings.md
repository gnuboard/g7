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

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Settings 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
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
| general | object | `{"site_name":"그누보드7","site_url":"https:\/\/g7_2.dev","sit…` | 일반 탭 설정 그룹 (사이트명·사이트 URL·설명·관리자 이메일·타임존·기본 언어·통화·점검 모드·사이트 로고 첨부). site_logo 는 SettingsService 가 별도 주입한 첨부 정보 |
| security | object | `{"force_https":false,"login_attempt_enabled":true,"auth_t…` | 보안 탭 설정 그룹 (HTTPS 강제·로그인 시도 제한 사용·인증 토큰 유지시간(분, 0=무한)·최대 로그인 시도 횟수·잠금 시간·내부 네트워크 주소 호출 허용). `allow_internal_outbound_urls`(boolean, 기본 false): 서버가 대신 호출하는 outbound 요청(예약 작업 URL 호출, 외부 API 연동)에서 사설 IP·`localhost` 등 내부 주소를 허용할지 여부 — 켜면 사내 서버 호출이 가능해지지만 서버가 내부망으로 요청을 보낼 수 있게 되므로 기본은 차단입니다. 언어팩 URL 설치는 원격 코드를 내려받으므로 이 설정과 무관하게 항상 내부 주소를 거부합니다 |
| mail | object | `{"mailer":"smtp","host":"","port":587,"username":"","pass…` | 메일 탭 설정 그룹 (메일러 종류(smtp/mailgun/ses)·SMTP 호스트/포트/인증 정보·암호화 방식·발신자 주소/이름·Mailgun/SES 자격 정보) |
| upload | object | `{"max_file_size":10,"allowed_extensions":["jpg","jpeg","p…` | 업로드 탭 설정 그룹 (최대 파일 크기(MB)·허용 확장자 목록·이미지 최대 가로/세로·이미지 품질) |
| seo | object | `{"meta_title_suffix":null,"meta_description":null,"meta_k…` | SEO 탭 설정 그룹 (메타 타이틀 접미사·메타 설명/키워드·검색엔진 인증 코드·봇 감지·OG/Twitter 기본값·SEO 캐시·사이트맵·생성기 설정) |
| advanced | object | `{"cache_enabled":true,"cache_default_ttl":86400,"layout_c…` | 고급 탭 설정 그룹 (캐시·디버그·코어 업데이트·GeoIP 설정을 한 탭으로 합친 병합 뷰). cache/debug 카테고리 값이 함께 노출됨 |
| cache | object | `{"cache_enabled":true,"cache_default_ttl":86400,"layout_c…` | 캐시 원본 카테고리 (전역 캐시 사용·기본 TTL·레이아웃/통계/SEO 캐시 사용 및 TTL). advanced 탭에 병합되면서 개별 접근용으로 별도 노출된 파생 뷰 |
| debug | object | `{"debug_mode":false,"sql_query_log":false,"log_level":"er…` | 디버그 원본 카테고리 (디버그 모드·SQL 쿼리 로그·로그 레벨). advanced 탭에 병합되면서 개별 접근용으로 별도 노출된 파생 뷰 |
| drivers | object | `{"storage_driver":"local","s3_bucket":null,"s3_region":"a…` | 드라이버 탭 설정 그룹 (스토리지/캐시/세션/큐/로그 드라이버 선택 + S3·Redis·Memcached·WebSocket·검색엔진 접속 파라미터) |
| core_update | object | `{"core_update_github_url":"https:\/\/github.com\/gnuboard…` | 코어 업데이트 원본 카테고리 (코어 업데이트를 받아올 GitHub 저장소 URL·비공개 저장소 접근용 토큰). advanced 탭에 병합된 파생 뷰 |
| geoip | object | `{"geoip_enabled":false,"geoip_license_key":null,"geoip_au…` | GeoIP 원본 카테고리 (GeoIP 사용 여부·MaxMind 라이선스 키·DB 자동 갱신 사용). advanced 탭에 병합된 파생 뷰 |
| notifications | object | `{"channels":[{"id":"mail","is_active":true,"sort_order":1…` | 알림 탭 설정 그룹. channels 는 알림 채널 목록으로 각 원소가 id(채널 식별자)·is_active(활성 여부)·sort_order(표시 순서)를 가짐 |
| identity | object | `{"default_provider":"g7:core.mail","purpose_providers":{"…` | 본인인증(IDV) 탭 설정 그룹 (기본 provider·목적별 provider 매핑(purpose_providers)·챌린지 유효시간(분)·최대 시도 횟수) |
| available_drivers | object | `{"storage":[{"id":"local","label":{"ko":"로컬","en":"Local"…` | 드라이버 선택지 카탈로그 (DriverRegistryService 산물). 종류별(storage/public_asset/cache/session/queue 등) 선택 가능한 드라이버 목록을 id/다국어 label 형태로 제공. `public_asset` 은 공개 자산 직접 URL 서빙 디스크 선택지 (코어 none/public/s3 + 플러그인 훅 등록분) |
| _meta | object | `{"limits":{"upload_max_file_size_min":1,"upload_max_file_…` | 설정값이 아니라 화면이 쓰는 메타. `limits` 는 각 설정 항목의 min/max 경계값 맵 (`config/core.php` 의 `settings_limits` 가 SSoT, 화면 입력 힌트와 FormRequest 검증이 같은 값을 공유) |
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
            "admin_email": "heuristing@gmail.com",
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
            "image_quality": 85
        },
        "seo": {
            "meta_title_suffix": "",
            "meta_description": "",
            "meta_keywords": "",
            "google_analytics_id": "",
            "google_site_verification": "",
            "...": "(23개 키 생략, 총 28개)"
        },
        "...": "(12개 키 생략, 총 17개)"
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

관리자 통합 환경설정 화면(`admin_settings.json`)이 사용하는 전체 설정 조회 엔드포인트입니다. 각 탭에 해당하는 설정 그룹(general/security/mail/upload/seo/advanced/drivers/geoip/notifications/identity 등)과 드라이버 선택지 카탈로그(`available_drivers`)를 한 번에 반환합니다. 응답은 Eloquent 모델이 아니라 SettingsService 가 여러 설정 소스를 병합해 만든 집계 배열이며, 일부 그룹(cache/debug 등)은 원본 카테고리 값을 별도 키로 함께 노출한 파생 뷰입니다.


### POST /api/admin/settings
<!-- @generated:start:api.admin.settings.store -->
- **라우트명**: `api.admin.settings.store`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@store`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| _tab | body | string | 아니오 | — | 활성 탭 식별자 (general/mail/upload/seo/security/drivers/advanced/notifications/identity). 지정 시 해당 탭 필드만 필수 검증되고 나머지 탭은 nullable 처리되어 탭 단위 부분 저장을 가능케 함 |
| general | body | array | 아니오 | — | 일반 탭 설정 묶음 (사이트명·URL·설명·관리자 이메일·타임존·기본 언어·통화·점검 모드·사이트 로고) |
| mail | body | array | 아니오 | — | 메일 탭 설정 묶음 (메일러 종류·SMTP 호스트/포트/인증·암호화·발신자 정보·Mailgun/SES 자격 정보) |
| upload | body | array | 아니오 | — | 업로드 탭 설정 묶음 (최대 파일 크기·허용 확장자·이미지 최대 크기 및 품질) |
| seo | body | array | 아니오 | — | SEO 탭 설정 묶음 (메타 태그·검색엔진 인증·봇 감지·OG/Twitter 기본값·SEO 캐시·사이트맵·생성기) |
| security | body | array | 아니오 | — | 보안 탭 설정 묶음 (HTTPS 강제·로그인 시도 제한·인증 토큰 유지시간·최대 시도 횟수·잠금 시간) |
| drivers | body | array | 아니오 | — | 드라이버 탭 설정 묶음 (스토리지/캐시/세션/큐/로그 드라이버 및 S3·Redis·Memcached·WebSocket·검색엔진 접속 정보) |
| advanced | body | array | 아니오 | — | 고급 탭 설정 묶음 (캐시·디버그·코어 업데이트·GeoIP 설정) |
| notifications | body | array | 아니오 | — | 알림 탭 설정 묶음. channels 배열로 각 알림 채널의 id·is_active(활성 여부)·sort_order(표시 순서)를 저장 |
| identity | body | array | 아니오 | — | 본인인증(IDV) 탭 설정 묶음 (기본 provider·목적별 provider 매핑·챌린지 유효시간·최대 시도 횟수) |
| notifications.channels | body | array | 아니오 | — | 알림 채널 목록. 각 원소는 `id`(채널 식별자, 필수·최대 50자)·`is_active`(활성 여부, 필수 boolean)·`sort_order`(표시 순서, 0 이상 정수) 로 구성 |
| general.site_name | body | string | 예 | max 100 | general.site 이름 (식별자) |
| general.site_url | body | string | 예 | max 255 | 사이트 기본 URL |
| general.site_description | body | string | 아니오 | max 500 | 사이트 설명 |
| general.admin_email | body | email | 예 | max 255 | 관리자 이메일 주소 |
| general.timezone | body | string | 예 | — | 타임존 식별자 |
| general.language | body | string | 예 | — | 언어 코드 |
| general.currency | body | string | 아니오 | max 10 | 통화 코드 (ISO 4217 — 예: KRW) |
| general.maintenance_mode | body | boolean | 아니오 | — | 점검 모드 사용 여부 (사이트 접근 차단) |
| general.site_logo | body | array | 아니오 | — | 사이트 로고 이미지 |
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
| seo.og_image_default_width | body | integer | 아니오 | min 0, max 8000 | 기본 Open Graph 이미지 너비 (px) |
| seo.og_image_default_height | body | integer | 아니오 | min 0, max 8000 | 기본 Open Graph 이미지 높이 (px) |
| seo.twitter_default_card | body | string | 아니오 | — | 기본 트위터 카드 유형 (summary 등) |
| seo.twitter_default_site | body | string | 아니오 | max 50 | 기본 트위터 사이트 계정 (@handle) |
| seo.cache_enabled | body | boolean | 아니오 | — | 캐시 사용 여부 |
| seo.cache_ttl | body | integer | 아니오 | min 60, max 86400 | 캐시 유효 시간 (초) |
| seo.sitemap_enabled | body | boolean | 아니오 | — | sitemap.xml 생성 사용 여부 |
| seo.sitemap_cache_ttl | body | integer | 아니오 | min 3600, max 604800 | sitemap 캐시 유효 시간 (초) |
| seo.sitemap_schedule | body | string | 아니오 | — | sitemap 자동 생성 주기 |
| seo.sitemap_schedule_time | body | string | 아니오 | — | sitemap 자동 생성 시각 |
| seo.generator_enabled | body | boolean | 아니오 | — | SEO 페이지 생성기 사용 여부 |
| seo.generator_content | body | string | 아니오 | max 200 | SEO 렌더링 본문 생성 방식 |
| security.force_https | body | boolean | 아니오 | — | HTTPS 강제 리다이렉트 여부 |
| security.login_attempt_enabled | body | boolean | 아니오 | — | 로그인 시도 제한 사용 여부 |
| security.auth_token_lifetime | body | integer | 아니오 | min 0, max 3600 | 인증 토큰 유효 시간 (분) |
| security.max_login_attempts | body | integer | 아니오 | min 0, max 100 | 로그인 실패 허용 횟수 (초과 시 잠금) |
| security.login_lockout_time | body | integer | 아니오 | min 0, max 1440 | 로그인 잠금 지속 시간 (분) |
| advanced.cache_enabled | body | boolean | 아니오 | — | 캐시 사용 여부 |
| advanced.layout_cache_enabled | body | boolean | 아니오 | — | 레이아웃 캐시 사용 여부 (레이아웃 데이터를 캐시) |
| advanced.layout_cache_ttl | body | integer | 아니오 | min 0, max 14400 | 레이아웃 캐시 만료 시간 (초, 0 = 만료 없음) |
| advanced.stats_cache_enabled | body | boolean | 아니오 | — | 통계 캐시 사용 여부 (대시보드 통계를 캐시) |
| advanced.stats_cache_ttl | body | integer | 아니오 | min 0, max 14400 | 통계 캐시 만료 시간 (초, 0 = 만료 없음) |
| advanced.seo_cache_enabled | body | boolean | 아니오 | — | SEO 캐시 사용 여부 (SEO 메타데이터를 캐시) |
| advanced.seo_cache_ttl | body | integer | 아니오 | min 0, max 14400 | SEO 캐시 만료 시간 (초, 0 = 만료 없음) |
| advanced.debug_mode | body | boolean | 아니오 | — | 디버그 모드 사용 여부 (상세 오류 노출) |
| advanced.sql_query_log | body | boolean | 아니오 | — | SQL 쿼리 로그 기록 여부 |
| advanced.outbound_proxy | body | string | 아니오 | max 500, 스킴 `http`/`https`/`socks4`/`socks4a`/`socks5`/`socks5h` | 외부 HTTP 호출이 경유할 프록시 주소 (예: `socks5h://127.0.0.1:1080`). 빈 값이면 사용하지 않으며, 디버그 모드가 꺼져 있으면 저장되어도 적용되지 않는다 |
| advanced.outbound_proxy_bypass | body | array | 아니오 | — | 프록시를 경유하지 않을 호스트 목록 |
| advanced.outbound_proxy_bypass.* | body | string | 아니오 | max 255 | 프록시 예외 호스트 |
| advanced.core_update_github_url | body | string | 아니오 | max 500 | 코어 업데이트를 확인할 GitHub 저장소 URL |
| advanced.core_update_github_token | body | string | 아니오 | max 500 | 프라이빗 저장소의 코어/확장 업데이트에 사용할 GitHub 액세스 토큰 (공개 저장소는 비워둘 수 있음) |
| advanced.geoip_enabled | body | boolean | 아니오 | — | IP 기반 타임존 감지(GeoIP) 사용 여부 |
| advanced.geoip_license_key | body | string | 아니오 | max 200 | MaxMind GeoLite2 라이선스 키 |
| advanced.geoip_auto_update_enabled | body | boolean | 아니오 | — | GeoIP DB 자동 업데이트 사용 여부 (주 1회 자동 재다운로드) |
| drivers.storage_driver | body | string | 아니오 | — | 스토리지 드라이버 (local/s3) |
| drivers.public_asset_disk | body | string | 아니오 | max 100 | 공개 자산 직접 URL 서빙 디스크 (none/public/s3 + 플러그인 훅 등록 디스크). 카탈로그(`available_drivers.public_asset`)에 없는 값은 422 |
| drivers.s3_bucket | body | string | 아니오 | max 255 | S3 버킷명 |
| drivers.s3_region | body | string | 아니오 | max 64, 소문자 영숫자·하이픈 (`^[a-z0-9-]+$`) | S3 리전 — AWS 리전 코드 또는 S3 호환 스토리지 값 (Cloudflare R2 는 `auto`, MinIO 관례는 `us-east-1`) |
| drivers.s3_access_key | body | string | 아니오 | max 255 | S3 액세스 키 |
| drivers.s3_secret_key | body | string | 아니오 | max 255 | S3 시크릿 키 |
| drivers.s3_url | body | string | 아니오 | url, max 500 | S3 공개 URL(CDN) base — 파일 URL 생성에만 사용 (API 요청 주소 아님) |
| drivers.s3_endpoint | body | string | 아니오 | url, max 500 | S3 API 엔드포인트 — S3 호환 스토리지(R2/MinIO/NCP 등)용. AWS S3 는 미입력 (예: `https://<account-id>.r2.cloudflarestorage.com`) |
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
| drivers.log_driver | body | string | 아니오 | — | 로그 드라이버 (single/daily/stack) |
| drivers.log_level | body | string | 아니오 | — | 로그 레벨 (debug/info/warning/error 등) |
| drivers.log_days | body | integer | 아니오 | min 1, max 365 | 로그 파일 보관 일수 |
| identity.default_provider | body | string | 아니오 | max 100 | 본인인증 기본 프로바이더 (목적별로 지정되지 않은 경우 사용). 예: `g7:core.mail` |
| identity.purpose_providers | body | array | 아니오 | — | 본인인증 목적(Purpose)별 프로바이더 매핑. 미지정 목적은 기본 프로바이더를 사용 |
| identity.challenge_ttl_minutes | body | integer | 아니오 | min 1, max 1440 | 발급된 인증 코드/링크(challenge)의 유효시간 (분) |
| identity.max_attempts | body | integer | 아니오 | min 1, max 20 | challenge 최대 시도 횟수 (연속 실패 시 잠금 — 재전송 필요) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.settings.save_validation_rules`, `core.search.engine_drivers`).

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
    "seo.sitemap_schedule": "예시값",
    "seo.sitemap_schedule_time": "예시값",
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
    "drivers.log_driver": "예시값",
    "drivers.log_level": "예시값",
    "drivers.log_days": 1,
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

통합 환경설정 화면에서 한 탭의 설정을 일괄 저장합니다. `_tab` 으로 활성 탭을 지정하면 해당 탭의 필드만 필수 검증되고 다른 탭 필드는 nullable 로 처리되므로, 탭 단위로 부분 저장할 수 있습니다. 저장 성공 시 응답 `data.settings` 에 갱신된 전체 설정과 `available_drivers` 를 함께 반환하여, 프론트엔드가 새로고침 없이 전역 상태를 갱신할 수 있습니다. 검증 실패 시 422, 그 외 오류 시 500 을 반환합니다.


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
| password | body | string | 예 | — | 비밀번호 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.settings.regenerate_app_key_validation_rules`).

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
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없거나, FormRequest 가 `super_admin` 역할이 아닌 사용자를 거부한 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | `.env` 기록/config 캐시 재생성 실패 (`settings.app_key_regenerate_failed`) |

<!-- @generated:end -->

**설명**

애플리케이션 키(`APP_KEY`)를 재생성합니다. FormRequest 단계에서 `super_admin` 역할만 허용하고, Service 단계에서 요청자 본인의 비밀번호가 일치하는지 다시 확인합니다(불일치 시 401). 성공 시 새 키를 `.env` 의 `APP_KEY` 에 기록하고 config 캐시를 재생성하며, 응답 `data.app_key` 에 새 키를 반환합니다. 앱 키 변경은 기존 암호화 값/서명 무효화를 동반하므로 주의가 필요합니다.


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
| g7_version | string | `7.0.6` | G7 코어 버전 (`config('app.version')`) |
| g7_release_year | string | `2026` | G7 릴리즈 연도 (`config('app.release_year')`, 저작권 표기 등에 사용) |
| laravel_version | string | `12.62.0` | 프레임워크 Laravel 버전 (`app()->version()`) |
| environment | string | `local` | 현재 실행 환경 (`app()->environment()` — local/production/testing 등) |
| cpu_info | string | `Intel(R) Core(TM) Ultra 5 225H` | CPU 모델명 (OS별 시스템 probe 산물). 수집 실패 시 "알 수 없음" 폴백 |
| memory_usage | object | `{"total":"31.49 GB","used":"24.31 GB","free":"7.18 GB","p…` | 물리 메모리 사용량. total/used/free 는 사람이 읽기 쉬운 단위 문자열, percentage 는 사용률(%) |
| disk_usage | object | `{"total":"474.72 GB","used":"408.15 GB","free":"66.57 GB"…` | 설치 볼륨 디스크 사용량. total/used/free 단위 문자열 + percentage 사용률(%) |
| php_memory_limit | string | `512M` | PHP `memory_limit` ini 값 |
| max_execution_time | string | `36000초` | PHP `max_execution_time` ini 값 (초 단위 접미사 부착) |
| upload_max_filesize | string | `2G` | PHP `upload_max_filesize` ini 값 |
| opcache | object | `{"loaded":true,"enabled":true}` | OPcache 상태 (`OpcacheStatus::probe()`). `loaded` 는 확장 적재 여부, `enabled` 는 `opcache.enable` 지시자 값이며 `ini_get` 이 차단·미정의인 환경에서는 **확인 불가를 뜻하는 `null`** 이 된다 (false 와 구분된다) |
| install_path | string | `C:\Users\HeuJung\htdocs\g7_2` | 애플리케이션 설치 루트 경로 (`base_path()`) |
| config_path | string | `C:\Users\HeuJung\htdocs\g7_2\storage\…` | 설정 파일 저장 경로 (`storage/app/settings`) |
| log_path | string | `C:\Users\HeuJung\htdocs\g7_2\storage\…` | 로그 파일 저장 경로 (`storage/logs`) |
| upload_path | string | `C:\Users\HeuJung\htdocs\g7_2\storage\…` | 공개 업로드 파일 저장 경로 (`storage/app/public`) |
| php_extensions | object | `{"required":{"openssl":true,"pdo":true,"mbstring":true,"t…` | PHP 확장 로드 상태. required(필수)·optional(선택) 두 그룹으로 나뉘며 각 확장명→로드 여부(bool) 매핑 |
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
        "g7_version": "7.0.7",
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
        "install_path": "C:\\Users\\HeuJung\\htdocs\\g7",
        "config_path": "C:\\Users\\HeuJung\\htdocs\\g7\\storage\\app/settings",
        "log_path": "C:\\Users\\HeuJung\\htdocs\\g7\\storage\\logs",
        "upload_path": "C:\\Users\\HeuJung\\htdocs\\g7\\storage\\app/public",
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
| s3_region | body | string | 아니오 | max 64, 소문자 영숫자·하이픈 (`^[a-z0-9-]+$`) | S3 리전 — AWS 리전 코드 또는 S3 호환 스토리지 값 (Cloudflare R2 는 `auto`) |
| s3_access_key | body | string | 아니오 | max 255 | S3 액세스 키 |
| s3_secret_key | body | string | 아니오 | max 255 | S3 시크릿 키 |
| s3_url | body | string | 아니오 | url, max 500 | S3 공개 URL(CDN) base — 연결 테스트에는 사용되지 않음 |
| s3_endpoint | body | string | 아니오 | url, max 500 | S3 API 엔드포인트 — 테스트 시 실제 아웃바운드 대상에 반영 (S3 호환 스토리지용) |
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


### POST /api/admin/settings/test-outbound-proxy
<!-- @generated:start:api.admin.settings.test-outbound-proxy -->
- **라우트명**: `api.admin.settings.test-outbound-proxy`
- **컨트롤러**: `AppHttpControllersApiAdminSettingsController@testOutboundProxy`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| outbound_proxy | body | string | 예 | max 500, 스킴 `http`/`https`/`socks4`/`socks4a`/`socks5`/`socks5h` | 검사할 프록시 주소 |
| outbound_proxy_bypass | body | array | 아니오 | — | 프록시를 경유하지 않을 호스트 목록 |
| outbound_proxy_bypass.* | body | string | 아니오 | max 255 | 프록시 예외 호스트 |

**요청 예시**

```http
POST /api/admin/settings/test-outbound-proxy HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "outbound_proxy": "socks5h://127.0.0.1:1080",
    "outbound_proxy_bypass": ["internal.example.com"]
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
| value | body | string | 예 | max 1000 | 값 |

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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 저장 중 예외가 발생한 경우 (`settings.update_error`) |

<!-- @generated:end -->

**설명**

단일 설정 키의 값을 업데이트합니다. 경로의 `key` 로 대상 설정을, 본문의 `value` 로 새 값을 지정합니다. 탭 단위 일괄 저장(`POST /api/admin/settings`)과 달리 개별 키 하나만 변경할 때 사용합니다. 검증 실패 시 422, 그 외 오류 시 500 을 반환합니다.


