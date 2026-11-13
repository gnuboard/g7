<?php

namespace App\Fields\Types;

use App\Models\FieldDefinition;

/**
 * URL.
 *
 * @since 7.2.0
 */
class UrlType extends AbstractFieldType
{
    protected array $features = [self::FEATURE_SIGNUP, self::FEATURE_SEARCH];

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'url';
    }

    /**
     * {@inheritDoc}
     */
    public function category(): string
    {
        return 'contact';
    }

    /**
     * {@inheritDoc}
     *
     * 스킴은 http·https 만 받는다 — 보기 화면에서 링크로 그려질 수 있어 `javascript:` 같은 값을 막는다.
     * 코어 회원 홈페이지 칸은 종전 규칙(`url`)을 그대로 쓰도록 `constraints.schemes = '*'` 로 선언한다.
     */
    public function rules(FieldDefinition $definition, string $attribute): array
    {
        $url = $definition->constraint('schemes') === '*' ? 'url' : 'url:http,https';

        return [$attribute => ['string', $url, 'max:255']];
    }
}
