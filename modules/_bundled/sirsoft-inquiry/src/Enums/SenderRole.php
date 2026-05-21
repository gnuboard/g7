<?php

namespace Modules\Sirsoft\Inquiry\Enums;

enum SenderRole: string
{
    case Client = 'client';
    case Operator = 'operator';
    case System = 'system';
}
