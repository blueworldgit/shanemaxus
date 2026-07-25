    (function(){
        var callout = window.mvpData["mvp-product-svg-gallery"][0];
        var source  = document.getElementById('mvp-pd-svg-source');
        if (!source) return;
        function mvpPdInit() {

        // The real diagram is the SVG with the most text callouts (no fixed
        // threshold, so small diagrams with only a few callouts still build).
        var srcSvg = null;
        source.querySelectorAll('svg').forEach(function(s){
            if (!srcSvg || s.querySelectorAll('text').length > srcSvg.querySelectorAll('text').length) srcSvg = s;
        });
        if (!srcSvg) { source.remove(); return; }

        // Keep the SVG's native viewBox and fit-to-box (centre + clip). We do NOT
        // expand the viewBox to chase stray off-page geometry, so no whitespace.
        srcSvg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
        srcSvg.removeAttribute('width');
        srcSvg.removeAttribute('height');
        srcSvg.style.width   = '100%';
        srcSvg.style.height  = '100%';
        srcSvg.style.display = 'block';
        srcSvg.style.transformOrigin = 'top left';
        srcSvg.style.transition = 'transform 0.2s';
        srcSvg.style.background = '#fff';

        var gallery = document.querySelector('.woocommerce-product-gallery__image')
                   || document.querySelector('.woocommerce-product-gallery__image--placeholder');
        if (!gallery) { source.remove(); return; }

        // Build the same widget shell as the category page (zoom controls + inner)
        var box = document.createElement('div');
        box.className = 'mvp-cd-svg-wrap mvp-pd-widget';

        var controls = document.createElement('div');
        controls.className = 'mvp-cd-zoom-controls';
        controls.innerHTML =
            '<button type="button" class="mvp-cd-zoom-btn" data-action="out" aria-label="Zoom out">&#8722;</button>' +
            '<button type="button" class="mvp-cd-zoom-btn" data-action="reset" aria-label="Reset zoom">&#8635;</button>' +
            '<button type="button" class="mvp-cd-zoom-btn" data-action="in" aria-label="Zoom in">&#43;</button>';

        var inner = document.createElement('div');
        inner.className = 'mvp-cd-svg-inner';
        inner.appendChild(srcSvg);

        box.appendChild(controls);
        box.appendChild(inner);
        gallery.innerHTML = '';
        gallery.appendChild(box);
        gallery.style.cursor = 'default';

        // Highlight this product's callout number in orange and ring it
        srcSvg.querySelectorAll('text').forEach(function(t){
            if (t.textContent.trim() === callout) {
                t.style.setProperty('fill', '#F29F05', 'important');
                t.style.setProperty('font-weight', 'bold', 'important');
                try {
                    var bb = t.getBBox();
                    var c = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                    c.setAttribute('cx', bb.x + bb.width / 2);
                    c.setAttribute('cy', bb.y + bb.height / 2);
                    c.setAttribute('r', Math.max(bb.width, bb.height) * 0.9 + 5);
                    c.setAttribute('fill', 'none');
                    c.setAttribute('stroke', '#F29F05');
                    c.setAttribute('stroke-width', '2');
                    // The callout text is positioned by its own transform, which getBBox
                    // ignores — copy it so the ring lands on the label, not at the origin.
                    var tf = t.getAttribute('transform');
                    if ( tf ) { c.setAttribute('transform', tf); }
                    t.parentNode.insertBefore(c, t);
                } catch(e) {}
            }
        });

        // Zoom: stepped +/-/reset buttons, PLUS hover-to-magnify (loupe) at
        // default scale — restores the original single-product hover behaviour.
        var scale = 1, STEP = 0.2, MIN = 0.4, MAX = 4, baseH = 0;
        var HOVER = 2.5, hovering = false;

        function applyBase(){
            srcSvg.style.transformOrigin = 'top left';
            srcSvg.style.transform = scale === 1 ? '' : 'scale(' + scale + ')';
            inner.style.overflow = scale > 1 ? 'auto' : 'hidden';
            inner.style.cursor   = scale > 1 ? 'grab' : 'zoom-in';
            inner.style.height   = scale > 1 ? (baseH * scale) + 'px' : '';
        }
        controls.querySelectorAll('.mvp-cd-zoom-btn').forEach(function(btn){
            btn.addEventListener('click', function(){
                if (!baseH) baseH = inner.clientHeight;
                var a = btn.dataset.action;
                if (a === 'in')    scale = Math.min(MAX, +(scale + STEP).toFixed(2));
                if (a === 'out')   scale = Math.max(MIN, +(scale - STEP).toFixed(2));
                if (a === 'reset') scale = 1;
                hovering = false;
                applyBase();
            });
        });
        applyBase();

        // Hover magnify — only when not stepped-zoomed via the buttons
        inner.addEventListener('mouseenter', function(){
            if (scale !== 1) return;
            hovering = true;
            inner.style.overflow = 'hidden';
        });
        inner.addEventListener('mousemove', function(e){
            if (scale !== 1 || !hovering) return;
            var r = inner.getBoundingClientRect();
            var x = (e.clientX - r.left) / r.width  * 100;
            var y = (e.clientY - r.top)  / r.height * 100;
            srcSvg.style.transformOrigin = x + '% ' + y + '%';
            srcSvg.style.transform = 'scale(' + HOVER + ')';
        });
        inner.addEventListener('mouseleave', function(){
            if (scale !== 1) return;
            hovering = false;
            srcSvg.style.transform = '';
            srcSvg.style.transformOrigin = 'top left';
        });

        // Kill the native WooCommerce zoom trigger left over from the gallery
        var trigger = document.querySelector('.woocommerce-product-gallery__trigger');
        if (trigger) trigger.style.display = 'none';

        source.remove();
        }
        // Phase 2: fetch the diagram SVG file into the hidden source, then build.
        if (source.getAttribute('data-svg-src') && !source.querySelector('svg')) {
            fetch(source.getAttribute('data-svg-src'))
                .then(function (r) { return r.ok ? r.text() : ''; })
                .then(function (t) { if (t) { source.innerHTML = t; } mvpPdInit(); })
                .catch(function () {});
        } else {
            mvpPdInit();
        }
    })();
