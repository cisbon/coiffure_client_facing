# User-Salon N:N Relationship Update

## Overview
This update changes the user-salon relationship from one-to-many (N:1) to many-to-many (N:N), allowing users to be assigned to multiple salons.

## Changes Made

### 1. Database Migration

**New Table: `coiffure_user_salons`** (Junction Table)
```sql
CREATE TABLE coiffure_user_salons (
    user_salon_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    salon_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES coiffure_users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_salon (user_id, salon_id)
);
```

**Migration File**: `migrations/002_user_salons_many_to_many.sql`
- Creates the junction table
- Migrates existing data from `coiffure_users.salon_id` to the junction table
- Keeps `salon_id` column for backward compatibility (deprecated but not removed)

### 2. API Updates

#### **salon-management.php**
- ✅ **Fixed 403 Error**: Now allows `customer_admin` and `customer_admin_delegate` roles to view salons
- ✅ **Role-Based Filtering**: Customer admins only see salons they're assigned to via junction table
- ✅ **Read-Only Access**: Customer admins can view salons but cannot create/update/delete (admin-only operations)

**Query Changes**:
```sql
-- For customer_admin roles, salons are filtered using EXISTS clause:
SELECT s.* FROM coiffure_salons s
WHERE EXISTS (
    SELECT 1 FROM coiffure_user_salons us
    WHERE us.salon_id = s.salon_id AND us.user_id = ?
)
```

### 3. Admin Dashboard Updates

#### **admin-dashboard.js**

**New Feature: Single Salon Auto-Selection**
- When a `customer_admin` has only **1 assigned salon**:
  - ✅ Salon filter dropdowns are hidden
  - ✅ Salon name is displayed instead (purple badge)
  - ✅ Salon ID is automatically selected for all operations
  - ✅ User cannot change salon selection

**New Function**: `handleSingleSalonAutoSelection()`
- Checks if user is customer_admin with single salon
- Hides salon filter dropdowns:
  - Social Links filter
  - Customer Entries filter
  - Social Link creation modal
- Replaces filters with read-only display showing salon name

**Visual Changes for Single Salon**:
```html
<!-- Instead of dropdown, shows: -->
<div class="bg-purple-50 border border-purple-200 rounded-lg px-4 py-3">
    <span class="text-sm font-medium text-gray-700">Salon: </span>
    <span class="font-semibold text-purple-700">Demo Salon</span>
</div>
```

## Installation

### 1. Apply Database Migration

Run the SQL migration to create the junction table:

```bash
mysql -u your_username -p salonlyft < migrations/002_user_salons_many_to_many.sql
```

Or execute in MySQL client:
```sql
source migrations/002_user_salons_many_to_many.sql;
```

### 2. Assign Salons to Users

For existing users, the migration automatically transfers their single salon assignment to the junction table.

For new multi-salon assignments, insert into the junction table:

```sql
-- Assign user to multiple salons
INSERT INTO coiffure_user_salons (user_id, salon_id) VALUES
(123, 1),  -- Assign user 123 to salon 1
(123, 2),  -- Also assign user 123 to salon 2
(123, 3);  -- And to salon 3
```

### 3. No Code Changes Needed

The frontend automatically detects:
- User role (customer_admin vs admin)
- Number of assigned salons
- Adjusts UI accordingly

## User Experience

### For Admin / Admin Delegate
- ✅ Can see **all salons**
- ✅ Salon filter dropdowns visible
- ✅ Can create/edit/delete salons
- ✅ Can assign users to multiple salons

### For Customer Admin (Multiple Salons)
- ✅ Can see **only assigned salons**
- ✅ Salon filter dropdowns visible
- ✅ Can switch between assigned salons
- ✅ **Cannot** create/edit/delete salons
- ✅ Can manage social links for assigned salons
- ✅ Can view customer entries for assigned salons

### For Customer Admin (Single Salon)
- ✅ Can see **only their one salon**
- ✅ **Salon filters are hidden**
- ✅ Salon name displayed as badge
- ✅ All operations auto-use that salon
- ✅ **Cannot** create/edit/delete salons
- ✅ Can manage social links for their salon
- ✅ Can view customer entries for their salon

## API Endpoints

