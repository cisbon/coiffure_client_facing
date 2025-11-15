# SalonLyft - Web App Suite for Hairdressing Salons

A complete, GDPR-compliant customer experience suite for hairdressing salons, featuring customer onboarding, QR code generation for reviews, and AI-powered virtual hairstyle consultations.

## Features

### 1. GDPR-Compliant Customer Onboarding
- Complete customer registration form with name, email, and phone
- GDPR-compliant consent management with checkboxes
- Digital signature capture via finger painting/mouse
- Policy acceptance tracking (cancellation policy, data processing)
- Data retention management
- Full audit trail logging
- Mobile-responsive tablet interface

### 2. QR Code Generator for Reviews
- Generate QR codes for Google Reviews, Facebook, Instagram, or custom URLs
- Real-time QR code display on tablet
- Customers can scan with their phones to immediately post reviews
- Download QR codes for printing
- Usage tracking and analytics
- Staff instructions included

### 3. AI Virtual Hairstyle Consultation
- Photo upload via drag & drop or file selector
- Text-based style prompts (e.g., "long wavy hair", "celebrity hairstyle")
- Integration with Open Router API (Google Gemini 2.5 Flash Image)
- Professional AI hairstyle analysis and recommendations
- Session tracking and history
- Mobile-optimized interface

## Tech Stack

### Frontend
- **Single HTML File**: Pure HTML/CSS/JavaScript (no frameworks)
- **Styling**: Tailwind CSS (CDN)
- **QR Generation**: QRCode.js library
- **Hosting**: GitHub Pages compatible

### Backend
- **Language**: PHP 7.4+
- **Database**: MySQL 5.7+ / MariaDB 10.3+
- **Database Driver**: MySQLi with prepared statements
- **API Integration**: Open Router API for AI features
- **Hosting**: clouedo.com/coiffure/api/

### Database
- **MySQL/MariaDB** with UTF-8 (utf8mb4) encoding
- **Tables**:
  - `coiffure_salons` - Salon information
  - `coiffure_customers` - GDPR-compliant customer data
  - `coiffure_qr_codes` - QR code generation tracking
  - `coiffure_ai_consultations` - AI consultation sessions
  - `coiffure_audit_log` - GDPR compliance audit trail

## File Structure

```
coiffure/
├── index.html                  # Frontend (deploy to GitHub Pages)
├── api/                        # Backend (deploy to clouedo.com/coiffure/api/)
│   ├── .env                   # Environment variables (DO NOT COMMIT!)
│   ├── .env.example           # Environment template
│   ├── config.php             # Database connection & utilities
│   ├── customer.php           # Customer onboarding endpoint
│   ├── qr-generate.php        # QR code generation endpoint
│   └── ai-consultation.php    # AI consultation endpoint
├── mysql_schema.sql           # Database schema
└── README.md                  # This file
```

## Installation & Setup

### Step 1: Database Setup

1. **Create MySQL Database**:
   ```bash
   mysql -u your_username -p
   ```

2. **Import Schema**:
   ```sql
   mysql -u your_username -p < mysql_schema.sql
   ```

   Or manually create the database:
   ```sql
   CREATE DATABASE salonlyft CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   USE salonlyft;
   SOURCE mysql_schema.sql;
   ```

3. **Verify Tables**:
   ```sql
   SHOW TABLES;
   -- Should show: coiffure_salons, coiffure_customers, coiffure_qr_codes,
   --              coiffure_ai_consultations, coiffure_audit_log
   ```

### Step 2: Backend API Setup (clouedo.com)

1. **Upload API Files**:
   ```bash
   # Upload the entire api/ directory to clouedo.com/coiffure/api/
   # Using FTP, SFTP, or your hosting provider's file manager
   ```

2. **Create .env File**:
   ```bash
   cd /path/to/clouedo.com/coiffure/api/
   cp .env.example .env
   nano .env
   ```

