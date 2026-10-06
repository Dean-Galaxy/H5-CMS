<?php

namespace App\Contracts;

use App\Data\PlaybackGrant;
use App\Data\ViewerIdentity;

interface PlaybackAuthorizer
{
    /** Region comes from trusted server context; anonymous free grants are supported. */
    public function authorizePlayback(?ViewerIdentity $viewer, string $episodeId, string $region, ?string $playSessionId = null): PlaybackGrant;
}
