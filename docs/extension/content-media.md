# 본문 미디어 업로드 계약 (Content Media)

> 코어 7.1.5 · 템플릿 엔진 engine-v1.89.0. 편집기 본문에 바로 넣는 이미지·동영상·오디오를 **어느 편집기든 같은 계약으로** 올리고,
> 확장(모듈·플러그인)이 자기 저장소·권한·정책으로 받게 하는 계약입니다.

## TL;DR (5초 요약)

```text
1. 화면(확장 레이아웃)이 편집기 확장점 html_editor 에 prop mediaUpload(업로드 계약)를 넘긴다 — 없으면 null
2. 편집기 플러그인은 계약을 읽기만 한다: G7Core.contentMedia 로 판정·문서당 개수 사전 차단·업로드
3. 서버 흐름(업로드·저장 동기화·서명·서빙·정리)은 코어 골격 AbstractContentMediaService 가 돌리고, 확장은 어댑터 ContentMediaStore 만 구현한다
4. 저장 본문에는 서명 없는 정식 주소, 응답에서 **이 문서 소유 해시만** 서명 주소로 바꾸고, 저장 때 서명 쿼리를 뗀다
5. 업로드·영구 삭제마다 공용 훅 core.content_media.* 가 발화한다(확장별 첨부 훅은 발화하지 않음)
6. 본문 미디어는 첨부 목록·개수와 따로 센다(문서당 최대 기본 30). 동영상·오디오는 코어 「본문 콘텐츠」 후보도 켜야 받는다
```

## 왜 계약인가

편집기(CKEditor 등)가 업로드 주소·권한을 직접 알면 편집기를 바꿀 때마다 업로드 규칙이 사라지고, 확장(게시판·페이지·쇼핑몰)마다
비밀글·삭제글 게이트를 편집기 안에서 다시 만들어야 합니다. 그래서 **형식과 흐름은 코어가, 값과 권한은 확장이** 정합니다.

- 코어: 계약 형식(`ContentMediaUploadTarget`), 정책 형식(`ContentMediaPolicy`), 검증 규칙, 흐름 골격(`AbstractContentMediaService`),
  어댑터 계약(`ContentMediaStore`), 공용 훅, 클라이언트 런타임(`G7Core.contentMedia`), 서빙 응답(`ContentMediaResponder` — Range·보안 헤더).
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

서버는 골격의 `uploadTarget($context, $admin)` 으로 만듭니다(내부적으로 `ContentMediaUploadTarget::toArray($endpoint, $policy, $pattern, $fields, $headers)`).
`policy` 의 `maxSizeMb` 는 PHP 업로드 상한(`upload_max_filesize`·`post_max_size`)과 비교한 실효값이고, 동영상·오디오는 코어 「본문 콘텐츠」
설정(`content.allow_uploaded_video`·`allow_uploaded_audio`, 기본 꺼짐)이 꺼져 있으면 확장 정책과 관계없이 `enabled=false` 로 내려갑니다.

화면은 받은 계약에 글쓰기 임시 키를 더해 넘깁니다.

```html
<extension-point name="html_editor"
  mediaUpload="{{form_meta?.data?.content_media ? Object.assign({}, form_meta.data.content_media, { fields: Object.assign({}, form_meta.data.content_media.fields ?? {}, { temp_key: _local.tempKey }) }) : null}}"/>
```

prop 이 없거나 `null` 이면(옛 화면·복제 템플릿) 편집기는 종전 동작입니다 — 서버 응답·요청에 필수 필드를 더하지 않았으므로 호환이 깨지지 않습니다.

### 표준 계약 엔드포인트 (`GET …/content-media/target`)

계약을 화면에 전하는 길은 확장마다 `…/content-media/target` 한 가지로 맞춥니다(모듈·플러그인 공통). 응답은 성공 200
`{data: ContentMediaUploadTarget | null}`(지금 받는 종류가 없으면 `null`), 올릴 권한이 없으면 403(`auth.scope_denied`)입니다.
컨트롤러는 트레이트 `App\Http\Controllers\Concerns\RespondsWithContentMediaTarget` 로 같은 모양을 냅니다.

