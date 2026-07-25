    document.addEventListener("DOMContentLoaded", function () {
        var wrap = document.getElementById(window.mvpData["mvp-render-component-diagram"][0]);
        if (!wrap) return;
        var rows   = Array.from(wrap.querySelectorAll('.mvp-cd-row'));
        // Prefer the diagram SVG inside this widget's container (robust for small
        // diagrams with <=5 callouts, where the old text-count heuristic failed).
        var svg = wrap.querySelector('.mvp-cd-svg-inner svg');
        if (!svg) { document.querySelectorAll('svg').forEach(function(s) { if (!svg && s.querySelectorAll('text').length > 5) svg = s; }); }
        // Only EXPAND viewBox if content is clipped outside it (never shrink) — uses svg.getBBox for full coverage
        if (svg) {
            try {
                var vb = (svg.getAttribute('viewBox') || '0 0 100 100').split(/\s+/).map(Number);
                var vbX=vb[0], vbY=vb[1], vbW=vb[2], vbH=vb[3];
                var bb = svg.getBBox();
                var mnX = Math.min(vbX, bb.x);
                var mnY = Math.min(vbY, bb.y);
                var mxX = Math.max(vbX+vbW, bb.x+bb.width);
                var mxY = Math.max(vbY+vbH, bb.y+bb.height);
                var changed = (mnX < vbX || mnY < vbY || mxX > vbX+vbW || mxY > vbY+vbH);
                if(changed){ var p=15; svg.setAttribute('viewBox',(mnX-p)+' '+(mnY-p)+' '+(mxX-mnX+p*2)+' '+(mxY-mnY+p*2)); }
            } catch(e) {}
        }
        // Save original SVG fills/strokes at init
        if (svg) {
            svg.querySelectorAll("text").forEach(function(t) {
                t.setAttribute("data-orig-fill", t.getAttribute("fill") || "");
                t.setAttribute("data-orig-size", t.getAttribute("font-size") || "9");
            });
        }
        var inner  = wrap.querySelector('.mvp-cd-svg-inner');
        var scale  = 1;
        var STEP   = 0.2;
        var MIN    = 0.4;
        var MAX    = 4;

        // ── Zoom controls ────────────────────────────────────────────────
        wrap.querySelectorAll('.mvp-cd-zoom-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var action = btn.dataset.action;
                if (action === 'in')    scale = Math.min(MAX, +(scale + STEP).toFixed(2));
                if (action === 'out')   scale = Math.max(MIN, +(scale - STEP).toFixed(2));
                if (action === 'reset') scale = 1;
                if (svg) svg.style.transform = scale === 1 ? '' : 'scale(' + scale + ')';
                // Expand inner height when zoomed so scrolling works
                if (inner && svg) {
                    inner.style.height = scale > 1
                        ? (svg.getBoundingClientRect().height * scale + 40) + 'px'
                        : '';
                }
            });
        });

        // ── Callout highlighting ─────────────────────────────────────────
        function activate(num) {
            var n = String(num);
            rows.forEach(function (r) {
                r.classList.toggle('mvp-cd-active', r.dataset.callout === n);
            });
            // Re-resolve the diagram SVG if it wasn't captured at load (guards
            // against a DOMContentLoaded timing race on large inline SVGs).
            if (!svg) { svg = wrap.querySelector('.mvp-cd-svg-inner svg'); }
            if (svg) {
                svg.querySelectorAll('text').forEach(function (t) {
                    var orig = t.getAttribute('data-orig-fill') || '';
                    if (t.textContent.trim() === n) {
                        t.style.setProperty('fill', '#F29F05', 'important');
                        t.style.setProperty('font-weight', 'bold', 'important');
                        t.style.setProperty('font-size', '14px', 'important');
                    } else {
                        t.style.removeProperty('fill');
                        t.style.removeProperty('font-weight');
                        var os = t.getAttribute('data-orig-size');
                        t.style.removeProperty('font-size');
                    }
                });
            }
        }

        function deactivate() {
            rows.forEach(function (r) { r.classList.remove('mvp-cd-active'); });
            if (svg) {
                svg.querySelectorAll('text').forEach(function (t) {
                    var orig = t.getAttribute('data-orig-fill') || '';
                    t.style.removeProperty('fill');
                    t.style.removeProperty('font-weight');
                    var os = t.getAttribute('data-orig-size');
                    t.style.removeProperty('font-size');
                });
            }
        }

        // Table row click → highlight SVG callout
        rows.forEach(function (row) {
            row.addEventListener('click', function () {
                activate(row.dataset.callout);
            });
            row.addEventListener('mouseenter', function () {
                activate(row.dataset.callout);
            });
            row.addEventListener('mouseleave', function () {
                deactivate();
            });
        });

        // SVG text click → highlight row + scroll into view
        if (svg) {
            svg.querySelectorAll('text').forEach(function (t) {
                var n = t.textContent.trim();
                if (/^\d+$/.test(n)) {
                    t.style.cursor = 'pointer';
                    t.addEventListener('click', function (e) {
                        e.stopPropagation();
                        activate(n);
                        var tableWrap = wrap.querySelector('.mvp-cd-table-wrap');
                        var match = rows.find(function (r) { return r.dataset.callout === n; });
                        if (match && tableWrap) match.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    });
                }
            });
        }

        // ── Sync table height to SVG diagram height ──────────────────────
        var svgWrap = wrap.querySelector('.mvp-cd-svg-wrap');
        var tableWrap = wrap.querySelector('.mvp-cd-table-wrap');
        if (svgWrap && tableWrap && window.innerWidth > 700) {
            function syncTableHeight() {
                var svgH = svgWrap.offsetHeight;
                if (svgH > 200) {
                    tableWrap.style.maxHeight = svgH + 'px';
                }
            }
            syncTableHeight();
            window.addEventListener('resize', syncTableHeight);
        }
    });