3. **Configure .env**:
   ```env
   # MySQL Database Configuration
   DB_HOST=localhost
   DB_PORT=3306
   DB_NAME=salonlyft
   DB_USERNAME=your_mysql_username
   DB_PASSWORD=your_mysql_password

   # Open Router API Configuration
   OPENROUTER_API_KEY=your_openrouter_api_key_here
   AI_MODEL=google/gemini-2.5-flash-image

   # Application Settings
   APP_ENV=production
   APP_DEBUG=false

   # CORS Settings (add your GitHub Pages URL)
   ALLOWED_ORIGINS=https://yourusername.github.io,http://localhost:3000

   # Default Salon ID
   DEFAULT_SALON_ID=1

   # File Upload Settings
   MAX_UPLOAD_SIZE=5242880
   ALLOWED_IMAGE_TYPES=image/jpeg,image/png,image/jpg,image/webp
   ```

4. **Set File Permissions**:
   ```bash
   chmod 644 .env
   chmod 644 config.php
   chmod 755 *.php
   ```

5. **Test API Endpoints**:
   ```bash
   # Test customer endpoint
   curl -X POST https://clouedo.com/coiffure/api/customer.php \
     -H "Content-Type: application/json" \
     -d '{"full_name":"Test User","email":"test@example.com","phone":"+1234567890","consent_data_processing":true,"consent_cancellation_policy":true,"policy_version":"1.0"}'
   ```

### Step 3: Frontend Setup (GitHub Pages)

1. **Create GitHub Repository**:
   ```bash
   git init
   git add index.html README.md
   git commit -m "Initial commit: SalonLyft frontend"
   git branch -M main
   git remote add origin https://github.com/yourusername/salonlyft.git
   git push -u origin main
   ```

2. **Enable GitHub Pages**:
   - Go to repository Settings
   - Navigate to Pages section
   - Select "main" branch as source
   - Save and note your GitHub Pages URL (e.g., `https://yourusername.github.io/salonlyft/`)

3. **Update CORS Settings**:
   - Add your GitHub Pages URL to `ALLOWED_ORIGINS` in the backend `.env` file

4. **Update API URL in Frontend** (if needed):
   - Edit `index.html`
   - Update the `API_BASE_URL` constant:
     ```javascript
     const API_BASE_URL = 'https://clouedo.com/coiffure/api';
     ```

### Step 4: Open Router API Setup

