<?php
// If you've installed PhpSpreadsheet via Composer, load its autoloader.
// Adjust the path if needed.
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// BIG-IP connection settings
$host = 'https://your-bigip.example.com';  // Replace with your BIG-IP hostname or IP
$username = 'admin';                  // Your BIG-IP username
$password = 'password';              // Your BIG-IP password
$verifySSL = false;                   // For testing only; enable in production

// Helper function to make REST API calls using cURL
function apiCall($url, $username, $password, $verifySSL = false) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    // Basic authentication
    curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySSL);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySSL ? 2 : 0);
    
    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'cURL error: ' . curl_error($ch) . "\n";
    }
    curl_close($ch);
    return json_decode($result, true);
}

// A small helper to convert "/Common/foo" => "~Common~foo"
function convertPoolName($poolName) {
    if (preg_match('#^/Common/(.*)$#', $poolName, $matches)) {
        return '~Common~' . $matches[1];
    }
    return $poolName;
}

// Base URL for the BIG-IP REST API
$baseUrl = $host . '/mgmt/tm';

// --------------------------------------------------------------------
// Step 1: Gather data (VIPs and Pool Members) - same logic for both HTML and Excel
// --------------------------------------------------------------------
$virtualsUrl      = $baseUrl . '/ltm/virtual';
$virtualsResponse = apiCall($virtualsUrl, $username, $password, $verifySSL);

// We will store the VIP data in $vipData (2D array) and the pool members in $poolMembersData.
$vipData = [];
$vipData[] = ['Virtual Server', 'Partition', 'Destination', 'Pool', 'Profiles', 'Persistence', 'ASM Policy', 'SSL Profile'];

$distinctPools = [];

// 1A) Collect Virtual Servers
if (!empty($virtualsResponse['items'])) {
    foreach ($virtualsResponse['items'] as $vip) {
        $vipName     = $vip['name'] ?? 'N/A';
        $partition   = $vip['partition'] ?? 'Common';
        $destination = $vip['destination'] ?? 'N/A';
        $pool        = $vip['pool'] ?? 'None';
        
        if ($pool !== 'None') {
            $distinctPools[$pool] = true;
        }
        
        // Collect profiles
        $profiles = [];
        if (!empty($vip['profilesReference']['items'])) {
            foreach ($vip['profilesReference']['items'] as $p) {
                $profiles[] = $p['name'];
            }
        }
        $profilesDisplay = $profiles ? implode(', ', $profiles) : 'None';

        // Persistence
        $persistence = 'None';
        if (!empty($vip['persist'])) {
            $persistNames = [];
            foreach ($vip['persist'] as $persistObj) {
                if (is_array($persistObj) && isset($persistObj['name'])) {
                    $persistNames[] = $persistObj['name'];
                } elseif (is_array($persistObj) && isset($persistObj['profileName'])) {
                    $persistNames[] = $persistObj['profileName'];
                } elseif (is_array($persistObj)) {
                    $persistNames[] = json_encode($persistObj);
                } else {
                    $persistNames[] = (string)$persistObj;
                }
            }
            if (!empty($persistNames)) {
                $persistence = implode(', ', $persistNames);
            }
        }

        // ASM Policy
        $asmPolicy = !empty($vip['asmPolicy']) ? $vip['asmPolicy'] : 'None';

        // SSL Profile
        $sslProfile = 'None';
        if (!empty($vip['profilesReference']['items'])) {
            foreach ($vip['profilesReference']['items'] as $prof) {
                if (stripos($prof['name'], 'clientssl') !== false || stripos($prof['name'], 'serverssl') !== false) {
                    $sslProfile = $prof['name'];
                    break;
                }
            }
        }
        
        $vipData[] = [
            $vipName,
            $partition,
            $destination,
            $pool,
            $profilesDisplay,
            $persistence,
            $asmPolicy,
            $sslProfile
        ];
    }
} else {
    $vipData[] = ['No virtual servers found', '', '', '', '', '', '', ''];
}

// 1B) Collect Pool Members
$poolMembersData = [];
$poolMembersData[] = ['Pool', 'Member Name', 'Member Address', 'Member Port', 'Health Status'];

