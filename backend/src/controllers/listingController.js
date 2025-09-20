import Listing from '../models/Listing.js';

export const createListing = async (req, res, next) => {
  try {
    const { title, description, category, imageUrl, contactInfo } = req.body;
    const listing = await Listing.create({
      title,
      description,
      category,
      imageUrl: imageUrl || '',
      contactInfo,
      owner: req.user.id
    });

    return res.status(201).json({
      message: 'İlan yönetici onayına gönderildi.',
      listing
    });
  } catch (error) {
    return next(error);
  }
};

export const getApprovedListings = async (req, res, next) => {
  try {
    const { search, category } = req.query;

    const filters = { status: 'approved' };
    if (category) {
      filters.category = category;
    }
    if (search) {
      filters.$or = [
        { title: { $regex: search, $options: 'i' } },
        { description: { $regex: search, $options: 'i' } }
      ];
    }

    const listings = await Listing.find(filters)
      .populate('owner', 'firstName lastName schoolNumber')
      .sort({ createdAt: -1 });

    return res.json({ listings });
  } catch (error) {
    return next(error);
  }
};

export const getMyListings = async (req, res, next) => {
  try {
    const listings = await Listing.find({ owner: req.user.id }).sort({ createdAt: -1 });
    return res.json({ listings });
  } catch (error) {
    return next(error);
  }
};

export const updateListing = async (req, res, next) => {
  try {
    const { id } = req.params;
    const updates = req.body;

    const listing = await Listing.findById(id);
    if (!listing) {
      return res.status(404).json({ message: 'İlan bulunamadı.' });
    }

    const isOwner = listing.owner.toString() === req.user.id.toString();
    const isAdmin = req.user.role === 'admin';

    if (!isOwner && !isAdmin) {
      return res.status(403).json({ message: 'Bu ilanı düzenleme yetkiniz yok.' });
    }

    listing.title = updates.title ?? listing.title;
    listing.description = updates.description ?? listing.description;
    listing.category = updates.category ?? listing.category;
    listing.imageUrl = updates.imageUrl ?? listing.imageUrl;
    listing.contactInfo = updates.contactInfo ?? listing.contactInfo;

    if (isOwner && !isAdmin) {
      listing.status = 'pending';
      listing.rejectionReason = '';
    }

    await listing.save();

    return res.json({ message: 'İlan güncellendi.', listing });
  } catch (error) {
    return next(error);
  }
};

export const deleteListing = async (req, res, next) => {
  try {
    const { id } = req.params;
    const listing = await Listing.findById(id);
    if (!listing) {
      return res.status(404).json({ message: 'İlan bulunamadı.' });
    }

    const isOwner = listing.owner.toString() === req.user.id.toString();
    const isAdmin = req.user.role === 'admin';

    if (!isOwner && !isAdmin) {
      return res.status(403).json({ message: 'Bu ilanı silme yetkiniz yok.' });
    }

    await listing.deleteOne();

    return res.json({ message: 'İlan silindi.' });
  } catch (error) {
    return next(error);
  }
};

export default {
  createListing,
  getApprovedListings,
  getMyListings,
  updateListing,
  deleteListing
};
