import { Router } from 'express';
import authenticate from '../middlewares/auth.js';
import requireRole from '../middlewares/roles.js';
import validateRequest from '../middlewares/validateRequest.js';
import {
  reviewListingSchema,
  listingIdParamSchema
} from '../validations/listingValidation.js';
import {
  getPendingListings,
  approveListing,
  rejectListing
} from '../controllers/adminController.js';

const router = Router();

router.use(authenticate, requireRole('admin'));

router.get('/listings/pending', getPendingListings);
router.patch('/listings/:id/approve', validateRequest(listingIdParamSchema), approveListing);
router.patch('/listings/:id/reject', validateRequest(reviewListingSchema), rejectListing);

export default router;
