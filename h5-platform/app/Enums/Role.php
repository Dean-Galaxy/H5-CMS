<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case ContentOperator = 'content_operator';
    case Support = 'support';
    case Acquisition = 'acquisition';

    public function scope(): string
    {
        return match ($this) {
            self::Owner => 'all', self::ContentOperator => 'catalog',
            self::Support => 'case_lookup', self::Acquisition => 'owned_or_assigned',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => '负责人', self::ContentOperator => '内容运营',
            self::Support => '客服', self::Acquisition => '投流',
        };
    }
}
