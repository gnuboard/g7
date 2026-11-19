<?php

namespace Plugins\Sirsoft\Sso\Services\Provisioning;

use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\Sso\Exceptions\ScimException;

/**
 * SCIM User 자원과 PatchOp 를 「바꿀 값」 평면 배열로 바꿉니다.
 *
 * 결과 키: `userName`, `externalId`, `name`, `email`, `mobile`, `title`, `department`, `employeeNumber`, `language`, `active`.
 * 값 null 은 「지움」(remove 연산), 키가 없으면 「그대로」다.
 *
 * IdP 마다 PatchOp 모양이 다르다 — 경로가 있는 연산(`path: "active"`), 경로 없이 값 객체(`value: {"active": false}`), 값 객체 안의 점
 * 경로(`value: {"name.givenName": "…"}`), 대문자 연산 이름(`Replace`)을 모두 받는다. 이 서버가 다루지 않는 속성은 무시하고 기록만 한다.
 *
 * @since 1.0.0
 */
final class ScimUserPayload
{
    /** @var array<string, string> 경로(소문자) → 결과 키 */
    private const PATHS = [
        'username' => 'userName',
        'externalid' => 'externalId',
        'displayname' => 'displayName',
        'name.formatted' => 'name',
        'name.givenname' => 'givenName',
        'name.familyname' => 'familyName',
        'title' => 'title',
        'active' => 'active',
        'preferredlanguage' => 'language',
        'locale' => 'language',
        'emails' => 'emails',
        'emails.value' => 'email',
        'phonenumbers' => 'phoneNumbers',
        'phonenumbers.value' => 'mobile',
        'department' => 'department',
        'employeenumber' => 'employeeNumber',
    ];

    /** 핵심 User 스키마 접두 (경로 앞에 붙어 올 수 있다) */
    private const CORE_PREFIX = 'urn:ietf:params:scim:schemas:core:2.0:user:';

    /** 기업 확장 접두 */
    private const ENTERPRISE_PREFIX = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:user:';

    /**
     * SCIM User 자원(POST, PUT 본문)을 바꿀 값으로.
     *
     * @param  array<string, mixed>  $resource  자원
     * @return array<string, mixed> 바꿀 값
     */
    public static function fromResource(array $resource): array
    {
        $raw = [];
        foreach ($resource as $key => $value) {
            if (! is_string($key) || in_array($key, ['schemas', 'id', 'meta', 'groups', 'password'], true)) {
                continue;
            }

            if (strcasecmp($key, rtrim(self::ENTERPRISE_PREFIX, ':')) === 0 && is_array($value)) {
                foreach ($value as $sub => $subValue) {
                    self::set($raw, self::ENTERPRISE_PREFIX.mb_strtolower((string) $sub), $subValue);
                }

                continue;
            }

            if (mb_strtolower($key) === 'name' && is_array($value)) {
                foreach ($value as $sub => $subValue) {
                    self::set($raw, 'name.'.mb_strtolower((string) $sub), $subValue);
                }

                continue;
            }

            self::set($raw, $key, $value);
        }

        return self::finish($raw);
    }

    /**
     * PatchOp Operations 를 바꿀 값으로.
     *
     * @param  array<int, mixed>  $operations  연산 목록
     * @return array<string, mixed> 바꿀 값
     *
     * @throws ScimException 연산 모양이 틀리면 400 invalidSyntax
     */
    public static function fromOperations(array $operations): array
    {
        $raw = [];

        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                throw new ScimException(400, 'invalidSyntax', __('sirsoft-sso::scim.error.invalid_patch'));
            }

            $op = mb_strtolower((string) ($operation['op'] ?? ''));
            if (! in_array($op, ['add', 'replace', 'remove'], true)) {
                throw new ScimException(400, 'invalidSyntax', __('sirsoft-sso::scim.error.invalid_patch'));
            }

            $path = isset($operation['path']) && is_string($operation['path']) && $operation['path'] !== '' ? $operation['path'] : null;
            $value = $operation['value'] ?? null;

            if ($op === 'remove') {
                if ($path === null) {
                    throw new ScimException(400, 'noTarget', __('sirsoft-sso::scim.error.invalid_patch'));
                }
                self::set($raw, $path, null);

                continue;
            }

            if ($path === null) {
                if (! is_array($value)) {
                    throw new ScimException(400, 'invalidSyntax', __('sirsoft-sso::scim.error.invalid_patch'));
                }
                foreach (self::fromResourceRaw($value) as $key => $item) {
                    $raw[$key] = $item;
                }

                continue;
            }

