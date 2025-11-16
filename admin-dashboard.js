// Configuration
const API_BASE_URL = 'https://clouedo.com/coiffure/api';  // Update with your API URL

// Global state
let currentUser = null;
let sessionToken = null;
let salons = [];
let users = [];

// Initialize
document.addEventListener('DOMContentLoaded', init);

async function init() {
    // Check authentication
    sessionToken = localStorage.getItem('session_token');
    const userDataStr = localStorage.getItem('user_data');

    if (!sessionToken || !userDataStr) {
        redirectToLogin();
        return;
    }

    currentUser = JSON.parse(userDataStr);

    // Check if user has admin permissions
    if (!['admin', 'admin_delegate', 'customer_admin', 'customer_admin_delegate'].includes(currentUser.role)) {
        alert('You do not have permission to access this page');
        redirectToLogin();
        return;
    }

    // Update user info
    updateUserInfo();

    // Hide tabs based on role
    hideTabsBasedOnRole();

    // Setup event listeners
    setupEventListeners();

    // Load initial data
    await loadSalons();
    await loadUsers();

    // Populate salon dropdown for user form
    populateSalonDropdown();
}

function updateUserInfo() {
    const userInfo = document.getElementById('userInfo');
    userInfo.textContent = `${currentUser.full_name} (${currentUser.role})${currentUser.salon_name ? ' - ' + currentUser.salon_name : ''}`;

    // Populate profile form
    document.getElementById('profileUsername').value = currentUser.username;
    document.getElementById('profileEmail').value = currentUser.email;
    document.getElementById('profileFullName').value = currentUser.full_name;
    document.getElementById('profilePhone').value = currentUser.phone || '';
}

function hideTabsBasedOnRole() {
    // Customer admins can't see salon management
    if (['customer_admin', 'customer_admin_delegate'].includes(currentUser.role)) {
        const salonsTab = document.querySelector('[data-tab="salons"]');
        if (salonsTab) salonsTab.style.display = 'none';

        // Switch to users tab by default
        switchTab('users');
    }
}

function setupEventListeners() {
    // Tab switching
    document.querySelectorAll('.tab-button').forEach(button => {
        button.addEventListener('click', () => {
            const tab = button.getAttribute('data-tab');
            switchTab(tab);
        });
    });

    // Logout
    document.getElementById('logoutButton').addEventListener('click', logout);

    // Salon management
    document.getElementById('addSalonButton')?.addEventListener('click', () => openSalonModal());
    document.getElementById('closeSalonModal')?.addEventListener('click', closeSalonModal);
    document.getElementById('salonForm')?.addEventListener('submit', saveSalon);

    // User management
    document.getElementById('addUserButton').addEventListener('click', () => openUserModal());
    document.getElementById('closeUserModal').addEventListener('click', closeUserModal);
    document.getElementById('userForm').addEventListener('submit', saveUser);

    // Filters
    document.getElementById('filterSalon').addEventListener('change', loadUsers);
    document.getElementById('filterRole').addEventListener('change', loadUsers);
    document.getElementById('filterStatus').addEventListener('change', loadUsers);

    // Profile
    document.getElementById('profileForm').addEventListener('submit', updateProfile);
    document.getElementById('passwordForm').addEventListener('submit', changePassword);
}

function switchTab(tabName) {
    // Update buttons
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('active', 'border-purple-600', 'text-purple-600');
        if (button.getAttribute('data-tab') === tabName) {
            button.classList.add('active', 'border-purple-600', 'text-purple-600');
        }
    });

    // Update content
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    document.getElementById(`${tabName}-tab`).classList.add('active');
}

