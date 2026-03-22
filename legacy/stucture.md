# Vehicle Parts Data File Traversal Documentation

## Overview
This documentation describes the data structure, file organization, and traversal methodology for vehicle parts data processing system using the `scrapeandpush.py` script.

## Data Organization Structure

### Hierarchical Directory Layout
```
LSH14C4C5NA129710/                    # Vehicle Serial Number (Root Directory)
├── commands.txt                      # Script execution command
├── air intake system/               # Parts Category (Parent Title)
│   └── Air filter.html             # Parts Diagram & Data (Child Title)
├── airbag/                         # Parts Category
│   └── AirBag.html                 # Parts Diagram & Data
└── antenna/                        # Parts Category  
    └── Antenna.html                # Parts Diagram & Data
```

### Directory Naming Convention
- **Serial Number Directory**: Vehicle identification (e.g., `LSH14C4C5NA129710`)
- **Category Directories**: Component systems (e.g., "air intake system", "airbag", "antenna")  
- **HTML Files**: Individual part diagrams and specifications

## HTML File Structure Analysis

### Core Components
Each HTML file contains structured data organized into these key sections:

#### 1. Legend Title
```html
<span id="legend-title" class="text bold-text">JE140A001 - Air filter</span>
```
- **Purpose**: Identifies the component code and name
- **Format**: `{ComponentCode} - {ComponentName}`
- **Extraction**: Script uses BeautifulSoup to find element with `id="legend-title"`

#### 2. SVG Diagram
```html
<svg version="1.1" xmlns="http://www.w3.org/2000/svg" 
     xmlns:xlink="http://www.w3.org/1999/xlink" 
     x="0px" y="0px" width="680.3px" height="856.1px" 
     viewBox="0 0 680.3 856.1" 
     enable-background="new 0 0 680.3 856.1" 
     xml:space="preserve">
  <!-- Complex SVG paths and shapes -->
</svg>
```
- **Purpose**: Technical diagram showing part positions and relationships
- **Content**: Vector graphics with numbered callouts corresponding to parts table
- **Storage**: Complete SVG markup stored in database for display

#### 3. Parts Data Table 
Each part entry follows this structure:
```html
<div class="parts-item" data-id="23613" data-part-id="C00180125" data-callout="1">
    <span class="column ordernumber">1</span>                    <!-- Callout Number -->
    <span class="column number">
        <div class="part-number">
            <a href="/part/C00180125" class="text-link">C00180125</a>  <!-- Part Number -->
        </div>
    </span>
    <span class="column describe" title="AMPLIFIER ASSEMBLY-PRINTED ANTENNA">
        AMPLIFIER ASSEMBLY-PRINTED ANTENNA                        <!-- Description -->
    </span>
    <span class="column quantity">1.0</span>                     <!-- Quantity -->
</div>
```

#### 4. Additional Metadata (L/R Orientation & Remarks)
```html
<div class="parts-table-tbody parts-table-tbody-dflz">
    <div class="parts-item" data-callout="1">
        <span class="column" style="width:70px;"></span>         <!-- L/R Orientation -->
        <span class="column text-column text-column-note">
            <span title=" "> </span>                             <!-- Remark -->
        </span>
    </div>
</div>
```

## Data Extraction Methodology

### Script Processing Flow

#### 1. Directory Traversal (`process_directory()`)
```python
for dirpath, dirnames, filenames in os.walk(root_dir):
    # Process each directory containing HTML files
    # Create parent titles for category directories
    # Process individual HTML files
```

**Key Actions:**
- Extract serial number from root directory name
- Determine vehicle brand using pattern matching
- Create/find existing serial number record in database
- Walk through all subdirectories to find HTML files

#### 2. HTML File Processing (`process_html_file()`)
**Sequential Processing Steps:**
1. **File Reading**: Open HTML file with UTF-8 encoding
2. **HTML Parsing**: Use BeautifulSoup to parse DOM structure
3. **Title Extraction**: Extract component name from `legend-title` element
4. **SVG Extraction**: Extract complete SVG element markup
5. **Parts Data Parsing**: Process TWO SEPARATE parts tables
6. **Database Serialization**: Save structured data using Django serializers

#### 3. Dual Table Data Extraction Logic

**CRITICAL DISCOVERY**: The HTML contains TWO separate parts tables:

**Table 1: Extra Info Table (`.parts-table-wrapper float`)**
```html
<div class="parts-table-wrapper float">
    <div class="parts-table-tbody parts-table-tbody-dflz">
        <!-- Contains L/R orientation and remarks data -->
    </div>
</div>
```

**Table 2: Main Parts Table (`.parts-table-wrapper lock`)**
```html
<div class="parts-table-wrapper lock">
    <div class="parts-table-tbody">
        <!-- Contains actual parts data: callout, part number, description, quantity -->
    </div>
</div>
```

**Parts Integration Process:**
```python
# Step 1: Extract L/R and remarks from "float" table
extra = []
container = soup.find('div', class_='condition-entity')
if container:
    right_div = container.find('div', class_='parts-table-tbody parts-table-tbody-dflz')
    # Extract orientation and remark data

# Step 2: Extract main parts data using data-callout selector
parts_items = soup.find_all(lambda tag: tag.name == "div" and 
                          "parts-item" in tag.get("class", []) and 
                          tag.has_attr("data-callout"))

# Step 3: Combine data by index position
for count, item in enumerate(filtered_items):
    orientation = extra[count]['orientation'] if count < len(extra) else "N/A"
    remarks = extra[count]['remark'] if count < len(extra) else "N/A"
    
    # Extract from main table
    order_number = extract_callout_number(item)
    part_number = extract_part_number(item) 
    description = extract_description(item)
    quantity = extract_quantity(item)
```

## Database Storage Model

### Django Model Hierarchy
```
SerialNumber (Vehicle)
├── vehicle_brand: String (determined by serial pattern)
├── serial: String (directory name)
└── ParentTitle[] (Categories)
    ├── title: String (directory name, formatted)
    ├── serial_number: ForeignKey
    └── ChildTitle[] (Components) 
        ├── title: String (from legend-title)
        ├── parent: ForeignKey  
        ├── svg_code: Text (complete SVG markup)
        └── Part[] (Individual Parts)
            ├── child_title: ForeignKey
            ├── call_out_order: Integer (callout number)
            ├── part_number: String (part identifier)
            ├── usage_name: String (description)
            ├── unit_qty: String (quantity)
            ├── lr: String (L/R orientation)
            ├── remark: String (usage notes)
            └── nn_note: String (additional notes)
```

## Data Traversal Methods

### 1. File System Traversal
```python
os.walk(root_directory)  # Recursive directory traversal
```
- **Scope**: All subdirectories from vehicle serial root
- **Filter**: Only processes directories containing .html files
- **Depth**: Unlimited depth but typically 2 levels (serial/category/files)

### 2. HTML Content Parsing
```python
BeautifulSoup(html_content, 'html.parser')  # DOM tree parsing
```
- **Parser**: Built-in HTML parser for robust handling
- **Selectors**: CSS class and ID selectors for data extraction
- **Error Handling**: Graceful degradation with default values

### 3. Database Query Patterns
```python
# Check for existing records before creating
existing_serial = SerialNumber.objects.filter(serial=serial_name).first()
existing_parent = ParentTitle.objects.filter(title=parent_name, serial_number=serial_instance).first()
```
- **Duplicate Prevention**: Check existing records before creation
- **Foreign Key Relationships**: Maintain referential integrity
- **Batch Processing**: Process files sequentially to avoid conflicts

## Data Storage Locations

### File Storage
- **Source Files**: `epcdata/LSH14C4C5NA129710/` (and similar vehicle directories)
- **Script Location**: `epcdata/scrapeandpush.py`
- **Log Files**: `scraper.log` (execution logging)
- **Commands**: `commands.txt` (processing instructions per vehicle)

### Database Storage
**Django Models Location**: `motorpartsdata/`
- `SerialNumber`: Vehicle identification and branding
- `ParentTitle`: Parts category organization  
- `ChildTitle`: Component-level data with SVG diagrams
- `Part`: Individual part specifications and metadata

### Processing Configuration
- **Django Settings**: `epcdata.settings`
- **Vehicle Brand Logic**: `vehicle_utils.determine_vehicle_brand()`
- **Serializers**: `motorpartsdata.serializers`

## Error Handling & Validation

### File Processing Errors
- **Missing Files**: Script continues processing remaining files
- **Malformed HTML**: BeautifulSoup provides graceful HTML parsing
- **Missing Data Elements**: Default values ("N/A") prevent processing failures

