# 표시 이름

회원을 화면에 어떤 이름으로 보일지 정하는 구조다. 운영자는 환경설정의 회원 탭에서 자리(영역)마다 방식을 정하고, 코드는 `users.name` 을 직접 읽지 않고 계산기에게 묻는다.

## TL;DR

- 이름을 응답에 실을 때는 `App\DisplayName\DisplayNameResolver::resolve($user, $area)` 를 쓴다. `$user->name` 을 그대로 싣지 않는다.
- 회원 관계를 컬럼 제한으로 읽을 때는 `'user:'.User::displayColumns()` 를 쓴다. `'user:id,name'` 으로 읽으면 닉네임, 상태, 회원이 고른 종류가 없어 계산할 수 없다.
- 여러 회원을 그릴 때는 `resolveMany()` 또는 `preload()` 로 추가 항목 값을 한 번에 읽는다.
- 작성자 검색과 작성자순 정렬은 `App\DisplayName\DisplayNameQuery` 를 쓴다.
- 확장은 `getDisplayNameKinds()` 로 종류를, `getDisplayNameAreas()` 로 영역을 등록한다.

## 종류

종류는 「회원의 어떤 값을 어떤 모양으로 보일지」다.

| 종류 키 | 보이는 값 | 예 | 값이 없을 때 대신 쓰는 종류 | 닉네임 필요 | 사용자 화면 검색 |
| --- | --- | --- | --- | --- | --- |
| `name` | 이름 | 홍길동 | `nickname` → `masked_id` | 아니오 | 이름 |
| `nickname` | 닉네임 | 길동이 | `masked_id` | 예 | 닉네임 |
| `masked_name` | 가린 이름 | 홍*동 | `masked_id` | 아니오 | 찾지 않음 |
| `masked_id` | 아이디 가림 | gil*** | 없음 | 아니오 | 찾지 않음 |
| `name_nickname` | 이름(닉네임) | 홍길동(길동이) | `name` → `nickname` → `masked_id` | 예 | 이름, 닉네임 |
| `field:{항목 키}` | 회원 추가 항목의 값 | 바람의 검객 | `nickname` → `masked_id` | 아니오 | 항목 값 |

- 닉네임이 비었을 때 이름으로 대신하지 않는다. 닉네임 사이트에서 실명이 새지 않게 하기 위해서다.
- 아이디는 이메일의 `@` 앞부분이다. 앞 3자(3자 이하면 앞 1자)만 남기고 `***` 를 붙인다.
- 가린 이름은 1자면 그대로, 2자면 첫 글자와 `*`, 3자 이상이면 첫 글자와 끝 글자만 남긴다. 가림 규칙은 `App\Support\NameMasker` 한 곳에 있다.
- `field:{항목 키}` 는 운영자가 「표시 이름으로 사용 가능」을 켠 회원 추가 항목마다 하나씩 생긴다. 사용 중이고, 공용 값 테이블에 저장하고, 유형이 표시 이름을 지원하는(코어 유형은 `text`) 항목만 종류가 된다. 관리자만 열람 항목은 종류가 되지 않는다. 항목 선언은 [항목 집합과 항목 선언](field-sets.md)을 본다.
- 표시 이름으로 쓰는 추가 항목의 값은 다른 회원에게 이름으로 보이므로 닉네임과 같은 금지어가 적용된다. 관리자가 관리자 화면에서 정하는 값은 면제이고, 지금 저장된 값과 같은 글자는 다시 검사하지 않는다.

## 영역

영역은 회원 이름이 보이는 자리 한 곳이다. 영역마다 방식을 정한다.

| 방식 | 뜻 |
| --- | --- |
| `inherit` | 상위 영역을 따른다 |
| `fixed` | 종류 하나로 고정한다 |
| `member` | 회원이 고른 종류로 보인다. 고르지 않았거나 고른 종류가 허용에서 빠졌으면 회원 기본 종류 |

코어 영역:

| 영역 키 | 상위 | 기본값 |
| --- | --- | --- |
| `core:site` | 없음 | 회원이 고른 값 |
| `core:admin` | `core:site` | `name` 고정 |
| `core:notification` | `core:site` | 따름 |
| `core:notification:{채널}` | `core:notification` | 따름. 지금 쓸 수 있는 알림 채널마다 하나(확장 채널 포함) |

