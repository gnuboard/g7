<?php

namespace App\DisplayName;

use App\DisplayName\Contracts\DisplayNameKindInterface;
use App\DisplayName\Kinds\FieldValueKind;
use App\Enums\UserStatus;
use App\Models\FieldValue;
use App\Models\User;
use App\Search\KeywordSearch;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * 표시 이름 기준의 작성자 검색과 정렬 헬퍼.
 *
 * 화면에 보이는 이름이 영역마다, 회원마다 다르므로 `users.name` 만 찾거나 그것으로만 정렬하면 화면과 어긋난다.
 * 이 헬퍼는 영역 설정을 읽어 조건과 정렬 식을 만든다.
 *
 * | 메서드 | 쓰는 곳 | 찾는 값 |
 * | --- | --- | --- |
 * | applySearch | 사용자 화면의 작성자 검색 | 그 회원이 그 영역에서 보이는 종류의 원본 컬럼만. 가린 종류는 찾지 않는다 |
 * | applyAdminSearch | 관리자 화면의 작성자 검색 | 이름과 닉네임 모두 |
 * | orderByAuthor | 작성자순 정렬 | 화면에 보이는 값과 같은 순서의 식 (대체 순서 포함) |
 *
 * 탈퇴한 회원은 이름이 문구로 바뀌어 보이므로 검색에서 빠진다.
 *
 * @since 7.2.0
 */
class DisplayNameQuery
{
    /** 정렬용으로 붙이는 users 별칭 */
    public const USERS_ALIAS = 'dn_users';

    /**
     * @param  DisplayNameResolver  $resolver  표시 이름 계산기 (영역의 종류 판정)
     * @param  DisplayNameKindRegistry  $kinds  종류 등록소
     * @param  DisplayNameSettings  $settings  표시 이름 설정
     */
    public function __construct(
        protected DisplayNameResolver $resolver,
        protected DisplayNameKindRegistry $kinds,
        protected DisplayNameSettings $settings,
    ) {}

    /**
     * 사용자 화면의 작성자 검색 조건을 겁니다.
     *
     * 옵션:
     * - `relation` — 회원 관계 이름 (기본 `user`)
     * - `guest_column` — 비회원 이름 컬럼 (예: `board_posts.author_name`). 주면 그 컬럼도 찾는다
     * - `user_key` — 회원 외래키 컬럼 (예: `board_posts.user_id`). `guest_column` 과 함께 주면 비회원 행(외래키가 NULL)의 이름만 찾는다
     * - `context` — 영역의 인스턴스 판정에 쓸 호출 문맥 (예: `['board' => $board]`)
     * - `boolean` — 앞 조건과 잇는 방식 `and`(기본) 또는 `or`
     *
     * 영역이 가린 종류로 고정이고 비회원 컬럼도 없으면 아무것도 찾지 않는다(결과 0건).
     *
     * @param  EloquentBuilder  $query  작성자 관계를 가진 모델의 쿼리
     * @param  string  $keyword  검색어
     * @param  string  $area  영역 키
     * @param  array<string, mixed>  $options  옵션
     * @return EloquentBuilder 조건을 건 쿼리
     */
    public function applySearch(EloquentBuilder $query, string $keyword, string $area = DisplayNameAreaRegistry::AREA_SITE, array $options = []): EloquentBuilder
    {
        $context = is_array($options['context'] ?? null) ? $options['context'] : [];
        $searchable = $this->searchableKinds($area, $context);
        $memberMode = $this->resolver->areaRule($area, $context)['mode'] === DisplayNameArea::MODE_MEMBER;

        return $this->applyAuthorCondition(
            $query,
            $keyword,
            $options,
            $searchable === [] ? null : fn (EloquentBuilder $users) => $this->constrainByKinds($users, $keyword, $searchable, $memberMode),
        );
    }

