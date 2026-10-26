<?php

namespace Plugins\Sirsoft\Sso\Tests\Fixtures\Ldap;

use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Services\Ldap\DirectoryClientInterface;

/**
 * 시험용 가짜 디렉터리 — 서버 없이 LDAP 드라이버를 시험한다.
 *
 * 검색 필터를 실제로 해석한다(`&`, `|`, `!`, 같음, 존재 `=*`, 값의 `\xx` 이스케이프) — 그래서 이스케이프되지 않은 입력(`*)(uid=*`)이
 * 필터 구조를 바꾸면 결과가 달라지고, 이스케이프된 입력은 글자 그대로 비교된다. 바인드는 등록한 DN 과 비밀번호가 맞을 때만 성공하고,
 * 빈 비밀번호 바인드는 실제 서버처럼 「익명 바인드 성공」으로 돌려준다(드라이버가 그 전에 막아야 한다). 모든 호출을 기록한다.
 *
 * @since 1.0.0
 */
class FakeDirectoryClient implements DirectoryClientInterface
{
    /** @var array<string, array{password: string|null, attributes: array<string, array<int, string>>}> DN → 항목 */
    public array $entries = [];

    /** @var array<int, array{0: string, 1: mixed}> 호출 기록 [동작, 인자] */
    public array $calls = [];

    /** @var string|null 다음 호출에서 낼 장애 (connect, bind, search) */
    public ?string $failOn = null;

    /** @var bool 연결 여부 */
    private bool $connected = false;

    /**
     * 항목을 더합니다.
     *
     * @param  string  $dn  항목 DN
     * @param  array<string, string|array<int, string>>  $attributes  속성 (이름은 대소문자 무관)
     * @param  string|null  $password  바인드 비밀번호 (null 이면 바인드 불가)
     * @return $this 체이닝
     */
    public function add(string $dn, array $attributes, ?string $password = null): static
    {
        $normalized = [];
        foreach ($attributes as $name => $value) {
            $normalized[mb_strtolower($name)] = array_values((array) $value);
        }
        $this->entries[$dn] = ['password' => $password, 'attributes' => $normalized];

        return $this;
    }

    /**
     * 동작별 호출 수.
     *
     * @param  string  $action  동작 이름 (connect, bind, search)
     * @return int 횟수
     */
    public function count(string $action): int
    {
        return count(array_filter($this->calls, static fn ($call) => $call[0] === $action));
    }

    /**
     * 기록된 검색 필터.
     *
     * @return array<int, string> 필터
     */
    public function filters(): array
    {
        return array_values(array_map(static fn ($call) => $call[1]['filter'], array_filter($this->calls, static fn ($call) => $call[0] === 'search')));
    }

    /**
     * 연결합니다.
     *
     * @param  array<string, mixed>  $options  연결 옵션
     * @return void 반환값 없음
     *
     * @throws SsoException `failOn=connect` 면 `ldap_unavailable`
     */
    public function connect(array $options): void
    {
        $this->calls[] = ['connect', $options];
        if ($this->failOn === 'connect') {
            throw new SsoException('ldap_unavailable', 'fake connect timeout');
        }
        $this->connected = true;
    }

    /**
     * 바인드합니다.
     *
     * @param  string|null  $dn  항목 DN
     * @param  string|null  $password  비밀번호
     * @return bool 성공 여부
     *
     * @throws SsoException `failOn=bind` 면 `ldap_unavailable`
     */
    public function bind(?string $dn, ?string $password): bool
    {
        $this->calls[] = ['bind', ['dn' => $dn, 'password_given' => $password !== null && $password !== '']];
        if (! $this->connected || $this->failOn === 'bind') {
            throw new SsoException('ldap_unavailable', 'fake bind failure');
        }

        // 실제 서버처럼 — DN 이 있어도 비밀번호가 비면 익명 바인드로 「성공」한다.
        if ($dn === null || $password === null || $password === '') {
            return true;
        }

        $entry = $this->entries[$dn] ?? null;

        return $entry !== null && $entry['password'] !== null && hash_equals($entry['password'], $password);
    }

