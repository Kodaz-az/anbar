            </div>
            
            <div class="method-item">
                <div class="method-icon">
                    <i class="fas fa-university"></i>
                </div>
                <div class="method-details">
                    <div class="method-title">Bank Köçürməsi</div>
                    <div class="method-description">Aşağıdakı bank hesabına köçürmə edə bilərsiniz. Açıqlamada sifariş nömrənizi qeyd etməyi unutmayın.</div>
                </div>
            </div>
        </div>
        
        <!-- Bank Details -->
        <div class="section-header">
            <h2 class="section-title"><i class="fas fa-university"></i> Bank Məlumatları</h2>
        </div>
        
        <div class="payment-methods">
            <div class="method-item">
                <div class="method-icon">
                    <i class="fas fa-building-columns"></i>
                </div>
                <div class="method-details">
                    <div class="method-title">Kapital Bank</div>
                    <div class="method-description">
                        <p>Hesab Sahibi: AlumPro MMC</p>
                        <p>IBAN: AZ12AIIB38070019440028941102</p>
                        <p>SWIFT: AIIBAZ2X</p>
                    </div>
                </div>
            </div>
            
            <div class="method-item">
                <div class="method-icon">
                    <i class="fas fa-building-columns"></i>
                </div>
                <div class="method-details">
                    <div class="method-title">ABB</div>
                    <div class="method-description">
                        <p>Hesab Sahibi: AlumPro MMC</p>
                        <p>IBAN: AZ56NABZ01350100000000012944</p>
                        <p>SWIFT: IBAZAZ2X</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Contact for Payment -->
        <div class="section-header">
            <h2 class="section-title"><i class="fas fa-headset"></i> Ödəniş Dəstəyi</h2>
        </div>
        
        <div class="payment-methods">
            <div class="method-item">
                <div class="method-icon">
                    <i class="fas fa-phone"></i>
                </div>
                <div class="method-details">
                    <div class="method-title">Maliyyə şöbəsi</div>
                    <div class="method-description">
                        <p>Telefon: +994 12 555 44 33</p>
                        <p>E-mail: finance@alumpro.az</p>
                        <p>İş saatları: Həftəiçi 09:00-18:00</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bottom Navigation -->
    <nav class="bottom-nav">
        <a href="index.php" class="nav-item">
            <div class="nav-icon"><i class="fas fa-home"></i></div>
            <span>Ana Səhifə</span>
        </a>
        <a href="orders.php" class="nav-item">
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
        <a href="profile.php" class="nav-item active">
            <div class="nav-icon"><i class="fas fa-user"></i></div>
            <span>Profil</span>
        </a>
    </nav>
</body>
</html>