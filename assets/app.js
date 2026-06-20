(function () {
    let cleanupGraph = null;
    let threePromise = null;

    initTheme();
    initAccount();
    initTranslation();
    initCodeCopy();
    initLikeForms();
    initShareButtons();
    initGraphPanel();
    initResponsiveShell();

    function initTheme() {
        const buttons = document.querySelectorAll('[data-theme-choice]');

        function apply(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('red-theme', theme);
            buttons.forEach((button) => {
                button.classList.toggle('active', button.getAttribute('data-theme-choice') === theme);
            });

            const graphPanel = document.getElementById('graphPanel');
            const graphSearch = document.querySelector('.graph-search');
            if (graphPanel && graphPanel.classList.contains('open')) {
                drawGraph3d(graphSearch ? graphSearch.value : '');
            }
        }

        buttons.forEach((button) => {
            button.addEventListener('click', () => apply(button.getAttribute('data-theme-choice') || 'base'));
        });

        apply(localStorage.getItem('red-theme') || document.documentElement.getAttribute('data-theme') || 'base');
    }

    function initAccount() {
        const accountButton = document.querySelector('[data-account]');
        const accountDialog = document.getElementById('accountDialog');
        const closeButton = document.querySelector('[data-account-close]');

        if (accountButton && accountDialog) {
            accountButton.addEventListener('click', () => accountDialog.showModal());
        }
        if (closeButton && accountDialog) {
            closeButton.addEventListener('click', () => accountDialog.close());
        }
    }

    function initTranslation() {
        const select = document.querySelector('[data-translate]');
        if (!select) return;

        const current = readCookie('googtrans');
        const currentLang = current && current.split('/').pop();
        if (currentLang) {
            select.value = currentLang;
        }

        select.addEventListener('change', () => {
            const lang = select.value || 'ko';
            if (lang === 'ko') {
                eraseTranslateCookie();
            } else {
                writeTranslateCookie('/ko/' + lang);
            }
            window.location.reload();
        });
    }

    function readCookie(name) {
        const found = document.cookie.split('; ').find((row) => row.startsWith(name + '='));
        return found ? decodeURIComponent(found.split('=').slice(1).join('=')) : '';
    }

    function writeTranslateCookie(value) {
        const host = window.location.hostname;
        document.cookie = 'googtrans=' + encodeURIComponent(value) + ';path=/;max-age=31536000';
        if (host && host.includes('.')) {
            document.cookie = 'googtrans=' + encodeURIComponent(value) + ';path=/;domain=.' + host + ';max-age=31536000';
        }
    }

    function eraseTranslateCookie() {
        const host = window.location.hostname;
        document.cookie = 'googtrans=;path=/;max-age=0';
        if (host && host.includes('.')) {
            document.cookie = 'googtrans=;path=/;domain=.' + host + ';max-age=0';
        }
    }

    function initCodeCopy() {
        document.querySelectorAll('.markdown pre').forEach((pre) => {
            if (pre.closest('.code-shell')) return;

            const code = pre.querySelector('code');
            if (!code) return;

            const shell = document.createElement('div');
            shell.className = 'code-shell';

            const toolbar = document.createElement('div');
            toolbar.className = 'code-toolbar';

            const lang = pre.getAttribute('data-lang') || 'code';
            const label = document.createElement('span');
            label.className = 'code-lang';
            label.textContent = lang;

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'code-copy';
            button.textContent = '복사';
            button.setAttribute('aria-label', '코드를 클립보드에 복사');

            button.addEventListener('click', async () => {
                const ok = await copyText(code.textContent || '');
                button.textContent = ok ? '복사됨' : '실패';
                button.classList.toggle('copied', ok);
                window.setTimeout(() => {
                    button.textContent = '복사';
                    button.classList.remove('copied');
                }, 1400);
            });

            toolbar.append(label, button);
            pre.parentNode.insertBefore(shell, pre);
            shell.append(toolbar, pre);
        });
    }

    async function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(text);
                return true;
            } catch (error) {
                // Fall through to the textarea fallback.
            }
        }

        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();

        try {
            return document.execCommand('copy');
        } catch (error) {
            return false;
        } finally {
            textarea.remove();
        }
    }

    function initLikeForms() {
        document.querySelectorAll('[data-like-form], [data-save-form]').forEach((form) => {
            const isSave = form.hasAttribute('data-save-form');
            const button = form.querySelector('.note-action-button');
            const count = form.querySelector(isSave ? '[data-save-count]' : '[data-like-count]');
            const label = form.querySelector('.note-action-text');

            if (!button) return;

            form.addEventListener('submit', async (event) => {
                if (button.hasAttribute('data-login-required')) {
                    event.preventDefault();
                    const dialog = document.getElementById('accountDialog');
                    if (dialog && typeof dialog.showModal === 'function') {
                        dialog.showModal();
                    }
                    return;
                }

                if (!window.fetch || button.disabled) return;
                event.preventDefault();

                button.disabled = true;
                button.classList.add('loading');

                try {
                    const response = await fetch(form.getAttribute('action') || window.location.href, {
                        method: 'POST',
                        body: new FormData(form),
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'fetch'
                        },
                        credentials: 'same-origin'
                    });
                    const payload = await response.json();
                    if (!response.ok || !payload.ok) {
                        throw new Error(payload.message || (isSave ? '문서를 저장하지 못했습니다.' : '좋아요를 저장하지 못했습니다.'));
                    }

                    if (count) {
                        count.textContent = Number(payload.count || 0).toLocaleString();
                    }
                    if (label) {
                        label.textContent = isSave ? '저장됨' : '좋아요 완료';
                    }
                    button.classList.add('active');
                    button.setAttribute('aria-pressed', 'true');
                    button.disabled = true;
                } catch (error) {
                    button.disabled = false;
                    if (label) {
                        const original = label.textContent;
                        label.textContent = '다시 시도';
                        window.setTimeout(() => {
                            label.textContent = original || (isSave ? '저장' : '좋아요');
                        }, 1400);
                    }
                } finally {
                    button.classList.remove('loading');
                }
            });
        });
    }

    function initShareButtons() {
        document.querySelectorAll('[data-share-url]').forEach((button) => {
            button.addEventListener('click', async () => {
                const url = button.getAttribute('data-share-url') || window.location.href;
                const title = button.getAttribute('data-share-title') || document.title;

                if (navigator.share) {
                    try {
                        await navigator.share({ title, url });
                        return;
                    } catch (error) {
                        if (error && error.name === 'AbortError') return;
                    }
                }

                const ok = await copyText(url);
                const label = button.querySelector('.note-action-text') || button.querySelector('span') || button;
                const original = label.textContent;
                label.textContent = ok ? '복사됨' : '실패';
                button.classList.toggle('copied', ok);
                window.setTimeout(() => {
                    label.textContent = original || '공유';
                    button.classList.remove('copied');
                }, 1400);
            });
        });
    }

    function initGraphPanel() {
        const graphButtons = document.querySelectorAll('[data-panel="graph"]');
        const graphPanel = document.getElementById('graphPanel');
        const closeButton = document.querySelector('[data-panel-close]');
        const graphSearch = document.querySelector('.graph-search');

        if (graphButtons.length && graphPanel) {
            graphButtons.forEach((graphButton) => graphButton.addEventListener('click', () => {
                graphPanel.classList.add('open');
                graphPanel.setAttribute('aria-hidden', 'false');
                drawGraph3d(graphSearch ? graphSearch.value : '');
            }));
        }

        if (closeButton && graphPanel) {
            closeButton.addEventListener('click', () => {
                graphPanel.classList.remove('open');
                graphPanel.setAttribute('aria-hidden', 'true');
                if (cleanupGraph) cleanupGraph();
            });
        }

        if (graphSearch) {
            graphSearch.addEventListener('input', () => drawGraph3d(graphSearch.value));
        }
    }

    function initResponsiveShell() {
        const body = document.body;
        const sidebar = document.getElementById('siteSidebar');
        const sidebarOpen = document.querySelector('[data-sidebar-open]');
        const sidebarCloseButtons = document.querySelectorAll('[data-sidebar-close]');
        const toolsToggle = document.querySelector('[data-tools-toggle]');

        function closeSidebar() {
            body.classList.remove('sidebar-open');
            if (sidebar) sidebar.setAttribute('aria-hidden', 'true');
        }

        function openSidebar() {
            body.classList.add('sidebar-open');
            body.classList.remove('tools-open');
            if (sidebar) sidebar.setAttribute('aria-hidden', 'false');
        }

        function closeTools() {
            body.classList.remove('tools-open');
            if (toolsToggle) toolsToggle.setAttribute('aria-expanded', 'false');
        }

        function toggleTools() {
            const next = !body.classList.contains('tools-open');
            body.classList.toggle('tools-open', next);
            body.classList.remove('sidebar-open');
            if (toolsToggle) toolsToggle.setAttribute('aria-expanded', next ? 'true' : 'false');
            if (sidebar) sidebar.setAttribute('aria-hidden', 'true');
        }

        if (sidebarOpen) {
            sidebarOpen.addEventListener('click', openSidebar);
        }
        sidebarCloseButtons.forEach((button) => {
            button.addEventListener('click', closeSidebar);
        });
        if (toolsToggle) {
            toolsToggle.addEventListener('click', toggleTools);
        }

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeSidebar();
                closeTools();
            }
        });

        document.addEventListener('click', (event) => {
            if (!body.classList.contains('tools-open')) return;
            const target = event.target;
            if (!(target instanceof Element)) return;
            if (target.closest('.actions') || target.closest('[data-tools-toggle]')) return;
            closeTools();
        });

        window.addEventListener('resize', () => {
            if (window.innerWidth > 860) {
                body.classList.remove('sidebar-open', 'tools-open');
                if (sidebar) sidebar.setAttribute('aria-hidden', 'false');
                if (toolsToggle) toolsToggle.setAttribute('aria-expanded', 'false');
            }
        });

        if (window.innerWidth <= 860 && sidebar) {
            sidebar.setAttribute('aria-hidden', 'true');
        }
    }

    function loadThree() {
        if (!threePromise) {
            threePromise = Promise.all([
                import('https://esm.sh/three@0.160.0'),
                import('https://esm.sh/three@0.160.0/examples/jsm/controls/OrbitControls.js')
            ]).then(([THREE, controls]) => ({ THREE, OrbitControls: controls.OrbitControls }));
        }
        return threePromise;
    }

    function buildGraph(filterText) {
        const existing = (window.RED_GRAPH.notes || []).map((note) => ({
            id: String(note.id),
            slug: note.slug,
            title: note.title,
            exists: true
        }));
        const bySlug = new Map(existing.map((note) => [note.slug, note]));
        const links = [];

        (window.RED_GRAPH.links || []).forEach((link) => {
            const source = existing.find((note) => note.id === String(link.from_note_id));
            if (!source) return;

            let target = bySlug.get(link.target_slug);
            if (!target) {
                target = {
                    id: 'missing:' + link.target_slug,
                    slug: link.target_slug,
                    title: link.target_title || link.target_slug,
                    exists: false
                };
                bySlug.set(target.slug, target);
            }
            links.push({ source: source.slug, target: target.slug });
        });

        const allNodes = Array.from(bySlug.values());
        const filter = (filterText || '').trim().toLowerCase();
        const visible = new Set();

        if (filter) {
            allNodes.forEach((node) => {
                if ((node.title || '').toLowerCase().includes(filter)) {
                    visible.add(node.slug);
                    links.forEach((link) => {
                        if (link.source === node.slug || link.target === node.slug) {
                            visible.add(link.source);
                            visible.add(link.target);
                        }
                    });
                }
            });
        } else {
            allNodes.forEach((node) => visible.add(node.slug));
        }

        return {
            nodes: allNodes.filter((node) => visible.has(node.slug)),
            links: links.filter((link) => visible.has(link.source) && visible.has(link.target))
        };
    }

    async function drawGraph3d(filterText) {
        const mount = document.getElementById('graphMount');
        if (!mount || !window.RED_GRAPH) return;
        if (cleanupGraph) cleanupGraph();

        mount.innerHTML = '<div class="graph-loader">3D 그래프를 불러오는 중입니다...</div>';

        let modules;
        try {
            modules = await loadThree();
        } catch (error) {
            mount.innerHTML = '<div class="graph-loader">Three.js를 불러오지 못했습니다. 네트워크 연결을 확인해주세요.</div>';
            return;
        }

        const { THREE, OrbitControls } = modules;
        const theme = graphTheme();
        const graph = buildGraph(filterText);
        const width = Math.max(360, mount.clientWidth || 640);
        const height = Math.max(460, Math.min(window.innerHeight - 170, 780));

        mount.innerHTML = '';

        const scene = new THREE.Scene();
        scene.background = new THREE.Color(theme.bg);
        scene.fog = new THREE.Fog(theme.fog, 560, 1360);

        const camera = new THREE.PerspectiveCamera(55, width / height, 1, 2200);
        camera.position.set(0, 0, 640);

        const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false });
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        renderer.setSize(width, height);
        renderer.domElement.className = 'graph-canvas';
        mount.appendChild(renderer.domElement);

        const controls = new OrbitControls(camera, renderer.domElement);
        controls.enableDamping = true;
        controls.dampingFactor = 0.08;
        controls.rotateSpeed = 0.55;
        controls.zoomSpeed = 0.85;
        controls.panSpeed = 0.65;
        controls.minDistance = 180;
        controls.maxDistance = 1300;

        scene.add(new THREE.AmbientLight(0xffffff, theme.isDark ? 0.9 : 1.22));
        const keyLight = new THREE.DirectionalLight(0xffffff, theme.isDark ? 1.65 : 1.2);
        keyLight.position.set(180, 260, 340);
        scene.add(keyLight);
        const rimLight = new THREE.PointLight(theme.node, theme.isDark ? 1.8 : 0.75, 900);
        rimLight.position.set(-280, -180, 260);
        scene.add(rimLight);

        const grid = new THREE.GridHelper(760, 18, theme.line, theme.line);
        grid.position.y = -260;
        grid.material.transparent = true;
        grid.material.opacity = theme.isDark ? 0.16 : 0.11;
        scene.add(grid);

        const stars = makeStarField(THREE, theme);
        scene.add(stars);

        const nodeMaterial = new THREE.MeshStandardMaterial({
            color: theme.node,
            emissive: theme.node,
            emissiveIntensity: theme.isDark ? 0.22 : 0.05,
            roughness: 0.34,
            metalness: 0.18
        });
        const currentMaterial = new THREE.MeshStandardMaterial({
            color: theme.current,
            emissive: theme.current,
            emissiveIntensity: theme.isDark ? 0.28 : 0.08,
            roughness: 0.3,
            metalness: 0.2
        });
        const missingMaterial = new THREE.MeshStandardMaterial({
            color: theme.missing,
            roughness: 0.72,
            metalness: 0.03,
            transparent: true,
            opacity: 0.62
        });
        const lineMaterial = new THREE.LineBasicMaterial({
            color: theme.line,
            transparent: true,
            opacity: theme.isDark ? 0.54 : 0.42
        });
        const haloMaterial = new THREE.MeshBasicMaterial({
            color: theme.node,
            transparent: true,
            opacity: theme.isDark ? 0.12 : 0.075,
            depthWrite: false
        });

        const nodeGeometry = new THREE.SphereGeometry(10, 24, 16);
        const currentGeometry = new THREE.SphereGeometry(15, 28, 18);
        const missingGeometry = new THREE.SphereGeometry(7, 18, 12);
        const haloGeometry = new THREE.SphereGeometry(18, 20, 12);

        const nodes = graph.nodes.map((node, index) => {
            const phi = Math.acos(1 - 2 * ((index + 0.5) / Math.max(1, graph.nodes.length)));
            const theta = Math.PI * (1 + Math.sqrt(5)) * index;
            const radius = 185 + (index % 5) * 18;
            return {
                ...node,
                x: Math.cos(theta) * Math.sin(phi) * radius,
                y: Math.sin(theta) * Math.sin(phi) * radius,
                z: Math.cos(phi) * radius,
                vx: 0,
                vy: 0,
                vz: 0
            };
        });
        const nodeBySlug = new Map(nodes.map((node) => [node.slug, node]));
        const links = graph.links.map((link) => ({
            source: nodeBySlug.get(link.source),
            target: nodeBySlug.get(link.target)
        })).filter((link) => link.source && link.target);

        nodes.forEach((node) => {
            const isCurrent = node.slug === window.RED_CURRENT_SLUG;
            const geometry = isCurrent ? currentGeometry : (node.exists ? nodeGeometry : missingGeometry);
            const material = isCurrent ? currentMaterial : (node.exists ? nodeMaterial : missingMaterial);
            const mesh = new THREE.Mesh(geometry, material);
            mesh.position.set(node.x, node.y, node.z);
            mesh.userData.node = node;
            node.mesh = mesh;
            scene.add(mesh);

            if (node.exists) {
                const halo = new THREE.Mesh(haloGeometry, haloMaterial);
                halo.position.copy(mesh.position);
                node.halo = halo;
                scene.add(halo);
            }

            const label = makeLabel(THREE, node.title || node.slug, node.exists ? theme.labelCss : theme.mutedCss, theme.panelCss);
            label.position.set(node.x, node.y + 24, node.z);
            label.userData.node = node;
            node.label = label;
            scene.add(label);
        });

        const lineObjects = links.map((link) => {
            const geometry = new THREE.BufferGeometry().setFromPoints([
                new THREE.Vector3(link.source.x, link.source.y, link.source.z),
                new THREE.Vector3(link.target.x, link.target.y, link.target.z)
            ]);
            const line = new THREE.Line(geometry, lineMaterial);
            line.userData.link = link;
            scene.add(line);
            return line;
        });

        const raycaster = new THREE.Raycaster();
        const pointer = new THREE.Vector2();
        let pointerDown = null;
        let hovered = null;
        let frame = null;

        renderer.domElement.addEventListener('pointerdown', (event) => {
            pointerDown = { x: event.clientX, y: event.clientY };
        });

        renderer.domElement.addEventListener('pointermove', (event) => {
            const hit = pickNode(event);
            if (hovered && hovered !== hit) hovered.scale.setScalar(1);
            hovered = hit;
            renderer.domElement.style.cursor = hit ? 'pointer' : 'grab';
            if (hit) hit.scale.setScalar(1.25);
        });

        renderer.domElement.addEventListener('click', (event) => {
            if (pointerDown && Math.hypot(event.clientX - pointerDown.x, event.clientY - pointerDown.y) > 6) return;
            const hit = pickNode(event);
            if (!hit || !hit.userData.node) return;
            const node = hit.userData.node;
            window.location.href = node.exists ? `index.php?note=${encodeURIComponent(node.slug)}` : 'index.php?mode=new';
        });

        function pickNode(event) {
            const rect = renderer.domElement.getBoundingClientRect();
            pointer.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
            pointer.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;
            raycaster.setFromCamera(pointer, camera);
            const hits = raycaster.intersectObjects(nodes.map((node) => node.mesh), false);
            return hits.length ? hits[0].object : null;
        }

        function forceStep() {
            const centerForce = 0.0025;
            const linkForce = 0.0028;
            const repelForce = 16000;
            const damping = 0.88;

            nodes.forEach((node) => {
                node.vx += -node.x * centerForce;
                node.vy += -node.y * centerForce;
                node.vz += -node.z * centerForce;
            });

            links.forEach((link) => {
                const dx = link.target.x - link.source.x;
                const dy = link.target.y - link.source.y;
                const dz = link.target.z - link.source.z;
                const distance = Math.sqrt(dx * dx + dy * dy + dz * dz) || 1;
                const desired = 135;
                const force = (distance - desired) * linkForce;
                const fx = (dx / distance) * force;
                const fy = (dy / distance) * force;
                const fz = (dz / distance) * force;
                link.source.vx += fx;
                link.source.vy += fy;
                link.source.vz += fz;
                link.target.vx -= fx;
                link.target.vy -= fy;
                link.target.vz -= fz;
            });

            for (let i = 0; i < nodes.length; i++) {
                for (let j = i + 1; j < nodes.length; j++) {
                    const a = nodes[i];
                    const b = nodes[j];
                    const dx = b.x - a.x;
                    const dy = b.y - a.y;
                    const dz = b.z - a.z;
                    const distanceSq = Math.max(dx * dx + dy * dy + dz * dz, 90);
                    const distance = Math.sqrt(distanceSq);
                    const force = repelForce / distanceSq;
                    const fx = (dx / distance) * force;
                    const fy = (dy / distance) * force;
                    const fz = (dz / distance) * force;
                    a.vx -= fx;
                    a.vy -= fy;
                    a.vz -= fz;
                    b.vx += fx;
                    b.vy += fy;
                    b.vz += fz;
                }
            }

            nodes.forEach((node) => {
                node.vx *= damping;
                node.vy *= damping;
                node.vz *= damping;
                node.x += node.vx;
                node.y += node.vy;
                node.z += node.vz;
                node.mesh.position.set(node.x, node.y, node.z);
                if (node.halo) node.halo.position.set(node.x, node.y, node.z);
                node.label.position.set(node.x, node.y + 24, node.z);
                node.label.quaternion.copy(camera.quaternion);
            });

            lineObjects.forEach((line) => {
                const link = line.userData.link;
                const positions = line.geometry.attributes.position.array;
                positions[0] = link.source.x;
                positions[1] = link.source.y;
                positions[2] = link.source.z;
                positions[3] = link.target.x;
                positions[4] = link.target.y;
                positions[5] = link.target.z;
                line.geometry.attributes.position.needsUpdate = true;
            });
        }

        function animate() {
            forceStep();
            controls.update();
            stars.rotation.y += 0.0007;
            nodes.forEach((node) => node.label.quaternion.copy(camera.quaternion));
            renderer.render(scene, camera);
            frame = requestAnimationFrame(animate);
        }

        function resize() {
            const nextWidth = Math.max(360, mount.clientWidth || 640);
            const nextHeight = Math.max(460, Math.min(window.innerHeight - 170, 780));
            camera.aspect = nextWidth / nextHeight;
            camera.updateProjectionMatrix();
            renderer.setSize(nextWidth, nextHeight);
        }

        window.addEventListener('resize', resize);
        animate();

        cleanupGraph = () => {
            if (frame) cancelAnimationFrame(frame);
            window.removeEventListener('resize', resize);
            controls.dispose();
            renderer.dispose();
            nodeGeometry.dispose();
            currentGeometry.dispose();
            missingGeometry.dispose();
            haloGeometry.dispose();
            nodeMaterial.dispose();
            currentMaterial.dispose();
            missingMaterial.dispose();
            haloMaterial.dispose();
            lineMaterial.dispose();
            stars.geometry.dispose();
            stars.material.dispose();
            lineObjects.forEach((line) => line.geometry.dispose());
            mount.innerHTML = '';
            cleanupGraph = null;
        };
    }

    function graphTheme() {
        const styles = getComputedStyle(document.documentElement);
        const css = (name) => styles.getPropertyValue(name).trim();
        const toHex = (value, fallback) => {
            if (!value) return fallback;
            if (value.startsWith('#')) return Number('0x' + value.slice(1));
            const match = value.match(/\d+/g);
            if (!match || match.length < 3) return fallback;
            return (Number(match[0]) << 16) + (Number(match[1]) << 8) + Number(match[2]);
        };
        return {
            bg: toHex(css('--graph-bg'), 0xf6f2ea),
            fog: toHex(css('--graph-fog'), 0xf6f2ea),
            line: toHex(css('--graph-line'), 0x8c7b67),
            node: toHex(css('--graph-node'), 0xbd4b3a),
            current: toHex(css('--graph-current'), 0x25775a),
            missing: toHex(css('--graph-missing'), 0xc9c0b0),
            labelCss: css('--graph-label') || '#202124',
            mutedCss: css('--muted') || '#6d675e',
            panelCss: css('--panel') || '#fffaf2',
            isDark: document.documentElement.getAttribute('data-theme') === 'dark'
        };
    }

    function makeStarField(THREE, theme) {
        const geometry = new THREE.BufferGeometry();
        const positions = [];
        for (let i = 0; i < 180; i++) {
            positions.push((Math.random() - 0.5) * 1200);
            positions.push((Math.random() - 0.5) * 900);
            positions.push((Math.random() - 0.5) * 1200);
        }
        geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
        const material = new THREE.PointsMaterial({
            color: theme.line,
            size: theme.isDark ? 2.1 : 1.4,
            transparent: true,
            opacity: theme.isDark ? 0.28 : 0.16,
            depthWrite: false
        });
        return new THREE.Points(geometry, material);
    }

    function makeLabel(THREE, text, color, strokeColor) {
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d');
        const label = String(text || '').length > 20 ? String(text).slice(0, 20) + '...' : String(text || '');
        const fontSize = 32;
        context.font = `700 ${fontSize}px system-ui, sans-serif`;
        const metrics = context.measureText(label);
        canvas.width = Math.ceil(metrics.width + 34);
        canvas.height = 58;
        context.font = `700 ${fontSize}px system-ui, sans-serif`;
        context.textAlign = 'center';
        context.textBaseline = 'middle';
        context.lineJoin = 'round';
        context.strokeStyle = strokeColor || 'rgba(255, 253, 248, 0.95)';
        context.lineWidth = 8;
        context.strokeText(label, canvas.width / 2, canvas.height / 2);
        context.fillStyle = color;
        context.fillText(label, canvas.width / 2, canvas.height / 2);

        const texture = new THREE.CanvasTexture(canvas);
        texture.needsUpdate = true;
        const material = new THREE.SpriteMaterial({ map: texture, transparent: true, depthWrite: false });
        const sprite = new THREE.Sprite(material);
        sprite.scale.set(canvas.width * 0.22, canvas.height * 0.22, 1);
        return sprite;
    }
})();