```php
use RespondsWithContentMediaTarget;

public function target(Request $request): JsonResponse
{
    $context = ContentMediaContext::plugin('vendor-notes', 'note')->withActor(Auth::id());

    return $this->contentMediaTargetResponse($this->media, $context, 'vendor-notes::messages.content_media.target_success', admin: true);
}
```

값(종류별 허용·크기·형식·문서당 최대)은 각 확장 설정이고, 코어는 동영상·오디오 업로드 허용 스위치로만 관여합니다. 예전에 폼 메타나
첨부 설정 응답에 계약을 실어 보내던 확장은 같은 값을 계속 싣습니다(호환).

## 정책 (`ContentMediaPolicy`)

| 항목 | 형식 | 비고 |
| --- | --- | --- |
| 종류 | `image`·`video`·`audio` | 후보 확장자는 코드가 정한 닫힌 집합 — 이미지 jpg·jpeg·png·gif·webp·avif / 동영상 mp4·webm·ogv / 오디오 mp3·m4a·ogg·oga·wav |
| 종류별 | `enabled`, `max_size_mb`(1~2048), `extensions[]` | 확장자는 후보 안으로 좁힌다 |
| 문서당 최대 | `max_per_document`(1~200, 기본 30) | 첨부 개수와 따로 센다. 업로드 시점은 그 2배까지, 최종 판정은 저장 때 |

저장 형식은 `{image:{enabled,max_size_mb,extensions}, video:{…}, audio:{…}, max_per_document}` 이고, 값이 없으면(null) 확장의 기존 첨부
정책으로 만든 대체 정책(`fromAttachmentSettings`)을 씁니다 — 확장자는 첨부 허용 형식과 종류 후보의 교집합(비면 후보 전부), 파일당 크기는 첨부 파일당 크기이고, 동영상·오디오는 코어 「본문 콘텐츠」의 업로드 스위치가 켜졌을 때만 받습니다. 설정 FormRequest 는 `ContentMediaPolicyRules::settingsRules($prefix)`·
`messages($prefix)` 와 입력 정리 `normalizeInput()`(「직접 정하기」 스위치 `use_custom` 이 꺼지면 null)을 함께 씁니다.

## 코어 골격과 확장이 구현할 것 (`ContentMediaStore`)

서버 흐름은 `App\Support\ContentMedia\AbstractContentMediaService` 가 한 곳에서 돌립니다. 확장(모듈·플러그인)은 어댑터
`App\Contracts\ContentMedia\ContentMediaStore` 를 구현하고, 골격을 상속한 얇은 서비스에 그 어댑터와 자기 저장소(`StorageInterface`)를 넘깁니다.

```php
final class NoteContentMediaService extends AbstractContentMediaService
{
    public function __construct(NoteContentMediaStore $store, StorageInterface $storage)
    {
        parent::__construct($store, $storage);
    }
}

// 서비스 프로바이더 boot() — 서빙 라우트를 미디어 출처 허용 패턴의 원천으로 등록한다
ContentMediaRegistrar::register($this->app->make(NoteContentMediaStore::class));
```

### 문맥 (`ContentMediaContext`)

골격·어댑터·공용 훅이 주고받는 불변 값 객체입니다. 값을 바꾸려면 `with*()` 로 사본을 만듭니다.

| 필드 | 뜻 |
| --- | --- |
| `extensionType` · `extension` | `module`·`plugin` + 확장 식별자 (`ContentMediaContext::module()`·`plugin()` 으로 만든다) |
| `documentType` · `documentId` | 확장이 정한 문서 종류 · 문서 ID(저장 전 업로드면 null) |
| `tempKey` | 편집 화면이 발급한 임시 업로드 키 |
| `scope` | 확장 안 범위(게시판 슬러그 등), 없으면 null |
| `actorId` · `guestOwnerHash` | 요청한 회원 ID / 비회원 업로드 소유 증명 해시(회원이면 null) |
| `kind` | 다루는 파일의 종류(골격이 훅 발화 때 채움) |
| `extra` | 확장 전용 부가 값(골격은 읽지 않음) |