계산 순서는 인스턴스 설정(게시판마다 다른 설정 등) → 영역 설정 → 상위 영역 → … → `core:site` 다. 영역 키의 구분자는 `:` 이고 점은 쓰지 못한다(설정 경로 구분자와 겹친다).

설정에 없는 영역은 영역의 선언 기본값으로 읽는다. 지금 없는 종류(꺼진 확장, 지워진 추가 항목)를 가리키는 값은 따름으로 읽는다. 사이트 기본(`core:site`)은 따를 상위가 없어서, 쓸 수 없는 값이면 처음 방식인 「회원이 고른 값」으로 읽는다.

사이트 기본의 처음 방식이 「회원이 고른 값」이라, 영역을 손대지 않은 사이트는 회원 기본 종류를 바꾸면 사이트 기본과 그것을 따르는 영역이 함께 바뀐다. 표시 이름을 고르는 역할 권한이 없거나 고를 수 있는 종류가 하나뿐이면 모든 회원이 회원 기본 종류로 보인다. 관리자 화면은 `name` 고정이라 따라 바뀌지 않는다.

## 설정 값

설정 범주 `member` 의 세 키다.

| 키 | 모양 | 기본값 |
| --- | --- | --- |
| `display_name_selectable_kinds` | 회원이 고를 수 있는 종류 키 배열 | `["name"]` |
| `display_name_member_default_kind` | 회원이 고르지 않았을 때의 종류 키 | `"name"` |
| `display_name_areas` | `{영역 키: {mode, kind}}`. `kind` 는 `mode` 가 `fixed` 일 때만 | 사이트 기본은 회원이 고른 값, 관리자 화면은 `name` 고정 |

회원이 고른 종류는 `users.display_name_kind` 에 저장된다(고르지 않았으면 NULL).

## 계산기

```php
use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\DisplayNameResolver;

$resolver = app(DisplayNameResolver::class);

// 한 명
$name = $resolver->resolve($post->user, 'sirsoft-board:posts', ['board' => $board]);

// 비회원 글: 회원이 없으면 넘겨준 이름을 그대로 쓴다
$name = $resolver->resolve(null, 'sirsoft-board:posts', ['guest_name' => $post->author_name]);

// 여러 명: 추가 항목 값을 쿼리 한 번으로 읽고 회원 ID => 이름으로 돌려준다
$names = $resolver->resolveMany($posts->pluck('user')->filter(), 'sirsoft-board:posts', ['board' => $board]);

// 리소스가 행마다 resolve() 를 부르는 구조면 그 전에 한 번 읽어 둔다
$resolver->preload($posts->pluck('user')->filter(), 'sirsoft-board:posts', ['board' => $board]);
```

| 메서드 | 쓰임 |
| --- | --- |
| `resolve(?User $user, string $area, array $context = [])` | 표시 이름. 보일 값이 없으면 null |
| `resolveMany(iterable $users, string $area, array $context = [])` | 회원 ID => 표시 이름 |
| `preload(iterable $users, ?string $area = null, array $context = [])` | 추가 항목 값을 미리 읽어 둔다. 추가 항목 종류가 쓰이지 않으면 쿼리하지 않는다 |
| `effectiveKind(?User $user, string $area, array $context = [])` | 이 회원이 이 영역에서 보이는 종류 키 |
| `areaRule(string $area, array $context = [])` | 상속을 다 따라간 방식과 종류 |
| `computeAs(User $user, string $kind, array $context = [])` | 영역 설정과 무관하게 지정한 종류로 계산 (미리보기) |
| `columnsFor(string $area, array $context = [])` | 계산에 필요한 users 컬럼 |
| `memberKind(?User $user)` | 「회원이 고른 값」 방식에서 이 회원에게 적용되는 종류 키 |
| `candidateKinds(string $area, array $context = [])` | 그 영역에서 회원에 따라 쓰일 수 있는 종류 키 목록. 고정이면 하나 |
| `forget(?User $user = null)` | 읽어 둔 추가 항목 값을 버린다. 같은 요청 안에서 회원의 항목 값을 바꾼 뒤 이름을 다시 계산할 때 부른다 |

`$area` 를 생략하면 `core:site` 다. 등록되지 않은 영역 키도 `core:site` 로 계산한다.

규칙:

- 탈퇴한 회원은 종류와 무관하게 「탈퇴한 사용자」 문구로 보인다.
- 비회원(`$user` 가 null)은 문맥의 `guest_name` 을 그대로 보인다. 없으면 null 이다.
- 마지막에 필터 `core.display_name.filter_resolved` 를 태운다. 필터가 빈 문자열이나 문자열이 아닌 값을 돌려주면 계산한 값을 그대로 쓴다.
- 지금 없는 종류(꺼진 확장, 지워진 추가 항목)를 계산하라고 하면 `name` 의 대체 순서로 계산한다.
- 사용자 화면 응답의 작성자 이름 키에는 그 영역의 표시 이름만 싣는다. 실명은 관리자 요청이고 회원 조회 권한이 있을 때만 별도 키 `real_name` 으로 싣는다.

## 응답에 이름 싣기와 실명 보조

응답에 회원 이름을 싣는 코드는 `App\DisplayName\MemberNamePresenter` 를 쓴다. 표시 이름과 실명 보조(`real_name`)를 같은 규칙으로 만든다. 리소스(`BaseApiResource`, `BaseApiCollection` 을 상속한 클래스)에서는 같은 일을 하는 메서드를 바로 쓸 수 있다. 두 기반 클래스가 트레이트 `App\Http\Resources\Traits\ExposesMemberDisplayName` 을 쓴다.

```php
// 리소스 안에서: ['name' => 표시 이름] 또는 ['name' => 표시 이름, 'real_name' => 실명]
'author' => [
    'uuid' => $this->user->uuid,
    ...$this->memberNameFields($this->user, $request, 'vendor-board:admin'),
],

// 컬렉션에서 행을 만들기 전에: 읽어 둔 회원 관계를 모아 추가 항목 값을 한 번에 읽는다
$this->preloadMemberDisplayNames($this->collection, ['user'], $request, 'vendor-board:admin');

// 리소스 밖에서
$presenter = app(\App\DisplayName\MemberNamePresenter::class);
$fields = $presenter->fields($user, $request, 'vendor-board:admin');
```

| 메서드 | 쓰임 |
| --- | --- |
| `fields(?User $user, ?Request $request = null, ?string $area = null, array $context = [], string $nameKey = 'name')` | 이름 묶음. 실명을 실을 수 있을 때만 `real_name` 이 더해진다 |
| `name(?User $user, ?Request $request = null, ?string $area = null, array $context = [])` | 표시 이름만 |
| `realName(?User $user, ?string $displayName, ?Request $request = null)` | 실명 보조로 실을 값. 실을 수 없으면 null |
| `preload(iterable $users, ?Request $request = null, ?string $area = null, array $context = [])` | 회원 여럿의 값을 한 번에 읽어 둔다 |
| `preloadRelations(iterable $models, array $relations, ?Request $request = null, ?string $area = null, array $context = [])` | 모델 목록의 읽어 둔 회원 관계를 모아 한 번에 읽어 둔다 |
| `canExposeRealName(?Request $request = null)` | 이 요청에서 실명 보조를 볼 수 있는지 (대상 회원과 무관한 판정) |
| `canExposeRealNameOf(User $user, ?Request $request = null)` | 이 요청에서 이 회원의 실명 보조를 볼 수 있는지 |
| `isAdminRequest(?Request $request = null)` | 요청이 관리자 컨트롤러에 도달했는지 |
| `areaFor(?Request $request = null)` | 이 요청에서 쓸 코어 영역 키. `core:admin` 또는 `core:site` |

리소스 안에서 쓰는 트레이트 메서드(모두 `protected`):

| 메서드 | 쓰임 |
| --- | --- |
| `memberNameFields(mixed $user, ?Request $request = null, ?string $area = null, array $context = [])` | 이름 묶음. `$user` 가 `User` 가 아니면 이름 없음으로 본다 |
| `memberDisplayName(mixed $user, ?Request $request = null, ?string $area = null, array $context = [])` | 표시 이름만 |
| `preloadMemberDisplayNames(iterable $models, array $relations, ?Request $request = null, ?string $area = null)` | 모델 목록의 읽어 둔 회원 관계를 모아 한 번에 읽어 둔다. 이미 읽어 둔 관계만 모으고 지연 로딩을 일으키지 않는다 |

