# License API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 License 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/admin/license
<!-- @generated:start:api.admin.license -->
- **라우트명**: `api.admin.license`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\LicenseController@core`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/license HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| content | string | `프로그램 명칭 : 그누보드7 (Gnuboard7)  저작자 : (주…` | 본문 내용 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "content": "프로그램 명칭 : 그누보드7 (Gnuboard7)\n\n저작자 : (주)에스아이알소프트\n\n라이선스 (License)\n\n번역문 아래에 원문이 있습니다.\n\n주의)\n1. 번역문과 원문의 내용상 차이가 있는 경우 원문의 내용을 우선으로 따릅니다.\n2. 이 라이선스 파일 및 내용은 저작자를 제외한 어느 누구도 추가, 수정, 삭제할 수 없습니다.\n\n----- MIT 라이선스 (한국어 번역) --------------------------------------------------------\n\nMIT 라이선스\n\nCopyright (c) 2026 (주)에스아이알소프트\n\n이 소프트웨어와 관련 문서 파일(이하 \"소프트웨어\")의 복사본을 취득하는 모든 사람에게\n소프트웨어를 제한 없이 사용, 복사, 수정, 병합, 출판, 배포, 서브라이선스 허여 및/또는\n판매할 수 있는 권리를 무상으로 부여합니다. 다만, 소프트웨어를 제공받은 사람은 다음\n조건을 따라야 합니다:\n\n위 저작권 고지와 본 허가 고지는 소프트웨어의 모든 복사본 또는 상당 부분에 포함되어야\n합니다.\n\n소프트웨어는 \"있는 그대로\" 제공되며, 명시적이든 묵시적이든 어떠한 종류의 보증도 하지\n않습니다. 여기에는 상품성, 특정 목적에의 적합성 및 비침해에 대한 보증이 포함되나 이에\n국한되지 않습니다. 어떠한 경우에도 저작자 또는 저작권자는 소프트웨어나 소프트웨어의\n사용 또는 기타 거래로 인해 발생하는 계약, 불법행위 또는 기타 청구, 손해 또는 기타\n책임에 대해 책임을 지지 않습니다.\n\n----- MIT License (English Original) --------------------------------------------------------\n\nThe MIT License (MIT)\n\nCopyright (c) 2026 SIRSOFT\n\nPermission is hereby granted, free of charge, to any person obtaining a copy\nof this software and associated documentation files (the \"Software\"), to deal\nin the Software without restriction, including without limitation the rights\nto use, copy, modify, merge, publish, distribute, sublicense, and/or sell\ncopies of the Software, and to permit persons to whom the Software is\nfurnished to do so, subject to the following conditions:\n\nThe above copyright notice and this permission notice shall be included in all\ncopies or substantial portions of the Software.\n\nTHE SOFTWARE IS PROVIDED \"AS IS\", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR\nIMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,\nFITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE\nAUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER\nLIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,\nOUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE\nSOFTWARE.\n"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명** 코어 라이선스 파일의 원문 텍스트를 `content`로 반환합니다. `auth:sanctum` 인증이 필요하며, 라이선스 파일이 없으면 404(`common.not_found`)를 반환합니다. 관리자 화면에서 코어 저작권·라이선스 고지를 표시하는 용도로 사용합니다.


