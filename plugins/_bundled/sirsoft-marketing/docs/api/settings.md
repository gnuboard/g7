# Settings API 레퍼런스

> **소유**: plugin `sirsoft-marketing` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Settings 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-marketing/settings
<!-- @generated:start:api.plugins.sirsoft-marketing.settings -->
- **라우트명**: `api.plugins.sirsoft-marketing.settings`
- **컨트롤러**: `Plugins\Sirsoft\Marketing\Http\Controllers\MarketingSettingsController@settings`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-marketing/settings HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| marketing_consent_enabled | boolean | `true` | 광고성 정보 수신 전체 동의 축의 노출 여부. false 면 회원가입/마이페이지에서 해당 동의 UI 를 렌더하지 않는다(미설정 시 기본 `true`). |
| marketing_consent_terms_slug | string | `marketing-terms` | 광고성 정보 수신 약관 페이지 라우팅에 쓰는 slug. 미설정 시 `null`. 프론트는 값 자체를 링크 대상에만 사용한다. |
| marketing_consent_terms_slug_set | boolean | `true` | 광고성 정보 수신 약관 slug 존재 여부. 프론트는 이 플래그로 약관 링크 표시 여부를 판정한다. |
| third_party_consent_enabled | boolean | `true` | 제3자 제공 동의 축의 노출 여부. false 면 해당 동의 UI 를 렌더하지 않는다(미설정 시 기본 `false`). |
| third_party_consent_terms_slug | null | `null` | 제3자 제공 약관 페이지 라우팅에 쓰는 slug. 미설정 시 `null`. |
| third_party_consent_terms_slug_set | boolean | `false` | 제3자 제공 약관 slug 존재 여부. 프론트의 약관 링크 표시 판정에 쓴다. |
| info_disclosure_enabled | boolean | `true` | 정보 이용 안내 동의 축의 노출 여부. false 면 해당 안내 UI 를 렌더하지 않는다(미설정 시 기본 `false`). |
| info_disclosure_terms_slug | null | `null` | 정보 이용 안내 약관 페이지 라우팅에 쓰는 slug. 미설정 시 `null`. |
| info_disclosure_terms_slug_set | boolean | `false` | 정보 이용 안내 약관 slug 존재 여부. 프론트의 약관 링크 표시 판정에 쓴다. |
| channels | array | `[{"key":"email_subscription","label":"광고성 이메일 수신","label_…` | `MarketingConsentService::getRegisteredChannels()` 가 반환하는 활성 채널 목록. 각 원소는 `key`·현재 로케일 해석 `label`·로케일 맵 원본 `label_i18n`·`enabled`·`terms_slug`·`terms_slug_set` 를 갖는다. 폼의 반복 렌더링에 그대로 쓰인다. |

**에러 응답**

_대표 에러 없음 (공개 조회). <!-- TODO: 도메인 특이 에러가 있으면 보강 -->_

<!-- @generated:end -->

**설명**

비로그인 상태에서도 조회 가능한 공개 설정 엔드포인트다(`PublicBaseController`). 회원가입 폼과 마이페이지의 마케팅 동의 UI 가, 어떤 동의 항목을 노출할지·약관 링크가 존재하는지·어떤 채널을 반복 렌더링할지 결정하기 위해 소비한다. `plugin_settings` 저장값과 `channels` JSON 을 조합해 반환하며 별도 DB 모델을 두지 않는다.

- **약관 slug 은 값을 노출하지 않는다.** 각 동의 항목마다 실제 slug(`*_terms_slug`)와 존재 플래그(`*_terms_slug_set`)를 함께 반환하되, slug 미설정 시 값은 `null` 이고 플래그는 `false` 다. 프론트는 링크 표시 여부를 `*_terms_slug_set` 으로 판정하고, slug 값 자체는 약관 페이지 라우팅에만 쓴다.
- **세 가지 법적 동의 축**: `marketing_consent_*`(광고성 정보 수신 전체 동의), `third_party_consent_*`(제3자 제공), `info_disclosure_*`(정보 이용 안내). 각 축의 `*_enabled` 가 false 면 해당 동의 UI 를 렌더하지 않는다. `marketing_consent_enabled` 는 미설정 시 기본 `true`, 나머지 두 축은 기본 `false`.
- **`channels` 배열**은 `MarketingConsentService::getRegisteredChannels()` 가 반환하는 **활성 채널만** 담는다. 각 원소는 `key`, 현재 로케일로 해석된 `label`, 로케일 맵 원본 `label_i18n`, `enabled`, 그리고 slug 값(`terms_slug`)과 존재 플래그(`terms_slug_set`)를 갖는다. 회원가입/마이페이지 폼의 iteration 렌더링에 그대로 쓰인다.
- `label` 은 요청 시점의 `app()->getLocale()` 기준으로 해석되며, 해당 로케일 라벨이 없으면 `fallback_locale`(기본 `ko`) → `key` 순으로 폴백한다.

**응답 예시** (실측)

```json
{
  "success": true,
  "data": {
    "marketing_consent_enabled": true,
    "marketing_consent_terms_slug": "marketing-terms",
    "marketing_consent_terms_slug_set": true,
    "third_party_consent_enabled": true,
    "third_party_consent_terms_slug": null,
    "third_party_consent_terms_slug_set": false,
    "info_disclosure_enabled": true,
    "info_disclosure_terms_slug": null,
    "info_disclosure_terms_slug_set": false,
    "channels": [
      {
        "key": "email_subscription",
        "label": "광고성 이메일 수신",
        "label_i18n": { "ko": "광고성 이메일 수신", "en": "Marketing email" },
        "enabled": true,
        "terms_slug": null,
        "terms_slug_set": false
      }
    ]
  },
  "message": null,
  "error": null
}
```


