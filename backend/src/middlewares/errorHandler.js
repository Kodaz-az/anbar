export const notFoundHandler = (req, res) => {
  res.status(404).json({ message: 'İstenen kaynak bulunamadı.' });
};

export const errorHandler = (err, req, res, next) => {
  console.error(err);
  if (res.headersSent) {
    return next(err);
  }

  const status = err.status || 500;
  const message = err.message || 'Beklenmeyen bir hata oluştu.';

  res.status(status).json({ message });
};

export default errorHandler;
