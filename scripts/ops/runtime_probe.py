#!/usr/bin/env python3
"""Read-only runtime/isolation evidence; boot failures captured by code, never raw settings."""
import hashlib, importlib.util, json, os, re, subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('h5_dev',ROOT/'scripts/dev.py');dev=importlib.util.module_from_spec(spec);spec.loader.exec_module(dev)
c=dev.config()
base=os.environ.copy()
base.update(APP_DEBUG='false',APP_URL='https://staging.example.invalid',SESSION_SECURE_COOKIE='true',MAIL_MAILER='smtp',MAIL_HOST='smtp.example.invalid',
 H5_LOCAL_US_TEST_REGION='false',H5_SIMULATION='false',H5_LOCAL_MODULES='',H5_BILLING_DRIVER='unconfigured',H5_BILLING_STRIPE_TEST_SECRET='',H5_BILLING_STRIPE_TEST_WEBHOOK_SECRET='')
php='''try { require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo json_encode(["boot"=>true,"environment"=>app()->environment(),"contract"=>config("h5.contract"),"region_class"=>get_class(app(App\\Contracts\\RegionResolver::class)),"acquisition"=>config("h5.modules.acquisition.ready"),"billing_driver"=>config("billing.driver"),"ad_transport"=>config("acquisition.transport","disabled"),"attribution"=>app(App\\Contracts\\AttributionService::class)->enabled()]); } catch (Throwable $e) { echo json_encode(["boot"=>false,"exception_type"=>get_class($e)]); exit(42); }'''
cases=[('production debug blocked',{'APP_ENV':'production','APP_DEBUG':'true'},False),('production http blocked',{'APP_ENV':'production','APP_URL':'http://staging.example.invalid'},False),('staging local region blocked',{'APP_ENV':'staging','H5_LOCAL_US_TEST_REGION':'true'},False),('staging preview mail blocked',{'APP_ENV':'staging','MAIL_MAILER':'preview'},False),('staging stripe_test blocked',{'APP_ENV':'staging','H5_BILLING_DRIVER':'stripe_test'},False),('production local module blocked',{'APP_ENV':'production','H5_LOCAL_MODULES':'acquisition'},False),('local default modules',{'APP_ENV':'local'},True),('local explicit acquisition',{'APP_ENV':'local','H5_LOCAL_MODULES':'acquisition'},True)]
results=[]
for name,changes,expected in cases:
 r=subprocess.run([dev.PHP,'-r',php],env={**base,**changes},cwd=dev.APP,text=True,capture_output=True)
 item=json.loads(r.stdout);assert item['boot']==expected,(name,item)
 results.append({'name':name,'passed':True,'observed':item})
# Existing staging is not a permitted deployment: record the current gap without changing it.
r=subprocess.run([dev.PHP,'-r',php],env={**base,'APP_ENV':'staging','APP_DEBUG':'true'},cwd=dev.APP,text=True,capture_output=True)
gap=json.loads(r.stdout)
query="SELECT current_database(),inet_server_port(),current_user,rolsuper,rolcreatedb FROM pg_roles WHERE rolname=current_user"
r=subprocess.run([dev.PG/'psql','-X','-At','-c',query],env=dev.pg_env(),check=True,text=True,capture_output=True)
config_text=(dev.APP/'.env').read_text()
fields={}
for line in config_text.splitlines():
 if '=' in line:
  k,v=line.split('=',1);fields[k]=v.strip('"')
expected=hashlib.sha256(str(ROOT).encode()).hexdigest()[:10]
assert c['workspace']=='h5_'+expected and c['db']=='h5_'+expected and fields['DB_DATABASE']==c['db'] and fields['DB_HOST']=='127.0.0.1'
assert fields['CACHE_PREFIX']==fields['DB_QUEUE']==fields['H5_WORKSPACE']==c['workspace']
assert fields['H5_MEDIA_ROOT']==str(dev.RUN/'media')
assert (dev.APP/'.env').stat().st_mode & 0o077 == 0
report={'checkout_commit':subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip(),'checks':results,'workspace_binding':True,'database_identity':r.stdout.strip(),
 'private_env_mode':'0600','runtime_realpath':str(dev.RUN.resolve()),'staging_debug_gap':{'observed':gap,'status':'deployment_blocker already in 03-002/06-001; not an accepted staging runtime'},
 'scope':'real independent PHP boot and local database role inspection; no external API calls'}
(dev.RUN/'06/runtime-probe.json').write_text(json.dumps(report,indent=2));print(json.dumps(report,indent=2))