    /**
     * 관리자 화면의 작성자 검색 조건을 겁니다 (이름과 닉네임을 모두 찾는다).
     *
     * 옵션은 {@see self::applySearch()} 와 같다(`context` 는 쓰지 않는다).
     *
     * @param  EloquentBuilder  $query  작성자 관계를 가진 모델의 쿼리
     * @param  string  $keyword  검색어
     * @param  array<string, mixed>  $options  옵션
     * @return EloquentBuilder 조건을 건 쿼리
     */
    public function applyAdminSearch(EloquentBuilder $query, string $keyword, array $options = []): EloquentBuilder
    {
        return $this->applyAuthorCondition(
            $query,
            $keyword,
            $options,
            fn (EloquentBuilder $users) => $this->constrainUsersForAdmin($users, $keyword),
        );
    }

    /**
     * 회원 쿼리 자체에 사용자 화면 검색 조건을 겁니다 (회원을 직접 찾는 화면용).
     *
     * @param  EloquentBuilder  $users  회원 쿼리
     * @param  string  $keyword  검색어
     * @param  string  $area  영역 키
     * @param  array<string, mixed>  $context  호출 문맥
     * @return EloquentBuilder 조건을 건 쿼리 (찾을 수 있는 종류가 없으면 결과 0건)
     */
    public function constrainUsers(EloquentBuilder $users, string $keyword, string $area = DisplayNameAreaRegistry::AREA_SITE, array $context = []): EloquentBuilder
    {
        $searchable = $this->searchableKinds($area, $context);
        if ($searchable === []) {
            return $users->whereRaw('1 = 0');
        }

        $memberMode = $this->resolver->areaRule($area, $context)['mode'] === DisplayNameArea::MODE_MEMBER;

        return $this->constrainByKinds($users, $keyword, $searchable, $memberMode);
    }

    /**
     * 회원 쿼리 자체에 관리자 화면 검색 조건을 겁니다 (이름 또는 닉네임).
     *
     * @param  EloquentBuilder  $users  회원 쿼리
     * @param  string  $keyword  검색어
     * @return EloquentBuilder 조건을 건 쿼리
     */
    public function constrainUsersForAdmin(EloquentBuilder $users, string $keyword): EloquentBuilder
    {
        $pattern = $this->likePattern($keyword);

        return $users->where(function (EloquentBuilder $where) use ($pattern) {
            $where->where($where->qualifyColumn('name'), 'like', $pattern)
                ->orWhere($where->qualifyColumn('nickname'), 'like', $pattern);
        });
    }

    /**
     * 작성자순으로 정렬합니다 (화면에 보이는 이름 순서).
     *
     * users 를 별칭 `dn_users` 로 LEFT JOIN 하고 정렬 식을 건다. 같은 이름끼리의 순서는 호출자가 뒤에 기본키 정렬을 더해 고정한다.
     *
     * 옵션:
     * - `user_key` (필수) — 회원 외래키 컬럼 (예: `board_posts.user_id`)
     * - `guest_column` — 비회원 이름 컬럼. 회원이 없는 행은 이 값으로 정렬한다
     * - `context` — 영역의 인스턴스 판정에 쓸 호출 문맥
     * - `users_alias` — users 별칭 (기본 `dn_users`). 이미 그 별칭으로 붙여 두었으면 `joined => true`
     *
     * @param  EloquentBuilder|QueryBuilder  $query  쿼리
     * @param  string  $area  영역 키
     * @param  string  $direction  asc 또는 desc
     * @param  array<string, mixed>  $options  옵션
     * @return EloquentBuilder|QueryBuilder 정렬을 건 쿼리
     */
    public function orderByAuthor(EloquentBuilder|QueryBuilder $query, string $area = DisplayNameAreaRegistry::AREA_SITE, string $direction = 'asc', array $options = []): EloquentBuilder|QueryBuilder
    {
        $alias = is_string($options['users_alias'] ?? null) ? $options['users_alias'] : self::USERS_ALIAS;

        if (($options['joined'] ?? false) !== true) {
            $this->joinUsers($query, (string) ($options['user_key'] ?? ''), $alias);
        }

        $expression = $this->sortExpression($query, $area, ['users_alias' => $alias] + $options);

        return $query->orderBy($expression, strtolower($direction) === 'desc' ? 'desc' : 'asc');
    }