`owns($createdBy, $rowGuestOwnerHash)` 는 「요청자가 올린 행인가」를 판정합니다 — 회원은 올린 회원 ID, 비회원은 행과 문맥의 소유 증명 해시가 같을 때만 참입니다.

### 어댑터가 답할 것

| 묶음 | 메서드 | 답 |
| --- | --- | --- |
| 정체 | `extensionType()` · `extensionIdentifier()` | `module`/`plugin` · 식별자 |
| 서빙 주소 | `serveRouteName()` · `serveRouteParameters($ctx, $hash)` · `servePattern($ctx)` | 서빙 라우트 이름·파라미터, 본문 참조를 찾을 정규식 본문(명명 그룹 `hash`) |
| 편집기 계약 | `uploadEndpoint($ctx, $admin)` · `uploadFields($ctx)` | 업로드 주소, 함께 보낼 폼 필드 |
| 정책 | `policyValue($ctx)` · `fallbackPolicy($ctx)` | 저장된 정책 배열(없으면 null) · 대체 정책 |
| 저장 위치 | `storageCategory()` · `storagePath($ctx, $storedFilename)` · `storagePathOf($row)` · `uploadMeta($ctx, $kind, $dimensions)` | 카테고리, 새 파일의 카테고리 기준 경로, 기존 행의 경로, 행 meta — 이미 저장된 파일의 경로 모양을 바꾸지 않게 확장이 정한다 |
| 행 조회 | `countTemp` · `tempRowsByHashes` · `filterLinkableTempRows` · `ownedRows` · `findByHash` | 임시 개수·임시 행(같은 임시 키)·이어도 되는 임시 행(올린 사람 규칙)·문서 소유 행·서빙 범위의 해시 단건 |
| 행 쓰기 | `createRow($ctx, $attributes, $attachToDocument)` · `linkRow($ctx, $row, $order)` · `forceDeleteRow($row)` | 행 생성(업로드 = 임시, 복제 = 문서 소유)·임시 행 연결·영구 삭제 |
| 제거 정책 | `handleRemoved($ctx, $row, $purge)` | 본문에서 빠진 행 처리 — 소프트 삭제는 직접, 영구 삭제는 `$purge($row)`, 남기면 false |
| 게이트 | `isTemp($row)` · `canView($ctx, $row)` · `isPublic($ctx, $row)` · `cacheMaxAge($public)` · `canUpload($ctx)` · `notFoundMessageKey()` | 임시 행 여부, 요청자가 서명 없이 볼 수 있나, 아무나 볼 수 있나, 캐시 수명, 업로드 권한, 404 문구 |
| 복제 | `findCloneSource($ctx, $hash)` · `mayClone($ctx, $row)` | 확장 안 복제 범위에서 원본 찾기, 복제 게이트(임시 행은 본인 것, 이어진 행은 `canView` 기준) |
| 정리 | `staleRows($threshold, $limit)` · `contextForRow($row)` | 보존기간이 지난 임시 행·제거 행, 정리 명령의 훅 문맥 |

`createRow()` 가 받는 공통 속성은 `temp_key`·`original_filename`·`stored_filename`·`disk`·`path`(카테고리 기준)·`mime_type`(내용으로
판정한 값)·`size`·`meta`·`order`·`created_by`·`guest_owner_hash` 입니다. 소유 열(문서 ID·범위)은 어댑터가 문맥으로 채웁니다.

### 골격이 하는 일

