import { prefersReducedMotion } from './motion';

/**
 * x-book-scene: progressively enhances an <x-book-3d> (`scene` prop) with a three.js book. The CSS book is always rendered first
 * and stays as the fallback; after first paint (requestIdleCallback) three.js is imported (its own chunk), the cover is loaded as a
 * texture from the same-origin proxy (`data-scene-cover`), and once the first frame is drawn a canvas fades in over the CSS book.
 *
 * Skipped entirely (the CSS book stays) without WebGL, with reduced motion, on `saveData`, on coarse pointers below md, and when
 * the cover texture fails. One renderer per element; it pauses off-screen and in a hidden tab, and is disposed on Alpine cleanup
 * and on `livewire:navigating`. `el.bookScene.turnTo(url)` opens the cover like a leaf and reveals another one (the hero's flip).
 * `el.dataset.scene` reads `pending`, `ready`, `skipped` or `disposed`.
 *
 * Drawn on demand: frames run only while something moves (the pointer easing the book, the float settling after a pointer move, a turn)
 * and the loop stops once the book is at rest, so an idle page costs no GPU. Pointer moves, turns, resizes and re-entering the viewport restart it.
 */

// The book in units of its own width: 1 wide, 1.5 tall, 0.2 deep. Hardcover boards, a rounded spine, a recessed page block.
const WIDTH = 1;
const HEIGHT = 1.5;
const DEPTH = 0.2;
const BOARD = 0.04;
const MARGIN_X = 0.4; // The canvas extends this far past the book on each side, and 0.2 of the height above and below (app.css).
const MARGIN_Y = 0.2;
const REST_PITCH = 0.12; // The CSS book rests at -7deg; three's x axis points the other way.

const live = new Set();

let webgl;

// Probed once per page, and the probe context is released at once: Chrome keeps only ~16 live contexts, reclaimed on GC at best.
const supportsWebGL = () => {
    if (webgl !== undefined) return webgl;

    try {
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('webgl2') || canvas.getContext('webgl');

        context?.getExtension('WEBGL_lose_context')?.loseContext();

        return (webgl = Boolean(context));
    } catch {
        return (webgl = false);
    }
};

const skipReason = () => {
    if (prefersReducedMotion()) return 'reduced-motion';
    if (navigator.connection?.saveData) return 'save-data';
    if (window.matchMedia('(pointer: coarse)').matches && !window.matchMedia('(min-width: 48rem)').matches) return 'coarse-pointer';
    if (!supportsWebGL()) return 'no-webgl';

    return null;
};

const idle = (callback) => ('requestIdleCallback' in window ? requestIdleCallback(callback, { timeout: 1500 }) : setTimeout(callback, 200));
const cancelIdle = (handle) => ('cancelIdleCallback' in window ? cancelIdleCallback(handle) : clearTimeout(handle));

const ease = (t) => (t < 0.5 ? 4 * t * t * t : 1 - (-2 * t + 2) ** 3 / 2);

