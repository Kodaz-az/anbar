import Listing from '../models/Listing.js';

export const getPendingListings = async (req, res, next) => {
  try {
    const listings = await Listing.find({ status: 'pending' })
      .populate('owner', 'firstName lastName schoolNumber email')
      .sort({ createdAt: 1 });

    return res.json({ listings });
  } catch (error) {
    return next(error);
  }
};

export const approveListing = async (req, res, next) => {
  try {
    const { id } = req.params;
    const listing = await Listing.findById(id);

    if (!listing) {
      return res.status(404).json({ message: 'İlan bulunamadı.' });
    }

    listing.status = 'approved';
    listing.rejectionReason = '';
    await listing.save();

    return res.json({ message: 'İlan onaylandı.', listing });
  } catch (error) {
    return next(error);
  }
};

export const rejectListing = async (req, res, next) => {
  try {
    const { id } = req.params;
    const { rejectionReason } = req.body;

    const listing = await Listing.findById(id);
    if (!listing) {
      return res.status(404).json({ message: 'İlan bulunamadı.' });
    }

    listing.status = 'rejected';
    listing.rejectionReason = rejectionReason || '';
    await listing.save();

    return res.json({ message: 'İlan reddedildi.', listing });
  } catch (error) {
    return next(error);
  }
};

export default {
  getPendingListings,
  approveListing,
  rejectListing
};
