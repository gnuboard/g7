<?php

namespace App\Support\SampleData;

use App\Support\SampleData\Datasets\Internet;
use App\Support\SampleData\Datasets\KoreanGeo;
use App\Support\SampleData\Datasets\KoreanPeople;
use App\Support\SampleData\Datasets\KoreanText;
use BadMethodCallException;
use DateTime;
use DateTimeZone;

/**
 * FakerShim — Faker\Generator 대체 구현
 *
 * fakerphp/faker 패키지가 설치되지 않은 환경(예: composer --no-dev)에서도
 * 샘플 시더와 팩토리가 동작하도록 Faker\Generator 와 동일한 공용 API 를 제공합니다.
 *
 * 등록 방법: AppServiceProvider::register() 에서 Faker 미설치 감지 시
 *   class_alias(self::class, \Faker\Generator::class)
 * 로 바인딩하면 Laravel Factory 와 fake() 전역 함수가 이 클래스를 사용합니다.
 *
 * 데이터 품질 전략:
 *  - 순수 랜덤 메서드(randomElement, numberBetween 등) — Faker 와 결과 구별 불가
 *  - 한국어 데이터(name, address, word 등) — Datasets/*.php 풀에서 조합 생성
 *  - 미구현 메서드는 BadMethodCallException — 필요 시 본 클래스에 추가 구현
 */
class FakerShim
{
    public string $locale;

    /**
     * unique() 추적 캐시 [methodKey => [hash => true]]
     *
     * @var array<string, array<string, true>>
     */
    protected array $seenUniques = [];

    public function __construct(string $locale = 'ko_KR')
    {
        $this->locale = $locale;
    }

    // ---------------------------------------------------------------
    //  순수 랜덤
    // ---------------------------------------------------------------

    /**
     * @template T
     *
     * @param  array<T>  $array
     * @return T|null
     */
    public function randomElement(array $array = ['a', 'b', 'c'])
    {
        if (empty($array)) {
            return null;
        }

        return $array[array_rand($array)];
    }

    /**
     * @template T
     *
     * @param  array<T>  $array
     * @return array<T>
     */
    public function randomElements(array $array = ['a', 'b', 'c'], int $count = 1, bool $allowDuplicates = false): array
    {
        if (empty($array)) {
            return [];
        }

        $result = [];
        if ($allowDuplicates) {
            for ($i = 0; $i < $count; $i++) {
                $result[] = $array[array_rand($array)];
            }

            return $result;
        }

        $count = min($count, count($array));
        $keys = (array) array_rand($array, $count);
        foreach ($keys as $key) {
            $result[] = $array[$key];
        }

        return $result;
    }

    public function randomDigit(): int
    {
        return random_int(0, 9);
    }

    public function randomDigitNotNull(): int
    {
        return random_int(1, 9);
    }

    public function randomNumber(?int $nbDigits = null, bool $strict = false): int
    {
        $nbDigits = $nbDigits ?? random_int(1, 9);
        $max = (int) (10 ** $nbDigits) - 1;
        $min = $strict && $nbDigits > 1 ? (int) (10 ** ($nbDigits - 1)) : 0;

        return random_int($min, $max);
    }

    public function numberBetween(int $min = 0, int $max = 2147483647): int
    {
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        return random_int($min, $max);
    }

    public function randomFloat(?int $decimals = null, int|float $min = 0, int|float|null $max = null): float
    {
        if ($max === null) {
            $max = $min + 100;
        }
        if ($decimals === null) {
            $decimals = random_int(0, 4);
        }
        $value = $min + (mt_rand() / mt_getrandmax()) * ($max - $min);

        return round($value, $decimals);
    }

    public function boolean(int $chanceOfGettingTrue = 50): bool
    {
        return random_int(1, 100) <= $chanceOfGettingTrue;
    }

    public function numerify(string $string = '###'): string
    {
        return preg_replace_callback('/#/', fn () => (string) random_int(0, 9), $string);
    }

    public function lexify(string $string = '????'): string
    {
        return preg_replace_callback('/\?/', fn () => chr(random_int(97, 122)), $string);
    }

    public function bothify(string $string = '## ??'): string
    {
        return $this->lexify($this->numerify($string));
    }

