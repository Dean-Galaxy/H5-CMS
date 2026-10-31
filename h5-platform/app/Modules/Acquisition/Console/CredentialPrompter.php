<?php

namespace App\Modules\Acquisition\Console;

use App\Modules\Acquisition\Sending\CredentialFileException;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class CredentialPrompter
{
    public function ask(InputInterface $input, OutputInterface $output, bool $test): array
    {
        if (! $input->isInteractive() || ! stream_isatty(STDIN) || ! stream_isatty(STDOUT)) {
            throw new CredentialFileException('TTY_REQUIRED');
        }
        $helper = new QuestionHelper;
        $read = static function (string $label) use ($helper, $input, $output) {
            $q = new Question($label);
            $q->setHidden(true)->setHiddenFallback(false);

            return $helper->ask($input, $output, $q);
        };

        return ['token' => $read('专用token（不回显）：'), 'test_event_code' => $test ? $read('test_event_code（不回显）：') : null];
    }
}
