#!/usr/bin/env python3
"""Read-only source validation. Never selects 成片 or writes into the source tree."""
import argparse, concurrent.futures, hashlib, json, re, subprocess
from pathlib import Path

def check_episode(root, n, decode):
    video=root/'纯净版'/f'{n}.mp4'; sub=root/'字幕'/f'{n}.srt'
    info=json.loads(subprocess.check_output(['/opt/homebrew/bin/ffprobe','-v','error','-show_format','-show_streams','-of','json',str(video)]))
    duration=float(info['format']['duration'])
    text=sub.read_text(encoding='utf-8-sig').replace('\r\n','\n').strip()
    cues=[];errors=[];last_start=-1
    for block in re.split(r'\n\s*\n',text):
        lines=block.splitlines()
        match=re.fullmatch(r'(\d{2}):(\d{2}):(\d{2}),(\d{3}) --> (\d{2}):(\d{2}):(\d{2}),(\d{3})',lines[1] if len(lines)>1 else '')
        if not match or not lines[0].isdigit() or len(lines)<3:
            errors.append('invalid_cue');continue
        x=list(map(int,match.groups()));start=x[0]*3600+x[1]*60+x[2]+x[3]/1000;end=x[4]*3600+x[5]*60+x[6]+x[7]/1000
        if start<last_start:errors.append('cues_out_of_order')
        if end<=start or end>duration+0.5:errors.append('timing_out_of_bounds')
        if any(x[i]>=60 for i in (1,2,5,6)):errors.append('invalid_timestamp')
        if '<' in '\n'.join(lines[2:]) or '>' in '\n'.join(lines[2:]):errors.append('markup_present')
        cues.append([start,end]);last_start=start
    err=''
    if decode:
        proc=subprocess.run(['/opt/homebrew/bin/ffmpeg','-nostdin','-v','error','-xerror','-threads','2','-i',str(video),'-map','0:v:0','-map','0:a:0?','-f','null','-'],capture_output=True,text=True)
        err=proc.stderr[-800:] if proc.returncode else ''
        if proc.returncode:errors.append('decode_failed')
    letters=len(re.findall('[A-Za-z]',text));han=len(re.findall('[\u3400-\u9fff]',text))
    return dict(episode=n,bytes=video.stat().st_size,duration_seconds=duration,streams=[{k:s.get(k) for k in ('codec_type','codec_name','width','height','sample_rate','channels')} for s in info['streams']],subtitle_cues=len(cues),subtitle_last_end=max(c[1] for c in cues) if cues else None,latin_letters=letters,han_characters=han,subtitle_language_observation='English text; human semantic review still required' if letters>han else 'human review required',decoded=decode and proc.returncode==0,errors=sorted(set(errors)),decode_error=err)

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--source',default='/Volumes/KESU/A Star Between Us');ap.add_argument('--output',required=True);ap.add_argument('--decode',action='store_true');a=ap.parse_args()
    root=Path(a.source)
    if not (root/'纯净版').is_dir():raise SystemExit('指定纯净版源目录缺失；未回退到其他素材。')
    for folder,suffix in [('纯净版','.mp4'),('字幕','.srt')]:
        names={p.name for p in (root/folder).glob('*'+suffix)}
        if names!={str(n)+suffix for n in range(1,63)}:raise SystemExit('素材序号不完整：'+folder)
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        result=list(pool.map(lambda n:check_episode(root,n,a.decode),range(1,63)))
    covers=[]
    for p in sorted((root/'封面').glob('*.png')):
        info=json.loads(subprocess.check_output(['/opt/homebrew/bin/ffprobe','-v','error','-show_streams','-of','json',str(p)]))['streams'][0]
        covers.append(dict(file=p.name,width=info.get('width'),height=info.get('height'),sha256=hashlib.sha256(p.read_bytes()).hexdigest()))
    report=dict(source=str(root),source_read_only=True,video_count=len(result),subtitle_count=len(result),rights_verified=False,semantic_sync_verified=False,covers=covers,episodes=result)
    out=Path(a.output);out.parent.mkdir(parents=True,exist_ok=True);out.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(dict(episodes=len(result),decode=a.decode,issues=[{'episode':r['episode'],'errors':r['errors']} for r in result if r['errors']],covers=covers),ensure_ascii=False))

if __name__=='__main__':main()
