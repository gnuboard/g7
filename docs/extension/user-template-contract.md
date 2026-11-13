# 사용자 템플릿 계약

> **목적**: 사용자 템플릿(`type: user`)을 새로 만들거나 고칠 때, 번들 모듈과 플러그인이 가입 화면과 내 정보 화면에 기대는 자리(앵커, 확장점)와, 회원 항목을 그리는 약속(항목 API, 공용 조각, 동의 구획, 추가 정보 입력 유도)을 한곳에 정리한다. 관리자 템플릿 쪽 짝 문서는 [admin-template-contract.md](admin-template-contract.md) 다.

---

## TL;DR (5초 요약)

```text
1. 확장은 사용자 템플릿의 앵커(노드 id)와 확장점에 조각을 붙인다. 자리가 없으면 조각은 오류 없이 빠진다
2. 가입, 내 정보 화면의 앵커 5종: register_extension_fields, register_privacy_agreement,
   profile_bio_card, profile_view_bio_card, mypage_tab_content
3. 회원 항목 확장점 2종: member_field_input(사용자, 관리자 템플릿), member_field_type_options(관리자 템플릿)
4. 회원 항목은 화면이 정하지 않는다. 항목 API 가 준 계약대로 그린다
   (가입 GET /api/auth/register/fields, 내 정보 GET /api/me/fields, 추가 정보 입력 ?context=required)
5. 가입 요청에는 받은 fields_version 을 싣고, 409 fields_changed 면 항목을 다시 받는다
6. 필수 항목이 비어 있는 회원은 추가 정보 입력 화면으로 이끈다(pending_required_fields, 403 required_fields_pending)
7. 기준 구현은 sirsoft-basic 이다. 이 문서의 id, 키, 경로는 모두 그 템플릿의 것이다
```

---

## 목차

