import base64,concurrent.futures,hashlib,http.client,json,ssl,subprocess,time,urllib.parse
from pathlib import Path
root=Path.cwd();d=root/'.runtime/paypal-sandbox';url=json.loads((d/'external-url.json').read_text())['url'];host=urllib.parse.urlsplit(url).hostname
php='/opt/homebrew/opt/php@8.4/bin/php'
def snapshot():return json.loads(subprocess.check_output([php,str(root/'scripts/ops/paypal_runtime_snapshot.php')],text=True))
before=snapshot()
nonce=json.loads((d/'external-probe-marker.json').read_text())['transmission_id']
body=(' {\n "id": "WH-SYNTHETIC-06", "event_type":"PAYMENT.SALE.COMPLETED",\n "resource": {"id":"SYNTHETIC", "description":"合成透传检查"}\n}\n').encode()
headers={'Content-Type':'application/json; charset=utf-8','paypal-auth-algo':'SHA256withRSA','paypal-cert-url':'https://api-m.sandbox.paypal.com/v1/notifications/certs/CERT-SYNTHETIC06','paypal-transmission-id':nonce,'paypal-transmission-sig':base64.b64encode(bytes(256)).decode(),'paypal-transmission-time':'2026-10-27T09:25:00Z'}
def request(label,method,path,extra,expected,payload=body):
 try:
  c=http.client.HTTPSConnection(host,443,timeout=25,context=ssl.create_default_context())
  c.request(method,path,payload if method=='POST' else None,extra)
  r=c.getresponse();data=r.read();result={'check':label,'method':method,'path':path,'status':r.status,'expected':expected,'body_bytes':len(data),'no_cookie_or_location':r.getheader('Set-Cookie') is None and r.getheader('Location') is None,'passed':r.status==expected and len(data)==0 and r.getheader('Set-Cookie') is None and r.getheader('Location') is None};c.close();return result
 except Exception as e:return {'check':label,'passed':False,'error_type':type(e).__name__}
checks=[]
for path in ('/','/admin','/.env','/metrics','/credentials','/api/admin/v1/staff','/media/test.m3u8','/webhooks/paypal'):
 checks.append(request('GET blocked '+path,'GET',path,{},404))
for method,path,h,status in [('POST','/admin',headers,404),('POST','/webhooks/paypal?extra=1',headers,404),('POST','/webhooks/paypal',{'Content-Type':'application/json'},400),('POST','/webhooks/paypal',{**headers,'Cookie':'synthetic=1'},403),('POST','/webhooks/paypal',{**headers,'Authorization':'synthetic'},403),('POST','/webhooks/paypal',{**headers,'Content-Type':'text/plain'},415)]:
 checks.append(request('reject '+method+' '+path+' '+str(status),method,path,h,status))
checks.append(request('synthetic raw body to real unconfigured application','POST','/webhooks/paypal',{**headers,'X-Forwarded-For':'192.0.2.6','CF-IPCountry':'US','Origin':'https://synthetic.invalid'},503))
proof=json.loads((d/'external-upstream.json').read_text()) if (d/'external-upstream.json').exists() else {}
expected_names=sorted(['host','content-type','content-length']+[k for k in headers if k.startswith('paypal-')])
transport={'body_bytes_identical':proof.get('body_bytes')==len(body),'raw_body_hash_identical':proof.get('body_sha256')==hashlib.sha256(body).hexdigest(),'five_paypal_headers_identical':all(proof.get('header_sha256',{}).get(k)==hashlib.sha256(v.encode()).hexdigest() for k,v in headers.items() if k.startswith('paypal-')),'only_allowlisted_outbound_headers':proof.get('header_names')==expected_names,'actual_application_response':proof.get('upstream')=={'host':'127.0.0.1','port':8406,'method':'POST','path':'/webhooks/paypal','status':503}}
after=snapshot()
result={'application_candidate':'a82b831ba5d03064d7d1564a93a5b201298571ce','checkout':subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip(),'observer_sha256':hashlib.sha256((root/'scripts/ops/tests/paypal_external_observer.py').read_bytes()).hexdigest(),'public_origin':url,'tls':'Python default system trust and hostname verification; no bypass','scope':'synthetic external transport to real application; no official PayPal signature or payment','checks':checks,'transport':transport,'upstream':proof,'before':before,'after':after,'billing_unchanged':before['counts']==after['counts'],'passed':all(x['passed'] for x in checks) and all(transport.values()) and before['counts']==after['counts']}
(d/'external-acceptance.json').write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
print(json.dumps({'checks':checks,'transport':transport,'billing_unchanged':result['billing_unchanged'],'passed':result['passed']},ensure_ascii=False))