    /**
     * 정렬용 users 를 LEFT JOIN 합니다.
     *
     * Eloquent 쿼리가 컬럼을 고르지 않았으면 본 테이블의 컬럼만 고르게 한다(붙인 users 의 `id` 가 모델의 `id` 를 덮지 않게).
     *
     * @param  EloquentBuilder|QueryBuilder  $query  쿼리
     * @param  string  $userKey  회원 외래키 컬럼 (예: `board_posts.user_id`)
     * @param  string  $alias  users 별칭
     * @return EloquentBuilder|QueryBuilder 붙인 쿼리
     */
    public function joinUsers(EloquentBuilder|QueryBuilder $query, string $userKey, string $alias = self::USERS_ALIAS): EloquentBuilder|QueryBuilder
    {
        if ($userKey === '') {
            throw new \InvalidArgumentException('orderByAuthor requires the user_key option (the member foreign key column).');
        }

        if ($query instanceof EloquentBuilder && $query->getQuery()->columns === null) {
            $query->select($query->getModel()->getTable().'.*');
        }

        return $query->leftJoin((new User)->getTable().' as '.$alias, $alias.'.id', '=', $userKey);
    }

    /**
     * 작성자순 정렬 식을 반환합니다 (이전 글, 다음 글처럼 식 자체를 비교해야 하는 쿼리용).
     *
     * users 가 `users_alias`(기본 `dn_users`)로 붙어 있어야 한다. 옵션은 {@see self::orderByAuthor()} 와 같다.
     *
     * @param  EloquentBuilder|QueryBuilder  $query  쿼리 (접두와 인용 규칙을 얻는다)
     * @param  string  $area  영역 키
     * @param  array<string, mixed>  $options  옵션
     * @return Expression 정렬 식
     */
    public function sortExpression(EloquentBuilder|QueryBuilder $query, string $area = DisplayNameAreaRegistry::AREA_SITE, array $options = []): Expression
    {
        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $grammar = $base->getGrammar();
        $connection = $base->getConnection();
        $alias = $grammar->wrapTable(is_string($options['users_alias'] ?? null) ? $options['users_alias'] : self::USERS_ALIAS);
        $context = is_array($options['context'] ?? null) ? $options['context'] : [];

        $rule = $this->resolver->areaRule($area, $context);
        if ($rule['mode'] === DisplayNameArea::MODE_MEMBER) {
            $default = $this->settings->memberDefaultKind();
            $sql = 'CASE';
            foreach ($this->settings->selectableKinds() as $key) {
                if ($key === $default) {
                    continue;
                }
                $sql .= ' WHEN '.$alias.'.display_name_kind = '.$connection->escape($key).' THEN '.$this->kindSortSql($key, $alias);
            }
            $sql = $sql === 'CASE' ? $this->kindSortSql($default, $alias) : $sql.' ELSE '.$this->kindSortSql($default, $alias).' END';
        } else {
            $sql = $this->kindSortSql((string) $rule['kind'], $alias);
        }

        $guestColumn = $options['guest_column'] ?? null;
        if (is_string($guestColumn) && $guestColumn !== '') {
            $sql = 'CASE WHEN '.$alias.'.id IS NULL THEN '.$grammar->wrap($guestColumn).' ELSE '.$sql.' END';
        }

        return DB::raw($sql);
    }

