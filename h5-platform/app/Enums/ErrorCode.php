<?php

namespace App\Enums;

enum ErrorCode: string
{
    case ValidationFailed = 'VALIDATION_FAILED';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case AuthenticationRequired = 'AUTHENTICATION_REQUIRED';
    case EmailUnverified = 'EMAIL_UNVERIFIED';
    case TokenInvalid = 'TOKEN_INVALID';
    case TokenExpired = 'TOKEN_EXPIRED';
    case TokenReplayed = 'TOKEN_REPLAYED';
    case CsrfMismatch = 'CSRF_MISMATCH';
    case RateLimited = 'RATE_LIMITED';
    case AccountDisabled = 'ACCOUNT_DISABLED';
    case SessionRevoked = 'SESSION_REVOKED';
    case MfaRequired = 'MFA_REQUIRED';
    case NotAuthorized = 'NOT_AUTHORIZED';
    case NotFound = 'NOT_FOUND';
    case MembershipRequired = 'MEMBERSHIP_REQUIRED';
    case Unpublished = 'UNPUBLISHED';
    case RegionBlocked = 'REGION_BLOCKED';
    case MediaNotReady = 'MEDIA_NOT_READY';
    case BillingPending = 'BILLING_PENDING';
    case SubscriptionExists = 'SUBSCRIPTION_EXISTS';
    case StaleVersion = 'STALE_VERSION';
    case DependencyNotReady = 'DEPENDENCY_NOT_READY';
    case InternalError = 'INTERNAL_ERROR';
}
