// Configuration
const API_BASE_URL = 'https://clouedo.com/coiffure/api';  // Update with your API URL

// Global state
let currentUser = null;
let sessionToken = null;
let salons = [];
let users = [];
let socialLinks = [];
let customerEntries = [];

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

    // Set current salon and load its language
    await loadSalonLanguage();

    await loadUsers();
    await loadSocialLinks();
    await loadCustomerEntries();

    // Populate salon dropdown for user form
    populateSalonDropdown();
}

async function loadSalonLanguage() {
    // Get user's first assigned salon
    if (salons && salons.length > 0) {
        window.currentSalon = salons[0];
        const salonLanguage = window.currentSalon.default_language || 'de';

        console.log('Loading salon language:', salonLanguage, 'for salon:', window.currentSalon.salon_name);

        // Load and apply salon's language
        if (typeof i18n !== 'undefined') {
            await i18n.loadLanguage(salonLanguage);
            i18n.applyTranslations();
        }
    } else {
        console.warn('No salons found for user');
    }
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

    // Social Links
    document.getElementById('addSocialLinkButton').addEventListener('click', () => openSocialLinkModal());
    document.getElementById('closeSocialLinkModal').addEventListener('click', closeSocialLinkModal);
    document.getElementById('socialLinkForm').addEventListener('submit', saveSocialLink);
    document.getElementById('socialLinkSalonFilter').addEventListener('change', loadSocialLinks);
    document.getElementById('closeQrCodeModal').addEventListener('click', closeQrCodeModal);

    // Customer Entries
    document.getElementById('customerSearchInput').addEventListener('input', debounce(loadCustomerEntries, 300));
    document.getElementById('customerSalonFilter').addEventListener('change', loadCustomerEntries);
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

        // Handle single salon auto-selection for customer_admin roles
        handleSingleSalonAutoSelection();
    }
}

