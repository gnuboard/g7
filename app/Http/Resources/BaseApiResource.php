<?php

namespace App\Http\Resources;

use App\Helpers\TimezoneHelper;
use App\Http\Resources\Traits\HasAbilityCheck;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\App;

class BaseApiResource extends JsonResource
{
    use HasAbilityCheck;
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }

    /**
     * 배열 데이터를 객체로 변환하여 접근 가능하게 합니다.
     *
     * @return object|mixed 객체로 변환된 리소스
     */
    protected function asObject()
    {
        if (is_array($this->resource)) {
            return (object) $this->resource;
        }

        return $this->resource;
    }

    /**
     * 배열이나 객체에서 안전하게 값을 가져옵니다.
     *
     * @param  string  $key  가져올 키
     * @param  mixed  $default  기본값 (키가 없을 경우)
     * @return mixed 값 또는 기본값
     */
    protected function getValue(string $key, $default = null)
    {
        if (is_array($this->resource)) {
            return $this->resource[$key] ?? $default;
        }

        return $this->resource->{$key} ?? $default;
    }

    /**
     * 리소스가 배열인지 확인합니다.
     *
     * @return bool 배열 여부
     */
    protected function isArray(): bool
    {
        return is_array($this->resource);
    }

    /**
     * 중첩된 배열을 재귀적으로 객체로 변환합니다.
     *
     * @param  mixed  $data  변환할 데이터
     * @return mixed 객체로 변환된 데이터
     */
    protected function arrayToObjectRecursive($data)
    {
        if (is_array($data)) {
            return (object) array_map([$this, 'arrayToObjectRecursive'], $data);
        }

        return $data;
    }

    /**
     * 매직 메서드 오버라이드: 배열일 때도 객체처럼 접근 가능하게 합니다.
     *
     * @param  string  $key  속성 키
     * @return mixed 속성 값
     */
    public function __get($key)
    {
        if (is_array($this->resource)) {
            return $this->resource[$key] ?? null;
        }

        return parent::__get($key);
    }

    /**
     * 배열/객체 상관없이 isset 체크를 수행합니다.
     *
     * @param  string  $key  확인할 키
     * @return bool 존재 여부
     */
    public function __isset($key)
    {
        if (is_array($this->resource)) {
            return isset($this->resource[$key]);
        }

        return parent::__isset($key);
    }

    /**
     * 공통 타임스탬프 형식을 반환합니다.
     * 사용자 timezone으로 변환하여 Y-m-d H:i:s 형식으로 반환합니다.
     *
     * @return array<string, string|null> 포맷된 타임스탬프 배열
     */
    protected function formatTimestamps(): array
    {
        return [
            'created_at' => $this->when($this->created_at, fn () => $this->formatDateTimeStringForUser($this->created_at)),
            'updated_at' => $this->when($this->updated_at, fn () => $this->formatDateTimeStringForUser($this->updated_at)),
        ];
    }

    /**
     * datetime 값을 사용자 timezone으로 변환하여 ISO8601 문자열로 반환합니다.
     *
     * @param  Carbon|null  $datetime  변환할 datetime
     * @return string|null ISO8601 형식의 datetime 문자열
     */
    protected function formatDateTimeForUser(?Carbon $datetime): ?string
    {
        return TimezoneHelper::toUserTimezone($datetime);
    }

    /**
     * datetime 값을 사용자 timezone으로 변환하여 날짜만 반환합니다 (Y-m-d 형식).
     *
     * @param  Carbon|null  $datetime  변환할 datetime
     * @return string|null Y-m-d 형식의 날짜 문자열
     */
    protected function formatDateForUser(?Carbon $datetime): ?string
    {
        return TimezoneHelper::toUserDateString($datetime);
    }

    /**
     * datetime 값을 사용자 timezone으로 변환하여 Y-m-d H:i:s 형식으로 반환합니다.
     *
     * @param  Carbon|null  $datetime  변환할 datetime
     * @return string|null Y-m-d H:i:s 형식의 datetime 문자열
     */
    protected function formatDateTimeStringForUser(?Carbon $datetime): ?string
    {
        return TimezoneHelper::toUserDateTimeString($datetime);
    }

    /**
     * datetime 값을 사이트 기본 timezone으로 변환하여 Y-m-d 형식으로 반환합니다.
     *
     * HTML <input type="date"> 호환용 (datepicker에 바인딩하는 날짜 필드에 사용).
     *
     * @param  Carbon|null  $datetime  변환할 datetime
     * @return string|null Y-m-d 형식의 날짜 문자열
     */
    protected function formatDateStringForSite(?Carbon $datetime): ?string
    {
        return TimezoneHelper::toSiteDateString($datetime);
    }

    /**
     * datetime 값을 사이트 기본 timezone으로 변환하여 datetime-local 호환 형식으로 반환합니다.
     *
     * HTML <input type="datetime-local"> 호환용 (Y-m-d\TH:i 형식).
     *
     * @param  Carbon|null  $datetime  변환할 datetime
     * @return string|null Y-m-d\TH:i 형식의 datetime 문자열
     */
    protected function formatDateTimeLocalStringForSite(?Carbon $datetime): ?string
    {
        return TimezoneHelper::toSiteDateTimeLocalString($datetime);
    }

    /**
     * 현재 요청의 타임존을 가져옵니다.
     * SetTimezone 미들웨어에서 설정한 값을 사용하며, 미설정 시 기본값을 반환합니다.
     */
    protected function getUserTimezone(): string
    {
        return TimezoneHelper::getUserTimezone();
    }

    /**
     * 다국어 메시지와 함께 응답을 생성합니다.
     *
     * @param  string  $messageKey  메시지 키
     * @param  array  $messageParams  메시지 파라미터
     * @param  string  $domain  메시지 도메인
     * @return $this
     */
    public function withMessage(string $messageKey, array $messageParams = [], string $domain = 'core')
    {
        return $this->additional([
            'message_key' => $messageKey,
            'message_params' => $messageParams,
            'message_domain' => $domain,
        ]);
    }

    /**
     * 다국어 필드 값을 현재 로케일에 맞게 반환합니다.
     *
     * @param  string  $field  필드명
     * @return string|array|null 다국어 값
     */
    protected function getLocalizedField(string $field)
    {
        $value = $this->getValue($field);

        if ($value === null) {
            return null;
        }

        // 이미 문자열이면 그대로 반환
        if (is_string($value)) {
            return $value;
        }

        // 배열(다국어)이면 현재 로케일에 맞는 값 반환
        if (is_array($value)) {
            $locale = App::getLocale();

            return $value[$locale] ?? $value[config('app.fallback_locale', 'ko')] ?? reset($value) ?: null;
        }

        // Model의 getLocalizedName() 등의 메서드가 있으면 사용
        $methodName = 'getLocalized'.ucfirst($field);
        if (is_object($this->resource) && method_exists($this->resource, $methodName)) {
            return $this->resource->{$methodName}();
        }

        return $value;
    }

    /**
     * 소유자 필드명을 반환합니다.
     * null 반환 시 is_owner 필드가 응답에서 제외됩니다.
     * 자식 클래스에서 오버라이드합니다.
     *
     * @return string|null 소유자 필드명
     */
    protected function ownerField(): ?string
    {
        return null;
    }

    /**
     * 리소스별 능력(can_*) 매핑을 반환합니다.
     * 자식 클래스에서 오버라이드합니다.
     *
     * @return array<string, string> ['can_update' => 'module.entity.update', ...]
     */
    protected function abilityMap(): array
    {
        return [];
    }

    /**
     * 소유자 여부를 계산합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return bool|null 소유자 여부 (ownerField 미정의 시 null)
     */
    protected function resolveIsOwner(Request $request): ?bool
    {
        $field = $this->ownerField();
        if ($field === null) {
            return null;
        }

        $ownerId = $this->resource->{$field};
        if ($ownerId === null) {
            return false;
        }

        return $request->user()?->id === $ownerId;
    }

    /**
     * 능력 맵을 불리언으로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, bool> 능력 불리언 맵
     */
    protected function resolveAbilities(Request $request): array
    {
        return $this->resolveAbilitiesFromMap($this->abilityMap(), $request->user());
    }

    /**
     * is_owner + abilities 표준 필드를 반환합니다.
     * 자식 클래스의 toArray()에서 spread 연산자로 합침.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 메타 데이터
     */
    protected function resourceMeta(Request $request): array
    {
        $meta = [];

        $isOwner = $this->resolveIsOwner($request);
        if ($isOwner !== null) {
            $meta['is_owner'] = $isOwner;
        }

        $abilities = $this->resolveAbilities($request);
        if (! empty($abilities)) {
            $meta['abilities'] = $abilities;
        }

        return $meta;
    }
}
