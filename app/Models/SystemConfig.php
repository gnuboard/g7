<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class SystemConfig extends Model
{
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'system_configs';

    /**
     * 기본키
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * 타임스탬프 사용 여부
     *
     * @var bool
     */
    public $timestamps = true;

    protected $fillable = [
        'key',
        'value',
        'type',
        'module',
    ];

    /**
     * 주어진 키의 시스템 설정 값을 가져옵니다.
     *
     * @param string $key 설정 키
     * @param mixed $default 기본값
     * @return mixed 설정 값 또는 기본값
     */
    public static function get(string $key, $default = null)
    {
        $config = static::where('key', $key)->first();
        
        if (!$config) {
            return $default;
        }

        return match ($config->type) {
            'integer' => (int) $config->value,
            'boolean' => (bool) $config->value,
            'json' => json_decode($config->value, true),
            default => $config->value,
        };
    }

    /**
     * 주어진 키에 시스템 설정 값을 저장합니다.
     *
     * @param string $key 설정 키
     * @param mixed $value 저장할 값
     * @param string $type 값의 타입 (기본값: 'string')
     * @param string|null $module 모듈명 (선택사항)
     * @return void
     */
    public static function set(string $key, $value, string $type = 'string', ?string $module = null): void
    {
        $processedValue = match ($type) {
            'json' => json_encode($value),
            default => (string) $value,
        };

        static::updateOrCreate(
            ['key' => $key],
            [
                'value' => $processedValue,
                'type' => $type,
                'module' => $module,
            ]
        );
    }

    /**
     * 첨부파일 다형성 관계
     *
     * @param string|null $collection 컬렉션명 (선택)
     * @return MorphMany
     */
    public function attachments(?string $collection = null): MorphMany
    {
        $relation = $this->morphMany(Attachment::class, 'attachmentable');

        if ($collection) {
            $relation->where('collection', $collection);
        }

        return $relation;
    }
}
