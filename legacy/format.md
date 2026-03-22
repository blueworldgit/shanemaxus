# Vehicle Subcategory Page Documentation

**Page Example:** `http://localhost/jasonwpclone/maxus-t90-ev/brakes/brake-modulator/`

This document explains how the vehicle subcategory (diagram) pages are generated, including the parts table and interactive SVG highlighting.

---

## Architecture Overview

The vehicle subcategory page displays:
1. **Parts table** - List of parts for this diagram filtered by vehicle VIN
2. **SVG diagram** - Technical diagram with callout numbers
3. **Interactive highlighting** - Hover table rows to highlight callouts in SVG

---

## 1. Main Template File

**File:** `wp-content/themes/mobex-child/vehicle-subcategory.php`

This PHP template handles:
- Database queries to fetch vehicle-specific parts
- HTML generation for breadcrumbs, header, table, and SVG
- CSS styling for layout and responsive design
- JavaScript for SVG highlighting and zoom functionality

---

## 2. URL Routing System

### Template Loader Function

**Location:** `wp-content/themes/mobex-child/functions.php` (Lines 947-991)

```php
add_filter('template_include', 'maxus_vehicle_templates');
function maxus_vehicle_templates($template) {
    $vehicle_slug = get_query_var('maxus_vehicle');
    
    if (!$vehicle_slug) {
        return $template;
    }
    
    $vehicle = maxus_get_vehicle_by_slug($vehicle_slug);
    if (!$vehicle) {
        return $template;
    }
    
    // Store vehicle info globally for templates
    global $maxus_current_vehicle;
    $maxus_current_vehicle = $vehicle;
    $maxus_current_vehicle['slug'] = $vehicle_slug;
    
    $product_slug = get_query_var('maxus_product');
    $subcategory = get_query_var('maxus_subcategory');
    $category = get_query_var('maxus_category');
    
    // Subcategory page (diagram)
    if ($subcategory) {
        $custom = get_stylesheet_directory() . '/vehicle-subcategory.php';
        if (file_exists($custom)) return $custom;
    }
    // ... other template conditions
    
    return $template;
}
```

### URL Pattern

```
/{vehicle-slug}/{category-slug}/{subcategory-slug}/
```

**Example:**
```
/maxus-t90-ev/brakes/brake-modulator/
```

**Query Variables:**
- `maxus_vehicle` = "maxus-t90-ev"
- `maxus_category` = "brakes"
- `maxus_subcategory` = "brake-modulator"

---

## 3. Vehicle Data Lookup

### Vehicle VIN Mapping

**Location:** `wp-content/themes/mobex-child/functions.php` (Lines 119-208)

```php
function maxus_get_vehicle_vins() {
    return [
        'maxus-t90-ev' => [
            'vin' => 'LSFAM120XNA160733',
            'name' => 'MAXUS T90 EV',
            'year' => '2022-Present',
        ],
        'maxus-e-deliver-9' => [
            'vin' => 'LSH14J4CXMA165329',
            'name' => 'MAXUS E DELIVER 9',
            'year' => '2021-Present',
        ],
        // ... 16 more vehicles
    ];
}

function maxus_get_vehicle_by_slug($slug) {
    $vehicles = maxus_get_vehicle_vins();
    return isset($vehicles[$slug]) ? $vehicles[$slug] : null;
}
```

**Process:**
1. Extract vehicle slug from URL ("maxus-t90-ev")
2. Look up VIN in hardcoded array → `LSFAM120XNA160733`
3. Store vehicle data in global variable `$maxus_current_vehicle`

---

## 4. Parts Table Generation

### Database Tables Involved

**Key Tables:**
- `wp_posts` - Products and variations
- `wp_postmeta` - Product metadata (_sku, _price, attribute_pa_variant, callout_number)
- `wp_sku_vin_mapping` - Maps SKUs to VINs with variant attributes
- `wp_term_relationships` - Product-category relationships
- `wp_term_taxonomy` - Category taxonomy data

### Query Process

**Location:** `wp-content/themes/mobex-child/vehicle-subcategory.php` (Lines 56-96)

#### Step 1: Get Product IDs (Lines 56-69)

