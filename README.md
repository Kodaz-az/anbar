# Kampüs Pazarı - Okul İçi İlan Platformu

Bu proje, öğrencilerin okul içi ilan paylaşımı yapabilmeleri için hazırlanan tam yığın bir örnektir. Backend Node.js/Express ve MongoDB kullanır, frontend ise HTML/CSS/JS ile hazırlanmıştır. Yönetici onayı ile ilanların yayınlanması sağlanır.

## Proje Yapısı

```
backend/       # Express + MongoDB API
frontend/      # Statik HTML/CSS/JS arayüzü
```

### Backend

- Kayıt/giriş işlemleri (`/api/auth`)
- Öğrenci ilan işlemleri (`/api/listings`)
- Yönetici onay akışı (`/api/admin`)
- JWT tabanlı kimlik doğrulama ve rol kontrolü
- Joi ile sunucu tarafı validasyon

### Frontend

- Onaylanmış ilanları listeleyen ana sayfa
- Öğrenci kayıt/giriş ekranları
- Öğrencinin kendi ilanlarını yönetebildiği alan
- Yönetici için onay/reddet panosu
- Vanilla JS ile backend API entegrasyonu

## Kurulum

### Backend

```bash
cd backend
npm install
cp .env.example .env
# .env dosyasında MONGODB_URI ve JWT_SECRET alanlarını güncelleyin
npm run dev
```

Sunucu varsayılan olarak `http://localhost:5000` adresinde çalışır.

### Frontend

Statik dosyalar `frontend/public` klasöründedir. Basit bir sunucu ile yayınlayabilirsiniz:

```bash
# Örneğin npx ile
cd frontend/public
npx serve .
```

Arayüz çalışırken backend API adresi `http://localhost:5000/api` olarak varsayılır. Farklı bir adres kullanacaksanız HTML içerisinde `window.__API_BASE_URL__` değişkenini global olarak tanımlayabilirsiniz:

```html
<script>
  window.__API_BASE_URL__ = 'https://ornek-domain.com/api';
</script>
<script type="module" src="js/listings.js"></script>
```

## Test Kullanıcısı Oluşturma

1. `register.html` üzerinden öğrenci hesabı açın.
2. MongoDB üzerinde doğrudan bir kullanıcıya `role: 'admin'` vererek yönetici hesabı oluşturabilirsiniz.
3. Öğrenci ilan gönderdiğinde durum `pending` olur. Yönetici panelinden onaylandığında ana sayfada listelenir.

## Lisans

Bu proje eğitim amaçlı örnek bir uygulamadır.
