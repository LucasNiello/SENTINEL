export const sceneStates = [
    { name:'hero', camera:[7.2,5.5,13.4], target:[0,2.9,0], position:[0,0,0], scale:1, framing:.245, angle:1.15, beam:1, lamp:1, opacity:1 },
    { name:'consult', camera:[6.3,6.7,10.9], target:[0,3.55,0], position:[.1,0,0], scale:1, framing:.29, angle:4.7, beam:1.3, lamp:1, opacity:1 },
    { name:'confirm', camera:[-6.7,5.4,13.4], target:[0,2.85,0], position:[0,0,0], scale:.93, framing:-.26, angle:7.75, beam:.8, lamp:.85, opacity:1 },
    { name:'audit', camera:[5.8,8.2,12.1], target:[0,4.2,0], position:[0,0,0], scale:1.04, framing:.285, angle:10.7, beam:1.2, lamp:1, opacity:1 },
    { name:'transition', camera:[2.7,6.25,5.4], target:[0,5.02,0], position:[0,0,0], scale:1.12, framing:.08, angle:12.9, beam:1.6, lamp:1.35, opacity:1 },
    { name:'work', camera:[1.3,5.75,3.5], target:[0,5.05,0], position:[0,0,0], scale:1.12, framing:0, angle:12.94, beam:2, lamp:1.5, opacity:0 },
];
export const clamp = value => Math.max(0,Math.min(1,value));
export const smooth = value => { const t=clamp(value); return t*t*(3-2*t); };
const mix = (a,b,t) => a+(b-a)*t;

export function storyPose(progress) {
    const p=Math.max(0,Math.min(sceneStates.length-1,progress));
    const i=Math.min(sceneStates.length-2,Math.floor(p));
    const t=smooth(p-i), a=sceneStates[i], b=sceneStates[i+1];
    const result={};
    for(const key of ['camera','target','position']) result[key]=a[key].map((v,j)=>mix(v,b[key][j],t));
    for(const key of ['scale','framing','angle','beam','lamp','opacity']) result[key]=mix(a[key],b[key],t);
    return result;
}

// Exponential damping is independent of the refresh rate.
export const damping = (speed,delta) => 1-Math.exp(-speed*delta);