```php
$product_ids = $wpdb->get_col($wpdb->prepare("
    SELECT DISTINCT p.ID
    FROM {$wpdb->posts} p
    INNER JOIN {$wpdb->postmeta} pm_sku 
        ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
    LEFT JOIN {$wpdb->postmeta} pm_var 
        ON p.ID = pm_var.post_id AND pm_var.meta_key = 'attribute_pa_variant'
    INNER JOIN {$wpdb->prefix}sku_vin_mapping svm 
        ON pm_sku.meta_value = svm.sku
        AND (svm.variant_attribute IS NULL 
             OR svm.variant_attribute = '' 
             OR svm.variant_attribute = pm_var.meta_value)
    INNER JOIN {$wpdb->term_relationships} tr 
        ON p.ID = tr.object_id
    INNER JOIN {$wpdb->term_taxonomy} tt 
        ON tr.term_taxonomy_id = tt.term_taxonomy_id
    WHERE tt.term_id = %d
    AND tt.taxonomy = 'product_cat'
    AND p.post_type IN ('product', 'product_variation')
    AND svm.vin = %s
", $subcategory->term_id, $vin));
```

**What This Does:**
- Finds all products/variations in the subcategory (e.g., "brake-modulator")
- Matches products to vehicle VIN via `wp_sku_vin_mapping`
- **Critical:** Joins on BOTH SKU and variant_attribute to prevent cross-variant matches
- Returns array of product IDs

**Variant Matching Logic:**
```sql
AND (svm.variant_attribute IS NULL 
     OR svm.variant_attribute = '' 
     OR svm.variant_attribute = pm_var.meta_value)
```
- Product with variant="Left" only matches mapping rows with variant="Left" or NULL/empty
- Product with no variant only matches mapping rows with NULL/empty variant
- Prevents "Left" part from showing for "Right" vehicle fitment

#### Step 2: Get Product Details (Lines 72-96)

```php
$products = $wpdb->get_results($wpdb->prepare("
    SELECT
        p.ID,
        p.post_title,
        p.post_name,
        p.post_type,
        p.post_parent,
        pm_sku.meta_value as sku,
        pm_price.meta_value as price,
        COALESCE(pm_callout.meta_value, pm_callout_generic.meta_value) as callout,
        svm.variant_attribute
    FROM {$wpdb->posts} p
    INNER JOIN {$wpdb->postmeta} pm_sku 
        ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
    LEFT JOIN {$wpdb->postmeta} pm_var 
        ON p.ID = pm_var.post_id AND pm_var.meta_key = 'attribute_pa_variant'
    LEFT JOIN {$wpdb->prefix}sku_vin_mapping svm 
        ON pm_sku.meta_value = svm.sku AND svm.vin = %s
        AND (svm.variant_attribute IS NULL 
             OR svm.variant_attribute = '' 
             OR svm.variant_attribute = pm_var.meta_value)
    LEFT JOIN {$wpdb->postmeta} pm_price 
        ON p.ID = pm_price.post_id AND pm_price.meta_key = '_price'
    LEFT JOIN {$wpdb->postmeta} pm_callout 
        ON p.ID = pm_callout.post_id AND pm_callout.meta_key = %s
    LEFT JOIN {$wpdb->postmeta} pm_callout_generic 
        ON p.ID = pm_callout_generic.post_id AND pm_callout_generic.meta_key = 'callout_number'
    WHERE p.ID IN ({$ids_placeholder})
    ORDER BY CAST(COALESCE(pm_callout.meta_value, pm_callout_generic.meta_value, '999') AS UNSIGNED), p.post_title
", $vin, 'callout_cat_' . $tt_id));
```

**What This Does:**
- Fetches full details for the product IDs from Step 1
- Gets SKU, price, callout number, variant attribute
- **Callout Priority:** Category-specific callout (`callout_cat_{id}`) > generic callout
- Orders by callout number (numerically), then alphabetically by title

**Callout Meta Keys:**
- `callout_cat_{term_taxonomy_id}` - Specific callout for this diagram
- `callout_number` - Generic fallback callout
- Numeric ordering: "1", "2", "10", "22" (not "1", "10", "2", "22")

### HTML Table Generation

**Location:** `wp-content/themes/mobex-child/vehicle-subcategory.php` (Lines 146-199)

