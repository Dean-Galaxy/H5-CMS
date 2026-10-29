<?php

namespace App\Modules\Acquisition\Analytics;

use App\Support\ApiProblem;

final class ClickContext
{
    public function validate(mixed $value): array
    {
        if (! is_array($value) || (array_is_list($value) && $value !== []) || array_diff(array_keys($value), ['fbclid', 'ttclid', 'fbc', 'fbp', 'ttp'])) {
            throw new ApiProblem('VALIDATION_FAILED', 422, '广告匹配信息格式无效');
        }
        foreach ($value as $key => $id) {
            $pattern = match ($key) {
                'fbc' => '/^fb\.[0-9]{1,2}\.[0-9]{13}\.[A-Za-z0-9_-]+$/D',
                'fbp' => '/^fb\.[0-9]{1,2}\.[0-9]{13}\.[0-9]{1,30}$/D',
                'ttp' => '/^[A-Za-z0-9_.-]+$/D',
                default => '/^[A-Za-z0-9_-]+$/D',
            };
            if (! is_string($id) || strlen($id) > 512 || ! preg_match($pattern, $id)) {
                throw new ApiProblem('VALIDATION_FAILED', 422, '广告匹配信息格式无效');
            }
        }

        return $value;
    }

    public function forPlatform(string $platform, array $value): array
    {
        $value = $this->validate($value);
        if ($platform === 'meta') {
            $result = array_intersect_key($value, array_flip(['fbc', 'fbp']));
            if (isset($value['fbclid'])) {
                // Meta's official integration constructs fbc only from a genuine URL click id.
                $result['fbc'] = 'fb.1.'.now()->getTimestampMs().'.'.$value['fbclid'];
            }

            return $result;
        }

        return array_intersect_key($value, array_flip(['ttclid', 'ttp']));
    }

    public function forward(string $platform, array $query): array
    {
        $key = $platform === 'meta' ? 'fbclid' : 'ttclid';
        if (! isset($query[$key])) {
            return [];
        }
        try {
            return $this->validate([$key => $query[$key]]);
        } catch (ApiProblem) {
            return [];
        }
    }
}
