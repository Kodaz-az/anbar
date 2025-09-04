        .user-meta {
            color: #6b7280;
            font-size: 14px;
            display: flex;
            gap: 15px;
        }
        
        .user-role {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            margin-left: 10px;
        }
        
        .role-admin { background: #fee2e2; color: #b91c1c; }
        .role-seller { background: #e0f2fe; color: #0369a1; }
        .role-customer { background: #d1fae5; color: #065f46; }
        .role-production { background: #fef3c7; color: #92400e; }
        
        .activity-item {
            display: flex;
            gap: 15px;
            padding: 15px;
            border-bottom: 1px solid #f3f4f6;
            background-color: white;
            transition: background-color 0.2s;
        }
        
        .activity-item:hover {
            background-color: #f9fafb;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
            flex-shrink: 0;
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-title {
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .activity-meta {
            display: flex;
            gap: 15px;
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .activity-description {
            margin-top: 5px;
            font-size: 14px;
        }
        
        .activity-type {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            margin-right: 10px;
        }
        
        .type-login { background: #e0f2fe; color: #0369a1; }
        .type-logout { background: #f3f4f6; color: #4b5563; }
        .type-register { background: #d1fae5; color: #065f46; }
        .type-update { background: #fef3c7; color: #92400e; }
        .type-delete { background: #fee2e2; color: #b91c1c; }
        .type-create { background: #d1fae5; color: #065f46; }
        .type-default { background: #e5e7eb; color: #374151; }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 20px;
        }
        
        .page-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: var(--border-radius);
            background: white;
            color: #374151;
            text-decoration: none;
            font-weight: 500;
            box-shadow: var(--card-shadow);
        }
        
        .page-link.active {
            background: var(--primary-gradient);
            color: white;
        }
        
        .page-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        @media (max-width: 768px) {
            .filter-container {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-item {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .filter-select, .filter-input {
                width: 100%;
            }
            
            .user-info-card {
                flex-direction: column;
                text-align: center;
            }
            
            .user-meta {
                justify-content: center;
                flex-wrap: wrap;
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
                <a href="index.php"><i class="fas fa-tachometer-alt"></i> Panel</a>
                <a href="users.php"><i class="fas fa-users"></i> İstifadəçilər</a>
                <a href="orders.php"><i class="fas fa-clipboard-list"></i> Sifarişlər</a>
                <a href="inventory.php"><i class="fas fa-boxes"></i> Anbar</a>
                <a href="branches.php"><i class="fas fa-building"></i> Filiallar</a>
                <a href="reports.php"><i class="fas fa-chart-bar"></i> Hesabatlar</a>
                <a href="settings.php"><i class="fas fa-cog"></i> Tənzimləmələr</a>
            </div>
        </div>
        <div class="header-right">
            <div class="user-info">
                <span><?= htmlspecialchars($adminName) ?> <i class="fas fa-angle-down"></i></span>
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
                <h1><i class="fas fa-history"></i> Aktivlik Jurnalı</h1>
                <div class="breadcrumb">
                    <a href="index.php">Panel</a> / 
                    <?php if ($userId > 0 && $userInfo): ?>
                        <a href="users.php">İstifadəçilər</a> / 
                        <a href="user-details.php?id=<?= $userId ?>"><?= htmlspecialchars($userInfo['fullname']) ?></a> / 
                    <?php endif; ?>
                    <span>Aktivlik Jurnalı</span>
                </div>
            </div>
            
            <?php if ($userId > 0 && $userInfo): ?>
                <!-- User Info Card -->
                <div class="user-info-card">
                    <div class="user-avatar">
                        <?= strtoupper(substr($userInfo['fullname'], 0, 1)) ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name">
                            <?= htmlspecialchars($userInfo['fullname']) ?>
                            <span class="user-role role-<?= $userInfo['role'] ?>">
                                <?php
                                    switch ($userInfo['role']) {
                                        case 'admin':
                                            echo 'Administrator';
                                            break;
                                        case 'seller':
                                            echo 'Satıcı';
                                            break;
                                        case 'production':
                                            echo 'İstehsalat';
                                            break;
                                        case 'customer':
                                            echo 'Müştəri';
                                            break;
                                        default:
                                            echo ucfirst($userInfo['role']);
                                    }
                                ?>
                            </span>
                        </div>
                        <div class="user-meta">
                            <div><?= htmlspecialchars($userInfo['email']) ?></div>
                            <div>ID: <?= $userInfo['id'] ?></div>
                            <div>
                                Status: 
                                <?php
                                    switch ($userInfo['status']) {
                                        case 'active':
                                            echo 'Aktiv';
                                            break;
                                        case 'inactive':
                                            echo 'Deaktiv';
                                            break;
                                        case 'suspended':
                                            echo 'Dayandırılmış';
                                            break;
                                        default:
                                            echo ucfirst($userInfo['status']);
                                    }
                                ?>
                            </div>
                        </div>
                    </div>
                    <div>
                        <a href="user-details.php?id=<?= $userId ?>" class="btn btn-outline">
                            <i class="fas fa-user"></i> İstifadəçi Detalları
                        </a>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <form action="" method="get" id="filterForm">
                        <?php if ($userId > 0): ?>
                            <input type="hidden" name="user_id" value="<?= $userId ?>">
                        <?php endif; ?>
                        
                        <div class="filter-container">
                            <?php if ($userId <= 0): ?>
                                <div class="filter-item">
                                    <label class="filter-label">İstifadəçi ID:</label>
                                    <input type="number" name="user_id" class="filter-input" value="<?= $userId > 0 ? $userId : '' ?>" placeholder="ID daxil edin">
                                </div>
                            <?php endif; ?>
                            
                            <div class="filter-item">
                                <label class="filter-label">Aktivlik Növü:</label>
                                <select name="type" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                                    <option value="all" <?= $activityType === 'all' ? 'selected' : '' ?>>Bütün Növlər</option>
                                    <?php foreach ($activityTypes as $type): ?>
                                        <option value="<?= $type ?>" <?= $activityType === $type ? 'selected' : '' ?>>
                                            <?php
                                                $typeText = $type;
                                                switch ($type) {
                                                    case 'login':
                                                        $typeText = 'Giriş';
                                                        break;
                                                    case 'logout':
                                                        $typeText = 'Çıxış';
                                                        break;
                                                    case 'register':
                                                        $typeText = 'Qeydiyyat';
                                                        break;
                                                    case 'profile_update':
                                                        $typeText = 'Profil Yeniləmə';
                                                        break;
                                                    case 'password_change':
                                                        $typeText = 'Şifrə Dəyişmə';
                                                        break;
                                                    case 'order_create':
                                                        $typeText = 'Sifariş Yaratma';
                                                        break;
                                                    case 'order_update':
                                                        $typeText = 'Sifariş Yeniləmə';
                                                        break;
                                                }
                                                echo $typeText;
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-item">
                                <label class="filter-label">Başlanğıc tarix:</label>
                                <input type="date" name="date_start" class="filter-input" value="<?= $dateStart ?>">
                            </div>
                            
                            <div class="filter-item">
                                <label class="filter-label">Son tarix:</label>
                                <input type="date" name="date_end" class="filter-input" value="<?= $dateEnd ?>">
                            </div>
                            
                            <div class="filter-item">
                                <label class="filter-label">Axtar:</label>
                                <input type="text" name="search" class="filter-input" value="<?= htmlspecialchars($search) ?>" placeholder="İstifadəçi, aktivlik və ya açıqlama...">
                            </div>
                            
                            <div class="filter-item">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Tətbiq et
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Activity Log -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Aktivlik Jurnalı (<?= $totalLogs ?>)</h2>
                    <?php if ($totalLogs > 0): ?>
                        <div class="card-actions">
                            <a href="#" onclick="window.print()" class="btn btn-sm btn-outline">
                                <i class="fas fa-print"></i> Çap et
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($logs)): ?>
                        <div class="text-center p-4">
                            <i class="fas fa-info-circle"></i> Seçilmiş parametrlərə uyğun aktivlik tapılmadı.
                        </div>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <?php
                                        $activityIcon = 'fa-info-circle'; // Default
                                        
                                        if (strpos($log['activity_type'], 'login') !== false) {
                                            $activityIcon = 'fa-sign-in-alt';
                                        } elseif (strpos($log['activity_type'], 'logout') !== false) {
                                            $activityIcon = 'fa-sign-out-alt';
                                        } elseif (strpos($log['activity_type'], 'order') !== false) {
                                            $activityIcon = 'fa-clipboard-list';
                                        } elseif (strpos($log['activity_type'], 'profile') !== false) {
                                            $activityIcon = 'fa-user-edit';
                                        } elseif (strpos($log['activity_type'], 'password') !== false) {
                                            $activityIcon = 'fa-key';
                                        } elseif (strpos($log['activity_type'], 'register') !== false) {
                                            $activityIcon = 'fa-user-plus';
                                        } elseif (strpos($log['activity_type'], 'delete') !== false) {
                                            $activityIcon = 'fa-trash';
                                        } elseif (strpos($log['activity_type'], 'create') !== false) {
                                            $activityIcon = 'fa-plus';
                                        } elseif (strpos($log['activity_type'], 'update') !== false) {
                                            $activityIcon = 'fa-edit';
                                        }
                                    ?>
                                    <i class="fas <?= $activityIcon ?>"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">
                                        <?php
                                            $typeClass = 'type-default';
                                            if (strpos($log['activity_type'], 'login') !== false) {
                                                $typeClass = 'type-login';
                                            } elseif (strpos($log['activity_type'], 'logout') !== false) {
                                                $typeClass = 'type-logout';
                                            } elseif (strpos($log['activity_type'], 'register') !== false) {
                                                $typeClass = 'type-register';
                                            } elseif (strpos($log['activity_type'], 'update') !== false || strpos($log['activity_type'], 'edit') !== false) {
                                                $typeClass = 'type-update';
                                            } elseif (strpos($log['activity_type'], 'delete') !== false) {
                                                $typeClass = 'type-delete';
                                            } elseif (strpos($log['activity_type'], 'create') !== false || strpos($log['activity_type'], 'add') !== false) {
                                                $typeClass = 'type-create';
                                            }
                                        ?>
                                        <span class="activity-type <?= $typeClass ?>">
                                            <?php
                                                $typeText = $log['activity_type'];
                                                switch ($log['activity_type']) {
                                                    case 'login':
                                                        $typeText = 'Giriş';
                                                        break;
                                                    case 'logout':
                                                        $typeText = 'Çıxış';
                                                        break;
                                                    case 'register':
                                                        $typeText = 'Qeydiyyat';
                                                        break;
                                                    case 'profile_update':
                                                        $typeText = 'Profil Yeniləmə';
                                                        break;
                                                    case 'password_change':
                                                        $typeText = 'Şifrə Dəyişmə';
                                                        break;
                                                    case 'order_create':
                                                        $typeText = 'Sifariş Yaratma';
                                                        break;
                                                    case 'order_update':
                                                        $typeText = 'Sifariş Yeniləmə';
                                                        break;
                                                    case 'order_status_change':
                                                        $typeText = 'Sifariş Status Dəyişmə';
                                                        break;
                                                    case 'inventory_add':
                                                        $typeText = 'Anbara Əlavə';
                                                        break;
                                                    case 'inventory_remove':
                                                        $typeText = 'Anbardan Çıxarma';
                                                        break;
                                                    case 'customer_add':
                                                        $typeText = 'Müştəri Əlavə';
                                                        break;
                                                    case 'customer_update':
                                                        $typeText = 'Müştəri Yeniləmə';
                                                        break;
                                                }
                                                echo $typeText;
                                            ?>
                                        </span>
                                        
                                        <?php if ($userId <= 0): ?>
                                            <strong>
                                                <a href="user-details.php?id=<?= $log['user_id'] ?>"><?= htmlspecialchars($log['fullname'] ?? 'Unknown User') ?></a>
                                            </strong>
                                        <?php endif; ?>
                                    </div>
                                    <div class="activity-meta">
                                        <div><?= formatDate($log['timestamp'], 'd.m.Y H:i:s') ?></div>
                                        <div>IP: <?= htmlspecialchars($log['ip_address'] ?? '-') ?></div>
                                        <?php if (!empty($log['user_agent'])): ?>
                                            <div title="<?= htmlspecialchars($log['user_agent']) ?>">
                                                <?= identifyBrowser($log['user_agent']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($log['description'])): ?>
                                        <div class="activity-description"><?= htmlspecialchars($log['description']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?user_id=<?= $userId ?>&type=<?= $activityType ?>&date_start=<?= $dateStart ?>&date_end=<?= $dateEnd ?>&search=<?= urlencode($search) ?>&page=1" class="page-link">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a href="?user_id=<?= $userId ?>&type=<?= $activityType ?>&date_start=<?= $dateStart ?>&date_end=<?= $dateEnd ?>&search=<?= urlencode($search) ?>&page=<?= $page - 1 ?>" class="page-link">
                            <i class="fas fa-angle-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-link disabled"><i class="fas fa-angle-double-left"></i></span>
                        <span class="page-link disabled"><i class="fas fa-angle-left"></i></span>
                    <?php endif; ?>
                    
                    <?php
                    // Show limited page numbers with current page in the middle
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    // Always show at least 5 pages if available
                    if ($endPage - $startPage + 1 < 5) {
                        if ($startPage === 1) {
                            $endPage = min($totalPages, 5);
                        } elseif ($endPage === $totalPages) {
                            $startPage = max(1, $totalPages - 4);
                        }
                    }
                    
                    for ($i = $startPage; $i <= $endPage; $i++): 
                    ?>
                        <a href="?user_id=<?= $userId ?>&type=<?= $activityType ?>&date_start=<?= $dateStart ?>&date_end=<?= $dateEnd ?>&search=<?= urlencode($search) ?>&page=<?= $i ?>" class="page-link <?= $i === $page ? 'active' : '' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?user_id=<?= $userId ?>&type=<?= $activityType ?>&date_start=<?= $dateStart ?>&date_end=<?= $dateEnd ?>&search=<?= urlencode($search) ?>&page=<?= $page + 1 ?>" class="page-link">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="?user_id=<?= $userId ?>&type=<?= $activityType ?>&date_start=<?= $dateStart ?>&date_end=<?= $dateEnd ?>&search=<?= urlencode($search) ?>&page=<?= $totalPages ?>" class="page-link">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-link disabled"><i class="fas fa-angle-right"></i></span>
                        <span class="page-link disabled"><i class="fas fa-angle-double-right"></i></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
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
            
            // Apply filters automatically when date inputs change
            const dateInputs = document.querySelectorAll('input[type="date"]');
            dateInputs.forEach(input => {
                input.addEventListener('change', function() {
                    document.getElementById('filterForm').submit();
                });
            });
        });
        
        /**
         * Identify browser from user agent
         */
        function identifyBrowser(userAgent) {
            const ua = userAgent.toLowerCase();
            let browser = '';
            let os = '';
            
            // Detect OS
            if (ua.indexOf('windows') !== -1) {
                os = 'Windows';
            } else if (ua.indexOf('mac') !== -1) {
                os = 'Mac';
            } else if (ua.indexOf('linux') !== -1) {
                os = 'Linux';
            } else if (ua.indexOf('android') !== -1) {
                os = 'Android';
            } else if (ua.indexOf('iphone') !== -1 || ua.indexOf('ipad') !== -1) {
                os = 'iOS';
            }
            
            // Detect browser
            if (ua.indexOf('chrome') !== -1 && ua.indexOf('edg') === -1) {
                browser = 'Chrome';
            } else if (ua.indexOf('firefox') !== -1) {
                browser = 'Firefox';
            } else if (ua.indexOf('safari') !== -1 && ua.indexOf('chrome') === -1) {
                browser = 'Safari';
            } else if (ua.indexOf('edge') !== -1 || ua.indexOf('edg') !== -1) {
                browser = 'Edge';
            } else if (ua.indexOf('opera') !== -1 || ua.indexOf('opr') !== -1) {
                browser = 'Opera';
            } else if (ua.indexOf('msie') !== -1 || ua.indexOf('trident') !== -1) {
                browser = 'Internet Explorer';
            }
            
            if (browser && os) {
                return browser + ' / ' + os;
            } else if (browser) {
                return browser;
            } else if (os) {
                return os;
            }
            
            return 'Unknown';
        }
    </script>
</body>
</html>