| 메서드 | 하는 일 |
| --- | --- |
| `upload($ctx, $file)` | 임시 키별 상한(문서당 최대 × 2) → `before_upload` → `filter_upload_file`(리스너가 돌려준 파일은 `ContentMediaFile` 판정을 다시 거침) → 최종 경로 저장(이미지는 환경설정 크기로 축소) → 임시 행 → `after_upload` |
| `syncWithContent($ctx, $content, $isHtml)` | 본문(문자열·로케일 배열) 참조 추출 → 개수 판정(복제분 포함, 바꾸기 전에) → **연결 → 복제 → 제거**. 반환 `{linked, removed, cloned, content}` — 복제가 있으면 `content` 를 문서에 다시 저장한다 |
| `normalizeContent($ctx, $content)` · `presentContent($ctx, $content, $signAll = false, $documentPublic = null)` | 저장 전 서명 쿼리 떼기 / 응답 서명(아래 규칙 4). `$documentPublic` 은 호출자가 문서 공개 여부를 이미 알 때 넘긴다(null 이면 어댑터 `isPublic` 으로 판정) |
| `signedUrl($ctx, $hash)` · `uploadTarget($ctx, $admin)` | 서명 주소 / 편집기 계약 |
| `findServable($ctx, $hash, $signatureVerified)` · `respond($ctx, $row, $request)` | 서빙 게이트 / 응답 — 캐시는 아무나 볼 수 있을 때만 `public`, 아니면 `private` |
| `purgeRows($ctx, $rows)` · `pruneStale($days, $limit, $dryRun)` | 문서 영구 삭제 때 행 정리 / 정리 명령 — 행마다 `before_delete` 뒤 파일·행 삭제 |
| `storageFor($row)` | 행에 기록된 디스크의 저장소(`App\Extension\Storage\RowDiskStorage::resolve`) — 서빙·복제 원본·제거·정리가 모두 이것을 쓴다. 설정에 없는 디스크는 기본 저장소로 물러난다 |

## 공용 훅 (`core.content_media.*`)

본문 미디어를 다루는 모든 확장(모듈·플러그인)에서 골격이 발화합니다. 마지막 인자 `ContentMediaContext` 로 어느 확장·문서의 미디어인지
압니다. 확장별 첨부 훅(`{확장}.attachment.*`)은 본문 미디어에 발화하지 않습니다 — 두 번 발화하면 첨부 리스너가 본문 미디어를 첨부로 오인합니다.

| 훅 | 종류 | 인자 | 발화 시점 |
| --- | --- | --- | --- |
| `core.content_media.before_upload` | action | `UploadedFile $file, ContentMediaContext $ctx` | 업로드 상한 검사 뒤, 저장 전 |
| `core.content_media.filter_upload_file` | filter | `UploadedFile $file, ContentMediaContext $ctx` → `UploadedFile` | `before_upload` 직후. 돌려준 파일은 내용 MIME·종류·확장자·크기를 다시 판정한다(어긋나면 422, 저장 없음). 파일이 아닌 값을 돌려주면 원본을 저장한다 |
| `core.content_media.after_upload` | action | `Model $row, ContentMediaContext $ctx` | 행을 만든 뒤 |
| `core.content_media.before_delete` | action | `Model $row, ContentMediaContext $ctx` | 행·파일을 **영구** 삭제하기 직전에만 — 본문에서 빠져 영구 삭제될 때, 문서 영구 삭제(`purgeRows`), 정리 명령. 소프트 삭제(휴지통) 때는 발화하지 않는다 |

형식을 바꾸는 필터(예: jpg → webp)는 바이트만 제자리에서 덮어쓰지 말고 변환한 임시 파일과 새 원본 파일명으로 `UploadedFile` 을 만들어
돌려줍니다 — 저장 확장자는 원본 파일명에서 얻습니다. 복제(다른 문서 미디어를 이 문서 소유로 복사)는 업로드가 아니라서 업로드 훅을 발화하지 않습니다.

이름 상수는 `App\Support\ContentMedia\ContentMediaHooks` 에 있습니다.

## 서버 도구

