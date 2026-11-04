# 템플릿 환경설정

> 템플릿이 자기 환경설정(브랜딩·색·헤더·푸터·홈 같은 테마 고유 옵션)을 선언하고, 운영자가 관리자 화면에서 값을 바꾸게 하는 방법입니다. 코어 7.2.0 부터.

---

## TL;DR (5초 요약)

```text
1. templates/{id}/config/settings/defaults.json — 섹션·기본값·칸·노출 선언 (모듈 설정과 같은 섹션 구조, PHP 클래스 없음)
2. 선언만 하면 켜진 관리자 템플릿의 공용 설정 화면이 그린다 — 직접 쓴 화면(templates/{id}/settings/template_settings.g7.html)이 있으면 그것이 우선
3. 보기 = core.templates.read, 저장·기본값 되돌리기 = core.templates.settings
4. 읽기: 레이아웃 {{_global.templateSettings?.섹션?.키 ?? 기본값}} · 서버 g7_template_settings('id', '섹션.키') · 명령줄 settings:get --template
5. 코어·모듈이 이미 가진 값(사이트 이름·로고·사업자 정보 등)은 템플릿 설정으로 다시 저장하지 않는다 — §8
```

---

## 목차

1. [개요](#1-개요)
2. [defaults.json 형식](#2-defaultsjson-형식)
3. [칸 종류](#3-칸-종류)
4. [표준 키 — 코어가 읽는 칸](#4-표준-키--코어가-읽는-칸)
5. [설정 화면](#5-설정-화면)
6. [권한과 API](#6-권한과-api)
7. [값 읽기](#7-값-읽기)
8. [템플릿 설정으로 두지 않는 것](#8-템플릿-설정으로-두지-않는-것)
9. [저장·이전·삭제 시 데이터](#9-저장이전삭제-시-데이터)
10. [알려진 한계](#10-알려진-한계)
11. [관련 문서](#11-관련-문서)

---

## 1. 개요

모듈·플러그인은 PHP 클래스가 기본값·검증 스키마를 돌려주지만, 템플릿에는 PHP 클래스가 없습니다. 그래서 템플릿 환경설정은 **선언 파일 한 벌**에서 섹션·기본값·검증·화면·노출을 모두 파생합니다. 저장·노출·비밀값 처리는 모듈 설정과 같습니다.

| 구성 | 위치 | 비고 |
| --- | --- | --- |
| 선언 | `templates/{id}/config/settings/defaults.json` | 없으면 설정 기능 없음 |
| 설정 화면 | 선언만 두면 켜진 관리자 템플릿의 공용 화면. 직접 쓰려면 `templates/{id}/settings/template_settings.g7.html` | 직접 쓴 화면이 우선 |
| 저장 값 | `storage/app/templates/{id}/settings/{섹션}.json` (섹션별 파일) | 템플릿 디렉토리 밖이라 업데이트가 지우지 않는다. 첫 저장 때 생긴다 |

설치된 템플릿이면 **꺼져 있어도** 설정할 수 있습니다(값은 켜진 뒤 적용). 저장하지 않은 칸은 선언 기본값으로 읽히므로, 운영자가 설정을 건드리지 않으면 화면은 템플릿 원래 모습 그대로입니다.

---

## 2. defaults.json 형식

```json
{
    "_meta": { "version": "1.0.0", "description": "My 템플릿 환경설정", "categories": ["branding", "header"] },
    "sections": {
        "branding": { "label": "$t:settings.sections.branding.label", "hint": "$t:settings.sections.branding.hint" },
        "header": { "label": "$t:settings.sections.header.label" }
    },
    "defaults": {
        "branding": { "favicon": [], "accent": "blue" },
        "header": { "max_boards": 5, "links": [] }
    },
    "schema": {
        "branding": {
            "favicon": { "type": "attachments", "max_files": 1, "accept": ["ico", "png", "svg", "gif", "jpg", "jpeg", "webp"], "label": "$t:settings.favicon.label" },
            "accent": { "type": "enum", "options": [{ "value": "blue", "label": "$t:settings.accent.blue" }, { "value": "green", "label": "$t:settings.accent.green" }], "label": "$t:settings.accent.label" }
        },
        "header": {
            "max_boards": { "type": "integer", "min": 1, "max": 10, "required": true, "label": "$t:settings.header.max_boards.label" },
            "links": {
                "type": "list", "max_items": 5, "label": "$t:settings.header.links.label",
                "item_fields": {
                    "label": { "type": "multilingual", "required": true, "max_length": 30, "label": "$t:settings.header.links.item_label" },
                    "url": { "type": "link", "required": true, "label": "$t:settings.header.links.item_url" }
                }
            }
        }
    },
    "frontend_schema": {
        "branding": { "expose": true, "fields": { "favicon_url": { "expose": true }, "accent": { "expose": true } } },
        "header": { "expose": true }
    }
}
```

| 블록 | 역할 |
| --- | --- |
| `_meta.categories` | 섹션 목록과 순서(필수) — 설정 화면은 섹션마다 탭 하나(모듈·코어 환경설정과 같다) |
| `sections` | 섹션 라벨·안내 |
| `defaults` | 섹션별 기본값. 선언만 하고 기본값을 두지 않은 칸은 종류별 빈 값(문자 `''`·참거짓 `false`·목록 `[]`) |
| `schema` | 섹션별 칸 선언 — 여기 있는 칸만 저장된다 |
| `frontend_schema` | 모듈과 같은 2단 노출 — 섹션 `expose`, 섹션 안 `fields.{키}.expose`(없으면 섹션 통째). 노출한 칸만 `_global.templateSettings`·공개 설정 API·검색엔진용 화면에 실린다 |

칸 공통 필드:

| 필드 | 설명 |
| --- | --- |
| `type` | §3 칸 종류 |
| `label`·`hint`·`placeholder` | 평문, 다국어 객체(`{"ko": "…", "en": "…"}`), 또는 `$t:키`. `$t:` 는 **그 템플릿의 프론트 언어 파일**(활성 언어팩 포함)에서 현재 로케일로 서버가 풀어 준다 — 사용자 템플릿 언어 파일은 관리자 화면에 없기 때문이다 |
| `required` | 필수(보냈을 때 비울 수 없음) |
| `sensitive` | 비밀값(`string`·`text` 만) — 파일에 암호화, 관리자 응답은 마스크, 노출 금지(§9) |
| `visible_if` | 같은 섹션 칸 값에 따라 칸 보이기 — `{"field": "show_banner", "equals": true}` 또는 `{"field": "mode", "in": ["a", "b"]}` |
| `renamed_from` | 옛 경로 목록 — `["옛섹션.옛키"]`(§9) |

선언이 규약에 어긋나면 **템플릿 설치·업데이트가 실패**합니다 — JSON 오류, `_meta.categories` 없음, 목록 밖 섹션·종류, 선택지 없는 `enum`, 계산 키(`{키}_url`)와 겹치는 칸, `frontend_schema` 의 선언 밖 키, 비밀값 노출, 기본값 종류 불일치, 표준 키 규격 위반(§4). §8 의 이름과 겹치는 칸은 실패가 아니라 설치 경고로 알립니다.

---

## 3. 칸 종류

| `type` | 값 | 화면 입력 | 서버 검증 |
| --- | --- | --- | --- |
| `string` | 문자열 | 한 줄 입력 | `min`·`max_length`(기본 1000) |
| `text` | 문자열 | 여러 줄 입력 | `max_length`(기본 10000) |
| `integer` | 정수 | 숫자 입력 | `min`·`max`·`step`(배수) |
| `boolean` | 참거짓 | 켜기/끄기 | boolean |
| `enum` | 선택지 값 | 4개 이하 단일 선택 단추, 넘으면 펼침 목록 | `options` 안 값. 선택지는 `["a","b"]` 또는 `[{"value","label"}]` |
| `color` | `#rgb`·`#rrggbb`·`#rrggbbaa` | 색 고르기 + 값 | 형식 |
| `url` | http·https 주소 | 입력 | `url:http,https` |
| `link` | 사이트 안 경로(`/…`) 또는 http·https 주소 | 입력 | `javascript:`·`data:`·`//…`·공백 거부 |
| `email` | 이메일 | 입력 | email |
| `multilingual` | 언어별 맵 `{"ko": "…", "en": "…"}` | 언어 탭 | 언어마다 `max_length`. 비운 언어는 화면에서 기본 언어 문구로 보인다(`$localized`) |
| `list` | 행 목록 | 행 추가·삭제·위아래 이동, `max_items`(기본 20) | 행 칸은 `item_fields`(첨부·중첩 목록 불가) |
| `attachments` | 첨부 ID 목록 | 파일 올리기(저장 때 업로드) | 그 칸 컬렉션의 첨부만, `max_files` |
| `image` | 첨부 ID 목록(이미지) | 파일 올리기 | 이미지만(`png·jpg·jpeg·webp·gif` 기본, `accept` 로 좁힘), `max_size_kb`. 계산 키 `{키}_url` 로 공개 주소를 준다 |

첨부 칸(`attachments`·`image`)의 컬렉션은 `template_settings.{id}.{섹션}.{키}` 입니다. 업로드 때 선언 `accept`(전역 허용 목록과의 교집합)·`max_size_kb` 를 서버가 검사하고, 저장 때 그 컬렉션에 실재하는 ID 만 받습니다(다른 템플릿·코어 로고 첨부를 끌어올 수 없음). 선언에 없는 칸 컬렉션은 업로드를 받지 않습니다.

---

## 4. 표준 키 — 코어가 읽는 칸

이름이 정해진 칸은 코어가 의미를 압니다. 어느 템플릿이든 같은 이름·규격으로 선언하면 같은 동작을 얻습니다.

| 칸 | 규격 | 코어 동작 |
| --- | --- | --- |
| `branding.favicon` | `attachments`, `max_files: 1`, `accept` = `ico·png·svg·gif·jpg·jpeg·webp` 전부 | 파비콘 우선순위 ①(아래). 계산 키 `branding.favicon_url` |
| `branding.accent` | `enum`, 값은 `^[a-z][a-z0-9-]{0,31}$` | 활성 템플릿의 값을 `<html data-g7-accent="값">` 으로 싣는다(사용자·관리자·검색엔진용 화면). 색은 템플릿 CSS 가 그 속성으로 정한다 |
| `branding.color_scheme_default` | `enum`, 값 ⊆ `system`·`light`·`dark` | `<html data-g7-color-scheme-default="값">` — 아직 모드를 고르지 않은 방문자의 첫 모드. 템플릿의 테마 단추·초기화가 저장값이 없을 때 이 속성을 읽는다 |

속성에는 선언 선택지 안의 값만 나갑니다(저장 파일을 손으로 고쳐도 다른 값은 나가지 않는다). 예 — sirsoft-basic 은 파랑 한 벌을 강조색으로 쓰므로 `[data-g7-accent="green"] { --color-blue-600: var(--color-green-600); … }` 로 파랑 변수 묶음만 바꿉니다(레이아웃 클래스 무수정).

파비콘 우선순위:

```text
① 그 화면 종류의 활성 템플릿 설정 branding.favicon (사용자 화면 = 활성 사용자 템플릿, 관리자 화면 = 활성 관리자 템플릿)
② 환경설정 일반 탭 「파비콘」(general.site_favicon)
③ 기본 아이콘 — 운영자가 public/favicon.ico 를 직접 바꿔 두었으면 그것, 아니면 코어 번들 아이콘(public/assets/core/favicon/)
```

첨부 행이 지워졌거나 형식이 목록 밖이면 다음 계층으로 내려갑니다. 업로드한 파비콘은 공개 경로 `/api/favicon/{hash}` 로 나갑니다(1년 캐시 — 교체하면 주소가 바뀜). ico·svg 는 파비콘 컬렉션에서만 받고, svg 는 스크립트·이벤트 속성·외부 참조·foreignObject 가 없는 100KB 이하 파일만 받습니다(확장 아이콘과 달리 정사각·최소 크기 요구는 없고, 거부 사유는 번역 문구로 알립니다). 업로드 크기 상한은 1MB 입니다. 오류·점검·DB 접속 오류 화면과 설치 마법사는 DB 를 보지 않으므로 ③ 만 씁니다. 코어 업데이트는 운영자가 넣은 `public/favicon.ico`(비어 있지 않고 배포본과 다른 파일)를 덮지 않습니다.

---

## 5. 설정 화면

**선언만 두면 됩니다.** 켜진 관리자 템플릿이 공용 설정 화면(`admin_template_settings` — 번들 sirsoft-admin_basic 이 제공)을 가지면, 코어가 `/admin/templates/{id}/settings` 를 그 화면으로 만들고 조회 응답의 `sections` 서술대로 섹션마다 탭 하나씩 칸을 그립니다(섹션이 하나여도 탭 줄이 나온다). 주소 `?tab=섹션` 으로 그 탭이 바로 열리고(없는 섹션이면 첫 섹션), 저장은 지금 탭 섹션만 보냅니다(서버는 보낸 섹션만 합치고 다른 섹션 저장값은 그대로 둔다). 저장하지 않은 변경이 있으면 다른 탭으로 옮기기 전에 확인 창이 뜹니다. 섹션·칸 라벨은 템플릿이 꺼져 있어도 그 템플릿 언어 파일로 풀고, 지금 로케일 번역이 없으면 대체 로케일(`app.fallback_locale`) 문구를 씁니다. 공용 화면에는 읽기 전용 배너, 비활성 안내, 저장 실패 요약, 「기본값으로」(확인 창), 「사용자 화면에서 보기」, 이탈 경고가 들어 있습니다.

화면을 직접 그리고 싶으면 `templates/{id}/settings/template_settings.g7.html` 을 **`layouts/` 밖**에 둡니다(`layouts/` 안이면 그 템플릿 자기 화면으로도 등록돼 사용자 템플릿에 관리자 화면이 섞인다). 직접 쓴 화면은 켜진 관리자 템플릿에 `{id}.template_settings` 로 교차 등록되고, 운영자는 레이아웃 편집기로 고칠 수 있습니다. 관리자 템플릿의 `layouts/overrides/` 로는 덮을 수 없습니다(건너뛰고 경고).

```html
<layout name="template_settings" version="1.0.0" extends="_admin_base">
  <data-source id="template_settings" type="api" endpoint="/api/admin/templates/{{route.identifier}}/settings" method="GET" :auto_fetch="true" :auth_required="true" :refetchOnMount="true">
    <init-local>
      <field name="form" value="{{data?.settings ?? null}}"/>
    </init-local>
  </data-source>
  <computed name="isReadOnly">{{template_settings?.data?.abilities?.can_update !== true}}</computed>
  …
</layout>
```

- 조회 응답(`data`): `settings`(섹션 중첩 현재 값 — 첨부 칸은 첨부 객체 목록, 비밀값은 마스크), `sections`(섹션·칸 서술: 종류·라벨·안내·선택지 라벨·제약·컬렉션·허용 형식), `locales`(활성 언어·기본 언어), `is_active`, `abilities.can_update`·`can_reset`.
- 저장은 `PUT …/settings` 에 섹션 중첩 `_local.form` 을 보냅니다. 업로더는 `autoUpload=false` 로 두고 저장 전 `emit-event` 로 올립니다(신호를 받는 업로더 전부가 끝날 때까지 기다린다).
- 화면 공통 문구는 코어 프론트 언어 `core.template_settings.*` 를 씁니다 — 어느 관리자 템플릿에서나 로드됩니다.

목록(`/admin/templates`) 카드 더보기의 「설정」은 설정 화면을 그릴 수 있을 때만 열립니다(선언이 있고, 직접 쓴 화면 또는 공용 화면이 있음). 그렇지 않으면 흐리게 잠기고 사유(「설정 항목이 없거나 지금 관리자 템플릿이 설정 화면을 지원하지 않습니다」)를 함께 보입니다. 보기 권한만 있는 관리자(`core.templates.settings` 없음)에게는 자물쇠 아이콘과 「설정 (읽기 전용)」으로 보여, 들어가기 전에 읽기 전용임을 압니다.

---

## 6. 권한과 API

| 동작 | 권한 | API |
| --- | --- | --- |
| 보기 | `core.templates.read` | `GET /api/admin/templates/{id}/settings` |
| 저장 | `core.templates.settings` (「템플릿 설정」) | `PUT /api/admin/templates/{id}/settings` |
| 기본값으로 되돌리기 | `core.templates.settings` | `POST /api/admin/templates/{id}/settings/reset` — 본문 `section` 이 있으면 그 섹션만 |
| 직접 쓴 화면 원본 | `core.templates.read` | `GET /api/admin/templates/{id}/settings/layout` (공용 화면을 쓰는 템플릿은 404) |

목록·상세 응답의 각 템플릿에 `has_settings`·`settings_route` 가 실리고, 목록 abilities 에 `can_update_settings` 가 있습니다.

| 훅 | 종류 | 인자 |
| --- | --- | --- |
| `core.template_settings.before_save` → `filter_save_data` → `after_save` | 액션·필터·액션 | 식별자, 제출값(필터는 제출값을 돌려준다) / 병합 결과, 성공 여부 |
| `core.template_settings.before_reset` → `after_reset` | 액션 | 식별자, 섹션(전체면 null) — `after_reset` 은 성공 여부까지 |
| `core.template_settings.before_delete` → `after_delete` | 액션 | 식별자 |
| `core.template_settings.update_validation_rules` · `reset_validation_rules` | 필터 | 규칙, 식별자, 입력 |

저장·되돌리기 뒤 검색엔진용 화면 캐시를 비우고 활동 로그(`template_settings.save`·`template_settings.reset`)를 남깁니다. 응답 상세는 [templates API](../backend/api/templates.md).

---

## 7. 값 읽기

| 자리 | 방법 | 범위 |
| --- | --- | --- |
| 레이아웃 | `{{_global.templateSettings?.header?.max_boards ?? 5}}` | 그 화면 종류(사용자·관리자)의 **활성 템플릿 하나**의 노출 값. 옛 코어에는 키가 없으므로 `??` 기본값을 둔다 |
| 언어별 문구 | `{{$localized(_global.templateSettings?.footer?.copyright ?? {})}}` | 현재 언어 → 기본 언어 순 |
| 검색엔진용 화면 | 같은 `_global.templateSettings` | 활성 사용자 템플릿 |
| 공개 설정 API | `GET /api/frontend-config` 의 `template_settings` | 셸과 같은 값 |
| 서버 코드 | `g7_template_settings('sirsoft-basic', 'header.max_boards')` · `g7_settings('templates.sirsoft-basic.header.max_boards')` | 꺼진 템플릿 값도 읽힌다. 비밀값은 미러에 없다 — 서비스로 읽는다 |
| 명령줄 | `php artisan settings:get --template=sirsoft-basic` · `settings:set header.max_boards 7 --template=sirsoft-basic` · 언어별 문구·목록은 `--json-value` · 비밀값은 `--value-file` | 첨부 칸은 조회만(업로드는 화면에서), 비밀값은 조회 때 가린다 |

저장 직후 `_global.templateSettings`·`<html>` 표준 속성은 다음 화면 로드부터 바뀝니다(셸이 부팅 때 싣는 값).

**편집기와의 관계** — 레이아웃 편집기로도 고칠 수 있는 prop(예: 헤더 게시판 개수)을 템플릿 설정으로 둘 때는 레이아웃이 `{{_global.templateSettings?.… ?? 기본값}}` 로 연결해 **템플릿 설정이 유일한 주인**이 되게 합니다. 편집기는 그 칸을 「템플릿 설정에 연결된 값」 배지로 보이고 직접 지정으로 바꾸는 단추를 두지 않으며, 편집 중인 템플릿의 설정 화면을 새 창으로 여는 「설정 열기」 링크를 붙입니다. 편집기 미리보기를 위해 `templates/{id}/editor-spec/sampleGlobal.json` 의 `templateSettings` 에 기본값을 싣습니다.

---

## 8. 템플릿 설정으로 두지 않는 것

템플릿을 바꾸면 템플릿 설정은 따라오지 않습니다. 그래서 사이트의 값을 템플릿 설정에 다시 저장하면 템플릿마다 다시 입력해야 하고, 두 곳의 값이 어긋납니다.

1. 코어·모듈·플러그인이 이미 가진 값(사이트 이름·설명·로고·파비콘·가입·언어·통화·통계·쇼핑몰 사업자 정보)을 다시 저장하지 않는다 — `_global.settings`·`_global.modules`·`_global.plugins` 를 읽는다. 선언 칸 이름이 이런 값과 겹치면(`site_name`·`site_logo`·`company_name` 등) 설치 때 경고가 납니다.
2. 코어 값을 바꾸고 싶으면 **변형 + 대체**로만 둔다 — 비우면 코어 값(파비콘·관리자 로고·다크 로고가 이 모양).
3. 모듈 소유 데이터(무엇이·어떤 순서로·누구에게 보이나)는 바꾸지 않는다. 몇 개 보일지만 정한다.
4. 편집기로 고칠 수 있는 prop 을 설정으로 둘 때는 §7 처럼 설정이 유일한 주인이 되게 연결한다. 페이지 구조(노드 추가·삭제·배치)는 편집기 몫이다.
5. 사이트 기능(메뉴 관리·법적 고지·소셜 계정 원장)은 코어·모듈과 확장점에 맡긴다. 쇼핑몰 사업자 정보는 쇼핑몰 모듈이 사용자 템플릿 공용 자리 `user_global_footer` 에 직접 그린다. (sirsoft-basic 의 헤더 바로가기·소셜 링크는 그 기능이 아직 없어 템플릿 설정에 둔 예외다.)
6. 노출하지 않은(`expose:false`) 다른 확장 설정을 읽지 않는다 — 공개 여부는 주인이 정한다.
7. 비밀값은 `sensitive` 로만 두고 노출하지 않는다.
8. 사용자 템플릿은 표준 확장점 `user_global_footer`·`user_global_overlay` 를 둔다(사업자 정보·쿠키 동의·팝업이 붙는 자리).

---

## 9. 저장·이전·삭제 시 데이터

- 제출하지 않은 섹션·칸은 기존 값을 유지합니다(첨부 칸 포함). 마스크 그대로 돌아온 비밀값도 유지합니다.
- 숫자·참거짓 문자열(HTML 입력·명령줄 값)은 칸 종류로 정규화해 저장합니다. 선언에서 빠진 칸은 다음 저장 때 파일에서 정리됩니다.
- 첨부 칸에서 빠진 첨부는 **저장이 성공한 뒤** 파일까지 파기합니다. 「기본값으로」도 그 범위의 첨부를 파기합니다.
- **칸 이름 바꾸기** — 템플릿에는 업그레이드 스텝이 없으므로, 새 칸에 `renamed_from: ["옛섹션.옛키"]` 를 적습니다. 읽을 때 옛 자리 값을 가져오고 다음 저장 때 새 자리에 씁니다(옛 섹션 파일은 비면 지운다).
- 비밀값(`sensitive`)은 파일에 암호화해 두고, 관리자 응답은 마스크로, 프론트·미러에는 싣지 않습니다(모듈·플러그인과 같음).
- 업로드 탭 「미참조 첨부 자동 정리」는 템플릿 설정 파일이 참조하는 첨부를 지우지 않습니다(템플릿을 지웠어도 설정 파일이 남아 있으면 보호).
- 템플릿 삭제에서 「데이터도 삭제」를 고르면 설정 폴더와 `template_settings.{id}.*` 첨부를 지웁니다(삭제 창에 설정 폴더 크기와 첨부 수가 보인다). 고르지 않으면 남겨 재설치 때 복원됩니다.

---

## 10. 알려진 한계

- `gnuboard7-hello_admin_template` 처럼 컴포넌트가 최소 구성인 관리자 템플릿이 켜져 있으면 공용 설정 화면이 없어 「설정」이 열리지 않습니다(직접 쓴 화면도 그 템플릿 컴포넌트로 그려야 한다 — 플러그인 설정 화면과 같은 한계).
- 탭 아이콘·색·첫 모드는 저장 뒤 **다음 화면 로드부터** 바뀝니다(SPA 이동은 `<head>` 를 다시 읽지 않음).
- 화면 모드 저장값(`g7_color_scheme`)은 관리자·사용자 화면이 같이 씁니다. 그래서 운영자 브라우저처럼 이미 모드를 고른 브라우저에는 「기본 화면 모드」가 적용되지 않습니다.

---

## 11. 관련 문서

- [template-basics.md](template-basics.md) — 템플릿 구조
- [module-settings.md](module-settings.md) — 모듈 환경설정(같은 섹션 구조)
- [layout-extensions.md](layout-extensions.md) — 표준 확장점(`user_global_footer`)
- [templates API](../backend/api/templates.md) · [settings API](../backend/api/settings.md) · [attachment API](../backend/api/attachment.md) — `/api/favicon/{hash}`
- [hooks.md](hooks.md) — 훅 시스템
