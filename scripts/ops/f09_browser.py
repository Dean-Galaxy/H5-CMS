#!/usr/bin/env python3
"""06 browser adapters: own loopback workspace only; never change module sources."""
import argparse
import hashlib
import json
import os
import re
from pathlib import Path
import subprocess
import sys
from datetime import datetime, timezone

ROOT = Path(__file__).resolve().parents[2]
CANDIDATE = "658f4078e3a2e943b617aeda6bb7c7e265dee1ac"
SOURCES = {
    "content": "Content/Support/design-alignment.browser.cjs",
    "content-pairs-all": "Content/Support/visual-pairs.browser.cjs",
    "content-prototype-all": "Content/Support/prototype-comparison.browser.cjs",
    "content-pairs": "Content/Support/visual-pairs.browser.cjs",
    "content-prototype": "Content/Support/prototype-comparison.browser.cjs",
    "release-range": "Content/Support/release-list.browser.cjs",
    "acquisition": "Acquisition/Support/admin-ui-smoke.cjs",
    "acquisition-errors": "Acquisition/Support/admin-ui-smoke.cjs",
    "acquisition-dirty": "Acquisition/Support/dirty-state-smoke.cjs",
}
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("scenario", choices=SOURCES)
a = parser.parse_args()
ws = json.loads((ROOT / ".runtime/workspace.json").read_text())
if Path(ws["root"]).resolve() != ROOT:
    raise SystemExit("Refuse foreign workspace.")
# Evidence-only commits are allowed; changed application code requires a new baseline.
paths = ["h5-platform/app", "h5-platform/config", "h5-platform/resources", "h5-platform/public", "h5-platform/routes", "h5-platform/database", "h5-platform/bootstrap", "h5-platform/composer.json", "h5-platform/composer.lock"]
if subprocess.check_output(["git", "diff", CANDIDATE, "--", *paths], cwd=ROOT):
    raise SystemExit("Application differs from fixed candidate; ask 00 to fix a new SHA.")
module = os.environ.get("H5_PLAYWRIGHT_MODULE")
if not module or not Path(module).exists():
    raise SystemExit("Set H5_PLAYWRIGHT_MODULE to your existing local Playwright; no dependency install.")