| 클래스 | 역할 |
| --- | --- |
| `App\Rules\ContentMediaFile` | 파일 **내용**으로 판정한 MIME(`App\Support\Files\FileContentSniffer` — finfo)과 이름 확장자가 모두 그 종류 허용 목록에 있어야 통과 + 종류별 용량. 이름만 바꾼 HTML·SVG 는 거부 |
| `ContentMediaReferenceExtractor::hashes($html, $pattern)` | `img`·`video`·`audio`·`source` 의 `src`, `video` 의 `poster` 에서 서빙 주소 해시를 순서대로 뽑는다 |
| `ContentMediaUrlRewriter::sign()` / `normalize()` | 정식 주소 ↔ 서명 주소. 같은 사이트 절대 주소는 상대 정식 주소로. `ttlMinutes()` = 코어 설정 `content.media_url_ttl_minutes`(5~1440, 기본 240) |
| `ContentMediaResponder::respond()` | 로컬은 `BinaryFileResponse`(Range·206·416), 원격 스트림 디스크는 단일 구간 직접 처리, 그 밖은 200. 래스터 이미지 inline, 동영상·오디오는 `playable` 일 때만 inline + `media-src 'self'` CSP, 나머지 다운로드. 재생 가능 MIME 은 `ContentMediaKind` 의 동영상·오디오 목록(`InlineMediaPolicy::playableMimeTypes()`) |
| `App\Exceptions\ContentMediaLimitExceededException` | 문서당 개수 초과 → 422 `errors.code = content_media_limit_exceeded` |

## 저장·서빙 규칙

골격이 아래 규칙을 지키므로, 어댑터는 자기 판정(권한·제거 정책)만 맞게 답하면 됩니다.

1. **파일은 처음부터 최종 경로**에 두고 행만 임시(소유자 없음 + 임시 키)로 둡니다. 저장 트랜잭션 안에서 본문이 참조한 것만 DB 갱신으로 잇습니다 —
   롤백되면 함께 되돌아가고 큰 동영상을 옮기지 않습니다. 끝내 잇지 않은 임시 행은 확장의 임시 정리 명령이 지웁니다.
2. 잇는 대상은 **같은 임시 키 + 올린 사람**(비회원은 업로드 쿠키 등 소유 증명)인 것만. 남의 해시는 잇지 않습니다.
3. 본문에서 빠진 본문 미디어는 확장의 제거 정책(`handleRemoved`)대로 처리합니다. 본문 미디어는 첨부 목록·첨부 개수·목록 썸네일에 넣지 않습니다.

   | 확장 | 본문에서 빠진 미디어 |
   | --- | --- |
   | 게시판 | 소프트 삭제(휴지통) — 파일은 소프트 삭제 첨부 영구 정리가 지움 |
   | 페이지 | 어느 버전도 참조하지 않을 때만 행·파일 영구 삭제(버전이 참조하면 남기고 페이지 삭제 때 정리) |
   | 쇼핑몰 | 소프트 삭제 → 보존기간(기본 2일) 뒤 정리 명령이 파일째 영구 삭제 |

4. **서명은 이 문서가 소유한 해시만** 합니다(세 확장 공통). 저장 전 `normalizeContent()` 로 서명 쿼리를 떼고, 응답 직렬화에서
   `presentContent()` 합니다 — 서명은 **본문을 받을 자격(게이트)을 통과한 응답에서만**, 그 자격은 이 문서에 대한 것이므로 다른 문서
   소유 해시는 정식 주소로 둡니다(원본 문서의 게이트를 따른다). 서명을 본문 전체에 하면 남의 비공개 문서 해시를 자기 문서에 넣어 서명
   주소를 받아 갈 수 있습니다.
   - 예외: 관리자 증거 열람 화면(신고 상세 등)은 `signAll: true` 로 모든 서빙 주소를 서명합니다.
   - 봇(SEO) 렌더(`SeoRenderRequest::matches()`)와 아무나 볼 수 있는 문서(`isPublic`)는 정식 주소 — 그 HTML 은 캐시됩니다.
