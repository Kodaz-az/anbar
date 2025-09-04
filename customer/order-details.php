        <div class="header-title">Sifariş Detalları</div>
    </header>
    
    <div class="container">
        <!-- Order Header -->
        <div class="order-header">
            <div class="order-number">
                #<?= htmlspecialchars($order['order_number']) ?>
                <span class="status-badge badge-<?= $statusInfo['color'] ?>"><?= $statusInfo['text'] ?></span>
            </div>
            <div class="order-date"><?= formatDate($order['order_date'], 'd.m.Y H:i') ?></div>
            
            <div class="order-details">
                <div class="detail-column">
                    <div class="detail-label">Filial</div>
                    <div class="detail-value"><?= htmlspecialchars($order['branch_name']) ?></div>
                </div>
                
                <div class="detail-column">
                    <div class="detail-label">Satıcı</div>
                    <div class="detail-value"><?= htmlspecialchars($order['seller_name']) ?></div>
                </div>
                
                <div class="detail-column">
                    <div class="detail-label">Ümumi Məbləğ</div>
                    <div class="detail-value"><?= formatMoney($order['total_amount']) ?></div>
                </div>
            </div>
            
            <div class="order-actions">
                <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $order['branch_phone']) ?>" class="action-btn btn-whatsapp">
                    <i class="fab fa-whatsapp"></i> Əlaqə saxla
                </a>
                
                <a href="messages.php?new=1&ref=order&id=<?= $order['id'] ?>" class="action-btn btn-outline">
                    <i class="fas fa-comment"></i> Mesaj yaz
                </a>
            </div>
        </div>
        
        <!-- Order Status Timeline -->
        <div class="detail-section">
            <div class="section-header">
                <i class="fas fa-clock"></i> Sifariş Statusu
            </div>
            <div class="section-body">
                <div class="status-timeline">
                    <div class="timeline-line"></div>
                    
                    <div class="timeline-item">
                        <div class="timeline-dot"><i class="fas fa-check"></i></div>
                        <div class="timeline-content">
                            <div class="timeline-title">Sifariş qəbul edildi</div>
                            <div class="timeline-date"><?= formatDate($order['order_date'], 'd.m.Y H:i') ?></div>
                        </div>
                    </div>
                    
                    <div class="timeline-item">
                        <div class="timeline-dot <?= in_array($order['order_status'], ['new']) ? 'inactive' : '' ?>">
                            <i class="fas fa-cog"></i>
                        </div>
                        <div class="timeline-content">
                            <div class="timeline-title">İstehsalata başlanıldı</div>
                            <?php if (in_array($order['order_status'], ['processing', 'completed', 'delivered'])): ?>
                                <div class="timeline-date"><?= formatDate($order['processing_date'] ?? $order['updated_at'], 'd.m.Y H:i') ?></div>
                            <?php else: ?>
                                <div class="timeline-date">Gözlənilir...</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="timeline-item">
                        <div class="timeline-dot <?= in_array($order['order_status'], ['new', 'processing']) ? 'inactive' : '' ?>">
                            <i class="fas fa-check"></i>
                        </div>
                        <div class="timeline-content">
                            <div class="timeline-title">Hazırdır</div>
                            <?php if (in_array($order['order_status'], ['completed', 'delivered'])): ?>
                                <div class="timeline-date"><?= formatDate($order['completion_date'] ?? $order['updated_at'], 'd.m.Y H:i') ?></div>
                            <?php else: ?>
                                <div class="timeline-date">Gözlənilir...</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="timeline-item">
                        <div class="timeline-dot <?= $order['order_status'] !== 'delivered' ? 'inactive' : '' ?>">
                            <i class="fas fa-truck"></i>
                        </div>
                        <div class="timeline-content">
                            <div class="timeline-title">Təhvil verilib</div>
                            <?php if ($order['order_status'] === 'delivered'): ?>
                                <div class="timeline-date"><?= formatDate($order['delivery_date'] ?? $order['updated_at'], 'd.m.Y H:i') ?></div>
                            <?php else: ?>
                                <div class="timeline-date">Gözlənilir...</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Financial Information -->
        <div class="detail-section">
            <div class="section-header">
                <i class="fas fa-money-bill-wave"></i> Maliyyə Məlumatları
            </div>
            <div class="section-body">
                <div class="price-row">
                    <div class="price-label">Ümumi Məbləğ</div>
                    <div class="price-value"><?= formatMoney($order['total_amount']) ?></div>
                </div>
                
                <div class="price-row">
                    <div class="price-label">Avans Ödəniş</div>
                    <div class="price-value"><?= formatMoney($order['advance_payment']) ?></div>
                </div>
                
                <div class="price-row">
                    <div class="price-label">Qalıq Borc</div>
                    <div class="price-value price-total"><?= formatMoney($order['remaining_amount']) ?></div>
                </div>
                
                <?php if (!empty($order['assembly_fee'])): ?>
                    <div class="price-row">
                        <div class="price-label">Yığılma Haqqı</div>
                        <div class="price-value"><?= formatMoney($order['assembly_fee']) ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Profile Products -->
        <?php if (!empty($profiles)): ?>
            <div class="detail-section">
                <div class="section-header">
                    <i class="fas fa-box"></i> Profil Məhsulları
                </div>
                <div class="section-body">
                    <div class="table-responsive">
                        <table class="product-table">
                            <thead>
                                <tr>
                                    <th>Profil Növü</th>
                                    <th>Ölçülər</th>
                                    <th>Sayı</th>
                                    <th>Petlə</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($profiles as $profile): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($profile['profile_type']) ?></td>
                                        <td><?= $profile['height'] ?> x <?= $profile['width'] ?> sm</td>
                                        <td><?= $profile['quantity'] ?> ədəd</td>
                                        <td><?= $profile['hinge_count'] ?> ədəd</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Glass Products -->
        <?php if (!empty($glasses)): ?>
            <div class="detail-section">
                <div class="section-header">
                    <i class="fas fa-square"></i> Şüşə Məhsulları
                </div>
                <div class="section-body">
                    <div class="table-responsive">
                        <table class="product-table">
                            <thead>
                                <tr>
                                    <th>Şüşə Növü</th>
                                    <th>Ölçülər</th>
                                    <th>Sayı</th>
                                    <th>Sahə</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($glasses as $glass): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($glass['glass_type']) ?></td>
                                        <td><?= $glass['height'] ?> x <?= $glass['width'] ?> sm</td>
                                        <td><?= $glass['quantity'] ?> ədəd</td>
                                        <td><?= $glass['area'] ?> m²</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Order Notes -->
        <?php if (!empty($order['initial_note'])): ?>
            <div class="detail-section">
                <div class="section-header">
                    <i class="fas fa-sticky-note"></i> Qeydlər
                </div>
                <div class="section-body">
                    <div class="note-box">
                        <div class="note-title">Sifariş qeydi:</div>
                        <?= nl2br(htmlspecialchars($order['initial_note'])) ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Drawing Image -->
        <?php if (!empty($order['drawing_image'])): ?>
            <div class="detail-section">
                <div class="section-header">
                    <i class="fas fa-pencil-alt"></i> Çəkilmiş Sxem
                </div>
                <div class="section-body">
                    <div class="drawing-container">
                        <img src="<?= htmlspecialchars($order['drawing_image']) ?>" alt="Çəkilmiş sxem" class="drawing-image">
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Bottom Navigation -->
    <nav class="bottom-nav">
        <a href="index.php" class="nav-item">
            <div class="nav-icon"><i class="fas fa-home"></i></div>
            <span>Ana Səhifə</span>
        </a>
        <a href="orders.php" class="nav-item active">
            <div class="nav-icon"><i class="fas fa-clipboard-list"></i></div>
            <span>Sifarişlər</span>
        </a>
        <a href="messages.php" class="nav-item">
            <div class="nav-icon"><i class="fas fa-envelope"></i></div>
            <span>Mesajlar</span>
            <?php if($unreadMessages > 0): ?>
                <span class="notification-badge"><?= $unreadMessages ?></span>
            <?php endif; ?>
        </a>
        <a href="profile.php" class="nav-item">
            <div class="nav-icon"><i class="fas fa-user"></i></div>
            <span>Profil</span>
        </a>
    </nav>
</body>
</html>