    /**
     * 검색합니다.
     *
     * @param  string  $baseDn  기준 DN
     * @param  string  $filter  필터
     * @param  array<int, string>  $attributes  가져올 속성
     * @param  int  $sizeLimit  최대 건수
     * @return array<int, array{dn: string, attributes: array<string, array<int, string>>}> 항목
     *
     * @throws SsoException `failOn=search` 면 `ldap_unavailable`
     */
    public function search(string $baseDn, string $filter, array $attributes, int $sizeLimit = 0): array
    {
        $this->calls[] = ['search', ['base' => $baseDn, 'filter' => $filter]];
        if (! $this->connected || $this->failOn === 'search') {
            throw new SsoException('ldap_unavailable', 'fake search failure');
        }

        $tree = $this->parse($filter);
        $found = [];
        foreach ($this->entries as $dn => $entry) {
            if (! str_ends_with(mb_strtolower($dn), mb_strtolower($baseDn))) {
                continue;
            }
            if ($tree !== null && $this->matches($tree, $entry['attributes'])) {
                $found[] = ['dn' => $dn, 'attributes' => $entry['attributes']];
                if ($sizeLimit > 0 && count($found) >= $sizeLimit) {
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * 연결을 닫습니다.
     *
     * @return void 반환값 없음
     */
    public function close(): void
    {
        $this->calls[] = ['close', null];
        $this->connected = false;
    }

    /**
     * 필터를 트리로 읽습니다 — 형식이 깨지면 null(아무것도 찾지 않음).
     *
     * @param  string  $filter  필터
     * @return array<string, mixed>|null 트리
     */
    private function parse(string $filter): ?array
    {
        $pos = 0;
        $tree = $this->node($filter, $pos);

        return $tree !== null && $pos === strlen($filter) ? $tree : null;
    }

    /**
     * 괄호 하나를 읽습니다.
     *
     * @param  string  $s  필터
     * @param  int  $pos  위치 (읽은 만큼 나아간다)
     * @return array<string, mixed>|null 노드
     */
    private function node(string $s, int &$pos): ?array
    {
        if (($s[$pos] ?? '') !== '(') {
            return null;
        }
        $pos++;
        $op = $s[$pos] ?? '';

        if (in_array($op, ['&', '|', '!'], true)) {
            $pos++;
            $children = [];
            while (($s[$pos] ?? '') === '(') {
                $child = $this->node($s, $pos);
                if ($child === null) {
                    return null;
                }
                $children[] = $child;
            }
            if (($s[$pos] ?? '') !== ')') {
                return null;
            }
            $pos++;

            return ['op' => $op, 'children' => $children];
        }

        $end = strpos($s, ')', $pos);
        if ($end === false) {
            return null;
        }
        $item = substr($s, $pos, $end - $pos);
        $pos = $end + 1;
        if (! str_contains($item, '=') || str_contains($item, '(')) {
            return null;
        }
        [$attribute, $value] = explode('=', $item, 2);

        if ($value === '*') {
            return ['op' => 'present', 'attribute' => mb_strtolower($attribute)];
        }

        if (str_contains($value, '*')) {
            // 와일드카드는 이스케이프되지 않은 입력이 필터에 들어갔다는 뜻 — 이 가짜 디렉터리는 부분 일치로 해석한다.
            return ['op' => 'wildcard', 'attribute' => mb_strtolower($attribute), 'pattern' => '/^'.str_replace('\*', '.*', preg_quote($this->unescape($value), '/')).'$/i'];
        }

        return ['op' => 'eq', 'attribute' => mb_strtolower($attribute), 'value' => $this->unescape($value)];
    }

    /**
     * 노드가 항목에 맞는지 봅니다.
     *
     * @param  array<string, mixed>  $node  노드
     * @param  array<string, array<int, string>>  $attributes  속성
     * @return bool 맞으면 true
     */
    private function matches(array $node, array $attributes): bool
    {
        return match ($node['op']) {
            '&' => array_reduce($node['children'], fn ($carry, $child) => $carry && $this->matches($child, $attributes), true),
            '|' => array_reduce($node['children'], fn ($carry, $child) => $carry || $this->matches($child, $attributes), false),
            '!' => ! $this->matches($node['children'][0] ?? ['op' => 'present', 'attribute' => ''], $attributes),
            'present' => $node['attribute'] === 'objectclass' || isset($attributes[$node['attribute']]),
            'wildcard' => (bool) array_filter($attributes[$node['attribute']] ?? [], static fn ($v) => preg_match($node['pattern'], $v) === 1),
            default => (bool) array_filter($attributes[$node['attribute']] ?? [], static fn ($v) => $node['attribute'] === 'objectguid' ? $v === $node['value'] : strcasecmp($v, $node['value']) === 0),
        };
    }

    /**
     * 필터 값의 `\xx` 이스케이프를 풉니다.
     *
     * @param  string  $value  값
     * @return string 원래 값
     */
    private function unescape(string $value): string
    {
        return (string) preg_replace_callback('/\\\\([0-9A-Fa-f]{2})/', static fn ($m) => chr((int) hexdec($m[1])), $value);
    }
}