### Get Salons (customer_admin)
```http
GET /api/salon-management.php
Authorization: Bearer {session_token}

Response:
{
  "success": true,
  "salons": [
    {
      "salon_id": 1,
      "salon_name": "Demo Salon",
      ...
    }
  ],
  "count": 1
}
```

### Assign User to Multiple Salons

**Manual SQL Method** (No API endpoint yet):
```sql
-- Remove all current assignments
DELETE FROM coiffure_user_salons WHERE user_id = 123;

-- Add new assignments
INSERT INTO coiffure_user_salons (user_id, salon_id) VALUES
(123, 1),
(123, 2),
(123, 5);
```

## Testing

### Test Scenario 1: Customer Admin with Single Salon

1. Create a test user with role `customer_admin`
2. Assign them to 1 salon via junction table:
   ```sql
   INSERT INTO coiffure_user_salons (user_id, salon_id) VALUES (USER_ID, SALON_ID);
   ```
3. Log in as that user
4. Verify:
   - ✅ No 403 error on dashboard load
   - ✅ Salon filter is hidden
   - ✅ Salon name is displayed as badge
   - ✅ Social links show only for that salon
   - ✅ Customer entries show only for that salon

### Test Scenario 2: Customer Admin with Multiple Salons

1. Create a test user with role `customer_admin`
2. Assign them to 3 salons:
   ```sql
   INSERT INTO coiffure_user_salons (user_id, salon_id) VALUES
   (USER_ID, SALON_1),
   (USER_ID, SALON_2),
   (USER_ID, SALON_3);
   ```
3. Log in as that user
4. Verify:
   - ✅ No 403 error
   - ✅ Salon filter dropdown is visible
   - ✅ Dropdown shows only their 3 salons
   - ✅ Can switch between salons
   - ✅ Data updates when changing salon filter

### Test Scenario 3: Admin User

1. Log in as admin
2. Verify:
   - ✅ Can see all salons
   - ✅ Can create new salons
   - ✅ Can edit salons
   - ✅ Can delete salons
   - ✅ Salon filter shows all salons

## Backward Compatibility

### Deprecated: `coiffure_users.salon_id`

The `salon_id` column in `coiffure_users` table is **deprecated** but **NOT removed**:
- ✅ Old code using this column will still work
- ✅ New code should use the junction table instead
- ⚠️ May be removed in future version

### Recommended: Always Use Junction Table

New features should query user salons via:
```sql
SELECT salon_id FROM coiffure_user_salons WHERE user_id = ?
```

Instead of:
```sql
SELECT salon_id FROM coiffure_users WHERE user_id = ?  -- Deprecated
```

## Future Enhancements

Potential improvements to consider:

1. **User Management UI**: Add interface to assign users to multiple salons
2. **Salon Assignment API**: Create endpoint to manage user-salon assignments
3. **Bulk Assignment**: Allow assigning multiple users to a salon at once
4. **Assignment History**: Track when users were assigned/removed from salons
5. **Permission Levels**: Add granular permissions per salon (view-only, edit, admin)

## Troubleshooting

### Issue: Still getting 403 error

**Solution**: Ensure migration was applied:
```sql
-- Check if junction table exists
SHOW TABLES LIKE 'coiffure_user_salons';

-- Check if user has salon assignments
SELECT * FROM coiffure_user_salons WHERE user_id = YOUR_USER_ID;
```

### Issue: Salon filter not hiding for single salon

**Solution**:
1. Clear browser cache
2. Check browser console for JavaScript errors
3. Verify user has exactly 1 salon:
   ```sql
   SELECT COUNT(*) FROM coiffure_user_salons WHERE user_id = YOUR_USER_ID;
   ```

### Issue: User sees no salons

**Solution**: Assign user to at least one salon:
```sql
INSERT INTO coiffure_user_salons (user_id, salon_id)
VALUES (YOUR_USER_ID, YOUR_SALON_ID);
```

## Files Modified

- **migrations/002_user_salons_many_to_many.sql** - New migration
- **api/salon-management.php** - Updated to support N:N and customer_admin access
- **admin-dashboard.js** - Added auto-selection logic for single salon
- **SALON_ASSIGNMENT_UPDATE.md** - This documentation

---

**Version**: 1.0
**Date**: 2025-11-16
**Status**: Complete
