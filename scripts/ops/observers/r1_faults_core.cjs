'use strict';
const assert=require('node:assert/strict'),crypto=require('node:crypto');
const ROOT='/Users/hejunxiang/.codex/worktrees/h5-r1-registration-manual/H5';
const RUNTIME='78bec3629bb4523a424516a5755fea421a28b20d';
const FROM='2026-10-30T19:53:47Z',UNTIL='2026-10-31T18:53:47Z',ORIGIN='http://127.0.0.1:8412';
const CASES=Object.freeze(['partial-503','lost-registration-json','lost-purpose-json','network-before-purpose','identity-unavailable','status-401','status-403','status-419','status-422','status-429','actual-cas']);
function validateTarget(root,manifest,processRecord,now=Date.now()){
 assert.equal(root,ROOT);assert.equal(manifest.workspace.root,ROOT);assert.equal(manifest.candidate,RUNTIME);assert.equal(processRecord.candidate,RUNTIME);assert.equal(processRecord.root,ROOT);assert.equal(processRecord.role,'manual');
 assert.equal(manifest.workspace.port,8412);assert.equal(manifest.workspace.db_port,5912);assert.equal(manifest.policy_from,FROM);assert.equal(manifest.policy_until,UNTIL);assert(Date.parse(FROM)<=now&&now<Date.parse(UNTIL));assert.equal(manifest.approved,true);assert.equal(manifest.external,false);assert.equal(manifest.purchase,false);assert.equal(manifest.purposes_effective,false);assert.equal(manifest.queue_scheduler,false);
}
function caseInput(name,locale){assert(CASES.includes(name));assert(['en','zh_CN'].includes(locale));return {name,locale};}
function competingAllow(original){assert.equal(original.purpose,'account_link');assert.equal(original.choice,'allow');return {...original,operation_id:crypto.randomUUID()};}
function acceptedRetry(value,revision){return value?.schema_version===3&&value.purpose==='account_link'&&value.status==='unchanged'&&value.state?.choice==='allow'&&value.state.effective===false&&value.state.revision===revision;}
function identityFailureSettled({purposeResponses,result,expected,advertisingRequests,unchecked,hiddenRetries}){return purposeResponses===1&&result===expected&&advertisingRequests===0&&unchecked&&hiddenRetries;}
function allowRequest(url){try{const parsed=new URL(url);return parsed.origin===ORIGIN&&!parsed.username&&!parsed.password;}catch{return false;}}
module.exports={ROOT,RUNTIME,FROM,UNTIL,ORIGIN,CASES,validateTarget,caseInput,competingAllow,acceptedRetry,identityFailureSettled,allowRequest};
