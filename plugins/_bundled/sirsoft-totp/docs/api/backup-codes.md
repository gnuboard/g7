# Backup Codes API 레퍼런스

> **소유**: plugin `sirsoft-totp` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Backup Codes 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /api/plugins/sirsoft-totp/backup-codes/regenerate

<!-- @generated:start:api.plugins.sirsoft-totp.backup-codes.regenerate -->
- **라우트명**: `api.plugins.sirsoft-totp.backup-codes.regenerate`
- **컨트롤러**: `Plugins\Sirsoft\Totp\Http\Controllers\TotpEnrollmentController@regenerateBackupCodes`
- **인증/권한**: `auth:sanctum` (로그인 필수)
- **FormRequest**: `Plugins\Sirsoft\Totp\Http\Requests\TotpCodeRequest`

**요청 파라미터**

| 파라미터 | 타입 | 필수 | 규칙 | 설명 |
| --- | --- | --- | --- | --- |
| `code` | string | ✅ | `min:6` `max:32` | 인증 앱의 현재 6자리 코드 **또는** 남은 백업 코드 |

**요청 예시**

```http
POST /api/plugins/sirsoft-totp/backup-codes/regenerate HTTP/1.1
Content-Type: application/json
Accept: application/json
Authorization: Bearer {token}

{ "code": "a3f9k-2mq7z" }
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시 | 설명 |
| --- | --- | --- | --- |
| `backup_codes` | string[] | `["b7k2m-4xqp9", ...]` | **평문** 백업 코드 10개 (새로 발급). 이전 목록은 전부 무효 |

확정 때와 마찬가지로 평문은 **이 응답에서만 한 번** 노출됩니다. 저장은 bcrypt 해시로만
이뤄집니다.

**응답 예시**

```json
{
  "success": true,
  "message": "백업 코드를 새로 만들었습니다. 이전 코드는 더 이상 쓸 수 없습니다.",
  "data": {
    "backup_codes": [
      "b7k2m-4xqp9", "c9r5t-1hdw3", "d2v8n-6jzl7", "e5f1q-9smb4", "f8h3c-2tkr6",
      "g1w6p-7ynd5", "j4l9z-3gvx8", "m6t2k-5bqh1", "n9s4d-8cwf2", "r3p7v-6mjt9"
    ]
  }
}
```

**에러 응답**

| 상태 | 조건 | 비고 |
| --- | --- | --- |
| 401 | 비로그인 요청 | `auth:sanctum` |
| 422 | `code` 검증 실패 | Laravel 표준 검증 응답 (`errors.code`) |
| 422 | 코드 불일치 · 확정된 자격증명 없음 | 같은 메시지 `sirsoft-totp::messages.errors.invalid_code` |
| 429 | 코드 확인을 너무 여러 번 틀림 (1.0.3) | `TotpOwnershipThrottle` — 회원 단위(`sirsoft-totp:ownership:{userId}`, 해제·재발급 합산), 상한 = 환경설정 보안 「로그인 시도 횟수」, 대기 = 「잠금 시간」(분). `sirsoft-totp::messages.errors.too_many_attempts` + `Retry-After`. 맞히면 횟수가 초기화된다 |

<!-- @generated:end -->

**설명**

백업 코드를 다 썼거나 보관 매체가 노출됐을 때 10개를 새로 발급한다. 시크릿은 그대로이므로
인증 앱을 다시 등록할 필요는 없다.

**이전 목록은 전부 무효가 된다.** 새 목록으로 통째로 교체하기 때문이며, 그래서 새 코드를
받아 적기 전에는 옛 목록을 버리면 안 된다.

본인 확인에 남은 백업 코드를 쓸 수도 있는데, 이때 그 코드는 교체 전에 소진된다 — 해제·
재발급도 1회용 규칙을 그대로 따른다.