            self::set($raw, $path, $value);
        }

        return self::finish($raw);
    }

    /**
     * 자원 조각을 정리 전 값으로 (경로 없는 PatchOp 의 값 객체 — 점 경로 키도 받는다).
     *
     * @param  array<string, mixed>  $value  값 객체
     * @return array<string, mixed> 정리 전 값
     */
    private static function fromResourceRaw(array $value): array
    {
        $raw = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                continue;
            }

            if ((mb_strtolower($key) === 'name' || strcasecmp($key, rtrim(self::ENTERPRISE_PREFIX, ':')) === 0) && is_array($item)) {
                $prefix = mb_strtolower($key) === 'name' ? 'name.' : self::ENTERPRISE_PREFIX;
                foreach ($item as $sub => $subValue) {
                    self::set($raw, $prefix.mb_strtolower((string) $sub), $subValue);
                }

                continue;
            }

            self::set($raw, $key, $item);
        }

        return $raw;
    }

    /**
     * 경로 하나의 값을 정리 전 값에 넣습니다.
     *
     * @param  array<string, mixed>  $raw  정리 전 값
     * @param  string  $path  경로 (`emails[type eq "work"].value` 처럼 거르개가 붙을 수 있다)
     * @param  mixed  $value  값
     * @return void
     */
    private static function set(array &$raw, string $path, mixed $value): void
    {
        $normalized = mb_strtolower(trim($path));
        if (str_starts_with($normalized, self::CORE_PREFIX)) {
            $normalized = substr($normalized, strlen(self::CORE_PREFIX));
        } elseif (str_starts_with($normalized, self::ENTERPRISE_PREFIX)) {
            $normalized = substr($normalized, strlen(self::ENTERPRISE_PREFIX));
        }

        // emails[type eq "work"].value → emails.value, phoneNumbers[type eq "mobile"] → phonenumbers
        $normalized = (string) preg_replace('/\[[^\]]*\]/', '', $normalized);

        $key = self::PATHS[$normalized] ?? null;
        if ($key === null) {
            Log::debug('[sirsoft-sso] SCIM 속성 무시', ['path' => mb_substr($normalized, 0, 100)]);

            return;
        }

        $raw[$key] = $value;
    }

    /**
     * 정리 전 값을 바꿀 값으로 다듬습니다 (여러 값 속성에서 대표 값 고르기, 이름 조합, 참거짓 해석).
     *
     * @param  array<string, mixed>  $raw  정리 전 값
     * @return array<string, mixed> 바꿀 값
     */
    private static function finish(array $raw): array
    {
        $out = [];

        foreach (['userName', 'externalId', 'title', 'department', 'employeeNumber', 'language', 'email', 'mobile'] as $key) {
            if (array_key_exists($key, $raw)) {
                $out[$key] = self::scalar($raw[$key]);
            }
        }

        if (array_key_exists('emails', $raw)) {
            $out['email'] = self::pickMulti($raw['emails'], 'work');
        }
        if (array_key_exists('phoneNumbers', $raw)) {
            $out['mobile'] = self::pickMulti($raw['phoneNumbers'], 'mobile');
        }

        $name = self::scalar($raw['name'] ?? null)
            ?? self::scalar($raw['displayName'] ?? null)
            ?? (trim((self::scalar($raw['givenName'] ?? null) ?? '').' '.(self::scalar($raw['familyName'] ?? null) ?? '')) ?: null);
        if ($name !== null) {
            $out['name'] = $name;
        }

        if (array_key_exists('active', $raw)) {
            $active = $raw['active'];
            $out['active'] = is_bool($active) ? $active : (is_string($active) ? filter_var($active, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null);
        }

        if (isset($out['email']) && filter_var($out['email'], FILTER_VALIDATE_EMAIL) === false) {
            unset($out['email']);
        }

        return $out;
    }

    /**
     * 여러 값 속성(emails, phoneNumbers)에서 대표 값을 고릅니다 — primary, 원하는 type, 첫째 순.
     *
     * @param  mixed  $items  값 목록 (또는 단일 값)
     * @param  string  $type  원하는 type
     * @return string|null 값
     */
    private static function pickMulti(mixed $items, string $type): ?string
    {
        if (! is_array($items)) {
            return self::scalar($items);
        }

        $items = array_is_list($items) ? $items : [$items];
        $candidates = array_values(array_filter($items, static fn ($i) => is_array($i) && self::scalar($i['value'] ?? null) !== null));
        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $item) {
            if (($item['primary'] ?? false) === true || ($item['primary'] ?? null) === 'true') {
                return self::scalar($item['value']);
            }
        }
        foreach ($candidates as $item) {
            if (mb_strtolower((string) ($item['type'] ?? '')) === $type) {
                return self::scalar($item['value']);
            }
        }

        return self::scalar($candidates[0]['value']);
    }

    /**
     * 스칼라 값을 문자열로 (빈 값은 null).
     *
     * @param  mixed  $value  값
     * @return string|null 문자열
     */
    private static function scalar(mixed $value): ?string
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
