# 본문 미디어 업로드 계약 (Content Media)

> 코어 7.1.5 · 템플릿 엔진 engine-v1.89.0. 편집기 본문에 바로 넣는 이미지·동영상·오디오를 **어느 편집기든 같은 계약으로** 올리고,
> 확장이 자기 저장소·권한·정책으로 받게 하는 계약입니다.

## TL;DR (5초 요약)

```text
1. 화면(확장 레이아웃)이 편집기 확장점 html_editor 에 prop mediaUpload(업로드 계약)를 넘긴다 — 없으면 null
2. 편집기 플러그인은 계약을 읽기만 한다: G7Core.contentMedia 로 판정·문서당 개수 사전 차단·업로드
3. 서버는 확장이 받는다: 정책(ContentMediaPolicy) + 파일 규칙(ContentMediaFile) + 참조 추출·주소 서명(ContentMedia*)
4. 저장 본문에는 서명 없는 정식 주소, 응답에서 서명 주소로 바꾸고(게이트 통과 자격의 위임), 저장 때 서명 쿼리를 뗀다
5. 본문 미디어는 첨부 목록·개수와 따로 센다(문서당 최대 기본 30). 동영상·오디오는 코어 「본문 콘텐츠」 후보도 켜야 받는다
```

## 왜 계약인가

편집기(CKEditor 등)가 업로드 주소·권한을 직접 알면 편집기를 바꿀 때마다 업로드 규칙이 사라지고, 확장(게시판·페이지·쇼핑몰)마다
비밀글·삭제글 게이트를 편집기 안에서 다시 만들어야 합니다. 그래서 **형식은 코어가, 값은 확장이** 정합니다.

- 코어: 계약 형식(`ContentMediaUploadTarget`), 정책 형식(`ContentMediaPolicy`), 검증 규칙, 참조 추출·주소 서명 도구, 클라이언트 런타임
  (`G7Core.contentMedia`), 서빙 응답(`ContentMediaResponder` — Range·보안 헤더).
- 확장: 업로드·서빙 라우트, 저장 행(자기 첨부 테이블), 정책 값(게시판은 게시판별, 쇼핑몰·페이지는 모듈 설정), 게이트(글 읽기 권한·비밀·삭제 등).
- 편집기 플러그인: 계약을 읽어 단추·형식·업로드를 정한다. 계약이 없으면 자기 종전 규칙.

## 계약 형식 (`mediaUpload`)

```ts
interface ContentMediaUploadTarget {
  version: 1;
  endpoint: string;                         // 같은 출처 상대 경로만 (다른 출처면 런타임이 버린다)
  fileField?: string;                       // 기본 'file'
  fields?: Record<string, string | number>; // 함께 보낼 폼 필드 — 화면이 temp_key 를 더한다
  headers?: Record<string, string>;         // 허용 목록: X-Board-Post-Verify-Token
  response: { urlPath: string; idPath?: string; kindPath?: string }; // 점 경로 (예: data.url)
  mediaUrlPattern: string;                  // 서빙 주소 정규식 본문, 명명 그룹 hash (문서당 개수 셀 때)
  policy: {
    image: KindPolicy; video: KindPolicy; audio: KindPolicy;
    maxPerDocument: number;
  };
}
interface KindPolicy { enabled: boolean; maxSizeMb: number; extensions: string[]; mimeTypes: string[] }
```

서버는 `App\Support\ContentMedia\ContentMediaUploadTarget::toArray($endpoint, $policy, $pattern, $fields, $headers)` 로 만듭니다.
`policy` 의 `maxSizeMb` 는 PHP 업로드 상한(`upload_max_filesize`·`post_max_size`)과 비교한 실효값이고, 동영상·오디오는 코어 「본문 콘텐츠」
설정(`content.allow_uploaded_video`·`allow_uploaded_audio`, 기본 꺼짐)이 꺼져 있으면 확장 정책과 관계없이 `enabled=false` 로 내려갑니다.

화면은 폼 메타 등으로 받은 계약에 글쓰기 임시 키를 더해 넘깁니다.

```html
<extension-point name="html_editor"
  mediaUpload="{{form_meta?.data?.content_media ? Object.assign({}, form_meta.data.content_media, { fields: Object.assign({}, form_meta.data.content_media.fields ?? {}, { temp_key: _local.tempKey }) }) : null}}"/>
```

prop 이 없거나 `null` 이면(옛 화면·복제 템플릿) 편집기는 종전 동작입니다 — 서버 응답·요청에 필수 필드를 더하지 않았으므로 호환이 깨지지 않습니다.

## 정책 (`ContentMediaPolicy`)