영역을 주지 않으면 요청이 관리자 컨트롤러(`AdminBaseController` 를 상속한 컨트롤러)에 도달했는지로 고른다. 관리자 화면이면 `core:admin`, 그 밖이면 `core:site` 다. 확장의 관리자 화면은 자기 영역 키(`{확장 식별자}:admin`)를 넘긴다.

`real_name` 이 실리는 조건은 다음을 모두 만족할 때다.

1. 요청이 관리자 컨트롤러에 도달했다. 헤더나 쿼리 같은 클라이언트 입력으로는 판정하지 않는다.
2. 보는 사람에게 회원 조회 권한(`core.users.read`)이 있다. 권한의 범위가 「본인」이나 「소유 역할」로 좁혀져 있으면 자기 자신의 실명만 실린다.
3. 실명이 비어 있지 않고 같은 응답의 표시 이름과 다르다.
4. 탈퇴한 회원이 아니다.

조건에 맞지 않으면 키 자체를 싣지 않는다. 화면은 `real_name` 이 있을 때만 표시 이름 옆에 보조로 그린다. 이름 키가 `name` 이 아닌 응답은 같은 꼬리말을 붙인 키를 쓴다(예: `created_by_name` 과 `created_by_real_name`, `requester_name` 과 `requester_real_name`).

## 코어 화면의 적용

| 자리 | 응답의 이름 | 영역 | 실명 보조 |
| --- | --- | --- | --- |
| 관리자 회원 목록, 상세, 수정 폼 | `name`, `nickname` 은 원본 그대로. `display_name` 이 함께 온다 | `core:site` (`display_name`) | 해당 없음 (원본이 그대로 온다) |
| 본인 응답(로그인 사용자, 내 정보) | `name` 은 원본 그대로. `display_name` 이 함께 온다 | `core:site`, 관리자 화면의 로그인 사용자는 `core:admin` | 해당 없음 |
| 공개 프로필 | `name` 이 표시 이름 | `core:site` | 싣지 않는다 |
| 관리자 회원 검색(자동완성) | `name`, `nickname` 원본과 `display_name`. 이름과 닉네임을 모두 찾는다 | `core:site` (`display_name`) | 해당 없음 |
| 활동 로그 | `actor_name`, `user.name` | `core:admin` | `user.real_name` |
| 알림 발송 이력 | `sender.name`, `recipient.name`. `recipient_name` 은 발송 당시 기록한 값 그대로 | `core:admin` | `sender.real_name`, `recipient.real_name` |
| 회원 알림함(관리자) | `member.name` | `core:admin` | `member.real_name` |
| 알림 템플릿의 지정 수신자 | `recipients[].display_names[]` | `core:admin` | 싣지 않는다 |
| 스케줄, 스케줄 실행 이력 | `creator.name`, `triggered_by.name` | `core:admin` | `creator.real_name`, `triggered_by.real_name` |
| 메뉴 | `creator.name` | `core:admin` | `creator.real_name` |
| 첨부 파일 | `creator.name` | 관리자 화면은 `core:admin`, 그 밖은 `core:site` | 관리자 화면에서만 `creator.real_name` |
| 레이아웃 버전, 레이아웃 확장 버전 | `created_by_name` | `core:admin` | `created_by_real_name` |
| 개인정보 수동 실행 | `requester_name` | `core:admin` | `requester_real_name` |
| 개인정보 처리 대상 명단(휴면, 비밀번호 만료, 최근 검색어) | `title` | `core:admin` | 싣지 않는다 |
| 개인정보 처리 대상 명단(탈퇴 회원 파기) | `title` 은 탈퇴 처리가 바꿔 둔 이름 그대로 | 해당 없음 | 싣지 않는다 |
| 방문자 통계의 회원(목록, 상세, 현재 접속자) | `user.name` | `core:admin` | `user.real_name` |
| 대시보드 최근 알림 | `recipient` | `core:admin` | 싣지 않는다 |
| 알림의 받는 사람 이름(`{recipient_name}`) | 발송 직전에 채널마다 계산 | `core:notification:{채널}` | 해당 없음 |
| 알림 발송 이력에 새로 남기는 이름 | `recipient_name` | `core:admin` | 해당 없음 |

기록물은 과거 값을 바꾸지 않는다. 알림 발송 이력의 `recipient_name` 과 활동 로그의 `properties` 에 이미 저장된 이름은 그대로 두고, 새 기록부터 그 시점의 관리자 영역 표시 이름을 저장한다.

