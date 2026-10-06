<?php

namespace App\Enums;

enum PlaybackReason: string
{
    case AllowedFree = 'allowed_free';
    case AllowedMember = 'allowed_member';
    case MembershipRequired = 'MEMBERSHIP_REQUIRED';
    case Unpublished = 'UNPUBLISHED';
    case RegionBlocked = 'REGION_BLOCKED';
    case MediaNotReady = 'MEDIA_NOT_READY';
}
