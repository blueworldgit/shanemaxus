    (function(){
        if (window.innerWidth > 1024) return;
        if (!document.body.classList.contains('home')) return;
        
        // Wait for DOM
        function injectMobileFilter() {
            if (document.querySelector('.mvp-mobile-filter')) return;
            
            // Find insertion point — after hero area or vehicle carousel
            var hero = document.getElementById('mvp-facelift-hero-area');
            var insertAfter = hero || document.querySelector('[data-id="60c0b2d"]');
            if (!insertAfter) return;
            
            var div = document.createElement('div');
            div.className = 'mvp-mobile-filter';
            div.innerHTML = 
                '<input type="text" class="mvp-mf-vin" placeholder="SEARCH BY VIN NUMBER" maxlength="17">' +
                '<div class="mvp-mf-or">OR</div>' +
                '<input type="text" class="mvp-mf-reg" placeholder="SEARCH BY REGISTRATION" maxlength="10">' +
                '<button type="button" class="mvp-mf-submit">Search</button>';
            
            insertAfter.parentNode.insertBefore(div, insertAfter.nextSibling);
            
            // Handle search
            div.querySelector('.mvp-mf-submit').addEventListener('click', function() {
                var vin = div.querySelector('.mvp-mf-vin').value.trim();
                var reg = div.querySelector('.mvp-mf-reg').value.trim();
                var home = window.location.origin;
                if (vin.length > 0) {
                    window.location.href = home + '/vin-search-test/?vin=' + encodeURIComponent(vin);
                } else if (reg.length > 0) {
                    window.location.href = home + '/registration-lookup/?reg=' + encodeURIComponent(reg);
                }
            });
            
            // Enter key support
            div.querySelectorAll('input[type="text"]').forEach(function(inp) {
                inp.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') { e.preventDefault(); div.querySelector('.mvp-mf-submit').click(); }
                });
            });
        }
        
        setTimeout(injectMobileFilter, 300);
        setTimeout(injectMobileFilter, 1000);
    })();
    
