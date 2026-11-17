# 첨부 업로더 확장점 (Attachment Uploader)

> 코어 7.2.0. 첨부 목록 업로더(드롭존·미리보기·진행률·삭제·정렬)를 **플러그인 하나로 모든 첨부 화면에서 바꿀 수 있게** 하는 확장점과
> 그 계약입니다. 바꾸는 것은 UI 와 업로드 방식이고, 저장 흐름(저장 버튼이 업로드를 먼저 시키고 결과를 읽는 순서)은 그대로입니다.

## TL;DR (5초 요약)

```text
1. 번들 화면의 첨부 업로더는 모두 확장점 attachment_uploader 안의 기본 업로더(default)다 — 주입이 없으면 지금 화면 그대로
2. 화면은 prop uploader(계약 객체, 버전 1)를 기존 값(업로드 주소·정책·초기 파일·업로드 신호 이름)으로 조립해 넘긴다
3. 업로더 플러그인은 mode="replace" 로 주입하고 {{extensionPointProps.uploader}}·{{extensionPointCallbacks.*}} 를 읽는다
4. 저장 흐름이 보내는 업로드 신호(triggerEvent)를 G7Core.componentEvent.on 으로 받아 기본 업로더와 같은 결과 모양을 돌려준다
5. 콜백(onUploadComplete·onRemove·…)은 G7Core.dispatch(액션, { componentContext: { data: { $event } } }) 로 실행한다
6. 아바타 업로더 2곳과 템플릿 설정 화면(TSX) 2곳은 대상이 아니다 — 사유는 「대상이 아닌 업로더」
```

## 목차