| 항목 | 형식 | 비고 |
| --- | --- | --- |
| 종류 | `image`·`video`·`audio` | 후보 확장자는 코드가 정한 닫힌 집합 — 이미지 jpg·jpeg·png·gif·webp·avif / 동영상 mp4·webm·ogv / 오디오 mp3·m4a·ogg·oga·wav |
| 종류별 | `enabled`, `max_size_mb`(1~2048), `extensions[]` | 확장자는 후보 안으로 좁힌다 |
| 문서당 최대 | `max_per_document`(1~200, 기본 30) | 첨부 개수와 따로 센다. 업로드 시점은 그 2배까지, 최종 판정은 저장 때 |

저장 형식은 `{image:{enabled,max_size_mb,extensions}, video:{…}, audio:{…}, max_per_document}` 이고, 값이 없으면(null) 확장의 기존 첨부
정책으로 만든 대체 정책(`fromAttachmentSettings`)을 씁니다 — 확장자는 첨부 허용 형식과 종류 후보의 교집합(비면 후보 전부), 파일당 크기는 첨부 파일당 크기이고, 동영상·오디오는 코어 「본문 콘텐츠」의 업로드 스위치가 켜졌을 때만 받습니다. 설정 FormRequest 는 `ContentMediaPolicyRules::settingsRules($prefix)`·
`messages($prefix)` 와 입력 정리 `normalizeInput()`(「직접 정하기」 스위치 `use_custom` 이 꺼지면 null)을 함께 씁니다.

## 서버 도구

| 클래스 | 역할 |
| --- | --- |
| `App\Rules\ContentMediaFile` | 파일 **내용**으로 판정한 MIME(finfo)과 이름 확장자가 모두 그 종류 허용 목록에 있어야 통과 + 종류별 용량. 이름만 바꾼 HTML·SVG 는 거부 |
| `ContentMediaReferenceExtractor::hashes($html, $pattern)` | `img`·`video`·`audio`·`source` 의 `src`, `video` 의 `poster` 에서 서빙 주소 해시를 순서대로 뽑는다 |
| `ContentMediaUrlRewriter::sign()` / `normalize()` | 정식 주소 ↔ 서명 주소. 같은 사이트 절대 주소는 상대 정식 주소로. `ttlMinutes()` = 코어 설정 `content.media_url_ttl_minutes`(5~1440, 기본 240) |
| `ContentMediaResponder::respond()` | 로컬은 `BinaryFileResponse`(Range·206·416), 원격 스트림 디스크는 단일 구간 직접 처리, 그 밖은 200. 래스터 이미지 inline, 동영상·오디오는 `playable` 일 때만 inline + `media-src 'self'` CSP, 나머지 다운로드 |
| `App\Exceptions\ContentMediaLimitExceededException` | 문서당 개수 초과 → 422 `errors.code = content_media_limit_exceeded` |

## 저장·서빙 규칙 (확장이 지킬 것)

1. **파일은 처음부터 최종 경로**에 두고 행만 임시(소유자 없음 + 임시 키)로 둡니다. 저장 트랜잭션 안에서 본문이 참조한 것만 DB 갱신으로 잇습니다 —
   롤백되면 함께 되돌아가고 큰 동영상을 옮기지 않습니다. 끝내 잇지 않은 임시 행은 확장의 임시 정리 명령이 지웁니다.
2. 잇는 대상은 **같은 임시 키 + 올린 사람**(비회원은 업로드 쿠키 등 소유 증명)인 것만. 남의 해시·다른 게시판 해시는 무시합니다.
3. 본문에서 빠진 본문 미디어는 지웁니다(소프트 삭제 등). 본문 미디어는 첨부 목록·첨부 개수·목록 썸네일에 넣지 않습니다.
4. 저장 전 `normalize()` 로 서명 쿼리를 떼고, 응답 직렬화에서 `sign()` 합니다 — 서명은 **본문을 받을 자격(게이트)을 통과한 응답에서만**.
   봇(SEO) 렌더(`SeoRenderRequest::matches()`)와 공개 콘텐츠는 정식 주소 — 그 HTML 은 캐시됩니다.
5. 서빙 라우트에는 권한 미들웨어를 두지 않습니다(`<img>`·`<video>` 요청에 Bearer 가 없음). 서명이 있으면 통과, 없으면 확장 게이트(예: 게시판은
   글 읽기 권한 + 삭제·비밀·블라인드). 임시 행은 서명이 있을 때만. 원격 임시 URL 로 리다이렉트하지 않습니다(게이트·헤더를 통제할 수 없음).

