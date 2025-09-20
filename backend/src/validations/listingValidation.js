import Joi from 'joi';

export const createListingSchema = Joi.object({
  body: Joi.object({
    title: Joi.string().min(3).max(120).required(),
    description: Joi.string().min(10).max(1000).required(),
    category: Joi.string().min(2).max(60).required(),
    imageUrl: Joi.string().uri().allow('', null),
    contactInfo: Joi.string().min(3).max(150).required()
  }).required(),
  params: Joi.object().optional(),
  query: Joi.object().optional()
});

export const updateListingSchema = Joi.object({
  body: Joi.object({
    title: Joi.string().min(3).max(120),
    description: Joi.string().min(10).max(1000),
    category: Joi.string().min(2).max(60),
    imageUrl: Joi.string().uri().allow('', null),
    contactInfo: Joi.string().min(3).max(150)
  })
    .min(1)
    .required(),
  params: Joi.object({
    id: Joi.string().hex().length(24).required()
  }).required(),
  query: Joi.object().optional()
});

export const reviewListingSchema = Joi.object({
  body: Joi.object({
    rejectionReason: Joi.string().allow('', null).max(300)
  }).optional(),
  params: Joi.object({
    id: Joi.string().hex().length(24).required()
  }).required(),
  query: Joi.object().optional()
});

export const listingIdParamSchema = Joi.object({
  body: Joi.object().optional(),
  params: Joi.object({
    id: Joi.string().hex().length(24).required()
  }).required(),
  query: Joi.object().optional()
});

export default {
  createListingSchema,
  updateListingSchema,
  reviewListingSchema,
  listingIdParamSchema
};
