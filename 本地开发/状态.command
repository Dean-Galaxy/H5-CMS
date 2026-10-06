#!/bin/zsh
cd "$(dirname "$0")/.." || exit 1
python3 scripts/dev.py status
result=$?
if [[ $result != 0 ]]; then
  echo "操作未完成，请保留上面的提示交给开发任务。"
fi
read -r "?按回车关闭此窗口。"
exit $result
