import * as THREE from 'three';

function mesh(parent, geometry, material, y = 0) {
    const object = new THREE.Mesh(geometry, material);
    object.position.y = y;
    parent.add(object);
    return object;
}

function lightTexture(beam = false) {
    const canvas = document.createElement('canvas');
    canvas.width = beam ? 128 : 64;
    canvas.height = beam ? 256 : 64;
    const context = canvas.getContext('2d');
    if (beam) {
        const pixels = context.createImageData(canvas.width, canvas.height);
        for (let y = 0; y < canvas.height; y++) {
            for (let x = 0; x < canvas.width; x++) {
                const u = x / (canvas.width - 1), v = 1 - y / (canvas.height - 1);
                const alpha = Math.pow(v, 1.5) * Math.pow(Math.sin(Math.PI * u), 1.6) * (1 - Math.pow(v, 22));
                const i = (y * canvas.width + x) * 4;
                pixels.data.set([255, 255, 255, Math.round(alpha * 255)], i);
            }
        }
        context.putImageData(pixels, 0, 0);
    } else {
        const glow = context.createRadialGradient(32,32,0,32,32,32);
        glow.addColorStop(0, 'rgba(255,252,239,.8)');
        glow.addColorStop(.15, 'rgba(255,244,194,.35)');
        glow.addColorStop(.45, 'rgba(255,244,194,.07)');
        glow.addColorStop(1, 'rgba(255,244,194,0)');
        context.fillStyle = glow; context.fillRect(0,0,64,64);
    }
    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;
    return texture;
}

export function createBeam(segments) {
    const group = new THREE.Group();
    group.name = 'single-light-beam';
    const texture = lightTexture(true);
    const materials = [];
    for (const [radius, strength] of [[2.2,.10],[1.05,.055]]) {
        const geometry = new THREE.ConeGeometry(radius, 10, segments, 1, true);
        geometry.translate(0,-5,0); geometry.rotateX(-Math.PI / 2);
        const material = new THREE.ShaderMaterial({
            uniforms: { map: { value: texture }, strength: { value: strength }, tint: { value: new THREE.Color('#FFFCEF') } },
            vertexShader: `varying vec2 vUv; varying vec3 vNormal; varying vec3 vView;
                void main() { vUv=uv; vec4 p=modelViewMatrix*vec4(position,1.0); vView=-p.xyz; vNormal=normalMatrix*normal; gl_Position=projectionMatrix*p; }`,
            fragmentShader: `uniform sampler2D map; uniform float strength; uniform vec3 tint;
                varying vec2 vUv; varying vec3 vNormal; varying vec3 vView;
                void main() { float edge=pow(abs(dot(normalize(vNormal),normalize(vView))),3.0);
                    gl_FragColor=vec4(tint,texture2D(map,vUv).a*strength*edge); }`,
            transparent: true, depthWrite: false, blending: THREE.AdditiveBlending, side: THREE.DoubleSide,
        });
        materials.push(material);
        mesh(group, geometry, material);
    }
    group.position.z = .38;
    group.rotation.x = .09;
    return { group, materials, texture };
}

