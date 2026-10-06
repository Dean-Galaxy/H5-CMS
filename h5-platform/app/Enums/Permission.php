<?php

namespace App\Enums;

enum Permission: string
{
    case StaffManage = 'staff.manage';
    case SiteConfigure = 'site.configure';
    case PriceManage = 'price.manage';
    case FreeDefaultManage = 'free_default.manage';
    case ContentManage = 'content.manage';
    case PublishedCatalogRead = 'catalog.published.read';
    case SupportLookup = 'support.lookup';
    case SupportNote = 'support.note';
    case SubscriptionCancel = 'subscription.cancel';
    case SubscriptionResume = 'subscription.resume';
    case PaymentException = 'payment.exception';
    case AudienceExport = 'audience.export';
    case AcquisitionManage = 'acquisition.manage';
    case AcquisitionSummary = 'acquisition.summary';
    case AuditRead = 'audit.read';
}
