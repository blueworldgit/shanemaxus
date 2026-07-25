    document.addEventListener('DOMContentLoaded', function() {
        var summary = document.querySelector('.summary, .entry-summary');
        if (!summary) return;
        var walker = document.createTreeWalker(summary, NodeFilter.SHOW_TEXT, null, false);
        var node;
        while (node = walker.nextNode()) {
            if (node.textContent && node.textContent.match(/Callout:\s*\d+|Qty:\s*[\d.]/i)) {
                var parent = node.parentElement;
                if (parent) parent.style.display = 'none';
            }
        }
    });
    
