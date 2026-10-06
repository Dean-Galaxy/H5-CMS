<?php

namespace App\Modules\Identity\Mail;

use Illuminate\Support\Str;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

final class PreviewTransport extends AbstractTransport
{
    protected function doSend(SentMessage $message): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \LogicException('Preview mail is forbidden outside local/testing');
        }
        $directory = config('h5.mail_preview_root');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $file = $directory.'/'.now()->format('Ymd-His').'-'.Str::uuid7();
        $original = $message->getOriginalMessage();
        file_put_contents($file.'.eml', $message->toString(), LOCK_EX);
        chmod($file.'.eml', 0600);
        if ($original instanceof Email) {
            file_put_contents($file.'.html', '<!doctype html><meta charset="utf-8"><p><strong>仅本地邮件预览：未发送到真实邮箱。</strong></p>'.($original->getHtmlBody() ?? nl2br(e($original->getTextBody()))), LOCK_EX);
            chmod($file.'.html', 0600);
        }
    }

    public function __toString(): string
    {
        return 'preview';
    }
}
