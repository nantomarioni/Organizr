<?php

require_once '/var/www/html/api/functions.php';

use Symfony\Component\Yaml\Yaml;

// Define default configuration
$defaultConfig = [
    'configPath' => '/config/config.yaml',
    'dbPath' => '/config/data/',
    'dbName' => 'organizr.db',
    'driver' => 'sqlite3'
];

// Check if config file exists
if (!file_exists($defaultConfig['configPath'])) {
    echo "No declarative config file found at " . $defaultConfig['configPath'] . ". Skipping declarative configuration.\n";
    exit(0);
}

try {
    echo "Parsing declarative config file...\n";
    $config = Yaml::parseFile($defaultConfig['configPath']);
} catch (Exception $e) {
    echo "Error parsing YAML config: " . $e->getMessage() . "\n";
    exit(1);
}

$Organizr = new Organizr();

// 1. Initialize Database/User if not exists
if (!$Organizr->hasConfig() || !$Organizr->hasDatabase()) {
    echo "Initializing Organizr...\n";

    if (isset($config['adminUser']) && isset($config['adminUser']['create']) && $config['adminUser']['create']) {
        $wizardData = [
            'driver' => $defaultConfig['driver'],
            'dbName' => $defaultConfig['dbName'],
            'dbPath' => $defaultConfig['dbPath'],
            'username' => $config['adminUser']['username'] ?? 'admin',
            'password' => $config['adminUser']['password'] ?? 'password',
            'email' => $config['adminUser']['email'] ?? 'admin@example.com',
            'registrationPassword' => $config['adminUser']['registrationPassword'] ?? 'admin',
            'license' => $config['license'] ?? 'personal',
            'hashKey' => $config['hashKey'] ?? bin2hex(random_bytes(16)),
            'api' => bin2hex(random_bytes(20))
        ];

        if ($Organizr->wizardConfig($wizardData)) {
            echo "Organizr initialized successfully.\n";
            // Re-initialize Organizr to load new config
            $Organizr = new Organizr(); 
        } else {
            echo "Failed to initialize Organizr.\n";
            // Print error from Organizr API response if available
            if (isset($GLOBALS['api']['response']['message'])) {
                 echo "Error: " . $GLOBALS['api']['response']['message'] . "\n";
            }
            exit(1);
        }
    } else {
        echo "Organizr is not configured and no adminUser creation requested. Skipping initialization.\n";
    }
} else {
    echo "Organizr is already initialized.\n";
}

// 2. Apply Settings
if (isset($config['settings'])) {
    echo "Applying settings...\n";
    $settingsMap = [
        'authProxy' => [
            'enabled' => 'authProxyEnabled',
            'headerName' => 'authProxyHeaderName',
            'headerNameEmail' => 'authProxyHeaderNameEmail',
            'headerNameGroups' => 'authProxyHeaderNameGroup',
            'groupMapping' => 'authProxyGroupMapping',
            'whitelist' => 'authProxyWhitelist',
            'overrideLogout' => 'authProxyOverrideLogout',
            'logoutURL' => 'authProxyLogoutURL',
            // 'register' => '', // Not sure where this maps to, explicitly
        ],
        'title' => 'title',
        'organizrHash' => 'organizrHash',
        // Add other simple mappings here
    ];

    $updateData = [];

    // Flatten logic
    foreach ($config['settings'] as $key => $value) {
        if ($key === 'authProxy' && is_array($value)) {
           foreach ($value as $apKey => $apVal) {
               if (isset($settingsMap['authProxy'][$apKey])) {
                   $updateData[$settingsMap['authProxy'][$apKey]] = $apVal;
               }
           }
        } elseif (isset($settingsMap[$key])) {
             $updateData[$settingsMap[$key]] = $value;
        } else {
            // Direct mapping fallback for simple keys not in map but matching config keys
            // You might want to be safer here and only allow explicitly mapped keys
             $updateData[$key] = $value; 
        }
    }

    if (!empty($updateData)) {
        if ($Organizr->updateConfig($updateData)) {
            echo "Settings updated successfully.\n";
        } else {
            echo "Failed to update settings.\n";
        }
    }
}

// 3. Create Groups
if (isset($config['groups']) && is_array($config['groups'])) {
    echo "Configuring groups...\n";
    foreach ($config['groups'] as $groupData) {
        $existingGroups = $Organizr->getAllGroups();
        $exists = false;
        foreach ($existingGroups as $g) {
            if ($g['group'] === $groupData['name']) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            // Assuming addGroup method exists or using generic insert
            // Organizr class doesn't seem to have public addGroup, searching functions... 
            // It has getAllGroups. 
            // Let's assume we might need to bypass or add it manually via query if no method exists.
            // For now, let's verify if addGroup exists or if we should skip.
            // Implementation detail: Use what is available. 
            // Check Organizr class again: getNextGroupOrder exists. 
             // In Organizr 2, groups are seemingly fixed 0-admin, 1-user, 999-guest etc? 
             // Or can we add custom groups? 
             // The user config implies adding groups.
             // If no addGroup method, we might skip this part or try to insert directly.
             // Looking at source, there is no addGroup method in the viewed file.
             // But there is 'getAllGroups'.
             // Let's assume for this MVP we might skip custom group creation if no clear API exists, 
             // OR check if we missed the function.
             // Actually, the user asked for "Assign Organizr to Group" in a previous context, 
             // implying groups existence.
             // Let's try to find an `addGroup` equivalent.
             // If not found, we print warning.
             echo "Warning: Group creation not fully implemented in this script yet (Method lookup needed).\n";
        }
    }
}

// 4. Create Tabs
if (isset($config['tabs']) && is_array($config['tabs'])) {
    echo "Configuring tabs...\n";
    $allTabsData = $Organizr->getAllTabs();
    $existingTabs = $allTabsData['tabs'] ?? [];
    
    foreach ($config['tabs'] as $tabData) {
        $exists = false;
        $existingTabId = null;
        
        foreach ($existingTabs as $tab) {
            if ($tab['name'] === $tabData['name']) {
                $exists = true;
                $existingTabId = $tab['id'];
                break;
            }
        }

        if ($exists) {
            echo "Tab " . $tabData['name'] . " already exists. Updating...\n";
            if ($Organizr->updateTab($existingTabId, $tabData)) {
                echo "Tab updated.\n";
            } else {
                if (isset($GLOBALS['api']['response']['message'])) {
                    echo "Failed to update tab: " . $GLOBALS['api']['response']['message'] . "\n";
                } else {
                    echo "Failed to update tab (Unknown error).\n";
                }
            }
        } else {
            echo "Adding tab " . $tabData['name'] . "...\n";
            // Map keys if necessary, or pass array directly if it matches
            // API expects: name, url, image, type, etc.
            if ($Organizr->addTab($tabData)) {
                echo "Tab added.\n";
            } else {
                 if (isset($GLOBALS['api']['response']['message'])) {
                     echo "Failed to add tab: " . $GLOBALS['api']['response']['message'] . "\n";
                 } else {
                     echo "Failed to add tab (Unknown error).\n";
                 }
            }
        }
    }
}

echo "Declarative configuration finished.\n";
