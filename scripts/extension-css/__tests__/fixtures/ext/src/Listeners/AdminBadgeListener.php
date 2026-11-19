<?php

namespace Fixture\Demo\Listeners;

class AdminBadgeListener
{
    public function badge(string $x): array
    {
        return [
            'className' => 'inline-flex px-2 input',
            'iconClassName' => "w-4 {$x}",
            'label' => 'flex',
        ];
    }
}
