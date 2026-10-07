#!/usr/bin/env python3
"""Read-only local HTTP checks plus ephemeral playback grants; never publishes content."""
import argparse, http.cookiejar, json, re, urllib.request, urllib.error
from pathlib import Path
from datetime import datetime, timezone
from jsonschema import Draft202012Validator, FormatChecker

p=argparse.ArgumentParser();p.add_argument('--base',required=True);p.add_argument('--drama',required=True);p.add_argument('--output',required=True);p.add_argument('--category');a=p.parse_args()
assert re.fullmatch(r'http://127\.0\.0\.1:\d+',a.base), 'Loopback verification only'
root=Path(__file__).resolve().parents[5];spec=json.loads((root/'docs/contracts/openapi.yaml').read_text())
client=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
stranger=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
checks=[]
def request(path,data=None,headers=None,other=False):
    url=path if path.startswith(a.base+'/') else a.base+path
    body=json.dumps(data).encode() if data is not None else None
    req=urllib.request.Request(url,data=body,headers={'Accept':'application/json',**({'Content-Type':'application/json'} if body else {}),**(headers or {})})
    try:
        with (stranger if other else client).open(req,timeout=30) as r:return r.status,r.headers,r.read()
    except urllib.error.HTTPError as r:return r.code,r.headers,r.read()
def expect(name,response,status,schema=None):
    assert response[0]==status,(name,response[0],response[2][:300])
    if schema:
        value=json.loads(response[2]); Draft202012Validator({'$ref':'#/components/schemas/'+schema,'components':spec['components']},format_checker=FormatChecker()).validate(value)
    checks.append({'check':name,'status':status,'schema':schema,'passed':True})
    return json.loads(response[2]) if schema else response[2]
home=expect('homepage frozen contract',request('/api/v1/home?locale=zh_CN'),200,'HomePage')
expect('categories frozen contract',request('/api/v1/categories'),200,'CategoryList')
expect('catalog frozen contract',request('/api/v1/catalog'),200,'CatalogPage')
d=expect('drama frozen contract',request('/api/v1/dramas/'+a.drama),200,'Drama')
assert d['episodes'] and d['published_episode_count']==len(d['episodes'])
if a.category:
    page=expect('category filter frozen contract',request('/api/v1/catalog?category_id='+a.category),200,'CatalogPage');assert a.drama in [x['id'] for x in page['items']]
for banner in home['banners']:
    if banner['target_drama_id']==a.drama:expect('published internal Banner image',request(banner['image_url']),200)
expect('cover is served after publication',request(d['cover_url']),200)
expect('invalid cursor',request('/api/v1/catalog?cursor=forged'),422,'Error')
expect('staff API rejects anonymous viewer',request('/api/v1/admin/content'),401,'Error')
ep=d['episodes'][0]['id']
expect('real HTTP CSRF rejects mutation without token',request('/api/v1/episodes/'+ep+'/playback-grants',{'play_session_id':None}),419,'Error')
csrf=json.loads(request('/api/v1/auth/csrf')[2])['csrf_token']; headers={'X-CSRF-TOKEN':csrf}
for item in d['episodes']:
    if item['number']==5:
        fifth=expect('fifth episode free boundary',request('/api/v1/episodes/'+item['id']+'/playback-grants',{'play_session_id':None},headers),200,'PlaybackGrant');assert fifth['allowed'] and fifth['reason']=='allowed_free'
g=expect('free episode grant',request('/api/v1/episodes/'+ep+'/playback-grants',{'play_session_id':None},headers),200,'PlaybackGrant');assert g['allowed']
master=expect('protected HLS master',request(g['short_lived_url']),200).decode()
assert '/assets/' not in master and 'source.mp4' not in master
video_url=next(x for x in master.splitlines() if x.startswith('http'))
playlist=expect('protected HLS playlist',request(video_url),200).decode()
segment=next(x for x in playlist.splitlines() if x.startswith('http'))
r=request(segment,headers={'Range':'bytes=0-187'});expect('authorized segment Range',r,206);assert len(r[2])==188
expect('copied grant in another browser',request(segment,other=True),404,'Error')
expect('unsigned segment',request(segment.split('?')[0]),403,'Error')
expect('tampered signature',request(segment+'&tampered=1'),403,'Error')
sub=re.search(r'URI="([^"]+)"',master)
if sub:
    captions=expect('protected captions playlist',request(sub[1]),200).decode();vtturl=next(x for x in captions.splitlines() if x.startswith('http'))
    vtt=expect('protected VTT timestamp map',request(vtturl),200).decode();assert 'X-TIMESTAMP-MAP=LOCAL:00:00:00.000,MPEGTS:' in vtt
    expect('unsigned VTT',request(vtturl.split('?')[0]),403,'Error')
for e in d['episodes']:
    if e['number']>d['free_episode_count']:
        denied=expect('paid episode refuses anonymous access',request('/api/v1/episodes/'+e['id']+'/playback-grants',{'play_session_id':None},headers),200,'PlaybackGrant')
        assert denied['allowed'] is False and denied['short_lived_url'] is None and denied['reason']=='MEMBERSHIP_REQUIRED';break
result={'checked_at':datetime.now(timezone.utc).isoformat(),'contract':spec['info']['version'],'published_episode_count':len(d['episodes']),'checks':checks,'paid_billing_integration_verified':False}
Path(a.output).write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n');print(json.dumps({'passed':len(checks),'published_episodes':len(d['episodes'])}))