탈퇴 회원 파기 명단은 표시 이름을 쓰지 않는다. 탈퇴한 회원의 표시 이름은 모두 같은 문구라 행을 구분할 수 없기 때문이다.

## 컬럼 제한

회원 관계를 컬럼 제한으로 읽을 때는 `User::displayColumns()` 를 쓴다.

```php
// 잘못된 예: 닉네임, 상태, 회원이 고른 종류가 없어 표시 이름을 계산할 수 없다
$query->with('user:id,name');

// 올바른 예
$query->with('user:'.User::displayColumns());
$query->with('creator:'.User::displayColumns('avatar'));
```

`User::DISPLAY_COLUMNS` 는 `id`, `uuid`, `name`, `nickname`, `email`, `status`, `display_name_kind` 다. 코어 종류 전부와 탈퇴 판정을 덮는다. 확장 종류가 다른 컬럼을 쓰면 `DisplayNameResolver::columnsFor()` 로 더한다.

## 검색과 정렬

화면에 보이는 이름이 영역마다, 회원마다 다르므로 `users.name` 만 찾거나 그것으로만 정렬하면 화면과 어긋난다.

```php
use App\DisplayName\DisplayNameQuery;

$helper = app(DisplayNameQuery::class);

// 사용자 화면의 작성자 검색: 그 회원이 그 영역에서 보이는 종류의 원본 컬럼만 찾는다
$helper->applySearch($query, $keyword, 'sirsoft-board:posts', [
    'relation' => 'user',
    'guest_column' => 'board_posts.author_name',
    'user_key' => 'board_posts.user_id',
    'context' => ['board' => $board],
]);

// 관리자 화면의 작성자 검색: 이름과 닉네임을 모두 찾는다
$helper->applyAdminSearch($query, $keyword, ['relation' => 'user']);

// 작성자순 정렬: users 를 dn_users 로 LEFT JOIN 하고 화면에 보이는 값의 순서로 정렬한다
$helper->orderByAuthor($query, 'sirsoft-board:posts', 'asc', [
    'user_key' => 'board_posts.user_id',
    'guest_column' => 'board_posts.author_name',
    'context' => ['board' => $board],
])->orderBy('board_posts.id');
```

| 메서드 | 동작 |
| --- | --- |
| `applySearch($query, $keyword, $area, $options)` | 가린 종류로 고정된 영역은 회원을 찾지 않는다. 「회원이 고른 값」 영역은 회원마다 자기에게 적용되는 종류의 컬럼만 찾는다(닉네임으로 보이는 회원을 이름으로 찾지 못한다) |
| `applyAdminSearch($query, $keyword, $options)` | 영역 설정과 무관하게 이름 또는 닉네임 |
| `constrainUsers($users, $keyword, $area, $context)` | 회원 쿼리 자체에 사용자 화면 검색 조건을 건다 |
| `constrainUsersForAdmin($users, $keyword)` | 회원 쿼리 자체에 이름 또는 닉네임 조건을 건다 |
| `orderByAuthor($query, $area, $direction, $options)` | 값이 없는 회원은 대체 종류의 값으로 자리를 잡는다. 가린 종류는 보이는 앞 글자만으로 정렬한다 |
| `sortExpression($query, $area, $options)` | 정렬 식만 돌려준다. 이전 글, 다음 글처럼 식 자체를 비교하는 쿼리에 쓴다. users 가 `users_alias` 로 붙어 있어야 한다 |
| `joinUsers($query, $userKey, $alias = 'dn_users')` | 정렬용 users 를 LEFT JOIN 한다. `sortExpression()` 만 쓸 때 먼저 부른다. Eloquent 쿼리가 컬럼을 고르지 않았으면 본 테이블의 컬럼만 고르게 한다 |
| `searchableKinds($area, $context)` | 그 영역에서 검색으로 찾을 수 있는 종류. 비어 있으면 그 영역은 회원을 찾지 않는다 |

옵션:

