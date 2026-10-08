#!/usr/bin/env python3
"""Create only synthetic, private 06 assets. Refuse overwrites; no paid media or downloads."""
import json
import os
from pathlib import Path
import subprocess
ROOT = Path(__file__).resolve().parents[2]
c = json.loads((ROOT / '.runtime/workspace.json').read_text())
assert c['root'] == str(ROOT)
os.umask(0o077)
run = ROOT / '.runtime/06'
run.mkdir(exist_ok=True)
outputs = [run / x for x in ('sample.mp4', 'cover.png', 'sample.srt')]
if any(p.exists() for p in outputs):
    raise SystemExit('Existing assets preserved; use the existing 06 sample.')
subprocess.run(['ffmpeg','-nostdin','-v','error','-n','-f','lavfi','-i','testsrc2=size=320x180:rate=24',
 '-f','lavfi','-i','sine=frequency=440:sample_rate=48000','-t','140','-c:v','libx264','-pix_fmt','yuv420p',
 '-g','48','-c:a','aac','-movflags','+faststart',str(outputs[0])],check=True)
subprocess.run(['ffmpeg','-nostdin','-v','error','-n','-i',str(outputs[0]),'-frames:v','1',str(outputs[1])],check=True)
outputs[2].write_text('1\n00:00:00,000 --> 00:00:12,000\n06 synthetic HLS acceptance sample\n\n2\n00:00:12,000 --> 00:02:20,000\nGenerated picture and tone; no licensed programme.\n')
print('Created three synthetic files in the private 06 runtime.')