- [왜 확장점인가](#왜-확장점인가)
- [계약 형식 (`uploader`)](#계약-형식-uploader)
- [콜백](#콜백)
- [화면이 확장점을 두는 법](#화면이-확장점을-두는-법)
- [업로더 플러그인 규약](#업로더-플러그인-규약)
- [확장점이 놓인 자리](#확장점이-놓인-자리)
- [대상이 아닌 업로더](#대상이-아닌-업로더)
- [기본 업로더의 주소 해석](#기본-업로더의-주소-해석)
- [옛 템플릿](#옛-템플릿)
- [관련 문서](#관련-문서)

## 왜 확장점인가

첨부 화면들은 업로더 컴포넌트(`FileUploader`)를 레이아웃에 직접 두고 업로드 주소를 적어 왔습니다. 그러면 대용량 분할 업로드,
외부 미디어 라이브러리 선택, 다른 모양의 드롭존 같은 업로더를 쓰려는 플러그인은 화면마다 레이아웃을 고쳐야 했습니다.
확장점은 **자리**를, 계약 객체는 **그 자리가 무엇을 받는지**를 정합니다. 화면은 이미 가진 값으로 계약을 채우므로 새 API 가 없고,
기본 업로더는 그대로 default 에 남아 플러그인이 없을 때의 화면은 바뀌지 않습니다.

## 계약 형식 (`uploader`)

타입은 코어 `resources/js/core/attachmentUploader/types.ts` 에 있습니다(타입만 있고 런타임 코드는 없습니다).

```ts
interface AttachmentUploaderContract {
  version: 1;
  mode: 'upload' | 'select';           // select = 파일만 고르고 화면 핸들러가 제출 때 올린다(클레임 신청·리뷰 작성)
  endpoints: { upload: string; delete: string; reorder: string | null } | null; // select 는 null
  fields: Record<string, string | number>;  // 업로드 요청에 함께 싣는 값(temp_key, device 등)
  policy: {
    accept: string;                    // `<input accept>` 형식 목록, 빈 문자열이면 제한 없음
    maxSize: number | null;            // 파일 하나의 상한(MB), null 이면 업로더가 거르지 않는다
    maxFiles: number | null;           // null 이면 제한 없음
    maxConcurrentUploads: number;      // 1 이면 고른 순서대로 하나씩
    imageCompression: Record<string, unknown> | null;
  };
  collection: string;                  // 업로드 요청의 collection
  attachmentable: { type: string | null; id: number | string | null };
  triggerEvent: string | null;         // 저장 흐름이 보내는 업로드 신호 이름, null 이면 신호 없음(자동 업로드·선택형)
  initialFiles: AttachmentItem[];      // 화면에 처음 보일 기존 첨부
  disabled: boolean;
  autoUpload: boolean;                 // false 면 triggerEvent 신호를 받을 때 올린다
  ui?: { confirmBeforeRemove?: boolean; enablePrimarySelection?: boolean; primaryFileId?: string | number | null };
  context: { owner: string; purpose: string }; // 예 { owner: 'sirsoft-page', purpose: 'page_attachments' }
}

interface AttachmentUploaderResult {   // triggerEvent 리스너가 돌려주는 값 — 저장 흐름은 _eventResult.data[i] 로 읽는다
  uploadedAttachments: AttachmentItem[];
  existingFiles: AttachmentItem[];
  allFiles: AttachmentItem[];          // 기존 + 새로 올린 것, 화면 순서
  failedCount: number;                 // 0 보다 크면 저장 흐름이 저장을 멈춘다
}
```

- `endpoints.delete` 의 `:id` 는 첨부 id 로 바꿔 부릅니다. 빈 문자열이면 서버 삭제 없이 화면에서만 뺍니다(배너·팝업은 「저장」 때 바뀝니다).
- `endpoints.reorder` 가 `null` 이면 정렬 요청을 보내지 않습니다. 새 글·새 상품처럼 아직 저장되지 않은 화면과, 정렬 API 가 없는 화면(카테고리·반응 아이콘·배너·팝업·클레임)이 그렇습니다.
- `context.owner` 는 화면을 소유한 확장 식별자(관리자 환경설정은 `core`), `purpose` 는 그 안의 자리 이름입니다. 업로더가 자리마다 모양을 달리하고 싶을 때 읽습니다.
- 모르는 `version` 을 받으면 업로더는 주입하지 않은 것처럼 비켜야 합니다(아래 규약).

## 콜백

화면은 기본 업로더의 이벤트와 **같은 액션**을 `<callback>` 으로 넘깁니다. 콜백의 `$event` 는 기본 업로더가 같은 이벤트에 넘기던 첫 인자와 같습니다.

| 이름 | `$event` | 하는 일(번들 화면) |
| --- | --- | --- |
| `onUploadComplete` | 이번에 올린 첨부 배열 | 폼 값에 덧붙이기·교체, 완료 알림 |
| `onRemove` | 뺀 첨부의 `hash`(없으면 `id`) | 폼 값에서 걸러 내기, 삭제 알림 |
| `onReorder` | 새 순서의 기존 첨부 배열 | 폼 값 순서 덮어쓰기 |
| `onFilesChange` | 업로드형은 대기 파일 배열, 선택형은 `{ target: { files, value } }` | 「변경 있음」 표시, 선택형은 고른 파일 담기 |
| `onUploadError` | 사람이 읽는 오류 문장 | 오류 알림, 저장 중 표시 풀기 |
| `onDeleteError` | 사람이 읽는 오류 문장 | 삭제 실패 알림 |
| `onPrimaryChange` | 대표로 고른 첨부의 `hash` | 대표 이미지 지정(상품 이미지) |

화면이 받지 않는 이벤트의 콜백은 없습니다 — 있는 것만 부르면 됩니다.

## 화면이 확장점을 두는 법

```html
<!-- 표시 조건(data-if)은 기본 업로더가 아니라 확장점 노드(또는 그 위)에 둔다 — replace 때 default 가 걷히므로 그 조건도 함께 사라진다 -->
<extension-point name="attachment_uploader" data-ref="file_uploader_slot" class="contents">
  <prop name="uploader">
    <field name="version" :value="1"/>
    <field name="mode" value="upload"/>
    <field name="endpoints">
      <field name="upload" value="/api/modules/vendor-module/admin/attachments"/>
      <field name="delete" value="/api/modules/vendor-module/admin/attachments/:id"/>
      <field name="reorder" value="{{route?.id ? '/api/modules/vendor-module/admin/attachments/reorder' : null}}"/>
    </field>
    <field name="fields">
      <field name="temp_key" value="{{_local.tempKey}}"/>
    </field>
    <field name="policy">
      <field name="accept" value=".jpg,.png,.pdf"/>
      <field name="maxSize" :value="10"/>
      <field name="maxFiles" :value="5"/>
      <field name="maxConcurrentUploads" :value="1"/>
      <field name="imageCompression" :value="null"/>
    </field>
    <field name="collection" value="attachments"/>
    <field name="attachmentable">
      <field name="type" :value="null"/>
      <field name="id" :value="null"/>
    </field>
    <field name="triggerEvent" value="upload:vendor_attachments"/>
    <field name="initialFiles" value="{{_local.form?.attachments ?? []}}"/>
    <field name="disabled" value="{{!!_computed.isReadOnly}}"/>
    <field name="autoUpload" :value="false"/>
    <field name="context">
      <field name="owner" value="vendor-module"/>
      <field name="purpose" value="attachments"/>
    </field>
  </prop>
  <callback name="onUploadComplete">
    <set-state target="local" form.attachments="{{[...(_local.form?.attachments ?? []), ...$event]}}"/>
  </callback>
  <default>
    <file-uploader data-type="composite" data-ref="file_uploader" collection="attachments" … uploadTriggerEvent="upload:vendor_attachments">
      <on event="onUploadComplete">
        <set-state target="local" form.attachments="{{[...(_local.form?.attachments ?? []), ...$args[0]]}}"/>
      </on>
    </file-uploader>
  </default>
</extension-point>
```

- `class="contents"` 로 확장점 상자를 만들지 않아 기존 레이아웃 간격이 그대로입니다.
- 기본 업로더는 확장점 props 를 받지 못하므로 같은 값을 자기 props 에 **한 번 더** 적습니다(`html_editor` 와 같은 방식). 둘이 갈리면 플러그인 유무에 따라 정책이 달라집니다.
- 저장 버튼의 순서(`emit-event` 로 업로드 신호 → `_eventResult` 로 결과 읽기 → 실패가 있으면 저장 중단)는 바꾸지 않습니다. 결과 모양이 같으므로 대체 업로더에서도 그대로 동작합니다.
- 기본 업로더의 `data-ref`·이벤트는 그대로 둡니다. 확장점 `data-ref` 는 `{기본 업로더 data-ref}_slot` 으로 짓습니다.
- 끌어 정렬 목록의 행 템플릿(`<sortable>` 의 `<item-template>`) 안에도 확장점을 둘 수 있습니다(7.2.0+).

## 업로더 플러그인 규약

```html
<extension extension-point="attachment_uploader" mode="replace" priority="100">
  <vendor-uploader.dropzone data-type="composite" uploader="{{extensionPointProps.uploader}}" callbacks="{{extensionPointCallbacks}}"/>
</extension>
```

1. **`mode="replace"` 필수.** `append`·`prepend` 면 기본 업로더와 둘이 함께 그려집니다. 한 사이트에 업로더 플러그인은 하나만 켭니다 — replace 가 둘이면 마지막에 적용된 하나만 남습니다.
2. **버전 확인.** `uploader.version` 이 아는 값이 아니면 기본 업로더와 같은 동작을 직접 할 수 없으므로, 그 자리에서는 아무것도 바꾸지 않는 조각 조건(`data-if="{{extensionPointProps.uploader?.version === 1}}"`)을 겁니다. 조건이 거짓이면 자리가 비므로, 지원하지 않는 버전이 오면 그 화면에 업로더가 없다는 점을 문서에 적어 두세요.
3. **업로드 신호 받기.** `triggerEvent` 가 있으면 `G7Core.componentEvent.on(triggerEvent, handler)` 로 구독하고, 핸들러는 [`AttachmentUploaderResult`](#계약-형식-uploader) 모양을 돌려줍니다. 올릴 파일이 없어도 `existingFiles`·`allFiles` 를 채워 돌려줍니다 — 상품 이미지 저장은 `allFiles` 를 이미지 목록으로 씁니다. 실패한 파일 수를 `failedCount` 에 넣어야 저장 흐름이 멈춥니다.
4. **props 를 따라가기.** 저장 뒤 `initialFiles` 가, 화면 이동 뒤 `endpoints`·`fields` 가 바뀝니다. 처음 마운트 때 값을 고정하지 말고 바뀐 값을 씁니다.
5. **콜백 실행.** 엔진을 고치지 않고 아래처럼 실행합니다. 콜백 값은 액션 하나 또는 배열입니다.

```ts
function runCallback(callbacks: Record<string, unknown> | undefined, name: string, event: unknown): void {
  const action = callbacks?.[name];
  if (!action) return;
  for (const one of Array.isArray(action) ? action : [action]) {
    (window as any).G7Core.dispatch(one, { componentContext: { data: { $event: event } } });
  }
}
```

6. **선택형(`mode: 'select'`).** 업로드하지 않고 고른 파일을 `onFilesChange` 에 `{ target: { files, value: files } }` 로 넘깁니다. 화면 핸들러가 `$event.target.files` 를 읽어 제출 요청에 싣습니다.
7. **정책 지키기.** `policy.maxConcurrentUploads` 가 1 인 화면은 서버가 도착 순서로 번호를 매기거나 개수 상한을 잠금 없이 판정합니다. 병렬로 올리면 순서가 바뀌거나 상한을 넘겨 저장됩니다.
8. **업로드 주소만 씁니다.** 브라우저에서 저장소로 바로 올리는 방식(사전 서명 업로드)은 이 계약 범위 밖입니다. 업로드는 `endpoints.upload` 로 보냅니다.

## 확장점이 놓인 자리

21곳입니다. 아래 화면 13개(레이아웃 소스 파일 14개)에 있고, 업로드형 19 · 선택형 2 입니다.

| 화면 | 자리 | 소유 | 형식 | 업로드 신호 | 정렬 |
| --- | --- | --- | --- | --- | --- |
| 관리자 환경설정 > 일반 | 사이트 로고, 파비콘 | 관리자 템플릿 | upload | `upload:site_logo`, `upload:site_favicon` | 코어 첨부 정렬 |
| 관리자 환경설정 > SEO | 기본 OG 이미지 | 관리자 템플릿 | upload | `upload:og_image_default` | 코어 첨부 정렬 |
| 게시판 글쓰기(사용자) | 첨부 | 사용자 템플릿 | upload | `upload:board_attachments` | 수정 화면만 |
| 마이페이지 클레임 신청 | 증빙 사진 | 사용자 템플릿 | select | 없음 | 없음 |
| 마이페이지 리뷰 작성 | 사진 | 사용자 템플릿 | select | 없음 | 없음 |
| 게시판 글쓰기(관리자) | 첨부 | 게시판 | upload | `upload:board_attachments` | 수정 화면만 |
| 게시판 설정 > 반응 유형 | 아이콘(넓은 표의 행·새 행, 좁은 화면 카드의 행·새 행 — 4곳) | 게시판 | upload(즉시) | 없음 | 없음 |
| 쇼핑몰 카테고리 관리 | 카테고리 이미지 | 이커머스 | upload(즉시) | 없음 | 없음 |
| 클레임 상세(관리자) | 운영자 대리 업로드 | 이커머스 | upload(즉시) | 없음 | 없음 |
| 상품 등록·수정 | 상품 이미지(대표 지정) | 이커머스 | upload | `upload:product_images` | 수정 화면만 |
| 페이지 등록·수정 | 첨부 | 페이지 | upload | `upload:page_attachments` | 수정 화면만 |
| 히어로 배너 등록·수정 | PC·휴대폰 영상, PC·휴대폰 이미지(4곳) | 히어로섹션 | upload(즉시) | 없음 | 없음 |
| 팝업 등록·수정 | PC·휴대폰 이미지(2곳) | 팝업 | upload(즉시) | 없음 | 없음 |

## 대상이 아닌 업로더

| 업로더 | 자리 | 사유 |
| --- | --- | --- |
| 아바타 업로더(`AvatarUploader`) | 사용자 템플릿 내 정보 보기·수정의 프로필 사진(2곳) | 첨부 목록이 아니라 사진 한 장을 고르는 즉시 올리고 바로 바꿔 보여 주는 전용 화면입니다. 저장 흐름·업로드 신호·첨부 목록이 없어 이 계약의 결과 모양과 맞지 않습니다 |
| 템플릿 설정 화면의 업로더 | 관리자·사용자 템플릿 설정 화면(`TemplateSettingsFields`, 2곳) | 레이아웃이 아니라 템플릿 코드(TSX)가 업로더를 직접 그립니다. 확장점은 레이아웃 노드라 이 자리에 닿지 않습니다 |

## 기본 업로더의 주소 해석

| 템플릿 | `apiEndpoints` 를 넘기지 않으면 | `reorder` 키 |
| --- | --- | --- |
| 관리자 템플릿 | 코어 첨부 주소(`/api/admin/attachments`, `…/:id`, `…/reorder`) | 키가 있으면 그 값, `null`·빈 문자열이면 정렬 요청 없음. 키가 없을 때만 코어 정렬 주소 |
| 사용자 템플릿 | **기본 주소 없음** — 업로드하면 요청 없이 그 파일을 실패로 두고 `onUploadError` 를 부르며 개발 모드 콘솔에 경고를 남깁니다. 삭제 주소가 없으면 화면에서만 뺍니다 | 없거나 `null`·빈 문자열이면 정렬 요청 없음 |

사용자 화면에는 공용 첨부 API 가 없으므로 업로더를 쓰는 화면은 자기 업로드 주소를 넘겨야 합니다. 정렬 API 가 없는 관리자 화면은 `reorder` 를 `null` 로 명시합니다 — 생략하면 코어 첨부 정렬 주소로 요청이 나갑니다.

## 옛 템플릿

7.1.x 기본 템플릿을 복사해 만든 템플릿에는 템플릿 소유 자리(관리자 환경설정 3곳, 사용자 글쓰기·클레임 신청·리뷰 작성)에 이 확장점이 없습니다.
업로더 플러그인을 켜도 그 화면은 기본 업로더 그대로이고, 오류는 없습니다. 모듈·플러그인 화면은 확장이 소유한 레이아웃이라 함께 바뀝니다.
복사 템플릿에서도 바꾸려면 그 화면의 `<file-uploader>` 를 위 [화면이 확장점을 두는 법](#화면이-확장점을-두는-법)처럼 감쌉니다.

## 관련 문서

- [layout-extensions.md](layout-extensions.md) — 확장점·조각 등록, `extensionPointProps`·`extensionPointCallbacks`
- [content-media.md](content-media.md) — 편집기 본문에 바로 넣는 미디어 업로드 계약(첨부 목록과 따로 센다)
- [admin-template-contract.md](admin-template-contract.md#조각-앵커) — 관리자 템플릿이 열어 두는 자리
