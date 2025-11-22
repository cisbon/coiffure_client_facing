# Social Links & Customer Management Implementation

## Overview
This document describes the implementation of social links management and customer entries viewing features for the Coiffure AI application.

## Features Implemented

### 1. Admin Dashboard Enhancements

#### A. Social Links Management Tab
- **Add/Edit/Delete Social Links**: Admins can manage multiple social media and review links
- **Automatic QR Code Generation**: QR codes are automatically generated when adding or editing links
- **Supported Platforms**:
  - Instagram
  - Facebook
  - TikTok
  - Google Reviews
  - Yelp
  - Twitter/X
  - LinkedIn
  - YouTube
  - Pinterest
  - Custom URLs
- **Icon Support**: Each platform has its own icon and color scheme
- **Custom Link Descriptions**: For custom URLs, admins can add descriptions shown below the icon
- **Display Order Control**: Links can be ordered as desired
- **QR Code Viewer**: Click "View QR Code" to see the generated QR code

#### B. Customer Entries Tab
- **View All Customer Onboarding Data**: See all customers who filled out the onboarding form
- **Search by Name**: Real-time search filter to find customers by name
- **Filter by Salon**: Filter entries by specific salon
- **Data Displayed**:
  - Full Name
  - Email Address
  - Phone Number
  - Marketing Consent Status (Yes/No)
  - Registration Date

### 2. Customer-Facing Interface (index.html)

#### Social Tab (Replaces QR Code Generator)
- **Tab Name Changed**: "QR Code Generator" → "Social"
- **Icon Grid Display**: Shows all active social links in an easy-to-tap grid
- **Visual Icons**: Each social platform displays with its characteristic color and icon
- **QR Code Popup**: Clicking any icon opens a modal with the QR code
- **Mobile-Friendly**: Large, touch-friendly buttons optimized for tablets and phones
- **Description Support**: Custom links show their description below the icon

## Files Created/Modified

### New Files
1. **migrations/001_add_social_links_table.sql** - Database migration for social links table
2. **api/social-links.php** - Backend API for CRUD operations on social links
3. **api/customer-entries.php** - Backend API to fetch and search customer entries
4. **SOCIAL_LINKS_IMPLEMENTATION.md** - This documentation file

### Modified Files
1. **admin-dashboard.html** - Added Social Links and Customer Entries tabs
2. **admin-dashboard.js** - Added JavaScript functionality for new features
3. **index.html** - Replaced QR Code Generator tab with Social tab

## Database Changes

### New Table: `coiffure_social_links`

```sql
CREATE TABLE coiffure_social_links (
    link_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,
    link_type ENUM('instagram', 'facebook', 'tiktok', 'google_reviews', 'yelp', 'twitter', 'linkedin', 'youtube', 'pinterest', 'custom'),
    link_url VARCHAR(1000) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    description TEXT,
    icon_name VARCHAR(100) NOT NULL DEFAULT 'default',
    qr_code_data LONGTEXT,
    display_order INT UNSIGNED DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE
);
```

## Installation Steps

### 1. Apply Database Migration

Run the SQL migration to create the social links table:

```bash
mysql -u your_username -p salonlyft < migrations/001_add_social_links_table.sql
```

Or execute the SQL directly in your MySQL client.

### 2. No Additional Dependencies

All changes use existing libraries and frameworks already in place:
- Tailwind CSS (already included)
- QRCode.js (already included in index.html)
- Existing API infrastructure

## Usage Guide

### For Administrators

#### Adding a Social Link
1. Log in to the admin dashboard
2. Click the "Social Links" tab
3. Click "+ Add Link" button
4. Fill in the form:
   - Select the salon
   - Choose link type (Instagram, Facebook, etc.)
   - Enter display name (e.g., "Follow us on Instagram")
   - Enter the full URL
   - Optionally add a description (for custom links)
   - Set display order (lower numbers appear first)
5. Click "Save"
6. QR code is automatically generated

#### Editing a Social Link
1. Navigate to Social Links tab
2. Find the link you want to edit
3. Click "Edit"
4. Make your changes
5. Click "Save"
6. QR code is regenerated if URL changed

#### Viewing Customer Entries
1. Click the "Customer Entries" tab
2. Use the search box to find customers by name
3. Filter by salon if managing multiple salons
4. View customer contact info and marketing consent status

### For Customers

#### Using Social Links
1. Open the salon's customer-facing page (index.html)
2. Click the "Social" tab
3. View all available social links and review platforms
4. Tap any icon to see the QR code
5. Use your phone's camera to scan the QR code
6. You'll be redirected to the social platform or review site

## API Endpoints

### Social Links API (`api/social-links.php`)

**GET** - List social links
```
GET /api/social-links.php?salon_id=1&include_inactive=false
```

**POST** - Create social link
```json
{
  "salon_id": 1,
  "link_type": "instagram",
  "link_url": "https://instagram.com/yoursalon",
  "display_name": "Follow us on Instagram",
  "description": "See our latest styles",
  "display_order": 1
}
```

**PUT** - Update social link
```json
{
  "link_id": 1,
  "display_name": "Updated Name",
  "is_active": 1
}
```

**DELETE** - Delete social link
```
DELETE /api/social-links.php?link_id=1
```

### Customer Entries API (`api/customer-entries.php`)

**GET** - List customer entries
```
GET /api/customer-entries.php?salon_id=1&search=john
```

## QR Code Generation

The QR code generation uses a simple SVG-based approach that embeds an external QR code API. For production use, consider implementing a server-side QR code library like:
- **phpqrcode**
- **endroid/qr-code**
- **bacon/bacon-qr-code**

Current implementation uses: https://api.qrserver.com/v1/create-qr-code/

## Security Considerations

1. **Input Validation**: All inputs are validated and sanitized
2. **SQL Injection Protection**: Uses prepared statements throughout
3. **XSS Prevention**: HTML escaping on all user-generated content
4. **Authentication Required**: Admin features require valid session token
5. **GDPR Compliance**: Customer data access is logged in audit trail

## Browser Compatibility

- Modern browsers (Chrome, Firefox, Safari, Edge)
- Mobile browsers (iOS Safari, Chrome Mobile)
- Tablet optimized for easy touch interaction

## Future Enhancements

Potential improvements to consider:
1. **Analytics**: Track QR code scans and social link clicks
2. **Custom Icons**: Allow uploading custom icons for custom links
3. **Link Categories**: Group links (Social Media vs. Reviews)
4. **Bulk Operations**: Add/edit multiple links at once
5. **Export Customer Data**: CSV/Excel export for customer entries
6. **Advanced Search**: Search by email, phone, or consent status

## Support

For issues or questions:
1. Check the browser console for JavaScript errors
2. Verify database migrations were applied correctly
3. Ensure API endpoints are accessible
4. Check that salon_id is correctly configured

## Testing Checklist

- [ ] Database migration applied successfully
- [ ] Can add a social link from admin dashboard
- [ ] Can edit an existing social link
- [ ] Can delete a social link
- [ ] QR code displays correctly in admin view
- [ ] Social links appear on customer-facing page
- [ ] Clicking social icon shows QR code popup
- [ ] Customer entries load in admin dashboard
- [ ] Search by name filters correctly
- [ ] Filter by salon works
- [ ] Marketing consent status displays correctly

---

**Version**: 1.0
**Date**: 2025-11-16
**Status**: Implementation Complete