```php
<table class="related-parts-table">
    <thead>
        <tr>
            <th class="col-callout">#</th>
            <th class="col-name">Part Name</th>
            <th class="col-sku">SKU</th>
            <th class="col-price">Price</th>
            <th class="col-cart"></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($products as $product): ?>
            <?php
            // For variations, link to parent product with variation parameter
            if ($product->post_type === 'product_variation' && $product->post_parent) {
                $parent = get_post($product->post_parent);
                $product_url = home_url('/' . $vehicle['slug'] . '/product/' . $parent->post_name . '/?variation_id=' . $product->ID . '&cat_id=' . $subcategory->term_id);
            } else {
                $product_url = home_url('/' . $vehicle['slug'] . '/product/' . $product->post_name . '/?cat_id=' . $subcategory->term_id);
            }
            ?>
            <tr data-callout="<?php echo esc_attr($callout); ?>">
                <td class="col-callout">
                    <?php if ($callout): ?>
                        <span class="callout-number"><?php echo esc_html($callout); ?></span>
                    <?php endif; ?>
                </td>
                <td class="col-name">
                    <a href="<?php echo esc_url($product_url); ?>"><?php echo esc_html($product->post_title); ?></a>
                    <?php if ($variant_attr): ?>
                        <span class="variant-attribute"><?php echo esc_html($variant_attr); ?></span>
                    <?php endif; ?>
                </td>
                <td class="col-sku"><?php echo esc_html($product->sku); ?></td>
                <td class="col-price">
                    <?php if ($price && $price > 0): ?>
                        <?php echo wc_price($price); ?>
                    <?php else: ?>
                        <span class="price-na">-</span>
                    <?php endif; ?>
                </td>
                <td class="col-cart">
                    <?php if ($price && $price > 0): ?>
                        <?php
                        // For variations, add to cart with variation_id parameter
                        if ($product->post_type === 'product_variation' && $product->post_parent) {
                            $add_url = wc_get_cart_url() . '?add-to-cart=' . $product->post_parent . '&variation_id=' . $product->ID;
                        } else {
                            $add_url = wc_get_cart_url() . '?add-to-cart=' . $product->ID;
                        }
                        ?>
                        <a href="<?php echo esc_url($add_url); ?>" class="add-to-cart-btn">Add</a>
                    <?php else: ?>
                        <?php echo maxus_price_enquiry_button($product->sku, $product->post_title); ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
```

**Key Features:**
- `data-callout` attribute on `<tr>` - Used by JavaScript for highlighting
- Callout number displayed in orange circle
- Variant badge (e.g., "Left", "Right") shown next to product name
- Product links include vehicle slug and category ID for breadcrumb context
- Variations link to parent product with `variation_id` parameter
- Price enquiry button for products without prices

### Table Styling

**Location:** `wp-content/themes/mobex-child/vehicle-subcategory.php` (Lines 321-410)

