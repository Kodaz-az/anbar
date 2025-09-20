import { Router } from 'express';
import { register, login, getProfile } from '../controllers/authController.js';
import validateRequest from '../middlewares/validateRequest.js';
import { registerSchema, loginSchema } from '../validations/authValidation.js';
import authenticate from '../middlewares/auth.js';

const router = Router();

router.post('/register', validateRequest(registerSchema), register);
router.post('/login', validateRequest(loginSchema), login);
router.get('/profile', authenticate, getProfile);

export default router;
