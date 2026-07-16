# Channels API 레퍼런스

> **소유**: plugin `sirsoft-marketing` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Channels 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### PUT /api/plugins/sirsoft-marketing/admin/channels
<!-- @generated:start:api.plugins.sirsoft-marketing.admin.channels.update -->
- **라우트명**: `api.plugins.sirsoft-marketing.admin.channels.update`
- **컨트롤러**: `Plugins\Sirsoft\Marketing\Http\Controllers\MarketingAdminController@updateChannels`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
PUT /api/plugins/sirsoft-marketing/admin/channels HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명**

관리자 환경설정 화면에서 마케팅 동의 **채널 목록 전체를 한 번에 저장**하는 엔드포인트다. 컨트롤러가 `AdminBaseController` 를 상속하므로 실제 인증은 `auth:sanctum` **에 더해 관리자(admin) 권한**을 요구한다(생성기 표기는 `auth:sanctum` 만 노출). 제출된 배열이 곧 새 상태가 되며, 개별 채널 추가/수정 엔드포인트는 없다(전량 교체 방식).

**요청 파라미터**는 생성기가 배열 중첩 규칙(`channels.*`)을 평면화하지 못해 위 표에 "없음"으로 표기되나, 실제 `ChannelUpdateRequest` 는 다음 body 를 요구한다:

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| channels | body | array | 예 | 최대 10개 | 저장할 채널 정의 배열(전체 교체) |
| channels.*.key | body | string | 예 | `^[a-z0-9_]+$` | 채널 식별자(소문자·숫자·밑줄) |
| channels.*.label | body | object | 예 | 로케일 맵, 각 값 최대 50자 | 다국어 라벨(`LocaleRequiredTranslatable`) |
| channels.*.page_slug | body | string | 아니오 | 최대 100자 | 연결 약관 페이지 slug |
| channels.*.enabled | body | boolean | 예 | — | 노출 여부 |
| channels.*.is_system | body | boolean | 예 | — | 시스템 채널 플래그 |

- **시스템 채널 보호**: `is_system=true` 채널은 제출 목록에서 빠져도 서버가 기본 정의를 다시 앞에 끼워 넣어 항상 유지된다. `is_system` 을 true→false 로 위변조하거나 시스템 채널을 누락시키면 검증 오류(422).
- **동의 이력 보호**: 이미 사용자 동의가 존재하는 채널을 목록에서 제거하려 하면 `MarketingConsentService::countConsentedByKey()` 가 0 이 아니어서 422 로 거부된다(개인정보 이력 보존).
- **key 중복 금지**: 같은 `key` 가 둘 이상이면 422.
- 성공 시 저장된 최종 `channels` 배열(시스템 채널 병합 포함)을 `data.channels` 로 되돌려준다.

**응답 예시** (성공)

```json
{
  "success": true,
  "data": {
    "channels": [
      { "key": "email_subscription", "label": { "ko": "광고성 이메일 수신", "en": "Marketing email" }, "page_slug": null, "enabled": true, "is_system": true }
    ]
  },
  "message": "채널이 저장되었습니다.",
  "error": null
}
```


