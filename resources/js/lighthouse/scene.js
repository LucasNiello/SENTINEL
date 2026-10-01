import * as THREE from 'three';
import { sceneQuality } from './quality';
import { storyPose, damping, smooth } from './motion';
import { createLighthouseModel, createLighting } from './model';

export function createLighthouse(stage, state, onFailure) {
    const host=stage.querySelector('[data-canvas]');
    let quality=sceneQuality();
    const canvas=document.createElement('canvas');
    const context=canvas.getContext('webgl2',{alpha:true,antialias:quality.antialias,powerPreference:'low-power'});
    if(!context) throw new Error('WebGL unavailable');
    const renderer=new THREE.WebGLRenderer({canvas,context,alpha:true,antialias:quality.antialias});
    renderer.setClearColor('#0A0E13',0);
    renderer.toneMapping=THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure=1.1;
    canvas.setAttribute('aria-hidden','true');
    const scene=new THREE.Scene();
    const camera=new THREE.PerspectiveCamera(32,1,.1,70);
    const model=createLighthouseModel(quality);
    scene.add(model.root);
    createLighting(scene);
    const cameraTarget=new THREE.Vector3();
    const vector=new THREE.Vector3();
    const initial=storyPose(state.progress);
    camera.position.fromArray(initial.camera);
    cameraTarget.fromArray(initial.target);
    let framing=initial.framing, angle=initial.angle, beam=initial.beam, lamp=initial.lamp;
    let frame=0, previous=0, elapsed=0, idleAngle=0, width=1, height=1;
    let visible=true, disposed=false, firstFrame=true, slowFrames=0, samples=0;

    function resizeScene() {
        if(disposed) return;
        quality=sceneQuality();
        width=stage.clientWidth; height=stage.clientHeight;
        if(!width||!height) return;
        renderer.setPixelRatio(Math.min(window.devicePixelRatio||1,quality.dpr));
        renderer.setSize(width,height,false);
        camera.aspect=width/height;
        camera.updateProjectionMatrix();
    }
    function updateScene(delta, now) {
        elapsed+=delta;
        const mobile=window.innerWidth<=600;
        const pose=storyPose(mobile ? 0 : state.progress);
        const factor=damping(4,delta);
        const atHero=state.progress<.15;
        if(atHero) idleAngle+=delta*Math.PI*2/26;
        // Blend out the exploratory rotation as scrolling takes over; never rotate the tower.
        const desiredAngle=pose.angle+idleAngle*(1-smooth(state.progress/.8));
        angle+=(desiredAngle-angle)*factor;
        model.rotating.rotation.y=angle;
        const pulse=state.confirmedAt ? Math.exp(-Math.max(0,now-state.confirmedAt)/450)*.6 : 0;
        const holding=state.confirmedAt ? 0 : state.confirmation;
        const multiplier=1+holding*.5+pulse;
        beam+=(pose.beam*multiplier-beam)*factor;
        lamp+=(pose.lamp*multiplier-lamp)*factor;
        model.beam.materials[0].uniforms.strength.value=.10*beam;
        model.beam.materials[1].uniforms.strength.value=.055*beam;
        model.lampLight.intensity=2.6*lamp;
        model.lampMaterial.color.set('#FFF4C2').multiplyScalar(1+(lamp-1)*.2);
        model.glowMaterial.opacity=.55*Math.min(1.5,lamp);
        model.root.position.lerp(vector.fromArray(pose.position),factor);
        const scale=model.root.scale.x+(pose.scale-model.root.scale.x)*factor;
        model.root.scale.setScalar(scale);
        const cameraPosition=mobile ? [8,5.8,14.5] : pose.camera;
        camera.position.lerp(vector.fromArray(cameraPosition),factor);
        cameraTarget.lerp(vector.fromArray(pose.target),factor);
        camera.lookAt(cameraTarget);
        framing+=((mobile?0:pose.framing)-framing)*factor;
        camera.setViewOffset(width,height,-width*framing,0,width,height);
        host.style.opacity=String(pose.opacity);
    }
    function render(now) {
        frame=0;
        if(disposed||!visible||document.hidden) return;
        frame=requestAnimationFrame(render);
        const duration=now-previous;
        if(duration<1000/quality.fps) return;
        previous=now;
        updateScene(Math.min(duration/1000,.08),now);
        try {
            renderer.render(scene,camera);
            if(firstFrame) { stage.classList.add('webgl-ready'); firstFrame=false; }
            // Viewport + measured performance only, no persistent device identification.
            if(window.innerWidth<=600 && samples<90) {
                samples++; if(duration>85) slowFrames++;
                if(samples===90 && slowFrames>30) { disposeScene(); onFailure(); }
            }
        } catch { disposeScene(); onFailure(); }
    }
    function sync() {
        cancelAnimationFrame(frame); frame=0;
        if(!disposed&&visible&&!document.hidden) {previous=performance.now();frame=requestAnimationFrame(render);}
    }
    const observer=new IntersectionObserver(([entry])=>{visible=entry.isIntersecting;sync();});
    const resizeObserver=new ResizeObserver(resizeScene);
    function contextLost(event) {event.preventDefault();disposeScene();onFailure();}
    function disposeScene() {
        if(disposed) return;
        disposed=true; cancelAnimationFrame(frame);
        observer.disconnect();resizeObserver.disconnect();
        document.removeEventListener('visibilitychange',sync);
        canvas.removeEventListener('webglcontextlost',contextLost);
        const geometries=new Set(), materials=new Set();
        scene.traverse(object=>{if(object.geometry)geometries.add(object.geometry);if(object.material)materials.add(object.material);});
        geometries.forEach(item=>item.dispose());materials.forEach(item=>item.dispose());model.textures.forEach(item=>item.dispose());
        renderer.dispose(); canvas.remove(); host.style.removeProperty('opacity');stage.classList.remove('webgl-ready');
    }
    host.appendChild(canvas);
    canvas.addEventListener('webglcontextlost',contextLost);
    document.addEventListener('visibilitychange',sync);
    observer.observe(stage);resizeObserver.observe(stage);resizeScene();sync();
    return {dispose:disposeScene};
}
