const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),context={};
vm.runInNewContext(fs.readFileSync(root+'/assets/js/marker-colors.js','utf8'),context);
const cases=[
 [{categoryColors:{park:'#445566'}},{cat:'park',markerColor:'#AABBCC',color:'#112233'},'#aabbcc'],
 [{categoryColors:{park:'#445566'}},{cat:'park',markerColor:'',color:'#112233'},'#445566'],
 [{categoryColors:{new:'#445566'}},{cat:'new',markerColor:''},'#445566'],
 [{},{cat:'new',markerColor:'#AABBCC'},'#aabbcc'],
 [{},{cat:'new',markerColor:''},'#7a7f87'],
 [{},{cat:'park',markerColor:'red;display:none',color:'#112233'},'#112233'],
 [{},{cat:'park',markerColor:'',color:'invalid'},'#7a7f87']
];
for(const [meta,spot,expected] of cases){
 assert.equal(context.SouliongMarkerColors.spot(meta,spot),expected);
 const output=execFileSync('php',['-r','require $argv[1]; $v=json_decode($argv[2],true); echo souliong_spot_color($v[0],$v[1]);',root+'/api/markercolors.php',JSON.stringify([meta,spot])],{encoding:'utf8'});
 assert.equal(output,expected);
}
console.log('PASS: 7 組前後端顏色優先順序、清除與非法值回退');
