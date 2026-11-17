"""Keep the media timer alive after a single known playback validation rejection."""

import re
import subprocess
import sys

COMMAND = [
    "/usr/bin/php8.4", "-d", "memory_limit=128M", "artisan",
    "content:process-media", "--limit=1", "--no-ansi", "--no-interaction",
]
ASSET_REJECTION = re.compile(
    rb"[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12} failed PLAYBACK_OPTIMIZATION_FAILED\s*"
)


def exit_status(result):
    if result.returncode == 1 and not result.stderr and ASSET_REJECTION.fullmatch(result.stdout):
        return 0
    if result.returncode < 0:
        return 128 - result.returncode
    return result.returncode


def main():
    result = subprocess.run(COMMAND, capture_output=True)
    sys.stdout.buffer.write(result.stdout)
    sys.stderr.buffer.write(result.stderr)
    return exit_status(result)


if __name__ == "__main__":
    raise SystemExit(main())
