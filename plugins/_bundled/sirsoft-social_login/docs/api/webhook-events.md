# Webhook Events API 레퍼런스

> **소유**: plugin `sirsoft-social_login` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Webhook Events 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-social_login/admin/webhook-events
<!-- @generated:start:api.plugins.sirsoft-social_login.admin.webhook-events.index -->
- **라우트명**: `api.plugins.sirsoft-social_login.admin.webhook-events.index`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin\DiagnosticsController@webhookEvents`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-social_login.settings.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| provider | query | string | 아니오 | — | 제공자 키로 거르기(`^[a-z][a-z0-9_]{1,49}$`, 모의 제공자 `mock_*` 포함). 형식이 틀리면 422 |
| outcome | query | string | 아니오 | — | 이 목록에서는 쓰지 않는다(시도 기록과 같은 검증 규칙을 나눠 써서 받기만 한다). 허용값 `login`, `signup`, `link`, `pending`, `handoff`, `denied`, `failed` |
| intent | query | string | 아니오 | — | 이 목록에서는 쓰지 않는다. 허용값 `login`, `link`, `test`, `reauth` |
| error_code | query | string | 아니오 | max 50 | 이 목록에서는 쓰지 않는다. 최대 50자 |
| status | query | string | 아니오 | — | 상태 필터 (해당 상태의 항목만 조회) |
| event_type | query | string | 아니오 | — | 사건 종류로 거르기. `unlink`, `account_delete`, `deauthorize`, `data_deletion`, `incomplete_signup` |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |

**요청 예시**

```http
GET /api/plugins/sirsoft-social_login/admin/webhook-events?provider=%EC%98%88%EC%8B%9C%EA%B0%92&outcome=%EC%98%88%EC%8B%9C%EA%B0%92&intent=%EC%98%88%EC%8B%9C%EA%B0%92&error_code=%EC%98%88%EC%8B%9C%EA%B0%92&status=%EC%98%88%EC%8B%9C%EA%B0%92&event_type=%EC%98%88%EC%8B%9C%EA%B0%92&page=1&per_page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `71` | 기본 키 (내부 식별자) |
| provider_key | string | `kakao` | 알림을 보낸 제공자 키. `kakao`, `naver`, `facebook` |
| event_type | string | `unlink` | 사건 종류. `unlink`(연결 끊기, 연동 해제), `account_delete`(카카오 계정 삭제), `deauthorize`(페이스북 앱 권한 해제), `data_deletion`(페이스북 데이터 삭제 요청), `incomplete_signup`(카카오 가입 미완료). 검증에 실패한 수신은 `unlink` 로 남는다 |
| provider_user_id_prefix | string | `514703` | 제공자 쪽 사용자 ID 의 앞 6자. 전체 값은 싣지 않는다. 검증에 실패한 수신은 `null` |
| payload | object | `{"ip":"127.0.0.1"}` | 수신 요약(서명, 토큰, 암호문 제외). 카카오 `{app_id, referrer_type}`, 네이버 `{timestamp}`, 페이스북 `{issued_at}`, 검증 실패 `{ip}` |
| status | string | `ignored` | 상태 값 (도메인별 상태 집합 — 사람이 읽는 라벨은 status_label, UI 변형은 status_variant 참조) |
| attempts | integer | `0` | 큐 작업의 처리 시도 횟수 |
| error | string | `webhook_auth_invalid` | 거절 사유나 마지막 처리 오류. 검증 실패는 `webhook_auth_invalid`, `webhook_ip_not_allowed`, `webhook_app_mismatch`, `webhook_signature_invalid`, `webhook_payload_invalid`, `provider_unavailable`, 처리 결과는 `not_linked`(연결된 계정 없음), `withdraw_refused: …`(탈퇴 거절). 성공이면 `null` |
| received_at | string | `2026-11-01 19:10:25` | received 일시 |
| processed_at | string | `2026-11-01 19:10:25` | processed 일시 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "제공자 알림 수신 이력입니다.",
    "data": {
        "data": [
            {
                "id": 71,
                "provider_key": "kakao",
                "event_type": "unlink",
                "provider_user_id_prefix": null,
                "payload": {
                    "ip": "127.0.0.1"
                },
                "status": "ignored",
                "attempts": 0,
                "error": "webhook_auth_invalid",
                "received_at": "2026-11-01 19:10:25",
                "processed_at": "2026-11-01 19:10:25"
            },
            {
                "id": 66,
                "provider_key": "kakao",
                "event_type": "unlink",
                "provider_user_id_prefix": null,
                "payload": {
                    "ip": "127.0.0.1"
                },
                "status": "ignored",
                "attempts": 0,
                "error": "webhook_auth_invalid",
                "received_at": "2026-11-01 18:58:59",
                "processed_at": "2026-11-01 18:58:59"
            },
            "... (총 18건 중 2건 표시)"
        ],
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 25,
            "total": 18,
            "from": 1,
            "to": 18
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-social_login.settings.view`)이 없는 경우 |
| 422 | Unprocessable Entity | 쿼리 검증 실패(`AdminLogListRequest`): `provider` 형식, `outcome` `intent` `status` `event_type` 허용값 밖, `error_code` 50자 초과, `page` `per_page` 범위 밖. `error.errors` 에 필드별 메시지 |

<!-- @generated:end -->

**설명**

관리자 진단 화면의 「웹훅 수신 이력」 표가 부른다. 카카오, 네이버, 페이스북이 보낸 알림을 최신순(`id` 내림차순)으로 돌려준다. 필터는 `provider`, `status`, `event_type` 이고 모두 정확히 일치로 건다. `per_page` 를 주지 않으면 20건씩이다.

`status` 는 `received`(받아서 처리 대기), `processed`(처리 끝), `ignored`(검증 실패이거나 연결된 계정이 없어 할 일이 없음), `failed`(처리 실패: 회원 탈퇴가 거절됐거나 큐 재시도를 다 씀)다. 검증에 실패한 수신도 `ignored` 행으로 남으므로 위조 시도나 설정 오류(어드민 키, 허용 IP, 앱 ID)를 여기서 확인한다. `outcome`, `intent`, `error_code` 는 시도 기록과 같은 요청 검증을 나눠 써서 받기만 하고 이 목록을 거르지 않는다.

