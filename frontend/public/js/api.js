const API_BASE_URL = window.__API_BASE_URL__ || 'http://localhost:5000/api';

const getToken = () => localStorage.getItem('accessToken');

const defaultHeaders = () => {
  const headers = { 'Content-Type': 'application/json' };
  const token = getToken();
  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }
  return headers;
};

const handleResponse = async (response) => {
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    if (response.status === 401) {
      localStorage.removeItem('accessToken');
      localStorage.removeItem('user');
    }
    const error = new Error(data.message || 'Bir hata oluştu.');
    error.response = data;
    error.status = response.status;
    throw error;
  }
  return data;
};

const request = async (path, options = {}) => {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...options,
    headers: {
      ...defaultHeaders(),
      ...(options.headers || {})
    }
  });

  if (response.status === 204) {
    return {};
  }

  return handleResponse(response);
};

export const api = {
  get: (path) => request(path),
  post: (path, body) =>
    request(path, {
      method: 'POST',
      body: JSON.stringify(body)
    }),
  put: (path, body) =>
    request(path, {
      method: 'PUT',
      body: JSON.stringify(body)
    }),
  patch: (path, body) =>
    request(path, {
      method: 'PATCH',
      body: JSON.stringify(body)
    }),
  delete: (path) =>
    request(path, {
      method: 'DELETE'
    })
};

export default api;