export function createLantern(root, materials, quality) {
    const n = quality.segments;
    const housing = new THREE.Group();
    housing.name = 'stationary-lantern-housing';
    housing.position.y = 5.04;
    root.add(housing);
    mesh(housing, new THREE.CylinderGeometry(.55,.59,.13,n), materials.metal, -.46);
    mesh(housing, new THREE.CylinderGeometry(.55,.55,.08,n), materials.metal, .45);
    const postGeometry = new THREE.CylinderGeometry(.024,.024,.86,8);
    for (let i=0;i<8;i++) {
        const post = mesh(housing, postGeometry, materials.metal);
        post.position.set(Math.sin(i*Math.PI/4)*.52,0,Math.cos(i*Math.PI/4)*.52);
    }
    mesh(housing, new THREE.CylinderGeometry(.73,.73,.075,n), materials.metal, .51);
    mesh(housing, new THREE.ConeGeometry(.72,.51,n), materials.roof, .795);
    mesh(housing, new THREE.SphereGeometry(.045,12,8), materials.metal, 1.065);

    const rotating = new THREE.Group();
    rotating.name = 'rotating-lantern';
    rotating.position.y = 5.04;
    root.add(rotating);
    const glass = new THREE.MeshStandardMaterial({ color: '#A8C8E6', roughness: .12, metalness: .12, transparent: true, opacity: .13, depthWrite: false, side: THREE.DoubleSide });
    mesh(rotating, new THREE.CylinderGeometry(.50,.50,.82,n,1,true), glass);
    const reflector = mesh(rotating, new THREE.SphereGeometry(.3, n, 16), materials.reflector);
    reflector.scale.z = .3; reflector.position.z = -.10;
    const lampMaterial = new THREE.MeshBasicMaterial({ color:'#FFF4C2', toneMapped:false });
    const lamp = mesh(rotating, new THREE.SphereGeometry(.14,24,16), lampMaterial);
    lamp.position.z = .18;
    const lens = mesh(rotating, new THREE.CylinderGeometry(.20,.20,.055, n), materials.reflector);
    lens.rotation.x = Math.PI/2; lens.position.z = -.03;
    const lampLight = new THREE.PointLight('#FFF4C2', 2.6, 4, 2);
    lampLight.position.z = .24; rotating.add(lampLight);
    const glowTexture = lightTexture();
    const glowMaterial = new THREE.SpriteMaterial({ map:glowTexture, transparent:true, depthWrite:false, blending:THREE.AdditiveBlending, opacity:.55 });
    const glow = new THREE.Sprite(glowMaterial);
    glow.scale.set(1.8,1.8,1); glow.position.z=.24; rotating.add(glow);
    const beam = createBeam(n); rotating.add(beam.group);
    return { rotating, lampLight, lampMaterial, glowMaterial, beam, textures:[glowTexture,beam.texture] };
}

export function createLighthouseModel(quality) {
    const root = new THREE.Group();
    root.name = 'lighthouse-root';
    const materials = {
        tower: new THREE.MeshStandardMaterial({ color:'#A8C8E6', roughness:.76, metalness:.06 }),
        metal: new THREE.MeshStandardMaterial({ color:'#496783', roughness:.4, metalness:.5 }),
        roof: new THREE.MeshStandardMaterial({ color:'#7294B3', roughness:.5, metalness:.28 }),
        reflector: new THREE.MeshStandardMaterial({ color:'#8A97A5', roughness:.24, metalness:.72 }),
    };
    const n=quality.segments;
    mesh(root, new THREE.CylinderGeometry(.97,1.03,.14,n), materials.metal, .07);
    const tower=mesh(root, new THREE.CylinderGeometry(.47,.76,4.24,n),materials.tower,2.26);
    tower.name='stationary-tower';
    mesh(root,new THREE.CylinderGeometry(.79,.83,.12,n),materials.roof,.20);
    mesh(root,new THREE.CylinderGeometry(.78,.68,.16,n),materials.metal,4.43);
    mesh(root,new THREE.CylinderGeometry(.83,.83,.07,n),materials.roof,4.545);
    const rail=mesh(root,new THREE.TorusGeometry(.79,.018,8,n),materials.metal,4.77);
    rail.rotation.x=Math.PI/2;
    const railPostGeometry=new THREE.CylinderGeometry(.014,.014,.20,6);
    for(let i=0;i<8;i++) {
        const post=mesh(root,railPostGeometry,materials.metal,4.67);
        post.position.x=Math.sin(i*Math.PI/4)*.79; post.position.z=Math.cos(i*Math.PI/4)*.79;
    }
    return {root,tower,...createLantern(root,materials,quality)};
}

export function createLighting(scene) {
    const key=new THREE.DirectionalLight('#FFFCEF',3.6); key.position.set(-4,8,7);
    const rim=new THREE.DirectionalLight('#A8C8E6',3.1); rim.position.set(4,6,-5);
    const fill=new THREE.DirectionalLight('#A8C8E6',.4); fill.position.set(6,2,6);
    scene.add(key,rim,fill,new THREE.HemisphereLight('#A8C8E6','#0A0E13',.34));
}