**Key Classes:**
- `.related-parts-table` - Main table container
- `.related-parts-table-wrapper` - Scrollable container (max-height: 650px)
- `.col-callout` - Callout number column (50px fixed width)
- `.callout-number` - Orange circular badge (#F29F05 background)
- `.variant-attribute` - Blue pill badge for variant (e.g., "Left", "FWD")
- `.add-to-cart-btn` - Orange button for adding to cart

**Responsive Design:**
- Desktop: Table fixed at 550px width, side-by-side with SVG
- Tablet (< 1100px): Stacked layout, SVG on top
- Mobile (< 767px): Hide SKU column, smaller buttons

---

## 5. SVG Diagram Display

### SVG File Storage

**Location:** Category term meta

```php
$svg_path = get_term_meta($subcategory->term_id, 'svg_diagram', true);
// Example: "diagrams/brake-modulator.svg"

$svg_full_path = WP_CONTENT_DIR . '/uploads/' . $svg_path;
// Full path: C:\Users\...\wp-content\uploads\diagrams\brake-modulator.svg
```

**Storage Structure:**
- SVG files stored in: `wp-content/uploads/diagrams/*.svg`
- Path stored in term meta: `svg_diagram` meta key
- Term = Subcategory (diagram level, e.g., "Brake Modulator")

### SVG Rendering

**Location:** `wp-content/themes/mobex-child/vehicle-subcategory.php` (Lines 207-218)

```php
<?php if ($has_svg): ?>
    <div class="diagram-svg-column">
        <div class="diagram-svg-wrapper">
            <div class="diagram-svg-full" id="diagram-svg-container">
                <?php echo file_get_contents($svg_full_path); ?>
            </div>
            <div class="diagram-controls">
                <button type="button" class="zoom-btn" id="zoom-toggle">Toggle Zoom</button>
                <span class="zoom-hint">Click diagram to zoom, hover table rows to highlight</span>
            </div>
        </div>
    </div>
<?php endif; ?>
```

**How It Works:**
- SVG is inline embedded (not `<img>` tag) for JavaScript manipulation
- `file_get_contents()` reads SVG XML and outputs directly
- Inline SVG allows JavaScript to access and modify elements

### Zoom Functionality

**Location:** `wp-content/themes/mobex-child/vehicle-subcategory.php` (Lines 608-633)

```javascript
document.addEventListener('DOMContentLoaded', function() {
    var svgContainer = document.getElementById('diagram-svg-container');
    var zoomBtn = document.getElementById('zoom-toggle');
    
    if (svgContainer && zoomBtn) {
        // Toggle zoom on button click
        zoomBtn.addEventListener('click', function() {
            svgContainer.classList.toggle('zoomed');
        });
        
        // Toggle zoom on diagram click
        svgContainer.addEventListener('click', function() {
            svgContainer.classList.toggle('zoomed');
        });
    }
});
```

**CSS:**
```css
.diagram-svg-full.zoomed svg {
    transform: scale(2.5);
}
```

**Behavior:**
- Click diagram or button to toggle 2.5x zoom
- Cursor changes: crosshair → zoom-out
- Transform origin: center

---

## 6. Interactive SVG Highlighting

### Overview

When you hover over a table row, the corresponding callout number in the SVG diagram is highlighted along with its leader line.

**Location:** `wp-content/themes/mobex-child/vehicle-subcategory.php` (Lines 635-835)

### Helper Functions

#### 6.1 Get Text Position

```javascript
function getTextPosition(textEl) {
    var bbox = textEl.getBBox();
    var transform = textEl.getAttribute('transform');
    var x = bbox.x, y = bbox.y, width = bbox.width, height = bbox.height;
    
    if (transform) {
        // Parse matrix(a, b, c, d, e, f)
        var matrixMatch = transform.match(/matrix\s*\(\s*([\d.-]+)\s*[\s,]\s*([\d.-]+)\s*[\s,]\s*([\d.-]+)\s*[\s,]\s*([\d.-]+)\s*[\s,]\s*([\d.-]+)\s*[\s,]\s*([\d.-]+)\s*\)/);
        if (matrixMatch) {
            x = parseFloat(matrixMatch[5]) + bbox.x;  // e = translateX
            y = parseFloat(matrixMatch[6]) + bbox.y;  // f = translateY
        }
        
        // Parse translate(x, y)
        var translateMatch = transform.match(/translate\s*\(\s*([\d.-]+)\s*[\s,]\s*([\d.-]+)\s*\)/);
        if (translateMatch) {
            x = parseFloat(translateMatch[1]) + bbox.x;
            y = parseFloat(translateMatch[2]) + bbox.y;
        }
    }
    
    return { x: x, y: y, width: width, height: height };
}
```

**Purpose:** Get actual screen coordinates of SVG text elements

**Why Needed:**
- `getBBox()` returns local coordinates (before transforms)
- SVG text elements often have `transform="matrix(...)"` or `translate(...)`
- Need real coordinates to find connected leader lines

#### 6.2 Get Path/Line Endpoints

```javascript
function getPathEndpoints(pathEl) {
    var d = pathEl.getAttribute('d');
    if (!d) return null;
    
    // Get start point from M command
    var moveMatch = d.match(/M\s*([\d.-]+)[,\s]*([\d.-]+)/i);
    var startX = moveMatch ? parseFloat(moveMatch[1]) : null;
    var startY = moveMatch ? parseFloat(moveMatch[2]) : null;
    
    // Track end point through L, C commands
    var endX = startX, endY = startY;
    var commands = d.match(/[LlHhVvCcSsQqTtAa][^LlHhVvCcSsQqTtAaMmZz]*/g) || [];
    
    commands.forEach(function(cmd) {
        var type = cmd[0];
        var nums = cmd.slice(1).match(/-?[\d.]+/g);
        if (!nums) return;
        
        if (type === 'L') {  // Line to (absolute)
            endX = parseFloat(nums[nums.length - 2] || nums[0]);
            endY = parseFloat(nums[nums.length - 1] || nums[1]);
        }
        else if (type === 'l') {  // Line to (relative)
            endX += parseFloat(nums[nums.length - 2] || nums[0]);
            endY += parseFloat(nums[nums.length - 1] || nums[1]);
        }
        else if (type === 'C' && nums.length >= 6) {  // Cubic bezier (absolute)
            endX = parseFloat(nums[nums.length - 2]);
            endY = parseFloat(nums[nums.length - 1]);
        }
        else if (type === 'c' && nums.length >= 6) {  // Cubic bezier (relative)
            endX += parseFloat(nums[nums.length - 2]);
            endY += parseFloat(nums[nums.length - 1]);
        }
    });
    
    return { startX: startX, startY: startY, endX: endX, endY: endY };
}

function getLineEndpoints(lineEl) {
    return {
        startX: parseFloat(lineEl.getAttribute('x1')),
        startY: parseFloat(lineEl.getAttribute('y1')),
        endX: parseFloat(lineEl.getAttribute('x2')),
        endY: parseFloat(lineEl.getAttribute('y2'))
    };
}
```

**Purpose:** Extract start/end coordinates from SVG path data

**Supports:**
- `<line>` elements (simple x1, y1, x2, y2)
- `<path>` elements (parses d attribute commands)
- Handles absolute and relative coordinates
- Tracks final endpoint through multiple line commands

#### 6.3 Find Connected Lines

```javascript
function findConnectedLines(textBbox, searchRadius, textEl) {
    var connected = [];
    var textCx = textBbox.x + textBbox.width / 2;   // Center X
    var textCy = textBbox.y + textBbox.height / 2;  // Center Y
    var textLeft = textBbox.x;                       // Left edge
    
    // APPROACH 1: Structural - sibling <g> element
    if (textEl) {
        var parentG = textEl.parentElement;
        if (parentG && parentG.tagName.toLowerCase() === 'g') {
            var nextG = parentG.nextElementSibling;
            if (nextG && nextG.tagName.toLowerCase() === 'g') {
                var siblingLines = nextG.querySelectorAll('line, path, polyline');
                siblingLines.forEach(function(el) {
                    // Skip white lines (diagram borders)
                    var stroke = el.getAttribute('stroke');
                    if (stroke) {
                        var s = stroke.toUpperCase();
                        if (s === '#FFFFFF' || s === 'WHITE' || s === '#FFF') return;
                    }
                    
                    var endpoints;
                    if (el.tagName.toLowerCase() === 'line') 
                        endpoints = getLineEndpoints(el);
                    else 
                        endpoints = getPathEndpoints(el);
                    
                    if (endpoints && endpoints.startX !== null) {
                        // Validate the line is actually near this text
                        var dStart = getDistance(textCx, textCy, endpoints.startX, endpoints.startY);
                        var dEnd = getDistance(textCx, textCy, endpoints.endX, endpoints.endY);
                        
                        if (dStart < 30 || dEnd < 30) {
                            connected.push({
                                element: el,
                                endpoints: endpoints,
                                connectedAtStart: dStart < dEnd,
                                distance: Math.min(dStart, dEnd)
                            });
                        }
                    }
                });
            }
        }
    }
    if (connected.length > 0) return connected;
    
    // APPROACH 2: Proximity-based fallback
    allPaths.forEach(function(el) {
        var endpoints;
        if (el.tagName === 'line') 
            endpoints = getLineEndpoints(el);
        else if (el.tagName === 'path' || el.tagName === 'polyline') 
            endpoints = getPathEndpoints(el);
        
        if (!endpoints || endpoints.startX === null) return;
        
        // Skip tiny lines (noise)
        var lineLength = getDistance(endpoints.startX, endpoints.startY, endpoints.endX, endpoints.endY);
        if (lineLength < 3) return;
        
        // Check proximity to text center
        var distToStart = getDistance(textCx, textCy, endpoints.startX, endpoints.startY);
        var distToEnd = getDistance(textCx, textCy, endpoints.endX, endpoints.endY);
        
        if (distToStart < 15 || distToEnd < 15) {
            connected.push({
                element: el,
                endpoints: endpoints,
                connectedAtStart: distToStart < distToEnd,
                distance: Math.min(distToStart, distToEnd)
            });
            return;
        }
        
        // Check for legend-style horizontal lines (Y-aligned)
        var yTolerance = 8;
        var startNearTextY = Math.abs(endpoints.startY - textCy) < yTolerance;
        var endNearTextY = Math.abs(endpoints.endY - textCy) < yTolerance;
        var startNearTextArea = Math.abs(endpoints.startX - textLeft) < 20;
        var endNearTextArea = Math.abs(endpoints.endX - textLeft) < 20;
        
        if (startNearTextY && startNearTextArea) {
            connected.push({
                element: el,
                endpoints: endpoints,
                connectedAtStart: true,
                distance: Math.abs(endpoints.startY - textCy)
            });
        } else if (endNearTextY && endNearTextArea) {
            connected.push({
                element: el,
                endpoints: endpoints,
                connectedAtStart: false,
                distance: Math.abs(endpoints.endY - textCy)
            });
        }
    });
    
    // Keep only closest line if multiple found
    if (connected.length > 1) {
        connected.sort(function(a, b) { return a.distance - b.distance; });
        connected = connected.slice(0, 1);
    }
    
    return connected;
}
```

**Two-Phase Strategy:**

**Phase 1: Structural Approach**
- Looks for SVG structure: `<g><text>...</text></g><g><path>...</path></g>`
- Callout text often in one `<g>`, leader line in sibling `<g>`
- Validates line is actually near the text (< 30px)
- Skips white lines (diagram borders)

**Phase 2: Proximity Fallback**
- If structural approach fails, search all paths/lines
- **Direct proximity:** Line endpoint within 15px of text center
- **Legend-style:** Line horizontally aligned (Y within 8px) and left of text
- Skips tiny lines (< 3px) to avoid noise
- Returns only the closest match if multiple found

**Result:**
- Array of `{ element, endpoints, connectedAtStart, distance }` objects
- Typically 1 leader line per callout number

#### 6.4 Highlight Callout

```javascript
function highlightCallout(calloutNum) {
    var color = '#F29F05';  // Orange
    var processedGroups = [];
    
    textElements.forEach(function(textEl) {
        // Find text matching callout number
        if (textEl.textContent.trim() !== calloutNum) return;
        
        // Avoid duplicate processing if same <g> parent
        var parentG = textEl.parentElement;
        if (parentG && processedGroups.indexOf(parentG) !== -1) return;
        if (parentG) processedGroups.push(parentG);
        
        var bbox;
        try { bbox = getTextPosition(textEl); } catch(e) { return; }
        
        // Highlight text
        textEl.style.fill = color;
        textEl.style.fontWeight = 'bold';
        highlightedTexts.push(textEl);
        
        // Find and highlight leader lines
        var connectedLines = findConnectedLines(bbox, 10, textEl);
        connectedLines.forEach(function(lineInfo) {
            var el = lineInfo.element;
            
            // Store original stroke for restoration
            if (el._originalStroke === undefined) {
                el._originalStroke = el.getAttribute('stroke') || '';
                el._originalStrokeWidth = el.getAttribute('stroke-width') || '';
            }
            
            // Apply highlight
            el.style.stroke = color;
            el.style.strokeWidth = '4px';
            el.setAttribute('stroke', color);
            el.setAttribute('stroke-width', '4');
            highlightedLines.push(el);
        });
    });
}
```

**Process:**
1. Find all `<text>` elements with matching content (e.g., "22")
2. Avoid duplicate processing (same `<g>` parent)
3. Change text color to orange, bold weight
4. Find connected leader lines
5. Store original stroke properties
6. Change line stroke to orange, 4px width
7. Track highlighted elements for later cleanup

#### 6.5 Clear Highlights

```javascript
function clearHighlights() {
    highlightedTexts.forEach(function(t) {
        t.style.fill = '';
        t.style.fontWeight = '';
    });
    highlightedTexts = [];
    
    highlightedLines.forEach(function(el) {
        el.style.stroke = '';
        el.style.strokeWidth = '';
        if (el._originalStroke !== undefined) {
            el.setAttribute('stroke', el._originalStroke);
            el.setAttribute('stroke-width', el._originalStrokeWidth || '');
        }
    });
    highlightedLines = [];
}
```

**Process:**
1. Reset all text elements (clear inline styles)
2. Reset all line elements (restore original stroke)
3. Clear tracking arrays

### Event Handlers

```javascript
// Highlight callouts on table row hover
var tableRows = document.querySelectorAll('.related-parts-table tbody tr');
tableRows.forEach(function(row) {
    row.addEventListener('mouseenter', function() {
        var callout = this.getAttribute('data-callout');
        if (callout) {
            clearHighlights();
            highlightCallout(callout);
            this.classList.add('highlight-row');
        }
    });
    
    row.addEventListener('mouseleave', function() {
        clearHighlights();
        this.classList.remove('highlight-row');
    });
});
```

**Behavior:**
1. User hovers over table row
2. Read `data-callout` attribute (e.g., "22")
3. Clear any previous highlights
4. Highlight matching callout(s) in SVG
5. Add background color to table row
6. On mouse leave: clear all highlights

**CSS Classes:**
```css
.highlight-row {
    background: #ffe0cc !important;  /* Light orange */
}

.svg-highlight-line {
    stroke: #F29F05 !important;
    stroke-width: 4px !important;
    stroke-opacity: 1 !important;
}

.svg-highlight-text {
    fill: #F29F05 !important;
    font-weight: bold !important;
}
```

---

## 7. Supporting Functions

### 7.1 Price Enquiry Button

**Location:** `wp-content/themes/mobex-child/price-enquiry-form.php` (Line 19)

```php
function maxus_price_enquiry_button($sku, $name) {
    return '<button type="button" class="price-enquiry-btn" 
                    data-sku="' . esc_attr($sku) . '" 
                    data-name="' . esc_attr($name) . '">Request a Price</button>';
}
```

**Purpose:** Display "Request a Price" button for products without prices

**Features:**
- Opens modal popup (injected in footer)
- Passes SKU and product name via data attributes
- AJAX form submission to `accounts@vanparts-direct.co.uk`
- Used in table when `$price` is NULL or 0

### 7.2 Category Term Meta

**SVG Diagram Storage:**
```php
// Save SVG path to category
update_term_meta($subcategory_id, 'svg_diagram', 'diagrams/brake-modulator.svg');

// Retrieve SVG path
$svg_path = get_term_meta($subcategory_id, 'svg_diagram', true);
```

**Meta Keys:**
- `svg_diagram` - Relative path to SVG file (from uploads directory)

---

## 8. Data Flow Summary

```
User visits URL
    ↓
/maxus-t90-ev/brakes/brake-modulator/
    ↓
WordPress parses query vars
    ↓
maxus_vehicle_templates() hook
    ↓
Loads vehicle-subcategory.php
    ↓
Look up vehicle VIN: LSFAM120XNA160733
    ↓
Query 1: Get product IDs
  - Match subcategory term_id
  - Match VIN in wp_sku_vin_mapping
  - Match variant_attribute
    ↓
Query 2: Get product details
  - SKU, price, callout, variant
  - Order by callout number
    ↓
Generate HTML table
  - Callout numbers
  - Product names with variant badges
  - Prices or "Request a Price" button
  - data-callout attributes
    ↓
Load SVG diagram
  - Read file from wp-content/uploads/diagrams/
  - Inline embed in HTML
    ↓
JavaScript initializes
  - Parse SVG elements
  - Add hover listeners to table rows
    ↓
User hovers table row
    ↓
Read data-callout attribute
    ↓
Find matching text in SVG
    ↓
Find connected leader lines
    ↓
Highlight text + lines orange
    ↓
User moves mouse away
    ↓
Clear all highlights
```

---

## 9. Key Technical Decisions

### Why Inline SVG?

**Chosen:** `<?php echo file_get_contents($svg_path); ?>`

**Alternatives Rejected:**
- `<img src="...">` - Can't manipulate with JavaScript
- `<object data="...">` - Requires async loading, separate DOM

**Benefits:**
- Direct JavaScript access to SVG elements
- No CORS issues
- Synchronous rendering
- Can modify styles and attributes

### Why Variant Join?

**Problem:** SKU alone isn't unique - same SKU can have Left/Right variants

**Solution:** Join on BOTH SKU and variant_attribute
```sql
ON pm_sku.meta_value = svm.sku
AND (svm.variant_attribute IS NULL 
     OR svm.variant_attribute = '' 
     OR svm.variant_attribute = pm_var.meta_value)
```

**Example:**
- SKU B00004683 has "Left" and "m6*20" variants
- Product with variant="Left" only matches "Left" mapping row
- Product with no variant only matches NULL/empty mapping rows
- Prevents cross-variant contamination

### Why Two-Phase Line Detection?

**Phase 1 (Structural):** Fast, reliable when SVG has consistent structure

**Phase 2 (Proximity):** Fallback for varied SVG structures

**Why Not Just Proximity?**
- Diagrams can have overlapping elements
- Structural parent-child relationships are more reliable
- Proximity alone could match wrong lines in dense areas

### Why Category-Specific Callouts?

**Meta Keys:**
- `callout_cat_{term_taxonomy_id}` - Specific to diagram
- `callout_number` - Generic fallback

**Reason:** Same part can appear in multiple diagrams with different callout numbers

**Example:**
- Brake pad B00003501
  - In "Front Brakes" diagram: Callout #5
  - In "Complete Brake Assembly" diagram: Callout #22

---

## 10. Performance Considerations

### Database Optimization

**Two-query approach:**
1. Get IDs only (lightweight)
2. Get full details for those IDs

**Why not single query?**
- Cleaner separation of concerns
- IDs used for IN clause placeholder generation
- Easier debugging and maintenance

**Indexes needed:**
```sql
-- wp_sku_vin_mapping
INDEX(sku, vin, variant_attribute)

-- wp_postmeta
INDEX(post_id, meta_key, meta_value)

-- wp_term_relationships
INDEX(object_id, term_taxonomy_id)
```

### JavaScript Performance

**Element caching:**
```javascript
var textElements = svg.querySelectorAll('text');  // Once on load
var allPaths = svg.querySelectorAll('path, line, polyline');  // Once on load
```

**Efficient highlighting:**
- Store references to highlighted elements (no re-querying)
- Early returns in loops
- Process only visible elements

---

## 11. Files Reference

**Template Files:**
- `wp-content/themes/mobex-child/vehicle-subcategory.php` - Main template
- `wp-content/themes/mobex-child/functions.php` - Routing, vehicle data
- `wp-content/themes/mobex-child/price-enquiry-form.php` - Price modal

**Database Tables:**
- `wp_posts` - Products and variations
- `wp_postmeta` - Product metadata
- `wp_sku_vin_mapping` - SKU-to-VIN mapping with variants
- `wp_term_relationships` - Product-category relationships
- `wp_term_taxonomy` - Category taxonomy
- `wp_termmeta` - SVG diagram paths

**Assets:**
- `wp-content/uploads/diagrams/*.svg` - SVG diagram files

---

## 12. Example Data

**Vehicle:**
```
URL Slug: maxus-t90-ev
VIN: LSFAM120XNA160733
Name: MAXUS T90 EV
Year: 2022-Present
```

**Category Structure:**
```
brakes (category)
  └─ brake-modulator (subcategory/diagram)
```

**Product Example:**
```
ID: 12345
Title: "Brake Actuator Assembly"
SKU: B00004683
Variant: "Left"
Callout: 22
Price: £125.00
```

**Mapping Example:**
```
SKU: B00004683
VIN: LSFAM120XNA160733
Variant: "Left"
```

**SVG Meta:**
```
Term ID: 224419 (brake-modulator)
Meta Key: svg_diagram
Meta Value: diagrams/brake-modulator.svg
```

---

## Revision History

- **March 22, 2026** - Initial documentation
- Created by: Claude (GitHub Copilot)
- Purpose: Document vehicle subcategory page generation system