5. 서빙 라우트에는 권한 미들웨어를 두지 않습니다(`<img>`·`<video>` 요청에 Bearer 가 없음). 서명이 있으면 통과, 없으면 임시 행은 403,
   이어진 행은 어댑터 `canView`(예: 게시판은 글 읽기 권한 + 삭제·비밀·블라인드). 응답 캐시는 서명 없이 아무나 볼 수 있을 때만 `public`,
   그 밖은 `private` 입니다. 원격 임시 URL 로 리다이렉트하지 않습니다(게이트·헤더를 통제할 수 없음).
6. **다른 문서 소유 본문 미디어는 저장 때 이 문서 소유로 복제합니다.** 본문 미디어 행은 문서 하나에 묶이므로, 다른 문서(답글 인용·복사·
   붙여넣기·상품 복사)가 같은 서빙 주소를 그대로 쓰면 원본 문서가 미디어를 빼거나 지워질 때 함께 깨집니다. 저장 동기화에서
   - 본문이 **같은 확장 안 다른 문서 소유**(또는 요청자 본인이 다른 임시 키로 올린 임시 행 — 비회원은 소유 증명이 맞을 때)의 서빙 주소를
     쓰면, 파일을 복사해 이 문서 소유의 새 행·새 해시를 만들고 본문 주소를 새 주소로 바꿔 다시 저장합니다(같은 트랜잭션 — 롤백되면 사본 파일도 지움).
     원본 파일은 그 행이 저장된 디스크에서 읽습니다.
   - 복제는 요청자가 **원본을 서명 없이 받을 수 있을 때만** 합니다(어댑터 `mayClone` — 서빙 게이트와 같은 기준). 볼 수 없으면 복제하지 않고
     주소를 그대로 둡니다 — 규칙 4 로 그 미디어는 원본 문서의 게이트를 따릅니다.
   - 이미 이 문서 소유인 것은 복제하지 않고, 같은 미디어를 여러 번(여러 로케일) 써도 사본은 하나입니다. 복제할 것도 문서당 최대
     개수에 듭니다(넘으면 422, 복사 전에 판정). 다른 확장 주소·다른 출처 주소·서명 쿼리가 남은 주소는 대상이 아닙니다.

   골격이 쓰는 코어 도구 `ContentMediaCloner`(표·모델에 묶이지 않음 — 골격 없이 쓰는 확장도 같은 도구를 쓸 수 있다):

   | 메서드 | 역할 |
   | --- | --- |
   | `foreignHashes($htmls, $pattern, $knownHashes)` | 본문들(로케일 배열)이 미디어 태그에서 **상대 정식 주소**로 참조하는 해시 중 아는 것을 뺀 복제 후보 |
   | `canonicalHashes($html, $pattern)` | 한 본문의 상대 정식 주소 참조 해시 |
   | `copyFile($from, $fromCategory, $fromPath, $to, $toCategory, $toPath)` | 파일 복사(스트림 우선, 디스크가 달라도 됨) — 트랜잭션이 되돌아가면 사본 삭제(`StorageTransaction::put`) |
   | `replaceHashes($content, $pattern, $map)` | 문자열·로케일 배열 본문에서 원본 해시 → 새 해시 (같은 해시 모두) |
   | `clonedFilename($sourceFilename)` | 새 UUID + 원본 확장자 |

7. **문서를 지울 때 본문 미디어도 그 확장의 첨부 삭제 정책대로 함께 지웁니다.** 첨부 목록 조회는 본문 미디어를 빼므로(규칙 3) 삭제
   경로가 본문 미디어를 따로 지나야 합니다 — 빠뜨리면 행이 외래키 연쇄로 사라지거나 남아도 파일은 저장소에 영구히 남습니다. 문서 영구
   삭제 트랜잭션 안에서 `purgeRows()` 로 지우면 파일은 커밋된 뒤에 지워집니다(롤백되면 파일이 남는다). 휴지통이 있는 문서는 첨부처럼
   행만 소프트 삭제하고 파일은 영구 정리 때 지웁니다.

   | 확장 | 문서 삭제 | 저장하지 않은 임시 본문 미디어 |
   | --- | --- | --- |
   | 게시판 | 휴지통 — 첨부와 같은 연쇄 소프트 삭제(복원하면 돌아옴), 파일은 소프트 삭제 첨부 영구 정리(운영자 옵트인)가 지움 | `sirsoft-board:prune-attachments`(매일)의 임시 정리 파트 |
   | 페이지 | 첨부와 같이 파일(커밋 뒤)·행 삭제(버전도 함께) | `sirsoft-page:prune-temp-attachments`(매일, 기본 보존 2일) |
   | 쇼핑몰 | 상품(영구 삭제)·상품 공통 정보 삭제 때 행(본문에서 빠진 것 포함)·파일(커밋 뒤) 삭제 | `sirsoft-ecommerce:prune-temp-product-images`(매일, 기본 보존 2일) — 본문에서 빠져 소프트 삭제된 지 보존기간이 지난 것도 파일째 지움 |

