from pathlib import Path
import hashlib,json,os,socket,subprocess,sys
root=Path.cwd();assert str(root).startswith('/private/tmp/h5-06-binding-compat-')
sys.path.insert(0,str(root/'scripts/ops'))
import paypal_apply_credentials as legacy
import paypal_purchase_switch as switch
candidate=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip();assert candidate=='942e6a4fd0739afab0c8bd25be79477603bcdc17'
env=root/'h5-platform/.env';loader=root/'h5-platform/vendor/autoload.php';original=env.read_bytes();values=legacy.parse_env(original.decode(),loader)
assert values[legacy.PREFIX+'CLIENT_ID']=='SYNTHETIC06CLIENT' and values[legacy.PREFIX+'CLIENT_SECRET']=='SYNTHETIC06NOTAREALSECRET'
assert values[legacy.PREFIX+'ENABLED']=='false' and values[switch.PROVIDER]=='unconfigured'
with socket.socket() as s:assert s.connect_ex(('127.0.0.1',5996))!=0
php='/opt/homebrew/opt/php@8.4/bin/php';artisan=str(root/'h5-platform/artisan')
# Real CLI, API disabled. Disable general PHP HTTP transports as an additional synthetic-run guard.
prefix=[php,'-d','disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client',artisan]
a=subprocess.run(prefix+['billing:paypal-prepare','--check-readiness'],cwd=root/'h5-platform',capture_output=True,text=True,timeout=30)
report=json.loads(a.stdout);assert a.returncode==1 and report['purchase_readiness_valid'] is False and report['app_authenticated'] is False
b=subprocess.run(prefix+['billing:paypal-prepare','--check-readiness','--create-product'],cwd=root/'h5-platform',capture_output=True,text=True,timeout=30)
conflict=json.loads(b.stdout);assert b.returncode==1 and conflict['diagnostic_code']=='PAYPAL_READINESS_OPTIONS_CONFLICT' and conflict['network_requested'] is False
assert env.read_bytes()==original
# The exact 06 parser also rejects this actual API-off 03 CLI result.
try:switch.official_readiness(root,values)
except ValueError:adapter_rejected=True
else:raise AssertionError('Actual failed readiness accepted')
assert env.read_bytes()==original
# Synthetic enabled selection; no official request or database was used to arrange it.
proposed=legacy.render_updates(original.decode(),values,{switch.PROVIDER:'paypal',legacy.PREFIX+'ENABLED':'true'},loader)
legacy.atomic_private(env,proposed)
source=root/'.runtime/paypal-sandbox/credentials.json';archive=source.with_name('synthetic-credentials-before-offline-disable.json');source.rename(archive)
assert not source.exists() and not (root/'.runtime/paypal-sandbox/external-url.json').exists()
result=subprocess.run(['/usr/bin/python3',str(root/'scripts/ops/paypal_purchase_switch.py'),'--candidate',candidate,'--disable'],cwd=root,capture_output=True,text=True,timeout=30)
assert result.returncode==0
final=legacy.parse_env(env.read_text(),loader)
assert final==values and final[switch.PROVIDER]=='unconfigured' and final[legacy.PREFIX+'ENABLED']=='false'
with socket.socket() as s:assert s.connect_ex(('127.0.0.1',5996))!=0
r={'candidate':candidate,'billing_candidate':'e50d7f2cb6852f31b46ac6ea8f9c0f5dd1217a0e','switch_source':'942e6a4fd0739afab0c8bd25be79477603bcdc17','scope':'isolated synthetic environment only','real_readiness_cli_exit':a.returncode,'readiness_valid':report['purchase_readiness_valid'],'app_authenticated':report['app_authenticated'],'readiness_diagnostic':report['diagnostic_code'],'readiness_network_requested_flag':report['network_requested'],'network_note':'CLI marks intent before the API-off guard; no successful authentication or official check asserted','mixed_action_exit':b.returncode,'mixed_action_rejected_before_network':conflict['network_requested'] is False,'actual_readiness_parser_rejected':adapter_rejected,'database_listener_present':False,'staged_credentials_missing':True,'window_records_missing':True,'real_disable_cli_exit':result.returncode,'purchase_disabled':True,'api_disabled':True,'all_other_env_values_preserved':True,'real_user_environment_touched':False,'passed':True}
(root/'.runtime/purchase-switch-compat-result.json').write_text(json.dumps(r,indent=2)+'\n');print(json.dumps(r))
