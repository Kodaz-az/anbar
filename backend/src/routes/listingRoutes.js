import { Router } from 'express';
import authenticate from '../middlewares/auth.js';
import validateRequest from '../middlewares/validateRequest.js';
import {
  createListingSchema,
  updateListingSchema,
  listingIdParamSchema
} from '../validations/listingValidation.js';
import {
  createListing,
  getApprovedListings,
  getMyListings,
  updateListing,
  deleteListing
} from '../controllers/listingController.js';

const router = Router();

router.get('/', getApprovedListings);
router.get('/mine', authenticate, getMyListings);
router.post('/', authenticate, validateRequest(createListingSchema), createListing);
router.put('/:id', authenticate, validateRequest(updateListingSchema), updateListing);
router.delete('/:id', authenticate, validateRequest(listingIdParamSchema), deleteListing);

export default router;
