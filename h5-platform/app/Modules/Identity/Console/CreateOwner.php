<?php

namespace App\Modules\Identity\Console;

use App\Enums\Role;
use App\Modules\Identity\Models\Staff;
use App\Modules\Identity\Services\StaffPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

final class CreateOwner extends Command
{
    protected $signature = 'identity:owner';

    protected $description = '交互创建首位负责人，不保存明文密码';

    public function handle(): int
    {
        if (Staff::where('role', Role::Owner->value)->exists()) {
            $this->error('负责人已存在，请由负责人登录后台创建其他员工。');

            return self::FAILURE;
        }
        $values = ['name' => $this->ask('负责人姓名'), 'email' => mb_strtolower(trim($this->ask('负责人邮箱'))), 'password' => $this->secret(StaffPassword::OWNER_PROMPT)];
        $validator = Validator::make($values, ['name' => ['required', 'max:80'], 'email' => ['required', 'email', 'max:254', 'unique:identity_staff,email'], 'password' => StaffPassword::requiredRules()]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        Staff::create([...$values, 'role' => Role::Owner]);
        $this->info('负责人已创建。进入 /admin 配置验证器，保存恢复码，随后重新登录。');

        return self::SUCCESS;
    }
}
