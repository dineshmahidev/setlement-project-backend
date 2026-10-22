<?php

$vendorConfig = require base_path('vendor/barryvdh/laravel-dompdf/config/dompdf.php');

// DejaVu Sans supports ₹ (U+20B9) and other unicode glyphs;
// core fonts (Helvetica/Times/Courier) render them as '?'
$vendorConfig['options']['default_font'] = 'DejaVu Sans';

return $vendorConfig;
