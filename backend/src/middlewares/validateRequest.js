export const validateRequest = (schema) => async (req, res, next) => {
  try {
    const payload = {
      body: req.body,
      params: req.params,
      query: req.query
    };

    await schema.validateAsync(payload, { abortEarly: false, allowUnknown: true });
    return next();
  } catch (error) {
    const details = error.details?.map((detail) => ({
      message: detail.message,
      path: detail.path.join('.')
    }));
    return res.status(400).json({
      message: 'Geçersiz veri gönderildi.',
      errors: details
    });
  }
};

export default validateRequest;
