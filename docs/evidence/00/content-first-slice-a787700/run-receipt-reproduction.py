import importlib.util, os, sys
from pathlib import Path
root=Path.cwd().resolve()
spec=importlib.util.spec_from_file_location('dev', root/'scripts/dev.py')
dev=importlib.util.module_from_spec(spec)
spec.loader.exec_module(dev)
c=dev.config()
env=os.environ.copy()
env.update(APP_ENV='testing', DB_CONNECTION='pgsql', DB_HOST='127.0.0.1', DB_PORT=str(c['db_port']), DB_DATABASE=c['db']+'_test', DB_USERNAME=c['user'], DB_PASSWORD=(dev.RUN/'pg-app-password').read_text(), H5_TEST_DATABASE=c['db']+'_test', H5_WORKSPACE=c['workspace']+'_test')
target=Path(sys.argv[1]).resolve() if len(sys.argv)>1 else Path(__file__).resolve().with_name('ContentReceiptReproductionTest.php')
if not target.is_relative_to(root):
    raise SystemExit('Refused target outside the isolated review')
extra=['--filter', '/^(?!.*ConcurrencyTest).*$/'] if target.is_dir() else []
dev.call([dev.PHP,dev.APP/'vendor/bin/phpunit',*extra,target],cwd=dev.APP,env=env)
