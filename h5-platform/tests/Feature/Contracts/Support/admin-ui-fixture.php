<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Blade;

$base = dirname(__DIR__, 4);
$output = $base.'/../.runtime/admin-ui-review';
if (! is_dir($output)) {
    mkdir($output, 0700, true);
}
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$body = Blade::render(<<<'BLADE'
<x-admin.workspace id="test-workspace" title="公共后台组件检查" description="仅验证交互机械行为，不写业务数据">
  <x-admin.tabs id="review-tabs" :items="[['id'=>'edit','label'=>'编辑内容'],['id'=>'history','label'=>'操作记录']]" active="edit" />
  <x-admin.panel group="review-tabs" name="edit" active="edit">
    <div class="h5-card h5-form-grid">
      <label class="h5-field">名称<input id="test-name" class="h5-input" value="已保存的名称"></label>
      <label class="h5-field">保存返回<select id="test-mode" class="h5-input"><option value="fail">保存失败</option><option value="ok">保存成功</option><option value="throw">网络异常</option></select></label>
      <button type="button" class="h5-button" id="test-open">打开编辑弹窗</button>
    </div>
  </x-admin.panel>
  <x-admin.panel group="review-tabs" name="history" active="edit"><div class="h5-card">已切换到操作记录</div></x-admin.panel>
  <p id="test-result" role="status">未修改；保存 0 次；放弃 0 次</p>
  <x-admin.dialog id="test-dialog" title="长表单编辑">
    <form id="test-form"><label class="h5-field">弹窗备注<input id="test-note" class="h5-input"></label><div style="height:900px">长内容滚动区</div></form>
    <x-slot:actions><button type="button" class="h5-button" data-admin-close>取消编辑</button><button type="button" class="h5-button h5-primary" id="test-dialog-save">保存弹窗</button></x-slot:actions>
  </x-admin.dialog>
</x-admin.workspace>
BLADE);
$script = <<<'JS'
let dirty = false, saved = 0, discarded = 0, original = '已保存的名称';
const nameInput = document.getElementById('test-name');
const note = document.getElementById('test-note');
const update = () => document.getElementById('test-result').textContent = `${dirty?'未保存':'已保存'}；保存 ${saved} 次；放弃 ${discarded} 次`;
const save = async () => {
  const mode = document.getElementById('test-mode').value;
  if (mode === 'throw') throw new Error('fixture-only');
  if (mode !== 'ok') return false;
  saved++; original = nameInput.value; dirty = false; update(); return true;
};
H5Admin.protect(document.getElementById('test-workspace'), {
 isDirty: () => dirty,
 save,
 discard: async () => { discarded++; nameInput.value = original; note.value = ''; dirty = false; update(); return true; }
});
nameInput.addEventListener('input', () => { dirty = true; update(); });
note.addEventListener('input', () => { dirty = true; update(); });
document.getElementById('test-open').onclick = () => H5Admin.openDialog('test-dialog');
document.getElementById('test-dialog-save').onclick = async () => { if (await save()) H5Admin.closeDialog('test-dialog'); };
JS;
file_put_contents($output.'/index.html', '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>00 公共组件检查</title><link rel="stylesheet" href="admin.css"><body style="margin:0;padding:20px;background:#f6f7f9">'.$body.'<script src="admin.js"></script><script>'.$script.'</script></body></html>');
copy(public_path('admin-ui/admin.css'), $output.'/admin.css');
copy(public_path('admin-ui/admin.js'), $output.'/admin.js');