번들 확장의 적용: 게시판 `PostContentMediaService`(`board_attachments.collection = content_media`), 페이지 `PageContentMediaService`
(`page_attachments.collection = content_media`), 쇼핑몰 `ContentMediaService`(`ecommerce_content_media`). 각 확장 문서의 API 레퍼런스를 보세요.

## 클라이언트 런타임 (`G7Core.contentMedia`)

| 함수 | 설명 |
| --- | --- |
| `normalizeTarget(raw)` | 형식이 틀리거나 다른 출처·허용 밖 헤더면 `null` |
| `validateFile(target, file)` | `{ ok, kind, messageKey?, params? }` — 문구 키는 `core.content_media.client.*` |
| `countReferences(html, target)` · `isAtLimit(html, target)` | 본문이 이미 참조하는 미디어 수 / 문서당 최대에 닿았는지 |
| `upload(target, file, { signal, onProgress })` | 어댑터를 골라 올리고 `{ url, id?, kind }`. 기본 어댑터는 `G7Core.api.post` + FormData. 토큰 없이 보낸 요청의 401 은 로그인 화면으로 옮기지 않고 그대로 돌려준다 |
| `registerAdapter({ id, priority?, supports, upload })` → 해제 함수 · `listAdapters()` | 외부 플러그인이 업로드 방식(직접 저장소 업로드 등)을 바꿀 때. `priority` 내림차순으로 `supports` 가 참인 첫 어댑터, 기본 어댑터는 0 |

## 본문 콘텐츠 규칙 (코어 설정 `content`, `G7Core.contentPolicy`)

본문에 **무엇이 남는가**(표시·봇·저장 정화)는 확장이 아니라 코어 환경설정 「본문 콘텐츠」가 정합니다. 편집기 설정에 두면 편집기를 바꿀 때
표시 규칙이 사라지므로 코어에 둡니다. 서버가 `content.render_policy`(계산 값)로 화면에 내려주고, 아래 네 곳이 같은 규칙을 읽습니다.

| 읽는 곳 | 방식 |
| --- | --- |
| 템플릿 `HtmlContent`(sirsoft-basic·sirsoft-admin_basic) | `G7Core.contentPolicy.sanitize(DOMPurify, html, config)` — 규칙이 없으면(옛 코어) `null` → 종전 DOMPurify |
| 검색엔진(봇) 정화기 `App\Seo\HtmlSanitizer` | `ContentHtmlPolicy::current()` — 켜진 후보 태그를 제거·벗김 목록에서 빼고 마지막에 `finalize('bot')` |
| 쇼핑몰 상품 설명 저장 정화(HTMLPurifier) | `purifyHtml($html, 'content')` — 후보 태그 정의를 HTMLPurifier 에 더하고 `finalize('store')` |
| 편집기 플러그인 | `G7Core.contentPolicy.editorConstraints()` — 외부 영상 서비스 목록·금지 태그·업로드 동영상/오디오 허용 |

설정 키(`content.*`, 기본값):

| 키 | 기본 | 뜻 |
| --- | --- | --- |
| `allow_figure_caption` | `true` | `<figure>` 안 `<figcaption>` |
| `allow_uploaded_video` · `allow_uploaded_audio` | `false` | `<video>`·`<audio>`·`<source>` — `src`·`poster` 는 서빙 주소 패턴에 맞을 때만. 정책(`ContentMediaPolicy`)의 동영상·오디오 업로드도 이 값이 켜져야 받는다 |
| `allow_external_embed` | `true` | `<oembed url>` → 켜진 서비스면 샌드박스 iframe, 아니면 `nofollow` 링크 |
| `embed_presets` | 유튜브·비메오만 `true` | 프리셋 3종(youtube·vimeo·tiktok) — 공식 문서에 iframe 플레이어 주소가 있는 서비스만. 그 밖의 서비스는 「직접 추가」 |
| `embed_custom_providers` | `[]` | 운영자 추가 서비스(최대 30) — 아래 항목 형식 |
| `custom_tags` | `[]` | 운영자 추가 태그. 스크립트·스타일·문서 구조·외부 삽입·폼 계열은 저장 거부 |
| `media_url_ttl_minutes` | `240` | 비공개 본문 미디어 서명 주소 유효 시간(5~1440분) |

외부 영상 서비스 항목 형식(운영자 추가·확장 제공 공통):