번들 확장의 저장 표: 게시판 `board_attachments`, 페이지 `page_attachments`, 쇼핑몰 `ecommerce_attachments` — 모두 `collection = content_media`
행입니다. 각 확장 문서의 API 레퍼런스를 보세요.

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
| `allow_figure_caption` | `true` | `<figure>` 안 `<figcaption>`. 끄면 표시(사람·봇 화면)에서 캡션만 내용째 지우고 그림은 남긴다(`render_policy.drop_tags`). 편집기와 저장 원문은 이 규칙으로 바뀌지 않아 다시 켜면 캡션이 돌아온다 |
| `allow_uploaded_video` · `allow_uploaded_audio` | `false` | `<video>`·`<audio>`·`<source>` — `src`·`poster` 는 미디어 출처 허용 패턴에 맞을 때만. 정책(`ContentMediaPolicy`)의 동영상·오디오 업로드도 이 값이 켜져야 받는다 |
| `allow_external_embed` | `true` | `<oembed url>` → 켜진 서비스면 샌드박스 iframe, 아니면 원래 주소 `nofollow` 새 창 링크. 끄면 모든 외부 영상이 링크로 보인다(사람·봇 화면 같음, http(s) 가 아닌 주소는 지움) |
| `embed_presets` | 유튜브·비메오만 `true` | 프리셋 3종(youtube·vimeo·tiktok) — 공식 문서에 iframe 플레이어 주소가 있는 서비스만. 그 밖의 서비스는 「직접 추가」 |
| `embed_custom_providers` | `[]` | 운영자 추가 서비스(최대 30) — 아래 항목 형식 |
| `custom_tags` | `[]` | 운영자 추가 태그. 스크립트·스타일·문서 구조·외부 삽입·폼 계열은 저장 거부 |
| `media_url_ttl_minutes` | `240` | 비공개 본문 미디어 서명 주소 유효 시간(5~1440분) |

### `render_policy` 필드

설정 응답의 계산 값 `content.render_policy` 는 화면 정화기(`G7Core.contentPolicy`)가 읽는 규칙 묶음입니다(`ContentHtmlPolicy::toFrontendArray()`).

| 필드 | 형식 | 뜻 |
| --- | --- | --- |
| `version` | `1` | 규칙 묶음 형식 판 |
| `forbid_tags` | `string[]` | 표시에서 지울 태그 — 기본 금지 목록에서 켜진 후보 태그를 뺀 것 |
| `drop_tags` | `string[]` | 표시에서 **내용째** 지울 태그 — 꺼진 후보의 표시 제거 태그(예: 캡션을 끄면 `figcaption`). 저장 원문은 건드리지 않는다 |
| `extra_tags` | `string[]` | 기본 허용 목록에 더할 태그 — 켜진 후보 태그 + 운영자 추가 태그 |
| `attributes` | `{태그: string[]}` | 켜진 후보 태그별 허용 속성 |
| `custom_tags` | `string[]` | 운영자 추가 태그 |
| `media_src_patterns` | `string[]` | `<video>`·`<audio>`·`<source>` 의 `src`·`poster` 가 맞아야 하는 서빙 주소 정규식(구분자 없음) — 아래 「미디어 출처 허용 패턴」 |
| `allow_external_embed` · `allow_uploaded_video` · `allow_uploaded_audio` | `boolean` | 외부 영상 재생·업로드 동영상·업로드 오디오 표시 스위치 |
| `embed_providers` | `object[]` | 켜진 외부 영상 서비스(`id`·`name`·`source`·`patterns`·`aspects`·`player_url`·`aspect`·`allow`) — `allow_external_embed` 가 꺼지면 빈 목록 |

