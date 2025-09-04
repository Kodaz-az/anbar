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
                <h1><i class="fas fa-clipboard-list"></i> Müştəri Sifarişləri</h1>
                <div class="breadcrumb">
                    <a href="index.php">Dashboard</a> / 
                    <a href="customers.php">Müştərilər</a> / 
                    <a href="customer-view.php?id=<?= $customerId ?>">Müştəri Məlumatları</a> / 
                    <span>Sifarişlər</span>
                </div>
            </div>

            <!-- Customer Header -->
            <div class="customer-header">
                <div class="customer-info">
                    <div class="customer-avatar">
                        <?= strtoupper(substr($customer['fullname'], 0, 1)) ?>
                    </div>
                    <div>
                        <div class="customer-name"><?= htmlspecialchars($customer['fullname']) ?></div>
                        <div class="customer-meta"><?= htmlspecialchars($customer['phone']) ?></div>
                    </div>
                </div>
                <div class="customer-actions">
                    <a href="customer-view.php?id=<?= $customerId ?>" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Müştəri Səhifəsinə Qayıt
                    </a>
                    <a href="hesabla.php?customer_id=<?= $customerId ?>" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Yeni Sifariş
                    </a>
                </div>
            </div>

            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <form action="" method="get" id="filterForm">
                        <input type="hidden" name="id" value="<?= $customerId ?>">
                        <div class="filter-container">
                            <div class="filter-item">
                                <label class="filter-label">Status:</label>
                                <select name="status" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Bütün Statuslar</option>
                                    <option value="new" <?= $status === 'new' ? 'selected' : '' ?>>Yeni</option>
                                    <option value="processing" <?= $status === 'processing' ? 'selected' : '' ?>>Hazırlanır</option>
                                    <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Hazır</option>
                                    <option value="delivered" <?= $status === 'delivered' ? 'selected' : '' ?>>Təhvil verilib</option>
                                    <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Ləğv edilib</option>
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
                                <label class="filter-label">Sıralama:</label>
                                <select name="sort" class="filter-select">
                                    <option value="date_desc" <?= $sort === 'date_desc' ? 'selected' : '' ?>>Tarix (Yeni-Köhnə)</option>
                                    <option value="date_asc" <?= $sort === 'date_asc' ? 'selected' : '' ?>>Tarix (Köhnə-Yeni)</option>
                                    <option value="amount_desc" <?= $sort === 'amount_desc' ? 'selected' : '' ?>>Məbləğ (Çox-Az)</option>
                                    <option value="amount_asc" <?= $sort === 'amount_asc' ? 'selected' : '' ?>>Məbləğ (Az-Çox)</option>
                                    <option value="status" <?= $sort === 'status' ? 'selected' : '' ?>>Status</option>
                                </select>
                            </div>
                            
                            <div class="filter-item">
                                <label class="filter-label">Axtar:</label>
                                <input type="text" name="search" class="filter-input" value="<?= htmlspecialchars($search) ?>" placeholder="Sifariş nömrəsi və ya qeyd">
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

            <!-- Orders List -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Sifarişlər (<?= $totalOrders ?>)</h2>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Sifariş №</th>
                                    <th>Tarix</th>
                                    <th>Filial</th>
                                    <th>Məbləğ</th>
                                    <th>Avans</th>
                                    <th>Qalıq</th>
                                    <th>Status</th>
                                    <th>Əməliyyatlar</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($orders)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center">Sifariş tapılmadı</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($orders as $order): ?>
                                        <?php 
                                            $statusInfo = $statusConfig[$order['order_status']] ?? ['text' => 'Bilinmir', 'color' => 'info'];
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($order['order_number']) ?></td>
                                            <td><?= formatDate($order['order_date'], 'd.m.Y H:i') ?></td>
                                            <td><?= htmlspecialchars($order['branch_name']) ?></td>
                                            <td><?= formatMoney($order['total_amount']) ?></td>
                                            <td><?= formatMoney($order['advance_payment']) ?></td>
                                            <td><?= formatMoney($order['remaining_amount']) ?></td>
                                            <td>
                                                <span class="status-badge badge-<?= $statusInfo['color'] ?>"><?= $statusInfo['text'] ?></span>
                                            </td>
                                            <td>
                                                <div class="btn-group">
                                                    <a href="order-details.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline" title="Ətraflı">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    
                                                    <?php if ($order['order_status'] === 'new'): ?>
                                                        <a href="order-edit.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline" title="Düzəliş et">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <a href="order-print.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline" title="Çap et">
                                                        <i class="fas fa-print"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?id=<?= $customerId ?>&page=1&status=<?= $status ?>&date_start=<?= $dateStart ?>&date_end=<?= $dateEnd ?>&search=<?= urlencode($search) ?>&sort=<?= $sort ?>" class="page-link">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                                <a href="?id=<?= $customerId ?>&page=<?= $page - 1 ?>&status=<?= $status ?>&date_start=<?= $dateStart ?>&date_end=<?= $dateEnd ?>&search=<?= urlencode($search) ?>&sort=<?= $sort ?>" class="page-link">
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
                                <a href="?id=<?= $customerId ?>&page=<?= $i ?>&status=<?= $status ?>&date_start=<?= $dateStart ?>&date_end=<?= $dateEnd ?>&search=<?= urlencode($search) ?>&sort=<?= $sort ?>" class="page-link <?= $i === $page ? 'active' : '' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="?id=<?= $customerId ?>&page=<?= $page + 1 ?>&status=<?= $status ?>&date_start=<?= $dateStart ?>&date_end=<?= $dateEnd ?>&search=<?= urlencode($search) ?>&sort=<?= $sort ?>" class="page-link">
                                    <i class="fas fa-angle-right"></i>
                                </a>
                                <a href="?id=<?= $customerId ?>&page=<?= $totalPages ?>&status=<?= $status ?>&date_start=<?= $dateStart ?>&date_end=<?= $dateEnd ?>&search=<?= urlencode($search) ?>&sort=<?= $sort ?>" class="page-link">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="page-link disabled"><i class="fas fa-angle-right"></i></span>
                                <span class="page-link disabled"><i class="fas fa-angle-double-right"></i></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
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
            
            // Apply filters automatically when date inputs change
            const dateInputs = document.querySelectorAll('input[type="date"]');
            dateInputs.forEach(input => {
                input.addEventListener('change', function() {
                    document.getElementById('filterForm').submit();
                });
            });
        });
    </script>
</body>
</html>