```php
[
    'id' => 'myvideo',                                        // 영문 소문자 시작 2~32자, 프리셋·다른 항목과 중복 금지
    'name' => 'My Video',                                     // 1~40자
    'url_patterns' => ['https://*.myvideo.example/v/{id}'],   // 1~5개. `*` = `/` 를 뺀 아무 글자(호스트는 맨 앞 하위 도메인만), `{id}` 정확히 1개
    'player_url' => 'https://player.myvideo.example/{id}',    // https 만, `{id}` 정확히 1개, 이 사이트 자신의 호스트 금지
    'id_rule' => 'alnum_dash',                                // alnum | alnum_dash | digits
    'aspect' => '16:9',                                       // 16:9 | 9:16 | 4:3 | 1:1
    'allow' => ['fullscreen', 'encrypted-media', 'picture-in-picture'], // + autoplay
    'enabled' => true,
]
```

주소 형식은 서버(`EmbedProviderRegistry::compilePattern`)가 정규식으로 컴파일해 화면에 내려주므로 PHP 와 JS 가 같은 정규식을 씁니다.
플레이어 iframe 에는 **원본 주소를 넣지 않고** 꺼낸 id 로만 `player_url` 을 채웁니다.

확장 훅(필터):

| 훅 | 받는 값 → 돌려줄 값 | 쓰임 |
| --- | --- | --- |
| `core.content.filter_embed_providers` | `array $providers`(처음 `[]`) → 항목 목록. 항목마다 위 형식 + `'extension' => '{확장 식별자}'` | 확장이 외부 영상 서비스를 더한다. 검증을 통과하지 못한 항목은 경고 로그 뒤 버린다. 처음에는 꺼져 있고, 운영자가 「본문 콘텐츠」 탭 「확장이 제공한 서비스」에서 켜야 쓰인다(`embed_presets` 의 `ext:{확장}:{id}` 키, 설정 응답 `_meta.embed_extension_providers` 가 선택지). `allow_external_embed` 가 꺼지면 함께 꺼진다 |
| `core.content.filter_media_source_patterns` | `array $patterns`(정규식 문자열, 구분자 없음) → 목록 | 본문 `<video>`·`<audio>`·`<source>` 의 `src`·`poster` 가 맞아야 하는 서빙 주소. 기본값이 `/api/modules\|plugins/{확장}/…/content-media/{12자 이상 해시}` 를 덮으므로 이 경로를 따르면 등록할 필요가 없다 |

```php
public static function getSubscribedHooks(): array
{
    return ['core.content.filter_embed_providers' => ['method' => 'addProviders', 'type' => 'filter']];
}

public function addProviders(array $providers): array
{
    $providers[] = ['extension' => 'vendor-video', 'id' => 'myvideo', 'name' => 'My Video', /* … 위 형식 */];

    return $providers;
}
```

## 설정 화면 조각

관리자 설정에서 정책을 고르는 조각의 기준 마크업은 게시판 사본(`modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_form/_content_media_policy.g7.html`)이고, 레이아웃 partial 은
패키지 경계를 넘어 include 할 수 없어 확장마다 사본을 둡니다. 사본은 값 경로 접두사(`content_media_policy` ↔ `product_display.content_media`)만
다르고 문구는 코어 키 `core.content_media.policy.*` 를 씁니다.

```html
<toggle data-type="composite" name="{prefix}.use_custom"/>              <!-- 끄면 null: 첨부 정책을 따름 -->
<toggle data-type="composite" name="{prefix}.image.enabled"/>
<input type="number" name="{prefix}.image.max_size_mb" min="1" max="2048"/>
<tag-input data-type="composite" name="{prefix}.image.extensions" :isMulti="true">…후보 확장자 item…</tag-input>
<!-- video·audio 같은 구성, {prefix}.max_per_document (1~200) -->
```

## FAQ

- **편집기를 CKEditor 가 아닌 것으로 바꾸면?** 새 편집기 플러그인이 `extensionPointProps.mediaUpload` 를 읽어 `G7Core.contentMedia.upload` 를 부르면
  같은 규칙으로 올라갑니다. 확장 쪽은 바꿀 것이 없습니다.
- **업로드 상한이 정책보다 작으면?** 413 이 나므로 계약의 `maxSizeMb` 가 PHP 상한으로 줄어 내려가 편집기가 미리 막습니다.
- **비회원 업로드는?** 확장 라우트가 `optional.sanctum` + 확장 업로드 권한이면 됩니다. 게시판은 업로드 응답의 소유 쿠키로 비회원 본인 업로드만 잇습니다.
- **프리셋 서비스의 주소 형식이 바뀌어 영상이 링크로만 보이면?** 서비스가 주소 체계를 바꾼 경우입니다. 그 프리셋을 끄고, 「본문 콘텐츠」 탭 「직접 추가한 서비스」에 새 주소 형식과 플레이어 주소로 같은 서비스를 추가해 쓰세요(예시 주소 확인으로 꺼낸 id 를 저장 전에 볼 수 있습니다). 코어 판이 오르면 프리셋도 고쳐집니다.
