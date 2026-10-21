<?php

namespace App\Support\Admin;

use Filament\Facades\Filament;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;

/** Presentation only: module pages remain the permission and feature-gate owners. */
final class StaffNavigation
{
    public static function build(NavigationBuilder $builder): NavigationBuilder
    {
        $pages = Filament::getCurrentPanel()->getPages();
        $modules = [
            'App\\Modules\\Content\\Filament\\Pages\\ContentLibrary' => ['内容管理', 'heroicon-o-film'],
            'App\\Modules\\Billing\\Filament\\Pages\\BillingDesk' => ['订单与会员', 'heroicon-o-credit-card'],
            'App\\Modules\\Acquisition\\Filament\\Pages\\AcquisitionDesk' => ['投流管理', 'heroicon-o-megaphone'],
            'App\\Modules\\Identity\\Filament\\Pages\\StaffAccounts' => ['员工与权限', 'heroicon-o-users'],
            'App\\Modules\\Content\\Filament\\Pages\\ContentSettings' => ['全局设置', 'heroicon-o-adjustments-horizontal'],
        ];
        foreach ($modules as $page => [$label, $icon]) {
            if (! in_array($page, $pages, true) || ! $page::canAccess()) {
                continue;
            }
            $builder->item(NavigationItem::make($label)->icon($icon)->url($page::getUrl())
                ->isActiveWhen(fn (): bool => request()->routeIs($page::getRouteName())));
        }

        return $builder;
    }
}