function handleSingleSalonAutoSelection() {
    const isCustomerRole = ['customer_admin', 'customer_admin_delegate'].includes(currentUser.role);

    if (isCustomerRole && salons.length === 1) {
        // Auto-select the single salon
        const singleSalon = salons[0];

        // Hide and auto-select for all salon filter dropdowns
        const salonFilters = [
            { id: 'socialLinkSalonFilter', label: document.querySelector('label[for="socialLinkSalonFilter"]')?.closest('div') },
            { id: 'customerSalonFilter', label: document.querySelector('label[for="customerSalonFilter"]')?.closest('div') },
            { id: 'linkSalonId', label: document.querySelector('label[for="linkSalonId"]')?.closest('div') }
        ];

        salonFilters.forEach(filter => {
            const select = document.getElementById(filter.id);
            if (select) {
                // Set value to the single salon
                select.value = singleSalon.salon_id;

                // Hide the entire filter container
                if (filter.label) {
                    filter.label.style.display = 'none';
                }

                // Or replace with a display-only element showing salon name
                if (filter.label && filter.id.includes('Filter')) {
                    const displayDiv = document.createElement('div');
                    displayDiv.className = 'mb-6';
                    displayDiv.innerHTML = `
                        <div class="bg-purple-50 border border-purple-200 rounded-lg px-4 py-3">
                            <span class="text-sm font-medium text-gray-700">Salon: </span>
                            <span class="font-semibold text-purple-700">${escapeHtml(singleSalon.salon_name)}</span>
                        </div>
                    `;
                    filter.label.parentNode.insertBefore(displayDiv, filter.label);
                    filter.label.style.display = 'none';
                }
            }
        });

        // For the link creation modal, hide the salon selector and auto-fill
        const linkSalonField = document.querySelector('label[for="linkSalonId"]')?.closest('div');
        if (linkSalonField) {
            linkSalonField.style.display = 'none';
        }
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
            document.getElementById('salonDefaultLanguage').value = salon.default_language || 'de';
        }
    } else {
        title.textContent = 'Add Salon';
        document.getElementById('salonDefaultLanguage').value = 'de';
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
        policy_version: document.getElementById('salonPolicyVersion').value,
        default_language: document.getElementById('salonDefaultLanguage').value
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
        'customer_facing_tablet_user': 'Tablet User'
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

// ============================================================
// SOCIAL LINKS MANAGEMENT
// ============================================================

async function loadSocialLinks() {
    const salonFilter = document.getElementById('socialLinkSalonFilter').value;
    let endpoint = 'social-links.php?include_inactive=true';

    if (salonFilter) {
        endpoint += `&salon_id=${salonFilter}`;
    }

    const data = await apiRequest(endpoint);
    if (data && data.success) {
        socialLinks = data.data;
        renderSocialLinksGrid();
        populateSocialLinkSalonDropdowns();
    }
}

function renderSocialLinksGrid() {
    const container = document.getElementById('socialLinksContainer');

    if (socialLinks.length === 0) {
        container.innerHTML = '<div class="col-span-full text-center text-gray-500 py-8">No social links found. Click "Add Link" to create one.</div>';
        return;
    }

    container.innerHTML = socialLinks.map(link => {
        const iconClass = getSocialIconClass(link.link_type);
        const iconColor = getSocialIconColor(link.link_type);

        return `
            <div class="bg-white border-2 ${link.is_active ? 'border-gray-200' : 'border-red-200'} rounded-lg p-4 hover:shadow-lg transition-shadow">
                <div class="flex items-start justify-between mb-3">
                    <div class="flex items-center">
                        <div class="w-12 h-12 ${iconColor} rounded-full flex items-center justify-center text-white text-2xl mr-3">
                            ${iconClass}
                        </div>
                        <div>
                            <h3 class="font-semibold text-gray-800">${escapeHtml(link.display_name)}</h3>
                            <p class="text-xs text-gray-500">${getLinkTypeLabel(link.link_type)}</p>
                        </div>
                    </div>
                    <span class="px-2 py-1 text-xs rounded-full ${link.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}">
                        ${link.is_active ? 'Active' : 'Inactive'}
                    </span>
                </div>
                <p class="text-xs text-gray-600 mb-2 truncate">${escapeHtml(link.link_url)}</p>
                ${link.description ? `<p class="text-xs text-gray-500 mb-3">${escapeHtml(link.description)}</p>` : ''}
                <div class="flex gap-2">
                    <button onclick="viewQRCode(${link.link_id})" class="flex-1 px-3 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                        View QR Code
                    </button>
                    <button onclick="editSocialLink(${link.link_id})" class="px-3 py-2 bg-purple-600 text-white text-sm rounded hover:bg-purple-700">
                        Edit
                    </button>
                    <button onclick="deleteSocialLink(${link.link_id})" class="px-3 py-2 bg-red-600 text-white text-sm rounded hover:bg-red-700">
                        Delete
                    </button>
                </div>
            </div>
        `;
    }).join('');
}

function getSocialIconClass(type) {
    const icons = {
        'instagram': '📷',
        'facebook': 'f',
        'tiktok': '🎵',
        'google_reviews': '⭐',
        'yelp': '🌟',
        'twitter': '🐦',
        'linkedin': '💼',
        'youtube': '▶',
        'pinterest': '📌',
        'custom': '🌐'
    };
    return icons[type] || '🔗';
}

function getSocialIconColor(type) {
    const colors = {
        'instagram': 'bg-gradient-to-br from-purple-600 to-pink-500',
        'facebook': 'bg-blue-600',
        'tiktok': 'bg-black',
        'google_reviews': 'bg-red-600',
        'yelp': 'bg-red-600',
        'twitter': 'bg-blue-400',
        'linkedin': 'bg-blue-700',
        'youtube': 'bg-red-600',
        'pinterest': 'bg-red-600',
        'custom': 'bg-gray-600'
    };
    return colors[type] || 'bg-gray-600';
}

function getLinkTypeLabel(type) {
    const labels = {
        'instagram': 'Instagram',
        'facebook': 'Facebook',
        'tiktok': 'TikTok',
        'google_reviews': 'Google Reviews',
        'yelp': 'Yelp',
        'twitter': 'Twitter / X',
        'linkedin': 'LinkedIn',
        'youtube': 'YouTube',
        'pinterest': 'Pinterest',
        'custom': 'Custom Link'
    };
    return labels[type] || type;
}

function openSocialLinkModal(linkId = null) {
    const modal = document.getElementById('socialLinkModal');
    const title = document.getElementById('socialLinkModalTitle');
    const form = document.getElementById('socialLinkForm');

    form.reset();
    document.getElementById('linkId').value = '';

    if (linkId) {
        const link = socialLinks.find(l => l.link_id == linkId);
        if (link) {
            title.textContent = 'Edit Social Link';
            document.getElementById('linkId').value = link.link_id;
            document.getElementById('linkSalonId').value = link.salon_id;
            document.getElementById('linkType').value = link.link_type;
            document.getElementById('linkDisplayName').value = link.display_name;
            document.getElementById('linkUrl').value = link.link_url;
            document.getElementById('linkDescription').value = link.description || '';
            document.getElementById('linkDisplayOrder').value = link.display_order;
        }
    } else {
        title.textContent = 'Add Social Link';

        // Auto-select salon for customer_admin with single salon
        const isCustomerRole = ['customer_admin', 'customer_admin_delegate'].includes(currentUser.role);
        if (isCustomerRole && salons.length === 1) {
            document.getElementById('linkSalonId').value = salons[0].salon_id;
        }
    }

    modal.classList.add('active');
}

function closeSocialLinkModal() {
    document.getElementById('socialLinkModal').classList.remove('active');
}

async function saveSocialLink(e) {
    e.preventDefault();

    const linkId = document.getElementById('linkId').value;
    const formData = {
        salon_id: parseInt(document.getElementById('linkSalonId').value),
        link_type: document.getElementById('linkType').value,
        display_name: document.getElementById('linkDisplayName').value,
        link_url: document.getElementById('linkUrl').value,
        description: document.getElementById('linkDescription').value,
        display_order: parseInt(document.getElementById('linkDisplayOrder').value)
    };

    let endpoint = 'social-links.php';
    let method = 'POST';

    if (linkId) {
        formData.link_id = parseInt(linkId);
        method = 'PUT';
    }

    const data = await apiRequest(endpoint, {
        method,
        body: JSON.stringify(formData)
    });

    if (data && data.success) {
        alert(linkId ? 'Social link updated successfully' : 'Social link created successfully');
        closeSocialLinkModal();
        await loadSocialLinks();
    }
}

function editSocialLink(linkId) {
    openSocialLinkModal(linkId);
}

async function deleteSocialLink(linkId) {
    if (!confirm('Are you sure you want to delete this social link?')) {
        return;
    }

    const data = await apiRequest(`social-links.php?link_id=${linkId}`, {
        method: 'DELETE'
    });

    if (data && data.success) {
        alert('Social link deleted successfully');
        await loadSocialLinks();
    }
}

function viewQRCode(linkId) {
    const link = socialLinks.find(l => l.link_id == linkId);
    if (!link) return;

    const modal = document.getElementById('qrCodeViewModal');
    const title = document.getElementById('qrCodeViewTitle');
    const display = document.getElementById('qrCodeDisplay');
    const urlElement = document.getElementById('qrCodeUrl');

    title.textContent = link.display_name;
    urlElement.textContent = link.link_url;

    // Display QR code
    if (link.qr_code_data) {
        display.innerHTML = `<img src="${link.qr_code_data}" alt="QR Code" class="max-w-full max-h-[300px]" />`;
    } else {
        display.innerHTML = '<p class="text-gray-500">QR Code not available</p>';
    }

    modal.classList.add('active');
}

function closeQrCodeModal() {
    document.getElementById('qrCodeViewModal').classList.remove('active');
}

function populateSocialLinkSalonDropdowns() {
    const salonSelects = [
        document.getElementById('linkSalonId'),
        document.getElementById('socialLinkSalonFilter'),
        document.getElementById('customerSalonFilter')
    ];

    salonSelects.forEach(select => {
        if (!select) return;

        const currentValue = select.value;
        const isFilter = select.id.includes('Filter');

        select.innerHTML = isFilter ? '<option value="">All Salons</option>' : '<option value="">Select Salon</option>';

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

// ============================================================
// CUSTOMER ENTRIES MANAGEMENT
// ============================================================

async function loadCustomerEntries() {
    const searchQuery = document.getElementById('customerSearchInput').value;
    const salonFilter = document.getElementById('customerSalonFilter').value;

    let endpoint = 'customer-entries.php?';

    if (salonFilter) {
        endpoint += `salon_id=${salonFilter}&`;
    }

    if (searchQuery) {
        endpoint += `search=${encodeURIComponent(searchQuery)}&`;
    }

    const data = await apiRequest(endpoint);
    if (data && data.success) {
        customerEntries = data.data;
        renderCustomerEntriesTable();
    }
}

function renderCustomerEntriesTable() {
    const tbody = document.getElementById('customerEntriesTableBody');

    if (customerEntries.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="px-6 py-4 text-center text-gray-500">No customer entries found</td></tr>';
        return;
    }

    tbody.innerHTML = customerEntries.map(customer => `
        <tr class="hover:bg-gray-50">
            <td class="px-6 py-4 whitespace-nowrap font-medium text-gray-900">${escapeHtml(customer.full_name)}</td>
            <td class="px-6 py-4 whitespace-nowrap text-gray-500">${escapeHtml(customer.email)}</td>
            <td class="px-6 py-4 whitespace-nowrap text-gray-500">${escapeHtml(customer.phone)}</td>
            <td class="px-6 py-4 whitespace-nowrap">
                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${customer.consent_marketing ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'}">
                    ${customer.consent_marketing ? 'Yes' : 'No'}
                </span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                ${formatDate(customer.created_at)}
            </td>
        </tr>
    `).join('');
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

// Debounce helper for search input
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// ===== SALON PROFILE & BRANDING =====

let salonProfileData = null;
let uploadedLogoFile = null;

// Initialize Salon Profile tab
document.addEventListener('DOMContentLoaded', function() {
    // Load salon profile when tab is clicked
    const salonProfileTab = document.querySelector('[data-tab="salon-profile"]');
    if (salonProfileTab) {
        salonProfileTab.addEventListener('click', loadSalonProfile);
    }

    // Handle color picker changes (sync with hex input)
    setupColorPickers();

    // Handle logo upload
    const logoUpload = document.getElementById('logoUpload');
    if (logoUpload) {
        logoUpload.addEventListener('change', handleLogoUpload);
    }

    // Handle remove logo
    const removeLogo = document.getElementById('removeLogo');
    if (removeLogo) {
        removeLogo.addEventListener('click', handleRemoveLogo);
    }

    // Handle reset colors
    const resetColors = document.getElementById('resetColors');
    if (resetColors) {
        resetColors.addEventListener('click', resetToDefaultColors);
    }

    // Handle form submission
    const salonProfileForm = document.getElementById('salonProfileForm');
    if (salonProfileForm) {
        salonProfileForm.addEventListener('submit', saveSalonProfile);
    }

    // Handle cancel
    const cancelBtn = document.getElementById('cancelSalonProfile');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', loadSalonProfile);
    }
});

function setupColorPickers() {
    const colorPairs = [
        { picker: 'primaryColor', hex: 'primaryColorHex' },
        { picker: 'secondaryColor', hex: 'secondaryColorHex' },
        { picker: 'backgroundColor', hex: 'backgroundColorHex' },
        { picker: 'buttonColor', hex: 'buttonColorHex' },
        { picker: 'textColor', hex: 'textColorHex' }
    ];

    colorPairs.forEach(pair => {
        const picker = document.getElementById(pair.picker);
        const hexInput = document.getElementById(pair.hex);

        if (picker && hexInput) {
            // Sync picker -> hex input
            picker.addEventListener('input', () => {
                hexInput.value = picker.value.toUpperCase();
                updatePreview();
            });

            // Sync hex input -> picker
            hexInput.addEventListener('input', () => {
                if (/^#[0-9A-Fa-f]{6}$/.test(hexInput.value)) {
                    picker.value = hexInput.value;
                    updatePreview();
                }
            });
        }
    });
}

async function loadSalonProfile() {
    try {
        const sessionToken = localStorage.getItem('session_token');

        if (!window.currentSalon || !window.currentSalon.salon_id) {
            alert('No salon selected. Please ensure you are assigned to a salon.');
            return;
        }

        const response = await fetch(`${API_BASE_URL}/salon-branding.php?salon_id=${window.currentSalon.salon_id}`, {
            headers: {
                'Authorization': `Bearer ${sessionToken}`
            }
        });

        const result = await response.json();

        if (result.success) {
            salonProfileData = result.branding;
            populateSalonProfileForm(result.branding);
        } else {
            alert('Failed to load salon profile: ' + (result.error || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error loading salon profile:', error);
        alert('Error loading salon profile. Please try again.');
    }
}

function populateSalonProfileForm(branding) {
    // Set colors
    document.getElementById('primaryColor').value = branding.primary_color || '#9333EA';
    document.getElementById('primaryColorHex').value = branding.primary_color || '#9333EA';

    document.getElementById('secondaryColor').value = branding.secondary_color || '#EC4899';
    document.getElementById('secondaryColorHex').value = branding.secondary_color || '#EC4899';

    document.getElementById('backgroundColor').value = branding.background_color || '#FFFFFF';
    document.getElementById('backgroundColorHex').value = branding.background_color || '#FFFFFF';

    document.getElementById('buttonColor').value = branding.button_color || '#9333EA';
    document.getElementById('buttonColorHex').value = branding.button_color || '#9333EA';

    document.getElementById('textColor').value = branding.text_color || '#1F2937';
    document.getElementById('textColorHex').value = branding.text_color || '#1F2937';

    // Set guest WiFi (optional)
    const wifiSsidEl = document.getElementById('wifiSsid');
    const wifiPasswordEl = document.getElementById('wifiPassword');
    if (wifiSsidEl) wifiSsidEl.value = branding.wifi_ssid || '';
    if (wifiPasswordEl) wifiPasswordEl.value = branding.wifi_password || '';

    // Set logo
    if (branding.logo_path) {
        // Convert relative path to full URL using correct base URL
        let logoUrl = branding.logo_path;
        if (!logoUrl.startsWith('http')) {
            logoUrl = 'https://clouedo.com/coiffure/' + logoUrl;
        }
        displayLogoPreview(logoUrl);
    } else {
        clearLogoPreview();
    }

    // Update preview
    updatePreview();
}

function handleLogoUpload(event) {
    const file = event.target.files[0];
    if (!file) return;

    // Validate file type
    if (!file.type.match('image.*')) {
        alert('Please select an image file');
        return;
    }

    // Validate file size (max 2MB)
    if (file.size > 2 * 1024 * 1024) {
        alert('Image size must be less than 2MB');
        return;
    }

    uploadedLogoFile = file;

    // Show preview
    const reader = new FileReader();
    reader.onload = function(e) {
        displayLogoPreview(e.target.result);
    };
    reader.readAsDataURL(file);
}

function displayLogoPreview(imageSrc) {
    const logoPreview = document.getElementById('logoPreview');
    const previewLogo = document.getElementById('previewLogo');
    const removeLogo = document.getElementById('removeLogo');

    logoPreview.innerHTML = `<img src="${imageSrc}" class="w-full h-full object-contain rounded-lg" />`;
    previewLogo.innerHTML = `<img src="${imageSrc}" class="w-full h-full object-contain" />`;
    previewLogo.classList.remove('hidden');
    removeLogo.classList.remove('hidden');
}

function clearLogoPreview() {
    const logoPreview = document.getElementById('logoPreview');
    const previewLogo = document.getElementById('previewLogo');
    const removeLogo = document.getElementById('removeLogo');

    logoPreview.innerHTML = '<span class="text-gray-400 text-sm text-center px-2">No logo uploaded</span>';
    previewLogo.innerHTML = '';
    previewLogo.classList.add('hidden');
    removeLogo.classList.add('hidden');
}

function handleRemoveLogo() {
    uploadedLogoFile = null;
    document.getElementById('logoUpload').value = '';
    clearLogoPreview();
}

function resetToDefaultColors() {
    document.getElementById('primaryColor').value = '#9333EA';
    document.getElementById('primaryColorHex').value = '#9333EA';
    document.getElementById('secondaryColor').value = '#EC4899';
    document.getElementById('secondaryColorHex').value = '#EC4899';
    document.getElementById('backgroundColor').value = '#FFFFFF';
    document.getElementById('backgroundColorHex').value = '#FFFFFF';
    document.getElementById('buttonColor').value = '#9333EA';
    document.getElementById('buttonColorHex').value = '#9333EA';
    document.getElementById('textColor').value = '#1F2937';
    document.getElementById('textColorHex').value = '#1F2937';

    updatePreview();
}

function updatePreview() {
    const bgColor = document.getElementById('backgroundColor').value;
    const textColor = document.getElementById('textColor').value;
    const buttonColor = document.getElementById('buttonColor').value;

    const preview = document.getElementById('brandingPreview');
    const previewTitle = document.getElementById('previewTitle');
    const previewButton = document.getElementById('previewButton');

    preview.style.backgroundColor = bgColor;
    previewTitle.style.color = textColor;
    previewButton.style.backgroundColor = buttonColor;
}

async function saveSalonProfile(event) {
    event.preventDefault();

    if (!window.currentSalon || !window.currentSalon.salon_id) {
        alert('No salon selected.');
        return;
    }

    try {
        const sessionToken = localStorage.getItem('session_token');
        const formData = new FormData();

        // Add salon ID
        formData.append('salon_id', window.currentSalon.salon_id);

        // Add colors
        formData.append('primary_color', document.getElementById('primaryColorHex').value);
        formData.append('secondary_color', document.getElementById('secondaryColorHex').value);
        formData.append('background_color', document.getElementById('backgroundColorHex').value);
        formData.append('button_color', document.getElementById('buttonColorHex').value);
        formData.append('text_color', document.getElementById('textColorHex').value);

        // Add guest WiFi (optional)
        const wifiSsidEl = document.getElementById('wifiSsid');
        const wifiPasswordEl = document.getElementById('wifiPassword');
        if (wifiSsidEl) formData.append('wifi_ssid', wifiSsidEl.value.trim());
        if (wifiPasswordEl) formData.append('wifi_password', wifiPasswordEl.value.trim());

        // Add logo if uploaded
        if (uploadedLogoFile) {
            formData.append('logo', uploadedLogoFile);
        }

        // Add remove logo flag if logo was removed
        if (document.getElementById('removeLogo').classList.contains('hidden') === false && !uploadedLogoFile) {
            formData.append('remove_logo', 'true');
        }

        const response = await fetch(`${API_BASE_URL}/salon-branding.php`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${sessionToken}`
            },
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            alert('Salon profile updated successfully!');
            uploadedLogoFile = null;
            loadSalonProfile();
        } else {
            alert('Failed to update salon profile: ' + (result.error || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error saving salon profile:', error);
        alert('Error saving salon profile. Please try again.');
    }
}

// ==================== Loyalty settings (Treueprogramm) ====================
let loyaltyOriginal = null;

document.addEventListener('DOMContentLoaded', function () {
    const tab = document.querySelector('[data-tab="loyalty"]');
    if (tab) tab.addEventListener('click', loadLoyaltySettings);

    const form = document.getElementById('loyaltyForm');
    if (form) form.addEventListener('submit', saveLoyaltySettings);

    const reload = document.getElementById('loyaltyReload');
    if (reload) reload.addEventListener('click', function (e) { e.preventDefault(); loadLoyaltySettings(); });

    ['loyaltyActive', 'loyaltyThreshold', 'loyaltyDiscountType', 'loyaltyDiscountValue', 'loyaltyDiscountLabel']
        .forEach(function (id) {
            const el = document.getElementById(id);
            if (el) { el.addEventListener('input', onLoyaltyInput); el.addEventListener('change', onLoyaltyInput); }
        });
});

async function loadLoyaltySettings() {
    if (!window.currentSalon || !window.currentSalon.salon_id) {
        console.warn('Loyalty: no salon selected yet');
        return;
    }
    try {
        const res = await fetch(`${API_BASE_URL}/loyalty-config.php?salon_id=${window.currentSalon.salon_id}`, {
            headers: { 'Authorization': `Bearer ${sessionToken}` }
        });
        const cfg = await res.json();
        if (!cfg || !cfg.success) { alert('Konnte Treue-Einstellungen nicht laden: ' + (cfg && cfg.error || '')); return; }
        loyaltyOriginal = cfg;
        document.getElementById('loyaltyActive').checked = !!cfg.loyalty_active;
        document.getElementById('loyaltyThreshold').value = cfg.visit_threshold;
        document.getElementById('loyaltyDiscountType').value = cfg.discount_type;
        document.getElementById('loyaltyDiscountValue').value = cfg.discount_value;
        document.getElementById('loyaltyDiscountLabel').value = ''; // custom override; blank = auto
        document.getElementById('loyaltyStaffPin').value = '';
        onLoyaltyInput();
    } catch (e) {
        console.error('loyalty load', e);
        alert('Fehler beim Laden der Treue-Einstellungen.');
    }
}

function onLoyaltyInput() {
    const active = document.getElementById('loyaltyActive').checked;
    const fields = document.getElementById('loyaltyFields');
    fields.style.opacity = active ? '1' : '0.5';
    fields.querySelectorAll('input, select').forEach(function (el) { el.disabled = !active; });

    const type = document.getElementById('loyaltyDiscountType').value;
    const valEl = document.getElementById('loyaltyDiscountValue');
    const hint = document.getElementById('loyaltyValueHint');
    if (type === 'percentage') { valEl.min = 1; valEl.max = 100; valEl.step = 1; if (hint) hint.textContent = '1 – 100 %'; }
    else { valEl.min = 0.5; valEl.max = 500; valEl.step = 0.5; if (hint) hint.textContent = '0,50 – 500 €'; }

    updateLoyaltyPreview();
}

function loyaltyLabel(type, value, custom) {
    custom = (custom || '').trim();
    if (custom) return custom;
    const num = Number(value);
    if (isNaN(num)) return type === 'percentage' ? '% ' : '€';
    const s = Number.isInteger(num) ? String(num) : num.toFixed(2).replace('.', ',');
    return type === 'percentage' ? (s + ' %') : (s + ' €');
}

function updateLoyaltyPreview() {
    const active = document.getElementById('loyaltyActive').checked;
    const box = document.getElementById('loyaltyPreviewBox');
    const inactive = document.getElementById('loyaltyPreviewInactive');
    if (!active) { box.classList.add('hidden'); inactive.classList.remove('hidden'); return; }
    box.classList.remove('hidden'); inactive.classList.add('hidden');

    const threshold = Math.max(2, parseInt(document.getElementById('loyaltyThreshold').value, 10) || 5);
    const type = document.getElementById('loyaltyDiscountType').value;
    const value = document.getElementById('loyaltyDiscountValue').value;
    const label = loyaltyLabel(type, value, document.getElementById('loyaltyDiscountLabel').value);

    // Hardcoded example: 3rd visit of the configured threshold.
    const exampleVisit = Math.min(3, threshold);
    const inCycle = exampleVisit % threshold;
    const remaining = threshold - inCycle;
    const pct = Math.round((inCycle / threshold) * 100);

    document.getElementById('loyaltyPreviewCount').textContent = 'Besuch ' + exampleVisit;
    document.getElementById('loyaltyPreviewPercent').textContent = pct + '%';
    document.getElementById('loyaltyPreviewFill').style.width = pct + '%';
    document.getElementById('loyaltyPreviewCaption').textContent =
        'Noch ' + remaining + ' Besuche bis zu Ihrem ' + label + ' Rabatt.';
}

async function saveLoyaltySettings(e) {
    e.preventDefault();
    if (!window.currentSalon || !window.currentSalon.salon_id) { alert('Kein Salon ausgewählt.'); return; }

    const active = document.getElementById('loyaltyActive').checked;
    const threshold = parseInt(document.getElementById('loyaltyThreshold').value, 10);
    const type = document.getElementById('loyaltyDiscountType').value;
    const value = parseFloat(document.getElementById('loyaltyDiscountValue').value);
    const label = document.getElementById('loyaltyDiscountLabel').value.trim();
    const pin = document.getElementById('loyaltyStaffPin').value.trim();

    if (active) {
        if (!(threshold >= 2 && threshold <= 50)) { alert('Die Besuchsschwelle muss zwischen 2 und 50 liegen.'); return; }
        if (type === 'fixed_eur' && !(value >= 0.5 && value <= 500)) { alert('Fester Rabatt muss zwischen 0,50 € und 500 € liegen.'); return; }
        if (type === 'percentage' && !(value >= 1 && value <= 100)) { alert('Prozentualer Rabatt muss zwischen 1 % und 100 % liegen.'); return; }
    }
    if (pin && !/^\d{4}$/.test(pin)) { alert('Die Personal-PIN muss aus genau 4 Ziffern bestehen.'); return; }

    // Confirmation when the threshold changes (affects active members going forward).
    if (loyaltyOriginal && Number(loyaltyOriginal.visit_threshold) !== threshold) {
        const ok = confirm('Die Besuchsschwelle ändert sich von ' + loyaltyOriginal.visit_threshold + ' auf ' + threshold +
            '. Bestehende Mitglieder behalten ihre gezählten Besuche und zählen ab sofort gegen die neue Schwelle. Fortfahren?');
        if (!ok) return;
    }

    const body = {
        salon_id: window.currentSalon.salon_id,
        loyalty_active: active ? 1 : 0,
        visit_threshold: threshold,
        discount_type: type,
        discount_value: value,
        discount_label: label
    };
    if (pin) body.staff_pin = pin;

    try {
        const res = await fetch(`${API_BASE_URL}/loyalty-config.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${sessionToken}` },
            body: JSON.stringify(body)
        });
        const data = await res.json();
        if (data && data.success) {
            loyaltyOriginal = data;
            document.getElementById('loyaltyStaffPin').value = '';
            showLoyaltyToast(data.message || 'Einstellungen gespeichert.');
        } else {
            alert((data && data.error) || 'Speichern fehlgeschlagen.');
        }
    } catch (err) {
        console.error('loyalty save', err);
        alert('Netzwerkfehler beim Speichern.');
    }
}

function showLoyaltyToast(msg) {
    let t = document.getElementById('loyaltyToast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'loyaltyToast';
        t.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#16A34A;color:#fff;padding:12px 20px;border-radius:10px;box-shadow:0 6px 20px rgba(0,0,0,.2);z-index:9999;font-weight:600;opacity:0;transition:opacity .2s';
        document.body.appendChild(t);
    }
    t.textContent = msg;
    requestAnimationFrame(function () { t.style.opacity = '1'; });
    clearTimeout(t._timer);
    t._timer = setTimeout(function () { t.style.opacity = '0'; }, 2500);
}

// ==================== Global settings (admin only) ====================
const GLOBAL_SETTINGS_FIELDS = {
    gsIdleReturn:      'timeout_idle_return_s',
    gsBirthday:        'timeout_birthday_s',
    gsAutoconfirm:     'timeout_autoconfirm_s',
    gsNamelist:        'timeout_namelist_s',
    gsNamesConfirm:    'timeout_names_confirm_s',
    gsPhone:           'timeout_phone_s',
    gsWelcomeSuccess:  'timeout_welcome_success_s',
    gsWelcomeDuplicate:'timeout_welcome_duplicate_s',
    gsStaffPin:        'timeout_staff_pin_s',
    gsStaffSearch:     'timeout_staff_search_s',
    gsAutocheckout:    'timeout_autocheckout_s'
};

document.addEventListener('DOMContentLoaded', function () {
    // The tab is reserved for full administrators.
    let role = null;
    try { role = (JSON.parse(localStorage.getItem('user_data') || '{}') || {}).role; } catch (e) {}
    if (role === 'admin') {
        const btn = document.getElementById('globalSettingsTabBtn');
        if (btn) {
            btn.style.display = '';
            btn.addEventListener('click', loadGlobalSettings);
        }
    }

    const form = document.getElementById('globalSettingsForm');
    if (form) form.addEventListener('submit', saveGlobalSettings);
    const reload = document.getElementById('globalSettingsReload');
    if (reload) reload.addEventListener('click', function (e) { e.preventDefault(); loadGlobalSettings(); });
});

async function loadGlobalSettings() {
    try {
        const res = await fetch(`${API_BASE_URL}/global-settings.php`, {
            headers: { 'Authorization': `Bearer ${sessionToken}` }
        });
        const data = await res.json();
        if (!data || !data.success) { alert('Konnte globale Einstellungen nicht laden.'); return; }
        const s = data.settings || {};
        Object.keys(GLOBAL_SETTINGS_FIELDS).forEach(function (fieldId) {
            const el = document.getElementById(fieldId);
            if (el) el.value = s[GLOBAL_SETTINGS_FIELDS[fieldId]];
        });
    } catch (e) {
        console.error('global settings load', e);
        alert('Fehler beim Laden der globalen Einstellungen.');
    }
}

async function saveGlobalSettings(e) {
    e.preventDefault();
    const settings = {};
    let invalid = null;
    Object.keys(GLOBAL_SETTINGS_FIELDS).forEach(function (fieldId) {
        const el = document.getElementById(fieldId);
        if (!el) return;
        const v = parseInt(el.value, 10);
        const min = parseInt(el.min, 10), max = parseInt(el.max, 10);
        if (isNaN(v) || v < min || v > max) { invalid = invalid || fieldId; }
        settings[GLOBAL_SETTINGS_FIELDS[fieldId]] = v;
    });
    if (invalid) { alert('Bitte gültige Werte innerhalb der erlaubten Bereiche eingeben.'); return; }

    try {
        const res = await fetch(`${API_BASE_URL}/global-settings.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${sessionToken}` },
            body: JSON.stringify({ settings: settings })
        });
        const data = await res.json();
        if (data && data.success) {
            showLoyaltyToast(data.message || 'Einstellungen gespeichert.');
        } else {
            alert((data && data.error) || 'Speichern fehlgeschlagen.');
        }
    } catch (err) {
        console.error('global settings save', err);
        alert('Netzwerkfehler beim Speichern.');
    }
}