    /**
     * 영역에서 검색으로 찾을 수 있는 종류를 반환합니다.
     *
     * @param  string  $area  영역 키
     * @param  array<string, mixed>  $context  호출 문맥
     * @return array<string, DisplayNameKindInterface> 종류 키 => 종류 (원본 컬럼이나 추가 항목 값을 찾을 수 있는 것만)
     */
    public function searchableKinds(string $area = DisplayNameAreaRegistry::AREA_SITE, array $context = []): array
    {
        $searchable = [];
        foreach ($this->resolver->candidateKinds($area, $context) as $key) {
            $kind = $this->kinds->get($key);
            if ($kind !== null && ($kind->searchColumns() !== [] || $kind instanceof FieldValueKind)) {
                $searchable[$key] = $kind;
            }
        }

        return $searchable;
    }

    /**
     * 작성자 조건(회원 관계 + 비회원 컬럼)을 한 묶음으로 겁니다.
     *
     * @param  EloquentBuilder  $query  작성자 관계를 가진 모델의 쿼리
     * @param  string  $keyword  검색어
     * @param  array<string, mixed>  $options  옵션
     * @param  callable|null  $userConstraint  회원 쿼리에 걸 조건 (null 이면 회원은 찾지 않는다)
     * @return EloquentBuilder 조건을 건 쿼리
     */
    protected function applyAuthorCondition(EloquentBuilder $query, string $keyword, array $options, ?callable $userConstraint): EloquentBuilder
    {
        $relation = is_string($options['relation'] ?? null) ? $options['relation'] : 'user';
        $guestColumn = is_string($options['guest_column'] ?? null) && $options['guest_column'] !== '' ? $options['guest_column'] : null;
        $userKey = is_string($options['user_key'] ?? null) && $options['user_key'] !== '' ? $options['user_key'] : null;
        $boolean = ($options['boolean'] ?? 'and') === 'or' ? 'or' : 'and';
        $pattern = $this->likePattern($keyword);
        $withdrawn = UserStatus::Withdrawn->value;

        return $query->where(function (EloquentBuilder $where) use ($relation, $guestColumn, $userKey, $pattern, $userConstraint, $withdrawn) {
            $any = false;
            if ($userConstraint !== null) {
                $where->whereHas($relation, function (EloquentBuilder $users) use ($userConstraint, $withdrawn) {
                    $users->where($users->qualifyColumn('status'), '!=', $withdrawn);
                    $userConstraint($users);
                });
                $any = true;
            }
            if ($guestColumn !== null) {
                $where->orWhere(function (EloquentBuilder $guest) use ($guestColumn, $userKey, $pattern) {
                    if ($userKey !== null) {
                        $guest->whereNull($userKey);
                    }
                    $guest->where($guestColumn, 'like', $pattern);
                });
                $any = true;
            }
            if (! $any) {
                $where->whereRaw('1 = 0');
            }
        }, null, null, $boolean);
    }

    /**
     * 회원 쿼리에 종류별 검색 조건을 겁니다.
     *
     * 「회원이 고른 값」 방식이면 회원마다 자기에게 적용되는 종류의 컬럼만 찾는다. 닉네임으로 보이는 회원을 이름으로 찾지 못하게 하기 위해서다.
     *
     * @param  EloquentBuilder  $users  회원 쿼리
     * @param  string  $keyword  검색어
     * @param  array<string, DisplayNameKindInterface>  $kinds  찾을 종류
     * @param  bool  $memberMode  「회원이 고른 값」 방식인지
     * @return EloquentBuilder 조건을 건 쿼리
     */
    protected function constrainByKinds(EloquentBuilder $users, string $keyword, array $kinds, bool $memberMode): EloquentBuilder
    {
        $pattern = $this->likePattern($keyword);
        $selectable = $this->settings->selectableKinds();
        $default = $this->settings->memberDefaultKind();

        return $users->where(function (EloquentBuilder $where) use ($kinds, $pattern, $memberMode, $selectable, $default) {
            foreach ($kinds as $key => $kind) {
                $where->orWhere(function (EloquentBuilder $each) use ($key, $kind, $pattern, $memberMode, $selectable, $default) {
                    if ($memberMode) {
                        $this->whereMemberKind($each, $key, $selectable, $default);
                    }
                    $each->where(fn (EloquentBuilder $match) => $this->whereKindMatches($match, $kind, $pattern));
                });
            }
        });
    }