1. **Get API Key**:
   - Visit [OpenRouter.ai](https://openrouter.ai/)
   - Create an account
   - Generate an API key
   - Add credits to your account

2. **Add API Key to .env**:
   ```env
   OPENROUTER_API_KEY=sk-or-v1-your-actual-api-key-here
   ```

## Configuration

### MySQL Connection

Edit `api/config.php` or use environment variables in `.env`:

```php
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'salonlyft');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
```

### CORS Configuration

For security, restrict CORS to your actual frontend domain:

```env
# In .env file
ALLOWED_ORIGINS=https://yourusername.github.io
```

### Salon Configuration

Update default salon information in the database:

```sql
UPDATE coiffure_salons
SET
    salon_name = 'Your Salon Name',
    email = 'info@yoursalon.com',
    phone = '+1234567890',
    google_reviews_url = 'https://g.page/yoursalon/review',
    facebook_url = 'https://facebook.com/yoursalon'
WHERE salon_id = 1;
```

## API Endpoints

### 1. Customer Onboarding
**Endpoint**: `POST /api/customer.php`

**Request**:
```json
{
    "full_name": "John Doe",
    "email": "john@example.com",
    "phone": "+1234567890",
    "consent_marketing": false,
    "consent_data_processing": true,
    "consent_cancellation_policy": true,
    "policy_version": "1.0",
    "signature_data": "data:image/png;base64,..."
}
```

**Response**:
```json
{
    "success": true,
    "message": "Customer registered successfully",
    "customer_id": 123,
    "action": "created",
    "data_retention_until": "2028-01-15"
}
```

### 2. QR Code Generation
**Endpoint**: `POST /api/qr-generate.php`

**Request**:
```json
{
    "target_url": "https://g.page/yoursalon/review",
    "qr_type": "google_reviews",
    "notes": "Main reception QR code"
}
```

**Response**:
```json
{
    "success": true,
    "message": "QR code data saved successfully",
    "qr_id": 456,
    "target_url": "https://g.page/yoursalon/review",
    "qr_type": "google_reviews",
    "generation_count": 1,
    "action": "created"
}
```

### 3. AI Consultation
**Endpoint**: `POST /api/ai-consultation.php`

**Request**:
```json
{
    "image_base64": "data:image/jpeg;base64,...",
    "style_prompt": "Long wavy hair with blonde highlights",
    "customer_id": 123
}
```

**Response**:
```json
{
    "success": true,
    "message": "AI consultation completed successfully",
    "consultation_id": 789,
    "session_id": "session_abc123",
    "ai_response": "Based on your photo, here are my recommendations...",
    "processing_time_ms": 2500,
    "tokens_used": 150,
    "model_used": "google/gemini-2.5-flash-image"
}
```

## Security Features

### GDPR Compliance
- ✅ Explicit consent collection
- ✅ Data processing purpose declaration
- ✅ Right to access (data export)
- ✅ Right to erasure (data deletion)
- ✅ Consent timestamp recording
- ✅ Audit trail logging
- ✅ Data retention management

### Security Best Practices
- ✅ MySQLi prepared statements (SQL injection protection)
- ✅ Input validation and sanitization (XSS protection)
- ✅ CORS configuration
- ✅ Secure password storage (not storing passwords)
- ✅ Environment variable protection (.env not in version control)
- ✅ File upload validation
- ✅ Rate limiting ready (configurable)

## Usage

### For Salon Staff

1. **Customer Onboarding**:
   - Navigate to "Customer Onboarding" tab
   - Help customer fill in their information
   - Ensure they read and accept all policies
   - Ask them to sign with their finger on the tablet
   - Submit the form

2. **QR Code Generator**:
   - Navigate to "QR Code Generator" tab
   - Select QR code type (Google Reviews, Facebook, etc.)
   - Enter the URL
   - Click "Generate QR Code"
   - Show the QR code to customers
   - They scan with their phone and are redirected to review/follow

3. **AI Hairstyle Consultation**:
   - Navigate to "AI Hairstyle Consultation" tab
   - Help customer upload their photo
   - Ask what hairstyle they're interested in
   - Submit for AI analysis
   - Review AI recommendations together

### For Customers

1. Simply follow salon staff instructions
2. Fill in required information
3. Read privacy policies carefully
4. Provide consent where comfortable
5. Sign digitally
6. Scan QR codes to leave reviews
7. Upload photos for AI hairstyle previews

## Troubleshooting

### Database Connection Issues
```php
// Check connection in config.php
$conn = getDbConnection();
if (!$conn) {
    die("Connection failed: Check DB credentials in .env");
}
```

### CORS Errors
- Ensure your GitHub Pages URL is in `ALLOWED_ORIGINS`
- Check browser console for specific CORS errors
- Verify API endpoints return proper headers

### API Key Issues
- Verify Open Router API key is correct
- Check API key has sufficient credits
- Ensure API key has proper permissions

### File Upload Issues
- Check `MAX_UPLOAD_SIZE` in .env
- Verify PHP `upload_max_filesize` and `post_max_size`
- Check file permissions on server

## Development

### Local Testing

1. **Frontend**:
   ```bash
   # Simple HTTP server
   python3 -m http.server 8000
   # Visit http://localhost:8000
   ```

2. **Backend**:
   ```bash
   # Using PHP built-in server
   cd api/
   php -S localhost:8080
   ```

3. **Update API URL for local testing**:
   ```javascript
   const API_BASE_URL = 'http://localhost:8080';
   ```

## License

Copyright © 2025 SalonLyft. All rights reserved.

## Support

For issues, questions, or feature requests, please contact your system administrator.

## Changelog

### Version 1.0 (2025-01-15)
- Initial release
- Customer onboarding with GDPR compliance
- QR code generator for reviews
- AI virtual hairstyle consultation
- Complete database schema
- RESTful API endpoints
- Mobile-responsive design