    public function asciify(string $string = '****'): string
    {
        return preg_replace_callback('/\*/', fn () => chr(random_int(33, 126)), $string);
    }

    // ---------------------------------------------------------------
    //  인터넷
    // ---------------------------------------------------------------

    public function ipv4(): string
    {
        return random_int(1, 254).'.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    public function ipv6(): string
    {
        $parts = [];
        for ($i = 0; $i < 8; $i++) {
            $parts[] = dechex(random_int(0, 65535));
        }

        return implode(':', $parts);
    }

    public function macAddress(): string
    {
        $parts = [];
        for ($i = 0; $i < 6; $i++) {
            $parts[] = str_pad(dechex(random_int(0, 255)), 2, '0', STR_PAD_LEFT);
        }

        return implode(':', $parts);
    }

    public function userAgent(): string
    {
        return $this->randomElement(Internet::USER_AGENTS);
    }

    public function url(): string
    {
        return 'https://'.$this->randomElement(Internet::DOMAINS).'/'.$this->slug(3, false);
    }

    public function domainName(): string
    {
        return $this->randomElement(Internet::DOMAINS);
    }

    public function tld(): string
    {
        return $this->randomElement(Internet::TLDS);
    }

    public function email(): string
    {
        $user = $this->randomElement(KoreanText::EMAIL_USER_SUFFIX).$this->randomNumber(3, true);

        return $user.'@'.$this->randomElement(Internet::EMAIL_DOMAINS);
    }

    public function safeEmail(): string
    {
        return $this->email();
    }

    public function userName(): string
    {
        return $this->randomElement(KoreanText::EMAIL_USER_SUFFIX).$this->randomNumber(3, true);
    }

    public function password(int $minLength = 6, int $maxLength = 20): string
    {
        $length = random_int($minLength, $maxLength);

        return $this->bothify(str_repeat('?', (int) floor($length / 2)).str_repeat('#', (int) ceil($length / 2)));
    }

    // ---------------------------------------------------------------
    //  식별자
    // ---------------------------------------------------------------

    public function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function semver(bool $preRelease = false, bool $build = false): string
    {
        $major = random_int(0, 9);
        $minor = random_int(0, 20);
        $patch = random_int(0, 50);
        $version = "{$major}.{$minor}.{$patch}";

        if ($preRelease) {
            $version .= '-'.$this->randomElement(['alpha', 'beta', 'rc']).'.'.random_int(1, 9);
        }
        if ($build) {
            $version .= '+'.$this->lexify('???').random_int(100, 999);
        }

        return $version;
    }

    public function slug(int $nbWords = 6, bool $variableNbWords = true): string
    {
        if ($variableNbWords) {
            $nbWords = max(1, $nbWords + random_int(-2, 2));
        }

        $parts = [];
        for ($i = 0; $i < $nbWords; $i++) {
            $parts[] = $this->lexify(str_repeat('?', random_int(4, 8)));
        }

        return implode('-', $parts);
    }

    public function md5(): string
    {
        return md5((string) random_int(0, PHP_INT_MAX));
    }

    public function sha1(): string
    {
        return sha1((string) random_int(0, PHP_INT_MAX));
    }

    public function sha256(): string
    {
        return hash('sha256', (string) random_int(0, PHP_INT_MAX));
    }

    // ---------------------------------------------------------------
    //  날짜/시간
    // ---------------------------------------------------------------

    public function dateTime(string|int $max = 'now', ?string $timezone = null): DateTime
    {
        $maxTs = is_int($max) ? $max : (int) strtotime((string) $max);
        $ts = random_int(0, max(0, $maxTs));
        $dt = new DateTime('@'.$ts);
        $dt->setTimezone(new DateTimeZone($timezone ?? date_default_timezone_get()));

        return $dt;
    }

    public function dateTimeBetween(string|int $startDate = '-30 years', string|int $endDate = 'now', ?string $timezone = null): DateTime
    {
        $start = is_int($startDate) ? $startDate : (int) strtotime((string) $startDate);
        $end = is_int($endDate) ? $endDate : (int) strtotime((string) $endDate);
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        $ts = random_int($start, $end);
        $dt = new DateTime('@'.$ts);
        $dt->setTimezone(new DateTimeZone($timezone ?? date_default_timezone_get()));

        return $dt;
    }

    public function dateTimeThisYear(string|int $max = 'now'): DateTime
    {
        return $this->dateTimeBetween(strtotime('first day of January this year'), $max);
    }

    public function dateTimeThisMonth(string|int $max = 'now'): DateTime
    {
        return $this->dateTimeBetween(strtotime('first day of this month'), $max);
    }

    public function date(string $format = 'Y-m-d', string|int $max = 'now'): string
    {
        return $this->dateTime($max)->format($format);
    }

    public function time(string $format = 'H:i:s', string|int $max = 'now'): string
    {
        return $this->dateTime($max)->format($format);
    }

    public function unixTime(string|int $max = 'now'): int
    {
        $maxTs = is_int($max) ? $max : (int) strtotime((string) $max);

        return random_int(0, max(0, $maxTs));
    }

    // ---------------------------------------------------------------
    //  한국어 인명/회사
    // ---------------------------------------------------------------

    public function name(?string $gender = null): string
    {
        return $this->lastName().$this->firstName();
    }

    public function firstName(?string $gender = null): string
    {
        return $this->randomElement(KoreanPeople::FIRST_NAMES);
    }

    public function lastName(): string
    {
        return $this->randomElement(KoreanPeople::LAST_NAMES);
    }

    public function title(?string $gender = null): string
    {
        return $this->randomElement(KoreanPeople::JOB_TITLES);
    }

    public function company(): string
    {
        return $this->randomElement(KoreanPeople::COMPANY_PREFIX).$this->randomElement(KoreanPeople::COMPANY_SUFFIX);
    }

    public function companySuffix(): string
    {
        return $this->randomElement(KoreanPeople::COMPANY_SUFFIX);
    }

    public function jobTitle(): string
    {
        return $this->randomElement(KoreanPeople::JOB_TITLES);
    }

    // ---------------------------------------------------------------
    //  주소
    // ---------------------------------------------------------------

    public function address(): string
    {
        $city = $this->randomElement(KoreanGeo::CITIES);
        $district = $this->randomElement(KoreanGeo::DISTRICTS);
        $road = $this->randomElement(KoreanGeo::ROADS);

        return "{$city} {$district} {$road} ".random_int(1, 999);
    }

    public function streetAddress(): string
    {
        return $this->randomElement(KoreanGeo::ROADS).' '.random_int(1, 999);
    }

    public function secondaryAddress(): string
    {
        return random_int(1, 50).'층 '.random_int(100, 9999).'호';
    }

    public function city(): string
    {
        return $this->randomElement(KoreanGeo::CITIES);
    }

    public function state(): string
    {
        return $this->randomElement(KoreanGeo::STATES);
    }

    public function stateAbbr(): string
    {
        return substr((string) $this->state(), 0, 2);
    }

    public function country(): string
    {
        return '대한민국';
    }

    public function countryCode(): string
    {
        return $this->randomElement(['KR', 'US', 'JP', 'CN', 'GB', 'DE', 'FR', 'IT', 'SG', 'TW']);
    }

    public function postcode(): string
    {
        return $this->numerify('#####');
    }

    public function postalCode(): string
    {
        return $this->postcode();
    }

    public function phoneNumber(): string
    {
        return '010-'.$this->numerify('####-####');
    }

    // ---------------------------------------------------------------
    //  텍스트
    // ---------------------------------------------------------------

    public function word(): string
    {
        return $this->randomElement(KoreanText::WORDS);
    }

    /**
     * @return array<string>|string
     */
    public function words(int $nb = 3, bool $asText = false)
    {
        $words = [];
        for ($i = 0; $i < $nb; $i++) {
            $words[] = $this->word();
        }

        return $asText ? implode(' ', $words) : $words;
    }

    public function sentence(int $nbWords = 6, bool $variableNbWords = true): string
    {
        if ($variableNbWords) {
            $nbWords = max(1, $nbWords + random_int(-2, 2));
        }

        $head = $this->randomElement(KoreanText::SENTENCE_HEADS);
        $words = $this->words($nbWords, false);

        return $head.' '.implode(' ', $words).'.';
    }

    /**
     * @return array<string>|string
     */
    public function sentences(int $nb = 3, bool $asText = false)
    {
        $sentences = [];
        for ($i = 0; $i < $nb; $i++) {
            $sentences[] = $this->sentence();
        }

        return $asText ? implode(' ', $sentences) : $sentences;
    }

    public function paragraph(int $nbSentences = 3, bool $variableNbSentences = true): string
    {
        if ($variableNbSentences) {
            $nbSentences = max(1, $nbSentences + random_int(-1, 1));
        }

        return $this->sentences($nbSentences, true);
    }

    /**
     * @return array<string>|string
     */
    public function paragraphs(int $nb = 3, bool $asText = false)
    {
        $paragraphs = [];
        for ($i = 0; $i < $nb; $i++) {
            $paragraphs[] = $this->paragraph();
        }

        return $asText ? implode("\n\n", $paragraphs) : $paragraphs;
    }

    public function text(int $maxNbChars = 200): string
    {
        $text = '';
        while (mb_strlen($text) < $maxNbChars) {
            $text .= $this->sentence().' ';
        }

        return rtrim(mb_substr($text, 0, $maxNbChars));
    }

    public function realText(int $maxNbChars = 200): string
    {
        return $this->text($maxNbChars);
    }

    public function realTextBetween(int $minNbChars = 160, int $maxNbChars = 200): string
    {
        $target = random_int($minNbChars, $maxNbChars);

        return $this->text($target);
    }

    // ---------------------------------------------------------------
    //  기타 편의
    // ---------------------------------------------------------------

    public function hexColor(): string
    {
        return '#'.str_pad(dechex(random_int(0, 0xFFFFFF)), 6, '0', STR_PAD_LEFT);
    }

    public function safeHexColor(): string
    {
        return $this->hexColor();
    }

    public function rgbColor(): string
    {
        return random_int(0, 255).','.random_int(0, 255).','.random_int(0, 255);
    }

    public function colorName(): string
    {
        return $this->randomElement(['red', 'blue', 'green', 'yellow', 'orange', 'purple', 'black', 'white', 'gray', 'pink']);
    }

    public function imageUrl(int $width = 640, int $height = 480, ?string $category = null): string
    {
        return "https://via.placeholder.com/{$width}x{$height}";
    }

    public function image(...$args): string
    {
        $width = $args[1] ?? 640;
        $height = $args[2] ?? 480;

        return $this->imageUrl($width, $height);
    }

    public function mimeType(): string
    {
        return $this->randomElement(['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'text/plain', 'application/json']);
    }

    public function fileExtension(): string
    {
        return $this->randomElement(['jpg', 'png', 'gif', 'pdf', 'txt', 'json', 'csv', 'xlsx', 'docx']);
    }

    public function currencyCode(): string
    {
        return $this->randomElement(['KRW', 'USD', 'JPY', 'EUR', 'CNY', 'GBP']);
    }

    public function regexify(string $regex = ''): string
    {
        return $regex !== '' ? $regex : $this->lexify('????');
    }

    // ---------------------------------------------------------------
    //  수식자 (modifier)
    // ---------------------------------------------------------------

    public function unique(bool $reset = false, int $maxRetries = 10000): UniqueShim
    {
        if ($reset) {
            $this->seenUniques = [];
        }

        return new UniqueShim($this, $this->seenUniques, $maxRetries);
    }

    public function optional(float|int $weight = 0.5, mixed $default = null): OptionalShim
    {
        return new OptionalShim($this, $weight, $default);
    }

    public function valid(?callable $validator = null, int $maxRetries = 10000): self
    {
        // Simple passthrough. Faker 의 valid() 는 콜백으로 필터링하지만
        // 샘플 데이터 범위에서는 사실상 패스스루면 충분.
        return $this;
    }

    // ---------------------------------------------------------------
    //  호환성 진입점 (속성 접근)
    // ---------------------------------------------------------------

    /** Faker\Generator 는 locale 을 public property 로 노출함 */
    public function __get(string $name): mixed
    {
        if ($name === 'locale') {
            return $this->locale;
        }

        return null;
    }

    /** 미구현 메서드 호출 시 명시적 예외 */
    public function __call(string $name, array $arguments): mixed
    {
        throw new BadMethodCallException(
            "FakerShim: 메서드 '{$name}'() 는 구현되지 않았습니다. ".
            'App\\Support\\SampleData\\FakerShim 에 추가 구현하거나 fakerphp/faker 를 설치하세요.'
        );
    }
}