| 옵션 | 뜻 |
| --- | --- |
| `relation` | 회원 관계 이름. 기본 `user` |
| `guest_column` | 비회원 이름 컬럼. 주면 그 컬럼도 찾고, 정렬에서는 회원이 없는 행이 이 값으로 정렬된다 |
| `user_key` | 회원 외래키 컬럼. 정렬에는 필수다(없으면 `InvalidArgumentException`). 검색에서 `guest_column` 과 함께 주면 외래키가 NULL 인 행의 이름만 찾는다 |
| `context` | 영역의 인스턴스 판정에 쓸 호출 문맥 |
| `boolean` | 앞 조건과 잇는 방식. `and`(기본) 또는 `or` |
| `users_alias`, `joined` | 정렬용 users 별칭(기본 `dn_users`)과, 이미 그 별칭으로 붙여 두었는지 |

탈퇴한 회원은 문구로 보이므로 검색에서 빠진다. 같은 이름끼리의 순서는 호출자가 뒤에 기본키 정렬을 더해 고정한다.

## 회원의 종류 선택

회원은 가입 화면과 내 정보 수정에서 종류 하나를 고를 수 있다. 두 조건이 모두 맞아야 한다.

1. 역할 권한 `core.profile.display_name.choose` 가 있다. 가입 화면은 가입하면 받는 `user` 역할이 그 권한을 가졌는지로 판정한다.
2. 운영자가 정한 「회원이 고를 수 있는 종류」가 둘 이상이다.

판정은 `App\DisplayName\DisplayNameChoicePolicy` 한 곳이 한다. 요청 키는 `display_name_kind` 이고, 권한이 없거나 허용하지 않은 종류면 422 다. 관리자는 회원의 종류를 바꾸지 못한다.

## 닉네임 항목 잠금

표시 이름 설정이 닉네임을 쓰면 닉네임 항목이 잠긴다(`App\DisplayName\NicknameRequirement`).

| 조건 | 닉네임 항목 |
| --- | --- |
| 어떤 영역(또는 영역의 인스턴스)이 닉네임이 필요한 종류로 고정 | 사용, 필수로 잠근다 |
| 「회원이 고른 값」 영역이 있고, 회원이 고를 수 있는 종류나 회원 기본 종류에 닉네임이 필요한 종류가 있다 | 사용만 잠근다. 그 종류로 보이는 회원만 닉네임이 필수 |

잠금은 항목을 내줄 때 적용되고 저장된 항목 행은 바꾸지 않는다. 설정을 되돌리면 운영자가 정해 둔 값이 그대로 돌아온다. 잠금 사유 코드는 `nickname_display` 다.

## 확장이 종류를 등록하는 법

`module.php` 또는 `plugin.php` 에 게터를 둔다.

```php
public function getDisplayNameKinds(): array
{
    return [\Vendor\Rank\DisplayName\TitleKind::class];
}
```

```php
use App\DisplayName\Kinds\AbstractDisplayNameKind;
use App\Models\User;

class TitleKind extends AbstractDisplayNameKind
{
    public function key(): string
    {
        return 'vendor-rank:title';
    }

    public function label(?string $locale = null): string
    {
        return __('vendor-rank::display_name.title', [], $locale);
    }

    public function compute(User $user, array $context = []): ?string
    {
        // 값이 없으면 null 을 돌려준다. 계산기가 fallback() 의 종류로 넘어간다.
        return $user->nickname ? '용사 '.$user->nickname : null;
    }
}
```

| 메서드 | 뜻 | 기본 구현 |
| --- | --- | --- |
| `key()` | 종류 키. 영문 소문자, 숫자, `_`, `-`, `:` 로 120자까지. `field:` 로 시작할 수 없다. `{확장 식별자}:{이름}` 형식을 권한다 | 없음 |
| `label()` | 화면에 보일 이름 | 번역 키 `display_name.kinds.{키}` |
| `compute()` | 표시 이름. 값이 없으면 null | 없음 |
| `columns()` | 계산에 필요한 users 컬럼 | 빈 배열 |
| `searchColumns()` | 사용자 화면 검색이 찾을 users 컬럼. 가린 종류는 빈 배열 | 빈 배열 |
| `sortExpression($usersAlias)` | 작성자순 정렬 식. 인자는 접두와 인용이 적용된 users 참조 | null |
| `requiresNickname()` | 닉네임이 필요한지. true 인 종류가 영역에 고정되면 닉네임 항목이 잠긴다 | false |
| `fallback()` | 값이 없을 때 대신 쓸 종류 키 | `masked_id` |

