# BIG-IP Extended Report with Excel Export

This PHP project retrieves and displays F5 BIG-IP configuration details (Virtual Servers and Pool Members) via the F5 REST API. It displays the information on a webpage **and** provides an **"Export to Excel"** button, which generates an Excel workbook with separate sheets for VIPs and pool members.

## Features

- Displays **Virtual Server** details (name, partition, IP, port, associated profiles, persistence, ASM policy, etc.).
- Collects **Pool Member** details (name, address, port, health status) and ties them to each pool discovered.
- Converts pool references from `/Common/foo` to `~Common~foo` for REST API consistency.
- **Exports** collected data to Excel using [PhpSpreadsheet](https://github.com/PHPOffice/PhpSpreadsheet).

## Prerequisites

1. **PHP** >= 8.1 recommended.
2. **Composer** for package management.
3. [**PhpSpreadsheet**](https://phpspreadsheet.readthedocs.io/) installed via Composer.
4. Network access and credentials to your F5 BIG-IP device’s REST API.

## Installation

1. **Clone/Download** this repository to your web server’s document root.
2. **Install Dependencies**:
   ```bash
   composer install
