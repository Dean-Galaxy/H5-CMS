<?php

namespace App\Data;

final readonly class Drama extends ContractData
{
    /**
     * @param  list<Episode>  $episodes
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $synopsis,
        public ?string $cover_url,
        public int $published_episode_count,
        public ?int $total_episode_count,
        public int $free_episode_count,
        public string $publication_state,
        public array $episodes
    ) {}

    protected static function types(): array
    {
        return ['episodes' => [Episode::class]];
    }
}
