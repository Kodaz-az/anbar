import { api } from './api.js';
import { requireAuth, requireAdmin, updateNavAuthState } from './auth.js';

const formatStatus = (status) => {
  switch (status) {
    case 'approved':
      return 'Onaylandı';
    case 'rejected':
      return 'Reddedildi';
    default:
      return 'Beklemede';
  }
};

const listingCardTemplate = (listing) => `
  <article class="card">
    ${listing.imageUrl ? `<img src="${listing.imageUrl}" alt="${listing.title}" />` : ''}
    <div>
      <h3>${listing.title}</h3>
      <p>${listing.description}</p>
      <p><strong>Kategori:</strong> ${listing.category}</p>
      <p><strong>İletişim:</strong> ${listing.contactInfo}</p>
    </div>
    <footer>
      <span class="badge">${listing.owner ? `${listing.owner.firstName} ${listing.owner.lastName}` : 'Anonim'}</span>
      <span class="status ${listing.status}">${formatStatus(listing.status)}</span>
    </footer>
  </article>
`;

const myListingTemplate = (listing) => `
  <article class="card" data-id="${listing._id}">
    <div class="card-header">
      <h3>${listing.title}</h3>
      <span class="status ${listing.status}">${formatStatus(listing.status)}</span>
    </div>
    <p><strong>Kategori:</strong> ${listing.category}</p>
    <p>${listing.description}</p>
    <p><strong>İletişim:</strong> ${listing.contactInfo}</p>
    ${listing.rejectionReason ? `<p><strong>Yönetici Notu:</strong> ${listing.rejectionReason}</p>` : ''}
    <div class="card-actions">
      <button class="button secondary" data-action="edit">Düzenle</button>
      <button class="button" data-action="delete">Sil</button>
    </div>
  </article>
`;

const adminListingTemplate = (listing) => `
  <article class="card" data-id="${listing._id}">
    <div class="card-header">
      <h3>${listing.title}</h3>
      <span class="badge">${listing.owner?.firstName || ''} ${listing.owner?.lastName || ''}</span>
    </div>
    <p>${listing.description}</p>
    <p><strong>Kategori:</strong> ${listing.category}</p>
    <p><strong>İletişim:</strong> ${listing.contactInfo}</p>
    <div class="card-actions">
      <button class="button" data-action="approve">Onayla</button>
      <button class="button secondary" data-action="reject">Reddet</button>
    </div>
  </article>
`;

const renderListings = (container, listings, templateFn, emptyMessage) => {
  if (!container) return;
  if (!listings.length) {
    container.innerHTML = `<div class="empty-state">${emptyMessage}</div>`;
    return;
  }
  container.innerHTML = listings.map(templateFn).join('');
};

const loadPublicListings = async () => {
  const container = document.querySelector('[data-listings]');
  if (!container) return;

  try {
    const params = new URLSearchParams(window.location.search);
    const queryString = params.toString();
    const endpoint = queryString ? `/listings?${queryString}` : '/listings';
    const { listings } = await api.get(endpoint);
    renderListings(container, listings, listingCardTemplate, 'Şu anda yayınlanan ilan bulunmuyor.');
  } catch (error) {
    container.innerHTML = `<div class="alert">İlanlar yüklenirken bir hata oluştu.</div>`;
  }
};

const handleSearchForm = () => {
  const form = document.querySelector('#search-form');
  if (!form) return;

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const formData = new FormData(form);
    const params = new URLSearchParams();

    const search = formData.get('search');
    const category = formData.get('category');

    if (search) params.set('search', search);
    if (category) params.set('category', category);

    const url = params.toString() ? `?${params.toString()}` : window.location.pathname;
    window.history.replaceState({}, '', url);
    loadPublicListings();
  });
};