게터는 클래스명 또는 인스턴스의 목록을 돌려준다. 종류는 DB 에 저장되지 않고 요청마다 등록소가 켜진 확장의 선언을 모은다. 키 형식이 맞지 않는 종류는 건너뛰고, 같은 키가 이미 있으면 먼저 등록된 쪽을 유지한다. 두 경우 모두 경고 로그가 남는다.

확장이 꺼지면 종류도 빠진다. 그 종류를 가리키던 영역 설정은 상위 영역을 따르고, 그 종류를 고른 회원은 회원 기본 종류로 보인다.

## 확장이 영역을 등록하는 법

```php
public function getDisplayNameAreas(): array
{
    return [
        [
            'key' => 'vendor-board:posts',
            'label' => 'vendor-board::display_name.areas.posts',
            'audience' => 'user',
            'parent' => 'core:site',
            'default' => ['mode' => 'inherit'],
            // 게시판마다 설정이 다르면: 호출 문맥을 받아 그 게시판의 설정 또는 null(따름)
            'instance_resolver' => [\Vendor\Board\DisplayName\BoardSetting::class, 'forContext'],
            // 게시판들이 쓰고 있는 설정 목록: 닉네임 항목 잠금 판정과 추가 항목 삭제 차단 안내에 쓰인다
            'used_kinds_provider' => [\Vendor\Board\DisplayName\BoardSetting::class, 'usages'],
        ],
        [
            'key' => 'vendor-board:admin',
            'label' => 'vendor-board::display_name.areas.admin',
            'audience' => 'admin',
        ],
    ];
}
```

| 키 | 뜻 |
| --- | --- |
| `key` | 영역 키. `{확장 식별자}:{이름}` 형식 |
| `label` | 운영자 화면에 보일 이름. 번역 키 또는 다국어 배열 |
| `audience` | `user`(기본), `admin`, `notification` |
| `parent` | 상위 영역 키. 생략하면 `audience` 가 `admin` 일 때 `core:admin`, 그 밖에는 `core:site` |
| `default` | 설정에 없을 때의 값 `{mode, kind}`. 생략하면 따름 |
| `instance_resolver` | `fn (array $context): ?array` — `{mode: fixed 또는 member, kind}` 또는 null |
| `used_kinds_provider` | `fn (): array` — `[{mode, kind, label}]`. `label` 은 인스턴스 이름 |
| `sort_order` | 운영자 화면의 표시 순서 |

등록한 영역은 환경설정의 회원 탭에 나타나고 설정 값은 `member.display_name_areas[{영역 키}]` 에 저장된다. 영역 선언도 DB 에 저장되지 않고 요청마다 등록소가 모은다. 키 형식(영문 소문자, 숫자, `_`, `-` 로 된 마디를 `:` 로 이은 두 마디 이상)이 맞지 않는 선언은 건너뛰고, 같은 키가 이미 있으면 먼저 등록된 쪽을 유지한다.

`instance_resolver` 와 `used_kinds_provider` 는 클로저, `[클래스, 메서드]`, `__invoke` 를 가진 클래스명 가운데 하나로 준다. 클래스는 컨테이너에서 꺼낸다. `instance_resolver` 가 `fixed` 나 `member` 가 아닌 방식을 돌려주면 따름으로 읽는다.

## 훅

| 훅 | 종류 | 인자 |
| --- | --- | --- |
| `core.display_name.filter_kinds` | 필터 | `array $kinds` (종류 키 => 종류). 추가 항목 종류는 이 필터 뒤에 더해진다 |
| `core.display_name.filter_areas` | 필터 | `array $areas` (영역 키 => `DisplayNameArea`) |
| `core.display_name.filter_resolved` | 필터 | `?string $name, ?User $user, string $area, array $context` (`$context['kind']` 에 적용된 종류) |
| `core.user.display_name_changed` | 액션 | `User $user, array $changed` (`name`, `nickname`, `email`, `display_name_kind`, `field:{키}` 가운데 바뀐 것) |

표시 이름은 저장하지 않고 볼 때마다 계산한다. 이름이 구워진 캐시를 가진 확장은 `core.user.display_name_changed` 를 구독해 그 회원이 나오는 화면을 무효화한다. 회원 탭 설정을 저장하면 코어가 검색 엔진용 미리 그린 화면을 전부 비운다.

## 관련 문서

- [항목 집합과 항목 선언](field-sets.md)
- [권한](permissions.md)
- [훅](hooks.md)
