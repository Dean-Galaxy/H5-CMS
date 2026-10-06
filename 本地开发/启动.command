#!/bin/zsh
cd "$(dirname "$0")/.." || exit 1
if [[ ! -f h5-platform/vendor/autoload.php || ! -f .runtime/workspace.json ]]; then
  python3 scripts/bootstrap.py || exit 1
fi
python3 scripts/dev.py start && python3 scripts/dev.py open
result=$?
if [[ $result != 0 ]]; then
  echo "操作未完成，请保留上面的提示交给开发任务。"
fi
read -r "?按回车关闭此窗口。"
exit $result
