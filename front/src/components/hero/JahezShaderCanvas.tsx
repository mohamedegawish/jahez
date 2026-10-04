import React, { useEffect, useRef } from 'react';
import * as THREE from 'three';

interface JahezShaderProps {
  className?: string;
  children?: React.ReactNode;
}

export const JahezShaderCanvas: React.FC<JahezShaderProps> = ({ className = '', children }) => {
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const container = containerRef.current;
    if (!container) return;

    // Scene, Camera, Renderer
    const scene = new THREE.Scene();
    const width = container.clientWidth || window.innerWidth;
    const height = container.clientHeight || 500;

    const camera = new THREE.PerspectiveCamera(45, width / height, 0.1, 1000);
    // User props: cDistance=3.6, cameraZoom=1, fov=45, cAzimuthAngle=180, cPolarAngle=90
    camera.position.set(0, 0, 3.6);

    const renderer = new THREE.WebGLRenderer({
      alpha: true,
      antialias: true,
      powerPreference: 'high-performance',
    });
    renderer.setSize(width, height);
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 1.5));
    container.appendChild(renderer.domElement);

    // Geometry matching user props: type="plane", positionX=-1.4, rotationZ=50, rotationY=10
    const geometry = new THREE.PlaneGeometry(7, 5, 80, 80);

    // Exact colors from user props:
    // color1: #faf3eb -> (0.98, 0.95, 0.92)
    // color2: #b8e2f4 -> (0.72, 0.88, 0.95)
    // color3: #b8e2f4 -> (0.72, 0.88, 0.95)
    const customMaterial = new THREE.ShaderMaterial({
      uniforms: {
        uTime: { value: 0 },
        uSpeed: { value: 0.2 },
        uStrength: { value: 4.0 },
        uDensity: { value: 1.3 },
        uFrequency: { value: 5.5 },
        uAmplitude: { value: 1.0 },
        color1: { value: new THREE.Color('#faf3eb') },
        color2: { value: new THREE.Color('#b8e2f4') },
        color3: { value: new THREE.Color('#9B8AFB') },
        brightness: { value: 1.1 }
      },
      vertexShader: `
        uniform float uTime;
        uniform float uSpeed;
        uniform float uStrength;
        uniform float uDensity;
        uniform float uFrequency;
        uniform float uAmplitude;
        varying vec2 vUv;
        varying float vElevation;

        // Classic Perlin-like wave generator
        void main() {
          vUv = uv;
          vec3 pos = position;

          float t = uTime * uSpeed;
          float wave1 = sin(pos.x * uFrequency * 0.25 + t) * cos(pos.y * uDensity * 0.3 + t);
          float wave2 = sin(pos.y * uFrequency * 0.35 - t * 0.8) * cos(pos.x * uDensity * 0.25 + t * 0.5);
          float wave3 = sin((pos.x + pos.y) * 0.5 + t * 0.6);

          float elevation = (wave1 + wave2 + wave3 * 0.5) * (uStrength * 0.08) * uAmplitude;
          pos.z += elevation;

          vElevation = elevation;
          gl_Position = projectionMatrix * modelViewMatrix * vec4(pos, 1.0);
        }
      `,
      fragmentShader: `
        uniform vec3 color1;
        uniform vec3 color2;
        uniform vec3 color3;
        uniform float brightness;
        varying vec2 vUv;
        varying float vElevation;

        void main() {
          float mixStrength = (vElevation + 0.35) * 1.2;
          mixStrength = clamp(mixStrength, 0.0, 1.0);

          vec3 grad = mix(color1, color2, smoothstep(0.1, 0.75, vUv.x + vElevation * 0.4));
          vec3 finalColor = mix(grad, color3, smoothstep(0.5, 0.95, vUv.y + vElevation * 0.3));

          // Apply brightness
          finalColor *= brightness;

          gl_FragColor = vec4(finalColor, 0.94);
        }
      `,
      wireframe: false,
      transparent: true,
      side: THREE.DoubleSide
    });

    const mesh = new THREE.Mesh(geometry, customMaterial);
    // User props: positionX={-1.4}, positionY={0}, positionZ={0}
    mesh.position.set(-0.8, 0, 0);
    // User props: rotationX={0}, rotationY={10deg}, rotationZ={50deg}
    mesh.rotation.x = 0;
    mesh.rotation.y = THREE.MathUtils.degToRad(10);
    mesh.rotation.z = THREE.MathUtils.degToRad(42);

    scene.add(mesh);

    let animationFrameId: number;
    let clock = new THREE.Clock();

    const animate = () => {
      animationFrameId = requestAnimationFrame(animate);
      const elapsedTime = clock.getElapsedTime();
      customMaterial.uniforms.uTime.value = elapsedTime;
      renderer.render(scene, camera);
    };

    animate();

    const handleResize = () => {
      if (!container) return;
      const newWidth = container.clientWidth;
      const newHeight = container.clientHeight;
      camera.aspect = newWidth / newHeight;
      camera.updateProjectionMatrix();
      renderer.setSize(newWidth, newHeight);
    };

    window.addEventListener('resize', handleResize);

    return () => {
      window.removeEventListener('resize', handleResize);
      cancelAnimationFrame(animationFrameId);
      if (renderer.domElement && container.contains(renderer.domElement)) {
        container.removeChild(renderer.domElement);
      }
      geometry.dispose();
      customMaterial.dispose();
      renderer.dispose();
    };
  }, []);

  return (
    <div className={`relative overflow-hidden ${className}`}>
      {/* 3D WebGL Canvas Layer */}
      <div 
        ref={containerRef} 
        className="absolute inset-0 w-full h-full pointer-events-none"
        style={{ zIndex: 1 }}
      />

      {/* Content Layer over the Shader */}
      <div className="relative z-10">
        {children}
      </div>
    </div>
  );
};