export default function bookScene(Alpine) {
    window.addEventListener('livewire:navigating', () => [...live].forEach((dispose) => dispose()));

    Alpine.directive('book-scene', (el, _, { cleanup }) => {
        const coverUrl = el.dataset.sceneCover;
        const reason = coverUrl ? skipReason() : 'no-cover';

        if (reason) {
            el.dataset.scene = 'skipped';

            return;
        }

        el.dataset.scene = 'pending';

        let disposed = false;
        let dispose = () => {
            disposed = true;
            el.dataset.scene = 'disposed';
        };
        const handle = idle(() => start().catch(() => dispose()));

        const stop = () => dispose();

        live.add(stop);
        cleanup(() => {
            cancelIdle(handle);
            stop();
            live.delete(stop);
        });

        async function start() {
            const THREE = await import('three');
            const { RoomEnvironment } = await import('three/examples/jsm/environments/RoomEnvironment.js');
            const { RoundedBoxGeometry } = await import('three/examples/jsm/geometries/RoundedBoxGeometry.js');

            if (disposed) return;

            // Everything with a GPU/CPU footprint; items replaced by a turn are released and leave the set, so it stays bounded.
            const disposables = new Set();
            const track = (item) => (disposables.add(item), item);
            const release = (item) => (item.dispose(), disposables.delete(item));
            const loader = new THREE.TextureLoader();
            const load = (url) => new Promise((resolve, reject) => loader.load(url, resolve, undefined, reject));
            const prepare = (texture, renderer) => {
                texture.colorSpace = THREE.SRGBColorSpace;
                texture.anisotropy = Math.min(8, renderer.capabilities.getMaxAnisotropy());

                return track(texture);
            };

            // The jacket round the hinge and the back are made from the cover, never copied from it: a mirrored copy would show
            // mirrored letters, and a sharp one a readable title on the back. So both are heavily blurred colour fields.
            const surface = (width, height) => {
                const art = document.createElement('canvas');
                art.width = width;
                art.height = height;

                return [art, art.getContext('2d')];
            };
            // Blur by repeated smooth upscaling of a tiny sample (canvas `filter` is missing in Safari).
            const soften = (image, sx, sy, sw, sh, tinyW, tinyH, width, height) => {
                let [art, context] = surface(tinyW, tinyH);
                context.drawImage(image, sx, sy, sw, sh, 0, 0, tinyW, tinyH);

                while (art.width < width || art.height < height) {
                    const [bigger, biggerContext] = surface(Math.min(width, art.width * 3), Math.min(height, art.height * 3));
                    biggerContext.imageSmoothingQuality = 'high';
                    biggerContext.drawImage(art, 0, 0, bigger.width, bigger.height);
                    art = bigger;
                    context = biggerContext;
                }

                return [art, context];
            };
            const wrapMaps = (image, renderer) => {
                // Spine: the cover's left edge, stretched across the spine and blurred; the right edge (u = 1) meets the front.
                const edge = Math.max(2, image.width * 0.05);
                const [spineArt, spineContext] = soften(image, 0, 0, edge, image.height, 1, 28, 96, 384);
                const crease = spineContext.createLinearGradient(0, 0, 96, 0);
                crease.addColorStop(0, 'rgba(0,0,0,0.38)');
                crease.addColorStop(0.1, 'rgba(0,0,0,0.02)');
                crease.addColorStop(0.9, 'rgba(0,0,0,0.02)');
                crease.addColorStop(0.96, 'rgba(0,0,0,0.22)');
                crease.addColorStop(1, 'rgba(0,0,0,0.42)');
                spineContext.fillStyle = crease;
                spineContext.fillRect(0, 0, 96, 384);
                spineContext.fillStyle = 'rgba(255,255,255,0.16)'; // Two blind-stamped bands, as on a bound spine.
                [0.075, 0.095, 0.905, 0.925].forEach((at) => spineContext.fillRect(14, Math.round(384 * at), 68, 2));

                // Back: the whole cover, blurred past reading, and darkened.
                const [backArt, backContext] = soften(image, 0, 0, image.width, image.height, 10, 15, 160, 240);
                backContext.fillStyle = 'rgba(0,0,0,0.34)';
                backContext.fillRect(0, 0, 160, 240);

                return [spineArt, backArt].map((art) => prepare(new THREE.CanvasTexture(art), renderer));
            };

            const canvas = document.createElement('canvas');
            canvas.className = 'book3d__canvas';
            canvas.setAttribute('aria-hidden', 'true');

            const renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: true, powerPreference: 'low-power' });
            renderer.setClearColor(0x000000, 0);
            renderer.outputColorSpace = THREE.SRGBColorSpace;
            renderer.toneMapping = THREE.NeutralToneMapping;
            renderer.toneMappingExposure = 0.95;

            const scene = new THREE.Scene();
            const pmrem = new THREE.PMREMGenerator(renderer);

            // Until the real teardown exists, a failure after this point (or a dispose while the cover loads) still frees the renderer.
            dispose = () => {
                disposed = true;
                disposables.forEach((item) => item.dispose());
                scene.environment?.dispose();
                pmrem.dispose();
                renderer.dispose();
                renderer.forceContextLoss();
                canvas.remove();
                el.dataset.scene = 'disposed';
            };

            const room = new RoomEnvironment();
            scene.environment = pmrem.fromScene(room, 0.06).texture;
            scene.environmentIntensity = 0.55;
            room.dispose();

            // The cover must load before anything is drawn, so a failure leaves the CSS book untouched.
            let cover;

            try {
                cover = prepare(await load(coverUrl), renderer);
            } catch {
                return dispose();
            }

            if (disposed) return;

            const aspect = (WIDTH + MARGIN_X * 2) / (HEIGHT * (1 + MARGIN_Y * 2));
            const fov = 22;
            const camera = new THREE.PerspectiveCamera(fov, aspect, 0.1, 50);
            camera.position.z = (HEIGHT * (1 + MARGIN_Y * 2)) / (2 * Math.tan((fov * Math.PI) / 360));

            // Light: the room gives soft reflections, a warm key from the top left carries the form, a cool rim from behind the edge.
            const key = new THREE.DirectionalLight(0xfff1e0, 2.4);
            key.position.set(-2.5, 3, 4);
            const rim = new THREE.DirectionalLight(0xbcd2ff, 0.9);
            rim.position.set(3, 1, -3);
            scene.add(key, rim, new THREE.HemisphereLight(0xffffff, 0x202028, 0.25));

            // The one jacket: the cover on the front, its left edge (mirrored, so the art meets the front without a seam) round the
            // spine, and the cover again, darkened, on the back. Page edges are paper only.
            const book = new THREE.Group();
            const pivot = new THREE.Group();
            pivot.add(book);
            scene.add(pivot);

            const cloth = track(new THREE.MeshStandardMaterial({ color: 0x2a2a2e, roughness: 0.75, metalness: 0 }));
            const jacket = (map, color = 0xffffff) => track(new THREE.MeshPhysicalMaterial({
                map,
                color,
                roughness: 0.52,
                metalness: 0,
                clearcoat: 0.18,
                clearcoatRoughness: 0.55,
            }));

            const boardGeometry = track(new RoundedBoxGeometry(WIDTH, HEIGHT, BOARD, 4, 0.012));
            const boardZ = DEPTH / 2 - BOARD / 2;

            [boardZ, -boardZ].forEach((z) => {
                const board = new THREE.Mesh(boardGeometry, cloth);
                board.position.z = z;
                book.add(board);
            });

            const faceGeometry = track(new THREE.PlaneGeometry(WIDTH - 0.012, HEIGHT - 0.012));

            const front = new THREE.Mesh(faceGeometry, jacket(cover));
            front.position.z = DEPTH / 2 + 0.0006;
            book.add(front);

            let [spineMap, backMap] = wrapMaps(cover.image, renderer);

            const back = new THREE.Mesh(faceGeometry, jacket(backMap));
            back.position.z = -DEPTH / 2 - 0.0006;
            back.rotation.y = Math.PI;
            book.add(back);

            const spineGeometry = track(new THREE.CylinderGeometry(DEPTH / 2, DEPTH / 2, HEIGHT - 0.012, 32, 1, true, Math.PI, Math.PI));

            const spine = new THREE.Mesh(spineGeometry, jacket(spineMap));
            spine.position.x = -WIDTH / 2;
            spine.scale.x = 0.22; // A hardcover spine bulges a little, it does not widen the book.
            book.add(spine);

            // Paper: a stripe per leaf, darker towards the boards where the covers shade it.
            const leaves = (vertical) => {
                const art = document.createElement('canvas');
                art.width = vertical ? 256 : 16;
                art.height = vertical ? 16 : 256;
                const context = art.getContext('2d');
                context.fillStyle = '#e9e4d8';
                context.fillRect(0, 0, art.width, art.height);

                for (let i = 0; i < 256; i += 3) {
                    context.fillStyle = `rgba(90, 80, 60, ${0.05 + ((i * 7) % 11) / 90})`;
                    vertical ? context.fillRect(i, 0, 1, 16) : context.fillRect(0, i, 16, 1);
                }

                const shade = vertical ? context.createLinearGradient(0, 0, 256, 0) : context.createLinearGradient(0, 0, 0, 256);
                shade.addColorStop(0, 'rgba(0,0,0,0.38)');
                shade.addColorStop(0.18, 'rgba(0,0,0,0)');
                shade.addColorStop(0.82, 'rgba(0,0,0,0)');
                shade.addColorStop(1, 'rgba(0,0,0,0.38)');
                context.fillStyle = shade;
                context.fillRect(0, 0, art.width, art.height);

                const texture = prepare(new THREE.CanvasTexture(art), renderer);

                return track(new THREE.MeshStandardMaterial({ map: texture, roughness: 0.92, metalness: 0 }));
            };
            const edge = leaves(true); // +x: leaves vary along the depth (u).
            const headTail = leaves(false); // +y / -y: leaves vary along the depth (v).
            const plain = track(new THREE.MeshStandardMaterial({ color: 0xe9e4d8, roughness: 0.92 }));
            const pagesGeometry = track(new THREE.BoxGeometry(WIDTH - 0.055, HEIGHT - 0.06, DEPTH - BOARD * 2));
            const pages = new THREE.Mesh(pagesGeometry, [edge, plain, headTail, headTail, plain, plain]);
            pages.position.x = 0.0125;
            book.add(pages);

            // The hinge crease on the front, a soft groove painted over the art (no lighting).
            const crease = document.createElement('canvas');
            crease.width = 256;
            crease.height = 4;
            const creaseContext = crease.getContext('2d');
            const groove = creaseContext.createLinearGradient(0, 0, 256, 0);
            groove.addColorStop(0, 'rgba(0,0,0,0.4)');
            groove.addColorStop(0.03, 'rgba(255,255,255,0.14)');
            groove.addColorStop(0.06, 'rgba(0,0,0,0.3)');
            groove.addColorStop(0.12, 'rgba(0,0,0,0)');
            creaseContext.fillStyle = groove;
            creaseContext.fillRect(0, 0, 256, 4);
            const creaseMesh = new THREE.Mesh(faceGeometry, track(new THREE.MeshBasicMaterial({
                map: track(new THREE.CanvasTexture(crease)),
                transparent: true,
                depthWrite: false,
            })));
            creaseMesh.position.z = DEPTH / 2 + 0.0012;
            book.add(creaseMesh);

            // The leaf that turns: the old cover, hinged at the spine, over a paper back. Hidden until a turn.
            const leaf = new THREE.Group();
            leaf.position.set(-WIDTH / 2, 0, DEPTH / 2 + 0.004);
            leaf.visible = false;
            const leafFront = new THREE.Mesh(faceGeometry, jacket(null));
            leafFront.position.x = WIDTH / 2;
            const leafBack = new THREE.Mesh(faceGeometry, track(new THREE.MeshStandardMaterial({ color: 0xe9e4d8, roughness: 0.9, side: THREE.BackSide })));
            leafBack.position.x = WIDTH / 2;
            leaf.add(leafFront, leafBack);
            book.add(leaf);

            // The contact shadow: a soft ellipse under the book that breathes with the float.
            const blob = document.createElement('canvas');
            blob.width = blob.height = 128;
            const blobContext = blob.getContext('2d');
            const glow = blobContext.createRadialGradient(64, 64, 0, 64, 64, 64);
            glow.addColorStop(0, 'rgba(0,0,0,0.78)');
            glow.addColorStop(0.55, 'rgba(0,0,0,0.42)');
            glow.addColorStop(1, 'rgba(0,0,0,0)');
            blobContext.fillStyle = glow;
            blobContext.fillRect(0, 0, 128, 128);
            const shadow = new THREE.Mesh(
                track(new THREE.PlaneGeometry(1.2, 0.42)),
                track(new THREE.MeshBasicMaterial({ map: track(new THREE.CanvasTexture(blob)), transparent: true, depthWrite: false, opacity: 1 })),
            );
            // The same pool as the CSS book's (.book3d__shadow): 78% of the width, 0.2 wide deep, centred just below the bottom edge.
            shadow.position.set(0.02, -HEIGHT / 2 - 0.05, -0.3);
            scene.add(shadow);

            // Sizing: the canvas is the book plus its margins; the renderer follows the element and the device pixel ratio.
            const resize = () => {
                const { width, height } = canvas.getBoundingClientRect();

                if (width && height) {
                    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
                    renderer.setSize(width, height, false);
                }
            };

            el.appendChild(canvas);
            resize();

            const tilt = (parseFloat(getComputedStyle(el).getPropertyValue('--tilt')) || -18) * (Math.PI / 180);
            const pointer = { x: 0, y: 0 };
            const current = { yaw: tilt, pitch: REST_PITCH };
            let turn = null;
            let frame = 0;
            let visible = true;
            let last = 0;
            let first = true;
            let clock = 0; // Virtual seconds that advance only while drawing, so the float resumes where it stopped.
            let float = 0; // 0..1: the idle float's strength; it fades out after activity so the loop can stop.
            let awakeUntil = 0;
            let draws = 0;

            const onPointer = (event) => {
                if (event.pointerType === 'touch') return;

                const box = el.getBoundingClientRect();
                const clamp = (value) => Math.max(-1, Math.min(1, value));

                const x = clamp((event.clientX - (box.left + box.width / 2)) / 600);
                const y = clamp((event.clientY - (box.top + box.height / 2)) / 500);

                if (Math.abs(x - pointer.x) < 0.002 && Math.abs(y - pointer.y) < 0.002) return;

                pointer.x = x;
                pointer.y = y;
                awakeUntil = performance.now() + 1500;
                run();
            };

            const draw = (now) => {
                frame = 0;

                if (disposed || !visible || document.hidden) return;

                const dt = Math.min((now - (last || now)) / 1000, 0.1);
                last = now;
                const follow = 1 - Math.exp(-dt * 4);
                const lively = turn !== null || now < awakeUntil;

                clock += dt;
                float += ((lively ? 1 : 0) - float) * follow;

                const yawGap = tilt + pointer.x * 0.5 - current.yaw;
                const pitchGap = REST_PITCH + pointer.y * 0.22 - current.pitch;
                const settled = !lively && float < 0.01 && Math.abs(yawGap) < 0.0005 && Math.abs(pitchGap) < 0.0005;

                if (settled) float = 0;

                current.yaw += yawGap * follow;
                current.pitch += pitchGap * follow;

                const time = clock;

                book.rotation.set(current.pitch + Math.sin(time * 0.6) * 0.01 * float, current.yaw + Math.sin(time * 0.45) * 0.03 * float, Math.sin(time * 0.5) * 0.006 * float);
                pivot.position.y = Math.sin(time * 0.9) * 0.022 * float;
                shadow.scale.setScalar(1 - Math.sin(time * 0.9) * 0.04 * float);

                if (turn) {
                    const progress = Math.min((now - turn.start) / turn.duration, 1);
                    const eased = ease(progress);

                    leaf.rotation.y = -eased * 2.5;
                    leafFront.material.opacity = leafBack.material.opacity = progress < 0.7 ? 1 : 1 - (progress - 0.7) / 0.3;
                    pivot.position.y += Math.sin(progress * Math.PI) * 0.05;

                    if (progress === 1) {
                        leaf.visible = false;
                        turn.done();
                        turn = null;
                    }
                }

                renderer.render(scene, camera);
                draws++;

                if (first) {
                    first = false;
                    // The canvas fades in over the CSS book; once it has, the CSS book steps out of the way.
                    requestAnimationFrame(() => {
                        el.dataset.scene = 'ready';
                        canvas.dataset.ready = '';
                        setTimeout(() => !disposed && el.toggleAttribute('data-scene-live', true), 500);
                    });
                }

                // At rest: the frame above is the last until something wakes the loop (run()).
                if (!settled || turn) frame = requestAnimationFrame(draw);
            };

            const run = () => {
                if (!frame && visible && !document.hidden && !disposed) {
                    last = 0;
                    frame = requestAnimationFrame(draw);
                }
            };

            const observer = new IntersectionObserver(([entry]) => {
                visible = entry.isIntersecting;
                visible ? run() : (cancelAnimationFrame(frame), (frame = 0));
            });
            const sizer = new ResizeObserver(() => (resize(), run()));
            const onVisibility = () => (document.hidden ? (cancelAnimationFrame(frame), (frame = 0)) : run());

            observer.observe(el);
            sizer.observe(el);
            document.addEventListener('visibilitychange', onVisibility);
            window.addEventListener('pointermove', onPointer, { passive: true });
            run();

            // el.bookScene.turnTo(url): the old cover swings open on its hinge and the new one is underneath.
            let queue = Promise.resolve();
            const turnTo = (url) => (queue = queue.then(() => turnNow(url)).catch(() => {}));
            const turnNow = async (url) => {
                if (disposed || turn) return;

                const next = prepare(await load(url), renderer);

                if (disposed) return;

                const old = front.material.map;
                leafFront.material.map = old;
                leafFront.material.transparent = leafBack.material.transparent = true;
                leafFront.material.needsUpdate = true;
                front.material.map = next;
                const [nextSpine, nextBack] = wrapMaps(next.image, renderer);
                const oldWrap = [spineMap, backMap];
                [spineMap, backMap] = [nextSpine, nextBack];
                back.material.map = backMap;
                spine.material.map = spineMap;
                front.material.needsUpdate = back.material.needsUpdate = spine.material.needsUpdate = true;
                leaf.rotation.y = 0;
                leaf.visible = true;

                await new Promise((resolve) => {
                    turn = { start: performance.now(), duration: 900, done: resolve };
                    run();
                });

                leafFront.material.map = null;
                release(old);
                oldWrap.forEach(release);
            };

            // Test hook: the live resource counts, to prove turns do not accumulate and the loop idles.
            el.bookScene = { turnTo, stats: () => ({ resources: disposables.size, looping: frame !== 0, draws }) };
            el.addEventListener('book-scene:turn', (event) => turnTo(event.detail.src));

            dispose = () => {
                if (disposed && el.dataset.scene === 'disposed') return;

                disposed = true;
                cancelAnimationFrame(frame);
                observer.disconnect();
                sizer.disconnect();
                document.removeEventListener('visibilitychange', onVisibility);
                window.removeEventListener('pointermove', onPointer);
                disposables.forEach((item) => item.dispose());
                disposables.clear();
                scene.environment?.dispose();
                pmrem.dispose();
                renderer.dispose();
                renderer.forceContextLoss();
                canvas.remove();
                el.removeAttribute('data-scene-live');
                delete el.bookScene;
                el.dataset.scene = 'disposed';
                live.delete(stop);
            };
        }
    });
}
