<?php

namespace Tests\Feature\Contracts\Support;

use Livewire\Component;

/** Test-server-only component: no staff, payment or content records. */
class AdminUiLivewireFixture extends Component
{
    public string $tab = 'orders';

    public bool $denyHistory = false;

    public int $requests = 0;

    public function selectTab(string $tab): void
    {
        usleep(350000);
        $this->requests++;
        $this->resetErrorBag();
        if (! in_array($tab, ['orders', 'prices', 'history'], true) || ($tab === 'history' && $this->denyHistory)) {
            $this->addError('tab', '测试服务器拒绝本次切换');

            return;
        }
        $this->tab = $tab;
    }

    public function render(): string
    {
        return <<<'BLADE'
        <x-admin.workspace id="livewire-fixture" title="真实 Livewire 公共组件夹具">
            <x-admin.tabs id="livewire-tabs" :items="[['id'=>'orders','label'=>'订单查询'],['id'=>'prices','label'=>'价格设置'],['id'=>'history','label'=>'操作记录']]" :active="$tab" method="selectTab" />
            <x-admin.panel group="livewire-tabs" name="orders" :active="$tab">订单内容</x-admin.panel>
            <x-admin.panel group="livewire-tabs" name="prices" :active="$tab">价格内容</x-admin.panel>
            <x-admin.panel group="livewire-tabs" name="history" :active="$tab">记录内容</x-admin.panel>
            <label><input type="checkbox" wire:model="denyHistory">拒绝操作记录</label>
            @error('tab')<p role="alert">{{ $message }}</p>@enderror
            <output id="server-requests">{{ $requests }}</output>
        </x-admin.workspace>
        BLADE;
    }
}