판 규칙: **필드를 더해도 `version` 은 올리지 않습니다.** 읽는 쪽은 모르는 필드를 무시하면 됩니다. 기존 필드의 뜻이나 형식을 바꿀 때만
`version` 을 올리고, 화면 정화기는 모르는 판을 받으면 종전 정화로 물러납니다.

### 미디어 출처 허용 패턴

`media_src_patterns` 는 확장이 등록한 **실제 서빙 라우트**에서 자동으로 만듭니다 — 서비스 프로바이더가 `ContentMediaRegistrar::register()`
로 등록한 라우트 이름의 URI 와 파라미터 제약(`{hash}` 는 정확히 12자 영숫자, `{slug}` 등은 그 라우트의 정규식)으로 정규식을 만들고,
서명 쿼리 꼬리를 허용합니다. 등록된 라우트가 없으면(확장이 꺼짐) 그 패턴도 없습니다. 기본 패턴은 없습니다 — 경로 모양만 흉내 낸 가짜
주소(`/api/modules/x/content-media/…` 처럼 실제로 서빙되지 않는 경로)는 통과하지 않습니다. 골격을 쓰지 않고 자기 경로로 서빙하는
서드파티 확장은 필터 `core.content.filter_media_source_patterns` 로 패턴을 더할 수 있습니다.

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
| `core.content.filter_media_source_patterns` | `array $patterns`(정규식 문자열, 구분자 없음, 등록된 서빙 라우트에서 만든 패턴) → 목록 | 본문 `<video>`·`<audio>`·`<source>` 의 `src`·`poster` 가 맞아야 하는 서빙 주소를 더한다. `ContentMediaRegistrar` 로 서빙 라우트를 등록한 확장은 쓸 필요가 없다. 정규식으로 컴파일되지 않는 값은 버린다 |

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
다르고 문구는 코어 키 `core.content_media.policy.*` 를 씁니다(코어 언어 파일 `lang/partial/{로케일}/content_media.json`).

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
- **플러그인도 본문 미디어를 받을 수 있나?** 네. `ContentMediaContext::plugin()` 문맥과 `api.plugins.{식별자}.…` 서빙 라우트로 모듈과 같은 골격·훅·계약 엔드포인트를 씁니다.
- **업로드 상한이 정책보다 작으면?** 413 이 나므로 계약의 `maxSizeMb` 가 PHP 상한으로 줄어 내려가 편집기가 미리 막습니다.
- **비회원 업로드는?** 확장 라우트가 `optional.sanctum` + 확장 업로드 권한이면 됩니다. 문맥에 비회원 소유 증명 해시를 실으면 골격이 행에 남기고, 그 본인 업로드만 잇고 복제합니다.
- **워터마크·형식 변환 플러그인은?** `core.content_media.filter_upload_file` 에서 변환한 파일을 돌려주세요. 골격이 그 파일을 같은 규칙으로 다시 판정하므로 정책 밖 형식으로 바꾸면 업로드가 거부됩니다.
- **프리셋 서비스의 주소 형식이 바뀌어 영상이 링크로만 보이면?** 서비스가 주소 체계를 바꾼 경우입니다. 그 프리셋을 끄고, 「본문 콘텐츠」 탭 「직접 추가한 서비스」에 새 주소 형식과 플레이어 주소로 같은 서비스를 추가해 쓰세요(예시 주소 확인으로 꺼낸 id 를 저장 전에 볼 수 있습니다). 코어 판이 오르면 프리셋도 고쳐집니다.
