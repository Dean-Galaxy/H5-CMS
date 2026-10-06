<?php

namespace App\Data;

use BackedEnum;

abstract readonly class ContractData implements \JsonSerializable
{
    protected static function types(): array
    {
        return [];
    }

    public static function fromArray(array $data): static
    {
        foreach (static::types() as $key => $type) {
            if (! isset($data[$key])) {
                continue;
            }
            $convert = fn ($class, $value) => is_subclass_of($class, BackedEnum::class) ? $class::from($value) : $class::fromArray($value);
            $data[$key] = is_array($type) ? array_map(fn ($v) => $convert($type[0], $v), $data[$key]) : $convert($type, $data[$key]);
        }

        return new static(...$data);
    }

    public function toArray(): array
    {
        $convert = function ($value) use (&$convert) {
            return match (true) {
                $value instanceof BackedEnum => $value->value,
                $value instanceof self => $value->toArray(),
                is_array($value) => array_map($convert, $value),
                default => $value,
            };
        };

        return array_map($convert, get_object_vars($this));
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