run = ROOT / ".runtime/06-f09" / (a.scenario + "-" + datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ"))
run.mkdir(parents=True, mode=0o700)
source = ROOT / "h5-platform/tests/Feature" / SOURCES[a.scenario]
original = source.read_text()
script = original
changes = []
env = os.environ.copy()
base = "http://127.0.0.1:" + str(ws["port"])
env.update(H5_CONTENT_BROWSER_URL=base, H5_CONTENT_BROWSER_ACCOUNT=str(ROOT / ".runtime/content/preview-account.json"))
if a.scenario.startswith("acquisition"):
    owner = ROOT / ".runtime/content/preview-account.json"
    operator = ROOT / ".runtime/acquisition-operator-preview-account.json"
    required = [owner, ROOT / ".runtime/acquisition-f09-fixture.json"]
    if a.scenario == "acquisition": required.append(operator)
    if any(not p.is_file() for p in required):
        raise SystemExit("Prepare own Owner, local visual fixture and MFA operator; see 06 F09 report.")
    for p in [owner] + ([operator] if a.scenario == "acquisition" else []):
        if p.stat().st_mode & 0o077 or not json.loads(p.read_text()).get("totp_secret"):
            raise SystemExit("Private 0600 MFA account required; never dump credential JSON.")
    env.update(H5_ACQUISITION_PREVIEW_URL=base, H5_ACQUISITION_PREVIEW_ACCOUNT=str(owner),
               H5_ACQUISITION_OPERATOR_ACCOUNT=str(operator), H5_ACQUISITION_SCREENSHOTS=str(run / "screenshots"),
               H5_ACQUISITION_CAPTURE_PREFIX="658f407-")
    if a.scenario == "acquisition-errors": env["H5_ACQUISITION_ERRORS_ONLY"] = "1"
    old = "getByLabel(/6-digit code/)"
    if script.count(old) != 1: raise SystemExit("MFA adapter source changed; inspect before running.")
    script = script.replace(old, "locator('input[id=\"multiFactorChallengeForm.app.code\"]')")
    script = script.replace("url => url.pathname === '/admin'", "url => url.pathname.startsWith('/admin') && url.pathname !== '/admin/login' && !url.pathname.includes('multi-factor-authentication/set-up')")
    changes.append("Exact dotted MFA id; accept successful intended admin path, reject login/setup. No auth bypass.")
    # Omit only the export iframe shell, retaining every byte of its srcdoc host CSS.
    old = "const frame=prototype.frameLocator('#codex-visualization');"
    if old in script:
        script = script.replace(old, "const srcdoc=await prototype.locator('#codex-visualization').evaluate(el=>el.srcdoc);await prototype.setContent(srcdoc);const frame=prototype;")
        changes.append("Render full original srcdoc at actual viewport without export-only 16px shell.")
elif a.scenario == "release-range":
    # Fresh 06 data has a real 35/35 withdrawn record from content flow. Other edge cases remain response fixtures.
    lines = script.splitlines()
    result = []
    for line in lines:
        if "check('real five-episode withdrawn completion" in line: continue
        if "check('real partially published disabled plan" in line:
            result.append(" await capture('actual');")
        else: result.append(line)
    script = "\n".join(result) + "\n"
    script = script.replace("const m=r[2].match", "const m=(r[2]||'').match")
    changes.append("Require actual 35/35 withdrawn fixture; 5/5, unknown, partial and cursor edge cases stay explicitly intercepted display fixtures.")
elif a.scenario in ("content-pairs-all", "content-prototype-all"):
    actual = a.scenario == "content-pairs-all"
    folder = "design-pairs-all" if actual else "design-prototype-all"
    side = "app" if actual else "prototype"
    (ROOT / ".runtime/content" / folder).mkdir(parents=True, exist_ok=True)
    script = script.replace("runtime+'/design-pairs'", "runtime+'/"+folder+"'") if actual else script.replace("root+'/.runtime/content/design-alignment'", "root+'/.runtime/content/"+folder+"'")
    capture = """shot=async name=>{if(name.includes('episodes-mobile'))return;const prior=page.viewportSize(),dark=await page.evaluate(()=>matchMedia('(prefers-color-scheme:dark)').matches),wasClass=await page.evaluate(()=>document.documentElement.classList.contains('dark'));const sizes=[['desktop',1100,1000,'light'],['desktop-dark',1100,1000,'dark'],['mobile',375,812,'light'],['mobile-dark',375,812,'dark']];if(/new|upload|editor|candidate|confirm|resume|cover/.test(name))sizes.push(['short',375,500,'light'],['short-dark',375,500,'dark']);for(const [label,width,height,theme] of sizes){await page.setViewportSize({width,height});await page.emulateMedia({colorScheme:theme});await page.evaluate(t=>{document.documentElement.classList.toggle('dark',t==='dark');scrollTo(0,0);document.querySelectorAll('.h5-dialog-body,.h5-dialog').forEach(el=>el.scrollTop=0)},theme);await page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))));await page.screenshot({path:out+'/658f407-'+label+'-'+name+'-SIDE.png'});}await page.setViewportSize(prior);await page.emulateMedia({colorScheme:dark?'dark':'light'});await page.evaluate(v=>document.documentElement.classList.toggle('dark',v),wasClass);};""".replace('SIDE', side)
    script, number = re.subn(r"shot=async name=>\{.*?\};", lambda _: capture, script, count=1)
    if number != 1: raise SystemExit("Capture adapter source changed.")
    changes.append("Same state photographed at 1100x1000 and 375x812 light/dark; shown dialogs additionally 375x500 light/dark. Viewport/theme restored after capture. No business/fixture changes.")
elif a.scenario == "content":
    env["H5_CONTENT_EVIDENCE_DIR"] = "f09-final-658f407"
elif a.scenario == "content-prototype":
    (ROOT / ".runtime/content/design-alignment").mkdir(parents=True, exist_ok=True)

prepared = run / "run.cjs"
prepared.write_text(script)
prepared.chmod(0o600)
meta = {"candidate": CANDIDATE, "checkoutHead": subprocess.check_output(["git","rev-parse","HEAD"],cwd=ROOT,text=True).strip(),
        "scenario":a.scenario,"base":base,"source":str(source.relative_to(ROOT)),
        "sourceSha256":hashlib.sha256(original.encode()).hexdigest(),"adapterSha256":hashlib.sha256(script.encode()).hexdigest(),"changes":changes}
with (run / "stdout.txt").open("w") as out, (run / "stderr.txt").open("w") as err:
    completed = subprocess.run([os.environ.get("H5_NODE_BIN","node"),str(prepared)],cwd=ROOT,env=env,stdout=out,stderr=err)
meta["exitCode"] = completed.returncode
(run / "run.json").write_text(json.dumps(meta,ensure_ascii=False,indent=2)+"\n")
for line in (run / "stdout.txt").read_text().splitlines():
    try: receipt=json.loads(line)
    except ValueError: continue
    if isinstance(receipt,dict) and isinstance(receipt.get("passed"),list):
        (run / "result.json").write_text(json.dumps(receipt,ensure_ascii=False,indent=2)+"\n")
        meta["checks"] = len(receipt["passed"])
print(json.dumps({"scenario":a.scenario,"exitCode":completed.returncode,"checks":meta.get("checks"),"privateEvidence":str(run)},ensure_ascii=False))
sys.exit(completed.returncode)