1. [왜 계약이 필요한가](#왜-계약이-필요한가)
2. [앵커 5종](#앵커-5종)
3. [회원 항목 확장점 2종](#회원-항목-확장점-2종)
4. [회원 항목 그리기 계약](#회원-항목-그리기-계약)
5. [확장별 카드](#확장별-카드)
6. [가입 화면의 동의 구획](#가입-화면의-동의-구획)
7. [정의 버전과 409 처리](#정의-버전과-409-처리)
8. [표시 이름 선택 칸](#표시-이름-선택-칸)
9. [본인인증 채움과 잠금](#본인인증-채움과-잠금)
10. [추가 정보 입력 유도](#추가-정보-입력-유도)
11. [소셜 로그인, SSO 가입 창 오버라이드](#소셜-로그인-sso-가입-창-오버라이드)
12. [새 사용자 템플릿 점검 목록](#새-사용자-템플릿-점검-목록)

---

## 왜 계약이 필요한가

모듈과 플러그인은 사용자 템플릿의 화면을 직접 고치지 않고, 그 화면의 노드 id 나 확장점에 조각을 붙인다([layout-extensions.md](layout-extensions.md)). 템플릿에 그 자리가 없으면 조각은 등록 단계에서 건너뛰어지고 화면에는 오류도 경고도 없이 그 부분만 빠진다. 다른 사용자 템플릿을 만든 사람은 「본인인증 카드가 안 보인다」로만 겪는다.

회원 항목도 같다. 어떤 항목을 받고 무엇이 필수인지는 운영자가 환경설정의 회원 탭에서 정하고, 확장이 항목을 선언해 더한다. 화면이 칸을 직접 적어 두면 운영자 설정과 확장 항목이 화면에 닿지 않는다. 그래서 가입 화면, 내 정보 화면, 추가 정보 입력 화면은 서버가 준 항목 계약을 돌며 그린다. 항목 선언 방법은 [field-sets.md](field-sets.md) 에, 응답 필드 전체는 [가입 API](../backend/api/auth.md), [내 정보 API](../backend/api/me.md) 에 있다.

## 앵커 5종

노드 id(마크업의 `data-ref`)로 겨냥하는 자리다. 이름을 바꾸거나 노드를 지우면 그 자리에 붙던 조각이 빠진다.

| 앵커 id | 대상 화면 | 놓인 곳(sirsoft-basic) | 붙는 번들 확장 | 붙이는 방식 |
| --- | --- | --- | --- | --- |
| `register_extension_fields` | `auth/register` | 가입 폼 조각 `partials/auth/_register_form`. 회원 항목 묶음과 표시 이름 칸 뒤, 약관 동의 줄 앞의 빈 노드 | 지금은 없음 | 확장이 가입 폼에 자기 칸을 더하는 공용 자리 |
| `register_privacy_agreement` | `auth/register` | 같은 조각의 개인정보 동의 줄을 감싼 노드 | 지금은 없음 | 동의 줄 바로 뒤에 자기 줄을 더하는 공용 자리 |
| `profile_bio_card` | `mypage/profile-edit` | 내 정보 수정 조각 `partials/mypage/profile/_edit` 의 서명과 자기소개 카드 | 지금은 없음 | 카드 뒤에 자기 카드를 더하는 공용 자리 |
| `profile_view_bio_card` | `mypage/profile` | 내 정보 보기 조각 `partials/mypage/profile/_view` 의 서명과 자기소개 카드 | 소셜 로그인(연결 계정 카드), GDPR(개인정보 카드), SSO(회사 계정 카드), KG이니시스 본인인증, NHN KCP 본인인증(본인인증 카드) | `append`(카드 뒤 형제로) |
| `mypage_tab_content` | `mypage/profile` 을 비롯한 마이페이지 탭 화면 전부 | 탭 내비게이션 아래의 본문 노드 | 이커머스(마일리지 카드, 배송지 카드. `mypage/profile` 에 붙는다) | `append_child`(본문 맨 뒤 자식으로) |

`profile_view_bio_card` 에 붙는 다섯 조각은 우선순위 값이 낮은 것부터 소셜 로그인(80), GDPR(90), SSO(95), KG이니시스(110), NHN KCP(120) 다. 조각이 놓이는 순서는 [우선순위](layout-extensions.md#우선순위) 규칙을 따른다.

지켜야 할 것:

- **노드는 조건으로 감춰도 트리에는 있어야 한다.** 조각 주입은 서버가 레이아웃 트리에서 id 가 같은 노드를 찾아 넣는다. `sirsoft-basic` 의 `profile_bio_card`, `profile_view_bio_card` 는 서명과 자기소개 항목이 모두 꺼져 있으면 `data-if` 로 그려지지 않지만, 노드가 트리에 남아 있어 그 뒤에 붙은 확장 카드는 그대로 그려진다. 카드를 조건부로 감추고 싶다고 노드를 지우거나 다른 id 로 바꾸지 않는다.
- **같은 id 는 한 화면에 한 번만 둔다.** 조각은 id 가 같은 첫 노드 하나에만 들어간다.
- **`mypage_tab_content` 는 화면 전환 표시의 대상이기도 하다.** 마이페이지 탭 화면들은 `<transition-overlay target="mypage_tab_content" fallback-target="main_content_area">` 로 탭 본문만 덮는다. 탭 화면마다 같은 id 로 둔다.
- **앵커에는 넘겨지는 값이 없다.** 확장점과 달리 노드 id 앵커는 props 를 넘기지 않는다. 붙은 조각은 그 화면의 데이터소스와 상태를 직접 읽는다. 화면별로 조각이 기댈 수 있는 것은 아래와 같다.

| 화면 | 데이터소스 | 폼 상태와 오류 | 그 밖 |
| --- | --- | --- | --- |
| `auth/register` | `registerFields`(가입 항목), `termsContent`, `privacyContent`(약관 본문, 페이지 모듈이 켜져 있을 때만) | `_local.registerForm`(가입 요청 본문으로 그대로 나간다), `_local.errors`, `_local.registerError`, `_local.isRegistering` | 주소의 `query.verification_token` |
| `mypage/profile` | `user`(`GET /api/me`), `profileFields`(`GET /api/me/fields`) | 없음(보기 화면) | 탈퇴 확인 창 `withdrawModal` |
| `mypage/profile-edit` | `user`, `profileFields` | `_local.profileEditForm`(저장 요청 본문으로 그대로 나간다), `_local.profileErrors`, `_local.isSavingProfile`, `_local.isPasswordVerified` | 저장하면 `user`, `current_user` 를 다시 받는다 |

가입 폼과 내 정보 수정 폼은 폼 상태를 통째로 요청 본문으로 보낸다. 조각이 이름(`name`)을 가진 입력칸을 폼 안에 붙이면 그 값은 요청에 함께 실리고, 서버에서 받아 저장하는 것은 그 확장의 훅 몫이다. 다만 **확장이 받을 값이 회원 한 사람에게 딸린 값이면 조각을 붙이지 말고 회원 항목으로 선언한다**([field-sets.md](field-sets.md)). 선언한 항목은 가입, 내 정보, 관리자 회원 화면에 한 번에 나타나고 운영자가 사용 여부와 필수 여부를 정할 수 있다. 번들 확장의 게시판 알림 수신, 이커머스 결제 통화와 배송 국가, 마케팅 동의는 조각에서 회원 항목 선언으로 옮겨졌다. 그래서 가입 폼의 두 앵커와 `profile_bio_card` 에는 지금 번들 조각이 없다.

이 밖에 사용자 템플릿이 열어 두어야 하는 확장점(로그인, 가입 화면의 `auth_login_extensions`, `auth_register_extensions` 등)은 [layout-extensions.md 의 표](layout-extensions.md#템플릿이-열어-두어야-하는-확장점)에 있다.

## 회원 항목 확장점 2종

확장은 회원 항목의 **유형**을 더할 수 있다(확장의 `getFieldTypes()` 또는 필터 `core.field.filter_types`. [field-sets.md](field-sets.md)). 코어가 모르는 유형은 템플릿의 입력 컴포넌트가 그릴 수 없으므로, 템플릿은 두 확장점을 열어 그 확장의 조각이 직접 그리게 한다. 번들 확장 가운데 유형을 더한 것은 아직 없다.

### `member_field_input`: 확장 유형의 입력칸

항목 계약의 `renderer` 가 `extension` 인 항목 자리에 연다. 그 유형을 더한 확장이 이 확장점에 입력 조각을 붙인다.

| 놓인 곳 | 템플릿 |
| --- | --- |
| 회원 항목 공용 조각 `partials/member/_member_fields` | 사용자(`sirsoft-basic`), 관리자(`sirsoft-admin_basic`) |
| 소셜 가입 창, SSO 동의 창 오버라이드(공용 조각을 옮겨 적은 구획) | 사용자 |
| 게시판 비회원 입력 조각 `partials/board/_guest_fields`, 주문서 추가 입력 조각 `partials/shop/_checkout_extra_fields` | 사용자 |

넘기는 값(조각에서는 `{{extensionPointProps.이름}}` 으로 읽는다):

| 이름 | 값 |
| --- | --- |
| `field` | 그 항목의 계약 전체(`key`, `name`, `type`, `label`, `required`, `readonly`, `constraints`, `default` 등) |
| `error` | 이 칸의 검증 오류 문구 한 줄. 없으면 빈 문자열 |
| `disabled` | 제출 중이면 true |
| `formKey` | 값을 써야 할 폼 상태 이름. 가입 `registerForm`, 내 정보 수정 `profileEditForm`, 추가 정보 입력 `requiredInfoForm`, 관리자 회원 폼 `form`, 소셜 가입 창 `_global.socialLoginFieldForm`, SSO 동의 창 `_global.ssoFieldForm` |

조각이 지킬 것:

- 같은 확장점에 여러 확장의 조각이 함께 붙는다. 조각 루트에 `data-if="{{extensionPointProps.field?.type === '자기 유형'}}"` 을 걸어 자기 유형일 때만 그린다.
- 값은 `formKey` 가 가리키는 폼 상태의 `field.name` 경로에 쓴다. 운영자 추가 항목의 `name` 은 `custom_fields.{키}` 꼴이다. 폼 상태 이름을 조각에 박아 두지 않는다. 화면마다 다르다.
- 라벨, 필수 별표, 안내 문구, 잠금 안내, 오류 줄은 호스트 조각이 그린다. 조각은 입력칸만 그린다.
- 반복 자리이므로 조각의 노드 id 는 `field.key` 를 보간해 만든다.

### `member_field_type_options`: 확장 유형의 설정 옵션

관리자 템플릿의 환경설정 회원 탭, 항목 추가와 수정 창(`partials/admin_settings/_modal_member_field_form`)에 있다. 운영자가 고른 유형이 코어 유형이 아닐 때만 「유형 옵션」 구획과 함께 열린다.

| 이름 | 값 |
| --- | --- |
| `field` | 창이 편집 중인 항목 값(`_global.member_field_form_modal.form`) |
| `disabled` | 읽기 전용이거나 저장 중이면 true |

조각은 자기 유형일 때만 그리고, 옵션 값은 `_global.member_field_form_modal.form.constraints.*` 에 담는다. 창의 저장이 이 값을 항목의 제한 값으로 함께 보낸다. 유형 선택 목록 자체는 서버가 준 유형 목록으로 그려지므로 확장이 따로 붙일 것이 없다.

## 회원 항목 그리기 계약

### 세 화면과 데이터소스

| 화면 | 레이아웃 | 데이터소스 id | API | 폼 상태 | 저장 |
| --- | --- | --- | --- | --- | --- |
| 가입 | `auth/register` | `registerFields` | `GET /api/auth/register/fields` (본인인증을 거쳐 왔으면 `?verification_token=`) | `registerForm` | `POST /api/auth/register` |
| 내 정보 보기 | `mypage/profile` | `profileFields` | `GET /api/me/fields` | 없음 | 없음 |
| 내 정보 수정 | `mypage/profile-edit` | `profileFields` | `GET /api/me/fields` | `profileEditForm` | `PUT /api/me` |
| 추가 정보 입력 | `mypage/required-info` | `profileFields` | `GET /api/me/fields?context=required` | `requiredInfoForm` | `PUT /api/me/required-fields` |

- 가입 항목 데이터소스는 가입이 닫힌 사이트에서는 부르지 않는다. 화면이 받은 값으로 폼을 채우므로 화면을 막는(blocking) 방식으로 두지 않는다.
- 내 정보 수정은 값(`user`, `GET /api/me`)과 항목 정의(`profileFields`)를 다른 데이터소스로 받는다. 폼 상태 전체가 저장 본문으로 나가므로 정의를 폼 상태에 섞지 않는다.
- 추가 정보 입력의 데이터소스 id 가 `profileFields` 인 것은 공용 조각의 파일 갈래가 파일을 올리거나 지운 뒤 이 id 를 다시 받기 때문이다. 파일 항목을 그리는 화면은 이 id 를 쓴다.
- 이메일, 비밀번호, 약관과 개인정보 동의는 항목이 아니라 고정 칸이다. 가입 폼 조각이 직접 그린다.

### 공용 조각 `partials/member/_member_fields`

항목 배열을 돌며 라벨, 필수 별표, 입력칸, 잠금 안내, 안내 문구, 오류 줄을 그리는 조각이다. 세 화면이 함께 쓴다. `<include>` 에는 인자가 없으므로 값은 두 길로 넘긴다.

**반복 변수 `memberFieldGroup`**: 부르는 화면이 `data-each="memberFieldGroup in …"` 안에서 조각을 include 한다.

| 키 | 뜻 |
| --- | --- |
| `key` | 묶음 이름. 노드 id 에 쓰인다 |
| `fields` | 항목 계약 배열 |
| `className` | 칸을 늘어놓는 클래스. 생략하면 세로 한 줄(`space-y-4`) |
| `portableClassName` | 좁은 화면에서 쓸 클래스. 생략하면 `className` |
| `includeAgreements` | true 면 약관 묶음 항목(`ui.group` 이 `agreements`)도 그린다. 생략하면 그 항목은 건너뛴다 |
| `title` | 확장별 카드의 제목(조각이 아니라 카드를 그리는 화면이 쓴다) |

**화면의 computed `memberFieldCtx`**: 묶음과 무관한 문맥이다. 조각을 쓰는 화면은 이 이름으로 선언해야 한다.

| 키 | 뜻 |
| --- | --- |
| `formKey` | 폼 상태 이름. 주소 검색 단추가 고른 주소를 쓸 경로와 확장 유형 조각에 넘겨진다 |
| `errors` | 서버가 준 검증 오류(폼 경로를 키로 하는 객체) |
| `disabled` | 제출 중이면 true |

조각이 만드는 노드 id:

| id | 무엇 |
| --- | --- |
| `member_fields_group_{묶음 key}` | 묶음을 감싼 노드 |
| `field_{항목 키}` | 항목 한 줄(라벨부터 오류 줄까지) |
| `mf_error_{항목 키}` | 이 칸의 검증 오류 줄. 입력 컴포넌트의 `errorId` 로도 넘겨 입력칸과 오류 줄을 잇는다 |
| `mf_deferred_{항목 키}` | 이 화면에서 받지 않는 항목의 안내 줄 |
| `mf_files_{항목 키}`, `mf_file_{항목 키}_{파일 id}`, `mf_file_add_{항목 키}` | 파일 갈래의 목록, 파일 한 줄, 파일 추가 단추 |
| `member_field_input_slot_{항목 키}` | 확장 유형 입력칸 확장점 |
| `member_field_address_search_slot_{항목 키}` | 주소 검색 단추 확장점 |
| `mf_agreement_meta_{항목 키}`, `mf_agreement_link_{항목 키}` | 동의 항목의 「(필수)」「(선택)」 표시와 약관 보기 링크 |
| `mf_locked_sso_{키}`, `mf_locked_identity_{키}`, `mf_locked_admin_only_{키}`, `mf_locked_nickname_interval_{키}`, `mf_locked_other_{키}` | 잠긴 항목의 사유 안내. 한 번에 하나만 보인다 |

항목 한 줄은 계약의 값에 따라 갈래가 갈린다.

| 조건 | 그리는 것 |
| --- | --- |
| `deferred` 가 true | 입력칸 없이 안내 줄만(가입 화면의 파일, 이미지 항목. 가입 뒤 내 정보에서 올린다) |
| `renderer` 가 `address` | 우편번호, 기본 주소, 상세 주소 세 칸. 입력 이름은 `parts.zipcode`, `parts.address`, `parts.address_detail`. 잠기지 않았으면 확장점 `address_search_slot` 을 열고, 고른 값을 쓸 경로를 `targetFields` 로 넘긴다 |
| `renderer` 가 `file` | 올라가 있는 파일 목록과 삭제 단추, 개수가 남았으면 파일 추가. 폼 저장과 따로 `POST`, `DELETE /api/me/fields/{키}/file` 로 바로 처리하고 `profileFields` 를 다시 받는다 |
| `renderer` 가 `extension` | 확장점 `member_field_input` |
| 그 밖 | 항목 1개짜리 입력 컴포넌트 `MemberFieldInput`. 유형과 제한 값을 보고 입력칸을 고른다. 값은 `name` 으로 폼에 자동으로 묶인다 |

오류 줄 규칙: 오류 객체의 키가 항목의 `name` 과 같거나, `name.` 으로 시작하거나(여러 개 선택, 기간 같은 묶음 유형의 하위 경로), `parts` 의 값 가운데 하나(주소의 세 칸)이면 그 항목의 오류다. 첫 문구 하나를 `mf_error_{키}` 에 보인다. 어느 항목에도, 화면의 고정 칸에도 속하지 않는 오류는 화면이 폼 위쪽 상자에 모은다(가입 `registerOrphanErrors`, 내 정보 수정 `profileOrphanErrors`, 추가 정보 입력 `requiredInfoOrphanErrors`). 본인인증 만료처럼 확장이 낸 오류가 여기로 온다.

값에 붙는 알림: 계약의 `ui.notice` 에 문장이 오면 보기 화면이 그 항목의 값 아래에 그대로 그린다(서버가 요청 언어로 완성한 글이다. 화면이 문장을 조립하지 않는다). 수정 화면은 같은 내용을 `help` 로 받는다.

잠금: `readonly` 가 true 인 항목은 입력 컴포넌트가 읽기 전용으로 그리고, `locked_reason` 에 따라 안내 한 줄을 붙인다(`sso`, `identity`, `admin_only`, `nickname_interval` 과 `locked_until`, 그 밖은 일반 문구).

### 보기 조각 `partials/member/_member_fields_view`

내 정보 보기 화면이 쓴다. 같은 반복 변수 `memberFieldGroup` 을 받아 라벨과 보기용 값(`display_value`, 없으면 「-」)을 그린다. 여러 줄 글은 줄바꿈을 살리고, 파일 항목은 파일 이름 목록을 그린다. 노드 id 는 `member_fields_view_group_{묶음 key}`, `field_view_{항목 키}`, `mf_view_files_{항목 키}` 다.

### 내 정보 화면의 묶음

내 정보 보기와 수정은 같은 계산으로 항목을 카드에 나눈다. 두 화면 모두 computed `profileFieldGroups` 를 선언한다.

| 묶음 `key` | 들어가는 항목 | 카드(수정 / 보기) |
| --- | --- | --- |
| `basic` | `ui.group` 이 `basic` | 기본 정보 카드 안(이름, 닉네임). 보기 화면은 프로필 카드가 직접 그린다 |
| `contact` | `ui.group` 이 `contact` | `profile_contact_card` / `profile_view_contact_card` |
| `location` | `ui.group` 이 `location` | `profile_location_card` / `profile_view_location_card` |
| `extra` | `ui.group` 이 없고 `owner` 가 `custom`(운영자 추가 항목) | `profile_extra_card` / `profile_view_extra_card` |
| `profile` | `ui.group` 이 `profile`(서명, 자기소개) | `profile_bio_card` / `profile_view_bio_card` |

항목이 없는 묶음은 목록에서 빠지고 그 카드는 그려지지 않는다.

## 확장별 카드

모듈과 플러그인이 선언한 회원 항목(`owner_type` 이 `module` 또는 `plugin`)은 위 묶음에 넣지 않고 확장마다 카드 하나로 그린다. 두 화면 모두 computed `profileExtensionGroups` 를 선언한다.

| 묶음의 키 | 값 |
| --- | --- |
| `key` | `ext_{확장 식별자}` |
| `title` | 그 확장 항목의 `source_label`(확장 이름). 없으면 확장 식별자 |
| `fields` | 그 확장이 선언한 항목 |
| `includeAgreements` | true(동의 항목도 카드 안에 함께 그린다) |

| 화면 | 카드 노드 id | 놓이는 곳 |
| --- | --- | --- |
| 내 정보 수정 | `profile_extension_card_{묶음 key}` (예: `profile_extension_card_ext_sirsoft-board`) | `profile_bio_card` 뒤, 저장 단추 앞. 폼 안이라 값이 저장 요청 한 번에 함께 간다 |
| 내 정보 보기 | `profile_view_extension_card_{묶음 key}` | `profile_view_bio_card` 뒤, 탈퇴 구획 앞 |

번들 확장이 선언한 항목(게시판의 알림 수신 넷, 이커머스의 결제 통화와 배송 국가, 마케팅 플러그인의 동의 항목)이 이 카드로 나온다. 선언한 확장이 없거나 꺼져 있으면 카드가 없다. 확장의 값이 화면 전역 상태에도 쓰이면(결제 통화 등) 그 확장이 로그인 사용자(`current_user`)가 다시 받아지는 것을 지켜보다 맞춘다. 템플릿은 저장 뒤 `current_user` 를 다시 받기만 하면 되고 확장의 키를 알 필요가 없다.

## 가입 화면의 동의 구획

확장이 선언한 동의 항목은 항목 계약의 `ui.group` 이 `agreements` 다. 가입 화면은 이 항목을 회원 항목 묶음에서 빼고(공용 조각이 `includeAgreements` 없는 묶음에서 건너뛴다), 약관과 개인정보 동의 줄 바로 아래에 따로 묶어 그린다.

- 구획 노드 id 는 `register_extension_agreements` 다. `register_privacy_agreement` 뒤에 있고, 받은 항목에 `ui.group` 이 `agreements` 인 것이 하나도 없으면 그려지지 않는다.
- 구획 안에서 묶음 `{ key: 'agreements', className: 'space-y-1', includeAgreements: true, fields: 동의 항목만 }` 으로 공용 조각을 부른다.
- 값은 다른 항목과 같이 `registerForm` 의 `name` 경로로 가입 요청에 실린다.

공용 조각이 동의 항목에서 읽는 `ui` 값:

| 키 | 뜻 | `sirsoft-basic` 의 처리 |
| --- | --- | --- |
| `ui.group` | `agreements` 면 동의 항목 | 동의 구획(가입)이나 확장별 카드(내 정보)에서만 그리고, 줄 아래 `mf_agreement_meta_{키}` 에 「(필수)」 또는 「(선택)」을 글자로 보인다(체크박스 옆 문구에는 별표가 없다) |
| `ui.parent` | 상위 동의 항목의 키(채널 동의는 전체 동의의 키) | 값이 있으면 그 줄을 들여 쓴다 |
| `ui.role` | `master`(전체 동의), `channel`(수신 채널), `legal`(제3자 제공, 정보 공개 등) | 읽지 않는다. 전체 동의를 켜면 채널을 함께 켜는 식의 연동을 넣을 템플릿이 쓰는 값이다 |
| `ui.link` | 약관 문서 링크. `label`, `title`, `url`, `content_api`, `requires_module` | `url` 이 있고 `requires_module` 이 비었거나 그 모듈이 켜져 있으면(`_global.activeModules`) 새 창 링크 `mf_agreement_link_{키}` 를 그린다. 링크 글자는 `label`, 접근 가능한 이름은 `title`(없으면 항목 라벨) |

## 정의 버전과 409 처리

항목 응답에는 정의 버전 `fields_version` 이 함께 온다. 운영자가 가입 항목을 바꾸면 값이 달라진다.

- 가입 화면은 받은 `fields_version` 을 **가입 요청 본문에 실어 보낸다**(`registerFields.data.fields_version`). 본인인증을 거쳐 왔으면 주소의 `verification_token` 도 함께 싣는다.
- 화면이 낡았으면(실어 보낸 버전이 지금 버전과 다르면) 서버는 검증보다 먼저 409 로 답하고 본문의 `error_code` 가 `fields_changed` 다.
- 409 `fields_changed` 를 받으면 가입 항목 데이터소스를 다시 받고 안내 문구(`member.fields.signup_fields_changed`)만 띄운다. **입력한 값은 지우지 않는다.** 칸별 오류는 비운다.
- 그 밖의 실패는 응답의 `errors` 를 칸별로 붙이고 `message` 를 폼 위쪽 상자에 보인다.
- 내 정보 수정(`PUT /api/me`)과 추가 정보 입력(`PUT /api/me/required-fields`)은 버전을 싣지 않는다.

## 표시 이름 선택 칸

가입과 내 정보의 항목 응답에는 `display_name` 묶음이 함께 온다(`data.display_name`). 다른 회원에게 어떤 이름으로 보일지 회원이 고르는 칸이 이 묶음을 쓴다. 종류와 영역, 서버 쪽 규칙은 [display-name.md](display-name.md) 에 있다.

- `can_choose` 가 true 일 때만 칸을 그린다(고를 권한이 있고 고를 종류가 둘 이상일 때). 노드 id 는 가입 `register_display_name_field`, 내 정보 수정 `profile_display_name_field` 다.
- 선택지는 빈 값(고르지 않음. 회원 기본 종류 `default_kind_label` 로 보인다) 하나에 `options[]` 의 `value`, `label` 을 잇는다. 고른 값은 요청의 `display_name_kind` 로 보낸다. 폼 자동 바인딩을 쓰지 않고 바꿀 때 폼 상태에 직접 쓴다.
- 칸 아래 한 줄은 고른 종류로 보일 이름(`options[].preview`)이다. 가림 규칙은 서버 한 곳에 있으므로 화면이 흉내 내지 않는다. 종류를 바꿀 때 입력해 둔 값으로 항목 API 를 다시 불러(`preview_name`, `preview_nickname`, 가입은 `preview_email` 도) 받은 미리보기로 바꾼다. 글자를 칠 때마다 부르지 않는다. 가입 화면의 첫 미리보기는 표본 회원으로 계산한 값이다(`preview_is_sample`).
- 고른 종류의 `requires_nickname` 이 true 면 닉네임을 입력해야 한다고 알린다. 비워 보내면 서버가 닉네임 칸에 오류를 준다.
- 내 정보 수정에서 `value_unavailable` 이 true 면(전에 고른 종류가 허용에서 빠졌다) 다시 골라 달라고 알리고 `unavailable_kind.label` 을 보인다.
- 내 정보 수정의 저장 본문에는 `display_name_kind` 를 가려 싣는다. `can_choose` 가 아니면 싣지 않고, 값이 지금 `options` 에 없으면(빈 값은 제외) 싣지 않는다. 허용되지 않는 종류를 보내면 서버가 저장 전체를 거절한다.
- `display_name_kind` 의 오류는 이 칸 아래에 보이고 폼 위쪽 상자에서는 뺀다.
- 헤더와 모바일 메뉴의 이름은 로그인 사용자 응답의 `display_name` 을 쓴다. 저장 뒤 `current_user` 를 다시 받으면 새로고침 없이 바뀐다.

## 본인인증 채움과 잠금

본인인증을 거쳐 가입 화면에 오면 주소에 `verification_token` 이 남는다. 가입 화면은 이 토큰을 항목 API 에 넘기고, 응답 최상위의 `verified_claims`(`status`, `filled`, `locked`. 토큰이 없으면 null)를 읽는다.

| `verified_claims.status` | 화면이 하는 일 |
| --- | --- |
| `applied` | `filled` 와 `locked` 에 든 항목의 `default` 를 폼 값으로 넣는다. `name` 이 `custom_fields.` 로 시작하면 그 아래에 넣는다 |
| `expired`, `invalid` | 아무 칸도 채우거나 잠그지 않고, 칸에 속하지 않는 오류로 폼 위쪽 상자에 알린다 |

- 글 입력칸은 폼 값만 보여 주므로 `default` 를 폼 값으로 넣어야 화면에 보인다.
- 「인증 값으로 채우고 잠금」 항목은 계약이 `readonly: true`, `locked_reason: "identity"` 로 온다. 서버가 인증 값으로 덮어쓰므로 화면이 무엇을 보내든 인증 값이 저장된다.
- 잠그지 않은 채움 항목은 화면이 보낸 값이 저장된다. 폼 값으로 넣지 않으면 빈 값이 저장된다.
- 내 정보에서도 본인인증으로 잠긴 항목은 `readonly: true`, `locked_reason: "identity"` 로 온다. 다른 값을 보내면 서버가 422 로 거절한다.

## 추가 정보 입력 유도

운영자가 「기존 회원에게도 입력 요구」를 켠 필수 항목이 비어 있는 회원은, 채울 때까지 쓰기 요청이 거부된다. 템플릿은 그 회원을 추가 정보 입력 화면으로 이끄는 세 길을 모두 갖춰야 한다.

### 1. 로그인 직후

로그인을 마친 응답(`POST /api/auth/login` 과 2단계 인증, 로그인 인계, 비밀번호 변경 단계의 응답)의 `data.pending_required_fields`(항목 키 배열)가 비어 있지 않으면, 원래 가려던 곳을 `redirect` 로 실어 `/mypage/required-info` 로 보낸다. 이미 그 화면으로 가는 중이면 다시 감싸지 않는다.

### 2. 화면에 들어올 때

사용자 화면 공통 레이아웃(`_user_base`)이 맡는다.

- 초기화에서 전역 `_global.requiredInfoGate` 에 `{ from, exempt }` 를 적는다. `from` 은 지금 주소(경로와 쿼리), `exempt` 는 이 화면이 유도 제외 화면인지다.
- 로그인 사용자 데이터소스 `current_user`(`GET /api/auth/user`)를 받은 뒤, 응답의 `pending_required_fields` 가 비어 있지 않고 `requiredInfoGate.from` 이 있으며 `exempt` 가 false 면 `/mypage/required-info?redirect={from}` 으로 바꿔 보낸다(뒤로 가기 이력을 남기지 않는다).
- `sirsoft-basic` 의 유도 제외 화면: `/mypage/required-info`, `/mypage/profile`, `/mypage/change-password`, `/identity/challenge`, `/identity/verify`, `/login`, `/register`, `/forgot-password`, `/reset-password`, 그리고 `/page/` 아래의 문서 화면. 내 정보 수정(`/mypage/profile/edit`)은 제외가 아니다.

### 3. 쓰기가 거부됐을 때

서버는 필수 항목이 비어 있는 회원의 쓰기 요청을 403 으로 거부하고 본문에 `error_code: "required_fields_pending"` 과 `data.pending_required_fields`, `data.fields`(키와 라벨)를 싣는다. 템플릿은 `template.json` 의 템플릿 수준 오류 처리에서 이 응답을 가려 안내 창을 연다.

```json
"errorHandling": {
    "403": {
        "handler": "conditions",
        "conditions": [
            {
                "if": "{{error.error_code === 'required_fields_pending'}}",
                "then": { "handler": "openModal", "target": "required_info_pending_modal" }
            },
            {
                "then": { "handler": "toast", "params": { "type": "error", "message": "{{error.message}}" } }
            }
        ]
    }
}
```

- 안내 창 `required_info_pending_modal` 은 공통 레이아웃에 실어 어느 화면에서든 열리게 한다(`sirsoft-basic` 은 조각 `partials/_modal_required_info_pending` 을 `_user_base` 의 모달로 둔다).
- 바로 입력 화면으로 보내지 않고 묻는다. 쓰던 글이 있는 화면에서 갑자기 옮겨지지 않게 하려는 것이다. 「입력하러 가기」는 `requiredInfoGate.from` 을 `redirect` 로 실어 `/mypage/required-info` 로 보낸다.
- 차단, 휴면 회원의 403 에는 `error_code` 가 없어 두 번째 갈래(오류 문구 토스트)로 간다.
- 확장이 자기 쓰기 요청에 403 처리를 따로 달면 그 처리가 먼저 걸린다. 확장 화면도 이 안내가 뜨게 하려면 같은 조건을 넣는다.
- 템플릿 자신의 요청도 자기 실패 처리(`<fail>`, `onError`)를 가지면 그 처리가 템플릿 수준보다 먼저라 위 갈래에 닿지 않는다. 회원이 보내는 쓰기 요청의 실패 처리에는 맨 앞에 `<open-modal if="{{error.error_code === 'required_fields_pending'}}" target="required_info_pending_modal"/>` 을 넣고, 오류 알림에는 반대 조건(`error.error_code !== 'required_fields_pending'`)을 단다. 로딩 표시를 끄는 상태 정리는 조건 없이 둔다(`sirsoft-basic` 의 게시판, 장바구니, 주문서, 배송지, 상품 문의 요청이 이렇게 한다).

### 추가 정보 입력 화면 `mypage/required-info`

- `GET /api/me/fields?context=required` 는 그 회원에게 비어 있는 필수 항목만 준다(없으면 빈 배열). 항목을 받았는데 비어 있으면 입력 없이 곧바로 목적지로 보낸다.
- 묶음 `{ key: 'required', includeAgreements: true, fields }` 로 공용 조각을 부른다. 비어 있던 칸만 받으므로 비밀번호 재확인을 거치지 않는다.
- 저장은 폼 값을 항목의 폼 경로 그대로 `PUT /api/me/required-fields` 로 보낸다. 성공하면 `current_user` 를 다시 받고, 응답의 `data.pending_required_fields` 가 비었으면 목적지로, 남았으면(파일 항목처럼 이 요청으로 채우지 못한 것) 화면에 남아 항목을 다시 받는다.
- 목적지는 주소의 `redirect` 가 사이트 안 경로일 때만 그 값이고(`/` 로 시작하고, `//` 나 `/\` 로 시작하지 않으며, 이 화면 자신이 아님) 아니면 홈이다. 바깥 주소로 보내는 통로가 되지 않게 이 검사를 빼지 않는다.
- 이 화면에서 나가는 길은 저장과 로그아웃이다.

## 소셜 로그인, SSO 가입 창 오버라이드

소셜 로그인 플러그인의 가입 완료 창과 SSO 플러그인의 첫 로그인 동의 창은 원래 이름, 닉네임(또는 약관)만 받는다. `sirsoft-basic` 은 두 조각을 자기 판본으로 대체해([템플릿 오버라이드](layout-extensions.md#템플릿-오버라이드)) 창 안에 코어 가입 화면과 같은 회원 항목을 그린다.

| 판본 | 대체하는 원본 | 대상 화면 | 버전 제약 |
| --- | --- | --- | --- |
| `extensions/sirsoft-social_login/auth_register_social_modals` | 소셜 로그인의 같은 이름 조각 | `auth/register` | `version-constraint="~1.0.0"` |
| `extensions/sirsoft-sso/auth-login-sso-modals` | SSO 의 같은 이름 조각 | `auth/login` | `version-constraint="~1.0.0"` |

두 판본이 하는 일:

- 가입 화면과 같은 API 에서 항목을 받되 대기 토큰을 함께 넘긴다(`GET /api/auth/register/fields?pending_source={플러그인 식별자}&pending_token={토큰}`). 서버가 제공자나 회사 계정이 준 값을 항목의 `default` 로 채워 주고, SSO 는 회사 계정이 관리하는 칸을 `readonly: true`, `locked_reason: "sso"` 로 준다.
- 받은 `fields_version` 을 가입 완료(또는 동의 완료) 요청에 싣는다. 409 `fields_changed` 면 안내만 띄우고 항목과 버전을 다시 받는다. 입력한 값과 대기 상태는 그대로다.
- 항목을 받지 못하면 원본처럼 그리고 버전 없이 보낸다.

**`fields_version` 이 있고 없음에 따라 서버의 처리가 갈린다**(각 플러그인의 API 문서).

| 요청 | 서버가 거는 규칙 | 만들어지는 회원 |
| --- | --- | --- |
| `fields_version` 있음 | 운영자가 정한 가입 항목 규칙(필수 포함) | 받은 항목 값을 저장한다 |
| `fields_version` 없음 | 그 플러그인의 고정 규칙만(종전과 같다) | 운영자가 필수로 둔 다른 항목은 건너뛴 채 만들어지고, 그 회원이 사용자 화면에 처음 로그인하면 추가 정보 입력 화면이 빠진 항목을 받는다 |

그래서 이 오버라이드를 두지 않는 템플릿에서도 소셜, SSO 가입은 동작한다. 다만 가입한 회원이 첫 로그인 뒤 추가 정보 입력 화면을 한 번 더 거치므로, [추가 정보 입력 유도](#추가-정보-입력-유도)는 반드시 갖춰야 한다.

**공용 조각을 고치면 두 판본도 함께 고친다.** 확장 조각은 `<include>` 를 풀지 않으므로, 두 판본은 공용 조각 `partials/member/_member_fields` 의 항목 반복 구획을 파일 안에 옮겨 적었다(창마다 두 번: 항목 묶음, 약관 묶음). 공용 조각만 고치면 두 창은 낡은 구획을 그린다. `sirsoft-basic` 은 옮겨 적은 네 벌이 공용 조각과 같은 구조인지 대조하는 테스트로 이를 잠근다. 옮겨 적으면서 달라진 것은 다섯 가지뿐이다.

| 차이 | 까닭 |
| --- | --- |
| 화면 문맥(`memberFieldCtx`)의 오류, 비활성, 폼 이름이 창의 전역 상태 이름으로 바뀜 | 확장 조각은 computed 를 선언하지 못하고, 모달은 화면 지역 상태의 낡은 값을 본다 |
| 노드 id 와 오류 줄 id 에 `social_`, `sso_` 접두가 붙음 | 가입 화면 본문의 같은 칸과 id 가 겹치지 않게 |
| 주소 검색 단추 자리(`address_search_slot`)가 없음 | 검색 단추는 고른 주소를 화면 지역 상태에 쓰는데 창의 폼 값은 전역 상태다 |
| 주소 칸의 읽기 전용이 잠금만 따름 | 검색 단추가 없으니 직접 적어야 한다 |
| 파일 갈래가 없음 | 가입 표면의 파일 항목은 늘 `deferred` 라 안내 줄만 나온다 |

원본 조각을 고칠 때도 같다. 원본을 옮겨 적은 판본은 원본을 따라가지 않으므로, 플러그인을 올린 뒤에는 원본과 대조해 판본을 고치고 버전 제약을 넓힌다. 플러그인 버전이 제약을 벗어나면 코어가 판본 대신 원본을 그리고, 그때의 요청은 버전 없는 요청이 되어 위 표의 둘째 줄로 처리된다.

## 새 사용자 템플릿 점검 목록

앵커와 확장점:

1. `auth/register` 에 `register_extension_fields`, `register_privacy_agreement` 가 있는가.
2. `mypage/profile-edit` 에 `profile_bio_card`, `mypage/profile` 에 `profile_view_bio_card` 가 있는가. 조건으로 감춰도 노드는 트리에 남는가.
3. 마이페이지 탭 화면마다 `mypage_tab_content` 가 있는가.
4. 회원 항목을 그리는 조각에 확장점 `member_field_input` 이 있고 `field`, `error`, `disabled`, `formKey` 를 넘기는가. 주소 항목에 `address_search_slot` 이 있는가.
5. [layout-extensions.md 의 사용자 템플릿 확장점 표](layout-extensions.md#템플릿이-열어-두어야-하는-확장점)에 있는 나머지 자리가 있는가.

회원 항목:

6. 가입, 내 정보 보기와 수정, 추가 정보 입력이 칸을 직접 적지 않고 항목 API 의 계약을 돌며 그리는가.
7. `deferred`, `renderer`(`address`, `file`, `extension`), `readonly` 와 `locked_reason`, `required`, `help` 를 모두 처리하는가.
8. 오류를 항목의 `name`, 하위 경로, `parts` 로 찾아 칸 아래에 붙이고, 남는 오류를 폼 위쪽에 모으는가.
9. 내 정보에 확장별 카드가 있는가(`owner_type` 이 `module`, `plugin` 인 항목). 동의 항목을 그 카드에서 함께 그리는가.
10. 가입 화면에 동의 구획이 있고(`ui.group` 이 `agreements`), 필수와 선택 표시, `ui.parent` 들여쓰기, `ui.link` 링크를 그리는가.
11. 가입 요청에 `fields_version` 을 싣고, 409 `fields_changed` 에서 입력값을 지우지 않고 항목을 다시 받는가.
12. 표시 이름 선택 칸을 `display_name.can_choose` 일 때만 그리고, 미리보기를 서버에서 받는가.
13. 가입 화면이 `verification_token` 을 항목 API 에 넘기고 `verified_claims` 대로 폼을 채우는가.

유도:

14. 로그인 응답의 `pending_required_fields` 를 보고 추가 정보 입력 화면으로 보내는가.
15. 공통 레이아웃이 `current_user` 를 받은 뒤 유도 제외 화면이 아니면 바꿔 보내는가. 제외 화면 목록에 추가 정보 입력 화면 자신, 로그인 전 화면, 본인인증 화면, 약관 문서 화면이 들어 있는가.
16. `template.json` 에 403 `required_fields_pending` 처리와 안내 창이 있는가.
17. 추가 정보 입력 화면이 `redirect` 를 사이트 안 경로로만 받는가.

오버라이드와 실측:

18. 소셜 로그인, SSO 가입 창을 대체했다면 `version-constraint` 를 적었는가. 공용 항목 조각을 고칠 때 판본을 함께 고치는가.
19. 번들 확장을 켠 상태로 가입, 내 정보 보기와 수정을 브라우저로 열어 확장 카드(본인인증, 소셜 계정, 회사 계정, 개인정보, 마일리지, 배송지)와 확장별 항목 카드가 모두 보이는지 확인한다.

## 관련 문서

- [admin-template-contract.md](admin-template-contract.md): 관리자 템플릿 교체 계약
- [layout-extensions.md](layout-extensions.md): 확장점, 조각 등록, 템플릿 오버라이드
- [field-sets.md](field-sets.md): 회원 항목 선언, 유형 추가
- [display-name.md](display-name.md): 표시 이름 종류와 영역
- [template-basics.md](template-basics.md): 템플릿 타입, 메타데이터
- [가입 API](../backend/api/auth.md), [내 정보 API](../backend/api/me.md), [본인인증 API](../backend/api/identity.md): 항목 계약과 요청, 응답
