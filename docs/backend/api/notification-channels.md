# Notification Channels API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Notification Channels 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/admin/notification-channels
<!-- @generated:start:api.admin.notification-channels.index -->
- **라우트명**: `api.admin.notification-channels.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\NotificationChannelController@index`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/notification-channels HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| channels | array | `[{"id":"mail","name_key":"notification.channels.mail.name…` | 사용 가능한 알림 채널 메타데이터 목록. 각 원소는 `id`(채널 식별자: mail, database 등), `name`/`name_key`·`description`/`description_key`(활성 locale 기준 해석된 라벨/설명과 원본 다국어 키), `icon`(Font Awesome 클래스), `source`(제공 주체: core/module/plugin)·`source_label`(출처 표시 라벨), `allow_guest`(비회원 발송 허용 여부), `readiness`(컨트롤러가 `ChannelReadinessCheckerInterface`로 붙인 채널 설정 완료 여부 정보)로 구성됩니다. config 기본 채널(mail, database)에 `core.notification.filter_available_channels` 훅으로 추가된 확장 채널이 병합됩니다. |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "알림 채널 목록을 조회했습니다.",
    "data": {
        "channels": [
            {
                "id": "mail",
                "name_key": "notification.channels.mail.name",
                "icon": "fas fa-envelope",
                "description_key": "notification.channels.mail.description",
                "source": "core",
                "source_label_key": "notification.channels.core_default",
                "allow_guest": true,
                "name": "메일",
                "description": "이메일로 알림 발송",
                "source_label": "코어 기본 채널",
                "readiness": {
                    "ready": false,
                    "reason": "notification.readiness.mail_smtp_host_empty"
                }
            },
            {
                "id": "database",
                "name_key": "notification.channels.database.name",
                "icon": "fas fa-bell",
                "description_key": "notification.channels.database.description",
                "source": "core",
                "source_label_key": "notification.channels.core_default",
                "allow_guest": false,
                "name": "사이트내 알림",
                "description": "사이트내 알림 센터에 표시",
                "source_label": "코어 기본 채널",
                "readiness": {
                    "ready": true,
                    "reason": null
                }
            }
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

**설명** 시스템에서 사용 가능한 알림 채널 목록을 반환하며, 각 채널에 설정 완료 여부(`readiness`) 정보를 붙여 제공합니다. 인증(`auth:sanctum`)과 `core.settings.read` 권한이 필요합니다. 플러그인이 Filter 훅으로 채널을 확장할 수 있으므로 목록은 설치된 확장에 따라 달라집니다. 알림 정의·템플릿 편집 화면에서 채널 선택 옵션을 채우고 미설정 채널을 안내할 때 사용합니다.


