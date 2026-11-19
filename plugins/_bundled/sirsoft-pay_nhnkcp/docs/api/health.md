# Health API 레퍼런스

> **소유**: plugin `sirsoft-pay_nhnkcp` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Health 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-pay_nhnkcp/admin/health
<!-- @generated:start:api.plugins.sirsoft-pay_nhnkcp.admin.health -->
- **라우트명**: `api.plugins.sirsoft-pay_nhnkcp.admin.health`
- **컨트롤러**: `Plugins\Sirsoft\PayNhnkcp\Controllers\HealthCheckController@check`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.read|core.plugins.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-pay_nhnkcp/admin/health HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| summary | object | `{"pc_payment_ready":true,"mobile_payment_ready":true,"mob…` | 결제 방식별 준비 여부를 한 줄로 요약한 것. 아래 `checks` 를 종합해 낸 값이며 `pc_payment_ready` 는 PHP `exec()` · CLI 바이너리 · 공개키가 모두 정상일 때만 참이다. 취소 요청이 나가는 서버 IP(`cancel_server_ip`)도 함께 실려, 결제사에 등록할 IP 를 운영자가 그대로 확인할 수 있다. |
| checks | array | `[{"id":"exec_function","category":"pc","label":"PHP exec(…` | 점검 항목의 목록. 항목마다 `id`(항목 식별자) · `category`(어느 결제 방식에 걸리는지) · `label`(화면 표시 이름) · `status`(정상/경고/오류)를 갖는다. 위 요약이 거짓일 때 **무엇이 막고 있는지**를 이 목록에서 찾는다. |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "시스템 점검을 마쳤습니다.",
    "data": {
        "summary": {
            "pc_payment_ready": true,
            "mobile_payment_ready": true,
            "mobile_vbank_only_ready": true,
            "error_count": 0,
            "warning_count": 0,
            "fixed_count": 0,
            "cancel_server_ip": {
                "address": "203.0.113.10",
                "source": "public_outbound",
                "source_label": "공인 송신 IP"
            }
        },
        "checks": [
            {
                "id": "exec_function",
                "category": "pc",
                "label": "PHP exec() 함수",
                "status": "ok",
                "detail": "CLI 호출 가능"
            },
            {
                "id": "bin_directory",
                "category": "pc",
                "label": "CLI 바이너리 디렉토리",
                "status": "ok",
                "detail": "디렉토리 존재"
            },
            {
                "id": "cli_pp_cli_exe",
                "category": "pc",
                "label": "CLI 바이너리 (Windows) — pp_cli_exe.exe",
                "status": "ok",
                "detail": "Windows 바이너리 — 실행 권한 검사 생략"
            },
            {
                "id": "pub_key",
                "category": "pc",
                "label": "KCP 공개키 (pub.key)",
                "status": "ok",
                "detail": "파일 존재"
            },
            {
                "id": "soap_extension",
                "category": "mobile",
                "label": "PHP SOAP 확장",
                "status": "ok",
                "detail": "SoapClient 사용 가능"
            },
            {
                "id": "wsdl_file",
                "category": "mobile",
                "label": "WSDL 파일",
                "status": "ok",
                "detail": "테스트/라이브 WSDL 모두 존재"
            }
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.settings.read\|core.plugins.update`)이 없는 경우 |

<!-- @generated:end -->

**설명** <!-- TODO: 이 엔드포인트의 용도·주의사항·예시 시나리오를 작성하세요 -->

