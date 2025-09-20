import Joi from 'joi';

export const registerSchema = Joi.object({
  body: Joi.object({
    email: Joi.string().email().required().messages({
      'string.email': 'Lütfen geçerli bir e-posta adresi girin.',
      'any.required': 'E-posta alanı zorunludur.'
    }),
    password: Joi.string().min(8).required().messages({
      'string.min': 'Şifre en az 8 karakter olmalıdır.',
      'any.required': 'Şifre alanı zorunludur.'
    }),
    firstName: Joi.string().min(2).max(50).required(),
    lastName: Joi.string().min(2).max(50).required(),
    schoolNumber: Joi.string().min(3).max(30).required()
  }).required(),
  params: Joi.object().optional(),
  query: Joi.object().optional()
});

export const loginSchema = Joi.object({
  body: Joi.object({
    email: Joi.string().email().required(),
    password: Joi.string().required()
  }).required(),
  params: Joi.object().optional(),
  query: Joi.object().optional()
});

export default {
  registerSchema,
  loginSchema
};