const handleListingCreation = () => {
  const form = document.querySelector('#listing-form');
  if (!form) return;

  const user = requireAuth();
  if (!user) return;

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submitButton = form.querySelector('button[type="submit"]');
    submitButton.disabled = true;
    const alert = document.querySelector('[data-alert]');
    if (alert) {
      alert.classList.add('hidden');
      alert.classList.remove('error', 'success');
    }

    const payload = Object.fromEntries(new FormData(form).entries());

    try {
      await api.post('/listings', payload);
      form.reset();
      if (alert) {
        alert.textContent = 'İlanınız yönetici onayına gönderildi.';
        alert.classList.remove('hidden');
        alert.classList.remove('error');
        alert.classList.add('success');
      }
    } catch (error) {
      if (alert) {
        alert.textContent = error.response?.message || 'İlan oluşturulurken hata oluştu.';
        alert.classList.remove('hidden');
        alert.classList.remove('success');
        alert.classList.add('error');
      }
    } finally {
      submitButton.disabled = false;
    }
  });
};

const handleMyListings = () => {
  const container = document.querySelector('[data-my-listings]');
  if (!container) return;

  const user = requireAuth();
  if (!user) return;

  const loadListings = async () => {
    try {
      const { listings } = await api.get('/listings/mine');
      renderListings(container, listings, myListingTemplate, 'Henüz bir ilan eklemediniz.');
    } catch (error) {
      container.innerHTML = `<div class="alert">İlanlar yüklenemedi.</div>`;
    }
  };

  loadListings();

  container.addEventListener('click', async (event) => {
    const actionButton = event.target.closest('button[data-action]');
    if (!actionButton) return;

    const card = actionButton.closest('[data-id]');
    if (!card) return;

    const listingId = card.dataset.id;
    const action = actionButton.dataset.action;

    if (action === 'delete') {
      if (!confirm('Bu ilanı silmek istediğinize emin misiniz?')) return;
      try {
        await api.delete(`/listings/${listingId}`);
        card.remove();
      } catch (error) {
        alert('İlan silinemedi.');
      }
    }

    if (action === 'edit') {
      const title = prompt('İlan başlığı', card.querySelector('h3').textContent);
      if (!title) return;
      const description = prompt('Açıklama', card.querySelector('p:nth-of-type(2)')?.textContent || '');
      if (!description) return;
      const contactInfo = prompt('İletişim bilgisi', card.querySelector('p:nth-of-type(3)')?.textContent.replace('İletişim: ', '') || '');
      const category = prompt('Kategori', card.querySelector('p:nth-of-type(1)')?.textContent.replace('Kategori: ', '') || '');
      const imageUrl = prompt('Görsel URL', '');

      try {
        await api.put(`/listings/${listingId}`, {
          title,
          description,
          contactInfo,
          category,
          imageUrl
        });
        await loadListings();
      } catch (error) {
        alert('İlan güncellenemedi.');
      }
    }
  });
};

const handleAdminDashboard = async () => {
  const container = document.querySelector('[data-admin-listings]');
  if (!container) return;

  const user = requireAdmin();
  if (!user) return;

  try {
    const { listings } = await api.get('/admin/listings/pending');
    renderListings(container, listings, adminListingTemplate, 'Onay bekleyen ilan bulunmuyor.');
  } catch (error) {
    container.innerHTML = `<div class="alert">Onay bekleyen ilanlar yüklenemedi.</div>`;
  }

  container.addEventListener('click', async (event) => {
    const button = event.target.closest('button[data-action]');
    if (!button) return;

    const card = button.closest('[data-id]');
    const id = card?.dataset.id;

    if (!id) return;

    if (button.dataset.action === 'approve') {
      await api.patch(`/admin/listings/${id}/approve`);
      card.remove();
      updateNavAuthState();
    }

    if (button.dataset.action === 'reject') {
      const reason = prompt('Red sebebi (opsiyonel)');
      await api.patch(`/admin/listings/${id}/reject`, { rejectionReason: reason });
      card.remove();
    }

    if (!container.querySelector('[data-id]')) {
      container.innerHTML = '<div class="empty-state">Onay bekleyen ilan yok.</div>';
    }
  });
};

const init = () => {
  updateNavAuthState();
  loadPublicListings();
  handleSearchForm();
  handleListingCreation();
  handleMyListings();
  handleAdminDashboard();
};

window.addEventListener('DOMContentLoaded', init);
