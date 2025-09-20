import mongoose from 'mongoose';

export const connectDB = async (mongoUri) => {
  try {
    mongoose.set('strictQuery', true);
    await mongoose.connect(mongoUri);
    console.log('MongoDB connection established');
  } catch (error) {
    console.error('MongoDB connection error:', error);
    throw error;
  }
};

export default mongoose;
