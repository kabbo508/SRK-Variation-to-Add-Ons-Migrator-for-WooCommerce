# SRK Variation to Add-Ons Migrator for WooCommerce

Automatically convert WooCommerce variable products into simple products and migrate variation attributes into Product Add-Ons using image-based selectable options.

This plugin helps simplify complex variation structures while preserving pricing logic and visual option selection.

Designed for stores with configurable products that need a cleaner UI and more flexible option display.

---

## Features

☑️ Convert variable products into simple products automatically  
☑️ Migrate variation attributes into Product Add-Ons  
☑️ Supports image-based option selection  
☑️ Automatically assigns variation image to add-on option  
☑️ Uses variation name as option label  
☑️ Uses variation price difference as add-on price  
☑️ Sets base product price from lowest variation price  
☑️ Removes default WooCommerce variation system  
☑️ Deletes child variations after conversion  
☑️ Skips products already using Product Add-Ons  
☑️ Search and select a specific product before running  
☑️ Batch processing for large product catalogs  
☑️ Live log showing migration progress  
☑️ Displays list of products that could not be converted  
☑️ Backup of original product data stored automatically  

---

## Plugin Overview

WooCommerce default variations can become difficult to manage when products contain many options or visual selections.

This plugin converts variation structures into Product Add-Ons image selections, creating a cleaner and more user-friendly product page.

It preserves pricing structure by calculating the price difference between each variation and the lowest variation price.

---

## Admin Interface

The plugin provides a structured admin interface with live progress tracking.

### Controls

☑️ Product search field  
☑️ Run migration button  
☑️ Reset run option  
☑️ Batch size control  

### Live Monitoring

☑️ Real-time migration log  
☑️ Converted product count  
☑️ Skipped product count  
☑️ Failed product count  
☑️ List of products that could not be changed  

---

## How Migration Works

1. Select a product or run migration for all variable products
2. Plugin reads variation attributes and variation data
3. Retrieves:
   - variation title
   - variation price
   - variation image
4. Calculates lowest variation price
5. Creates Product Add-Ons options with image selection
6. Converts product type to simple product
7. Removes variation attributes
8. Deletes child variation products
9. Saves backup of original product data
10. Logs results

---

## Add-On Structure Created

Each variation attribute becomes:

Multiple Choice Field  
Display Type: Images  

Each option includes:

☑️ variation label  
☑️ variation image  
☑️ price difference  

---

## Requirements

WordPress 5.8 or higher  
WooCommerce 6.0 or higher  
WooCommerce Product Add-Ons plugin  
PHP 7.4 or higher  

---

## Installation

1. Upload plugin zip file
2. Activate plugin
3. Go to:

WooCommerce → Variation to Add-Ons

4. Select product or leave empty for all products
5. Set batch size
6. Click Run

---

## Safety Features

The plugin stores backup data before conversion:

Original product attributes  
Original variation structure  
Original product type  
Original price values  
Original child variation IDs  

Backups are saved in post meta fields for recovery if needed.

---

## Use Cases

☑️ Furniture products with selectable styles  
☑️ Salon equipment configurable products  
☑️ Products with many visual options  
☑️ Stores wanting simplified product structure  
☑️ Improving product page UX  
☑️ Replacing dropdown variation selectors  

---

## Compatibility

Tested with:

WooCommerce Product Add-Ons  
Elementor product pages  
AJAX-enabled themes  
Large product catalogs  

---

## Author

Sumon Rahman Kabbo  
https://sumonrahmankabbo.com/

---

## License

GPL v2 or later