// API Helper
async function apiRequest(endpoint, options = {}) {
    const headers = {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${sessionToken}`,
        ...options.headers
    };

    try {
        const response = await fetch(`${API_BASE_URL}/${endpoint}`, {
            ...options,
            headers
        });

        const data = await response.json();

        if (response.status === 401) {
            alert('Session expired. Please login again.');
            redirectToLogin();
            return null;
        }

        if (!response.ok && response.status !== 400) {
            throw new Error(data.error || 'Request failed');
        }

        return data;
    } catch (error) {
        console.error('API Error:', error);
        alert('Error: ' + error.message);
        return null;
    }
}

// Salons Management
async function loadSalons() {
    const data = await apiRequest('salon-management.php');
    if (data && data.success) {
        salons = data.salons;
        renderSalonsTable();
    }
}

function renderSalonsTable() {
    const tbody = document.getElementById('salonsTableBody');
    if (!tbody) return;

    if (salons.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="px-6 py-4 text-center text-gray-500">No salons found</td></tr>';
        return;
    }

    tbody.innerHTML = salons.map(salon => `
        <tr class="hover:bg-gray-50">
            <td class="px-6 py-4 whitespace-nowrap font-medium text-gray-900">${escapeHtml(salon.salon_name)}</td>
            <td class="px-6 py-4 whitespace-nowrap text-gray-500">${escapeHtml(salon.email)}</td>
            <td class="px-6 py-4 whitespace-nowrap text-gray-500">${escapeHtml(salon.phone)}</td>
            <td class="px-6 py-4 whitespace-nowrap text-gray-500">${salon.user_count || 0}</td>
            <td class="px-6 py-4 whitespace-nowrap">
                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${salon.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}">
                    ${salon.is_active ? 'Active' : 'Inactive'}
                </span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                <button onclick="editSalon(${salon.salon_id})" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</button>
                <button onclick="deleteSalon(${salon.salon_id})" class="text-red-600 hover:text-red-900">Delete</button>
            </td>
        </tr>
    `).join('');
}

function openSalonModal(salonId = null) {
    const modal = document.getElementById('salonModal');
    const title = document.getElementById('salonModalTitle');
    const form = document.getElementById('salonForm');

    form.reset();
    document.getElementById('salonId').value = '';

    if (salonId) {
        const salon = salons.find(s => s.salon_id == salonId);
        if (salon) {
            title.textContent = 'Edit Salon';
            document.getElementById('salonId').value = salon.salon_id;
            document.getElementById('salonName').value = salon.salon_name;
            document.getElementById('salonEmail').value = salon.email;
            document.getElementById('salonPhone').value = salon.phone;
            document.getElementById('salonAddress').value = salon.address || '';
            document.getElementById('salonGoogleUrl').value = salon.google_reviews_url || '';
            document.getElementById('salonFacebookUrl').value = salon.facebook_url || '';
            document.getElementById('salonPolicyVersion').value = salon.policy_version;
        }
    } else {
        title.textContent = 'Add Salon';
    }

    modal.classList.add('active');
}

function closeSalonModal() {
    document.getElementById('salonModal').classList.remove('active');
}

async function saveSalon(e) {
    e.preventDefault();

    const salonId = document.getElementById('salonId').value;
    const formData = {
        salon_name: document.getElementById('salonName').value,
        email: document.getElementById('salonEmail').value,
        phone: document.getElementById('salonPhone').value,
        address: document.getElementById('salonAddress').value,
        google_reviews_url: document.getElementById('salonGoogleUrl').value,
        facebook_url: document.getElementById('salonFacebookUrl').value,
        policy_version: document.getElementById('salonPolicyVersion').value
    };

    let endpoint = 'salon-management.php';
    let method = 'POST';

    if (salonId) {
        endpoint += `?salon_id=${salonId}`;
        method = 'PUT';
    }

    const data = await apiRequest(endpoint, {
        method,
        body: JSON.stringify(formData)
    });

    if (data && data.success) {
        alert(salonId ? 'Salon updated successfully' : 'Salon created successfully');
        closeSalonModal();
        await loadSalons();
        populateSalonDropdown();
    }
}

async function editSalon(salonId) {
    openSalonModal(salonId);
}

async function deleteSalon(salonId) {
    if (!confirm('Are you sure you want to delete this salon? This will also deactivate all users from this salon.')) {
        return;
    }

    const data = await apiRequest(`salon-management.php?salon_id=${salonId}`, {
        method: 'DELETE'
    });

    if (data && data.success) {
        alert('Salon deleted successfully');
        await loadSalons();
    }
}

// Users Management
async function loadUsers() {
    let endpoint = 'user-management.php?';

    const salonFilter = document.getElementById('filterSalon').value;
    const roleFilter = document.getElementById('filterRole').value;
    const statusFilter = document.getElementById('filterStatus').value;

    if (salonFilter) endpoint += `salon_id=${salonFilter}&`;
    if (roleFilter) endpoint += `role=${roleFilter}&`;
    if (statusFilter) endpoint += `is_active=${statusFilter}&`;

    const data = await apiRequest(endpoint);
    if (data && data.success) {
        users = data.users;
        renderUsersTable();
    }
}

function renderUsersTable() {
    const tbody = document.getElementById('usersTableBody');

    if (users.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="px-6 py-4 text-center text-gray-500">No users found</td></tr>';
        return;
    }

    tbody.innerHTML = users.map(user => `
        <tr class="hover:bg-gray-50">
            <td class="px-6 py-4 whitespace-nowrap font-medium text-gray-900">${escapeHtml(user.username)}</td>
            <td class="px-6 py-4 whitespace-nowrap text-gray-500">${escapeHtml(user.full_name)}</td>
            <td class="px-6 py-4 whitespace-nowrap text-gray-500">${escapeHtml(user.email)}</td>
            <td class="px-6 py-4 whitespace-nowrap">
                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                    ${getRoleLabel(user.role)}
                </span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-gray-500">${user.salon_name || '-'}</td>
            <td class="px-6 py-4 whitespace-nowrap">
                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${user.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}">
                    ${user.is_active ? 'Active' : 'Inactive'}
                </span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                <button onclick="editUser(${user.user_id})" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</button>
                ${user.user_id !== currentUser.user_id ? `<button onclick="deleteUser(${user.user_id})" class="text-red-600 hover:text-red-900">Delete</button>` : ''}
            </td>
        </tr>
    `).join('');
}

function getRoleLabel(role) {
    const labels = {
        'admin': 'Admin',
        'admin_delegate': 'Admin Delegate',
        'customer_admin': 'Customer Admin',
        'customer_admin_delegate': 'Customer Admin Delegate',
        'customer_user': 'Customer User'
    };
    return labels[role] || role;
}

function openUserModal(userId = null) {
    const modal = document.getElementById('userModal');
    const title = document.getElementById('userModalTitle');
    const form = document.getElementById('userForm');
    const passwordField = document.getElementById('passwordField');

    form.reset();
    document.getElementById('userId').value = '';

    if (userId) {
        const user = users.find(u => u.user_id == userId);
        if (user) {
            title.textContent = 'Edit User';
            document.getElementById('userId').value = user.user_id;
            document.getElementById('userUsername').value = user.username;
            document.getElementById('userUsername').readOnly = true;
            document.getElementById('userEmail').value = user.email;
            document.getElementById('userFullName').value = user.full_name;
            document.getElementById('userPhone').value = user.phone || '';
            document.getElementById('userRole').value = user.role;
            document.getElementById('userSalon').value = user.salon_id || '';
            document.getElementById('userStatus').value = user.is_active ? '1' : '0';

            // Password optional for editing
            passwordField.querySelector('label').textContent = 'Password (leave blank to keep current)';
            document.getElementById('userPassword').required = false;
        }
    } else {
        title.textContent = 'Add User';
        document.getElementById('userUsername').readOnly = false;
        passwordField.querySelector('label').textContent = 'Password * (min 8 chars)';
        document.getElementById('userPassword').required = true;
    }

    modal.classList.add('active');
}

function closeUserModal() {
    document.getElementById('userModal').classList.remove('active');
}

async function saveUser(e) {
    e.preventDefault();

    const userId = document.getElementById('userId').value;
    const formData = {
        username: document.getElementById('userUsername').value,
        email: document.getElementById('userEmail').value,
        full_name: document.getElementById('userFullName').value,
        phone: document.getElementById('userPhone').value,
        role: document.getElementById('userRole').value,
        salon_id: document.getElementById('userSalon').value || null,
        is_active: document.getElementById('userStatus').value === '1'
    };

    const password = document.getElementById('userPassword').value;
    if (password) {
        formData.password = password;
    }

    let endpoint = 'user-management.php';
    let method = 'POST';

    if (userId) {
        endpoint += `?user_id=${userId}`;
        method = 'PUT';
        delete formData.username;  // Can't update username
    }

    const data = await apiRequest(endpoint, {
        method,
        body: JSON.stringify(formData)
    });

    if (data && data.success) {
        alert(userId ? 'User updated successfully' : 'User created successfully');
        closeUserModal();
        await loadUsers();
    }
}

function editUser(userId) {
    openUserModal(userId);
}

async function deleteUser(userId) {
    if (!confirm('Are you sure you want to delete this user?')) {
        return;
    }

    const data = await apiRequest(`user-management.php?user_id=${userId}`, {
        method: 'DELETE'
    });

    if (data && data.success) {
        alert('User deleted successfully');
        await loadUsers();
    }
}

// Profile Management
async function updateProfile(e) {
    e.preventDefault();

    const formData = {
        email: document.getElementById('profileEmail').value,
        full_name: document.getElementById('profileFullName').value,
        phone: document.getElementById('profilePhone').value
    };

    const data = await apiRequest(`user-management.php?user_id=${currentUser.user_id}`, {
        method: 'PUT',
        body: JSON.stringify(formData)
    });

    if (data && data.success) {
        alert('Profile updated successfully');
        // Update local user data
        currentUser.email = formData.email;
        currentUser.full_name = formData.full_name;
        currentUser.phone = formData.phone;
        localStorage.setItem('user_data', JSON.stringify(currentUser));
        updateUserInfo();
    }
}

async function changePassword(e) {
    e.preventDefault();

    const newPassword = document.getElementById('newPassword').value;
    const confirmPassword = document.getElementById('confirmPassword').value;

    if (newPassword !== confirmPassword) {
        alert('Passwords do not match');
        return;
    }

    if (newPassword.length < 8) {
        alert('Password must be at least 8 characters long');
        return;
    }

    const data = await apiRequest(`user-management.php?user_id=${currentUser.user_id}`, {
        method: 'PUT',
        body: JSON.stringify({ password: newPassword })
    });

    if (data && data.success) {
        alert('Password changed successfully');
        document.getElementById('passwordForm').reset();
    }
}

// Helpers
function populateSalonDropdown() {
    const salonSelects = [
        document.getElementById('userSalon'),
        document.getElementById('filterSalon')
    ];

    salonSelects.forEach(select => {
        if (!select) return;

        const currentValue = select.value;
        const isFilter = select.id === 'filterSalon';

        select.innerHTML = isFilter ? '<option value="">All Salons</option>' : '<option value="">None (for admin roles)</option>';

        salons.filter(s => s.is_active).forEach(salon => {
            const option = document.createElement('option');
            option.value = salon.salon_id;
            option.textContent = salon.salon_name;
            select.appendChild(option);
        });

        if (currentValue) {
            select.value = currentValue;
        }
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

async function logout() {
    if (!confirm('Are you sure you want to logout?')) {
        return;
    }

    await apiRequest('auth-logout.php', { method: 'POST' });

    localStorage.removeItem('session_token');
    localStorage.removeItem('user_data');
    redirectToLogin();
}

function redirectToLogin() {
    window.location.href = 'login.html';
}
