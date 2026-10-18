<?php

namespace Modules\Sirsoft\Board\Models;

use App\Casts\AsUnicodeJson;
use App\Models\Attachment;
use App\Models\Concerns\HasUserOverrides;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 게시판 반응 유형 모델.
 *
 * 반응(추천/비추천 등) 유형을 DB로 관리한다. name은 다국어 JSON,
 * 조회 시 getLocalizedName()이 로케일 폴백을 처리한다 (이슈 #525 확정 15).
 *
 * 아이콘 표시 우선순위는 icon_attachment(업로드 이미지) > icon(시드 FA 클래스) >
 * 레이아웃 기본 아이콘이다. 관리자는 이미지만 지정할 수 있고 FA 클래스는 시드 전용이다.
 *
 * @property int $id
 * @property string $code
 * @property array $name
 * @property string|null $icon
 * @property int|null $icon_attachment_id
 * @property int $display_order
 * @property bool $is_active
 * @property array|null $user_overrides
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ReactionType extends Model
{
    use HasUserOverrides;

    protected $table = 'board_reaction_types';

    protected $fillable = [
        'code',
        'name',
        'icon',
        'icon_attachment_id',
        'display_order',
        'is_active',
        'user_overrides',
    ];

    /**
     * 사용자 수정 보존 대상 필드.
     *
     * is_active·display_order 가 빠져 있으면 관리자가 끄거나 순서를 바꾼 유형을
     * 시더 재실행(module:update)이 기본값으로 되돌린다.
     * icon_attachment_id 는 시더가 만지지 않는 컬럼이라 추적 대상이 아니다.
     *
     * @var array<int, string>
     */
    protected array $trackableFields = ['name', 'icon', 'is_active', 'display_order'];

    /**
     * 다국어 JSON 컬럼 — sub-key dot-path 단위 user_overrides 보존.
     *
     * @var array<int, string>
     */
    protected array $translatableTrackableFields = ['name'];

    /**
     * 속성 캐스팅
     *
     * @return array<string, mixed> 캐스팅 정의
     */
    protected function casts(): array
    {
        return [
            'name' => AsUnicodeJson::class,
            'icon_attachment_id' => 'integer',
            'display_order' => 'integer',
            'is_active' => 'boolean',
            'user_overrides' => 'array',
        ];
    }

    /**
     * 아이콘 이미지 첨부 관계.
     *
     * @return BelongsTo<Attachment, ReactionType>
     */
    public function iconAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'icon_attachment_id');
    }

    /**
     * 이 유형으로 남겨진 반응 이력.
     *
     * @return HasMany<Reaction>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class, 'reaction_type_id');
    }

    /**
     * 업로드된 아이콘 이미지의 공개 URL.
     *
     * 첨부가 없으면 null 이며, 이 경우 화면은 icon(FA 클래스) → 레이아웃 기본 아이콘 순으로
     * 폴백한다. 관계가 로드돼 있지 않으면 lazy-load 를 피하려 null 을 돌려주지 않고
     * loadMissing 으로 채운다 — 목록 경로는 저장소가 with() 로 미리 싣는다.
     *
     * @return string|null 아이콘 이미지 URL (없으면 null)
     */
    public function getIconUrl(): ?string
    {
        if ($this->icon_attachment_id === null) {
            return null;
        }

        $this->loadMissing('iconAttachment');

        $hash = $this->iconAttachment?->hash;

        return $hash === null ? null : Attachment::urlForHash($hash);
    }

    /**
     * 지정된 로케일의 반응 유형명 반환 (미입력 언어는 폴백).
     *
     * @param  string|null  $locale  로케일 (null이면 현재 로케일)
     * @return string 해당 로케일의 유형명
     */
    public function getLocalizedName(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        if (! is_array($this->name)) {
            return (string) $this->name;
        }

        return $this->name[$locale]
            ?? $this->name[config('app.fallback_locale')]
            ?? (! empty($this->name) ? array_values($this->name)[0] : '')
            ?? '';
    }

    /**
     * 활성 유형만 조회하는 스코프.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * 저장된 Font Awesome 클래스(`fas fa-thumbs-up`)에서 Icon 컴포넌트 name prop 용
     * 아이콘 토큰(`fa-thumbs-up`)만 추출합니다. Icon 컴포넌트가 스타일 접두사를 자체 부착하므로
     * 원본 클래스를 그대로 name 에 넘기면 접두사가 중복됩니다.
     *
     * @return string|null Icon name prop 값 (없으면 null)
     */
    public function getIconName(): ?string
    {
        if ($this->icon === null || $this->icon === '') {
            return null;
        }

        foreach (preg_split('/\s+/', trim($this->icon)) as $token) {
            if (str_starts_with($token, 'fa-')) {
                return $token;
            }
        }

        return $this->icon;
    }
}
