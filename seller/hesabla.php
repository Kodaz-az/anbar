               <?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirectToLogin();
}

// Check if session has expired
if (isSessionExpired()) {
    session_unset();
    session_destroy();
    redirectToLogin();
}

// Check if user has seller or admin role
if (!hasRole(['seller', 'admin'])) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Add at the top of hesabla.php after the includes
if (!function_exists('generateBarcodeValue')) {
    function generateBarcodeValue($orderNumber) {
        $cleanOrderNumber = preg_replace('/[^A-Za-z0-9]/', '', $orderNumber);
        $dateCode = date('Ymd');
        return $dateCode . $cleanOrderNumber;
    }
}



// Get seller's information and branch
$sellerId = $_SESSION['user_id'];
$sellerName = $_SESSION['fullname'];
$branchId = $_SESSION['branch_id'];

// Get branch information
$branch = getBranchById($branchId);
$branchName = $branch ? $branch['name'] : '';

// Generate unique order number for this session if not exists
if (!isset($_SESSION['current_order_number'])) {
    $_SESSION['current_order_number'] = generateOrderNumber();
}
$orderNumber = $_SESSION['current_order_number'];

// Initialize barcode value
$barcodeValue = generateBarcodeValue($orderNumber);

// Process form submission (will be implemented in save-order.php)
?>
<!DOCTYPE html>
<html lang="az">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>AlumPro - Profil Hesablama Sistemi</title>

  <link href="https://fonts.googleapis.com/css2?family=Ethnocentric&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>

  <style>
    :root{
      --a4-width: 210mm;
      --page-margin: 8mm;
      --main-font: Arial, sans-serif;
      --table-header-bg: #e8ecef;
      --note-bg: #f2f3f6;
      --modal-z: 2000;
      --logo-height: 60px;
      --barcode-height: 60px;
      --barcode-width: 180px;
      --footer-height: 60px;
    }

    html, body { margin: 0; padding: 0; background: #f4f4f4; font-family: var(--main-font); color: #222; font-size: 12px; }
    .container { 
      width: calc(var(--a4-width) - (var(--page-margin) * 2)); 
      margin: 12px auto; 
      background: #fff; 
      padding: 10px; 
      box-sizing: border-box; 
      min-height: calc(297mm - 24px);
      position: relative;
      padding-bottom: calc(var(--footer-height) + 20px);
    }

    /* Header with navbar */
    .app-header {
      background: linear-gradient(135deg, #1eb15a 0%, #1e5eb1 100%);
      color: white;
      padding: 10px 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .app-header .logo {
      font-family: 'Ethnocentric', sans-serif;
      font-size: 24px;
    }
    .app-header .nav-links {
      display: flex;
      gap: 20px;
    }
    .app-header .nav-links a {
      color: white;
      text-decoration: none;
      padding: 5px 10px;
      border-radius: 4px;
      transition: background 0.3s;
    }
    .app-header .nav-links a:hover {
      background: rgba(255,255,255,0.1);
    }
    .app-header .user-info {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .app-header .user-info span {
      font-size: 14px;
    }
    .app-header .user-info .logout {
      padding: 5px 10px;
      background: rgba(255,255,255,0.2);
      border-radius: 4px;
      color: white;
      text-decoration: none;
      transition: background 0.3s;
    }
    .app-header .user-info .logout:hover {
      background: rgba(255,255,255,0.3);
    }

    /* Header layout with fixed positioning - keeps elements from moving during print */
    .header { 
      display: flex; 
      justify-content: space-between; 
      align-items: flex-start;
      gap: 12px; 
      border-bottom: 1px solid #ddd; 
      padding-bottom: 8px;
      position: relative;
    }
    .left { display: flex; align-items: center; gap: 12px; }
    .logo img { 
      height: var(--logo-height); 
      width: auto; 
      object-fit: contain; 
      display: block; 
    }
    .site-name { font-family: 'Ethnocentric', sans-serif; font-size: 18px; color: #1f3b4d; line-height: 1; }
    
    /* Meta section with order info and barcode - structured to prevent print layout shifts */
    .meta { 
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      position: relative;
    }
    .order-info {
      text-align: right;
      font-size: 12px;
      margin-bottom: 8px;
    }
    .barcode-container {
      width: var(--barcode-width);
      height: var(--barcode-height);
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
    }
    #barcode {
      height: 100%;
      width: 100%;
    }

    /* Key info bold and underlined for print/PDF */
    .print-bold {
      font-weight: 600;
      text-decoration: underline;
    }

    /* Top row */
    .top-row { display: flex; gap: 8px; align-items: center; margin-top: 8px; margin-bottom: 6px; flex-wrap: wrap; }
    .top-field { display: flex; align-items: center; min-width: 0; }
    .label { font-weight: 700; font-size: 12px; margin-right: 6px; white-space: nowrap; }
    .value input, .value select { padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-size: 12px; max-width: 100%; }
    .value span { padding: 6px 8px; border: 1px solid #e7e7e7; border-radius: 4px; background-color: #f8f8f8; display: inline-block; }
    #branch-number { width: 160px; max-width: 45vw; }
    #seller-name { min-width: 100px; max-width: 34vw; }
    #customer-name { min-width: 140px; max-width: 60vw; width: 260px; box-sizing: content-box; }

    .initial-note-row { display: flex; align-items: center; justify-content: center; margin-top: 8px; gap: 8px; }
    .initial-note-row input[type="text"] { width: 60%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; text-align: center; font-weight: 600; min-width: 140px; }

    /* sections */
    .section-title { text-align: center; font-weight: 700; margin: 12px 0 6px 0; color: #1f3b4d; background: var(--table-header-bg); padding: 6px 0; }
    table { width: 100%; border-collapse: collapse; font-size: 12px; table-layout: fixed; }
    th, td { border: 1px solid #ddd; padding: 6px; text-align: center; vertical-align: middle; overflow: hidden; word-break: break-word; }
    th { background: var(--table-header-bg); font-weight: 700; }

    /* Column alignments */
    #glass-table th:nth-child(2), #profile-table th:nth-child(2) { width: 12%; }
    #glass-table th:nth-child(3), #profile-table th:nth-child(3) { width: 12%; }

    /* Notes section with drawing functionality */
    .notes { 
      border: 1px dashed #d0d0d0; 
      padding: 6px; 
      background: var(--note-bg); 
      margin: 6px 0; 
      min-height: 30px; 
      font-size: 12px; 
      position: relative; 
    }
    #seller-notes { 
      width: 100%; 
      min-height: 60px; 
      border: none; 
      background: transparent; 
      resize: vertical; 
    }
    
    /* Drawing area */
    .drawing-container {
      position: relative;
      margin-top: 10px;
      border: 1px solid #ddd;
      display: none;
    }
    .drawing-canvas {
      background: white;
      cursor: crosshair;
      width: 100%;
      height: 300px;
    }
    .drawing-toolbar {
      background: #f8f9fa;
      border-bottom: 1px solid #ddd;
      padding: 5px;
      display: flex;
      flex-wrap: wrap;
      gap: 5px;
    }
    .drawing-tool {
      border: 1px solid #ccc;
      background: white;
      border-radius: 4px;
      padding: 5px 10px;
      cursor: pointer;
      font-size: 12px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 34px;
      height: 30px;
    }
    .drawing-tool:hover {
      background: #e9ecef;
    }
    .drawing-tool.active {
      background: #007bff;
      color: white;
      border-color: #0069d9;
    }
    .color-picker {
      height: 30px;
      width: 34px;
      padding: 0;
      border: 1px solid #ccc;
      border-radius: 4px;
      cursor: pointer;
    }
    .size-slider {
      width: 80px;
      height: 30px;
    }
    #text-input {
      display: none;
      position: absolute;
      z-index: 1000;
      background: white;
      border: 1px solid #ddd;
      padding: 5px;
    }

    .controls, .input-section, .file-controls { margin-top: 8px; }
    .small-btn { padding: 6px 10px; font-size: 13px; margin-right: 6px; }

    /* Footer with signatures - fixed positioning for consistent print */
    .sign-row-wrapper {
      position: absolute;
      bottom: 10px;
      left: 10px;
      right: 10px;
      height: var(--footer-height);
    }
    .sign-row { 
      display: flex; 
      justify-content: space-between; 
      width: 100%;
      gap: 12px;
    }
    .sign-cell { flex: 1; min-width: 80px; font-size: 11px; }
    .sign-label { font-weight: 600; margin-bottom: 4px; font-size: 11px; }
    .sign-line { border-bottom: 1px solid #333; height: 14px; display: block; width: 95%; }

    /* Modal styling */
    .modal-backdrop { 
      position: fixed; 
      inset: 0; 
      background: rgba(0,0,0,0.45); 
      z-index: var(--modal-z); 
      display: none; 
      align-items: center; 
      justify-content: center; 
    }
    .modal { 
      width: 920px; 
      max-width: 95%; 
      background: #fff; 
      border-radius: 6px; 
      padding: 16px; 
      box-shadow: 0 8px 30px rgba(0,0,0,0.25); 
      z-index: calc(var(--modal-z)+1); 
      max-height: 86vh; 
      overflow: auto; 
      box-sizing: border-box; 
    }
    
    /* Modal sections */
    .modal-section {
      margin-bottom: 16px;
      padding-bottom: 12px;
      border-bottom: 1px solid #eee;
    }
    .modal-section:last-child {
      border-bottom: none;
    }
    .modal-section h4 {
      margin-top: 0;
      margin-bottom: 8px;
      font-size: 14px;
      color: #1f3b4d;
    }

    /* Combined parameters and materials */
    .modal-order {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 8px;
      background: #f8f9fa;
      padding: 10px;
      border-radius: 4px;
      margin-bottom: 12px;
    }
    .modal-order .field {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .modal-order .field label {
      font-weight: 600;
      min-width: 80px;
    }
    .modal-order .field .value {
      flex: 1;
    }
    .modal-order .field input, 
    .modal-order .field select {
      width: 100%;
      padding: 4px 8px;
      border: 1px solid #ddd;
      border-radius: 3px;
    }

    /* Combined parameters grid */
    .modal-params {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 10px;
      margin-bottom: 12px;
    }
    .modal-params .param {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .modal-params .param label {
      font-weight: 600;
      font-size: 12px;
    }
    .modal-params .param input {
      padding: 4px 8px;
      border: 1px solid #ddd;
      border-radius: 3px;
      width: 100%;
    }

    /* Additional fields for order saving */
    .modal-section .additional-fields {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 15px;
      margin-top: 15px;
      padding-top: 15px;
      border-top: 1px dashed #ddd;
    }
    .modal-section .additional-fields .param {
      display: flex;
      flex-direction: column;
      gap: 5px;
    }
    .modal-section .additional-fields label {
      font-weight: 600;
      font-size: 13px;
      color: #1f3b4d;
    }
    .modal-section .additional-fields input {
      padding: 8px;
      border: 1px solid #ddd;
      border-radius: 4px;
      font-size: 14px;
    }

    /* Tables in modal */
    .calc-table { 
      width: 100%;
      border-collapse: collapse;
      margin-top: 8px;
      margin-bottom: 12px;
    }
    .calc-table th, .calc-table td { 
      border: 1px solid #ddd; 
      padding: 6px; 
      text-align: left; 
    }
    .calc-table th {
      background: #f3f4f6;
    }

    .modal-footer { 
      display: flex; 
      justify-content: flex-end; 
      gap: 8px; 
      margin-top: 16px;
      border-top: 1px solid #eee;
      padding-top: 16px;
    }

    /* Input sizes */
    .small-number { width: 72px; padding: 4px; }
    .calc-input { width: 100px; padding: 4px; }

    /* Customer autocomplete dropdown */
    .autocomplete-items {
      position: absolute;
      border: 1px solid #ddd;
      border-bottom: none;
      border-top: none;
      z-index: 99;
      top: 100%;
      left: 0;
      right: 0;
      max-height: 200px;
      overflow-y: auto;
      background: white;
    }
    .autocomplete-items div {
      padding: 8px 10px;
      cursor: pointer;
      background-color: #fff;
      border-bottom: 1px solid #ddd;
    }
    .autocomplete-items div:hover {
      background-color: #e9e9e9;
    }
    .autocomplete-active {
      background-color: #e9e9e9 !important;
    }
    .autocomplete-container {
      position: relative;
      display: inline-block;
      width: 100%;
    }

    /* Print styles - critical for maintaining layout when printing */
    @page {
      size: A4;
      margin: 0;
    }
    
    @media print {
      html, body {
        width: 210mm;
        height: 297mm;
        margin: 0;
        padding: 0;
        background: #fff;
      }
      
      .app-header {
        display: none !important;
      }
      
      .container {
        width: 210mm;
        padding: 10mm;
        margin: 0;
        box-shadow: none;
        border: none;
      }
      
      /* Keep header layout the same during print */
      .header {
        display: flex !important;
        justify-content: space-between !important;
        align-items: flex-start !important;
        width: 100% !important;
        position: relative !important;
      }
      
      .meta {
        flex-direction: column !important;
        align-items: flex-end !important;
        position: relative !important;
      }
      
      .barcode-container {
        position: relative !important;
      }
      
      #barcode {
        position: relative !important;
      }
      
      /* Bold important information for print */
      .print-bold {
        font-weight: 700 !important;
        text-decoration: underline !important;
      }
      
      .sign-row-wrapper {
        position: absolute !important;
        bottom: 10mm !important;
        left: 10mm !important;
        right: 10mm !important;
      }
      
      .input-section, .file-controls, .hide-on-print, .drawing-toolbar {
        display: none !important;
      }
    }

    /* Hidden measuring element */
    #__measure_span {
      position: absolute;
      visibility: hidden;
      height: auto;
      width: auto;
      white-space: pre;
    }

    /* Responsive */
    @media (max-width: 768px) {
      .header {
        flex-direction: column;
        align-items: flex-start;
      }
      .meta {
        flex-direction: column;
        width: 100%;
      }
      .modal-params {
        grid-template-columns: 1fr;
      }
      .app-header {
        flex-direction: column;
        padding: 10px;
      }
      .app-header .nav-links {
        flex-direction: column;
        align-items: center;
        gap: 10px;
        margin: 10px 0;
      }
      .app-header .user-info {
        flex-direction: column;
        align-items: center;
      }
    }
    
    /* PDF Save status message */
    #pdf-status {
      position: fixed;
      top: 20px;
      right: 20px;
      background: rgba(0, 128, 0, 0.8);
      color: white;
      padding: 10px;
      border-radius: 4px;
      display: none;
      z-index: 10000;
    }
    
    /* PDF save progress */
    .pdf-progress {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(255, 255, 255, 0.8);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      z-index: 10000;
      display: none;
    }
    
    .pdf-progress-message {
      font-size: 16px;
      margin-bottom: 10px;
      font-weight: bold;
    }
    
    .pdf-progress-bar {
      width: 300px;
      height: 20px;
      background: #f0f0f0;
      border-radius: 10px;
      overflow: hidden;
    }
    
    .pdf-progress-fill {
      height: 100%;
      background: #4CAF50;
      width: 0%;
      transition: width 0.3s ease;
    }
  </style>
</head>
<body>
  <!-- Application Header Navigation -->
  <header class="app-header">
    <div class="logo">ALUMPRO.AZ</div>
    <div class="nav-links">
      <a href="index.php"><i class="fas fa-home"></i> Ana Səhifə</a>
      <a href="orders.php"><i class="fas fa-clipboard-list"></i> Sifarişlər</a>
      <a href="customers.php"><i class="fas fa-users"></i> Müştərilər</a>
      <a href="warehouse.php"><i class="fas fa-warehouse"></i> Anbar</a>
      <a href="messages.php"><i class="fas fa-envelope"></i> Mesajlar</a>
    </div>
    <div class="user-info">
      <span><i class="fas fa-user"></i> <?= htmlspecialchars($sellerName) ?> (<?= htmlspecialchars($branchName) ?>)</span>
      <a href="../auth/logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Çıxış</a>
    </div>
  </header>

  <div id="pdf-status">PDF fayl yaddaşa verilir...</div>
  
  <div class="pdf-progress" id="pdf-progress">
    <div class="pdf-progress-message">PDF yaradılır...</div>
    <div class="pdf-progress-bar">
      <div class="pdf-progress-fill" id="pdf-progress-fill"></div>
    </div>
  </div>
  
  <div class="container" id="container">
    <div class="header" role="banner">
      <div class="left">
        <div class="logo"><img id="site-logo" src="../assets/images/logo.png" alt="ALUMPRO Logo" crossorigin="anonymous"></div>
        <div>
          <div class="site-name">ALUMPRO.AZ</div>
          <div class="muted">Profil & Şüşə Hesablaması</div>
        </div>
      </div>

      <div class="meta" aria-hidden="true">
        <div class="order-info">
          <div>Sifariş №: <span id="order-number-display" class="print-bold"><?= htmlspecialchars($orderNumber) ?></span></div>
          <div>Tarix: <span id="current-date" class="print-bold"><?= date('d.m.Y') ?></span></div>
        </div>
        <div class="barcode-container">
          <svg id="barcode"></svg>
        </div>
      </div>
    </div>

    <!-- Top row -->
    <div class="top-row" role="group" aria-label="Müştəri və satış məlumatları">
      <div class="top-field" style="margin-right:18px;">
        <div class="label">Mağaza:</div>
        <div class="value">
          <span id="branch-number" class="print-bold"><?= htmlspecialchars($branchName) ?></span>
        </div>
      </div>

      <div class="top-field" style="margin-right:18px;">
        <div class="label">Satıcı:</div>
        <div class="value">
          <span id="seller-name" class="print-bold"><?= htmlspecialchars($sellerName) ?></span>
        </div>
      </div>

      <div class="top-field" style="flex:2; min-width:0;">
        <div class="label">Müştəri:</div>
        <div class="value autocomplete-container">
          <input id="customer-name" type="text" placeholder="Ad Soyad" class="print-bold" autocomplete="off">
          <div id="customer-autocomplete" class="autocomplete-items"></div>
          <input type="hidden" id="customer-id" name="customer_id" value="">
        </div>
      </div>
    </div>

    <div class="initial-note-row">
      <label class="hide-on-snapshot hide-on-print"><input type="checkbox" id="show-seller-notes" checked> &nbsp;Əlavə qeydlər göstərilsin</label>
      <input type="text" id="initial-note" placeholder="Qısa qeyd (opsional)">
    </div>

    <!-- Input table -->
    <div class="input-section" id="input-section">
      <table>
        <thead>
          <tr>
            <th>Tip</th><th>Hündürlük (sm)</th><th>En (sm)</th><th>Ədəd</th><th>Şüşə Növü</th><th>Ofset (mm)</th><th>Petlə Sayı</th><th>Əməliyyat</th>
          </tr>
        </thead>
        <tbody id="input-table-body">
          <tr>
            <td>
              <select class="door-type">
                <option value="BQ">BQ</option>
                <option value="Qulp 110">Qulp 110</option>
                <option value="Qulp 20">Qulp 20</option>
                <option value="Başaç">Başaç</option>
                <option value="Ref">Ref</option>
                <option value="Gizli qapı">Gizli qapı</option>
                <option value="Veqa">Veqa</option>
              </select>
            </td>
            <td><input class="height-input" type="number" min="0"></td>
            <td><input class="width-input" type="number" min="0"></td>
            <td><input class="count-input" type="number" min="1" value="1"></td>
            <td>
              <select class="glass-type">
                <option>Temper Qəhvəyi</option>
                <option>Temper Fume</option>
                <option>Şəffaf</option>
                <option>Buzlu</option>
                <option>Güzgü</option>
                <option>Susesiz</option>
              </select>
            </td>
            <td>
              <select class="offset-type">
                <option value="3">3 mm</option>
                <option value="4">4 mm</option>
                <option value="6">6 mm</option>
                <option value="0">Yoxdur</option>
              </select>
            </td>
            <td><select class="hinge-count"><option value="0">0</option><option value="2">2</option><option value="3">3</option><option value="4">4</option></select></td>
            <td><button onclick="removeRow(this)" class="small-btn">Sil</button></td>
          </tr>
        </tbody>
      </table>

      <div class="controls" style="margin-top:8px;">
        <button onclick="addNewRow()" class="small-btn">Yeni Sətir</button>
        <button onclick="calculateRequirements()" class="small-btn">Hesabla</button>
        <button onclick="clearAll()" class="small-btn">Təmizlə</button>
      </div>
    </div>

    <!-- Results -->
    <div class="section-title" id="profile-title">Profil Ölçüləri</div>
    <table id="profile-table">
      <thead>
        <tr><th>Profil Tipi</th><th>Hündürlük (sm)</th><th>En (sm)</th><th>Say</th><th>Petlə sayı</th><th>Qeyd</th></tr>
      </thead>
      <tbody></tbody>
    </table>

    <div class="section-title" id="seller-notes-title">Əlavə Qeydlər</div>
    <div class="notes" id="seller-notes-wrapper">
      <textarea id="seller-notes" placeholder="Satıcının və ya usta qeydləri burada (hesabat üçün)"></textarea>
      
      <!-- Toggle drawing button -->
      <div style="text-align:right; margin-top:5px;">
        <button id="toggle-drawing" class="small-btn">Çəkmə Alətləri</button>
      </div>
      
      <!-- Drawing area -->
      <div id="drawing-container" class="drawing-container">
        <!-- Drawing toolbar -->
        <div class="drawing-toolbar" id="drawing-toolbar">
          <button class="drawing-tool" id="select-tool" title="Seçim aləti">
            <i class="fas fa-mouse-pointer"></i>
          </button>
          <button class="drawing-tool active" id="pencil-tool" title="Qələm">
            <i class="fas fa-pencil-alt"></i>
          </button>
          <button class="drawing-tool" id="line-tool" title="Düz xətt">
            <i class="fas fa-minus"></i>
          </button>
          <button class="drawing-tool" id="rect-tool" title="Düzbucaqlı">
            <i class="far fa-square"></i>
          </button>
          <button class="drawing-tool" id="circle-tool" title="Dairə">
            <i class="far fa-circle"></i>
          </button>
          <button class="drawing-tool" id="arrow-tool" title="Ox">
            <i class="fas fa-long-arrow-alt-right"></i>
          </button>
          <button class="drawing-tool" id="text-tool" title="Mətn">
            <i class="fas fa-font"></i>
          </button>
          <input type="color" id="color-picker" class="color-picker" value="#000000" title="Rəng seçin">
          <input type="range" id="size-slider" class="size-slider" min="1" max="20" value="3" title="Xətt qalınlığı">
          <span id="size-value">3px</span>
          <button class="drawing-tool" id="undo-tool" title="Geri al" style="margin-left:auto">
            <i class="fas fa-undo"></i>
          </button>
          <button class="drawing-tool" id="clear-canvas" title="Təmizlə">
            <i class="fas fa-trash-alt"></i>
          </button>
        </div>
        <canvas id="drawing-canvas" class="drawing-canvas"></canvas>
        <div id="text-input" contenteditable="true"></div>
      </div>
    </div>

    <div class="section-title" id="glass-title">Şüşə Ölçüləri</div>
    <table id="glass-table">
      <thead><tr><th>Şüşə növü</th><th>Hündürlük (sm)</th><th>En (sm)</th><th>Ədəd</th><th>Sahə (m²)</th></tr></thead>
      <tbody></tbody>
    </table>

    <div class="file-controls" style="margin-top:10px;">
      <button onclick="printResults()" class="small-btn">Çap Et</button>
      <button onclick="generateInvoicePDF()" class="small-btn">Qaime Yadda saxla (PDF - 1 A4)</button>
      <button onclick="generateFullReportPDF()" class="small-btn">Tam Hesabat Yadda saxla (PDF)</button>
      <button onclick="openPriceModal()" class="small-btn">Qiymət Hesabla</button>
    </div>

    <!-- Bottom signature area fixed to page bottom -->
    <div class="sign-row-wrapper">
      <div class="sign-row" id="sign-row" aria-hidden="false">
        <div class="sign-cell"><div class="sign-label">Sifarişi Tamamlayan Usta</div><div class="sign-line"></div></div>
        <div class="sign-cell"><div class="sign-label">Sifarişi Təhvil Verən</div><div class="sign-line"></div></div>
        <div class="sign-cell"><div class="sign-label">Təhvil Tarixi</div><div class="sign-line"></div></div>
        <div class="sign-cell"><div class="sign-label">Təhvil Alan</div><div class="sign-line"></div></div>
      </div>
    </div>
  </div>

  <!-- Measuring element -->
  <span id="__measure_span"></span>

  <!-- Modal redesigned with combined parameters and materials -->
  <div id="modal-backdrop" class="modal-backdrop" role="dialog" aria-hidden="true">
    <div class="modal" role="document" aria-labelledby="modal-title">
      <form id="save-order-form" method="post" action="save-order.php">
        <h3 id="modal-title">Sifariş Qiymət Hesablaması</h3>

        <!-- 1. Order info section -->
        <div class="modal-section">
          <h4>Sifariş Məlumatları</h4>
          <div class="modal-order">
            <div class="field">
              <label>Sifariş №:</label>
              <div class="value">
                <span id="modal-order-number"><?= htmlspecialchars($orderNumber) ?></span>
                <input type="hidden" name="order_number" value="<?= htmlspecialchars($orderNumber) ?>">
              </div>
            </div>
            <div class="field">
              <label>Tarix:</label>
              <div class="value">
                <span id="modal-date"><?= date('d.m.Y') ?></span>
                <input type="hidden" name="order_date" value="<?= date('Y-m-d') ?>">
              </div>
            </div>
            <div class="field">
              <label>Mağaza:</label>
              <div class="value">
                <span id="modal-branch"><?= htmlspecialchars($branchName) ?></span>
                <input type="hidden" name="branch_id" value="<?= htmlspecialchars($branchId) ?>">
              </div>
            </div>
            <div class="field">
              <label>Satıcı:</label>
              <div class="value">
                <span id="modal-seller"><?= htmlspecialchars($sellerName) ?></span>
                <input type="hidden" name="seller_id" value="<?= htmlspecialchars($sellerId) ?>">
              </div>
            </div>
            <div class="field">
              <label>Müştəri:</label>
              <div class="value">
                <span id="modal-customer"></span>
                <input type="hidden" name="customer_id" id="modal-customer-id" value="">
              </div>
            </div>
            <div class="field">
              <label>Qeyd:</label>
              <div class="value">
                <input id="modal-initial-note" type="text" name="initial_note">
              </div>
            </div>
            <div class="field">
              <label>Ümumi qapaq sayı:</label>
              <div class="value"><span id="modal-door-count">0</span></div>
            </div>
          </div>
        </div>

        <!-- 2. Combined parameters and materials -->
        <div class="modal-section">
          <h4>Hesablama Parametrləri və Materiallar</h4>
          <div class="modal-params">
            <div class="param">
              <label>Profil parçası uzunluğu (m)</label>
              <input id="param-profile-unit" type="number" step="0.1" value="3" class="calc-input" name="profile_unit">
            </div>
            <div class="param">
              <label>Yan Profil qiyməti (AZN/m)</label>
              <input id="param-profile-price" type="number" step="0.01" value="5.00" class="calc-input" name="profile_price">
            </div>
            <div class="param">
              <label>Qulp Profil qiyməti (AZN/m)</label>
              <input id="param-handle-price" type="number" step="0.01" value="6.50" class="calc-input" name="handle_price">
            </div>
            <div class="param">
              <label>Şüşə qiyməti (AZN/m²)</label>
              <input id="param-glass-price" type="number" step="0.01" value="20.00" class="calc-input" name="glass_price">
            </div>
            <div class="param">
              <label>Misar qalınlığı (mm)</label>
              <input id="param-kerf-mm" type="number" step="0.1" value="4" class="calc-input" name="kerf_mm">
            </div>
            <div class="param">
              <label>Tullantı % (əlavə)</label>
              <input id="param-waste" type="number" step="0.1" value="0" class="calc-input" name="waste_percent">
            </div>
            <div class="param">
              <label>Petlə qiyməti (AZN/əd)</label>
              <input id="param-hinge-price" type="number" step="0.01" value="2.50" class="calc-input" name="hinge_price">
            </div>
            <div class="param">
              <label>Bağlantı qiyməti (AZN/əd)</label>
              <input id="param-conn-price" type="number" step="0.01" value="1.20" class="calc-input" name="conn_price">
            </div>
            <div class="param">
              <label>Mexanizm qiyməti (AZN/əd)</label>
              <input id="param-mech-price" type="number" step="0.01" value="" placeholder="Mexanizm qiyməti" class="calc-input" name="mech_price">
            </div>
            <div class="param">
              <label>Petlə sayı</label>
              <input id="calc-hinge-count-modal" type="number" value="0" class="calc-input" name="hinge_count">
            </div>
            <div class="param">
              <label>Bağlantı sayı</label>
              <input id="calc-conn-count-modal" type="number" value="0" class="calc-input" name="conn_count">
            </div>
            <div class="param">
              <label>Mexanizm sayı</label>
              <input id="calc-mech-count-modal" type="number" value="0" class="calc-input" name="mech_count">
            </div>
            <div class="param">
              <label>Nəqliyyat (AZN)</label>
              <input id="calc-transport-modal" type="number" value="0" class="calc-input" name="transport_fee">
            </div>
          </div>
          
          <!-- Additional payment fields -->
          <div class="additional-fields">
            <div class="param">
              <label>Yığılma haqqı (AZN)</label>
              <input id="assembly-fee" type="number" step="0.01" value="0" name="assembly_fee">
            </div>
            <div class="param">
              <label>Avans ödəniş (AZN)</label>
              <input id="advance-payment" type="number" step="0.01" value="0" name="advance_payment">
            </div>
          </div>
        </div>

        <!-- 3. Profile calculation results -->
        <div class="modal-section">
          <h4>Profil Hesablaması</h4>
          <table class="calc-table" id="calc-profiles">
            <thead>
              <tr>
                <th>Profil Tipi</th>
                <th>Total (m)</th>
                <th>Parça ölçüsü (m)</th>
                <th>Parça sayı</th>
                <th>Qalıq (m)</th>
                <th>Qiymət (AZN)</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
          <div style="font-size:11px; color:#666; margin-top:4px;">
            Qeyd: Hər kəsim üçün misar qalınlığı (kerf) iki tərəfdən əlavə edilir. Qalıqlar digər uyğun kəsimlər üçün istifadə olunur.
          </div>
        </div>

        <!-- 4. Glass calculation -->
        <div class="modal-section">
          <h4>Şüşə Hesablaması</h4>
          <table class="calc-table" id="calc-glass">
            <thead>
              <tr>
                <th>Şüşə növü</th>
                <th>Ümumi sahə (m²)</th>
                <th>Qiymət/m²</th>
                <th>Qiymət (AZN)</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>

        <!-- 5. Final costs summary -->
        <div class="modal-section">
          <h4>Yekun Hesablama</h4>
          <table class="calc-table">
            <tbody>
              <tr>
                <td width="25%"><strong>Profil xərci:</strong></td>
                <td width="25%" id="calc-profile-total">0.00 AZN</td>
                <td width="25%"><strong>Şüşə xərci:</strong></td>
                <td width="25%" id="calc-glass-total">0.00 AZN</td>
              </tr>
              <tr>
                <td><strong>Petlə xərci:</strong></td>
                <td id="calc-hinge-price">0.00 AZN</td>
                <td><strong>Bağlantı xərci:</strong></td>
                <td id="calc-conn-price">0.00 AZN</td>
              </tr>
              <tr>
                <td><strong>Mexanizm xərci:</strong></td>
                <td id="calc-mech-price">0.00 AZN</td>
                <td><strong>Nəqliyyat:</strong></td>
                <td id="calc-transport-price">0.00 AZN</td>
              </tr>
              <tr>
                <td><strong>Yığılma haqqı:</strong></td>
                <td id="calc-assembly-price">0.00 AZN</td>
                <td><strong>Avans ödəniş:</strong></td>
                <td id="calc-advance-payment">0.00 AZN</td>
              </tr>
            </tbody>
          </table>
          <input type="hidden" id="total-amount" name="total_amount" value="0">
          <input type="hidden" id="remaining-amount" name="remaining_amount" value="0">
          <input type="hidden" id="profile-data" name="profile_data" value="">
          <input type="hidden" id="glass-data" name="glass_data" value="">
          <input type="hidden" id="drawing-data" name="drawing_data" value="">
        </div>

        <!-- 6. Final total -->
        <div style="margin:16px 0; text-align:right; font-size:16px; font-weight:bold; color:#1f3b4d;">
          Ümumi Cəmi: <span id="calc-total">0.00 AZN</span>
          <div style="font-size:14px; margin-top:5px; color:#777;">
            Qalıq Məbləğ: <span id="calc-remaining">0.00 AZN</span>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" id="modal-recalc-btn" class="small-btn">Yenidən Hesabla</button>
          <button type="submit" class="small-btn">Sifarişi Yadda Saxla</button>
          <button type="button" onclick="closePriceModal()" class="small-btn">Bağla</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Template for printing to ensure consistent layout -->
  <div id="print-template" style="display:none;"></div>

  <script>
    // Helper utilities with fixed pad function
    const byId = id => document.getElementById(id) || null;
    const setText = (id, txt) => { const el = byId(id); if(el) el.textContent = txt; };
    const getNum = (id, fallback=0) => { const el = byId(id); if(!el) return fallback; const v = el.value; return v === '' || v === undefined || v === null ? fallback : Number(v); };
    
    // Fixed pad function that works correctly
    function padZero(num, size=4) {
      let s = String(num);
      while (s.length < size) s = "0" + s;
      return s;
    }
    
    // Generate barcode
    function updateBarcode() {
      try {
        const barcodeElement = byId('barcode');
        if (!barcodeElement) return;
        
        // Generate barcode with proper formatting
        const dateDigits = '<?= date('Ymd') ?>';
        const orderSeq = '<?= htmlspecialchars($orderNumber) ?>';
        const barcodeValue = dateDigits + orderSeq;
        
        JsBarcode("#barcode", barcodeValue, {
          format: "CODE39",
          displayValue: true,
          height: parseInt(getComputedStyle(document.documentElement).getPropertyValue('--barcode-height')) || 60,
          width: 1.5,
          margin: 2,
          fontOptions: "bold"
        });
      } catch(e) {
        console.error("Barcode generation error:", e);
      }
    }
    updateBarcode();

    // Row management functions
    function addNewRow() {
      const tbody = byId('input-table-body');
      if (!tbody) return;
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>
          <select class="door-type">
            <option value="BQ">BQ</option>
            <option value="Qulp 110">Qulp 110</option>
            <option value="Qulp 20">Qulp 20</option>
            <option value="Başaç">Başaç</option>
            <option value="Ref">Ref</option>
            <option value="Gizli qapı">Gizli qapı</option>
            <option value="Veqa">Veqa</option>
          </select>
        </td>
        <td><input class="height-input" type="number" min="0"></td>
        <td><input class="width-input" type="number" min="0"></td>
        <td><input class="count-input" type="number" min="1" value="1"></td>
        <td>
          <select class="glass-type">
            <option>Temper Qəhvəyi</option>
            <option>Temper Fume</option>
            <option>Şəffaf</option>
            <option>Buzlu</option>
            <option>Güzgü</option>
            <option>Susesiz</option>
          </select>
        </td>
        <td>
          <select class="offset-type">
            <option value="3">3 mm</option>
            <option value="4">4 mm</option>
            <option value="6">6 mm</option>
            <option value="0">Yoxdur</option>
          </select>
        </td>
        <td><select class="hinge-count"><option value="0">0</option><option value="2">2</option><option value="3">3</option><option value="4">4</option></select></td>
        <td><button onclick="removeRow(this)" class="small-btn">Sil</button></td>
      `;
      tbody.appendChild(tr);
    }
    
    function removeRow(btn) {
      const tbody = byId('input-table-body');
      if (!tbody) return;
      if (tbody.querySelectorAll('tr').length <= 1) {
        alert('Ən azı bir sətir qalmalıdır');
        return;
      }
      btn.closest('tr').remove();
    }
    
    function clearAll() {
      if (!confirm('Bütün məlumatlar silinsin?')) return;
      const tbody = byId('input-table-body');
      if (!tbody) return;
      
      tbody.querySelectorAll('tr').forEach((tr, idx) => {
        if (idx === 0) {
          tr.querySelectorAll('input').forEach(i => i.value = '');
          tr.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
        } else {
          tr.remove();
        }
      });
      
      const profileBody = byId('profile-table')?.querySelector('tbody');
      const glassBody = byId('glass-table')?.querySelector('tbody');
      if (profileBody) profileBody.innerHTML = '';
      if (glassBody) glassBody.innerHTML = '';
      if (byId('seller-notes')) byId('seller-notes').value = '';
      if (byId('initial-note')) byId('initial-note').value = '';
      if (byId('customer-name')) byId('customer-name').value = '';
      if (byId('customer-id')) byId('customer-id').value = '';
      
      // Clear drawing canvas
      if (window.drawingCanvas && drawingCanvas.clearCanvas) {
        drawingCanvas.clearCanvas();
      }
    }

    // Revised packing algorithm - separate door types, handle profile types correctly
    function packProfilesExact(kerfMm = 4, panelLenCm = 300) {
      const rows = document.querySelectorAll('#input-table-body tr');
      
      // Initialize data structure for profile tracking
      const allProfiles = {
        side: [], // All side profile pieces (consolidated)
        bqHandleCount: 0, // Count of BQ handle profiles needed
        qulp110Count: 0, // Count of Qulp 110 profiles needed
        qulp20Count: 0, // Count of Qulp 20 profiles needed
      };
      
      let totalDoors = 0;
      let totalHinges = 0;
      
      // First collect all pieces
      for (const r of rows) {
        const doorType = r.querySelector('.door-type')?.value || '';
        const h = Number(r.querySelector('.height-input')?.value) || 0;
        const w = Number(r.querySelector('.width-input')?.value) || 0;
        const cnt = Number(r.querySelector('.count-input')?.value) || 0;
        const hingeCount = Number(r.querySelector('.hinge-count')?.value) || 0;
        
        if (!(h > 0 && w > 0 && cnt > 0)) continue;
        
        totalDoors += cnt;
        totalHinges += hingeCount * cnt;
        
        for (let i = 0; i < cnt; i++) {
          if (doorType === 'BQ') {
            // BQ: 1 height side is handle profile, other 3 sides are regular
            allProfiles.bqHandleCount++;
            allProfiles.side.push(h, w, w); // 1 height + 2 widths for side profiles
          } 
          else if (doorType === 'Qulp 110') {
            // Qulp 110: Fixed 110cm handle + 3 sides
            allProfiles.qulp110Count++;
            allProfiles.side.push(h, h, w); // 2 heights + 1 width for side profiles
          } 
          else if (doorType === 'Qulp 20') {
            // Qulp 20: Fixed 20cm handle + 3 sides
            allProfiles.qulp20Count++;
            allProfiles.side.push(h, h, w); // 2 heights + 1 width for side profiles
          } 
          else {
            // All other types: 4 sides as regular profiles
            allProfiles.side.push(h, h, w, w); // 2 heights + 2 widths for side profiles
          }
        }
      }

      // Process all side pieces with First-Fit Decreasing bin packing
      const kerfCm = (Number(kerfMm) || 4) / 10; // mm -> cm
      
      function packPieces(pieces) {
        if (!pieces || !pieces.length) return {
          panels: [],
          totalUsedCm: 0,
          totalWasteCm: 0,
          panelsCount: 0
        };
        
        // Sort largest to smallest for optimal packing
        const sorted = pieces.slice().sort((a, b) => b - a);
        const panels = [];
        
        for (const len of sorted) {
          const consume = len + 2 * kerfCm; // Each piece consumes length + 2*kerf
          let placed = false;
          
          // Try to fit in existing panels
          for (const p of panels) {
            if (p.remaining + 1e-9 >= consume) {
              p.cuts.push(len);
              p.remaining -= consume;
              placed = true;
              break;
            }
          }
          
          // If can't fit, create a new panel
          if (!placed) {
            panels.push({ 
              cuts: [len], 
              remaining: panelLenCm - consume 
            });
          }
        }
        
        // Calculate totals
        const totalUsedCm = sorted.reduce((s, v) => s + v, 0);
        const totalWasteCm = panels.reduce((s, p) => s + Math.max(0, p.remaining), 0);
        
        return { 
          panels, 
          totalUsedCm, 
          totalWasteCm, 
          panelsCount: panels.length 
        };
      }

      // Pack side profiles
      const sideResult = packPieces(allProfiles.side);
      
      // Calculate handle profiles - they don't get packed together, each is a separate unit
      // BQ handle profiles are the exact height of the door
      const bqHandleHeights = [];
      const qulp110Profiles = [];
      const qulp20Profiles = [];
      
      rows.forEach(r => {
        const doorType = r.querySelector('.door-type')?.value || '';
        const h = Number(r.querySelector('.height-input')?.value) || 0;
        const cnt = Number(r.querySelector('.count-input')?.value) || 0;
        
        if (!(h > 0 && cnt > 0)) return;
        
        if (doorType === 'BQ') {
          // For each BQ door, add one handle of height h
          for (let i = 0; i < cnt; i++) {
            bqHandleHeights.push(h);
          }
        } 
        else if (doorType === 'Qulp 110') {
          // For each Qulp 110 door, add one 110cm handle
          for (let i = 0; i < cnt; i++) {
            qulp110Profiles.push(110);
          }
        } 
        else if (doorType === 'Qulp 20') {
          // For each Qulp 20 door, add one 20cm handle
          for (let i = 0; i < cnt; i++) {
            qulp20Profiles.push(20);
          }
        }
      });
      
      // Pack the BQ handle profiles separately
      const bqHandleResult = packPieces(bqHandleHeights);
      
      // For Qulp 110 and Qulp 20, each profile uses a separate panel (no reuse of leftover)
      const qulp110Result = {
        panels: qulp110Profiles.map(len => ({
          cuts: [len],
          remaining: panelLenCm - (len + 2 * kerfCm)
        })),
        totalUsedCm: qulp110Profiles.reduce((s, v) => s + v, 0),
        totalWasteCm: qulp110Profiles.length * panelLenCm - qulp110Profiles.reduce((s, v) => s + v + 2 * kerfCm, 0),
        panelsCount: qulp110Profiles.length
      };
      
      const qulp20Result = {
        panels: qulp20Profiles.map(len => ({
          cuts: [len],
          remaining: panelLenCm - (len + 2 * kerfCm)
        })),
        totalUsedCm: qulp20Profiles.reduce((s, v) => s + v, 0),
        totalWasteCm: qulp20Profiles.length * panelLenCm - qulp20Profiles.reduce((s, v) => s + v + 2 * kerfCm, 0),
        panelsCount: qulp20Profiles.length
      };
      
      // Return organized results
      return { 
        results: {
          side: sideResult,
          bqHandle: bqHandleResult,
          qulp110: qulp110Result,
          qulp20: qulp20Result,
          kerfCm
        }, 
        totalDoors,
        totalHinges
      };
    }

    // Build main tables
    function calculateRequirements() {
      const rows = document.querySelectorAll('#input-table-body tr');
      const profileBody = byId('profile-table')?.querySelector('tbody');
      const glassBody = byId('glass-table')?.querySelector('tbody');
      
      if (profileBody) profileBody.innerHTML = '';
      if (glassBody) glassBody.innerHTML = '';

      let allSusesiz = true;
      let profileHTML = '';

      rows.forEach(r => {
        const tip = r.querySelector('.door-type')?.value || '';
        const height = Number(r.querySelector('.height-input')?.value) || 0;
        const width = Number(r.querySelector('.width-input')?.value) || 0;
        const count = Number(r.querySelector('.count-input')?.value) || 0;
        const glassType = r.querySelector('.glass-type')?.value || '';
        const offsetMm = Number(r.querySelector('.offset-type')?.value) || 0;
        const hingeCount = r.querySelector('.hinge-count')?.value || 0;
        
        if (!height || !width || !count) return;
        
        profileHTML += `<tr>
          <td>${tip}</td>
          <td>${height}</td>
          <td>${width}</td>
          <td>${count}</td>
          <td>${hingeCount}</td>
          <td><div class="profile-note" contenteditable="true"></div></td>
        </tr>`;
        
        if (glassType !== 'Susesiz') {
          const reductionCm = offsetMm / 10;
          const glassW = Math.max(0, width - reductionCm).toFixed(1);
          const glassH = Math.max(0, height - reductionCm).toFixed(1);
          const area = (Number(glassW) / 100) * (Number(glassH) / 100) * count;
          
          if (glassBody) {
            glassBody.innerHTML += `<tr>
              <td>${glassType}</td>
              <td>${glassH}</td>
              <td>${glassW}</td>
              <td>${count}</td>
              <td>${area.toFixed(2)}</td>
            </tr>`;
          }
          
          allSusesiz = false;
        }
      });

      if (profileBody) profileBody.innerHTML = profileHTML;
      
      // Hide glass section if no glass used
      if (allSusesiz) { 
        const glassTitle = byId('glass-title');
        const glassTable = byId('glass-table');
        if (glassTitle) glassTitle.style.display = 'none';
        if (glassTable) glassTable.style.display = 'none';
      } else {
        const glassTitle = byId('glass-title');
        const glassTable = byId('glass-table');
        if (glassTitle) glassTitle.style.display = '';
        if (glassTable) glassTable.style.display = '';
      }
    }

    // Print function
    function printResults() {
      calculateRequirements();
      window.print();
    }
    
    // Show status message for PDF saving
    function showPdfStatus(message) {
      const status = byId('pdf-status');
      if (status) {
        status.textContent = message;
        status.style.display = 'block';
        setTimeout(() => {
          status.style.display = 'none';
        }, 3000);
      }
    }
    
    // Show progress for PDF generation
    function showPdfProgress(show, message = "", percent = 0) {
      const progress = byId('pdf-progress');
      const progressFill = byId('pdf-progress-fill');
      const progressMsg = document.querySelector('.pdf-progress-message');
      
      if (!progress || !progressFill || !progressMsg) return;
      
      if (show) {
        progressMsg.textContent = message;
        progressFill.style.width = `${percent}%`;
        progress.style.display = 'flex';
      } else {
        progress.style.display = 'none';
      }
    }

    // Helper to sanitize filename
    function sanitizeFilename(name) {
      return name.replace(/[^\w\s\-\.]/gi, '')  // Remove special chars except dots
                .replace(/\s+/g, '_')          // Replace spaces with underscores
                .replace(/-+/g, '-');          // Normalize dashes
    }

    // New PDF generation with customer directory structure
    async function generateInvoicePDF() {
      try {
        calculateRequirements();
        showPdfProgress(true, "PDF hazırlanır...", 10);

        // Get customer name for directory
        const customerName = (byId('customer-name')?.value || 'Musteri').trim();
        const sanitizedCustomerName = sanitizeFilename(customerName);
        
        // Clone container for PDF generation
        const container = byId('container');
        if (!container) throw new Error("Container element not found");
        
        const clone = container.cloneNode(true);
        
        showPdfProgress(true, "Məlumatlar çevrilir...", 20);
        
        // Hide application header
        const appHeader = clone.querySelector('.app-header');
        if (appHeader) appHeader.remove();
        
        // Hide input section and controls
        clone.querySelectorAll('.input-section, .controls, .file-controls, button').forEach(n => n.remove());
        clone.querySelectorAll('.hide-on-snapshot, .hide-on-print').forEach(n => n.remove());
        
        // Process notes
        const notesArea = clone.querySelector('#seller-notes-wrapper textarea');
        if (notesArea) {
          const div = document.createElement('div');
          div.style.whiteSpace = 'pre-wrap';
          div.style.fontSize = '13px';
          div.style.background = 'var(--note-bg)';
          div.style.padding = '6px';
          div.innerHTML = (notesArea.value || '').replace(/\n/g, '<br>');
          notesArea.parentNode.replaceChild(div, notesArea);
        }
        
        // Ensure drawing canvas is included in PDF if visible
        const drawingContainer = clone.querySelector('#drawing-container');
        if (drawingContainer && window.drawingCanvas && window.drawingCanvas.isVisible) {
          // Remove the toolbar from the clone
          const toolbar = clone.querySelector('#drawing-toolbar');
          if (toolbar) toolbar.remove();
          
          // Make drawing container visible in clone
          drawingContainer.style.display = 'block';
          
          // Replace canvas with image from original canvas
          const originalCanvas = byId('drawing-canvas');
          const cloneCanvas = clone.querySelector('#drawing-canvas');
          
          if (originalCanvas && cloneCanvas) {
            // Create image from original canvas
            const img = document.createElement('img');
            img.src = originalCanvas.toDataURL();
            img.style.width = '100%';
            img.style.height = 'auto';
            
            // Replace canvas with image
            cloneCanvas.parentNode.replaceChild(img, cloneCanvas);
          }
        } else {
          // Remove drawing container if not used
          if (drawingContainer) drawingContainer.remove();
        }
        
        showPdfProgress(true, "Mətn elementləri işlənir...", 30);
        
        // Replace all inputs with static text - ensure key fields are bold and underlined
        clone.querySelectorAll('input, select, textarea').forEach(el => {
          if (el.tagName === 'TEXTAREA') return;
          
          const span = document.createElement('div');
          span.className = 'printed-value';
          
          // Add bold and underline to key fields
          if (el.id === 'customer-name' || el.id === 'seller-name') {
            span.className += ' print-bold';
            span.style.fontWeight = 'bold';
            span.style.textDecoration = 'underline';
          }
          
          if (el.id === 'initial-note') {
            span.className += ' printed-initial-note';
            span.style.fontWeight = '700';
            span.style.textAlign = 'center';
            span.textContent = el.value || '';
          } else if (el.tagName === 'SELECT') {
            const selectedOption = el.options[el.selectedIndex];
            span.textContent = selectedOption ? selectedOption.text : (el.value || '');
          } else {
            span.textContent = el.value || '';
          }
          
          try {
            el.parentNode.replaceChild(span, el);
          } catch (e) {
            console.error("Error replacing element:", e);
          }
        });
        
        // Also ensure order-number and date are bold and underlined
        clone.querySelectorAll('#order-number-display, #current-date').forEach(el => {
          el.classList.add('print-bold');
          el.style.fontWeight = 'bold';
          el.style.textDecoration = 'underline';
        });
        
        // Handle contenteditable elements
        clone.querySelectorAll('[contenteditable]').forEach(el => {
          const txt = el.innerText || el.textContent || '';
          const div = document.createElement('div');
          div.style.whiteSpace = 'pre-wrap';
          div.style.fontSize = '12px';
          div.textContent = txt;
          el.parentNode.replaceChild(div, el);
        });
        
        showPdfProgress(true, "Qrafika elementləri köçürülür...", 40);
        
        // Copy barcode and logo SVG
        const origBarcode = byId('barcode');
        const cloneBarcode = clone.querySelector('#barcode');
        if (origBarcode && cloneBarcode) cloneBarcode.innerHTML = origBarcode.innerHTML;
        
        const origLogo = byId('site-logo');
        const cloneLogo = clone.querySelector('#site-logo');
        if (origLogo && cloneLogo) cloneLogo.src = origLogo.src;
        
        // Append clone off-screen for rendering
        clone.style.position = 'fixed';
        clone.style.left = '-9999px';
        clone.style.top = '0';
        clone.style.width = container.clientWidth + 'px';
        document.body.appendChild(clone);
        
        // Render to canvas
        showPdfProgress(true, "HTML şəkli çəkilir...", 60);
        const canvas = await html2canvas(clone, {
          scale: 2,
          useCORS: true,
          allowTaint: true,
          logging: false,
          onclone: (doc) => {
            // Additional fixes in cloned document
            const clonedContainer = doc.querySelector('.container');
            if (clonedContainer) {
              clonedContainer.style.width = '190mm';
              clonedContainer.style.padding = '10mm';
            }
            
            // Ensure bold & underline in clone (for PDF)
            doc.querySelectorAll('.print-bold').forEach(el => {
              el.style.fontWeight = 'bold';
              el.style.textDecoration = 'underline';
            });
          }
        });
        
        // Remove clone after rendering
        document.body.removeChild(clone);
        
        // Generate PDF
        showPdfProgress(true, "PDF yaradılır...", 80);
        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF('p', 'mm', 'a4');
        const pdfWidth = pdf.internal.pageSize.getWidth() - 10;
        const pdfHeight = pdf.internal.pageSize.getHeight() - 12;
        
        const imgData = canvas.toDataURL('image/jpeg', 1.0);
        const imgW = canvas.width;
        const imgH = canvas.height;
        const ratio = imgW / imgH;
        
        let drawW = pdfWidth;
        let drawH = drawW / ratio;
        
        if (drawH > pdfHeight) {
          drawH = pdfHeight;
          drawW = drawH * ratio;
        }
        
        const left = (pdf.internal.pageSize.getWidth() - drawW) / 2;
        pdf.addImage(imgData, 'JPEG', left, 6, drawW, drawH);
        
        // Get order number for filename
        const orderNumber = '<?= htmlspecialchars($orderNumber) ?>';
        const barcodeValue = '<?= htmlspecialchars($barcodeValue) ?>';
        
        // Save PDF
        showPdfProgress(true, "PDF faylı hazırlanır...", 90);
        const pdfBlob = pdf.output('blob');
        
        // Create customer directory and save the PDF using server request
        const filename = `${sanitizedCustomerName}_${barcodeValue}.pdf`;
        const directoryPath = `/sifarisler/${sanitizedCustomerName}`;
        
        // Use fetch to send the PDF to the server and create directories if needed
        showPdfProgress(true, "Müştəri qovluğu yaradılır və fayl saxlanılır...", 95);
        
        try {
          // Convert blob to base64 for sending to server
          const reader = new FileReader();
          reader.readAsDataURL(pdfBlob);
          reader.onloadend = async function() {
            const base64data = reader.result;
            
            // Use fetch to send the file to the server
            const response = await fetch('../api/save-pdf.php', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
              },
              body: JSON.stringify({
                pdf: base64data,
                directory: directoryPath,
                filename: filename
              })
            });
            
            if (response.ok) {
              // Server successfully saved the file
              showPdfProgress(false);
              showPdfStatus(`PDF uğurla yadda saxlanıldı: ${directoryPath}/${filename}`);
              
              // Also provide local download as backup
              saveAs(pdfBlob, filename);
            } else {
              // Server error - fall back to local download
              console.error('Server error saving PDF');
              saveAs(pdfBlob, filename);
              showPdfProgress(false);
              showPdfStatus(`Server xətası: PDF lokal olaraq saxlanıldı: ${filename}`);
            }
          };
        } catch (error) {
          console.error('Error saving PDF to server:', error);
          // Fall back to local download
          saveAs(pdfBlob, filename);
          showPdfProgress(false);
          showPdfStatus(`PDF lokal olaraq saxlanıldı: ${filename}`);
        }
      } catch (err) {
        console.error("PDF generation error:", err);
        showPdfProgress(false);
        showPdfStatus(`PDF yaradılma xətası: ${err.message || 'Naməlum səhv'}`);
        alert(`PDF yaradılma xətası: ${err.message || 'Naməlum səhv'}`);
      }
    }
    
    async function generateFullReportPDF() {
      await generateInvoicePDF();
    }

    // Modal handling
    function openPriceModal() {
      calculateRequirements();
      const modalBackdrop = byId('modal-backdrop');
      if (modalBackdrop) modalBackdrop.style.display = 'flex';
      
      // Copy order info to modal
      setText('modal-customer', byId('customer-name')?.value || '');
      byId('modal-customer-id').value = byId('customer-id')?.value || '';
      
      if (byId('initial-note') && byId('modal-initial-note')) {
        byId('modal-initial-note').value = byId('initial-note').value;
      }

      // Calculate total hinge count from input table and set in modal
      let totalHinges = 0;
      document.querySelectorAll('#input-table-body tr').forEach(row => {
        const hingeCount = Number(row.querySelector('.hinge-count')?.value) || 0;
        const doorCount = Number(row.querySelector('.count-input')?.value) || 0;
        if (doorCount > 0 && hingeCount > 0) {
          totalHinges += hingeCount * doorCount;
        }
      });
      
      // Set calculated hinge count in modal
      if (byId('calc-hinge-count-modal')) {
        byId('calc-hinge-count-modal').value = totalHinges;
      }

      // Sync other editable values with modal
      ['calc-conn-count-modal', 'calc-mech-count-modal', 'calc-transport-modal'].forEach(id => {
        const src = byId(id);
        const dst = byId(id.replace('-modal', '-input')) || byId(id);
        if (src && dst) src.value = dst.value || '0';
      });

      // Bind recalculate button
      const recalcBtn = byId('modal-recalc-btn');
      if (recalcBtn) {
        recalcBtn.onclick = () => {
          try {
            recalculateModal();
          
          } catch(e) {
            console.error('Calculation error:', e);
            alert('Hesablama xətası: ' + (e.message || e));
          }
        };
      }

      // Auto recalculate when parameters change
      const inputIds = [
        'param-profile-unit', 'param-profile-price', 'param-handle-price',
        'param-glass-price', 'param-kerf-mm', 'param-waste', 
        'param-hinge-price', 'param-conn-price', 'param-mech-price',
        'calc-hinge-count-modal', 'calc-conn-count-modal', 
        'calc-mech-count-modal', 'calc-transport-modal',
        'assembly-fee', 'advance-payment'
      ];
      
      inputIds.forEach(id => {
        const el = byId(id);
        if (el) {
          el.oninput = () => {
            try {
              recalculateModal();
            } catch(e) {
              console.error('Auto-recalc error:', e);
            }
          };
        }
      });

      // Initial calculation
      recalculateModal();
    }

    function closePriceModal() {
      const modalBackdrop = byId('modal-backdrop');
      if (modalBackdrop) modalBackdrop.style.display = 'none';
    }

    // Modal calculation with revised packing algorithm
    function recalculateModal() {
      try {
        // Get parameters
        const unitLenM = getNum('param-profile-unit', 3);
        const priceSide = getNum('param-profile-price', 5);
        const priceHandle = getNum('param-handle-price', 6.5);
        const glassPrice = getNum('param-glass-price', 20);
        const kerfMm = getNum('param-kerf-mm', 4);
        const extraWastePct = getNum('param-waste', 0);
        const mechPrice = getNum('param-mech-price', 0); // No default - use user input
        const assemblyFee = getNum('assembly-fee', 0);
        const advancePayment = getNum('advance-payment', 0);

        const panelLenCm = Math.round(unitLenM * 100);

        // Run the revised packing algorithm
        const { results: packing, totalDoors, totalHinges } = packProfilesExact(kerfMm, panelLenCm);
        
        // Update total door count in modal
        setText('modal-door-count', totalDoors);

        // Fill profiles table - consolidate side profiles, separate handle profiles by type
        const profBody = byId('calc-profiles')?.querySelector('tbody');
        if (profBody) profBody.innerHTML = '';
        
        let totalProfileCost = 0;
        
        // 1. Side profiles row - all side profiles consolidated together
        const sideData = packing.side;
        const sideUsedM = (sideData.totalUsedCm || 0) / 100; // cm -> m
        const sidePanels = sideData.panelsCount || 0;
        const sideWasteM = (sideData.totalWasteCm || 0) / 100; // cm -> m
        const sideExtra = sideUsedM * (extraWastePct / 100); // Additional waste percentage
        const sideCost = (sideUsedM + sideWasteM + sideExtra) * priceSide;
        totalProfileCost += sideCost;
        
        // Add side profile row
        if (profBody && sideUsedM > 0) {
          const tr = document.createElement('tr');
          tr.innerHTML = `
            <td>Yan Profillər</td>
            <td>${sideUsedM.toFixed(3)} m</td>
            <td>${unitLenM} m</td>
            <td>${sidePanels}</td>
            <td>${sideWasteM.toFixed(3)} m</td>
            <td class="profile-cost-cell">${sideCost.toFixed(2)} AZN</td>
          `;
          profBody.appendChild(tr);
        }
        
        // 2. BQ Handle profiles
        const bqData = packing.bqHandle;
        const bqUsedM = (bqData.totalUsedCm || 0) / 100; // cm -> m
        const bqPanels = bqData.panelsCount || 0;
        const bqWasteM = (bqData.totalWasteCm || 0) / 100; // cm -> m
        const bqCost = (bqUsedM + bqWasteM) * priceHandle;
        totalProfileCost += bqCost;
        
        // Add BQ handle row if any used
        if (profBody && bqUsedM > 0) {
          const tr = document.createElement('tr');
          tr.innerHTML = `
            <td>BQ Qulp Profili</td>
            <td>${bqUsedM.toFixed(3)} m</td>
            <td>${unitLenM} m</td>
            <td>${bqPanels}</td>
            <td>${bqWasteM.toFixed(3)} m</td>
            <td class="profile-cost-cell">${bqCost.toFixed(2)} AZN</td>
          `;
          profBody.appendChild(tr);
        }
        
        // 3. Qulp 110 profiles
        const qulp110Data = packing.qulp110;
        const qulp110Used = 1.1 * qulp110Data.panelsCount; // 110cm = 1.1m per panel
        const qulp110Panels = qulp110Data.panelsCount || 0;
        const qulp110WasteM = qulp110Panels * (unitLenM - 1.1); // Each 110cm handle wastes (300-110)cm
        const qulp110Cost = qulp110Used * priceHandle;
        totalProfileCost += qulp110Cost;
        
        // Add Qulp 110 row if any used
        if (profBody && qulp110Panels > 0) {
          const tr = document.createElement('tr');
          tr.innerHTML = `
            <td>Qulp 110 Profili</td>
            <td>${qulp110Used.toFixed(3)} m</td>
            <td>${unitLenM} m</td>
            <td>${qulp110Panels}</td>
            <td>${qulp110WasteM.toFixed(3)} m</td>
            <td class="profile-cost-cell">${qulp110Cost.toFixed(2)} AZN</td>
          `;
          profBody.appendChild(tr);
        }
        
        // 4. Qulp 20 profiles
        const qulp20Data = packing.qulp20;
        const qulp20Used = 0.2 * qulp20Data.panelsCount; // 20cm = 0.2m per panel
        const qulp20Panels = qulp20Data.panelsCount || 0;
        const qulp20WasteM = qulp20Panels * (unitLenM - 0.2); // Each 20cm handle wastes (300-20)cm
        const qulp20Cost = qulp20Used * priceHandle;
        totalProfileCost += qulp20Cost;
        
        // Add Qulp 20 row if any used
        if (profBody && qulp20Panels > 0) {
          const tr = document.createElement('tr');
          tr.innerHTML = `
            <td>Qulp 20 Profili</td>
            <td>${qulp20Used.toFixed(3)} m</td>
            <td>${unitLenM} m</td>
            <td>${qulp20Panels}</td>
            <td>${qulp20WasteM.toFixed(3)} m</td>
            <td class="profile-cost-cell">${qulp20Cost.toFixed(2)} AZN</td>
          `;
          profBody.appendChild(tr);
        }
        
        // Empty profile table fallback
        if (profBody && profBody.children.length === 0) {
          profBody.innerHTML = '<tr><td colspan="6" style="text-align:center">Hesablanacaq profil yoxdur</td></tr>';
        }

        // Glass calculations
        const rows = document.querySelectorAll('#input-table-body tr');
        const glassTotals = {};
        
        rows.forEach(r => {
          const glassType = r.querySelector('.glass-type')?.value || '';
          const height = Number(r.querySelector('.height-input')?.value) || 0;
          const width = Number(r.querySelector('.width-input')?.value) || 0;
          const count = Number(r.querySelector('.count-input')?.value) || 0;
          const offsetMm = Number(r.querySelector('.offset-type')?.value) || 0;
          
          if (!(height > 0 && width > 0 && count > 0)) return;
          
          if (glassType !== 'Susesiz') {
            const reduction = offsetMm / 10; // mm -> cm
            const glassW = Math.max(0, width - reduction);
            const glassH = Math.max(0, height - reduction);
            const area = (glassW / 100) * (glassH / 100) * count; // m²
            glassTotals[glassType] = (glassTotals[glassType] || 0) + area;
          }
        });

        // Fill glass table
        const glassBody = byId('calc-glass')?.querySelector('tbody');
        if (glassBody) glassBody.innerHTML = '';
        
        let totalGlassCost = 0;
        
        Object.keys(glassTotals).forEach(type => {
          const area = glassTotals[type];
          const cost = area * glassPrice;
          totalGlassCost += cost;
          
          if (glassBody) {
            glassBody.innerHTML += `<tr>
              <td>${type}</td>
              <td>${area.toFixed(3)} m²</td>
              <td>${glassPrice.toFixed(2)} AZN/m²</td>
              <td>${cost.toFixed(2)} AZN</td>
            </tr>`;
          }
        });
        
        // Empty glass table fallback
        if (glassBody && Object.keys(glassTotals).length === 0) {
          glassBody.innerHTML = '<tr><td colspan="4" style="text-align:center">Şüşə yoxdur</td></tr>';
        }

        // Additional items calculation
        const modalHingeCount = Number(byId('calc-hinge-count-modal')?.value) || totalHinges;
        const modalConnCount = Number(byId('calc-conn-count-modal')?.value) || (4 * totalDoors);
        const modalMechCount = Number(byId('calc-mech-count-modal')?.value) || totalDoors;
        const modalTransport = Number(byId('calc-transport-modal')?.value) || 0;
        
        const hingePrice = getNum('param-hinge-price', 2.5);
        const connPrice = getNum('param-conn-price', 1.2);
        
        // Calculate costs
        const hingeCost = modalHingeCount * hingePrice;
        const connCost = modalConnCount * connPrice;
        const mechCost = modalMechCount * mechPrice;
        
        // Update totals in summary
        setText('calc-profile-total', totalProfileCost.toFixed(2) + ' AZN');
        setText('calc-glass-total', totalGlassCost.toFixed(2) + ' AZN');
        setText('calc-hinge-price', hingeCost.toFixed(2) + ' AZN');
        setText('calc-conn-price', connCost.toFixed(2) + ' AZN');
        setText('calc-mech-price', mechCost.toFixed(2) + ' AZN');
        setText('calc-transport-price', modalTransport.toFixed(2) + ' AZN');
        setText('calc-assembly-price', assemblyFee.toFixed(2) + ' AZN');
        setText('calc-advance-payment', advancePayment.toFixed(2) + ' AZN');

        // Calculate grand total and remaining amount
        const grandTotal = totalProfileCost + totalGlassCost + hingeCost + connCost + mechCost + modalTransport + assemblyFee;
        const remainingAmount = grandTotal - advancePayment;
        
        // Update UI
        setText('calc-total', grandTotal.toFixed(2) + ' AZN');
        setText('calc-remaining', remainingAmount.toFixed(2) + ' AZN');
        
        // Update hidden fields for form submission
        byId('total-amount').value = grandTotal.toFixed(2);
        byId('remaining-amount').value = remainingAmount.toFixed(2);
        
        // Store profile and glass data for database
        const profileData = {
          side: {
            length: sideUsedM,
            panels: sidePanels,
            waste: sideWasteM,
            cost: sideCost
          },
          handle: {
            bq: { length: bqUsedM, panels: bqPanels, waste: bqWasteM, cost: bqCost },
            qulp110: { length: qulp110Used, panels: qulp110Panels, waste: qulp110WasteM, cost: qulp110Cost },
            qulp20: { length: qulp20Used, panels: qulp20Panels, waste: qulp20WasteM, cost: qulp20Cost }
          }
        };
        
        const glassData = Object.keys(glassTotals).map(type => ({
          type: type,
          area: glassTotals[type],
          cost: glassTotals[type] * glassPrice
        }));
        
        byId('profile-data').value = JSON.stringify(profileData);
        byId('glass-data').value = JSON.stringify(glassData);
        
        // Save drawing canvas data if available
        if (window.drawingCanvas && drawingCanvas.isVisible) {
          const canvas = byId('drawing-canvas');
          if (canvas) {
            byId('drawing-data').value = canvas.toDataURL();
          }
        }
      } catch (err) {
        console.error('Modal calculation error:', err);
        alert('Hesablama zamanı xəta baş verdi: ' + (err?.message || String(err)));
      }
    }

    // Customer name dynamic width
    (function() {
      const input = byId('customer-name');
      const measureSpan = byId('__measure_span');
      
      if (!input || !measureSpan) return;
      
      function copyStyle() {
        const cs = window.getComputedStyle(input);
        measureSpan.style.font = cs.font;
        measureSpan.style.fontSize = cs.fontSize;
        measureSpan.style.fontFamily = cs.fontFamily;
        measureSpan.style.fontWeight = cs.fontWeight;
      }
      
      function adjustWidth() {
        copyStyle();
        const value = input.value || input.placeholder || '';
        measureSpan.textContent = value;
        const width = Math.ceil(measureSpan.getBoundingClientRect().width) + 28;
        const parentWidth = input.closest('.top-field')?.getBoundingClientRect().width || window.innerWidth * 0.6;
        const maxWidth = Math.min(parentWidth - 40, window.innerWidth * 0.6);
        input.style.width = Math.max(120, Math.min(width, maxWidth)) + 'px';
      }
      
      input.addEventListener('input', adjustWidth);
      window.addEventListener('resize', adjustWidth);
      setTimeout(adjustWidth, 50);
    })();

    // Customer autocomplete
    function setupCustomerAutocomplete() {
      const input = byId('customer-name');
      const autocompleteContainer = byId('customer-autocomplete');
      const customerId = byId('customer-id');
      
      if (!input || !autocompleteContainer || !customerId) return;
      
      let currentFocus = -1;
      
      // Function to get customer data
      async function fetchCustomers(search) {
        try {
          const response = await fetch(`../api/customers.php?search=${encodeURIComponent(search)}`);
          if (!response.ok) throw new Error('Network response was not ok');
          return await response.json();
        } catch (error) {
          console.error('Error fetching customers:', error);
          return [];
        }
      }
      
      // Show autocomplete results
      async function showAutocomplete() {
        const search = input.value.trim();
        if (search.length < 2) {
          autocompleteContainer.innerHTML = '';
          return;
        }
        
        const customers = await fetchCustomers(search);
        
        autocompleteContainer.innerHTML = '';
        
        if (customers.length === 0) {
          const div = document.createElement('div');
          div.textContent = 'Nəticə tapılmadı';
          div.style.fontStyle = 'italic';
          div.style.color = '#777';
          autocompleteContainer.appendChild(div);
          return;
        }
        
        customers.forEach(customer => {
          const div = document.createElement('div');
          div.innerHTML = `<strong>${customer.fullname}</strong> <small>${customer.phone}</small>`;
          div.dataset.id = customer.id;
          div.dataset.name = customer.fullname;
          
          div.addEventListener('click', function() {
            input.value = this.dataset.name;
            customerId.value = this.dataset.id;
            autocompleteContainer.innerHTML = '';
            
            // Update modal
            setText('modal-customer', input.value);
            byId('modal-customer-id').value = customerId.value;
          });
          
          autocompleteContainer.appendChild(div);
        });
      }
      
      // Handle keyboard navigation
      function keydownHandler(e) {
        const items = autocompleteContainer.getElementsByTagName('div');
        
        if (e.key === 'ArrowDown') {
          currentFocus++;
          addActive(items);
          e.preventDefault();
        } else if (e.key === 'ArrowUp') {
          currentFocus--;
          addActive(items);
          e.preventDefault();
        } else if (e.key === 'Enter') {
          e.preventDefault();
          if (currentFocus > -1 && items[currentFocus]) {
            items[currentFocus].click();
          }
        }
      }
      
      // Add active class to current focus item
      function addActive(items) {
        if (!items || !items.length) return;
        
        removeActive(items);
        
        if (currentFocus >= items.length) currentFocus = 0;
        if (currentFocus < 0) currentFocus = items.length - 1;
        
        items[currentFocus].classList.add('autocomplete-active');
      }
      
      // Remove active class from all items
      function removeActive(items) {
        for (let i = 0; i < items.length; i++) {
          items[i].classList.remove('autocomplete-active');
        }
      }
      
      // Close autocomplete when clicking outside
      document.addEventListener('click', function(e) {
        if (e.target !== input) {
          autocompleteContainer.innerHTML = '';
        }
      });
      
      // Setup event listeners
      input.addEventListener('input', showAutocomplete);
      input.addEventListener('keydown', keydownHandler);
    }

    // Drawing canvas functionality
    class DrawingCanvas {
      constructor(canvasId) {
        this.canvas = document.getElementById(canvasId);
        this.ctx = this.canvas.getContext('2d');
        this.isDrawing = false;
        this.isVisible = false;
        this.startX = 0;
        this.startY = 0;
        this.shapes = [];
        this.undoStack = [];
        this.currentTool = 'pencil';
        this.currentColor = '#000000';
        this.lineWidth = 3;
        this.selectedShape = null;
        this.dragStartX = 0;
        this.dragStartY = 0;
        this.textInput = document.getElementById('text-input');
        this.fontSize = 16;
        this.fontFamily = 'Arial';
        
        this.setupEventListeners();
        this.resizeCanvas();
      }
      
      setupEventListeners() {
        const canvas = this.canvas;
        
        // Mouse events
        canvas.addEventListener('mousedown', this.handleMouseDown.bind(this));
        canvas.addEventListener('mousemove', this.handleMouseMove.bind(this));
        canvas.addEventListener('mouseup', this.handleMouseUp.bind(this));
        canvas.addEventListener('mouseout', this.handleMouseUp.bind(this));
        
        // Touch events for mobile
        canvas.addEventListener('touchstart', this.handleTouchStart.bind(this));
        canvas.addEventListener('touchmove', this.handleTouchMove.bind(this));
        canvas.addEventListener('touchend', this.handleTouchEnd.bind(this));
        
        // Text input events
        this.textInput.addEventListener('blur', this.finalizeText.bind(this));
        this.textInput.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            this.finalizeText();
          }
        });
        
        // Window resize
        window.addEventListener('resize', this.resizeCanvas.bind(this));
        
        // Tool selection
        document.querySelectorAll('.drawing-tool').forEach(tool => {
          tool.addEventListener('click', (e) => {
            if (e.currentTarget.id === 'undo-tool') {
              this.undo();
              return;
            }
            
            if (e.currentTarget.id === 'clear-canvas') {
              if (confirm('Bütün çəkilənlər silinsin?')) {
                this.clearCanvas();
              }
              return;
            }
            
            document.querySelectorAll('.drawing-tool').forEach(t => t.classList.remove('active'));
            e.currentTarget.classList.add('active');
            this.currentTool = e.currentTarget.id.replace('-tool', '');
          });
        });
        
        // Color picker
        document.getElementById('color-picker').addEventListener('input', (e) => {
          this.currentColor = e.target.value;
        });
        
        // Size slider
        const sizeSlider = document.getElementById('size-slider');
        const sizeValue = document.getElementById('size-value');
        sizeSlider.addEventListener('input', (e) => {
          this.lineWidth = parseInt(e.target.value);
          sizeValue.textContent = `${this.lineWidth}px`;
        });
        
        // Toggle drawing button
        document.getElementById('toggle-drawing').addEventListener('click', () => {
          const drawingContainer = document.getElementById('drawing-container');
          
          if (drawingContainer.style.display === 'block') {
            drawingContainer.style.display = 'none';
            this.isVisible = false;
          } else {
            drawingContainer.style.display = 'block';
            this.isVisible = true;
            this.resizeCanvas();
            this.redraw();
          }
        });
      }
      
      resizeCanvas() {
        const container = this.canvas.parentElement;
        this.canvas.width = container.clientWidth;
        this.canvas.height = 300;
        this.redraw();
      }
      
      handleMouseDown(e) {
        const rect = this.canvas.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;
        
        this.isDrawing = true;
        this.startX = x;
        this.startY = y;
        
        if (this.currentTool === 'pencil') {
          this.shapes.push({
            type: 'pencil',
            color: this.currentColor,
            lineWidth: this.lineWidth,
            points: [{x, y}]
          });
        } else if (this.currentTool === 'text') {
          // Position text input at click location
          this.textInput.style.left = `${e.clientX}px`;
          this.textInput.style.top = `${e.clientY}px`;
          this.textInput.style.display = 'block';
          this.textInput.style.color = this.currentColor;
          this.textInput.style.fontSize = `${this.fontSize}px`;
          this.textInput.style.fontFamily = this.fontFamily;
          this.textInput.focus();
        } else if (this.currentTool === 'select') {
          // Check if clicked on any shape
          let selectedIndex = -1;
          
          // Reverse loop to check top shapes first
          for (let i = this.shapes.length - 1; i >= 0; i--) {
            const shape = this.shapes[i];
            
            if (this.isPointInShape(x, y, shape)) {
              selectedIndex = i;
              break;
            }
          }
          
          if (selectedIndex >= 0) {
            this.selectedShape = this.shapes[selectedIndex];
            this.dragStartX = x;
            this.dragStartY = y;
            
            // Bring selected shape to front
            this.shapes.splice(selectedIndex, 1);
            this.shapes.push(this.selectedShape);
            
            // Redraw with selection highlight
            this.redraw();
          } else {
            this.selectedShape = null;
          }
        }
      }
      
      handleMouseMove(e) {
        if (!this.isDrawing) return;
        
        const rect = this.canvas.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;
        
        if (this.currentTool === 'pencil') {
          const currentShape = this.shapes[this.shapes.length - 1];
          currentShape.points.push({x, y});
          
          // Draw the current line segment
          this.ctx.strokeStyle = currentShape.color;
          this.ctx.lineWidth = currentShape.lineWidth;
          this.ctx.lineCap = 'round';
          this.ctx.lineJoin = 'round';
          
          const lastPoint = currentShape.points[currentShape.points.length - 2];
          this.ctx.beginPath();
          this.ctx.moveTo(lastPoint.x, lastPoint.y);
          this.ctx.lineTo(x, y);
          this.ctx.stroke();
        } else if (this.currentTool === 'select' && this.selectedShape) {
          // Calculate offset and move the selected shape
          const dx = x - this.dragStartX;
          const dy = y - this.dragStartY;
          
          this.moveShape(this.selectedShape, dx, dy);
          this.dragStartX = x;
          this.dragStartY = y;
          
          // Redraw with moved shape
          this.redraw();
        } else {
          // For other tools, just redraw preview
          this.redraw();
          
          // Draw shape preview
          this.ctx.strokeStyle = this.currentColor;
          this.ctx.fillStyle = this.currentColor;
          this.ctx.lineWidth = this.lineWidth;
          
          if (this.currentTool === 'line') {
            this.ctx.beginPath();
            this.ctx.moveTo(this.startX, this.startY);
            this.ctx.lineTo(x, y);
            this.ctx.stroke();
          } else if (this.currentTool === 'rect') {
            const width = x - this.startX;
            const height = y - this.startY;
            this.ctx.strokeRect(this.startX, this.startY, width, height);
          } else if (this.currentTool === 'circle') {
            const radius = Math.sqrt(Math.pow(x - this.startX, 2) + Math.pow(y - this.startY, 2));
            this.ctx.beginPath();
            this.ctx.arc(this.startX, this.startY, radius, 0, 2 * Math.PI);
            this.ctx.stroke();
          } else if (this.currentTool === 'arrow') {
            this.drawArrow(this.ctx, this.startX, this.startY, x, y, this.lineWidth);
          }
        }
      }
      
      handleMouseUp(e) {
        if (!this.isDrawing) return;
        
        this.isDrawing = false;
        
        // Don't add shapes for text or select tools
        if (this.currentTool === 'text' || this.currentTool === 'select') {
          return;
        }
        
        const rect = this.canvas.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;
        
        if (this.currentTool === 'pencil') {
          // Pencil shape is already being updated during mousemove
        } else if (this.currentTool === 'line') {
          this.shapes.push({
            type: 'line',
            startX: this.startX,
            startY: this.startY,
            endX: x,
            endY: y,
            color: this.currentColor,
            lineWidth: this.lineWidth
          });
        } else if (this.currentTool === 'rect') {
          this.shapes.push({
            type: 'rect',
            x: this.startX,
            y: this.startY,
            width: x - this.startX,
            height: y - this.startY,
            color: this.currentColor,
            lineWidth: this.lineWidth
          });
        } else if (this.currentTool === 'circle') {
          const radius = Math.sqrt(Math.pow(x - this.startX, 2) + Math.pow(y - this.startY, 2));
          this.shapes.push({
            type: 'circle',
            x: this.startX,
            y: this.startY,
            radius: radius,
            color: this.currentColor,
            lineWidth: this.lineWidth
          });
        } else if (this.currentTool === 'arrow') {
          this.shapes.push({
            type: 'arrow',
            startX: this.startX,
            startY: this.startY,
            endX: x,
            endY: y,
            color: this.currentColor,
            lineWidth: this.lineWidth
          });
        }
        
        this.redraw();
        
        // Save state for undo
        this.saveToUndoStack();
      }
      
      handleTouchStart(e) {
        if (e.touches.length === 1) {
          e.preventDefault();
          const touch = e.touches[0];
          const mouseEvent = new MouseEvent('mousedown', {
            clientX: touch.clientX,
            clientY: touch.clientY
          });
          this.canvas.dispatchEvent(mouseEvent);
        }
      }
      
      handleTouchMove(e) {
        if (e.touches.length === 1) {
          e.preventDefault();
          const touch = e.touches[0];
          const mouseEvent = new MouseEvent('mousemove', {
            clientX: touch.clientX,
            clientY: touch.clientY
          });
          this.canvas.dispatchEvent(mouseEvent);
        }
      }
      
      handleTouchEnd(e) {
        const mouseEvent = new MouseEvent('mouseup', {});
        this.canvas.dispatchEvent(mouseEvent);
      }
      
      finalizeText() {
        if (this.textInput.style.display === 'block' && this.textInput.textContent.trim() !== '') {
          const rect = this.canvas.getBoundingClientRect();
          const x = parseInt(this.textInput.style.left) - rect.left;
          const y = parseInt(this.textInput.style.top) - rect.top;
          
          this.shapes.push({
            type: 'text',
            x: x,
            y: y,
            text: this.textInput.textContent,
            color: this.currentColor,
            fontSize: this.fontSize,
            fontFamily: this.fontFamily
          });
          
          this.textInput.textContent = '';
          this.textInput.style.display = 'none';
          
          this.redraw();
          
          // Save state for undo
          this.saveToUndoStack();
        } else {
          this.textInput.textContent = '';
          this.textInput.style.display = 'none';
        }
      }
      
      redraw() {
        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
        
        // Draw all shapes
        for (const shape of this.shapes) {
          this.drawShape(shape);
        }
        
        // Draw selection indicator if a shape is selected
        if (this.selectedShape) {
          this.drawSelectionIndicator(this.selectedShape);
        }
      }
      
      drawShape(shape) {
        this.ctx.strokeStyle = shape.color;
        this.ctx.fillStyle = shape.color;
        this.ctx.lineWidth = shape.lineWidth;
        
        switch (shape.type) {
          case 'pencil':
            if (shape.points.length < 2) return;
            this.ctx.beginPath();
            this.ctx.moveTo(shape.points[0].x, shape.points[0].y);
            for (let i = 1; i < shape.points.length; i++) {
              this.ctx.lineTo(shape.points[i].x, shape.points[i].y);
            }
            this.ctx.stroke();
            break;
            
          case 'line':
            this.ctx.beginPath();
            this.ctx.moveTo(shape.startX, shape.startY);
            this.ctx.lineTo(shape.endX, shape.endY);
            this.ctx.stroke();
            break;
            
          case 'rect':
            this.ctx.strokeRect(shape.x, shape.y, shape.width, shape.height);
            break;
            
          case 'circle':
            this.ctx.beginPath();
            this.ctx.arc(shape.x, shape.y, shape.radius, 0, 2 * Math.PI);
            this.ctx.stroke();
            break;
            
          case 'text':
            this.ctx.font = `${shape.fontSize}px ${shape.fontFamily}`;
            this.ctx.fillText(shape.text, shape.x, shape.y + shape.fontSize);
            break;
            
          case 'arrow':
            this.drawArrow(this.ctx, shape.startX, shape.startY, shape.endX, shape.endY, shape.lineWidth);
            break;
        }
      }
      
      drawArrow(ctx, fromX, fromY, toX, toY, width) {
        // Calculate the angle of the line
        const angle = Math.atan2(toY - fromY, toX - fromX);
        
        // Length of the arrow head
        const headLength = 10 + width;
        
        // Draw main line
        ctx.beginPath();
        ctx.moveTo(fromX, fromY);
        ctx.lineTo(toX, toY);
        ctx.stroke();
        
        // Draw arrow head
        ctx.beginPath();
        ctx.moveTo(toX, toY);
        ctx.lineTo(
          toX - headLength * Math.cos(angle - Math.PI / 6),
          toY - headLength * Math.sin(angle - Math.PI / 6)
        );
        ctx.lineTo(
          toX - headLength * Math.cos(angle + Math.PI / 6),
          toY - headLength * Math.sin(angle + Math.PI / 6)
        );
        ctx.closePath();
        ctx.fill();
      }
      
      drawSelectionIndicator(shape) {
        this.ctx.strokeStyle = '#4285F4';
        this.ctx.lineWidth = 2;
        this.ctx.setLineDash([5, 3]);
        
        switch (shape.type) {
          case 'pencil':
            // Find bounding box for pencil points
            let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
            for (const point of shape.points) {
              minX = Math.min(minX, point.x);
              minY = Math.min(minY, point.y);
              maxX = Math.max(maxX, point.x);
              maxY = Math.max(maxY, point.y);
            }
            this.ctx.strokeRect(minX - 5, minY - 5, maxX - minX + 10, maxY - minY + 10);
            break;
            
          case 'line':
          case 'arrow':
            // Draw rectangle around the line
            const angle = Math.atan2(shape.endY - shape.startY, shape.endX - shape.startX);
            const padding = 5;
            
            this.ctx.beginPath();
            this.ctx.moveTo(shape.startX - padding * Math.sin(angle), shape.startY + padding * Math.cos(angle));
            this.ctx.lineTo(shape.endX - padding * Math.sin(angle), shape.endY + padding * Math.cos(angle));
            this.ctx.lineTo(shape.endX + padding * Math.sin(angle), shape.endY - padding * Math.cos(angle));
            this.ctx.lineTo(shape.startX + padding * Math.sin(angle), shape.startY - padding * Math.cos(angle));
            this.ctx.closePath();
            this.ctx.stroke();
            break;
            
          case 'rect':
            this.ctx.strokeRect(shape.x - 5, shape.y - 5, shape.width + 10, shape.height + 10);
            break;
            
          case 'circle':
            this.ctx.beginPath();
            this.ctx.arc(shape.x, shape.y, shape.radius + 5, 0, 2 * Math.PI);
            this.ctx.stroke();
            break;
            
          case 'text':
            const textWidth = this.ctx.measureText(shape.text).width;
            this.ctx.strokeRect(shape.x - 5, shape.y - 5, textWidth + 10, shape.fontSize + 10);
            break;
        }
        
        this.ctx.setLineDash([]);
      }
      
      isPointInShape(x, y, shape) {
        switch (shape.type) {
          case 'pencil':
            // Check distance to any point in the pencil path
            for (const point of shape.points) {
              const distance = Math.sqrt(Math.pow(x - point.x, 2) + Math.pow(y - point.y, 2));
              if (distance <= shape.lineWidth + 5) return true;
            }
            return false;
            
          case 'line':
          case 'arrow':
            // Check distance to the line
            const A = y - shape.startY;
            const B = shape.endX - shape.startX;
            const C = shape.startX - x;
            const D = shape.endY - shape.startY;
            
            // Distance from point to line
            const distance = Math.abs(A * B + C * D) / Math.sqrt(B * B + D * D);
            
            // Check if point is close to the line and within the line segment bounds
            return distance <= shape.lineWidth + 5 && 
                  x >= Math.min(shape.startX, shape.endX) - (shape.lineWidth + 5) &&
                  x <= Math.max(shape.startX, shape.endX) + (shape.lineWidth + 5) &&
                  y >= Math.min(shape.startY, shape.endY) - (shape.lineWidth + 5) &&
                  y <= Math.max(shape.startY, shape.endY) + (shape.lineWidth + 5);
            
          case 'rect':
            // Check if point is inside rectangle with some padding
            return x >= shape.x - (shape.lineWidth + 5) &&
                  x <= shape.x + shape.width + (shape.lineWidth + 5) &&
                  y >= shape.y - (shape.lineWidth + 5) &&
                  y <= shape.y + shape.height + (shape.lineWidth + 5);
            
          case 'circle':
            // Check distance to center vs radius
            const dist = Math.sqrt(Math.pow(x - shape.x, 2) + Math.pow(y - shape.y, 2));
            return Math.abs(dist - shape.radius) <= shape.lineWidth + 5;
            
          case 'text':
            // Check if point is inside text bounding box
            const textWidth = this.ctx.measureText(shape.text).width;
            return x >= shape.x - 5 &&
                  x <= shape.x + textWidth + 5 &&
                  y >= shape.y - 5 &&
                  y <= shape.y + shape.fontSize + 5;
            
          default:
            return false;
        }
      }
      
      moveShape(shape, dx, dy) {
        switch (shape.type) {
          case 'pencil':
            // Move all points
            for (const point of shape.points) {
              point.x += dx;
              point.y += dy;
            }
            break;
            
          case 'line':
          case 'arrow':
            shape.startX += dx;
            shape.startY += dy;
            shape.endX += dx;
            shape.endY += dy;
            break;
            
          case 'rect':
            shape.x += dx;
            shape.y += dy;
            break;
            
          case 'circle':
            shape.x += dx;
            shape.y += dy;
            break;
            
          case 'text':
            shape.x += dx;
            shape.y += dy;
            break;
        }
      }
      
      saveToUndoStack() {
        // Deep clone the shapes array
        this.undoStack.push(JSON.parse(JSON.stringify(this.shapes)));
        
        // Limit undo stack size
        if (this.undoStack.length > 20) {
          this.undoStack.shift();
        }
      }
      
      undo() {
        if (this.undoStack.length > 0) {
          // Pop the current state and get the previous state
          this.undoStack.pop();
          
          if (this.undoStack.length > 0) {
            this.shapes = JSON.parse(JSON.stringify(this.undoStack[this.undoStack.length - 1]));
          } else {
            this.shapes = [];
          }
          
          this.redraw();
        }
      }
      
      clearCanvas() {
        this.saveToUndoStack();
        this.shapes = [];
        this.redraw();
      }
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
      // Add initial row if none exists
      if (document.querySelectorAll('#input-table-body tr').length === 0) {
        addNewRow();
      }
      
      // Setup show/hide seller notes
      const showNotesCheck = byId('show-seller-notes');
      if (showNotesCheck) {
        showNotesCheck.addEventListener('change', function() {
          const wrapper = byId('seller-notes-wrapper');
          const title = byId('seller-notes-title');
          
          if (this.checked) {
            if (wrapper) wrapper.style.display = '';
            if (title) title.style.display = '';
          } else {
            if (wrapper) wrapper.style.display = 'none';
            if (title) title.style.display = 'none';
          }
        });
      }
      
      // Initialize drawing canvas
      window.drawingCanvas = new DrawingCanvas('drawing-canvas');
      
      // Initialize customer autocomplete
      setupCustomerAutocomplete();
      
      // Make barcode responsive
      window.addEventListener('resize', updateBarcode);
      
      // Set up form submission handling
      const saveOrderForm = byId('save-order-form');
      if (saveOrderForm) {
        saveOrderForm.addEventListener('submit', function(event) {
          // Validate form
          const customerId = byId('modal-customer-id').value;
          if (!customerId) {
            event.preventDefault();
            alert('Zəhmət olmasa müştəri seçin');
            return false;
          }
          
          // Get all product data for validation
          const rows = document.querySelectorAll('#input-table-body tr');
          let hasValidProducts = false;
          
          rows.forEach(r => {
            const height = Number(r.querySelector('.height-input')?.value) || 0;
            const width = Number(r.querySelector('.width-input')?.value) || 0;
            const count = Number(r.querySelector('.count-input')?.value) || 0;
            
            if (height > 0 && width > 0 && count > 0) {
              hasValidProducts = true;
            }
          });
          
          if (!hasValidProducts) {
            event.preventDefault();
            alert('Zəhmət olmasa ən azı bir düzgün ölçülü məhsul daxil edin');
            return false;
          }
          
          // Update calculation
          recalculateModal();
          
          return true;
        });
      }
    });
  </script>
</body>
</html>