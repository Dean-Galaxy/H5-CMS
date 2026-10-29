// Refuse a stale evidence label, foreign runtime or insecure fixture file.
const fs=require('node:fs'),cp=require('node:child_process'),assert=require('node:assert/strict');
module.exports=function guard(root,candidate='658f4078e3a2e943b617aeda6bb7c7e265dee1ac'){
 const ws=JSON.parse(fs.readFileSync(root+'/.runtime/workspace.json'));assert.equal(fs.realpathSync(ws.root),fs.realpathSync(root));
 assert(Number.isInteger(ws.port)&&ws.port>1024&&ws.port!==8206&&ws.port!==8303,'Use the new isolated 06 runtime.');
 const paths=['h5-platform/app','h5-platform/config','h5-platform/resources','h5-platform/public','h5-platform/routes','h5-platform/database','h5-platform/bootstrap','h5-platform/composer.json','h5-platform/composer.lock'];
 assert.equal(cp.execFileSync('git',['diff',candidate,'--',...paths],{cwd:root,encoding:'utf8'}),'','Application differs from evidence candidate.');
 const file=root+'/.runtime/content/preview-account.json';assert(!fs.lstatSync(file).isSymbolicLink()&&(fs.statSync(file).mode&0o077)===0,'Private own fixture required.');
};
