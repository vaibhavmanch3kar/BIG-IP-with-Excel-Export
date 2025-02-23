# BIG-IP Extended Report with Excel Export

This PHP project retrieves and displays F5 BIG-IP configuration details (Virtual Servers and Pool Members) using the F5 REST API. It displays the information on a webpage and provides an **"Export to Excel"** button that generates an Excel workbook (XLSX) with separate sheets for VIPs and Pool Members.

---

## Table of Contents

- [Features](#features)
- [Prerequisites](#prerequisites)
- [Installation](#installation)
- [Usage](#usage)
- [How It Works](#how-it-works)
- [Standard Operating Procedure (SOP)](#standard-operating-procedure-sop)
- [Next Steps](#next-steps)
- [Contributing](#contributing)
- [License](#license)

---

## Features

- **Virtual Server (VIP) Details:**  
  Displays VIP name, partition, destination (IP:port), associated pool, profiles, persistence, ASM policy, and SSL profile.

- **Pool Member Details:**  
  Retrieves pool member information such as member name, address, port, and health status and ties them to each pool discovered.

- **Pool Name Conversion:**  
  Converts pool names from `/Common/foo` format (as returned by VIP objects) to `~Common~foo` format (required by pool member API calls).

- **Excel Export:**  
  Provides an "Export to Excel" button that generates an Excel file with two sheets: one for VIPs and one for Pool Members, using [PhpSpreadsheet](https://github.com/PHPOffice/PhpSpreadsheet).

---

## Prerequisites

1. **PHP** version 8.1 or later (recommended).
2. **Composer** for dependency management.
3. [**PhpSpreadsheet**](https://phpspreadsheet.readthedocs.io/) installed via Composer.
   - Alternatively, if needed:
     ```bash
     composer require phpoffice/phpspreadsheet
     ```
4. Configure your `php.ini` with the required extensions (e.g., `ext-gd`, `ext-zip`).
5. Network access and valid credentials for your F5 BIG-IP device’s REST API.

---

## Installation

1. **Clone or Download the Repository:**  
   Download or clone this repository to your web server’s document root.

2. **Install Dependencies:**  
   Navigate to the project directory and run:
   ```bash
   composer install
