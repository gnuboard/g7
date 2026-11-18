# Display Settings API 레퍼런스

> **소유**: plugin `sirsoft-popup` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 팝업 관리 화면이 읽는 표시 방식(전역 설정) 조회 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 응답 필드 표 + 응답 예시(envelope)
3. 조회만 합니다 — 표시 방식 저장은 코어 플러그인 설정 화면(core.plugins.update)이 합니다
4. 응답 예시의 값은 기본 설정 기준 예시이며, 실제 값은 사이트 설정·서버 PHP 한도에 따라 다릅니다
5. 갱신: 코드 변경 후 php artisan api:docgen 재실행
```

---


### GET /api/plugins/sirsoft-popup/admin/display-settings
<!-- @generated:start:api.plugins.sirsoft-popup.admin.display-settings.show -->
- **라우트명**: `api.plugins.sirsoft-popup.admin.display-settings.show`
- **컨트롤러**: `Plugins\Sirsoft\Popup\Http\Controllers\Admin\DisplaySettingsController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-popup.popups.read|core.plugins.read,false` (OR — 플러그인 설정 화면이 받는 조회라 「플러그인 보기」로도 읽는다)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-popup/admin/display-settings HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| settings | object | `{"pc_position":"center","pc_left":80,"pc_top":120,"pc_wid…` | 모든 팝업에 똑같이 적용되는 전역 표시 방식(플러그인 설정값을 허용 범위로 맞춘 값). 공개 `GET /popups` 의 `settings` 와 같은 객체다 — 키별 의미는 아래 설명 절의 표 |
| upload | object | `{"max_mb":5,"server_limit_mb":8,"effective_mb":5}` | 이미지 업로드 한도(MB). `max_mb` 플러그인 설정값(1~10), `server_limit_mb` 서버 PHP 한도(`upload_max_filesize`·`post_max_size` 중 작은 값, 0 이면 제한 없음), `effective_mb` 실제로 받는 한도(두 값 중 작은 값) — 업로더 최대 용량과 안내 문구에 쓴다 |
| can_update_settings | boolean | `true` | 요청자에게 코어 플러그인 설정 변경 권한(`core.plugins.update`)이 있는지. 표시 방식 저장은 플러그인 설정 화면이 하므로, 화면은 이 값으로 「표시 방식 설정」 이동 링크를 보이거나 숨긴다 |
| editor | object | `{"timezone":"Asia\/Seoul","server_now":"2026-01-15T01:00:…` | 등록·수정 화면 보조값. `timezone` 사이트 시간대(기간 입력의 기준), `server_now` 서버 현재 시각(ISO 8601, UTC) — 「지금 저장하면 예약으로 표시됩니다」 판정용, `default_locale` 사이트 기본 언어(비운 언어의 대체 언어), `locales` 다국어 입력 탭 순서(사이트 지원 언어) |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "표시 방식을 불러왔습니다.",
    "data": {
        "settings": {
            "pc_position": "center",
            "pc_left": 80,
            "pc_top": 120,
            "pc_width": 480,
            "pc_height": 520,
            "mobile_style": "sheet",
            "dim_background": true,
            "close_on_backdrop": false,
            "autoplay": true,
            "autoplay_interval": 6,
            "dismiss_mode": "today",
            "dismiss_days": 7
        },
        "upload": {
            "max_mb": 5,
            "server_limit_mb": 8,
            "effective_mb": 5
        },
        "can_update_settings": true,
        "editor": {
            "timezone": "Asia/Seoul",
            "server_now": "2026-01-15T01:00:00+00:00",
            "default_locale": "ko",
            "locales": [
                "ko",
                "en",
                "ja"
            ]
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-popup.popups.read`·`core.plugins.read`)이 모두 없는 경우 |

<!-- @generated:end -->

**설명**

팝업 관리 화면이 지금 적용 중인 표시 방식을 읽는 조회 전용 엔드포인트입니다. 목록 상단의 표시 방식 요약, 등록·수정 화면 오른쪽 미리보기(사용자 레이어와 같은 컴포넌트를 이 값대로 축소해 그림), 권장 이미지 크기(PC 너비×높이) 안내에 씁니다.

- 권한: `auth:sanctum` + 관리자(`admin`) + 팝업 조회 권한(`sirsoft-popup.popups.read`). 표시 방식 **저장**은 이 API 가 아니라 코어 플러그인 설정 화면(`core.plugins.update` 권한)이 합니다. 그 권한이 없는 팝업 담당자도 현재 표시 방식을 보고 미리볼 수 있도록 읽기를 팝업 조회 권한으로 따로 열어 둔 것입니다.
- `settings` 는 저장된 설정을 그대로 내보내지 않고 읽을 때 허용 범위로 한 번 더 맞춥니다(설정 파일을 직접 고쳤거나 옛 값이 남아 있어도 화면이 깨지지 않도록). 범위를 벗어난 값은 아래 표의 범위로 잘리고, 허용값이 아닌 값은 기본값으로 바뀝니다.
- 요청 파라미터는 없습니다.

**`settings` 객체**

| 키 | 타입 | 기본값 | 범위·허용값 | 설명 |
| --- | --- | --- | --- | --- |
| pc_position | string | `center` | `center`, `coords` | PC 배치 — 화면 중앙 또는 좌표 지정 |
| pc_left | integer | `80` | 0~2000 | 좌표 지정일 때 왼쪽에서의 거리(px) |
| pc_top | integer | `120` | 0~2000 | 좌표 지정일 때 위에서의 거리(px) |
| pc_width | integer | `480` | 240~1200 | PC 팝업 너비(px). 권장 이미지 너비 |
| pc_height | integer | `520` | 200~1000 | PC 팝업 높이(px). 권장 이미지 높이 |
| mobile_style | string | `sheet` | `center`, `sheet` | 모바일 표시 — 중앙 또는 하단 시트 |
| dim_background | boolean | `true` | — | 팝업 뒤 배경 어둡게 |
| close_on_backdrop | boolean | `false` | — | 배경을 누르면 닫기 |
| autoplay | boolean | `true` | — | 팝업이 여러 개일 때 자동 넘김 |
| autoplay_interval | integer | `6` | `4`, `6`, `8` | 자동 넘김 간격(초) |
| dismiss_mode | string | `today` | `today`, `days`, `close` | 「보지 않기」 방식 — 오늘 하루 / N일 / 보지 않기 단추 없이 닫기만 |
| dismiss_days | integer | `7` | 1~30 | `days` 일 때 보지 않을 일수 |