    /**
     * 「이 회원에게 적용되는 종류가 이것」 조건을 겁니다.
     *
     * @param  EloquentBuilder  $users  회원 쿼리
     * @param  string  $key  종류 키
     * @param  array<int, string>  $selectable  회원이 고를 수 있는 종류
     * @param  string  $default  회원 기본 종류
     * @return void 반환값 없음
     */
    protected function whereMemberKind(EloquentBuilder $users, string $key, array $selectable, string $default): void
    {
        $column = $users->qualifyColumn('display_name_kind');
        $chosen = in_array($key, $selectable, true);

        $users->where(function (EloquentBuilder $where) use ($column, $key, $selectable, $default, $chosen) {
            if ($chosen) {
                $where->where($column, $key);
            }
            // 고르지 않았거나 허용에서 빠진 종류를 고른 회원은 회원 기본 종류로 보인다.
            if ($key === $default) {
                $where->orWhereNull($column)->orWhereNotIn($column, $selectable);
            }
            if (! $chosen && $key !== $default) {
                $where->whereRaw('1 = 0');
            }
        });
    }

    /**
     * 종류의 원본 값이 검색어를 포함한다는 조건을 겁니다.
     *
     * @param  EloquentBuilder  $users  회원 쿼리
     * @param  DisplayNameKindInterface  $kind  종류
     * @param  string  $pattern  LIKE 패턴
     * @return void 반환값 없음
     */
    protected function whereKindMatches(EloquentBuilder $users, DisplayNameKindInterface $kind, string $pattern): void
    {
        foreach ($kind->searchColumns() as $column) {
            $users->orWhere($users->qualifyColumn($column), 'like', $pattern);
        }

        if ($kind instanceof FieldValueKind && $kind->definition()->exists) {
            $definitionId = (int) $kind->definition()->id;
            $ownerKey = $users->qualifyColumn('id');
            $users->orWhereExists(function (QueryBuilder $values) use ($definitionId, $ownerKey, $pattern) {
                $table = (new FieldValue)->getTable();
                $values->selectRaw('1')
                    ->from($table)
                    ->where($table.'.field_definition_id', $definitionId)
                    ->where($table.'.owner_type', FieldValue::OWNER_USER)
                    ->whereColumn($table.'.owner_id', $ownerKey)
                    ->where($table.'.value_text', 'like', $pattern);
            });
        }
    }

    /**
     * 종류의 정렬 식(대체 순서 포함)을 만듭니다.
     *
     * @param  string  $key  종류 키
     * @param  string  $alias  users 테이블 참조 (접두와 인용이 적용된 것)
     * @return string SQL 식 (정렬할 수 있는 종류가 하나도 없으면 NULL)
     */
    protected function kindSortSql(string $key, string $alias): string
    {
        $chain = $this->kinds->chain($key) ?: $this->kinds->chain(DisplayNameSettings::FALLBACK_KIND);

        $parts = [];
        foreach ($chain as $kind) {
            $expression = $kind->sortExpression($alias);
            if ($expression !== null && $expression !== '') {
                $parts[] = "NULLIF({$expression}, '')";
            }
        }

        return match (count($parts)) {
            0 => 'NULL',
            1 => $parts[0],
            default => 'COALESCE('.implode(', ', $parts).')',
        };
    }

    /**
     * 검색어를 LIKE 패턴으로 바꿉니다 (와일드카드 문자는 글자 그대로 찾는다).
     *
     * @param  string  $keyword  검색어
     * @return string `%검색어%`
     */
    protected function likePattern(string $keyword): string
    {
        return '%'.KeywordSearch::escapeLikeWildcards($keyword).'%';
    }
}
