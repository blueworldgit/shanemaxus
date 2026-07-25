    (function(){
        var vinMap = window.mvpData["mvp-breadcrumb-replace-vin"][0];
        var bc = document.querySelector('.et-breadcrumbs');
        if (!bc) return;
        var links = bc.querySelectorAll('a');
        links.forEach(function(a) {
            var text = a.textContent.trim();
            if (vinMap[text]) {
                a.textContent = vinMap[text];
            }
        });
        // Also check text nodes (the last crumb might not be a link)
        bc.childNodes.forEach(function(node) {
            if (node.nodeType === 3) {
                var text = node.textContent.trim();
                if (vinMap[text]) {
                    node.textContent = node.textContent.replace(text, vinMap[text]);
                }
            }
        });
    })();
