    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .card {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 0;
        }
        
        .form-col {
            flex: 1;
        }
        
        .switch-container {
            display: flex;
            align-items: center;
            margin-top: 20px;
        }
        
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
            margin-right: 10px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: var(--primary-color);
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 0;
            }
        }
    </style>
</head>
<body>
    <!-- App Header -->
    <header class="app-header">
        <div class="header-left">
            <div class="logo">ALUMPRO.AZ</div>
            <div class="nav-links">
                <a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="customers.php" class="active"><i class="fas fa-users"></i> Müştərilər</a>
                <a href="orders.php"><i class="fas fa-clipboard-list"></i> Sifarişlər</a>
                <a href="hesabla.php"><i class="fas fa-calculator"></i> Hesabla</a>
                <a href="warehouse.php"><i class="fas fa-warehouse"></i> Anbar</a>
            </div>
        </div>
        <div class="header-right">
            <a href="messages.php" class="nav-link position-relative">
                <i class="fas fa-envelope"></i>
                <?php if($unreadMessages > 0): ?>
                    <span class="notification-badge"><?= $unreadMessages ?></span>
                <?php endif; ?>
            </a>
            <div class="user-info">
                <span><?= htmlspecialchars($sellerName) ?> <i class="fas fa-angle-down"></i></span>
                <div class="user-menu">
                    <a href="profile.php"><i class="fas fa-user-cog"></i> Profil</a>
                    <a href="../auth/logout.php"><i class="fas fa-sign-out-alt"></i> Çıxış</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="app-main">
        <div class="app-container">
            <div class="page-header">
                <h1><i class="fas fa-user-plus"></i> Yeni Müştəri</h1>
                <div class="breadcrumb">
                    <a href="index.php">Dashboard</a> / 
                    <a href="customers.php">Müştərilər</a> / 
                    <span>Yeni Müştəri</span>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Müştəri Məlumatları</h2>
                </div>
                <div class="card-body">
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($success)): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
                        </div>
                    <?php endif; ?>
                    
                    <form action="" method="post">
                        <div class="form-row">
                            <div class="form-col">
                                <div class="form-group">
                                    <label for="fullname" class="form-label">Ad Soyad <span class="text-danger">*</span></label>
                                    <input type="text" id="fullname" name="fullname" class="form-control" value="<?= isset($_POST['fullname']) ? htmlspecialchars($_POST['fullname']) : '' ?>" required>
                                </div>
                            </div>
                            <div class="form-col">
                                <div class="form-group">
                                    <label for="phone" class="form-label">Telefon <span class="text-danger">*</span></label>
                                    <input type="tel" id="phone" name="phone" class="form-control" placeholder="+994 XX XXX XX XX" value="<?= isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : '' ?>" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-col">
                                <div class="form-group">
                                    <label for="email" class="form-label">E-poçt</label>
                                    <input type="email" id="email" name="email" class="form-control" value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                                    <div class="form-text">Hesab yaratmaq istəyirsinizsə e-poçt tələb olunur</div>
                                </div>
                            </div>
                            <div class="form-col">
                                <div class="form-group">
                                    <label for="company" class="form-label">Şirkət</label>
                                    <input type="text" id="company" name="company" class="form-control" value="<?= isset($_POST['company']) ? htmlspecialchars($_POST['company']) : '' ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="address" class="form-label">Ünvan</label>
                            <input type="text" id="address" name="address" class="form-control" value="<?= isset($_POST['address']) ? htmlspecialchars($_POST['address']) : '' ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="notes" class="form-label">Qeydlər</label>
                            <textarea id="notes" name="notes" class="form-control" rows="3"><?= isset($_POST['notes']) ? htmlspecialchars($_POST['notes']) : '' ?></textarea>
                        </div>
                        
                        <div class="switch-container">
                            <label class="switch">
                                <input type="checkbox" name="create_account" value="1" id="create_account" <?= isset($_POST['create_account']) ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                            <label for="create_account">Müştəri üçün hesab yarat</label>
                            <div class="form-text ml-2">(Avtomatik şifrə yaradılıb WhatsApp ilə göndəriləcək)</div>
                        </div>
                        
                        <div class="form-group mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Müştəri Əlavə Et
                            </button>
                            <a href="customers.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Ləğv Et
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="app-footer">
        <div>&copy; <?= date('Y') ?> AlumPro.az - Bütün hüquqlar qorunur</div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // User menu toggle
            const userInfo = document.querySelector('.user-info');
            userInfo.addEventListener('click', function() {
                this.classList.toggle('open');
            });
            
            // Email field requirement based on create account checkbox
            const createAccountCheckbox = document.getElementById('create_account');
            const emailField = document.getElementById('email');
            
            createAccountCheckbox.addEventListener('change', function() {
                if (this.checked) {
                    emailField.setAttribute('required', 'required');
                } else {
                    emailField.removeAttribute('required');
                }
            });
            
            // Initialize phone number formatting
            const phoneField = document.getElementById('phone');
            
            phoneField.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                
                if (value.length > 0) {
                    if (value.length <= 3) {
                        value = '+' + value;
                    } else if (value.length <= 5) {
                        value = '+' + value.substring(0, 3) + ' ' + value.substring(3);
                    } else if (value.length <= 8) {
                        value = '+' + value.substring(0, 3) + ' ' + value.substring(3, 5) + ' ' + value.substring(5);
                    } else if (value.length <= 10) {
                        value = '+' + value.substring(0, 3) + ' ' + value.substring(3, 5) + ' ' + value.substring(5, 8) + ' ' + value.substring(8);
                    } else {
                        value = '+' + value.substring(0, 3) + ' ' + value.substring(3, 5) + ' ' + value.substring(5, 8) + ' ' + value.substring(8, 10) + ' ' + value.substring(10);
                    }
                }
                
                e.target.value = value;
            });
        });
    </script>
</body>
</html>