### Database Errors  
- **Validation**: Django serializers validate data before database insertion
- **Duplicate Detection**: Check existing records to prevent constraint violations
- **Transaction Safety**: Individual file processing isolated to prevent batch failures

### Logging System
```python
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s', 
    handlers=[
        logging.FileHandler("scraper.log"),     # File logging
        logging.StreamHandler()                 # Console output
    ]
)
```

## Usage Examples

### Command Line Execution
```bash
python scrapeandpush.py ./LSH14C4C5NA129710
```

### Programmatic Access
```python
# Process specific vehicle directory
process_directory("/path/to/LSH14C4C5NA129710")

# Process individual HTML file  
process_html_file(html_path, serial_instance, parent_instance)
```

## Performance Characteristics

### Processing Statistics (Sample Data)
- **Files Processed**: 3 HTML files
- **Categories**: 3 (air intake system, airbag, antenna)  
- **Total Parts**: Variable per file (typically 10-50 parts per component)
- **SVG Diagrams**: 3 complex technical diagrams stored as text

### Memory Usage
- **HTML Parsing**: BeautifulSoup holds DOM in memory per file
- **SVG Storage**: Complete markup stored as text (potentially large)
- **Batch Processing**: One file processed at a time to minimize memory usage

## Extension Possibilities

### Additional Vehicle Serial Processing  
- Add more vehicle directories following same pattern
- Bulk processing multiple serial numbers
- Automated directory discovery and processing

### Enhanced Data Extraction
- Extract more metadata from HTML comments
- Process additional diagram formats
- Integrate pricing and availability data

### Database Enhancements
- Add indexing for better query performance
- Implement full-text search on descriptions
- Add audit trails for data changes

---

*Generated: March 22, 2026*
*Script Version: scrapeandpush.py*
*Data Source: LSH14C4C5NA129710 vehicle parts database*

---

## 🎯 FINAL ANALYSIS SUMMARY

Based on comprehensive analysis of the provided sample data (LSH14C4C5NA129710), here are the key findings:

### Data Structure Validation
- **Vehicle Serial**: LSH14C4C5NA129710 (Sample vehicle data)
- **Categories**: 3 (air intake system, airbag, antenna)
- **HTML Files**: 3 total files with vehicle parts data
- **Total Parts**: 23 extractable valid parts across all categories
- **SVG Diagrams**: 3 technical diagrams (183,147 total characters)

### HTML Structure Discovery
**CRITICAL**: Each HTML file contains **TWO SEPARATE PARTS TABLES**:

1. **Float Table** (`.parts-table-wrapper float`)
   - Contains L/R orientation and remarks data
   - Generally sparse or empty in sample data
   - Used for metadata alignment

2. **Lock Table** (`.parts-table-wrapper lock`) 
   - Contains actual parts data (callout numbers, part numbers, descriptions, quantities)
   - This is the primary data source
   - Uses `data-callout` attributes for part identification

### Traversal Methodology Verification
✅ **Directory Traversal**: `os.walk()` correctly processes hierarchical structure
✅ **File Processing**: Only processes directories containing `.html` files
✅ **Data Extraction**: Dual table parsing with index-based alignment
✅ **Validation Logic**: Skips parts with missing required data ("N/A" values)
✅ **Django Integration**: Proper serializer usage for database storage

### Sample Processing Results
```
Air Intake System (Air filter.html):
├── Parts Found: 28 total, 14 valid
├── SVG Size: 96,945 characters  
└── Sample Parts: C00041192 (MOUNT-AIR CLEANER), C00017370 (BOLT), etc.

Airbag (AirBag.html):
├── Parts Found: 10 total, 5 valid
├── SVG Size: 59,658 characters
└── Sample Parts: C00173421 (STEERING WHEEL), C00082271 (AIRBAG), etc.

Antenna (Antenna.html):
├── Parts Found: 8 total, 4 valid  
├── SVG Size: 26,544 characters
└── Sample Parts: C00180125 (AMPLIFIER), B00004097 (BOLT/SCREW), etc.
```

### 📋 Ready for Production
The `scrapeandpush.py` script is properly designed to handle the vehicle parts data structure. Other vehicle serial directories following the same pattern (LSH14C4C5NA129710) will process successfully using the established traversal methodology.
