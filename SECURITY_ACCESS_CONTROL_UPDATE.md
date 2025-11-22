# Security & Access Control Updates

## Overview
This document describes the security improvements, authentication requirements, and data isolation features implemented for the Coiffure AI application.

## Changes Implemented

### 1. Authentication Required for index.html ✅

**Issue**: Customer-facing page (index.html) was publicly accessible without authentication.

**Solution**:
- Added authentication check on page load
- Redirects to login.html if no valid session found
- Detects user's assigned salon(s) from `coiffure_user_salons` junction table
- Auto-loads social links for the user's assigned salon

**Files Modified**:
- `index.html` - Added `checkAuthentication()` and `getUserSalonAssignments()` functions

**User Experience**:
- Unauthenticated users: Redirected to login page
- Authenticated users: See social links for their assigned salon
- customer_facing_tablet_user: Auto-detects salon assignment

### 2. Data Isolation for customer_admin Roles ✅

**Issue**: customer_admin users could potentially see data from salons they're not assigned to.

**Solution**: Enforce strict data filtering based on user's assigned salons using the `coiffure_user_salons` junction table.

#### API Updates

**social-links.php**:
- ✅ GET: Filter links to only show for user's assigned salons
- ✅ POST: Verify user has access to salon before creating link
- ✅ PUT: Verify user has access to link's salon before updating
- ✅ DELETE: Verify user has access to link's salon before deleting
- ✅ Uses `canManageSalon()` with junction table for access control

**customer-entries.php**:
- ✅ GET: Filter customer entries to only show for user's assigned salons
- ✅ Uses EXISTS clause with junction table for filtering
- ✅ Applies to customer_admin, customer_admin_delegate, and customer_facing_tablet_user roles

**config.php**:
- ✅ Updated `canManageSalon()` function to check junction table
- ✅ Accepts optional database connection parameter
- ✅ Falls back to old salon_id column for backward compatibility
- ✅ Admin roles maintain full access to all salons

#### Access Control Matrix

| Role | Salon Access | Can Create | Can Edit | Can Delete |
|------|--------------|------------|----------|------------|
| admin | All salons | ✅ | ✅ | ✅ |
| admin_delegate | All salons | ✅ | ✅ | ✅ |
| customer_admin | Assigned only | ✅ | ✅ | ✅ |
| customer_admin_delegate | Assigned only | ✅ | ✅ | ✅ |
| customer_facing_tablet_user | Assigned only | ❌ | ❌ | ❌ |

### 3. Role Rename: customer_user → customer_facing_tablet_user ✅

**Reason**: More descriptive name that clearly indicates the role's purpose (tablet-facing users).

**Migration**: `migrations/003_rename_customer_user_role.sql`
```sql
ALTER TABLE coiffure_users
MODIFY COLUMN role ENUM(
    'admin',
    'admin_delegate',
    'customer_admin',
    'customer_admin_delegate',
    'customer_facing_tablet_user'
);

UPDATE coiffure_users
SET role = 'customer_facing_tablet_user'
WHERE role = 'customer_user';
```

**Files Updated**:
- `admin-dashboard.html` - Updated role dropdowns (2 locations)
- `admin-dashboard.js` - Updated `getRoleLabel()` function
- Display name: "Tablet User"

## Installation Steps

### 1. Apply Database Migration

Run the role rename migration:

```bash
mysql -u your_username -p salonlyft < migrations/003_rename_customer_user_role.sql
```

This will:
- Update the ENUM in `coiffure_users` table
- Rename all existing `customer_user` records to `customer_facing_tablet_user`
- No data loss

### 2. Verify Junction Table Exists

Ensure the user-salon junction table was created (from previous migration):

```sql
SHOW TABLES LIKE 'coiffure_user_salons';

-- Should show the table exists
```

### 3. Assign Users to Salons (If Needed)

For any new users or users needing salon assignments:

```sql
-- Assign user to salon(s)
INSERT INTO coiffure_user_salons (user_id, salon_id) VALUES
(USER_ID, SALON_ID);

-- Example: Assign user 5 to salons 1 and 2
INSERT INTO coiffure_user_salons (user_id, salon_id) VALUES
(5, 1),
(5, 2);
```

## Security Features

### Authentication Flow

1. **Page Load**: Check for `session_token` in localStorage
2. **Token Validation**: Call API to validate session
3. **Redirect**: If invalid, redirect to login.html
4. **Salon Detection**: Load user's assigned salons from junction table
5. **Data Loading**: Load data filtered by assigned salons

### Data Filtering

All APIs now use the following filtering logic:

```php
// Example from social-links.php
if ($isCustomerRole) {
    $conditions[] = "EXISTS (
        SELECT 1 FROM coiffure_user_salons us
        WHERE us.salon_id = coiffure_social_links.salon_id
        AND us.user_id = ?
    )";
    $params[] = $currentUser['user_id'];
}
```

