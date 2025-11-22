<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Migration Manager - Coiffure AI</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen py-12 px-4">
        <div class="max-w-4xl mx-auto">
            <!-- Header -->
            <div class="bg-white rounded-lg shadow-lg p-8 mb-6">
                <h1 class="text-3xl font-bold text-gray-800 mb-2">Database Migration Manager</h1>
                <p class="text-gray-600">Manage and run database migrations for Coiffure AI</p>
            </div>

            <!-- Available Migrations -->
            <div class="bg-white rounded-lg shadow-lg p-8 mb-6">
                <h2 class="text-2xl font-bold text-gray-800 mb-6">Available Migrations</h2>
                <div id="migrations-list" class="space-y-4">
                    <div class="animate-pulse">
                        <div class="h-20 bg-gray-200 rounded"></div>
                    </div>
                </div>
            </div>

            <!-- Migration Log -->
            <div class="bg-white rounded-lg shadow-lg p-8">
                <h2 class="text-2xl font-bold text-gray-800 mb-6">Migration Log</h2>
                <div id="migration-log" class="bg-gray-900 text-green-400 p-4 rounded font-mono text-sm h-96 overflow-y-auto">
                    <div class="text-gray-500">Ready to run migrations...</div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Load available migrations
        async function loadMigrations() {
            try {
                const response = await fetch('migration-manager.php?action=list');
                const data = await response.json();

                if (data.success) {
                    displayMigrations(data.migrations);
                } else {
                    logMessage('ERROR: Failed to load migrations: ' + data.error, 'error');
                }
            } catch (error) {
                logMessage('ERROR: ' + error.message, 'error');
            }
        }

        function displayMigrations(migrations) {
            const container = document.getElementById('migrations-list');
            container.innerHTML = '';

            migrations.forEach(migration => {
                const migrationDiv = document.createElement('div');
                migrationDiv.className = 'border border-gray-200 rounded-lg p-4 hover:border-purple-400 transition-all';

                const statusColor = migration.applied ? 'text-green-600' : 'text-gray-600';
                const statusText = migration.applied ? '✓ Applied' : '○ Not Applied';
                const buttonDisabled = migration.applied ? 'disabled opacity-50 cursor-not-allowed' : '';

                migrationDiv.innerHTML = `
                    <div class="flex items-center justify-between">
                        <div class="flex-1">
                            <h3 class="font-bold text-lg text-gray-800">${migration.number}. ${migration.name}</h3>
                            <p class="text-sm text-gray-600 mt-1">${migration.description}</p>
                            <p class="text-xs ${statusColor} font-semibold mt-2">${statusText}</p>
                        </div>
                        <button
                            onclick="runMigration('${migration.number}')"
                            ${buttonDisabled}
                            class="ml-4 px-6 py-3 bg-purple-600 text-white font-semibold rounded-lg hover:bg-purple-700 transition-colors ${buttonDisabled}">
                            Run Migration
                        </button>
                    </div>
                `;

                container.appendChild(migrationDiv);
            });
        }

        async function runMigration(number) {
            logMessage(`\n========================================`, 'info');
            logMessage(`Running migration ${number}...`, 'info');
            logMessage(`========================================\n`, 'info');

            try {
                const response = await fetch('migration-manager.php?action=run&migration=' + number, {
                    method: 'POST'
                });
                const data = await response.json();

                if (data.success) {
                    logMessage(data.output, 'success');
                    logMessage('\n✓ Migration completed successfully!', 'success');

                    // Reload migrations list
                    setTimeout(() => loadMigrations(), 1000);
                } else {
                    logMessage('ERROR: ' + data.error, 'error');
                    if (data.output) {
                        logMessage(data.output, 'error');
                    }
                }
            } catch (error) {
                logMessage('ERROR: ' + error.message, 'error');
            }
        }

        function logMessage(message, type = 'info') {
            const log = document.getElementById('migration-log');
            const line = document.createElement('div');

            if (type === 'error') {
                line.className = 'text-red-400';
            } else if (type === 'success') {
                line.className = 'text-green-400';
            } else {
                line.className = 'text-gray-300';
            }

            line.textContent = message;
            log.appendChild(line);

            // Auto-scroll to bottom
            log.scrollTop = log.scrollHeight;
        }

        // Load migrations on page load
        loadMigrations();
    </script>
</body>
</html>
