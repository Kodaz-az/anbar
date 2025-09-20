import jwt from 'jsonwebtoken';
import User from '../models/User.js';
import { hashPassword, comparePassword } from '../utils/password.js';

const buildToken = (userId, role) =>
  jwt.sign({ sub: userId, role }, process.env.JWT_SECRET, { expiresIn: '7d' });

const sanitizeUser = (user) => {
  const obj = user.toObject({ versionKey: false });
  delete obj.password;
  return obj;
};

export const register = async (req, res, next) => {
  try {
    const { email, password, firstName, lastName, schoolNumber } = req.body;

    const existingUser = await User.findOne({
      $or: [{ email: email.toLowerCase() }, { schoolNumber }]
    });

    if (existingUser) {
      return res
        .status(409)
        .json({ message: 'Bu e-posta veya okul numarası ile kullanıcı zaten kayıtlı.' });
    }

    const hashedPassword = await hashPassword(password);

    const user = await User.create({
      email: email.toLowerCase(),
      password: hashedPassword,
      firstName,
      lastName,
      schoolNumber,
      role: 'student'
    });

    const token = buildToken(user.id, user.role);

    return res.status(201).json({
      message: 'Kayıt başarılı.',
      token,
      user: sanitizeUser(user)
    });
  } catch (error) {
    return next(error);
  }
};

export const login = async (req, res, next) => {
  try {
    const { email, password } = req.body;

    const user = await User.findOne({ email: email.toLowerCase() });
    if (!user) {
      return res.status(401).json({ message: 'E-posta veya şifre hatalı.' });
    }

    if (user.status !== 'active') {
      return res.status(403).json({ message: 'Hesabınız pasif durumdadır. Yönetici ile iletişime geçin.' });
    }

    const isPasswordValid = await comparePassword(password, user.password);
    if (!isPasswordValid) {
      return res.status(401).json({ message: 'E-posta veya şifre hatalı.' });
    }

    const token = buildToken(user.id, user.role);

    return res.json({
      message: 'Giriş başarılı.',
      token,
      user: sanitizeUser(user)
    });
  } catch (error) {
    return next(error);
  }
};

export const getProfile = async (req, res, next) => {
  try {
    if (!req.user) {
      return res.status(401).json({ message: 'Oturum bulunamadı.' });
    }
    return res.json({ user: req.user });
  } catch (error) {
    return next(error);
  }
};

export default {
  register,
  login,
  getProfile
};