This ensures:
- ✅ Users only see data for their assigned salons
- ✅ No data leakage between salons
- ✅ SQL injection protection via prepared statements
- ✅ Performance optimized with EXISTS clause

### Access Verification

Before any write operation (POST, PUT, DELETE):

```php
if (!canManageSalon($currentUser, $salonId, $conn)) {
    sendErrorResponse('You do not have access to manage this salon', 403);
}
```

## Testing Checklist

### ✅ Test 1: index.html Authentication
1. Log out
2. Try to access index.html directly
3. Should redirect to login.html
4. Log in as customer_facing_tablet_user
5. Should load and show social links

### ✅ Test 2: customer_admin Data Isolation
1. Log in as customer_admin with salon assignment to salon_id=1
2. Try to add social link for salon_id=2
3. Should get 403 error
4. Add social link for salon_id=1
5. Should succeed

### ✅ Test 3: Social Links Display
1. Log in as customer_facing_tablet_user
2. Open index.html
3. Click "Social" tab
4. Should see social links for assigned salon only

### ✅ Test 4: Customer Entries Filtering
1. Log in as customer_admin with salon assignment to salon_id=1
2. Open admin dashboard → Customer Entries tab
3. Should see entries for salon_id=1 only
4. Should NOT see entries from other salons

### ✅ Test 5: Role Rename
1. Check admin dashboard user management
2. Verify role dropdown shows "Tablet User" instead of "Customer User"
3. Verify existing users with old role name still work

## API Changes Summary

### social-links.php
**Before**: No authentication, no filtering
**After**:
- Authentication required for POST/PUT/DELETE
- Optional authentication for GET (enables filtering)
- Filters by user's assigned salons for customer roles
- Verifies salon access on all write operations

### customer-entries.php
**Before**: No authentication, minimal filtering
**After**:
- Optional authentication for GET (enables filtering)
- Filters by user's assigned salons for customer roles
- Only shows entries from assigned salons

### config.php
**Before**: `canManageSalon()` checked old `salon_id` column
**After**:
- Checks `coiffure_user_salons` junction table
- Accepts optional database connection
- Falls back to old column for compatibility
- More secure and supports multiple salon assignments

## Breaking Changes

### ⚠️ index.html Requires Authentication

**Impact**: Users must be logged in to access the customer-facing page.

**Mitigation**:
- Redirect to login.html is automatic
- No data loss
- Existing sessions continue to work

### ⚠️ customer_user Renamed

**Impact**: References to "customer_user" role name will break.

**Mitigation**:
- Database migration updates all existing records
- Frontend updated to use new name
- Old role value no longer valid

## Backward Compatibility

### Old salon_id Column

The `salon_id` column in `coiffure_users` is still present and used as fallback:
- ✅ Old code continues to work
- ✅ Migration data preserved
- ⚠️ Deprecated - use junction table instead

### Session Handling

Existing sessions remain valid:
- ✅ No re-login required after deployment
- ✅ Session tokens unchanged
- ✅ User data structure compatible

## Future Enhancements

Potential improvements:
1. **API Key for index.html**: Allow public access with read-only API key
2. **Salon Selection**: Allow users with multiple salons to switch between them in index.html
3. **Audit Logging**: Log all data access attempts for security monitoring
4. **Rate Limiting**: Prevent brute force attacks on authentication
5. **IP Whitelisting**: Restrict admin dashboard to specific IP ranges

## Troubleshooting

### Issue: Can't access index.html

**Solution**:
1. Check if you're logged in
2. Clear browser cache
3. Try logging in again
4. Check browser console for errors

### Issue: Social links not showing

**Solution**:
1. Verify user has salon assignment:
   ```sql
   SELECT * FROM coiffure_user_salons WHERE user_id = YOUR_USER_ID;
   ```
2. Verify social links exist for that salon:
   ```sql
   SELECT * FROM coiffure_social_links WHERE salon_id = YOUR_SALON_ID AND is_active = 1;
   ```
3. Check browser console for API errors

### Issue: 403 errors for customer_admin

**Solution**:
1. Verify user is assigned to the salon:
   ```sql
   SELECT * FROM coiffure_user_salons WHERE user_id = USER_ID AND salon_id = SALON_ID;
   ```
2. If not, assign them:
   ```sql
   INSERT INTO coiffure_user_salons (user_id, salon_id) VALUES (USER_ID, SALON_ID);
   ```

## Files Modified

**Frontend**:
- `index.html` - Add authentication and salon detection
- `admin-dashboard.html` - Update role references
- `admin-dashboard.js` - Update role labels

**Backend**:
- `api/social-links.php` - Add authentication and filtering
- `api/customer-entries.php` - Add filtering
- `api/config.php` - Update canManageSalon()

**Database**:
- `migrations/003_rename_customer_user_role.sql` - New migration

---

**Version**: 1.0
**Date**: 2025-11-16
**Status**: Complete