foreach (array_keys($distinctPools) as $poolName) {
    $convertedName = convertPoolName($poolName);
    $poolUrl       = $baseUrl . '/ltm/pool/' . urlencode($convertedName) . '?expandSubcollections=true';
    $poolResponse  = apiCall($poolUrl, $username, $password, $verifySSL);
    
    $members = [];
    if (!empty($poolResponse['membersReference']['items'])) {
        $members = $poolResponse['membersReference']['items'];
    } elseif (!empty($poolResponse['members'])) {
        $members = $poolResponse['members'];
    }
    if ($members) {
        foreach ($members as $member) {
            $memberName    = $member['name']    ?? 'N/A';
            $memberAddress = $member['address'] ?? 'N/A';
            // If "port" is missing, parse from name if possible
            if (!empty($member['port'])) {
                $memberPort = $member['port'];
            } else {
                if (strpos($memberName, ':') !== false) {
                    $parts      = explode(':', $memberName);
                    $memberPort = $parts[1] ?? 'N/A';
                } else {
                    $memberPort = 'N/A';
                }
            }
            // Health
            if (isset($member['monitor_status'])) {
                $memberHealth = $member['monitor_status'];
            } elseif (isset($member['state'])) {
                $memberHealth = $member['state'];
            } elseif (isset($member['session'])) {
                $memberHealth = $member['session'];
            } else {
                $memberHealth = 'N/A';
            }
            
            $poolMembersData[] = [
                $poolName,
                $memberName,
                $memberAddress,
                $memberPort,
                $memberHealth
            ];
        }
    } else {
        $poolMembersData[] = [$poolName, 'No members found', '', '', ''];
    }
}

// --------------------------------------------------------------------
// Step 2: If user clicked "Export to Excel", generate XLSX
// --------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    // Create a new spreadsheet
    $spreadsheet = new Spreadsheet();

    // --- Sheet 1: VIPs ---
    $sheet1 = $spreadsheet->getActiveSheet();
    $sheet1->setTitle('VIPs');
    
    // Write out $vipData
    $rowNum = 1;
    foreach ($vipData as $row) {
        $colNum = 1;
        foreach ($row as $cellValue) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colNum) . $rowNum;
            $sheet1->setCellValue($cell, $cellValue);
            $colNum++;
        }
        $rowNum++;
    }

    // Some quick styling for header row
    $headerRange = 'A1:H1';
    $sheet1->getStyle($headerRange)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF4CAF50']]
    ]);

    // Auto-size columns A through H
    foreach (range('A','H') as $col) {
        $sheet1->getColumnDimension($col)->setAutoSize(true);
    }

    // --- Sheet 2: Pool Members ---
    $sheet2 = $spreadsheet->createSheet();
    $sheet2->setTitle('Pool Members');

    $rowNum = 1;
    foreach ($poolMembersData as $row) {
        $colNum = 1;
        foreach ($row as $cellValue) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colNum) . $rowNum;
            $sheet2->setCellValue($cell, $cellValue);
            $colNum++;
        }
        $rowNum++;
    }

    // Style the header row in sheet2
    $headerRange2 = 'A1:E1';
    $sheet2->getStyle($headerRange2)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2196F3']]
    ]);

    foreach (range('A','E') as $col) {
        $sheet2->getColumnDimension($col)->setAutoSize(true);
    }

    // Send the file to the browser as an XLSX download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="bigip_report.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// --------------------------------------------------------------------
// Step 3: Otherwise, display the data in HTML + "Export to Excel" button
// --------------------------------------------------------------------
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>BIG-IP Extended Report</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        h1, h2 {
            color: #333;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            margin-bottom: 30px;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #4CAF50;
            color: white;
        }
        tr:nth-child(even) {
            background-color: #f2f2f2;
        }
        .export-btn {
            display: inline-block;
            padding: 8px 16px;
            background: #1976D2;
            color: #fff;
            text-decoration: none;
            font-weight: bold;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .export-btn:hover {
            background: #125EA1;
        }
    </style>
</head>
<body>
    <h1>BIG-IP Extended Report</h1>

    <!-- Button to export all data to Excel -->
    <a href="?export=excel" class="export-btn">Export to Excel</a>

    <h2>Virtual Servers (VIPs)</h2>
    <table>
        <thead>
            <tr>
                <?php foreach ($vipData[0] as $header): ?>
                    <th><?php echo htmlspecialchars($header); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php for ($i = 1; $i < count($vipData); $i++): ?>
                <tr>
                    <?php foreach ($vipData[$i] as $cell): ?>
                        <td><?php echo htmlspecialchars($cell); ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endfor; ?>
        </tbody>
    </table>

    <h2>Pool Members</h2>
    <table>
        <thead>
            <tr>
                <?php foreach ($poolMembersData[0] as $header): ?>
                    <th><?php echo htmlspecialchars($header); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php for ($i = 1; $i < count($poolMembersData); $i++): ?>
                <tr>
                    <?php foreach ($poolMembersData[$i] as $cell): ?>
                        <td><?php echo htmlspecialchars($cell); ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endfor; ?>
        </tbody>
    </table>
</body>
</html>
