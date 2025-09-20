import { api } from './api.js';

export const getStoredUser = () => {
  const raw = localStorage.getItem('user');
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch (error) {
    console.error('Kullanıcı bilgisi çözümlenemedi', error);
    localStorage.removeItem('user');
    return null;
  }
};

export const setSession = ({ token, user }) => {
  if (token) {
    localStorage.setItem('accessToken', token);
  }
  if (user) {
    localStorage.setItem('user', JSON.stringify(user));
  }
};

export const clearSession = () => {
  localStorage.removeItem('accessToken');
  localStorage.removeItem('user');
};

export const requireAuth = () => {
  const user = getStoredUser();
  if (!user) {
    window.location.href = 'login.html';
    return null;
  }
  return user;
};

export const requireAdmin = () => {
  const user = requireAuth();
  if (!user) return null;
  if (user.role !== 'admin') {
    window.location.href = 'index.html';
    return null;
  }
  return user;
};

export const updateNavAuthState = () => {
  const user = getStoredUser();
  const authOnlyElements = document.querySelectorAll('[data-auth]');
  const guestOnlyElements = document.querySelectorAll('[data-guest]');
  const adminOnlyElements = document.querySelectorAll('[data-role="admin"]');

  authOnlyElements.forEach((el) => {
    el.classList.toggle('hidden', !user);
  });
  guestOnlyElements.forEach((el) => {
    el.classList.toggle('hidden', !!user);
  });
  adminOnlyElements.forEach((el) => {
    el.classList.toggle('hidden', !user || user.role !== 'admin');
  });

  const profileName = document.querySelector('[data-profile-name]');
  if (profileName) {
    profileName.textContent = user ? `${user.firstName} ${user.lastName}` : '';
  }
};

const showError = (message) => {
  const alert = document.querySelector('[data-alert]');
  if (!alert) return;
  alert.textContent = message;
  alert.classList.remove('hidden');
};

const hideError = () => {
  const alert = document.querySelector('[data-alert]');
  if (!alert) return;
  alert.classList.add('hidden');
};

const formDataToObject = (form) => Object.fromEntries(new FormData(form).entries());

const handleRegister = () => {
  const form = document.querySelector('#register-form');
  if (!form) return;

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    hideError();
    form.querySelector('button[type="submit"]').disabled = true;

    try {
      const payload = formDataToObject(form);
      const response = await api.post('/auth/register', payload);
      setSession(response);
      window.location.href = 'submit-listing.html';
    } catch (error) {
      showError(error.response?.message || error.message);
    } finally {
      form.querySelector('button[type="submit"]').disabled = false;
    }
  });
};

const handleLogin = () => {
  const form = document.querySelector('#login-form');
  if (!form) return;

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    hideError();
    form.querySelector('button[type="submit"]').disabled = true;

    try {
      const payload = formDataToObject(form);
      const response = await api.post('/auth/login', payload);
      setSession(response);
      window.location.href = 'index.html';
    } catch (error) {
      showError(error.response?.message || error.message);
    } finally {
      form.querySelector('button[type="submit"]').disabled = false;
    }
  });
};

const handleLogout = () => {
  document.querySelectorAll('[data-logout]').forEach((button) => {
    button.addEventListener('click', () => {
      clearSession();
      window.location.href = 'index.html';
    });
  });
};

export const initializeAuth = () => {
  updateNavAuthState();
  handleRegister();
  handleLogin();
  handleLogout();
};

window.addEventListener('DOMContentLoaded', initializeAuth);
